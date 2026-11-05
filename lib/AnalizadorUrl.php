<?php
/**
 * Extrae propietario/repositorio de GitHub y valida URLs HTTP públicas.
 */
class AnalizadorUrl
{
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

        // Acepta github.com/a/b, www.github.com/a/b, git@github.com:a/b.git, a/b
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
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('No se permiten direcciones locales (localhost).');
        }

        if ($this->esHostPrivadoOReservado($host)) {
            throw new InvalidArgumentException('No se permiten IPs privadas o reservadas.');
        }

        $puerto = isset($partes['port']) ? (int) $partes['port'] : ($esquema === 'https' ? 443 : 80);
        if (!in_array($puerto, [80, 443, 8080, 8443], true)) {
            throw new InvalidArgumentException('Puerto no permitido para el análisis web.');
        }

        $ruta = $partes['path'] ?? '/';
        $query = isset($partes['query']) ? '?' . $partes['query'] : '';
        $fragmento = '';

        return $esquema . '://' . $partes['host']
            . (isset($partes['port']) ? ':' . $partes['port'] : '')
            . $ruta . $query . $fragmento;
    }

    /**
     * Comprueba host literal. Los nombres se validan tras DoH en ClienteWeb
     * para no bloquear con gethostbynamel() (puede colgar ~10s).
     */
    private function esHostPrivadoOReservado(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->esIpPrivadaOReservada($host);
        }

        return false;
    }

    private function esIpPrivadaOReservada(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $privado = !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            return $privado;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $privado = !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            return $privado || $ip === '::1';
        }

        return true;
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
