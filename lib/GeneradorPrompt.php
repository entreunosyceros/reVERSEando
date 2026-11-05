<?php
/**
 * Construye prompts de desarrollo a partir de datos de repositorio o entrada manual.
 */
class GeneradorPrompt
{
    /**
     * @param array{
     *   metadatos: array<string, mixed>,
     *   lenguajes: array<string, int>,
     *   readme: string,
     *   arbol: list<string>,
     *   archivoConfig: array{ruta: string, contenido: string}|null
     * } $datos
     */
    public function construirPromptDesdeRepositorio(array $datos): string
    {
        $metadatos = $datos['metadatos'];
        $nombreCompleto = (string) ($metadatos['full_name'] ?? 'repositorio');
        $descripcion = trim((string) ($metadatos['description'] ?? ''));
        $url = (string) ($metadatos['html_url'] ?? '');
        $rama = (string) ($metadatos['default_branch'] ?? 'main');
        $temas = $metadatos['topics'] ?? [];
        $licencia = $metadatos['license']['spdx_id'] ?? ($metadatos['license']['name'] ?? 'No indicada');

        $stack = $this->formatearLenguajes($datos['lenguajes']);
        $estructura = $this->formatearArbol($datos['arbol']);
        $readme = trim($datos['readme']) !== ''
            ? $this->recortarBloque($datos['readme'], 3500)
            : 'No hay README disponible.';

        $bloqueConfig = 'No se detectó un archivo de configuración principal.';
        if ($datos['archivoConfig'] !== null) {
            $bloqueConfig = "Archivo: {$datos['archivoConfig']['ruta']}\n"
                . $this->recortarBloque($datos['archivoConfig']['contenido'], 2500);
        }

        $temasTexto = is_array($temas) && $temas !== []
            ? implode(', ', array_map('strval', $temas))
            : 'Ninguno';

        $objetivo = $descripcion !== ''
            ? $descripcion
            : "Reconstruir la esencia funcional del proyecto {$nombreCompleto}.";

        return <<<PROMPT
# Prompt de reconstrucción — {$nombreCompleto}

## Rol
Eres un ingeniero de software senior. Debes diseñar e implementar un proyecto inspirado en el repositorio analizado, priorizando claridad, mantenibilidad y un MVP funcional.

## Objetivo
{$objetivo}

## Origen
- Repositorio: {$nombreCompleto}
- URL: {$url}
- Rama por defecto: {$rama}
- Licencia: {$licencia}
- Temas: {$temasTexto}

## Stack detectado
{$stack}

## Estructura observada (muestra)
{$estructura}

## README (extracto)
{$readme}

## Configuración clave
{$bloqueConfig}

## Requisitos de implementación
1. Define la arquitectura mínima necesaria para reproducir el comportamiento principal.
2. Respeta las convenciones visibles en la estructura y en los archivos de configuración.
3. Implementa primero el flujo feliz; deja extensiones avanzadas como fases posteriores.
4. Incluye instrucciones de instalación, ejecución y verificación.
5. Documenta decisiones técnicas breves cuando haya ambigüedad.

## Formato de respuesta esperado
1. Resumen del producto
2. Arquitectura propuesta
3. Plan de tareas ordenado
4. Esqueleto de carpetas y archivos
5. Primeros pasos de código e instrucciones de arranque

Genera el plan y el código inicial listos para empezar a construir.
PROMPT;
    }

    /**
     * Prompt de recreación visual a partir de datos HTML/CSS de una web.
     *
     * @param array{
     *   url: string,
     *   titulo: string,
     *   descripcion: string,
     *   headings: list<string>,
     *   clasesFrecuentes: list<string>,
     *   textoVisible: string,
     *   resumenCss: string
     * } $datos
     */
    public function construirPromptDesdeWeb(array $datos): string
    {
        $url = (string) ($datos['url'] ?? '');
        $titulo = trim((string) ($datos['titulo'] ?? '')) ?: 'Página sin título';
        $descripcion = trim((string) ($datos['descripcion'] ?? '')) ?: 'Sin meta description.';
        $headings = $datos['headings'] ?? [];
        $clases = $datos['clasesFrecuentes'] ?? [];
        $texto = $this->recortarBloque((string) ($datos['textoVisible'] ?? ''), 3500);
        $css = $this->recortarBloque((string) ($datos['resumenCss'] ?? ''), 3000);

        $headingsTexto = is_array($headings) && $headings !== []
            ? implode("\n", array_map(static fn ($h) => '- ' . $h, array_slice($headings, 0, 30)))
            : '- No se detectaron headings.';
        $clasesTexto = is_array($clases) && $clases !== []
            ? implode(', ', array_slice($clases, 0, 40))
            : 'Ninguna';

        return <<<PROMPT
# Prompt de recreación visual — {$titulo}

## Rol
Eres un diseñador/front-end senior. Debes proponer cómo recrear el tema visual y la distribución de una página web a partir del análisis aportado (sin copiar marcas, logos ni textos protegidos).

## Objetivo
Recrear la sensación visual, jerarquía y layout de la página de referencia en un MVP front-end moderno.

## Origen
- URL: {$url}
- Título: {$titulo}
- Descripción: {$descripcion}

## Estructura observada (headings)
{$headingsTexto}

## Clases frecuentes
{$clasesTexto}

## Texto visible (extracto)
{$texto}

## Tokens / CSS detectados
{$css}

## Requisitos de implementación
1. Define una paleta, tipografías, radios, espaciado y atmósfera coherentes con lo observado.
2. Describe el layout por secciones (hero, navegación, contenido, pie, etc.).
3. Propón HTML/CSS (o un stack front ligero) para un MVP responsive.
4. No copies identidad de marca propietaria; inspírate en la distribución y el estilo.
5. Incluye criterios de calidad visual y checklist responsive.

## Formato de respuesta esperado
1. Resumen visual del producto
2. Design tokens
3. Wireframe/secciones
4. Plan de implementación UI
5. Esqueleto de archivos e instrucciones de arranque

Genera un prompt listo para construir la interfaz.
PROMPT;
    }

    /**
     * Construye un prompt a partir de título y descripción manuales.
     */
    public function construirPromptManual(string $titulo, string $descripcion): string
    {
        $titulo = trim($titulo);
        $descripcion = trim($descripcion);

        if ($titulo === '') {
            $titulo = 'Proyecto sin título';
        }

        if ($descripcion === '') {
            $descripcion = 'Sin descripción adicional proporcionada.';
        }

        return <<<PROMPT
# Prompt de construcción — {$titulo}

## Rol
Eres un ingeniero de software senior. Debes convertir la descripción del usuario en un plan de implementación concreto y ejecutable.

## Objetivo
{$titulo}

## Descripción aportada por el usuario
{$descripcion}

## Requisitos de implementación
1. Extrae requisitos funcionales y no funcionales a partir de la descripción.
2. Propón un stack razonable si no está definido.
3. Diseña una arquitectura simple y un MVP.
4. Entrega un plan de tareas priorizado.
5. Incluye estructura de carpetas e instrucciones de arranque.

## Formato de respuesta esperado
1. Resumen del producto
2. Requisitos claros
3. Arquitectura propuesta
4. Plan de tareas
5. Esqueleto del proyecto e instrucciones iniciales

Empieza por el MVP y evita sobreingeniería.
PROMPT;
    }

    /**
     * @param array<string, int> $lenguajes
     */
    private function formatearLenguajes(array $lenguajes): string
    {
        if ($lenguajes === []) {
            return '- No se detectaron lenguajes.';
        }

        arsort($lenguajes);
        $total = array_sum($lenguajes);
        $lineas = [];

        foreach ($lenguajes as $nombre => $bytes) {
            $porcentaje = $total > 0 ? round(($bytes / $total) * 100, 1) : 0;
            $lineas[] = "- {$nombre}: {$porcentaje}%";
        }

        return implode("\n", $lineas);
    }

    /**
     * @param list<string> $arbol
     */
    private function formatearArbol(array $arbol): string
    {
        if ($arbol === []) {
            return '- No se pudo obtener el árbol de archivos.';
        }

        $lineas = array_map(
            static fn (string $ruta): string => '- ' . $ruta,
            array_slice($arbol, 0, 80)
        );

        if (count($arbol) > 80) {
            $lineas[] = '- …';
        }

        return implode("\n", $lineas);
    }

    private function recortarBloque(string $texto, int $maximo): string
    {
        $texto = trim($texto);
        if (mb_strlen($texto) <= $maximo) {
            return $texto;
        }

        return mb_substr($texto, 0, $maximo) . "\n…[recortado]";
    }
}
