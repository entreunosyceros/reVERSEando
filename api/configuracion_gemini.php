<?php
/**
 * Endpoint JSON para leer y guardar la configuración local de Gemini.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/lib/GestorConfiguracionGemini.php';

/**
 * @param array<string, mixed> $datos
 */
function enviarRespuestaConfig(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array<string, mixed>
 */
function leerEntradaConfig(): array
{
    $tipoContenido = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if (stripos($tipoContenido, 'application/json') !== false) {
        $bruto = file_get_contents('php://input');
        $datos = json_decode($bruto ?: '', true);
        return is_array($datos) ? $datos : [];
    }

    return $_POST;
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$gestor = new GestorConfiguracionGemini();

try {
    if ($metodo === 'GET') {
        enviarRespuestaConfig([
            'exito' => true,
            'configuracion' => $gestor->resumenPublico(),
        ]);
    }

    if ($metodo === 'POST') {
        $entrada = leerEntradaConfig();
        $modelo = (string) ($entrada['modelo'] ?? 'gemini-3.5-flash-lite');

        $payload = [
            'modelo' => $modelo,
            'limpiar_api_key' => !empty($entrada['limpiar_api_key']),
        ];

        if (array_key_exists('api_key', $entrada)) {
            $payload['api_key'] = (string) $entrada['api_key'];
        }

        $gestor->guardar($payload);

        enviarRespuestaConfig([
            'exito' => true,
            'mensaje' => 'Configuración de Gemini guardada.',
            'configuracion' => $gestor->resumenPublico(),
        ]);
    }

    enviarRespuestaConfig([
        'exito' => false,
        'error' => 'Método no permitido. Usa GET o POST.',
    ], 405);
} catch (InvalidArgumentException $excepcion) {
    enviarRespuestaConfig([
        'exito' => false,
        'error' => $excepcion->getMessage(),
    ], 400);
} catch (RuntimeException $excepcion) {
    enviarRespuestaConfig([
        'exito' => false,
        'error' => $excepcion->getMessage(),
    ], 500);
} catch (Throwable $excepcion) {
    enviarRespuestaConfig([
        'exito' => false,
        'error' => 'Error inesperado al gestionar la configuración de Gemini.',
    ], 500);
}
