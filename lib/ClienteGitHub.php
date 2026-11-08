<?php
/**
 * Cliente mínimo para la API pública de GitHub.
 */
class ClienteGitHub
{
    private string $urlBase = 'https://api.github.com';
    private int $tiempoEspera = 20;

    /**
     * Obtiene metadatos principales del repositorio.
     *
     * @return array<string, mixed>
     */
    public function obtenerMetadatosRepositorio(string $propietario, string $repositorio): array
    {
        return $this->solicitarJson("/repos/{$propietario}/{$repositorio}");
    }

    /**
     * Obtiene el mapa de lenguajes del repositorio.
     *
     * @return array<string, int>
     */
    public function obtenerLenguajes(string $propietario, string $repositorio): array
    {
        $respuesta = $this->solicitarJson("/repos/{$propietario}/{$repositorio}/languages");
        /** @var array<string, int> $respuesta */
        return $respuesta;
    }

    /**
     * Obtiene el README en texto plano si existe.
     */
    public function obtenerReadme(string $propietario, string $repositorio): string
    {
        try {
            $contenido = $this->solicitarTexto(
                "/repos/{$propietario}/{$repositorio}/readme",
                ['Accept: application/vnd.github.raw']
            );
            return $this->recortarTexto($contenido, 8000);
        } catch (RuntimeException $excepcion) {
            if ($this->esErrorNoEncontrado($excepcion)) {
                return '';
            }
            throw $excepcion;
        }
    }

    /** Manifiestos/configuración por prioridad (nombre de archivo, sin ruta). */
    private const MANIFIESTOS = [
        'package.json', 'composer.json', 'pyproject.toml', 'requirements.txt', 'Pipfile', 'setup.py',
        'Cargo.toml', 'go.mod', 'pom.xml', 'build.gradle', 'build.gradle.kts', 'Gemfile', 'mix.exs',
        'pubspec.yaml', 'deno.json', 'Dockerfile', 'docker-compose.yml', 'docker-compose.yaml',
        'compose.yml', 'compose.yaml', '.env.example', 'schema.prisma', 'tsconfig.json', 'angular.json',
        'vercel.json', 'netlify.toml', 'Makefile',
    ];

    /** Directorios de código (mayor prioridad en el árbol y en los puntos de entrada). */
    private const DIRECTORIOS_CODIGO = [
        'src', 'app', 'lib', 'packages', 'apps', 'cmd', 'internal', 'server', 'client', 'api',
        'routes', 'components', 'pages', 'backend', 'frontend', 'core',
    ];

    private int $maxManifiestos = 6;
    private int $maxPuntosEntrada = 3;
    private int $maxArchivosCodigo = 12;
    private int $maxCaracteresManifiesto = 2500;
    private int $maxCaracteresPuntoEntrada = 1500;

    /**
     * Árbol recursivo de una rama ya procesado (o null si la rama no existe).
     *
     * @return array{arbol: list<string>, todas: list<string>, total: int, truncado: bool, directorios: array<string, int>, rama: string}|null
     */
    private function obtenerArbolProcesado(string $propietario, string $repositorio, string $rama, int $limite): ?array
    {
        try {
            $datos = $this->solicitarJson(
                "/repos/{$propietario}/{$repositorio}/git/trees/" . rawurlencode($rama) . '?recursive=1'
            );
        } catch (RuntimeException $excepcion) {
            if ($this->esErrorNoEncontrado($excepcion)) {
                return null;
            }
            throw $excepcion;
        }
        return $this->procesarArbol($datos, $limite, $rama);
    }

    /**
     * Prioriza rutas relevantes (raíz, código, manifiestos) en vez de cortar en orden alfabético.
     *
     * @param array<string, mixed> $datos respuesta de git/trees
     * @return array{arbol: list<string>, todas: list<string>, total: int, truncado: bool, directorios: array<string, int>, rama: string}
     */
    private function procesarArbol(array $datos, int $limite, string $rama): array
    {
        $nodos = is_array($datos['tree'] ?? null) ? $datos['tree'] : [];
        $todas = [];
        $total = 0;
        $directorios = [];
        foreach ($nodos as $nodo) {
            if (!is_array($nodo) || ($nodo['type'] ?? '') !== 'blob') {
                continue;
            }
            $ruta = (string) ($nodo['path'] ?? '');
            if ($ruta === '' || $this->debeIgnorarRuta($ruta)) {
                continue;
            }
            $total++;
            if ($total <= 20000) {
                $todas[] = $ruta;
            }
            $partes = explode('/', $ruta);
            if (count($partes) > 1) {
                $directorios[$partes[0] . '/'] = ($directorios[$partes[0] . '/'] ?? 0) + 1;
                if (count($partes) > 2 && in_array($partes[0], self::DIRECTORIOS_CODIGO, true)) {
                    $sub = $partes[0] . '/' . $partes[1] . '/';
                    $directorios[$sub] = ($directorios[$sub] ?? 0) + 1;
                }
            }
        }

        $puntuadas = [];
        foreach ($todas as $indice => $ruta) {
            $puntuadas[$ruta] = $this->puntuarRuta($ruta) + $indice / 1000000;
        }
        asort($puntuadas);
        $arbol = array_slice(array_keys($puntuadas), 0, $limite);
        sort($arbol);

        arsort($directorios);

        return [
            'arbol' => $arbol,
            'todas' => $todas,
            'total' => $total,
            'truncado' => !empty($datos['truncated']) || $total > $limite,
            'directorios' => array_slice($directorios, 0, 30, true),
            'rama' => $rama,
        ];
    }

    private function puntuarRuta(string $ruta): float
    {
        $partes = explode('/', $ruta);
        $profundidad = count($partes) - 1;
        $nombre = end($partes);
        if (preg_match('/\.(png|jpe?g|gif|svg|ico|webp|bmp|woff2?|ttf|eot|otf|mp[34]|webm|pdf|zip|gz|lock|min\.(js|css)|map)$/i', $nombre)) {
            return 9.0;
        }
        $raiz = strtolower($partes[0]);
        $secundario = ['docs', 'doc', '.github', 'examples', 'example', 'samples', 'fixtures', 'test', 'tests', '__tests__', 'spec'];
        if ($profundidad > 0 && in_array($raiz, $secundario, true)) {
            return 4.0 + $profundidad * 0.3;
        }
        if (in_array($nombre, self::MANIFIESTOS, true) && $profundidad <= 2) {
            return 0.0;
        }
        if ($profundidad === 0) {
            return 0.5;
        }
        if (in_array($raiz, self::DIRECTORIOS_CODIGO, true)) {
            return 1.0 + $profundidad * 0.3;
        }
        return 2.0 + $profundidad * 0.3;
    }

    /**
     * Descarga manifiestos y puntos de entrada desde raw.githubusercontent.com
     * (no consume el límite de la API REST).
     *
     * @param list<string> $rutasArbol todas las rutas del árbol
     * @return array{
     *   manifiestos: list<array{ruta: string, contenido: string, dependencias: list<string>, scripts: list<string>}>,
     *   puntosEntrada: list<array{ruta: string, contenido: string, dependencias: list<string>, scripts: list<string>}>,
     *   mapaCodigo: array{rutas: list<string>, modelos: list<string>, pantallas: list<string>, comandos: list<string>, archivos: int}
     * }
     */
    public function obtenerArchivosClave(
        string $propietario,
        string $repositorio,
        string $rama,
        array $rutasArbol
    ): array {
        $manifiestos = $this->seleccionarManifiestos($rutasArbol);
        $puntosEntrada = $this->seleccionarPuntosEntrada($rutasArbol);
        $codigo = $this->seleccionarArchivosCodigo($rutasArbol, array_merge($manifiestos, $puntosEntrada));

        $peticiones = [];
        foreach (array_merge($manifiestos, $puntosEntrada, $codigo) as $ruta) {
            $rutaCodificada = implode('/', array_map('rawurlencode', explode('/', $ruta)));
            $peticiones[$ruta] = [
                'ruta' => 'https://raw.githubusercontent.com/' . rawurlencode($propietario) . '/'
                    . rawurlencode($repositorio) . '/' . rawurlencode($rama) . '/' . $rutaCodificada,
                'cabeceras' => ['Accept: text/plain'],
            ];
        }
        $respuestas = $peticiones === [] ? [] : $this->solicitarMultiples($peticiones);

        $empaquetar = function (array $rutas, int $maximo) use ($respuestas): array {
            $salida = [];
            foreach ($rutas as $ruta) {
                $cuerpo = $respuestas[$ruta]['cuerpo'] ?? '';
                if (($respuestas[$ruta]['ok'] ?? false) !== true || trim($cuerpo) === '' || !mb_check_encoding($cuerpo, 'UTF-8')) {
                    continue;
                }
                $nombre = basename($ruta);
                if (str_starts_with($nombre, '.env')) {
                    $cuerpo = preg_replace('/^(\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=).*$/m', '$1', $cuerpo) ?? '';
                }
                $dependencias = $this->extraerDependencias($nombre, $cuerpo);
                $scripts = $this->extraerScripts($nombre, $cuerpo);
                if (in_array($nombre, ['package.json', 'composer.json', 'deno.json'], true)) {
                    $cuerpo = $this->compactarManifiestoJson($cuerpo);
                }
                $salida[] = [
                    'ruta' => $ruta,
                    'contenido' => $this->recortarTexto($cuerpo, $maximo),
                    'dependencias' => $dependencias,
                    'scripts' => $scripts,
                ];
            }
            return $salida;
        };

        $fuentes = [];
        foreach (array_merge($manifiestos, $puntosEntrada, $codigo) as $ruta) {
            $cuerpo = $respuestas[$ruta]['cuerpo'] ?? '';
            if (($respuestas[$ruta]['ok'] ?? false) !== true || trim($cuerpo) === '' || !mb_check_encoding($cuerpo, 'UTF-8')) {
                continue;
            }
            $fuentes[$ruta] = substr($cuerpo, 0, 80000);
        }

        return [
            'manifiestos' => $empaquetar($manifiestos, $this->maxCaracteresManifiesto),
            'puntosEntrada' => $empaquetar($puntosEntrada, $this->maxCaracteresPuntoEntrada),
            'mapaCodigo' => $this->construirMapaCodigo($fuentes),
        ];
    }

    /**
     * Hasta 12 archivos de rutas, modelos, pantallas y comandos, fuera de tests y docs.
     *
     * @param list<string> $rutas
     * @param list<string> $excluir ya descargados como manifiesto o punto de entrada
     * @return list<string>
     */
    private function seleccionarArchivosCodigo(array $rutas, array $excluir): array
    {
        $excluidas = array_fill_keys($excluir, true);
        $tope = ['ruta' => 5, 'modelo' => 4, 'pantalla' => 3, 'comando' => 2];
        $orden = ['ruta' => 0, 'modelo' => 1, 'pantalla' => 2, 'comando' => 3];
        $candidatas = [];
        foreach ($rutas as $ruta) {
            if (isset($excluidas[$ruta]) || $this->puntuarRuta($ruta) >= 4.0) {
                continue;
            }
            $tipo = $this->clasificarArchivoCodigo($ruta);
            if ($tipo === null) {
                continue;
            }
            $candidatas[] = [$orden[$tipo], substr_count($ruta, '/'), $ruta, $tipo];
        }
        usort($candidatas, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: $a[2] <=> $b[2]);

        $elegidas = [];
        $cuenta = array_fill_keys(array_keys($tope), 0);
        $resto = [];
        foreach ($candidatas as [, , $ruta, $tipo]) {
            if ($cuenta[$tipo] < $tope[$tipo]) {
                $elegidas[] = $ruta;
                $cuenta[$tipo]++;
            } else {
                $resto[] = $ruta;
            }
        }
        foreach ($resto as $ruta) {
            if (count($elegidas) >= $this->maxArchivosCodigo) {
                break;
            }
            $elegidas[] = $ruta;
        }
        return array_slice($elegidas, 0, $this->maxArchivosCodigo);
    }

    private function clasificarArchivoCodigo(string $ruta): ?string
    {
        $nombre = basename($ruta);
        if (preg_match('/\.(test|spec)\.|_test\.go$|^test_/i', $nombre) === 1) {
            return null;
        }
        if (preg_match('#(^|/)routes/console\.php$|(^|/)app/Console/Commands/.+\.php$|(^|/)cmd/[^/]+/main\.go$|(^|/)commands/.+\.py$#i', $ruta) === 1) {
            return 'comando';
        }
        if (preg_match('#(^|/)routes/.+\.php$|(^|/)urls\.py$|(^|/)(router|routes)\.(go|php|js|mjs|ts)$|(^|/)app/api/.+/route\.(js|jsx|ts|tsx|mjs)$|(^|/)pages/api/.+\.(js|jsx|ts|tsx)$|(^|/)src/routes/.+\.(js|jsx|ts|tsx|php)$|openapi\.(ya?ml|json)$|swagger\.(ya?ml|json)$#i', $ruta) === 1) {
            return 'ruta';
        }
        if (preg_match('#(^|/)(app/Models|models|entities)/.+\.(php|py|rb|js|ts|java|kt|cs)$|(^|/)migrations/.+\.(php|sql)$|schema\.prisma$|/models\.py$#i', $ruta) === 1) {
            return 'modelo';
        }
        if (preg_match('#(^|/)app/.+/page\.(jsx|tsx|vue|svelte)$#i', $ruta) === 1) {
            return 'pantalla';
        }
        if (preg_match('#(^|/)pages/.+\.(jsx|tsx|vue)$#i', $ruta) === 1 && !str_contains($ruta, 'pages/api/')) {
            return 'pantalla';
        }
        if (preg_match('#(^|/)(resources/views|templates|views)/.+\.(blade\.php|html|twig|jinja|ejs|erb|php|vue)$#i', $ruta) === 1) {
            return 'pantalla';
        }
        return null;
    }

    /**
     * Firmas (rutas HTTP, entidades, pantallas y comandos), sin cuerpos de función.
     *
     * @param array<string, string> $fuentes ruta => contenido
     * @return array{rutas: list<string>, modelos: list<string>, pantallas: list<string>, comandos: list<string>, archivos: int}
     */
    private function construirMapaCodigo(array $fuentes): array
    {
        $mapa = ['rutas' => [], 'modelos' => [], 'pantallas' => [], 'comandos' => [], 'archivos' => 0];
        $topes = ['rutas' => 40, 'modelos' => 20, 'pantallas' => 20, 'comandos' => 15];
        foreach ($fuentes as $ruta => $cuerpo) {
            $parcial = $this->extraerFirmas($ruta, $cuerpo);
            $aporto = false;
            foreach (['rutas', 'modelos', 'pantallas', 'comandos'] as $clave) {
                foreach ($parcial[$clave] as $linea) {
                    if (count($mapa[$clave]) >= $topes[$clave] || in_array($linea, $mapa[$clave], true)) {
                        continue;
                    }
                    $mapa[$clave][] = $linea;
                    $aporto = true;
                }
            }
            if ($aporto) {
                $mapa['archivos']++;
            }
        }
        return $mapa;
    }

    /**
     * @return array{rutas: list<string>, modelos: list<string>, pantallas: list<string>, comandos: list<string>}
     */
    private function extraerFirmas(string $ruta, string $cuerpo): array
    {
        $out = ['rutas' => [], 'modelos' => [], 'pantallas' => [], 'comandos' => []];
        $tipo = $this->clasificarArchivoCodigo($ruta);

        if (preg_match_all('/Route::(get|post|put|patch|delete|options|any)\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $cuerpo, $m, PREG_SET_ORDER)) {
            foreach ($m as $coincidencia) {
                $path = $this->sanearFragmento($coincidencia[2]);
                if ($path !== '') {
                    $out['rutas'][] = strtoupper($coincidencia[1]) . ' ' . $path . ' — ' . $ruta;
                }
            }
        }
        if (preg_match_all('/Route::resource\(\s*[\'"]([^\'"]+)[\'"]/i', $cuerpo, $m)) {
            foreach ($m[1] as $recurso) {
                $recurso = $this->sanearFragmento($recurso);
                if ($recurso !== '') {
                    $out['rutas'][] = 'RESOURCE /' . ltrim($recurso, '/') . ' — ' . $ruta;
                }
            }
        }
        if (preg_match('#(^|/)app/api/(.+)/route\.[a-z]+$#i', $ruta, $m) === 1
            && preg_match_all('/export\s+(?:async\s+)?function\s+(GET|POST|PUT|PATCH|DELETE)\b/', $cuerpo, $verbos)
        ) {
            $path = '/' . trim($m[2], '/');
            foreach (array_unique($verbos[1]) as $verbo) {
                $out['rutas'][] = $verbo . ' ' . $path . ' — ' . $ruta;
            }
        }
        if (preg_match_all('/\b(?:app|router|r)\.(get|post|put|patch|delete|all)\s*\(\s*[\'"`]([^\'"`]+)/i', $cuerpo, $m, PREG_SET_ORDER)) {
            foreach ($m as $coincidencia) {
                $path = $this->sanearFragmento($coincidencia[2]);
                if ($path !== '' && str_starts_with($path, '/')) {
                    $out['rutas'][] = strtoupper($coincidencia[1]) . ' ' . $path . ' — ' . $ruta;
                }
            }
        }
        if (preg_match_all('/@(?:app|router)\.(?:route|get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*methods\s*=\s*\[([^\]]+)\])?/i', $cuerpo, $m, PREG_SET_ORDER)) {
            foreach ($m as $coincidencia) {
                $path = $this->sanearFragmento($coincidencia[1]);
                $metodos = isset($coincidencia[2]) && trim($coincidencia[2]) !== ''
                    ? strtoupper(preg_replace('/[\'"\s]/', '', $coincidencia[2]) ?? '')
                    : 'GET';
                if ($path !== '') {
                    $out['rutas'][] = $metodos . ' ' . $path . ' — ' . $ruta;
                }
            }
        }
        if (preg_match_all('/\bpath\(\s*[\'"]([^\'"]+)[\'"]/', $cuerpo, $m)) {
            foreach ($m[1] as $path) {
                $path = $this->sanearFragmento($path);
                if ($path !== '') {
                    $out['rutas'][] = 'PATH /' . ltrim($path, '/') . ' — ' . $ruta;
                }
            }
        }
        if (preg_match_all('/\.(GET|POST|PUT|PATCH|DELETE|HandleFunc)\(\s*"([^"]+)"/', $cuerpo, $m, PREG_SET_ORDER)) {
            foreach ($m as $coincidencia) {
                $path = $this->sanearFragmento($coincidencia[2]);
                if ($path !== '') {
                    $out['rutas'][] = strtoupper($coincidencia[1]) . ' ' . $path . ' — ' . $ruta;
                }
            }
        }

        if (preg_match_all('/Schema::create\(\s*[\'"](\w+)[\'"].*?function\s*\([^)]*\)\s*\{(.*?)\n\s*\}\);/s', $cuerpo, $tablas, PREG_SET_ORDER)) {
            foreach ($tablas as $tabla) {
                preg_match_all('/\$table->\w+\(\s*[\'"](\w+)[\'"]/', $tabla[2], $campos);
                $lista = implode(', ', array_slice($campos[1], 0, 12));
                $out['modelos'][] = $tabla[1] . ($lista !== '' ? ' (' . $lista . ')' : '') . ' — ' . $ruta;
            }
        }
        if (preg_match_all('/\bclass\s+(\w+)(?:\s+extends\s+\w+)?[^{]*\{(.*?)\n\}/s', $cuerpo, $clases, PREG_SET_ORDER)) {
            foreach ($clases as $clase) {
                $campos = [];
                if (preg_match('/\$fillable\s*=\s*\[([^\]]+)\]/', $clase[2], $fillable) === 1) {
                    preg_match_all('/[\'"](\w+)[\'"]/', $fillable[1], $nombres);
                    $campos = $nombres[1];
                }
                if (preg_match_all('/^\s{4}(?:public|protected|private)\s+(?:\??[\w\\\\|]+\s+)?\$(\w+)/m', $clase[2], $props)) {
                    $campos = array_merge($campos, $props[1]);
                }
                if (preg_match_all('/^\s+(\w+)\s*=\s*models\.\w+/m', $clase[2], $django)) {
                    $campos = array_merge($campos, $django[1]);
                }
                $campos = array_values(array_unique(array_filter(
                    $campos,
                    static fn (string $c): bool => !in_array($c, ['fillable', 'guarded', 'hidden', 'casts', 'table', 'connection', 'signature', 'description', 'aliases'], true)
                )));
                if ($campos !== [] || $tipo === 'modelo') {
                    $lista = implode(', ', array_slice($campos, 0, 12));
                    $out['modelos'][] = $clase[1] . ($lista !== '' ? ' (' . $lista . ')' : '') . ' — ' . $ruta;
                }
            }
        }
        if (preg_match_all('/\bmodel\s+(\w+)\s*\{([^}]+)\}/', $cuerpo, $modelos, PREG_SET_ORDER)) {
            foreach ($modelos as $modelo) {
                preg_match_all('/^\s*(\w+)\s+\w+/m', $modelo[2], $campos);
                $nombres = array_values(array_filter($campos[1], static fn (string $c): bool => !in_array($c, ['model', 'enum'], true)));
                $lista = implode(', ', array_slice($nombres, 0, 12));
                $out['modelos'][] = $modelo[1] . ($lista !== '' ? ' (' . $lista . ')' : '') . ' — ' . $ruta;
            }
        }
        if (preg_match_all('/^class\s+(\w+)\b[^\n]*:\s*\n((?:[ \t].*\n?)*)/m', $cuerpo, $clasesPy, PREG_SET_ORDER)) {
            foreach ($clasesPy as $clase) {
                preg_match_all('/^\s+(\w+)\s*=\s*(?:models|fields)\.\w+/m', $clase[2], $campos);
                if ($campos[1] === [] && $tipo !== 'modelo') {
                    continue;
                }
                $lista = implode(', ', array_slice($campos[1], 0, 12));
                $out['modelos'][] = $clase[1] . ($lista !== '' ? ' (' . $lista . ')' : '') . ' — ' . $ruta;
            }
        }
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?(\w+)/i', $cuerpo, $tablas)) {
            foreach ($tablas[1] as $tabla) {
                $out['modelos'][] = $tabla . ' — ' . $ruta;
            }
        }

        if ($tipo === 'pantalla') {
            $titulo = '';
            if (preg_match('#<h1[^>]*>\s*([^<]{1,80})#i', $cuerpo, $h) === 1
                || preg_match('#<title[^>]*>\s*([^<]{1,80})#i', $cuerpo, $h) === 1
            ) {
                $titulo = $this->sanearFragmento(html_entity_decode(strip_tags($h[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $out['pantallas'][] = '`' . $ruta . '`' . ($titulo !== '' ? ' — ' . $titulo : '');
        }

        if (preg_match_all('/\$signature\s*=\s*[\'"]([^\'"]+)[\'"]/', $cuerpo, $m)) {
            foreach ($m[1] as $firma) {
                $firma = $this->sanearFragmento(explode(' ', $firma)[0] ?? $firma);
                if ($firma !== '') {
                    $out['comandos'][] = $firma . ' — ' . $ruta;
                }
            }
        }
        if (preg_match_all('/\bUse:\s*[\'"]([^\'"]+)[\'"]/', $cuerpo, $m)) {
            foreach ($m[1] as $uso) {
                $uso = $this->sanearFragmento($uso);
                if ($uso !== '') {
                    $out['comandos'][] = $uso . ' — ' . $ruta;
                }
            }
        }
        if (preg_match_all('/@click\.command\(\)\s*(?:async\s+)?def\s+(\w+)/', $cuerpo, $m)) {
            foreach ($m[1] as $comando) {
                $out['comandos'][] = $comando . ' — ' . $ruta;
            }
        }

        foreach ($out as $clave => $lineas) {
            $out[$clave] = array_slice(array_values(array_unique($lineas)), 0, 20);
        }
        return $out;
    }

    private function sanearFragmento(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/', ' ', $texto) ?? '');
        if ($texto === '' || preg_match('/(sk_live_|sk-|ghp_|github_pat_|AKIA[0-9A-Z]{8}|xox[baprs]-|-----BEGIN |eyJ[A-Za-z0-9_-]{16,})/', $texto) === 1) {
            return '';
        }
        return mb_strlen($texto) > 80 ? mb_substr($texto, 0, 80) . '…' : $texto;
    }

    /**
     * Nombres de dependencias declaradas (se calcula sobre el archivo completo, antes de recortar).
     *
     * @return list<string>
     */
    private function extraerDependencias(string $nombre, string $cuerpo): array
    {
        $deps = [];
        if (in_array($nombre, ['package.json', 'composer.json', 'deno.json'], true)) {
            $json = json_decode($cuerpo, true);
            if (is_array($json)) {
                $grupos = [
                    'dependencies' => '',
                    'require' => '',
                    'imports' => '',
                    'devDependencies' => ' (dev)',
                    'require-dev' => ' (dev)',
                    'peerDependencies' => ' (peer)',
                ];
                foreach ($grupos as $clave => $sufijo) {
                    foreach (is_array($json[$clave] ?? null) ? $json[$clave] : [] as $paquete => $version) {
                        if (!is_string($paquete) || $paquete === 'php' || str_starts_with($paquete, 'ext-')) {
                            continue;
                        }
                        $deps[] = $paquete . $sufijo;
                    }
                }
            }
        } elseif ($nombre === 'requirements.txt') {
            foreach (preg_split('/\R/', $cuerpo) ?: [] as $linea) {
                $linea = trim($linea);
                if ($linea !== '' && $linea[0] !== '#' && $linea[0] !== '-'
                    && preg_match('/^([A-Za-z0-9_.\-]+(?:\[[^\]]+\])?)/', $linea, $m) === 1
                ) {
                    $deps[] = $m[1];
                }
            }
        } elseif ($nombre === 'go.mod') {
            if (preg_match_all('/^\s*(?:require\s+)?([a-z0-9.\-]+\.[a-z]{2,}\/\S+)\s+v\d/mi', $cuerpo, $m)) {
                $deps = $m[1];
            }
        } elseif ($nombre === 'Cargo.toml' || $nombre === 'pyproject.toml') {
            if (preg_match_all('/^\[(?:workspace\.)?(dev-)?dependencies\]\s*\n(.*?)(?=^\[|\z)/ms', $cuerpo, $secciones, PREG_SET_ORDER)) {
                foreach ($secciones as $seccion) {
                    if (preg_match_all('/^([A-Za-z0-9_\-]+)\s*=/m', $seccion[2], $claves)) {
                        foreach ($claves[1] as $clave) {
                            $deps[] = $clave . ($seccion[1] !== '' ? ' (dev)' : '');
                        }
                    }
                }
            }
            if (preg_match('/^dependencies\s*=\s*\[(.*?)\]/ms', $cuerpo, $lista) === 1
                && preg_match_all('/["\']([A-Za-z0-9_.\-]+)/', $lista[1], $claves)
            ) {
                $deps = array_merge($deps, $claves[1]);
            }
        } elseif ($nombre === 'Gemfile') {
            if (preg_match_all('/^\s*gem\s+["\']([^"\']+)/m', $cuerpo, $m)) {
                $deps = $m[1];
            }
        }
        return array_slice(array_values(array_unique($deps)), 0, 40);
    }

    /**
     * @return list<string>
     */
    private function extraerScripts(string $nombre, string $cuerpo): array
    {
        if (!in_array($nombre, ['package.json', 'composer.json', 'deno.json'], true)) {
            return [];
        }
        $json = json_decode($cuerpo, true);
        $scripts = is_array($json) ? ($json['scripts'] ?? $json['tasks'] ?? []) : [];
        $salida = [];
        foreach (is_array($scripts) ? $scripts : [] as $clave => $comando) {
            if (is_string($clave) && (is_string($comando) || is_array($comando))) {
                $texto = is_array($comando) ? implode(' && ', array_filter($comando, 'is_string')) : $comando;
                $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;
                $salida[] = $clave . ': ' . (mb_strlen($texto) > 100 ? mb_substr($texto, 0, 100) . '…' : $texto);
            }
            if (count($salida) >= 12) {
                break;
            }
        }
        return $salida;
    }

    private function compactarManifiestoJson(string $cuerpo): string
    {
        $json = json_decode($cuerpo, true);
        if (!is_array($json)) {
            return $cuerpo;
        }
        $claves = [
            'name', 'description', 'version', 'type', 'private', 'main', 'module', 'bin', 'workspaces',
            'engines', 'packageManager', 'scripts', 'tasks', 'dependencies', 'devDependencies',
            'peerDependencies', 'require', 'require-dev', 'imports', 'autoload',
        ];
        $filtrado = array_intersect_key($json, array_flip($claves));
        $codificado = json_encode($filtrado, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($codificado) ? $codificado : $cuerpo;
    }

    /**
     * @param list<string> $rutas
     * @return list<string>
     */
    private function seleccionarManifiestos(array $rutas): array
    {
        $prioridad = array_flip(self::MANIFIESTOS);
        $candidatas = [];
        foreach ($rutas as $ruta) {
            $partes = explode('/', $ruta);
            $nombre = end($partes);
            $profundidad = count($partes) - 1;
            $esManifiesto = isset($prioridad[$nombre]) || preg_match('/\.csproj$/i', $nombre) === 1;
            if (!$esManifiesto || $profundidad > 2 || $this->puntuarRuta($ruta) >= 4.0) {
                continue;
            }
            $candidatas[$ruta] = $profundidad * 100 + ($prioridad[$nombre] ?? 50);
        }
        asort($candidatas);
        return array_slice(array_keys($candidatas), 0, $this->maxManifiestos);
    }

    /**
     * @param list<string> $rutas
     * @return list<string>
     */
    private function seleccionarPuntosEntrada(array $rutas): array
    {
        $candidatas = [];
        $patron = '/^(main|index|app|server|cli|routes?|urls|web|api|wsgi|asgi|manage|program|lib|mod)'
            . '\.(php|js|mjs|ts|tsx|jsx|py|go|rs|rb|java|kt|cs|ex|dart|vue|svelte)$/i';
        foreach ($rutas as $ruta) {
            $partes = explode('/', $ruta);
            $nombre = end($partes);
            $profundidad = count($partes) - 1;
            if ($profundidad > 3 || preg_match($patron, $nombre) !== 1) {
                continue;
            }
            $puntuacion = $this->puntuarRuta($ruta);
            if ($puntuacion >= 4.0) {
                continue;
            }
            $candidatas[$ruta] = $puntuacion + $profundidad;
        }
        asort($candidatas);
        return array_slice(array_keys($candidatas), 0, $this->maxPuntosEntrada);
    }

    /**
     * Lenguajes + README + árbol en paralelo tras conocer la rama.
     *
     * @return array{
     *   lenguajes: array<string, int>,
     *   readme: string,
     *   arbol: list<string>,
     *   arbolInfo: array{total: int, truncado: bool, directorios: array<string, int>, rama: string},
     *   rutasTodas: list<string>
     * }
     */
    public function obtenerLenguajesReadmeYArbol(
        string $propietario,
        string $repositorio,
        string $rama = 'main',
        int $limiteArbol = 150
    ): array {
        $base = "/repos/{$propietario}/{$repositorio}";
        $peticiones = [
            'lenguajes' => [
                'ruta' => $base . '/languages',
                'cabeceras' => ['Accept: application/vnd.github+json'],
            ],
            'readme' => [
                'ruta' => $base . '/readme',
                'cabeceras' => ['Accept: application/vnd.github.raw'],
            ],
            'arbol' => [
                'ruta' => $base . '/git/trees/' . rawurlencode($rama) . '?recursive=1',
                'cabeceras' => ['Accept: application/vnd.github+json'],
            ],
        ];

        $respuestas = $this->solicitarMultiples($peticiones);

        $lenguajes = [];
        if (($respuestas['lenguajes']['ok'] ?? false) === true) {
            $json = json_decode($respuestas['lenguajes']['cuerpo'], true);
            if (is_array($json)) {
                /** @var array<string, int> $lenguajes */
                $lenguajes = $json;
            }
        }

        $readme = '';
        if (($respuestas['readme']['ok'] ?? false) === true) {
            $readme = $this->recortarTexto($respuestas['readme']['cuerpo'], 8000);
        }

        $procesado = null;
        if (($respuestas['arbol']['ok'] ?? false) === true) {
            $datos = json_decode($respuestas['arbol']['cuerpo'], true);
            if (is_array($datos)) {
                $procesado = $this->procesarArbol($datos, $limiteArbol, $rama);
            }
        }
        if ($procesado === null) {
            foreach (array_diff(['main', 'master'], [$rama]) as $ramaAlternativa) {
                $procesado = $this->obtenerArbolProcesado($propietario, $repositorio, $ramaAlternativa, $limiteArbol);
                if ($procesado !== null) {
                    break;
                }
            }
        }

        return [
            'lenguajes' => $lenguajes,
            'readme' => $readme,
            'arbol' => $procesado['arbol'] ?? [],
            'arbolInfo' => [
                'total' => $procesado['total'] ?? 0,
                'truncado' => $procesado['truncado'] ?? false,
                'directorios' => $procesado['directorios'] ?? [],
                'rama' => $procesado['rama'] ?? $rama,
            ],
            'rutasTodas' => $procesado['todas'] ?? [],
        ];
    }

    /**
     * @param array<string, array{ruta: string, cabeceras?: list<string>}> $peticiones
     * @return array<string, array{ok: bool, codigo: int, cuerpo: string}>
     */
    private function solicitarMultiples(array $peticiones): array
    {
        if (!function_exists('curl_multi_init') || count($peticiones) <= 1) {
            $resultado = [];
            foreach ($peticiones as $clave => $peticion) {
                try {
                    $cuerpo = $this->solicitarTexto(
                        $peticion['ruta'],
                        $peticion['cabeceras'] ?? ['Accept: application/vnd.github+json']
                    );
                    $resultado[$clave] = ['ok' => true, 'codigo' => 200, 'cuerpo' => $cuerpo];
                } catch (RuntimeException $excepcion) {
                    $codigo = $this->esErrorNoEncontrado($excepcion) ? 404 : 500;
                    $resultado[$clave] = ['ok' => false, 'codigo' => $codigo, 'cuerpo' => ''];
                }
            }
            return $resultado;
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            return $this->solicitarMultiples(array_slice($peticiones, 0, 1, true));
        }

        $manejadores = [];
        foreach ($peticiones as $clave => $peticion) {
            $url = str_starts_with($peticion['ruta'], 'https://')
                ? $peticion['ruta']
                : $this->urlBase . $peticion['ruta'];
            $cabeceras = array_merge([
                'User-Agent: reVERSEand0/1.0',
                'X-GitHub-Api-Version: 2022-11-28',
            ], $peticion['cabeceras'] ?? ['Accept: application/vnd.github+json']);

            $manejador = curl_init($url);
            if ($manejador === false) {
                continue;
            }
            curl_setopt_array($manejador, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->tiempoEspera,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $cabeceras,
            ]);
            curl_multi_add_handle($multi, $manejador);
            $manejadores[$clave] = $manejador;
        }

        $activo = null;
        do {
            $estado = curl_multi_exec($multi, $activo);
            if ($activo) {
                curl_multi_select($multi, 1.0);
            }
        } while ($activo && $estado === CURLM_OK);

        $resultado = [];
        foreach ($manejadores as $clave => $manejador) {
            $codigo = (int) curl_getinfo($manejador, CURLINFO_HTTP_CODE);
            $cuerpo = curl_multi_getcontent($manejador);
            curl_multi_remove_handle($multi, $manejador);
            curl_close($manejador);
            $resultado[$clave] = [
                'ok' => is_string($cuerpo) && $codigo >= 200 && $codigo < 300,
                'codigo' => $codigo,
                'cuerpo' => is_string($cuerpo) ? $cuerpo : '',
            ];
        }
        curl_multi_close($multi);
        foreach ($resultado as $clave => $item) {
            if (!in_array((int) ($item['codigo'] ?? 0), [502, 503, 504], true) || !isset($peticiones[$clave])) {
                continue;
            }
            try {
                $cuerpo = $this->solicitarTexto(
                    $peticiones[$clave]['ruta'],
                    $peticiones[$clave]['cabeceras'] ?? ['Accept: application/vnd.github+json']
                );
                $resultado[$clave] = ['ok' => true, 'codigo' => 200, 'cuerpo' => $cuerpo];
            } catch (RuntimeException $excepcion) {
                $resultado[$clave]['cuerpo'] = '';
            }
        }

        return $resultado;
    }

    /**
     * @param list<string> $cabecerasExtra
     * @return array<string, mixed>
     */
    private function solicitarJson(string $ruta, array $cabecerasExtra = []): array
    {
        $cuerpo = $this->solicitarTexto($ruta, array_merge(['Accept: application/vnd.github+json'], $cabecerasExtra));
        $datos = json_decode($cuerpo, true);

        if (!is_array($datos)) {
            throw new RuntimeException('La respuesta de GitHub no es JSON válido.');
        }

        return $datos;
    }

    /**
     * @param list<string> $cabecerasExtra
     */
    private function solicitarTexto(string $ruta, array $cabecerasExtra = []): string
    {
        $url = str_starts_with($ruta, 'https://') ? $ruta : $this->urlBase . $ruta;
        $cabeceras = array_merge([
            'User-Agent: reVERSEand0/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ], $cabecerasExtra);

        if (function_exists('curl_init')) {
            return $this->solicitarConCurl($url, $cabeceras);
        }

        return $this->solicitarConStream($url, $cabeceras);
    }

    /**
     * @param list<string> $cabeceras
     */
    private function solicitarConCurl(string $url, array $cabeceras): string
    {
        $ultimoError = '';
        $ultimoCodigo = 0;
        $ultimoCuerpo = '';

        for ($intento = 1; $intento <= 3; $intento++) {
            $manejador = curl_init($url);
            if ($manejador === false) {
                throw new RuntimeException('No se pudo iniciar la petición a GitHub.');
            }

            curl_setopt_array($manejador, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->tiempoEspera,
                CURLOPT_HTTPHEADER => $cabeceras,
            ]);

            $cuerpo = curl_exec($manejador);
            $codigo = (int) curl_getinfo($manejador, CURLINFO_HTTP_CODE);
            $errorCurl = curl_error($manejador);
            $errno = curl_errno($manejador);
            curl_close($manejador);

            $transitorio = in_array($codigo, [502, 503, 504], true) || $errno === 28;
            if ($cuerpo !== false && !$transitorio) {
                $this->lanzarSiErrorHttp($codigo, (string) $cuerpo);
                return (string) $cuerpo;
            }

            $ultimoCodigo = $codigo;
            $ultimoError = $errorCurl;
            $ultimoCuerpo = is_string($cuerpo) ? $cuerpo : '';
            if (!$transitorio) {
                break;
            }
        }

        if ($ultimoCuerpo === '' && $ultimoCodigo === 0) {
            throw new RuntimeException('Fallo de red al contactar GitHub: ' . $ultimoError);
        }

        $this->lanzarSiErrorHttp($ultimoCodigo, $ultimoCuerpo);
        return $ultimoCuerpo;
    }

    /**
     * @param list<string> $cabeceras
     */
    private function solicitarConStream(string $url, array $cabeceras): string
    {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $cabeceras),
                'timeout' => $this->tiempoEspera,
                'ignore_errors' => true,
            ],
        ]);

        $cuerpo = @file_get_contents($url, false, $contexto);
        if ($cuerpo === false) {
            throw new RuntimeException('Fallo de red al contactar GitHub.');
        }

        $codigo = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $codigo = (int) $m[1];
        }

        $this->lanzarSiErrorHttp($codigo, $cuerpo);
        return $cuerpo;
    }

    private function lanzarSiErrorHttp(int $codigo, string $cuerpo): void
    {
        if ($codigo >= 200 && $codigo < 300) {
            return;
        }

        if ($codigo === 404) {
            throw new RuntimeException('Repositorio no encontrado o privado. [404]');
        }

        if ($codigo === 403 || $codigo === 429) {
            throw new RuntimeException(
                'Límite de la API de GitHub alcanzado o acceso denegado. Intenta de nuevo más tarde. [' . $codigo . ']'
            );
        }

        $mensajeApi = '';
        $json = json_decode($cuerpo, true);
        if (is_array($json) && isset($json['message'])) {
            $mensajeApi = ' ' . (string) $json['message'];
        }

        throw new RuntimeException('Error al consultar GitHub (' . $codigo . ').' . $mensajeApi);
    }

    private function esErrorNoEncontrado(RuntimeException $excepcion): bool
    {
        return str_contains($excepcion->getMessage(), '[404]');
    }

    private function debeIgnorarRuta(string $ruta): bool
    {
        $fragmentos = [
            'node_modules/',
            'vendor/',
            '.git/',
            'dist/',
            'build/',
            '.next/',
            'coverage/',
            '__pycache__/',
            '.venv/',
            '.idea/',
        ];

        foreach ($fragmentos as $fragmento) {
            if (str_contains($ruta, $fragmento)) {
                return true;
            }
        }

        return false;
    }

    private function recortarTexto(string $texto, int $maximo): string
    {
        if (mb_strlen($texto) <= $maximo) {
            return $texto;
        }

        return mb_substr($texto, 0, $maximo) . "\n…[contenido recortado]";
    }
}
