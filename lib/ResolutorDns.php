<?php
/**
 * Resolución DNS vía DNS-over-HTTPS (cuando el resolver local falla o es lento).
 */
class ResolutorDns
{
    private int $tiempoEspera = 8;

    /** @var list<string> */
    private array $endpoints = [
        'https://dns.google/resolve?name=%s&type=A',
        'https://cloudflare-dns.com/dns-query?name=%s&type=A',
    ];

    /**
     * Devuelve una IPv4 pública o null si no se pudo resolver.
     */
    public function resolverIpv4(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        foreach ($this->endpoints as $plantilla) {
            $url = sprintf($plantilla, rawurlencode($host));
            try {
                $json = $this->obtenerJson($url);
                $respuestas = $json['Answer'] ?? null;
                if (!is_array($respuestas)) {
                    continue;
                }
                foreach ($respuestas as $respuesta) {
                    if (!is_array($respuesta)) {
                        continue;
                    }
                    // type 1 = A
                    if ((int) ($respuesta['type'] ?? 0) !== 1) {
                        continue;
                    }
                    $ip = (string) ($respuesta['data'] ?? '');
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return $ip;
                    }
                }
            } catch (RuntimeException $excepcion) {
                continue;
            }
        }

        return null;
    }

    /**
     * Entradas CURLOPT_RESOLVE para http/https.
     *
     * @return list<string>
     */
    public function entradasCurlResolve(string $host, string $ipv4): array
    {
        return [
            $host . ':443:' . $ipv4,
            $host . ':80:' . $ipv4,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function obtenerJson(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL no disponible para DoH.');
        }

        $manejador = curl_init($url);
        if ($manejador === false) {
            throw new RuntimeException('No se pudo iniciar DoH.');
        }

        $hostDoH = strtolower((string) parse_url($url, PHP_URL_HOST));
        // IPs conocidas por si el DNS local tampoco resuelve los endpoints DoH.
        $ipsDoH = [
            'dns.google' => '8.8.8.8',
            'cloudflare-dns.com' => '1.1.1.1',
        ];

        $opciones = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->tiempoEspera,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/dns-json',
                'User-Agent: reVERSEand0/1.0',
            ],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ];

        if (isset($ipsDoH[$hostDoH])) {
            $ip = $ipsDoH[$hostDoH];
            $opciones[CURLOPT_RESOLVE] = [
                $hostDoH . ':443:' . $ip,
                $hostDoH . ':80:' . $ip,
            ];
        }

        curl_setopt_array($manejador, $opciones);

        $cuerpo = curl_exec($manejador);
        $codigo = (int) curl_getinfo($manejador, CURLINFO_HTTP_CODE);
        $error = curl_error($manejador);
        curl_close($manejador);

        if ($cuerpo === false || $codigo < 200 || $codigo >= 300) {
            throw new RuntimeException('DoH falló: ' . ($error !== '' ? $error : 'HTTP ' . $codigo));
        }

        $datos = json_decode((string) $cuerpo, true);
        if (!is_array($datos)) {
            throw new RuntimeException('DoH devolvió JSON inválido.');
        }

        return $datos;
    }
}
