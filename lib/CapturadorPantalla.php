<?php
/**
 * Captura de pantalla de una URL con Chrome/Chromium headless aislado.
 */
class CapturadorPantalla
{
    private int $tiempoEsperaProceso = 45;
    private ResolutorDns $resolutor;
    private AnalizadorUrl $analizador;

    /** @var list<string> */
    private array $candidatosBinario = [
        'google-chrome-stable',
        'google-chrome',
        'chromium',
        'chromium-browser',
        'chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ];

    public function __construct(?ResolutorDns $resolutor = null, ?AnalizadorUrl $analizador = null)
    {
        $this->resolutor = $resolutor ?? new ResolutorDns();
        $this->analizador = $analizador ?? new AnalizadorUrl();
    }

    /**
     * @return array{mime: string, base64: string, bytes: int}
     */
    public function capturar(string $url): array
    {
        $url = $this->analizador->validarUrlHttpPublica($url);

        $binario = $this->localizarBinario();
        if ($binario === null) {
            throw new RuntimeException(
                'No se encontró Chrome ni Chromium. Instálalo para el modo página web con Gemini '
                . '(por ejemplo: sudo apt install chromium-browser).'
            );
        }

        $directorio = sys_get_temp_dir() . '/reverseando-captures';
        if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de capturas.');
        }

        $archivo = $directorio . '/shot-' . bin2hex(random_bytes(8)) . '.png';
        $perfil = $directorio . '/profile-' . bin2hex(random_bytes(6));
        if (!mkdir($perfil, 0700, true) && !is_dir($perfil)) {
            throw new RuntimeException('No se pudo crear el perfil temporal de Chromium.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ipv4 = null;
        if ($host !== '') {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipv4 = $host;
            } else {
                $ipv4 = $this->resolutor->resolverIpv4($host);
            }
            if ($ipv4 === null || $this->analizador->esIpPrivadaOReservada($ipv4)) {
                $this->limpiarDir($perfil);
                throw new RuntimeException('Destino no seguro para captura (DNS/IP no pública).');
            }
        }

        $comando = [
            $binario,
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox', // necesario en muchos entornos locales sin user namespaces
            '--disable-dev-shm-usage',
            '--disable-extensions',
            '--disable-plugins',
            '--disable-background-networking',
            '--disable-sync',
            '--disable-translate',
            '--disable-default-apps',
            '--no-first-run',
            '--no-default-browser-check',
            '--metrics-recording-only',
            '--mute-audio',
            '--hide-scrollbars',
            '--window-size=1280,900',
            '--virtual-time-budget=3500',
            '--user-data-dir=' . $perfil,
            '--data-path=' . $perfil . '/data',
            '--disk-cache-dir=' . $perfil . '/cache',
        ];

        if ($host !== '' && $ipv4 !== null) {
            $comando[] = '--host-resolver-rules=MAP ' . $host . ' ' . $ipv4;
        }

        $comando[] = '--screenshot=' . $archivo;
        $comando[] = $url;

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proceso = proc_open($comando, $descriptor, $tubos, null, null, [
            'bypass_shell' => true,
        ]);

        if (!is_resource($proceso)) {
            $this->limpiarDir($perfil);
            throw new RuntimeException('No se pudo iniciar Chromium para la captura.');
        }

        fclose($tubos[0]);
        stream_set_blocking($tubos[1], false);
        stream_set_blocking($tubos[2], false);

        $inicio = time();
        $stdout = '';
        $stderr = '';

        do {
            $stdout .= (string) stream_get_contents($tubos[1]);
            $stderr .= (string) stream_get_contents($tubos[2]);
            $estado = proc_get_status($proceso);
            if (!$estado['running']) {
                break;
            }
            if ((time() - $inicio) >= $this->tiempoEsperaProceso) {
                proc_terminate($proceso, 9);
                fclose($tubos[1]);
                fclose($tubos[2]);
                proc_close($proceso);
                @unlink($archivo);
                $this->limpiarDir($perfil);
                throw new RuntimeException('La captura de pantalla agotó el tiempo de espera.');
            }
            usleep(100000);
        } while (true);

        $stdout .= (string) stream_get_contents($tubos[1]);
        $stderr .= (string) stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        $codigo = proc_close($proceso);

        if (!is_file($archivo) || filesize($archivo) < 100) {
            @unlink($archivo);
            $this->limpiarDir($perfil);
            $detalle = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException(
                'No se pudo generar la captura de pantalla.'
                . ($detalle !== '' ? ' ' . mb_substr($detalle, 0, 300) : '')
                . ($codigo !== 0 ? ' (código ' . $codigo . ')' : '')
            );
        }

        $bytes = file_get_contents($archivo);
        @unlink($archivo);
        $this->limpiarDir($perfil);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('La captura de pantalla está vacía.');
        }

        if (strlen($bytes) > 4_000_000) {
            throw new RuntimeException('La captura es demasiado grande para enviarla a Gemini.');
        }

        return [
            'mime' => 'image/png',
            'base64' => base64_encode($bytes),
            'bytes' => strlen($bytes),
        ];
    }

    public function localizarBinario(): ?string
    {
        foreach ($this->candidatosBinario as $candidato) {
            if (str_contains($candidato, '/')) {
                if (is_executable($candidato)) {
                    return $candidato;
                }
                continue;
            }

            $ruta = $this->which($candidato);
            if ($ruta !== null) {
                return $ruta;
            }
        }

        return null;
    }

    private function which(string $comando): ?string
    {
        $salida = [];
        $codigo = 0;
        @exec('command -v ' . escapeshellarg($comando) . ' 2>/dev/null', $salida, $codigo);
        if ($codigo === 0 && isset($salida[0]) && is_executable($salida[0])) {
            return $salida[0];
        }
        return null;
    }

    private function limpiarDir(string $directorio): void
    {
        if (!is_dir($directorio)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directorio, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directorio);
    }
}
