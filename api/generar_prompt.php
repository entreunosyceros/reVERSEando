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
 * @param list<array{id: string, etiqueta: string, estado: string, detalle: string}> $items
 * @return array{items: list<array{id: string, etiqueta: string, estado: string, detalle: string}>}
 */
function empaquetarCalidad(array $items): array
{
    return ['items' => $items];
}

/**
 * @param array<string, mixed> $analisis
 * @param 'ok'|'fallida'|'omitida' $estadoCaptura
 * @param string $detalleCaptura
 * @return array{items: list<array{id: string, etiqueta: string, estado: string, detalle: string}>}
 */
function construirCalidadWeb(array $analisis, string $estadoCaptura, string $detalleCaptura = ''): array
{
    $diag = is_array($analisis['diagnostico'] ?? null) ? $analisis['diagnostico'] : [];
    $htmlOk = (bool) ($diag['htmlOk'] ?? true);
    $cssEnlazadas = (int) ($diag['cssEnlazadas'] ?? 0);
    $cssObtenidas = (int) ($diag['cssObtenidas'] ?? 0);
    $cssFallidas = is_array($diag['cssFallidas'] ?? null) ? $diag['cssFallidas'] : [];
    $dinamico = (bool) ($diag['dinamico'] ?? false);
    $dinamicoDetalle = (string) ($diag['dinamicoDetalle'] ?? '');
    $estructura = is_array($analisis['estructura'] ?? null) ? $analisis['estructura'] : [];
    $zonas = is_array($estructura['zonas'] ?? null) ? count($estructura['zonas']) : 0;
    $enlacesNav = is_array($estructura['navegacion'] ?? null) ? count($estructura['navegacion']) : 0;
    $formularios = is_array($estructura['formularios'] ?? null) ? count($estructura['formularios']) : 0;
    $paginasIntentadas = (int) ($diag['paginasIntentadas'] ?? 0);
    $paginasObtenidas = (int) ($diag['paginasObtenidas'] ?? 0);
    $paginasFallidas = is_array($diag['paginasFallidas'] ?? null) ? count($diag['paginasFallidas']) : 0;

    if ($cssEnlazadas === 0) {
        $cssEstado = 'aviso';
        $cssDetalle = 'No se detectaron hojas CSS enlazadas.';
    } elseif ($cssObtenidas === 0) {
        $cssEstado = 'no';
        $cssDetalle = 'Ninguna de las ' . $cssEnlazadas . ' hojas CSS se pudo descargar.';
    } elseif ($cssObtenidas < $cssEnlazadas) {
        $cssEstado = 'parcial';
        $cssDetalle = $cssObtenidas . ' de ' . $cssEnlazadas . ' hojas CSS disponibles.';
    } else {
        $cssEstado = 'ok';
        $cssDetalle = $cssObtenidas . ' hoja(s) CSS analizadas.';
    }
    if (!empty($diag['cssEnLinea'])) {
        if ($cssEstado === 'aviso' || $cssEstado === 'no') {
            $cssEstado = 'parcial';
        }
        $cssDetalle .= ' Incluye CSS en línea (<style>).';
    }

    $capturaMap = [
        'ok' => ['estado' => 'ok', 'detalle' => $detalleCaptura !== '' ? $detalleCaptura : 'Captura con Chromium realizada.'],
        'fallida' => ['estado' => 'no', 'detalle' => $detalleCaptura !== '' ? $detalleCaptura : 'La captura con Chromium falló.'],
        'omitida' => ['estado' => 'aviso', 'detalle' => $detalleCaptura !== '' ? $detalleCaptura : 'Captura omitida (sin Gemini o no requerida).'],
    ];
    $captura = $capturaMap[$estadoCaptura] ?? $capturaMap['omitida'];

    $items = [
        [
            'id' => 'html',
            'etiqueta' => 'HTML obtenido',
            'estado' => $htmlOk ? 'ok' : 'no',
            'detalle' => $htmlOk
                ? (!empty($diag['htmlRecortado']) ? 'HTML obtenido (recortado por tamaño).' : 'HTML obtenido correctamente.')
                : 'No se pudo obtener el HTML.',
        ],
        [
            'id' => 'css',
            'etiqueta' => 'CSS disponible',
            'estado' => $cssEstado,
            'detalle' => $cssDetalle,
        ],
        [
            'id' => 'captura',
            'etiqueta' => 'Captura realizada',
            'estado' => $captura['estado'],
            'detalle' => $captura['detalle'],
        ],
        [
            'id' => 'dinamico',
            'etiqueta' => 'Contenido dinámico',
            'estado' => $dinamico ? 'parcial' : 'ok',
            'detalle' => $dinamico
                ? ($dinamicoDetalle !== '' ? $dinamicoDetalle : 'Posible SPA o mucho JS: el análisis puede ser incompleto.')
                : 'No se detectó indicios claros de SPA/contenido solo-cliente.',
        ],
        [
            'id' => 'recursos',
            'etiqueta' => 'Recursos inaccesibles',
            'estado' => $cssFallidas === [] ? 'ok' : 'parcial',
            'detalle' => $cssFallidas === []
                ? 'Sin recursos CSS fallidos.'
                : count($cssFallidas) . ' hoja(s) CSS inaccesible(s).',
        ],
        [
            'id' => 'estructura',
            'etiqueta' => 'Estructura de la página',
            'estado' => $zonas > 0 ? 'ok' : ($enlacesNav > 0 ? 'parcial' : 'aviso'),
            'detalle' => $zonas > 0 || $enlacesNav > 0
                ? $zonas . ' zona(s), ' . $enlacesNav . ' enlace(s) de navegación, ' . $formularios . ' formulario(s).'
                : 'Sin etiquetas semánticas (header/nav/main/footer) detectables.',
        ],
        [
            'id' => 'paginas',
            'etiqueta' => 'Páginas internas',
            'estado' => $paginasObtenidas > 0
                ? ($paginasFallidas === 0 ? 'ok' : 'parcial')
                : ($paginasIntentadas > 0 ? 'no' : 'aviso'),
            'detalle' => ($paginasIntentadas > 0
                    ? $paginasObtenidas . ' de ' . $paginasIntentadas . ' página(s) interna(s) analizadas'
                    : 'No se encontraron páginas internas que analizar')
                . (!empty($diag['sitemapEncontrado']) ? '; sitemap encontrado.' : '; sin sitemap.'),
        ],
        [
            'id' => 'inferencias',
            'etiqueta' => 'Información inferida',
            'estado' => 'aviso',
            'detalle' => 'El prompt puede incluir inferencias etiquetadas; revisa Hecho observado / Inferencia / No verificable.',
        ],
    ];

    return empaquetarCalidad($items);
}

/**
 * @param array<string, mixed> $metadatos
 * @param array<string, int> $lenguajes
 * @param string $readme
 * @param list<string> $arbol
 * @param array{total?: int, truncado?: bool, rama?: string} $arbolInfo
 * @param list<array{ruta: string, contenido: string}> $manifiestos
 * @param list<array{ruta: string, contenido: string}> $puntosEntrada
 * @param array{rutas?: list<string>, modelos?: list<string>, pantallas?: list<string>, comandos?: list<string>, archivos?: int} $mapaCodigo
 * @return array{items: list<array{id: string, etiqueta: string, estado: string, detalle: string}>}
 */
function construirCalidadRepositorio(
    array $metadatos,
    array $lenguajes,
    string $readme,
    array $arbol,
    array $arbolInfo,
    array $manifiestos,
    array $puntosEntrada,
    array $mapaCodigo = []
): array {
    $tieneReadme = trim($readme) !== '';
    $tieneLenguajes = $lenguajes !== [];
    $tieneArbol = $arbol !== [];
    $totalArbol = (int) ($arbolInfo['total'] ?? count($arbol));
    $truncado = !empty($arbolInfo['truncado']);
    $rutasManifiestos = array_map(static fn (array $m): string => (string) $m['ruta'], $manifiestos);
    $rutasEntrada = array_map(static fn (array $p): string => (string) $p['ruta'], $puntosEntrada);

    $items = [
        [
            'id' => 'metadatos',
            'etiqueta' => 'Metadatos del repositorio',
            'estado' => isset($metadatos['full_name']) || isset($metadatos['name']) ? 'ok' : 'parcial',
            'detalle' => isset($metadatos['full_name'])
                ? 'Repositorio ' . (string) $metadatos['full_name'] . '.'
                : 'Metadatos básicos obtenidos.',
        ],
        [
            'id' => 'lenguajes',
            'etiqueta' => 'Lenguajes detectados',
            'estado' => $tieneLenguajes ? 'ok' : 'aviso',
            'detalle' => $tieneLenguajes
                ? count($lenguajes) . ' lenguaje(s) reportados por GitHub.'
                : 'GitHub no reportó lenguajes.',
        ],
        [
            'id' => 'readme',
            'etiqueta' => 'README',
            'estado' => $tieneReadme ? 'ok' : 'aviso',
            'detalle' => $tieneReadme ? 'README disponible.' : 'Sin README usable.',
        ],
        [
            'id' => 'arbol',
            'etiqueta' => 'Árbol de archivos',
            'estado' => $tieneArbol ? ($truncado ? 'parcial' : 'ok') : 'no',
            'detalle' => $tieneArbol
                ? ($truncado
                    ? count($arbol) . ' rutas priorizadas de ' . $totalArbol . ' (árbol recortado; se incluye resumen por directorio).'
                    : $totalArbol . ' rutas en el árbol.')
                    . (isset($arbolInfo['rama']) ? ' Rama: ' . $arbolInfo['rama'] . '.' : '')
                : 'No se obtuvo el árbol de archivos.',
        ],
        [
            'id' => 'config',
            'etiqueta' => 'Manifiestos y configuración',
            'estado' => $rutasManifiestos !== [] ? 'ok' : 'aviso',
            'detalle' => $rutasManifiestos !== []
                ? 'Leídos: ' . implode(', ', $rutasManifiestos) . '.'
                : 'Sin package.json / composer.json / Dockerfile / equivalente detectado.',
        ],
        [
            'id' => 'entrada',
            'etiqueta' => 'Puntos de entrada',
            'estado' => $rutasEntrada !== [] ? 'ok' : 'aviso',
            'detalle' => $rutasEntrada !== []
                ? 'Leídos: ' . implode(', ', $rutasEntrada) . '.'
                : 'No se identificaron archivos main/index/app/routes.',
        ],
        [
            'id' => 'codigo',
            'etiqueta' => 'Mapa del código',
            'estado' => (int) ($mapaCodigo['archivos'] ?? 0) > 0 ? 'ok' : 'aviso',
            'detalle' => (int) ($mapaCodigo['archivos'] ?? 0) > 0
                ? (int) ($mapaCodigo['archivos'] ?? 0) . ' archivo(s) con señales: '
                    . count(is_array($mapaCodigo['rutas'] ?? null) ? $mapaCodigo['rutas'] : []) . ' rutas, '
                    . count(is_array($mapaCodigo['modelos'] ?? null) ? $mapaCodigo['modelos'] : []) . ' modelos, '
                    . count(is_array($mapaCodigo['pantallas'] ?? null) ? $mapaCodigo['pantallas'] : []) . ' pantallas, '
                    . count(is_array($mapaCodigo['comandos'] ?? null) ? $mapaCodigo['comandos'] : []) . ' comandos.'
                : 'No se extrajeron rutas, modelos ni pantallas del código.',
        ],
        [
            'id' => 'inferencias',
            'etiqueta' => 'Información inferida',
            'estado' => 'aviso',
            'detalle' => 'El prompt puede incluir inferencias etiquetadas a partir del README y la estructura.',
        ],
    ];

    return empaquetarCalidad($items);
}

/**
 * @return array{items: list<array{id: string, etiqueta: string, estado: string, detalle: string}>}
 */
function construirCalidadManual(string $titulo, string $descripcion): array
{
    $t = trim($titulo) !== '';
    $d = trim($descripcion) !== '';
    $items = [
        [
            'id' => 'titulo',
            'etiqueta' => 'Título',
            'estado' => $t ? 'ok' : 'aviso',
            'detalle' => $t ? 'Título indicado.' : 'Sin título; se usará la descripción.',
        ],
        [
            'id' => 'descripcion',
            'etiqueta' => 'Descripción',
            'estado' => $d ? 'ok' : 'aviso',
            'detalle' => $d ? 'Descripción indicada.' : 'Sin descripción; el prompt será más genérico.',
        ],
        [
            'id' => 'inferencias',
            'etiqueta' => 'Información inferida',
            'estado' => 'aviso',
            'detalle' => 'Sin fuente externa: gran parte del prompt será inferida o genérica.',
        ],
    ];

    return empaquetarCalidad($items);
}

/**
 * @param array<string, mixed> $contexto
 * @param list<string> $secciones
 * @param array{api_key: string, modelo: string}|null $config
 * @return array{prompt: string, fuente: string, aviso?: string}
 */
function resolverRegeneracion(
    string $promptActual,
    array $secciones,
    array $contexto,
    GeneradorPrompt $generador,
    ?array $config = null
): array {
    if ($config === null) {
        $config = (new GestorConfiguracionGemini())->obtener();
    }

    $modoOrigen = (string) ($contexto['modo'] ?? 'repositorio');
    $validas = array_values(array_intersect($secciones, GeneradorPrompt::SECCIONES));
    if ($validas === []) {
        throw new InvalidArgumentException('Indica al menos una sección válida a regenerar.');
    }

    if ($config['api_key'] === '') {
        return [
            'prompt' => $generador->regenerarSeccionesDesdePlantilla(
                $promptActual,
                $validas,
                $modoOrigen,
                $contexto
            ),
            'fuente' => 'plantilla',
        ];
    }

    $clienteGemini = new ClienteGemini();
    try {
        $prompt = $clienteGemini->regenerarSeccionesPrompt(
            $config['api_key'],
            $config['modelo'],
            $promptActual,
            $validas,
            $modoOrigen
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

        return [
            'prompt' => $generador->regenerarSeccionesDesdePlantilla(
                $promptActual,
                $validas,
                $modoOrigen,
                $contexto
            ),
            'fuente' => 'plantilla',
            'aviso' => 'Gemini estaba saturado; se regeneraron las secciones con la plantilla local.',
        ];
    }
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
    if ($config['api_key'] === '') {
        return [
            'prompt' => $generador->construirPromptDesdeContexto($contexto),
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
        return [
            'prompt' => $generador->construirPromptDesdeContexto($contexto),
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
    if ($modo === 'regenerar') {
        $promptActual = (string) ($entrada['promptActual'] ?? '');
        $secciones = is_array($entrada['secciones'] ?? null) ? $entrada['secciones'] : [];
        $contexto = is_array($entrada['contexto'] ?? null) ? $entrada['contexto'] : [];
        $modoOrigen = (string) ($entrada['modoOrigen'] ?? ($contexto['modo'] ?? 'repositorio'));
        $contexto['modo'] = $modoOrigen;

        if (trim($promptActual) === '') {
            enviarRespuestaJson([
                'exito' => false,
                'error' => 'Falta el prompt actual para regenerar secciones.',
            ], 400);
        }

        $resultado = resolverRegeneracion(
            $promptActual,
            array_map('strval', $secciones),
            $contexto,
            $generador,
            $configGemini
        );

        $respuestaRegen = [
            'exito' => true,
            'modo' => 'regenerar',
            'modoOrigen' => $modoOrigen,
            'fuente' => $resultado['fuente'],
            'prompt' => $resultado['prompt'],
            'secciones' => array_values(array_intersect(
                array_map('strval', $secciones),
                GeneradorPrompt::SECCIONES
            )),
        ];
        if (!empty($resultado['aviso'])) {
            $respuestaRegen['aviso'] = (string) $resultado['aviso'];
        }
        enviarRespuestaJson($respuestaRegen);
    }

    if ($modo === 'manual') {
        $titulo = (string) ($entrada['titulo'] ?? '');
        $descripcion = (string) ($entrada['descripcion'] ?? '');

        if (trim($titulo) === '' && trim($descripcion) === '') {
            enviarRespuestaJson([
                'exito' => false,
                'error' => 'Indica un título o una descripción para el control manual.',
            ], 400);
        }

        $contextoManual = [
            'modo' => 'manual',
            'titulo' => $titulo,
            'descripcion' => $descripcion,
        ];
        $resultado = resolverPrompt($contextoManual, $generador, $configGemini);

        $respuestaManual = [
            'exito' => true,
            'modo' => 'manual',
            'fuente' => $resultado['fuente'],
            'prompt' => $resultado['prompt'],
            'calidadAnalisis' => construirCalidadManual($titulo, $descripcion),
            'contextoRegeneracion' => $contextoManual,
            'secciones' => GeneradorPrompt::SECCIONES,
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
        $estadoCaptura = 'omitida';
        $detalleCaptura = 'Captura omitida: se usa solo HTML/CSS (sin Gemini).';
        if ($hayGemini) {
            $capturador = new CapturadorPantalla($clienteWeb->obtenerResolutor());
            try {
                $captura = $capturador->capturar($url);
                $contexto['capturaMime'] = $captura['mime'];
                $contexto['capturaBase64'] = $captura['base64'];
                $estadoCaptura = 'ok';
                $detalleCaptura = 'Captura con Chromium realizada.';
            } catch (RuntimeException $capturaError) {
                $estadoCaptura = 'fallida';
                $detalleCaptura = 'Captura fallida: ' . $capturaError->getMessage();
            }
        }

        $resultado = resolverPrompt($contexto, $generador, $configGemini);

        // Sin imagen en el contexto que se reenvía al regenerar.
        $contextoLigero = [
            'modo' => 'web',
            'url' => $url,
            'analisisWeb' => $analisis,
        ];

        $respuestaWeb = [
            'exito' => true,
            'modo' => 'web',
            'fuente' => $resultado['fuente'],
            'url' => $url,
            'titulo' => $analisis['titulo'] ?? '',
            'prompt' => $resultado['prompt'],
            'calidadAnalisis' => construirCalidadWeb($analisis, $estadoCaptura, $detalleCaptura),
            'contextoRegeneracion' => $contextoLigero,
            'secciones' => GeneradorPrompt::SECCIONES,
        ];
        if (!empty($resultado['aviso'])) {
            $respuestaWeb['aviso'] = (string) $resultado['aviso'];
        }
        enviarRespuestaJson($respuestaWeb);
    }

    if ($modo !== 'repositorio') {
        enviarRespuestaJson([
            'exito' => false,
            'error' => 'Modo no reconocido. Usa "repositorio", "web", "manual" o "regenerar".',
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
    $arbolInfo = $paquete['arbolInfo'];

    $archivosClave = $cliente->obtenerArchivosClave(
        $partes['propietario'],
        $partes['repositorio'],
        $arbolInfo['rama'],
        $paquete['rutasTodas']
    );

    $contextoRepo = [
        'modo' => 'repositorio',
        'metadatos' => $metadatos,
        'lenguajes' => $lenguajes,
        'readme' => $readme,
        'arbol' => $arbol,
        'arbolInfo' => $arbolInfo,
        'manifiestos' => $archivosClave['manifiestos'],
        'puntosEntrada' => $archivosClave['puntosEntrada'],
        'mapaCodigo' => $archivosClave['mapaCodigo'],
        'archivoConfig' => $archivosClave['manifiestos'][0] ?? null,
    ];
    $resultado = resolverPrompt($contextoRepo, $generador, $configGemini);

    $respuestaRepo = [
        'exito' => true,
        'modo' => 'repositorio',
        'fuente' => $resultado['fuente'],
        'repositorio' => $metadatos['full_name'] ?? ($partes['propietario'] . '/' . $partes['repositorio']),
        'url' => $metadatos['html_url'] ?? $url,
        'prompt' => $resultado['prompt'],
        'calidadAnalisis' => construirCalidadRepositorio(
            $metadatos,
            $lenguajes,
            $readme,
            $arbol,
            $arbolInfo,
            $archivosClave['manifiestos'],
            $archivosClave['puntosEntrada'],
            $archivosClave['mapaCodigo']
        ),
        'contextoRegeneracion' => $contextoRepo,
        'secciones' => GeneradorPrompt::SECCIONES,
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
