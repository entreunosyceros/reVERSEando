<?php
/**
 * Extrae propietario/repositorio de GitHub y valida URLs HTTP públicas (anti-SSRF).
 */
class AnalizadorUrl
{
    /** @var list<int> */
    private array $puertosPermitidos = [80, 443];

    /**
     * Analiza una URL o cadena tipo propietario/repositorio.
     *
     * @return array{propietario: string, repositorio: string}
     * @throws InvalidArgumentException Si el formato no es válido.
     */
    public function analizarUrlGitHub(string $entrada): array
    {
        $entrada = trim($entrada);

        if ($entrada === '') {
            throw new InvalidArgumentException('La URL del repositorio está vacía.');
        }

        $patron = '#^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?(?:/.*)?$#i';
        if (preg_match($patron, $entrada, $coincidencias) === 1) {
            return $this->normalizarPartes($coincidencias[1], $coincidencias[2]);
        }

        $patronSsh = '#^git@github\.com:([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?$#i';
        if (preg_match($patronSsh, $entrada, $coincidencias) === 1) {
            return $this->normalizarPartes($coincidencias[1], $coincidencias[2]);
        }

        $patronCorto = '#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#';
        if (preg_match($patronCorto, $entrada, $coincidencias) === 1) {
            return $this->normalizarPartes($coincidencias[1], $coincidencias[2]);
        }

        throw new InvalidArgumentException(
            'URL de GitHub no válida. Usa el formato https://github.com/propietario/repositorio.'
        );
    }

    /**
     * Valida y normaliza una URL http(s) pública (anti-SSRF).
     *
     * @throws InvalidArgumentException
     */
    public function validarUrlHttpPublica(string $entrada): string
    {
        $entrada = trim($entrada);

        if ($entrada === '') {
            throw new InvalidArgumentException('La URL de la página web está vacía.');
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $entrada, $esquemaMatch) === 1) {
            $esquemaDetectado = strtolower($esquemaMatch[1]);
            if ($esquemaDetectado !== 'http' && $esquemaDetectado !== 'https') {
                throw new InvalidArgumentException('Solo se permiten URLs http o https.');
            }
        } else {
            $entrada = 'https://' . $entrada;
        }

        $partes = parse_url($entrada);
        if ($partes === false || empty($partes['scheme']) || empty($partes['host'])) {
            throw new InvalidArgumentException('URL de página web no válida.');
        }

        $esquema = strtolower((string) $partes['scheme']);
        if ($esquema !== 'http' && $esquema !== 'https') {
            throw new InvalidArgumentException('Solo se permiten URLs http o https.');
        }

        $host = strtolower((string) $partes['host']);
        // Quitar corchetes de literales IPv6 si parse_url los deja.
        $host = trim($host, '[]');

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('No se permiten direcciones locales (localhost).');
        }

        if ($this->esHostLiteralNoPermitido($host)) {
            throw new InvalidArgumentException('No se permiten IPs privadas, locales o reservadas.');
        }

        $puerto = isset($partes['port']) ? (int) $partes['port'] : ($esquema === 'https' ? 443 : 80);
        if (!$this->esPuertoPermitido($puerto)) {
            throw new InvalidArgumentException('Solo se permiten los puertos 80 y 443.');
        }

        $ruta = $partes['path'] ?? '/';
        $query = isset($partes['query']) ? '?' . $partes['query'] : '';

        return $esquema . '://' . $host
            . (isset($partes['port']) ? ':' . $partes['port'] : '')
            . $ruta . $query;
    }

    public function esPuertoPermitido(int $puerto): bool
    {
        return in_array($puerto, $this->puertosPermitidos, true);
    }

    /**
     * Comprueba si una IP (v4 o v6) es privada, local, link-local, ULA o reservada.
     */
    public function esIpPrivadaOReservada(string $ip): bool
    {
        $ip = trim($ip, '[]');

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                return true;
            }
            // 0.0.0.0 / broadcast / cloud metadata habituales.
            if ($ip === '0.0.0.0' || $ip === '255.255.255.255' || str_starts_with($ip, '169.254.')) {
                return true;
            }
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalizada = strtolower($ip);
            if ($normalizada === '::1' || $normalizada === '::') {
                return true;
            }
            // IPv4-mapeada: :ffff:x.x.x.x
            if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/', $normalizada, $m) === 1) {
                return $this->esIpPrivadaOReservada($m[1]);
            }
            if (!filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                return true;
            }
            // Link-local fe80::/10, ULA fc00::/7, multicast ff00::/8
            if (preg_match('/^fe[89ab][0-9a-f]:/i', $normalizada) === 1) {
                return true;
            }
            if (preg_match('/^f[cd][0-9a-f]{2}:/i', $normalizada) === 1) {
                return true;
            }
            if (str_starts_with($normalizada, 'ff')) {
                return true;
            }
            return false;
        }

        return true;
    }

    private function esHostLiteralNoPermitido(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->esIpPrivadaOReservada($host);
        }
        return false;
    }

    /**
     * @return array{propietario: string, repositorio: string}
     */
    private function normalizarPartes(string $propietario, string $repositorio): array
    {
        $repositorio = preg_replace('/\.git$/i', '', $repositorio) ?? $repositorio;

        if ($propietario === '' || $repositorio === '') {
            throw new InvalidArgumentException('No se pudo identificar propietario y repositorio.');
        }

        return [
            'propietario' => $propietario,
            'repositorio' => $repositorio,
        ];
    }
}
