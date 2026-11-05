<?php
/**
 * Endpoint JSON para generar prompts desde repositorio, web o entrada manual.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/lib/AnalizadorUrl.php';
require_once dirname(__DIR__) . '/lib/ClienteGitHub.php';
require_once dirname(__DIR__) . '/lib/ResolutorDns.php';
require_once dirname(__DIR__) . '/lib/ClienteWeb.php';
require_once dirname(__DIR__) . '/lib/CapturadorPantalla.php';
require_once dirname(__DIR__) . '/lib/GeneradorPrompt.php';
require_once dirname(__DIR__) . '/lib/GestorConfiguracionGemini.php';
require_once dirname(__DIR__) . '/lib/ClienteGemini.php';

/**
 * @param array<string, mixed> $datos
 */
function enviarRespuestaJson(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array<string, mixed>
 */
function leerEntradaPeticion(): array
{
    $tipoContenido = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if (stripos($tipoContenido, 'application/json') !== false) {
        $bruto = file_get_contents('php://input');
        $datos = json_decode($bruto ?: '', true);
        return is_array($datos) ? $datos : [];
    }

    return $_POST;
}

/**
 * @param array<string, mixed> $contexto
 * @param array{api_key: string, modelo: string}|null $config
 * @return array{prompt: string, fuente: string, aviso?: string}
 */
function resolverPrompt(array $contexto, GeneradorPrompt $generador, ?array $config = null): array
{
    if ($config === null) {
        $config = (new GestorConfiguracionGemini())->obtener();
    }
    $modo = (string) ($contexto['modo'] ?? 'repositorio');

    if ($config['api_key'] === '') {
        if ($modo === 'manual') {
            $prompt = $generador->construirPromptManual(
                (string) ($contexto['titulo'] ?? ''),
                (string) ($contexto['descripcion'] ?? '')
            );
        } elseif ($modo === 'web') {
            $prompt = $generador->construirPromptDesdeWeb(
                is_array($contexto['analisisWeb'] ?? null) ? $contexto['analisisWeb'] : []
            );
        } else {
            $prompt = $generador->construirPromptDesdeRepositorio([
                'metadatos' => $contexto['metadatos'] ?? [],
                'lenguajes' => $contexto['lenguajes'] ?? [],
                'readme' => (string) ($contexto['readme'] ?? ''),
                'arbol' => $contexto['arbol'] ?? [],
                'archivoConfig' => $contexto['archivoConfig'] ?? null,
            ]);
        }

        return [
            'prompt' => $prompt,
            'fuente' => 'plantilla',
        ];
    }

    $clienteGemini = new ClienteGemini();
    try {
        $prompt = $clienteGemini->generarPromptDesdeContexto(
            $config['api_key'],
            $config['modelo'],
            $contexto
        );

        return [
            'prompt' => $prompt,
            'fuente' => 'gemini',
        ];
    } catch (RuntimeException $excepcion) {
        $mensaje = strtolower($excepcion->getMessage());
        $esSaturacion = str_contains($mensaje, '(503)')
            || str_contains($mensaje, 'saturado')
            || str_contains($mensaje, 'high demand')
            || str_contains($mensaje, '(429)')
            || str_contains($mensaje, 'quota');

        if (!$esSaturacion) {
            throw $excepcion;
        }

        // Si Gemini está saturado tras todos los failover, devolver plantilla local.
        if ($modo === 'manual') {
            $prompt = $generador->construirPromptManual(
                (string) ($contexto['titulo'] ?? ''),
                (string) ($contexto['descripcion'] ?? '')
            );
        } elseif ($modo === 'web') {
            $prompt = $generador->construirPromptDesdeWeb(
                is_array($contexto['analisisWeb'] ?? null) ? $contexto['analisisWeb'] : []
            );
        } else {
            $prompt = $generador->construirPromptDesdeRepositorio([
                'metadatos' => $contexto['metadatos'] ?? [],
                'lenguajes' => $contexto['lenguajes'] ?? [],
                'readme' => (string) ($contexto['readme'] ?? ''),
                'arbol' => $contexto['arbol'] ?? [],
                'archivoConfig' => $contexto['archivoConfig'] ?? null,
            ]);
        }

        return [
            'prompt' => $prompt,
            'fuente' => 'plantilla',
            'aviso' => 'Gemini estaba saturado; se usó la plantilla local.',
        ];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    enviarRespuestaJson([
        'exito' => false,
        'error' => 'Método no permitido. Usa POST.',
    ], 405);
}

$entrada = leerEntradaPeticion();
$modo = (string) ($entrada['modo'] ?? 'repositorio');
$generador = new GeneradorPrompt();
$gestorConfig = new GestorConfiguracionGemini();
$configGemini = $gestorConfig->obtener();

try {
    if ($modo === 'manual') {
        $titulo = (string) ($entrada['titulo'] ?? '');
        $descripcion = (string) ($entrada['descripcion'] ?? '');

        if (trim($titulo) === '' && trim($descripcion) === '') {
            enviarRespuestaJson([
                'exito' => false,
                'error' => 'Indica un título o una descripción para el control manual.',
            ], 400);
        }

        $resultado = resolverPrompt([
            'modo' => 'manual',
            'titulo' => $titulo,
            'descripcion' => $descripcion,
        ], $generador, $configGemini);

        $respuestaManual = [
            'exito' => true,
            'modo' => 'manual',
            'fuente' => $resultado['fuente'],
            'prompt' => $resultado['prompt'],
        ];
        if (!empty($resultado['aviso'])) {
            $respuestaManual['aviso'] = (string) $resultado['aviso'];
        }
        enviarRespuestaJson($respuestaManual);
    }

    if ($modo === 'web') {
        $analizador = new AnalizadorUrl();
        $url = $analizador->validarUrlHttpPublica((string) ($entrada['url'] ?? ''));

        $clienteWeb = new ClienteWeb();
        $analisis = $clienteWeb->analizarPagina($url);

        $contexto = [
            'modo' => 'web',
            'url' => $url,
            'analisisWeb' => $analisis,
        ];

        $hayGemini = $configGemini['api_key'] !== '';
        if ($hayGemini) {
            $capturador = new CapturadorPantalla($clienteWeb->obtenerResolutor());
            try {
                $captura = $capturador->capturar($url);
                $contexto['capturaMime'] = $captura['mime'];
                $contexto['capturaBase64'] = $captura['base64'];
            } catch (RuntimeException $capturaError) {
                // Captura opcional: continuar solo con HTML/CSS.
            }
        }

        $resultado = resolverPrompt($contexto, $generador, $configGemini);

        $respuestaWeb = [
            'exito' => true,
            'modo' => 'web',
            'fuente' => $resultado['fuente'],
            'url' => $url,
            'titulo' => $analisis['titulo'] ?? '',
            'prompt' => $resultado['prompt'],
        ];
        if (!empty($resultado['aviso'])) {
            $respuestaWeb['aviso'] = (string) $resultado['aviso'];
        }
        enviarRespuestaJson($respuestaWeb);
    }

    if ($modo !== 'repositorio') {
        enviarRespuestaJson([
            'exito' => false,
            'error' => 'Modo no reconocido. Usa "repositorio", "web" o "manual".',
        ], 400);
    }

    $url = (string) ($entrada['url'] ?? '');
    $analizador = new AnalizadorUrl();
    $partes = $analizador->analizarUrlGitHub($url);

    $cliente = new ClienteGitHub();
    $metadatos = $cliente->obtenerMetadatosRepositorio($partes['propietario'], $partes['repositorio']);
    $rama = (string) ($metadatos['default_branch'] ?? 'main');

    $paquete = $cliente->obtenerLenguajesReadmeYArbol(
        $partes['propietario'],
        $partes['repositorio'],
        $rama
    );
    $lenguajes = $paquete['lenguajes'];
    $readme = $paquete['readme'];
    $arbol = $paquete['arbol'];

    $archivoConfig = $cliente->obtenerPrimerArchivoExistente(
        $partes['propietario'],
        $partes['repositorio'],
        [
            'package.json',
            'composer.json',
            'pyproject.toml',
            'Cargo.toml',
            'go.mod',
            'pom.xml',
            'requirements.txt',
        ],
        $rama,
        $arbol
    );

    $resultado = resolverPrompt([
        'modo' => 'repositorio',
        'metadatos' => $metadatos,
        'lenguajes' => $lenguajes,
        'readme' => $readme,
        'arbol' => $arbol,
        'archivoConfig' => $archivoConfig,
    ], $generador, $configGemini);

    $respuestaRepo = [
        'exito' => true,
        'modo' => 'repositorio',
        'fuente' => $resultado['fuente'],
        'repositorio' => $metadatos['full_name'] ?? ($partes['propietario'] . '/' . $partes['repositorio']),
        'url' => $metadatos['html_url'] ?? $url,
        'prompt' => $resultado['prompt'],
    ];
    if (!empty($resultado['aviso'])) {
        $respuestaRepo['aviso'] = (string) $resultado['aviso'];
    }
    enviarRespuestaJson($respuestaRepo);
} catch (InvalidArgumentException $excepcion) {
    enviarRespuestaJson([
        'exito' => false,
        'error' => $excepcion->getMessage(),
    ], 400);
} catch (RuntimeException $excepcion) {
    $mensaje = $excepcion->getMessage();
    $codigo = str_contains($mensaje, '[404]') ? 404 : 502;

    enviarRespuestaJson([
        'exito' => false,
        'error' => $mensaje,
    ], $codigo);
} catch (Throwable $excepcion) {
    enviarRespuestaJson([
        'exito' => false,
        'error' => 'Error inesperado al generar el prompt.',
    ], 500);
}
