<?php
/**
 * Cliente mínimo para la API REST de Gemini (generateContent).
 */
class ClienteGemini
{
    private string $urlBase = 'https://generativelanguage.googleapis.com/v1beta';
    private int $tiempoEspera = 120;
    private int $tiempoConexion = 15;

    /** @var list<string> Reserva corta: menos espera ante 503. */
    private array $modelosReserva = [
        'gemini-3.1-flash-lite',
        'gemini-3.8-flash',
        'gemini-3.5-flash-lite',
    ];

    private string $instruccionSistema = <<<'TXT'
Eres un ingeniero de software senior. Tu única salida debe ser un prompt de desarrollo en español, listo para pegar en otra IA o entregárselo a un equipo.

El prompt debe ser amplio, concreto y tecnológico: stack, arquitectura, módulos, requisitos de implementación, criterios de calidad y formato de respuesta esperado. Usa solo la información del contexto aportado; no inventes archivos o dependencias que no estén respaldados por ese contexto, pero sí puedes inferir prácticas habituales del stack detectado.

Estructura el prompt con secciones Markdown claras (por ejemplo: Rol, Objetivo, Stack, Arquitectura, Estructura, Requisitos, Formato de respuesta). No envuelvas la respuesta en fences de código ni añadas preámbulos ni epílogos fuera del prompt.
TXT;

    private string $instruccionSistemaWeb = <<<'TXT'
Eres un diseñador y front-end senior. Tu única salida debe ser un prompt de desarrollo en español para recrear el tema visual y la distribución (layout) de una página web de referencia.

Prioriza: paleta, tipografía, espaciado, jerarquía, secciones, hero, navegación, responsive y atmósfera. Usa la captura de pantalla como fuente principal de verdad visual y el HTML/CSS como apoyo. No copies logos, marcas registradas ni textos propietarios; inspírate en estructura y estilo.

Estructura el prompt con secciones Markdown claras (Rol, Objetivo visual, Design tokens, Layout por secciones, Requisitos UI, Formato de respuesta). Sin fences ni preámbulos fuera del prompt.
TXT;

    /**
     * Genera un prompt de desarrollo a partir de un contexto estructurado.
     *
     * @param array{
     *   modo: string,
     *   titulo?: string,
     *   descripcion?: string,
     *   metadatos?: array<string, mixed>,
     *   lenguajes?: array<string, int>,
     *   readme?: string,
     *   arbol?: list<string>,
     *   archivoConfig?: array{ruta: string, contenido: string}|null,
     *   url?: string,
     *   analisisWeb?: array<string, mixed>,
     *   capturaMime?: string,
     *   capturaBase64?: string
     * } $contexto
     */
    public function generarPromptDesdeContexto(string $apiKey, string $modelo, array $contexto): string
    {
        $apiKey = trim($apiKey);
        $modelo = trim($modelo);

        if ($apiKey === '') {
            throw new InvalidArgumentException('Falta la API key de Gemini.');
        }

        if ($modelo === '' || !preg_match('/^[a-zA-Z0-9._\-]+$/', $modelo)) {
            throw new InvalidArgumentException('El modelo de Gemini no es válido.');
        }

        $modo = (string) ($contexto['modo'] ?? 'repositorio');
        $textoUsuario = $this->construirMensajeUsuario($contexto);
        $system = $modo === 'web' ? $this->instruccionSistemaWeb : $this->instruccionSistema;
        $capturaMime = isset($contexto['capturaMime']) ? (string) $contexto['capturaMime'] : '';
        $capturaBase64 = isset($contexto['capturaBase64']) ? (string) $contexto['capturaBase64'] : '';
        $candidatos = array_values(array_unique(array_merge([$modelo], $this->modelosReserva)));
        $ultimoError = null;

        foreach ($candidatos as $indice => $modeloActual) {
            try {
                return $this->generarConModelo(
                    $apiKey,
                    $modeloActual,
                    $textoUsuario,
                    $system,
                    $capturaMime,
                    $capturaBase64
                );
            } catch (RuntimeException $excepcion) {
                $ultimoError = $excepcion;

                if (!$this->esErrorSaturacion($excepcion) && !$this->esErrorModeloNoDisponible($excepcion)) {
                    throw $excepcion;
                }

                // Si falla con captura por saturación, reintentar el mismo modelo solo con texto.
                if (
                    $capturaBase64 !== ''
                    && $this->esErrorSaturacion($excepcion)
                ) {
                    try {
                        return $this->generarConModelo(
                            $apiKey,
                            $modeloActual,
                            $textoUsuario,
                            $system,
                            '',
                            ''
                        );
                    } catch (RuntimeException $sinCaptura) {
                        $ultimoError = $sinCaptura;
                        if (!$this->esErrorSaturacion($sinCaptura) && !$this->esErrorModeloNoDisponible($sinCaptura)) {
                            throw $sinCaptura;
                        }
                    }
                }

                if ($indice < count($candidatos) - 1) {
                    usleep(350_000);
                }
            }
        }

        throw $ultimoError ?? new RuntimeException('No se pudo generar el prompt con Gemini.');
    }

    private function esErrorSaturacion(RuntimeException $excepcion): bool
    {
        $mensaje = strtolower($excepcion->getMessage());
        return str_contains($mensaje, '(503)')
            || str_contains($mensaje, 'saturado')
            || str_contains($mensaje, 'high demand')
            || str_contains($mensaje, '(429)')
            || str_contains($mensaje, 'resource_exhausted')
            || str_contains($mensaje, 'quota');
    }

    private function esErrorModeloNoDisponible(RuntimeException $excepcion): bool
    {
        $mensaje = strtolower($excepcion->getMessage());
        return str_contains($mensaje, '(404)')
            || str_contains($mensaje, 'not found')
            || str_contains($mensaje, 'is not found')
            || str_contains($mensaje, 'not supported');
    }

    private function generarConModelo(
        string $apiKey,
        string $modelo,
        string $textoUsuario,
        string $instruccionSistema,
        string $capturaMime = '',
        string $capturaBase64 = ''
    ): string {
        $generationConfig = [
            'temperature' => 0.4,
            'maxOutputTokens' => 8192,
        ];

        // thinkingConfig no es fiable en todos los Flash-Lite.
        if (!str_contains($modelo, 'lite')) {
            $generationConfig['thinkingConfig'] = [
                'thinkingLevel' => 'low',
            ];
        }

        $partsUsuario = [
            ['text' => $textoUsuario],
        ];

        if ($capturaBase64 !== '' && $capturaMime !== '') {
            $partsUsuario[] = [
                'inline_data' => [
                    'mime_type' => $capturaMime,
                    'data' => $capturaBase64,
                ],
            ];
        }

        $cuerpoPeticion = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $instruccionSistema],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $partsUsuario,
                ],
            ],
            'generationConfig' => $generationConfig,
        ];

        $url = sprintf(
            '%s/models/%s:generateContent',
            $this->urlBase,
            rawurlencode($modelo)
        );

        $respuesta = $this->solicitarJsonConReintento($url, $apiKey, $cuerpoPeticion);
        $texto = $this->extraerTexto($respuesta);

        if ($texto === '') {
            throw new RuntimeException('Gemini devolvió una respuesta vacía.');
        }

        return $texto;
    }

    /**
     * Hasta 2 intentos ante 503/429 temporal, con breve espera.
     *
     * @param array<string, mixed> $cuerpo
     * @return array<string, mixed>
     */
    private function solicitarJsonConReintento(string $url, string $apiKey, array $cuerpo): array
    {
        try {
            return $this->solicitarJson($url, $apiKey, $cuerpo);
        } catch (RuntimeException $excepcion) {
            if (!$this->esErrorSaturacion($excepcion)) {
                throw $excepcion;
            }
            usleep(700_000);
            return $this->solicitarJson($url, $apiKey, $cuerpo);
        }
    }

    /**
     * @param array<string, mixed> $contexto
     */
    private function construirMensajeUsuario(array $contexto): string
    {
        $modo = (string) ($contexto['modo'] ?? 'repositorio');

        if ($modo === 'manual') {
            $titulo = trim((string) ($contexto['titulo'] ?? ''));
            $descripcion = trim((string) ($contexto['descripcion'] ?? ''));

            return "Modo: manual (sin repositorio GitHub).\n\n"
                . "Título del proyecto:\n{$titulo}\n\n"
                . "Descripción o notas:\n{$descripcion}\n\n"
                . 'Genera un prompt de desarrollo completo y tecnológico para construir este proyecto.';
        }

        if ($modo === 'web') {
            $analisis = $contexto['analisisWeb'] ?? [];
            if (!is_array($analisis)) {
                $analisis = [];
            }
            $url = (string) ($contexto['url'] ?? ($analisis['url'] ?? ''));
            $titulo = (string) ($analisis['titulo'] ?? '');
            $descripcion = (string) ($analisis['descripcion'] ?? '');
            $headings = $analisis['headings'] ?? [];
            $clases = $analisis['clasesFrecuentes'] ?? [];
            $texto = (string) ($analisis['textoVisible'] ?? '');
            $css = (string) ($analisis['resumenCss'] ?? '');
            $headingsTexto = is_array($headings) && $headings !== []
                ? implode("\n", array_map(static fn ($h) => '- ' . $h, array_slice($headings, 0, 30)))
                : '- No detectados';
            $clasesTexto = is_array($clases) && $clases !== []
                ? implode(', ', array_slice($clases, 0, 40))
                : 'Ninguna';
            if (mb_strlen($texto) > 6000) {
                $texto = mb_substr($texto, 0, 6000) . "\n…[recortado]";
            }
            if (mb_strlen($css) > 4000) {
                $css = mb_substr($css, 0, 4000) . "\n…[recortado]";
            }

            return <<<CTX
Modo: página web (recreación de tema y distribución).

URL: {$url}
Título: {$titulo}
Meta description: {$descripcion}

Headings:
{$headingsTexto}

Clases frecuentes: {$clasesTexto}

Texto visible (extracto):
{$texto}

Resumen CSS / tokens:
{$css}

Adjunta una captura de pantalla de la página. Genera un prompt completo para recrear el tema visual y el layout (no clones marca ni copy propietario).
CTX;
        }

        $metadatos = $contexto['metadatos'] ?? [];
        if (!is_array($metadatos)) {
            $metadatos = [];
        }

        $nombre = (string) ($metadatos['full_name'] ?? 'desconocido');
        $descripcion = (string) ($metadatos['description'] ?? '');
        $url = (string) ($metadatos['html_url'] ?? '');
        $rama = (string) ($metadatos['default_branch'] ?? 'main');
        $temas = $metadatos['topics'] ?? [];
        $temasTexto = is_array($temas) && $temas !== []
            ? implode(', ', array_map('strval', $temas))
            : 'Ninguno';
        $licencia = $metadatos['license']['spdx_id']
            ?? ($metadatos['license']['name'] ?? 'No indicada');

        $lenguajes = $contexto['lenguajes'] ?? [];
        $lenguajesTexto = $this->formatearLenguajes(is_array($lenguajes) ? $lenguajes : []);

        $arbol = $contexto['arbol'] ?? [];
        $arbolTexto = is_array($arbol) && $arbol !== []
            ? implode("\n", array_map(static fn ($ruta) => '- ' . $ruta, array_slice($arbol, 0, 80)))
            : 'No disponible';

        $readme = trim((string) ($contexto['readme'] ?? ''));
        if ($readme === '') {
            $readme = 'No hay README disponible.';
        } elseif (mb_strlen($readme) > 6000) {
            $readme = mb_substr($readme, 0, 6000) . "\n…[contenido recortado]";
        }

        $bloqueConfig = 'No se detectó un archivo de configuración principal.';
        $archivoConfig = $contexto['archivoConfig'] ?? null;
        if (is_array($archivoConfig) && isset($archivoConfig['ruta'], $archivoConfig['contenido'])) {
            $contenidoConfig = (string) $archivoConfig['contenido'];
            if (mb_strlen($contenidoConfig) > 3000) {
                $contenidoConfig = mb_substr($contenidoConfig, 0, 3000) . "\n…[contenido recortado]";
            }
            $bloqueConfig = "Archivo: {$archivoConfig['ruta']}\n{$contenidoConfig}";
        }

        return <<<CTX
Modo: repositorio GitHub.

Nombre: {$nombre}
URL: {$url}
Descripción: {$descripcion}
Rama por defecto: {$rama}
Temas: {$temasTexto}
Licencia: {$licencia}

Lenguajes detectados:
{$lenguajesTexto}

Estructura de archivos observada (parcial):
{$arbolTexto}

README (parcial):
{$readme}

Configuración clave:
{$bloqueConfig}

Genera un prompt de desarrollo completo, amplio y tecnológico para reconstruir o extender este proyecto.
CTX;
    }

    /**
     * @param array<string, int|float> $lenguajes
     */
    private function formatearLenguajes(array $lenguajes): string
    {
        if ($lenguajes === []) {
            return 'No detectados';
        }

        arsort($lenguajes);
        $total = array_sum($lenguajes);
        if ($total <= 0) {
            return 'No detectados';
        }

        $lineas = [];
        foreach ($lenguajes as $nombre => $bytes) {
            $porcentaje = round(((float) $bytes / (float) $total) * 100, 1);
            $lineas[] = "- {$nombre}: {$porcentaje}%";
        }

        return implode("\n", $lineas);
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @return array<string, mixed>
     */
    private function solicitarJson(string $url, string $apiKey, array $cuerpo): array
    {
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo preparar la petición a Gemini.');
        }

        // Las API keys modernas (p. ej. prefijo AQ.) fallan/timeout si van en ?key=;
        // hay que enviarlas en la cabecera x-goog-api-key.
        $cabeceras = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: reVERSEand0/1.0',
            'x-goog-api-key: ' . $apiKey,
        ];

        if (function_exists('curl_init')) {
            $texto = $this->solicitarConCurl($url, $cabeceras, $json);
        } else {
            $texto = $this->solicitarConStream($url, $cabeceras, $json);
        }

        $datos = json_decode($texto, true);
        if (!is_array($datos)) {
            throw new RuntimeException('La respuesta de Gemini no es JSON válido.');
        }

        return $datos;
    }

    /**
     * @param list<string> $cabeceras
     */
    private function solicitarConCurl(string $url, array $cabeceras, string $cuerpo): string
    {
        $manejador = curl_init($url);
        if ($manejador === false) {
            throw new RuntimeException('No se pudo iniciar la petición a Gemini.');
        }

        curl_setopt_array($manejador, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->tiempoConexion,
            CURLOPT_TIMEOUT => $this->tiempoEspera,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cuerpo,
            CURLOPT_HTTPHEADER => $cabeceras,
        ]);

        $respuesta = curl_exec($manejador);
        $codigo = (int) curl_getinfo($manejador, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($manejador);
        curl_close($manejador);

        if ($respuesta === false) {
            throw new RuntimeException('Fallo de red al contactar Gemini: ' . $errorCurl);
        }

        $this->lanzarSiErrorHttp($codigo, (string) $respuesta);
        return (string) $respuesta;
    }

    /**
     * @param list<string> $cabeceras
     */
    private function solicitarConStream(string $url, array $cabeceras, string $cuerpo): string
    {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $cabeceras),
                'content' => $cuerpo,
                'timeout' => $this->tiempoEspera,
                'ignore_errors' => true,
            ],
        ]);

        $respuesta = @file_get_contents($url, false, $contexto);
        if ($respuesta === false) {
            throw new RuntimeException('Fallo de red al contactar Gemini.');
        }

        $codigo = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $codigo = (int) $m[1];
        }

        $this->lanzarSiErrorHttp($codigo, $respuesta);
        return $respuesta;
    }

    private function lanzarSiErrorHttp(int $codigo, string $cuerpo): void
    {
        if ($codigo >= 200 && $codigo < 300) {
            return;
        }

        $detalle = $this->extraerMensajeError($cuerpo);

        if ($codigo === 400) {
            throw new RuntimeException(
                'Gemini rechazó la petición (solicitud inválida).' . ($detalle !== '' ? ' ' . $detalle : '')
            );
        }

        if ($codigo === 401 || $codigo === 403) {
            throw new RuntimeException(
                'API key de Gemini inválida o sin permiso. Revisa la configuración.'
                . ($detalle !== '' ? ' ' . $detalle : '')
            );
        }

        if ($codigo === 429) {
            throw new RuntimeException(
                'Límite de cuota de Gemini alcanzado. Intenta de nuevo más tarde.'
            );
        }

        if ($codigo === 503) {
            throw new RuntimeException(
                'Gemini está saturado ahora mismo (503). Espera unos segundos e inténtalo de nuevo.'
                . ($detalle !== '' ? ' ' . $detalle : '')
            );
        }

        throw new RuntimeException(
            'Error al consultar Gemini (' . $codigo . ').'
            . ($detalle !== '' ? ' ' . $detalle : '')
        );
    }

    private function extraerMensajeError(string $cuerpo): string
    {
        $json = json_decode($cuerpo, true);
        if (!is_array($json)) {
            return '';
        }

        $mensaje = $json['error']['message'] ?? null;
        return is_string($mensaje) ? trim($mensaje) : '';
    }

    /**
     * @param array<string, mixed> $respuesta
     */
    private function extraerTexto(array $respuesta): string
    {
        $candidatos = $respuesta['candidates'] ?? null;
        if (!is_array($candidatos) || $candidatos === []) {
            $bloqueo = $respuesta['promptFeedback']['blockReason'] ?? null;
            if (is_string($bloqueo) && $bloqueo !== '') {
                throw new RuntimeException('Gemini bloqueó la generación: ' . $bloqueo);
            }
            return '';
        }

        $candidato = $candidatos[0];
        if (!is_array($candidato)) {
            return '';
        }

        $parts = $candidato['content']['parts'] ?? null;
        if (!is_array($parts)) {
            return '';
        }

        $fragmentos = [];
        foreach ($parts as $parte) {
            if (!is_array($parte) || !isset($parte['text']) || !is_string($parte['text'])) {
                continue;
            }
            // Ignorar partes de "thinking" si el modelo las devuelve.
            if (!empty($parte['thought'])) {
                continue;
            }
            $fragmentos[] = $parte['text'];
        }

        return trim(implode("\n", $fragmentos));
    }
}
