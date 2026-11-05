<?php
/**
 * Cliente para obtener y resumir el HTML/CSS público de una página web.
 */
class ClienteWeb
{
    private int $tiempoEspera = 25;
    private int $maxCss = 3;
    private int $maxBytesCss = 100000;
    private int $maxBytesHtml = 800000;
    private ResolutorDns $resolutor;

    /** @var array<string, string> */
    private array $cacheDns = [];

    public function __construct(?ResolutorDns $resolutor = null)
    {
        $this->resolutor = $resolutor ?? new ResolutorDns();
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
     *   hojasCss: list<string>
     * }
     */
    public function analizarPagina(string $url): array
    {
        $html = $this->obtenerTexto($url);
        if (strlen($html) > $this->maxBytesHtml) {
            $html = substr($html, 0, $this->maxBytesHtml);
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
        foreach ($urlsCss as $urlCss) {
            if (!isset($descargas[$urlCss])) {
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

        return [
            'url' => $url,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'headings' => $headings,
            'clasesFrecuentes' => $clases,
            'textoVisible' => $this->recortar($textoVisible, 7000),
            'resumenCss' => $resumenCss,
            'hojasCss' => $urlsCss,
        ];
    }

    /**
     * Prefiere hojas CSS del mismo origen (más útiles y más rápidas).
     *
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
     * Descarga varias URLs en paralelo (cURL multi) o en serie como respaldo.
     *
     * @param list<string> $urls
     * @return array<string, string>
     */
    private function obtenerTextos(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            return [];
        }

        if (count($urls) === 1 || !function_exists('curl_multi_init')) {
            $resultado = [];
            foreach ($urls as $url) {
                try {
                    $resultado[$url] = $this->obtenerTexto($url);
                } catch (RuntimeException $excepcion) {
                    // Ignorar fallos individuales.
                }
            }
            return $resultado;
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            return $this->obtenerTextos(array_slice($urls, 0, 1));
        }

        $manejadores = [];
        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $ipv4 = $this->resolverHost($host);
            $manejador = curl_init($url);
            if ($manejador === false) {
                continue;
            }
            $opciones = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $this->tiempoEspera,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: reVERSEand0/1.0 (web analyzer)',
                    'Accept: text/css,*/*;q=0.8',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ];
            if ($ipv4 !== null) {
                $opciones[CURLOPT_RESOLVE] = $this->resolutor->entradasCurlResolve($host, $ipv4);
            }
            curl_setopt_array($manejador, $opciones);
            curl_multi_add_handle($multi, $manejador);
            $manejadores[$url] = $manejador;
        }

        $activo = null;
        do {
            $estado = curl_multi_exec($multi, $activo);
            if ($activo) {
                curl_multi_select($multi, 1.0);
            }
        } while ($activo && $estado === CURLM_OK);

        $resultado = [];
        foreach ($manejadores as $url => $manejador) {
            $codigo = (int) curl_getinfo($manejador, CURLINFO_HTTP_CODE);
            $cuerpo = curl_multi_getcontent($manejador);
            curl_multi_remove_handle($multi, $manejador);
            curl_close($manejador);
            if (is_string($cuerpo) && $cuerpo !== '' && $codigo >= 200 && $codigo < 300) {
                $resultado[$url] = $cuerpo;
            }
        }
        curl_multi_close($multi);

        return $resultado;
    }

    private function obtenerTexto(string $url): string
    {
        $cabeceras = [
            'User-Agent: reVERSEand0/1.0 (web analyzer)',
            'Accept: text/html,application/xhtml+xml,text/css,*/*;q=0.8',
        ];

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ipv4 = $this->resolverHost($host);

        if (function_exists('curl_init')) {
            $manejador = curl_init($url);
            if ($manejador === false) {
                throw new RuntimeException('No se pudo iniciar la petición a la página web.');
            }

            $opciones = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $this->tiempoEspera,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $cabeceras,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ];

            if ($ipv4 !== null) {
                $opciones[CURLOPT_RESOLVE] = $this->resolutor->entradasCurlResolve($host, $ipv4);
            }

            curl_setopt_array($manejador, $opciones);

            $cuerpo = curl_exec($manejador);
            $codigo = (int) curl_getinfo($manejador, CURLINFO_HTTP_CODE);
            $error = curl_error($manejador);
            curl_close($manejador);

            if ($cuerpo === false) {
                throw new RuntimeException('Fallo de red al obtener la página: ' . $error);
            }

            if ($codigo < 200 || $codigo >= 300) {
                throw new RuntimeException('La página respondió con error HTTP ' . $codigo . '.');
            }

            return (string) $cuerpo;
        }

        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $cabeceras),
                'timeout' => $this->tiempoEspera,
                'follow_location' => 1,
                'ignore_errors' => true,
            ],
        ]);

        $cuerpo = @file_get_contents($url, false, $contexto);
        if ($cuerpo === false) {
            throw new RuntimeException('Fallo de red al obtener la página.');
        }

        return $cuerpo;
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

    private function resolverHost(string $host): ?string
    {
        if ($host === '') {
            return null;
        }
        if (isset($this->cacheDns[$host])) {
            return $this->cacheDns[$host];
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->cacheDns[$host] = $host;
            return $host;
        }

        $ipv4 = $this->resolutor->resolverIpv4($host);
        if ($ipv4 === null) {
            return null;
        }

        // Anti-SSRF post-DoH (evita gethostbynamel bloqueante en validación).
        if ($this->esIpPrivadaOReservada($ipv4)) {
            throw new RuntimeException('No se permiten destinos en redes privadas o reservadas.');
        }

        $this->cacheDns[$host] = $ipv4;
        return $ipv4;
    }

    private function esIpPrivadaOReservada(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }
        return true;
    }
}
