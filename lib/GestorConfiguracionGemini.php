<?php
/**
 * Lectura y escritura de la configuración local de Gemini.
 */
class GestorConfiguracionGemini
{
    private string $rutaArchivo;
    private string $modeloPorDefecto = 'gemini-3.5-flash-lite';

    public function __construct(?string $rutaArchivo = null)
    {
        $this->rutaArchivo = $rutaArchivo
            ?? (dirname(__DIR__) . '/config/gemini.json');
    }

    /**
     * @return array{api_key: string, modelo: string}
     */
    public function obtener(): array
    {
        if (!is_file($this->rutaArchivo)) {
            return $this->valoresPorDefecto();
        }

        $bruto = file_get_contents($this->rutaArchivo);
        if ($bruto === false || trim($bruto) === '') {
            return $this->valoresPorDefecto();
        }

        $datos = json_decode($bruto, true);
        if (!is_array($datos)) {
            throw new RuntimeException('La configuración de Gemini no es un JSON válido.');
        }

        $apiKey = trim((string) ($datos['api_key'] ?? ''));
        $modelo = trim((string) ($datos['modelo'] ?? ''));

        if ($modelo === '' || in_array($modelo, ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'], true)) {
            $modelo = $this->modeloPorDefecto;
        }

        return [
            'api_key' => $apiKey,
            'modelo' => $modelo,
        ];
    }

    /**
     * @param array{api_key?: string, modelo?: string} $datos
     */
    public function guardar(array $datos): void
    {
        $actual = $this->obtener();
        $limpiarApiKey = !empty($datos['limpiar_api_key']);

        if ($limpiarApiKey) {
            $apiKey = '';
        } elseif (array_key_exists('api_key', $datos)) {
            $apiKey = trim((string) $datos['api_key']);
            // Vacío sin limpiar_api_key → conservar la key (p. ej. solo cambio de modelo).
            if ($apiKey === '' && $actual['api_key'] !== '') {
                $apiKey = $actual['api_key'];
            }
        } else {
            $apiKey = $actual['api_key'];
        }

        $modelo = trim((string) ($datos['modelo'] ?? ''));

        if ($modelo === '') {
            $modelo = $this->modeloPorDefecto;
        }

        // Modelos antiguos → default actual gratuito.
        if (in_array($modelo, ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'], true)) {
            $modelo = $this->modeloPorDefecto;
        }

        if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $modelo)) {
            throw new InvalidArgumentException('El nombre del modelo de Gemini no es válido.');
        }

        $directorio = dirname($this->rutaArchivo);
        if (!is_dir($directorio) && !mkdir($directorio, 0755, true) && !is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear el directorio de configuración.');
        }

        $payload = json_encode(
            [
                'api_key' => $apiKey,
                'modelo' => $modelo,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            throw new RuntimeException('No se pudo serializar la configuración de Gemini.');
        }

        $escrito = file_put_contents($this->rutaArchivo, $payload . "\n", LOCK_EX);
        if ($escrito === false) {
            throw new RuntimeException('No se pudo guardar la configuración de Gemini. Comprueba permisos de escritura.');
        }
    }

    public function estaConfigurado(): bool
    {
        $config = $this->obtener();
        return $config['api_key'] !== '';
    }

    /**
     * Resumen seguro para la UI (sin exponer la key completa).
     *
     * @return array{configurado: bool, sufijo_key: string|null, modelo: string}
     */
    public function resumenPublico(): array
    {
        $config = $this->obtener();
        $apiKey = $config['api_key'];
        $configurado = $apiKey !== '';

        return [
            'configurado' => $configurado,
            'sufijo_key' => $configurado ? substr($apiKey, -4) : null,
            'modelo' => $config['modelo'],
        ];
    }

    /**
     * @return array{api_key: string, modelo: string}
     */
    private function valoresPorDefecto(): array
    {
        return [
            'api_key' => '',
            'modelo' => $this->modeloPorDefecto,
        ];
    }
}
