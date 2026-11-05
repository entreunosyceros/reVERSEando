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

    /**
     * Obtiene rutas del árbol de archivos (recortado).
     *
     * @return list<string>
     */
    public function obtenerArbolArchivos(
        string $propietario,
        string $repositorio,
        string $rama = 'main',
        int $limite = 120
    ): array {
        $ramasCandidatas = array_values(array_unique([$rama, 'main', 'master']));
        $ultimoError = null;

        foreach ($ramasCandidatas as $ramaActual) {
            try {
                $datos = $this->solicitarJson(
                    "/repos/{$propietario}/{$repositorio}/git/trees/{$ramaActual}?recursive=1"
                );
                $arbol = $datos['tree'] ?? [];
                $rutas = [];

                foreach ($arbol as $nodo) {
                    if (($nodo['type'] ?? '') !== 'blob') {
                        continue;
                    }
                    $ruta = (string) ($nodo['path'] ?? '');
                    if ($ruta === '' || $this->debeIgnorarRuta($ruta)) {
                        continue;
                    }
                    $rutas[] = $ruta;
                    if (count($rutas) >= $limite) {
                        break;
                    }
                }

                return $rutas;
            } catch (RuntimeException $excepcion) {
                $ultimoError = $excepcion;
                if (!$this->esErrorNoEncontrado($excepcion)) {
                    throw $excepcion;
                }
            }
        }

        if ($ultimoError !== null) {
            return [];
        }

        return [];
    }

    /**
     * Obtiene el contenido de un archivo de texto del repositorio.
     */
    public function obtenerContenidoArchivo(
        string $propietario,
        string $repositorio,
        string $ruta,
        string $rama = 'main'
    ): string {
        $rutaCodificada = implode('/', array_map('rawurlencode', explode('/', $ruta)));

        try {
            $datos = $this->solicitarJson(
                "/repos/{$propietario}/{$repositorio}/contents/{$rutaCodificada}?ref=" . rawurlencode($rama)
            );
        } catch (RuntimeException $excepcion) {
            if ($this->esErrorNoEncontrado($excepcion)) {
                return '';
            }
            throw $excepcion;
        }

        if (($datos['encoding'] ?? '') === 'base64' && isset($datos['content'])) {
            $decodificado = base64_decode((string) $datos['content'], true);
            if ($decodificado === false) {
                return '';
            }
            return $this->recortarTexto($decodificado, 4000);
        }

        return '';
    }

    /**
     * Busca el primer archivo de configuración disponible entre candidatos.
     * Si se pasa el árbol, evita peticiones 404 a rutas inexistentes.
     *
     * @param list<string> $candidatos
     * @param list<string>|null $rutasArbol
     * @return array{ruta: string, contenido: string}|null
     */
    public function obtenerPrimerArchivoExistente(
        string $propietario,
        string $repositorio,
        array $candidatos,
        string $rama = 'main',
        ?array $rutasArbol = null
    ): ?array {
        if (is_array($rutasArbol)) {
            $indice = array_fill_keys($rutasArbol, true);
            $candidatos = array_values(array_filter(
                $candidatos,
                static fn (string $ruta): bool => isset($indice[$ruta])
            ));
        }

        foreach ($candidatos as $ruta) {
            $contenido = $this->obtenerContenidoArchivo($propietario, $repositorio, $ruta, $rama);
            if ($contenido !== '') {
                return [
                    'ruta' => $ruta,
                    'contenido' => $contenido,
                ];
            }
        }

        return null;
    }

    /**
     * Lenguajes + README + árbol en paralelo tras conocer la rama.
     *
     * @return array{
     *   lenguajes: array<string, int>,
     *   readme: string,
     *   arbol: list<string>
     * }
     */
    public function obtenerLenguajesReadmeYArbol(
        string $propietario,
        string $repositorio,
        string $rama = 'main',
        int $limiteArbol = 120
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

        $arbol = [];
        if (($respuestas['arbol']['ok'] ?? false) === true) {
            $datos = json_decode($respuestas['arbol']['cuerpo'], true);
            $nodos = is_array($datos) ? ($datos['tree'] ?? []) : [];
            if (is_array($nodos)) {
                foreach ($nodos as $nodo) {
                    if (!is_array($nodo) || ($nodo['type'] ?? '') !== 'blob') {
                        continue;
                    }
                    $ruta = (string) ($nodo['path'] ?? '');
                    if ($ruta === '' || $this->debeIgnorarRuta($ruta)) {
                        continue;
                    }
                    $arbol[] = $ruta;
                    if (count($arbol) >= $limiteArbol) {
                        break;
                    }
                }
            }
        } elseif ($rama !== 'main' && $rama !== 'master') {
            // Fallback solo si la rama por defecto falló.
            $arbol = $this->obtenerArbolArchivos($propietario, $repositorio, 'main', $limiteArbol);
        }

        return [
            'lenguajes' => $lenguajes,
            'readme' => $readme,
            'arbol' => $arbol,
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
            $url = $this->urlBase . $peticion['ruta'];
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
        $url = $this->urlBase . $ruta;
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
        curl_close($manejador);

        if ($cuerpo === false) {
            throw new RuntimeException('Fallo de red al contactar GitHub: ' . $errorCurl);
        }

        $this->lanzarSiErrorHttp($codigo, (string) $cuerpo);
        return (string) $cuerpo;
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
