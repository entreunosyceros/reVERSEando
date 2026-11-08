<?php
/**
 * Cliente para obtener y resumir el HTML/CSS público de una página web (anti-SSRF).
 */
class ClienteWeb
{
    private int $tiempoEspera = 25;
    private int $maxCss = 5;
    private int $maxImportsCss = 3;
    private int $maxBytesCss = 100000;
    private int $maxBytesCssEnLinea = 60000;
    private int $maxBytesHtml = 800000;
    private int $maxRedirecciones = 3;
    private int $maxPaginasInternas = 4;
    private float $presupuestoPaginasSeg = 6.0;
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
     *   tokensCss: array{variables: list<string>, colores: list<string>, fuentes: list<string>, medias: list<string>},
     *   hojasCss: list<string>,
     *   estructura: array<string, mixed>,
     *   paginasInternas: array<string, mixed>,
     *   diagnostico: array{
     *     htmlOk: bool,
     *     htmlRecortado: bool,
     *     cssEnlazadas: int,
     *     cssObtenidas: int,
     *     cssFallidas: list<string>,
     *     cssEnLinea: bool,
     *     dinamico: bool,
     *     dinamicoDetalle: string,
     *     paginasIntentadas: int,
     *     paginasObtenidas: int,
     *     paginasFallidas: list<string>,
     *     sitemapEncontrado: bool
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
        $estructura = $this->extraerEstructura($html, $url);
        $urlsCss = $this->priorizarCssMismoOrigen($this->extraerUrlsCss($html, $url), $url);
        $urlsCss = array_slice($urlsCss, 0, $this->maxCss);

        $descargas = $this->obtenerTextos($urlsCss);
        $imports = [];
        foreach ($descargas as $urlCss => $css) {
            foreach ($this->extraerImportsCss($css, $urlCss) as $import) {
                if (!in_array($import, $urlsCss, true)) {
                    $imports[] = $import;
                }
            }
        }
        $imports = array_slice(array_values(array_unique($imports)), 0, $this->maxImportsCss);
        if ($imports !== []) {
            $descargas += $this->obtenerTextos($imports);
            $urlsCss = array_merge($urlsCss, $imports);
        }

        $contenidosCss = [];
        $cssFallidas = [];
        $cssEnLinea = $this->extraerCssEnLinea($html);
        if ($cssEnLinea !== '') {
            $contenidosCss[] = "/* Fuente: <style> en línea */\n" . $cssEnLinea;
        }
        $hojasObtenidas = 0;
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
            $hojasObtenidas++;
        }

        $cssCombinado = implode("\n\n", $contenidosCss);
        $resumenCss = $this->resumirCss($cssCombinado);
        $dinamico = $this->detectarContenidoDinamico($html, $textoVisible);
        $paginasInternas = $this->analizarPaginasInternas($url, $estructura['navegacion']);

        return [
            'url' => $url,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'headings' => $headings,
            'clasesFrecuentes' => $clases,
            'textoVisible' => $this->recortar($textoVisible, 7000),
            'resumenCss' => $resumenCss,
            'tokensCss' => $this->extraerTokensCss($cssCombinado),
            'hojasCss' => $urlsCss,
            'estructura' => $estructura,
            'paginasInternas' => $paginasInternas,
            'diagnostico' => [
                'htmlOk' => true,
                'htmlRecortado' => $htmlRecortado,
                'cssEnlazadas' => count($urlsCss),
                'cssObtenidas' => $hojasObtenidas,
                'cssFallidas' => $cssFallidas,
                'cssEnLinea' => $cssEnLinea !== '',
                'dinamico' => $dinamico['detectado'],
                'dinamicoDetalle' => $dinamico['detalle'],
                'paginasIntentadas' => $paginasInternas['intentadas'],
                'paginasObtenidas' => count($paginasInternas['paginas']),
                'paginasFallidas' => $paginasInternas['fallidas'],
                'sitemapEncontrado' => $paginasInternas['sitemapEncontrado'],
            ],
        ];
    }

    /**
     * Estructura de una página: zonas, navegación, formularios, CTA, imágenes, metadatos y tecnologías.
     *
     * @return array{
     *   lang: string,
     *   viewport: bool,
     *   generador: string,
     *   openGraph: array<string, string>,
     *   jsonLdTipos: list<string>,
     *   tecnologias: list<string>,
     *   zonas: list<string>,
     *   navegacion: list<array{texto: string, url: string, interno: bool}>,
     *   formularios: list<array{metodo: string, accion: string, campos: list<string>}>,
     *   ctas: list<string>,
     *   botones: int,
     *   imagenes: array{total: int, conAlt: int},
     *   enlaces: array{internos: int, externos: int}
     * }
     */
    private function extraerEstructura(string $html, string $url): array
    {
        $estructura = [
            'lang' => '',
            'viewport' => (bool) preg_match('#<meta[^>]+name=["\']?viewport#i', $html),
            'generador' => '',
            'openGraph' => [],
            'jsonLdTipos' => $this->extraerTiposJsonLd($html),
            'tecnologias' => [],
            'zonas' => [],
            'navegacion' => [],
            'formularios' => [],
            'ctas' => [],
            'botones' => 0,
            'imagenes' => ['total' => 0, 'conAlt' => 0],
            'enlaces' => ['internos' => 0, 'externos' => 0],
        ];

        if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m) === 1
            || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']generator["\']#i', $html, $m) === 1
        ) {
            $estructura['generador'] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $estructura['tecnologias'] = $this->detectarTecnologias($html, $estructura['generador']);

        if (!class_exists(DOMDocument::class)) {
            return $estructura;
        }

        $dom = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $cargado = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (!$cargado) {
            return $estructura;
        }

        $xpath = new DOMXPath($dom);
        $htmlNodo = $xpath->query('//html')->item(0);
        if ($htmlNodo instanceof DOMElement) {
            $estructura['lang'] = trim($htmlNodo->getAttribute('lang'));
        }

        foreach ($xpath->query('//meta[starts-with(@property, "og:")]') ?: [] as $meta) {
            if (!$meta instanceof DOMElement || count($estructura['openGraph']) >= 8) {
                continue;
            }
            $valor = trim($meta->getAttribute('content'));
            if ($valor !== '') {
                $estructura['openGraph'][$meta->getAttribute('property')] = $this->recortar($valor, 200);
            }
        }

        $hostBase = strtolower((string) parse_url($url, PHP_URL_HOST));

        $consultaZonas = '//header|//nav|//main|//section|//article|//aside|//footer'
            . '|//*[@role="banner" or @role="navigation" or @role="main" or @role="contentinfo" or @role="complementary"]';
        $landmarks = ['header', 'nav', 'main', 'section', 'article', 'aside', 'footer'];
        $items = [];
        foreach ($xpath->query($consultaZonas) ?: [] as $nodo) {
            if (!$nodo instanceof DOMElement || count($items) >= 300) {
                continue;
            }
            $profundidad = 0;
            for ($padre = $nodo->parentNode; $padre instanceof DOMElement; $padre = $padre->parentNode) {
                if (in_array(strtolower($padre->tagName), $landmarks, true) || $padre->hasAttribute('role')) {
                    $profundidad++;
                }
            }
            if ($profundidad > 2) {
                continue;
            }
            $etiqueta = strtolower($nodo->tagName);
            $rol = $nodo->getAttribute('role');
            $nombre = in_array($etiqueta, $landmarks, true) ? $etiqueta : $etiqueta . '[role=' . $rol . ']';
            $heading = $xpath->query('.//h1|.//h2|.//h3|.//h4', $nodo)->item(0);
            $detalle = '';
            if ($heading instanceof DOMElement) {
                $detalle = $this->limpiarTextoNodo($heading->textContent, 80);
            } elseif ($nodo->getAttribute('aria-label') !== '') {
                $detalle = $this->limpiarTextoNodo($nodo->getAttribute('aria-label'), 80);
            }
            $id = $nodo->getAttribute('id');
            $items[] = [$profundidad, $nombre, $id !== '' ? '#' . $this->recortar($id, 40) : '', $detalle];
        }
        $estructura['zonas'] = $this->agruparZonas($items, 30);

        $vistos = [];
        $consultaNav = '//nav//a[@href]|//header//a[@href]|//*[@role="navigation"]//a[@href]';
        foreach ($xpath->query($consultaNav) ?: [] as $enlace) {
            if (!$enlace instanceof DOMElement || count($estructura['navegacion']) >= 25) {
                continue;
            }
            $texto = $this->limpiarTextoNodo($enlace->textContent, 60);
            if ($texto === '') {
                $texto = $this->limpiarTextoNodo($enlace->getAttribute('aria-label'), 60);
            }
            $destino = $this->resolverUrl($url, $enlace->getAttribute('href'));
            if ($texto === '' || $destino === null || !preg_match('#^https?://#i', $destino)) {
                continue;
            }
            $destino = preg_replace('/#.*$/', '', $destino) ?? $destino;
            $clave = strtolower($texto . '|' . $destino);
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $estructura['navegacion'][] = [
                'texto' => $texto,
                'url' => $destino,
                'interno' => strtolower((string) parse_url($destino, PHP_URL_HOST)) === $hostBase,
            ];
        }

        foreach ($xpath->query('//form') ?: [] as $form) {
            if (!$form instanceof DOMElement || count($estructura['formularios']) >= 5) {
                continue;
            }
            $campos = [];
            foreach ($xpath->query('.//input|.//select|.//textarea', $form) ?: [] as $campo) {
                if (!$campo instanceof DOMElement || count($campos) >= 12) {
                    continue;
                }
                $tipoCampo = strtolower($campo->tagName) === 'input'
                    ? (strtolower($campo->getAttribute('type')) ?: 'text')
                    : strtolower($campo->tagName);
                if (in_array($tipoCampo, ['hidden', 'submit', 'button', 'image', 'reset'], true)) {
                    continue;
                }
                $nombreCampo = $campo->getAttribute('name')
                    ?: ($campo->getAttribute('placeholder') ?: $campo->getAttribute('aria-label'));
                $campos[] = $tipoCampo . ($nombreCampo !== '' ? ':' . $this->limpiarTextoNodo($nombreCampo, 40) : '');
            }
            $accion = $form->getAttribute('action');
            $formulario = [
                'metodo' => strtoupper($form->getAttribute('method') ?: 'GET'),
                'accion' => $accion !== '' ? $this->recortar((string) (parse_url($accion, PHP_URL_PATH) ?? $accion), 80) : '(misma página)',
                'campos' => $campos,
            ];
            if (!in_array($formulario, $estructura['formularios'], true)) {
                $estructura['formularios'][] = $formulario;
            }
        }

        $botones = $xpath->query('//button|//input[@type="submit" or @type="button"]|//*[@role="button"]');
        $estructura['botones'] = $botones ? $botones->length : 0;
        $consultaCtas = '//button|//a[contains(concat(" ", normalize-space(@class), " "), "btn")'
            . ' or contains(@class, "button") or contains(@class, "cta")]';
        $ctasVistas = [];
        foreach ($xpath->query($consultaCtas) ?: [] as $cta) {
            if (!$cta instanceof DOMElement || count($ctasVistas) >= 15) {
                continue;
            }
            $texto = $this->limpiarTextoNodo($cta->textContent, 50);
            if ($texto !== '' && !isset($ctasVistas[$texto])) {
                $ctasVistas[$texto] = true;
            }
        }
        $estructura['ctas'] = array_keys($ctasVistas);

        $imagenes = $xpath->query('//img');
        $conAlt = $xpath->query('//img[normalize-space(@alt) != ""]');
        $estructura['imagenes'] = [
            'total' => $imagenes ? $imagenes->length : 0,
            'conAlt' => $conAlt ? $conAlt->length : 0,
        ];

        foreach ($xpath->query('//a[@href]') ?: [] as $enlace) {
            if (!$enlace instanceof DOMElement) {
                continue;
            }
            $destino = $this->resolverUrl($url, $enlace->getAttribute('href'));
            if ($destino === null || !preg_match('#^https?://#i', $destino)) {
                continue;
            }
            $esInterno = strtolower((string) parse_url($destino, PHP_URL_HOST)) === $hostBase;
            $estructura['enlaces'][$esInterno ? 'internos' : 'externos']++;
        }

        return $estructura;
    }

    /**
     * Agrupa hermanos consecutivos del mismo tipo (p. ej. "article ×6") y omite sus hijos repetidos.
     *
     * @param list<array{0: int, 1: string, 2: string, 3: string}> $items profundidad, nombre, id, detalle
     * @return list<string>
     */
    private function agruparZonas(array $items, int $maximo): array
    {
        $grupos = [];
        $ultimoPorNivel = [];
        $saltarMasProfundoQue = null;
        foreach ($items as [$profundidad, $nombre, $id, $detalle]) {
            if ($saltarMasProfundoQue !== null && $profundidad > $saltarMasProfundoQue) {
                continue;
            }
            $saltarMasProfundoQue = null;
            foreach (array_keys($ultimoPorNivel) as $nivel) {
                if ($nivel > $profundidad) {
                    unset($ultimoPorNivel[$nivel]);
                }
            }
            $previo = $ultimoPorNivel[$profundidad] ?? null;
            if ($previo !== null && $grupos[$previo]['nombre'] === $nombre) {
                $grupos[$previo]['veces']++;
                if ($detalle !== '' && count($grupos[$previo]['ejemplos']) < 3) {
                    $grupos[$previo]['ejemplos'][] = $detalle;
                }
                $saltarMasProfundoQue = $profundidad;
                continue;
            }
            $grupos[] = [
                'profundidad' => $profundidad,
                'nombre' => $nombre,
                'id' => $id,
                'veces' => 1,
                'ejemplos' => $detalle !== '' ? [$detalle] : [],
            ];
            $ultimoPorNivel[$profundidad] = count($grupos) - 1;
        }

        $zonas = [];
        foreach (array_slice($grupos, 0, $maximo) as $grupo) {
            $linea = str_repeat('  ', $grupo['profundidad']) . $grupo['nombre'];
            if ($grupo['veces'] > 1) {
                $linea .= ' ×' . $grupo['veces'];
                if ($grupo['ejemplos'] !== []) {
                    $linea .= ' (ej.: ' . implode('; ', $grupo['ejemplos']) . ')';
                }
            } else {
                $linea .= $grupo['id'] . ($grupo['ejemplos'] !== [] ? ': ' . $grupo['ejemplos'][0] : '');
            }
            $zonas[] = $linea;
        }
        return $zonas;
    }

    private function limpiarTextoNodo(string $texto, int $maximo): string
    {
        $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? $texto;
        return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo) . '…' : $texto;
    }

    /**
     * @return list<string>
     */
    private function extraerTiposJsonLd(string $html): array
    {
        $tipos = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            foreach (array_slice($m[1], 0, 5) as $bloque) {
                $datos = json_decode(trim($bloque), true);
                if (!is_array($datos)) {
                    continue;
                }
                $nodos = isset($datos['@graph']) && is_array($datos['@graph']) ? $datos['@graph'] : [$datos];
                foreach ($nodos as $nodo) {
                    $tipo = is_array($nodo) ? ($nodo['@type'] ?? null) : null;
                    foreach ((array) $tipo as $t) {
                        if (is_string($t) && $t !== '') {
                            $tipos[$t] = true;
                        }
                    }
                }
            }
        }
        return array_slice(array_keys($tipos), 0, 10);
    }

    /**
     * @return list<string>
     */
    private function detectarTecnologias(string $html, string $generador): array
    {
        $firmas = [
            'WordPress' => '#wp-content/|wp-includes/#i',
            'Drupal' => '#Drupal\.settings|/sites/default/files/#i',
            'Joomla' => '#/media/jui/|Joomla!#i',
            'Shopify' => '#cdn\.shopify\.com|Shopify\.theme#i',
            'Wix' => '#static\.wixstatic\.com|wix-bolt#i',
            'Squarespace' => '#static1\.squarespace\.com#i',
            'Webflow' => '#data-wf-page|webflow\.js#i',
            'Next.js' => '#__NEXT_DATA__|/_next/static/#',
            'Nuxt' => '#__NUXT__|/_nuxt/#',
            'Gatsby' => '#___gatsby#',
            'Astro' => '#<astro-island|data-astro-cid#i',
            'React' => '#data-reactroot|react(?:-dom)?(?:\.production)?(?:\.min)?\.js#i',
            'Vue' => '#data-v-[0-9a-f]{6,}|vue(?:\.global|\.runtime)?(?:\.min)?\.js#i',
            'Angular' => '#ng-version=|_nghost-#i',
            'Svelte' => '#class="[^"]*svelte-[a-z0-9]+#i',
            'jQuery' => '#jquery(?:[.-][0-9.]+)?(?:\.min)?\.js#i',
            'Bootstrap' => '#bootstrap(?:\.bundle)?(?:\.min)?\.(?:css|js)#i',
            'Tailwind CSS' => '#tailwind|class="[^"]*\b(?:flex|grid) [^"]*\b(?:px|py|mt|mb)-[0-9]#i',
            'Font Awesome' => '#font-?awesome#i',
            'Google Fonts' => '#fonts\.googleapis\.com#i',
            'Google Analytics / Tag Manager' => '#googletagmanager\.com|google-analytics\.com|gtag\(#i',
        ];
        $detectadas = [];
        if ($generador !== '') {
            $detectadas[] = 'Generador: ' . $this->recortar($generador, 60);
        }
        foreach ($firmas as $nombre => $patron) {
            if (preg_match($patron, $html) === 1) {
                $detectadas[] = $nombre;
            }
        }
        return $detectadas;
    }

    private function extraerCssEnLinea(string $html): string
    {
        if (!preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $m)) {
            return '';
        }
        $css = trim(implode("\n", $m[1]));
        if (strlen($css) > $this->maxBytesCssEnLinea) {
            $css = substr($css, 0, $this->maxBytesCssEnLinea) . "\n/* …recortado */";
        }
        return $css;
    }

    /**
     * @return list<string>
     */
    private function extraerImportsCss(string $css, string $urlCss): array
    {
        $urls = [];
        if (preg_match_all('/@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)["\']?\s*\)?/i', $css, $m)) {
            foreach ($m[1] as $relativa) {
                $absoluta = $this->resolverUrl($urlCss, $relativa);
                if ($absoluta !== null) {
                    $urls[] = $absoluta;
                }
            }
        }
        return array_values(array_unique($urls));
    }

    /**
     * Analiza hasta N páginas internas (enlaces de navegación + sitemap) del mismo host.
     *
     * @param list<array{texto: string, url: string, interno: bool}> $navegacion
     * @return array{
     *   paginas: list<array{url: string, origen: string, titulo: string, headings: list<string>, zonas: list<string>, formularios: int, ctas: list<string>}>,
     *   intentadas: int,
     *   fallidas: list<string>,
     *   sitemapEncontrado: bool,
     *   totalSitemap: int
     * }
     */
    private function analizarPaginasInternas(string $url, array $navegacion): array
    {
        $resultado = [
            'paginas' => [],
            'intentadas' => 0,
            'fallidas' => [],
            'sitemapEncontrado' => false,
            'totalSitemap' => 0,
        ];
        $limite = microtime(true) + $this->presupuestoPaginasSeg;
        $partes = parse_url($url);
        if ($partes === false || empty($partes['host']) || empty($partes['scheme'])) {
            return $resultado;
        }
        $host = strtolower($partes['host']);
        $origen = $partes['scheme'] . '://' . $partes['host'] . (isset($partes['port']) ? ':' . $partes['port'] : '');
        $rutaBase = rtrim((string) ($partes['path'] ?? '/'), '/');

        $candidatos = [];
        foreach ($navegacion as $enlace) {
            if (!empty($enlace['interno'])) {
                $candidatos[$enlace['url']] = 'navegación';
            }
        }

        $urlsSitemap = $this->obtenerUrlsSitemap($origen, $host, $limite);
        $resultado['sitemapEncontrado'] = $urlsSitemap !== null;
        $resultado['totalSitemap'] = $urlsSitemap === null ? 0 : count($urlsSitemap);
        foreach ($urlsSitemap ?? [] as $urlSitemap) {
            $candidatos[$urlSitemap] ??= 'sitemap';
        }

        $seleccion = [];
        $rutasVistas = [$rutaBase => true];
        foreach ($candidatos as $candidata => $fuente) {
            if (count($seleccion) >= $this->maxPaginasInternas) {
                break;
            }
            if (strtolower((string) parse_url($candidata, PHP_URL_HOST)) !== $host) {
                continue;
            }
            $ruta = rtrim((string) (parse_url($candidata, PHP_URL_PATH) ?? '/'), '/');
            if (isset($rutasVistas[$ruta])
                || preg_match('/\.(pdf|jpe?g|png|gif|svg|webp|zip|xml|json|mp4|mp3|css|js)$/i', $ruta)
            ) {
                continue;
            }
            $rutasVistas[$ruta] = true;
            $seleccion[$candidata] = $fuente;
        }

        $resultado['intentadas'] = count($seleccion);
        $descargas = $this->obtenerTextos(array_keys($seleccion), 'html', $limite);
        foreach ($seleccion as $urlPagina => $fuente) {
            if (!isset($descargas[$urlPagina])) {
                $resultado['fallidas'][] = $urlPagina;
                continue;
            }
            $htmlPagina = substr($descargas[$urlPagina], 0, $this->maxBytesHtml);
            $estructuraPagina = $this->extraerEstructura($htmlPagina, $urlPagina);
            $resultado['paginas'][] = [
                'url' => $urlPagina,
                'origen' => $fuente,
                'titulo' => $this->extraerTitulo($htmlPagina),
                'headings' => array_slice($this->extraerHeadings($htmlPagina), 0, 12),
                'zonas' => array_slice($estructuraPagina['zonas'], 0, 15),
                'formularios' => count($estructuraPagina['formularios']),
                'ctas' => array_slice($estructuraPagina['ctas'], 0, 6),
            ];
        }

        return $resultado;
    }

    /**
     * @return list<string>|null null si no se encontró sitemap
     */
    private function obtenerUrlsSitemap(string $origen, string $host, float $limite): ?array
    {
        $fuentes = [];
        $robots = $this->obtenerTextos([$origen . '/robots.txt'], 'html', $limite);
        if (isset($robots[$origen . '/robots.txt'])
            && preg_match_all('/^\s*Sitemap:\s*(\S+)/im', $robots[$origen . '/robots.txt'], $m)
        ) {
            foreach ($m[1] as $urlSitemap) {
                if (strtolower((string) parse_url($urlSitemap, PHP_URL_HOST)) === $host) {
                    $fuentes[] = $urlSitemap;
                }
            }
        }
        if ($fuentes === []) {
            $fuentes[] = $origen . '/sitemap.xml';
        }

        $xml = null;
        foreach (array_slice($fuentes, 0, 2) as $urlSitemap) {
            $descarga = $this->obtenerTextos([$urlSitemap], 'html', $limite);
            if (isset($descarga[$urlSitemap]) && stripos($descarga[$urlSitemap], '<loc>') !== false) {
                $xml = $descarga[$urlSitemap];
                break;
            }
        }
        if ($xml === null) {
            return null;
        }

        if (stripos($xml, '<sitemapindex') !== false && preg_match('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $hijo) === 1) {
            $urlHijo = html_entity_decode($hijo[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (strtolower((string) parse_url($urlHijo, PHP_URL_HOST)) === $host) {
                $descarga = $this->obtenerTextos([$urlHijo], 'html', $limite);
                $xml = $descarga[$urlHijo] ?? '';
            }
        }

        $urls = [];
        if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m)) {
            foreach (array_slice($m[1], 0, 200) as $loc) {
                $urls[] = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        // Rutas más cortas primero: suelen ser las secciones principales.
        usort($urls, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));
        return array_values(array_unique($urls));
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
     * @param 'html'|'css' $tipo
     * @param float|null $limite marca de tiempo (microtime) a partir de la cual no se lanzan más peticiones
     * @return array<string, string>
     */
    private function obtenerTextos(array $urls, string $tipo = 'css', ?float $limite = null): array
    {
        $resultado = [];
        foreach (array_values(array_unique(array_filter($urls))) as $url) {
            $tiempo = null;
            if ($limite !== null) {
                $restante = $limite - microtime(true);
                if ($restante < 1.0) {
                    break;
                }
                $tiempo = (int) max(1, floor($restante));
            }
            try {
                $urlSegura = $this->analizador->validarUrlHttpPublica($url);
                $resultado[$url] = $this->obtenerTexto($urlSegura, $tipo, $tiempo);
            } catch (Throwable $excepcion) {
                // Ignorar recursos individuales fallidos o no seguros.
            }
        }
        return $resultado;
    }

    /**
     * @param 'html'|'css' $tipo
     */
    private function obtenerTexto(string $url, string $tipo = 'html', ?int $tiempoMax = null): string
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
                CURLOPT_TIMEOUT => $tiempoMax ?? $this->tiempoEspera,
                CURLOPT_CONNECTTIMEOUT => min(10, $tiempoMax ?? 10),
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

            return $this->normalizarCharset($cuerpoAcumulado, $tipoContenido, $abortadoPorTamano);
        }
    }

    /**
     * Convierte el cuerpo a UTF-8 según Content-Type o meta charset.
     */
    private function normalizarCharset(string $cuerpo, string $tipoContenido, bool $recortado): string
    {
        $charset = '';
        if (preg_match('/charset\s*=\s*["\']?([\w.:-]+)/i', $tipoContenido, $m) === 1) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w.:-]+)/i', substr($cuerpo, 0, 4096), $m) === 1
            || preg_match('/^@charset\s+["\']([\w.:-]+)["\']/i', $cuerpo, $m) === 1
        ) {
            $charset = $m[1];
        }
        $charset = strtoupper($charset);

        if (in_array($charset, ['', 'UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true)) {
            if (mb_check_encoding($cuerpo, 'UTF-8')) {
                return $cuerpo;
            }
            // Un recorte por tamaño puede partir un carácter multibyte al final.
            $sinCola = $recortado ? substr($cuerpo, 0, max(0, strlen($cuerpo) - 4)) : $cuerpo;
            if ($charset !== '' || mb_check_encoding($sinCola, 'UTF-8')) {
                return mb_scrub($cuerpo, 'UTF-8');
            }
            $charset = 'WINDOWS-1252';
        }
        if (in_array($charset, ['ISO-8859-1', 'LATIN1', 'LATIN-1'], true)) {
            $charset = 'WINDOWS-1252';
        }

        try {
            $convertido = mb_convert_encoding($cuerpo, 'UTF-8', $charset);
        } catch (ValueError $error) {
            return mb_scrub($cuerpo, 'UTF-8');
        }
        return is_string($convertido) ? $convertido : mb_scrub($cuerpo, 'UTF-8');
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
        if (preg_match_all('#<h([1-4])[^>]*>(.*?)</h\1>#is', $html, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $item) {
                $texto = trim(html_entity_decode(strip_tags($item[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;
                if ($texto !== '') {
                    $headings[] = 'h' . $item[1] . ': ' . $this->recortar($texto, 120);
                }
                if (count($headings) >= 40) {
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
        if (preg_match_all('#<link\b[^>]*\brel\s*=\s*["\']?[^"\'>]*\bstylesheet\b[^>]*>#i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('#\bhref\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))#i', $tag, $m) === 1) {
                    $href = html_entity_decode($m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $absoluta = $this->resolverUrl($urlBase, $href);
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
        if ($relativa === '' || str_starts_with($relativa, '#')) {
            return null;
        }
        if (preg_match('#^https?://#i', $relativa)) {
            return $relativa;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relativa)) {
            return null;
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
        if (str_starts_with($relativa, '?')) {
            return $origen . $ruta . $relativa;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $ruta) ?? '/';
        return $origen . $dir . $relativa;
    }

    /**
     * @param list<string> $valores
     * @return list<string>
     */
    private function ordenarPorFrecuencia(array $valores, int $maximo): array
    {
        $conteo = array_count_values(array_map(static fn (string $v): string => strtolower(trim($v)), $valores));
        arsort($conteo);
        $salida = [];
        foreach (array_slice($conteo, 0, $maximo, true) as $valor => $veces) {
            $salida[] = $veces > 1 ? "{$valor} (×{$veces})" : (string) $valor;
        }
        return $salida;
    }

    /**
     * @return array{variables: list<string>, colores: list<string>, fuentes: list<string>, medias: list<string>}
     */
    private function extraerTokensCss(string $css): array
    {
        $tokens = ['variables' => [], 'colores' => [], 'fuentes' => [], 'medias' => []];
        if (preg_match_all('/--[a-zA-Z0-9_-]+\s*:\s*[^;}]+[;}]/u', $css, $m)) {
            $tokens['variables'] = array_slice(array_values(array_unique(array_map('trim', $m[0]))), 0, 40);
        }
        if (preg_match_all('/#(?:[0-9a-fA-F]{3,8})\b|rgba?\([^)]+\)|hsla?\([^)]+\)/u', $css, $m)) {
            $tokens['colores'] = $this->ordenarPorFrecuencia($m[0], 40);
        }
        if (preg_match_all('/font-family\s*:\s*([^;}]+)[;}]/iu', $css, $m)) {
            $tokens['fuentes'] = $this->ordenarPorFrecuencia($m[1], 20);
        }
        if (preg_match_all('/@media[^{]+/iu', $css, $m)) {
            $tokens['medias'] = array_slice(array_values(array_unique(array_map('trim', $m[0]))), 0, 15);
        }
        return $tokens;
    }

    private function resumirCss(string $css): string
    {
        if (trim($css) === '') {
            return 'No se pudieron obtener hojas de estilo externas.';
        }

        $tokens = $this->extraerTokensCss($css);
        $variables = $tokens['variables'];
        $colores = $tokens['colores'];
        $fuentes = $tokens['fuentes'];
        $medias = $tokens['medias'];

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
