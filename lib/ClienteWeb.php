<?php
/**
 * Cliente para obtener y resumir el HTML/CSS público de una página web (anti-SSRF).
 */
class ClienteWeb
{
    private int $tiempoEspera = 25;
    private int $maxCss = 3;
    private int $maxBytesCss = 100000;
    private int $maxBytesHtml = 800000;
    private int $maxRedirecciones = 3;
    private ResolutorDns $resolutor;
    private AnalizadorUrl $analizador;

    /** @var array<string, string> */
    private array $cacheDns = [];

    public function __construct(?ResolutorDns $resolutor = null, ?AnalizadorUrl $analizador = null)
    {
        $this->resolutor = $resolutor ?? new ResolutorDns();
        $this->analizador = $analizador ?? new AnalizadorUrl();
    }

    public function obtenerResolutor(): ResolutorDns
    {
        return $this->resolutor;
    }

    /**
     * @return array{
     *   url: string,
     *   titulo: string,
     *   descripcion: string,
     *   headings: list<string>,
     *   clasesFrecuentes: list<string>,
     *   textoVisible: string,
     *   resumenCss: string,
     *   hojasCss: list<string>,
     *   diagnostico: array{
     *     htmlOk: bool,
     *     cssEnlazadas: int,
     *     cssObtenidas: int,
     *     cssFallidas: list<string>,
     *     dinamico: bool,
     *     dinamicoDetalle: string
     *   }
     * }
     */
    public function analizarPagina(string $url): array
    {
        $html = $this->obtenerTexto($url, 'html');
        $htmlRecortado = false;
        if (strlen($html) > $this->maxBytesHtml) {
            $html = substr($html, 0, $this->maxBytesHtml);
            $htmlRecortado = true;
        }

        $titulo = $this->extraerTitulo($html);
        $descripcion = $this->extraerMetaDescription($html);
        $headings = $this->extraerHeadings($html);
        $clases = $this->extraerClasesFrecuentes($html);
        $textoVisible = $this->extraerTextoVisible($html);
        $urlsCss = $this->priorizarCssMismoOrigen($this->extraerUrlsCss($html, $url), $url);
        $urlsCss = array_slice($urlsCss, 0, $this->maxCss);

        $descargas = $this->obtenerTextos($urlsCss);
        $contenidosCss = [];
        $cssFallidas = [];
        foreach ($urlsCss as $urlCss) {
            if (!isset($descargas[$urlCss])) {
                $cssFallidas[] = $urlCss;
                continue;
            }
            $css = $descargas[$urlCss];
            if (strlen($css) > $this->maxBytesCss) {
                $css = substr($css, 0, $this->maxBytesCss) . "\n/* …recortado */";
            }
            $contenidosCss[] = "/* Fuente: {$urlCss} */\n" . $css;
        }

        $cssCombinado = implode("\n\n", $contenidosCss);
        $resumenCss = $this->resumirCss($cssCombinado);
        $dinamico = $this->detectarContenidoDinamico($html, $textoVisible);

        return [
            'url' => $url,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'headings' => $headings,
            'clasesFrecuentes' => $clases,
            'textoVisible' => $this->recortar($textoVisible, 7000),
            'resumenCss' => $resumenCss,
            'hojasCss' => $urlsCss,
            'diagnostico' => [
                'htmlOk' => true,
                'htmlRecortado' => $htmlRecortado,
                'cssEnlazadas' => count($urlsCss),
                'cssObtenidas' => count($contenidosCss),
                'cssFallidas' => $cssFallidas,
                'dinamico' => $dinamico['detectado'],
                'dinamicoDetalle' => $dinamico['detalle'],
            ],
        ];
    }

    /**
     * @return array{detectado: bool, detalle: string}
     */
    private function detectarContenidoDinamico(string $html, string $textoVisible): array
    {
        $scripts = preg_match_all('#<script\b#i', $html) ?: 0;
        $frameworks = (bool) preg_match(
            '/\b(react|vue|angular|next\.js|nuxt|svelte|__NEXT_DATA__|ng-version|data-reactroot)\b/i',
            $html
        );
        $ratioTexto = strlen($html) > 0 ? (strlen($textoVisible) / strlen($html)) : 1.0;
        $pocoTexto = $ratioTexto < 0.08 && strlen($html) > 20000;

        $detectado = $frameworks || ($scripts >= 8 && $pocoTexto) || ($scripts >= 15);
        $partes = [];
        if ($frameworks) {
            $partes[] = 'señales de framework SPA';
        }
        if ($scripts >= 8) {
            $partes[] = $scripts . ' etiquetas script';
        }
        if ($pocoTexto) {
            $partes[] = 'poco texto visible respecto al HTML';
        }

        return [
            'detectado' => $detectado,
            'detalle' => $detectado
                ? ('Posible contenido dinámico: ' . implode('; ', $partes))
                : 'No se detectaron indicios claros de SPA/render solo-cliente',
        ];
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function priorizarCssMismoOrigen(array $urls, string $urlBase): array
    {
        $hostBase = strtolower((string) parse_url($urlBase, PHP_URL_HOST));
        $mismo = [];
        $otros = [];
        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && $host === $hostBase) {
                $mismo[] = $url;
            } else {
                $otros[] = $url;
            }
        }
        return array_values(array_merge($mismo, $otros));
    }

    /**
     * @param list<string> $urls
     * @return array<string, string>
     */
    private function obtenerTextos(array $urls): array
    {
        $resultado = [];
        foreach (array_values(array_unique(array_filter($urls))) as $url) {
            try {
                $urlSegura = $this->analizador->validarUrlHttpPublica($url);
                $resultado[$url] = $this->obtenerTexto($urlSegura, 'css');
            } catch (Throwable $excepcion) {
                // Ignorar CSS individuales fallidos o no seguros.
            }
        }
        return $resultado;
    }

    /**
     * @param 'html'|'css' $tipo
     */
    private function obtenerTexto(string $url, string $tipo = 'html'): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL es obligatorio para el análisis web seguro.');
        }

        $urlActual = $this->analizador->validarUrlHttpPublica($url);
        $redirecciones = 0;

        while (true) {
            $host = strtolower((string) parse_url($urlActual, PHP_URL_HOST));
            $ipv4 = $this->resolverHostPublico($host);
            if ($ipv4 === null) {
                throw new RuntimeException('No se pudo resolver de forma segura el host: ' . $host);
            }

            $aceptar = $tipo === 'css'
                ? 'text/css,*/*;q=0.1'
                : 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1';

            $maxBytes = $tipo === 'css' ? $this->maxBytesCss : $this->maxBytesHtml;
            $cuerpoAcumulado = '';
            $abortadoPorTamano = false;
            $location = null;

            $manejador = curl_init($urlActual);
            if ($manejador === false) {
                throw new RuntimeException('No se pudo iniciar la petición a la página web.');
            }

            $opciones = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $this->tiempoEspera,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: reVERSEand0/1.0 (web analyzer)',
                    'Accept: ' . $aceptar,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => $this->resolutor->entradasCurlResolve($host, $ipv4),
                CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$location): int {
                    if (preg_match('/^Location:\s*(.+)$/i', trim($linea), $m) === 1) {
                        $location = trim($m[1]);
                    }
                    return strlen($linea);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$cuerpoAcumulado, &$abortadoPorTamano, $maxBytes): int {
                    $cuerpoAcumulado .= $chunk;
                    if (strlen($cuerpoAcumulado) > $maxBytes) {
                        $abortadoPorTamano = true;
                        $cuerpoAcumulado = substr($cuerpoAcumulado, 0, $maxBytes);
                        return 0;
                    }
                    return strlen($chunk);
                },
            ];

            curl_setopt_array($manejador, $opciones);
            $ok = curl_exec($manejador);
            $info = curl_getinfo($manejador);
            $codigo = (int) ($info['http_code'] ?? 0);
            $error = curl_error($manejador);
            $ipUsada = (string) ($info['primary_ip'] ?? '');
            $tipoContenido = strtolower((string) ($info['content_type'] ?? ''));
            curl_close($manejador);

            if ($ipUsada !== '' && strcasecmp($ipUsada, $ipv4) !== 0) {
                throw new RuntimeException('La IP de conexión no coincide con la IP validada (posible DNS rebinding).');
            }
            if ($ipUsada !== '' && $this->analizador->esIpPrivadaOReservada($ipUsada)) {
                throw new RuntimeException('Conexión a IP privada o reservada bloqueada.');
            }

            if (!$abortadoPorTamano && $ok === false && $cuerpoAcumulado === '' && !in_array($codigo, [301, 302, 303, 307, 308], true)) {
                throw new RuntimeException('Fallo de red al obtener la página: ' . $error);
            }

            if (in_array($codigo, [301, 302, 303, 307, 308], true)) {
                if ($redirecciones >= $this->maxRedirecciones) {
                    throw new RuntimeException('Demasiadas redirecciones al obtener la página.');
                }
                if ($location === null || $location === '') {
                    throw new RuntimeException('Redirección sin destino Location válido.');
                }
                $urlActual = $this->analizador->validarUrlHttpPublica(
                    $this->absolutizarUrl($urlActual, $location)
                );
                $redirecciones++;
                continue;
            }

            if ($codigo < 200 || $codigo >= 300) {
                throw new RuntimeException('La página respondió con error HTTP ' . $codigo . '.');
            }

            if (!$this->esTipoContenidoPermitido($tipoContenido, $tipo)) {
                throw new RuntimeException('Tipo de contenido no permitido: ' . ($tipoContenido !== '' ? $tipoContenido : 'desconocido'));
            }

            return $cuerpoAcumulado;
        }
    }

    private function absolutizarUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $partes = parse_url($base);
        if ($partes === false || empty($partes['scheme']) || empty($partes['host'])) {
            return $location;
        }
        $origen = $partes['scheme'] . '://' . $partes['host']
            . (isset($partes['port']) ? ':' . $partes['port'] : '');
        if (str_starts_with($location, '//')) {
            return $partes['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origen . $location;
        }
        $ruta = $partes['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $ruta) ?? '/';
        return $origen . $dir . $location;
    }

    private function esTipoContenidoPermitido(string $contentType, string $tipo): bool
    {
        if ($contentType === '') {
            // Algunos servidores omiten Content-Type; se tolera con recorte de tamaño.
            return true;
        }
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($tipo === 'css') {
            return $mime === 'text/css' || $mime === 'text/plain' || str_contains($mime, 'css');
        }
        return in_array($mime, [
            'text/html',
            'application/xhtml+xml',
            'application/xml',
            'text/xml',
            'text/plain',
        ], true) || str_contains($mime, 'html');
    }

    private function extraerTitulo(string $html): string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    private function extraerMetaDescription(string $html): string
    {
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>#i', $html, $m) === 1) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'][^>]*>#i', $html, $m) === 1) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    /**
     * @return list<string>
     */
    private function extraerHeadings(string $html): array
    {
        $headings = [];
        if (preg_match_all('#<h([1-3])[^>]*>(.*?)</h\1>#is', $html, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $item) {
                $texto = trim(html_entity_decode(strip_tags($item[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;
                if ($texto !== '') {
                    $headings[] = 'h' . $item[1] . ': ' . $texto;
                }
                if (count($headings) >= 30) {
                    break;
                }
            }
        }
        return $headings;
    }

    /**
     * @return list<string>
     */
    private function extraerClasesFrecuentes(string $html): array
    {
        $conteo = [];
        if (preg_match_all('#class=["\']([^"\']+)["\']#i', $html, $coincidencias)) {
            foreach ($coincidencias[1] as $bloque) {
                foreach (preg_split('/\s+/', trim($bloque)) ?: [] as $clase) {
                    if ($clase === '' || strlen($clase) > 60) {
                        continue;
                    }
                    $conteo[$clase] = ($conteo[$clase] ?? 0) + 1;
                }
            }
        }
        arsort($conteo);
        return array_slice(array_keys($conteo), 0, 40);
    }

    private function extraerTextoVisible(string $html): string
    {
        $sinScripts = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $texto = strip_tags($sinScripts);
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\n{3,}/u', "\n\n", $texto) ?? $texto;
        return trim($texto);
    }

    /**
     * @return list<string>
     */
    private function extraerUrlsCss(string $html, string $urlBase): array
    {
        $urls = [];
        if (preg_match_all('#<link[^>]+rel=["\']stylesheet["\'][^>]*>#i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('#href=["\']([^"\']+)["\']#i', $tag, $m) === 1) {
                    $absoluta = $this->resolverUrl($urlBase, $m[1]);
                    if ($absoluta !== null) {
                        $urls[] = $absoluta;
                    }
                }
            }
        }
        return array_values(array_unique($urls));
    }

    private function resolverUrl(string $base, string $relativa): ?string
    {
        $relativa = trim($relativa);
        if ($relativa === '' || str_starts_with($relativa, 'data:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $relativa)) {
            return $relativa;
        }

        $partesBase = parse_url($base);
        if ($partesBase === false || empty($partesBase['scheme']) || empty($partesBase['host'])) {
            return null;
        }

        $origen = $partesBase['scheme'] . '://' . $partesBase['host']
            . (isset($partesBase['port']) ? ':' . $partesBase['port'] : '');

        if (str_starts_with($relativa, '//')) {
            return $partesBase['scheme'] . ':' . $relativa;
        }
        if (str_starts_with($relativa, '/')) {
            return $origen . $relativa;
        }

        $ruta = $partesBase['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $ruta) ?? '/';
        return $origen . $dir . $relativa;
    }

    private function resumirCss(string $css): string
    {
        if (trim($css) === '') {
            return 'No se pudieron obtener hojas de estilo externas.';
        }

        $variables = [];
        if (preg_match_all('/--[a-zA-Z0-9_-]+\s*:\s*[^;]+;/u', $css, $m)) {
            $variables = array_slice(array_unique($m[0]), 0, 40);
        }

        $colores = [];
        if (preg_match_all('/#(?:[0-9a-fA-F]{3,8})\b|rgba?\([^)]+\)|hsla?\([^)]+\)/u', $css, $m)) {
            $colores = array_slice(array_unique($m[0]), 0, 40);
        }

        $fuentes = [];
        if (preg_match_all('/font-family\s*:\s*([^;]+);/iu', $css, $m)) {
            $fuentes = array_slice(array_unique(array_map('trim', $m[1])), 0, 20);
        }

        $medias = [];
        if (preg_match_all('/@media[^{]+/iu', $css, $m)) {
            $medias = array_slice(array_unique(array_map('trim', $m[0])), 0, 15);
        }

        $bloques = [];
        if ($variables !== []) {
            $bloques[] = "Variables CSS:\n- " . implode("\n- ", $variables);
        }
        if ($colores !== []) {
            $bloques[] = "Colores detectados:\n- " . implode("\n- ", $colores);
        }
        if ($fuentes !== []) {
            $bloques[] = "Tipografías:\n- " . implode("\n- ", $fuentes);
        }
        if ($medias !== []) {
            $bloques[] = "Media queries:\n- " . implode("\n- ", $medias);
        }

        $extracto = $this->recortar($css, 2500);
        $bloques[] = "Extracto CSS:\n" . $extracto;

        return implode("\n\n", $bloques);
    }

    private function recortar(string $texto, int $maximo): string
    {
        $texto = trim($texto);
        if (mb_strlen($texto) <= $maximo) {
            return $texto;
        }
        return mb_substr($texto, 0, $maximo) . "\n…[recortado]";
    }

    private function resolverHostPublico(string $host): ?string
    {
        if ($host === '') {
            return null;
        }
        if (isset($this->cacheDns[$host])) {
            return $this->cacheDns[$host];
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($this->analizador->esIpPrivadaOReservada($host)) {
                throw new RuntimeException('No se permiten destinos en redes privadas o reservadas.');
            }
            $this->cacheDns[$host] = $host;
            return $host;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new RuntimeException('Solo se permiten destinos IPv4 públicos para el análisis web.');
        }

        $ipv4 = $this->resolutor->resolverIpv4($host);
        if ($ipv4 === null) {
            return null;
        }
        if ($this->analizador->esIpPrivadaOReservada($ipv4)) {
            throw new RuntimeException('No se permiten destinos en redes privadas o reservadas.');
        }

        $this->cacheDns[$host] = $ipv4;
        return $ipv4;
    }
}
