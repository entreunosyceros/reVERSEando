<?php
/**
 * Página de aterrizaje y herramienta principal de reVERSEand0.
 */
declare(strict_types=1);

$ejemplosRepositorio = [
    ['nombre' => 'React', 'url' => 'https://github.com/facebook/react'],
    ['nombre' => 'Vue', 'url' => 'https://github.com/vuejs/core'],
    ['nombre' => 'Next.js', 'url' => 'https://github.com/vercel/next.js'],
    ['nombre' => 'VS Code', 'url' => 'https://github.com/microsoft/vscode'],
    ['nombre' => 'TensorFlow', 'url' => 'https://github.com/tensorflow/tensorflow'],
    ['nombre' => 'Kubernetes', 'url' => 'https://github.com/kubernetes/kubernetes'],
    ['nombre' => 'Django', 'url' => 'https://github.com/django/django'],
    ['nombre' => 'Linux', 'url' => 'https://github.com/torvalds/linux'],
];

$ejemplosWeb = [
    ['nombre' => 'Stripe', 'url' => 'https://stripe.com'],
    ['nombre' => 'Apple', 'url' => 'https://www.apple.com'],
    ['nombre' => 'GitHub', 'url' => 'https://github.com'],
    ['nombre' => 'Linear', 'url' => 'https://linear.app'],
    ['nombre' => 'Vercel', 'url' => 'https://vercel.com'],
    ['nombre' => 'Notion', 'url' => 'https://www.notion.so'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="reVERSEand0 transforma un repositorio de GitHub en un prompt útil para desarrollar tu proyecto.">
  <title>reVERSEand0 — Del repositorio al prompt</title>
  <link rel="icon" href="img/logo.png" type="image/png">
  <link rel="apple-touch-icon" href="img/logo.png">
  <script>
    (function () {
      try {
        var guardado = localStorage.getItem('reverseando-tema');
        var tema = (guardado === 'claro' || guardado === 'oscuro')
          ? guardado
          : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'claro' : 'oscuro');
        document.documentElement.setAttribute('data-tema', tema);
      } catch (e) {
        document.documentElement.setAttribute('data-tema', 'oscuro');
      }
    })();
  </script>
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
  <div class="envoltorio">
    <header class="cabecera">
      <button
        type="button"
        class="marca"
        id="boton-logo-docs"
        title="Acerca reVERSEando"
        aria-label="Acerca reVERSEando"
      >
        <span class="marca-marco">
          <img class="logo-marca logo-para-oscuro" src="img/logo.png" alt="reVERSEand0" width="180" height="40">
          <img class="logo-marca logo-para-claro" src="img/logo-claro.png" alt="reVERSEand0" width="180" height="40">
        </span>
      </button>
      <div class="acciones-cabecera">
        <p id="estado-gemini" class="estado-gemini" aria-live="polite">Usando plantilla local</p>
        <button
          class="boton-tema"
          type="button"
          id="boton-tema"
          aria-label="Activar tema claro"
          aria-pressed="true"
        >
          <span class="boton-tema-icono boton-tema-icono-sol" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="4"></circle>
              <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path>
            </svg>
          </span>
          <span class="boton-tema-icono boton-tema-icono-luna" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 14.5A8.5 8.5 0 1 1 9.5 3a7 7 0 0 0 11.5 11.5z"></path>
            </svg>
          </span>
        </button>
        <button class="boton boton-secundario" type="button" id="boton-configurar-gemini">
          Configurar Gemini
        </button>
      </div>
    </header>

    <dialog id="dialogo-documentacion" class="dialogo-documentacion" aria-labelledby="titulo-dialogo-docs">
      <div class="dialogo-docs-cabecera">
        <h2 id="titulo-dialogo-docs">Acerca de reVERSEand0</h2>
        <button class="boton boton-secundario" type="button" id="boton-cerrar-docs">
          Cerrar
        </button>
      </div>
      <div class="dialogo-docs-cuerpo">
        <div class="dialogo-docs-logo">
          <img class="logo-para-oscuro" src="img/logo.png" alt="reVERSEand0" width="320" height="72">
          <img class="logo-para-claro" src="img/logo-claro.png" alt="reVERSEand0" width="320" height="72">
        </div>
        <section>
          <h3>Qué hace</h3>
          <p>
            reVERSEand0 genera prompts de desarrollo en español a partir de un repositorio público
            de GitHub o de una página web (tema visual y distribución).
          </p>
        </section>

        <section>
          <h3>Cómo funciona</h3>
          <ol>
            <li>Elige el modo <strong>Repositorio</strong> o <strong>Página web</strong> y pega una URL (o un ejemplo).</li>
            <li>
              En modo repositorio consulta la API de GitHub (metadatos, lenguajes, README, árbol y configs).
              En modo web descarga HTML/CSS y, si hay Gemini, captura la página con Chrome/Chromium local.
            </li>
            <li>
              Con API key de Gemini se genera un prompt amplio; sin key se usa una plantilla local
              (en web, solo con HTML/CSS, sin captura).
            </li>
            <li>Copias el prompt y lo usas en tu herramienta de desarrollo o IA preferida.</li>
          </ol>
          <p>
            También puedes usar el <strong>control manual</strong>: título y descripción sin consultar GitHub ni la web.
          </p>
        </section>

        <section>
          <h3>Modo página web</h3>
          <p>
            Pensado para recrear tema y layout. Requiere <strong>Chrome o Chromium</strong> instalado en la máquina
            cuando uses Gemini (captura headless). Sitios muy dinámicos (SPA), con captcha o que bloqueen bots
            pueden dar resultados incompletos.
          </p>
        </section>

        <section>
          <h3>Configurar Gemini</h3>
          <ol>
            <li>Obtén una API key en <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">Google AI Studio</a>.</li>
            <li>Pulsa <strong>Configurar Gemini</strong>, pega la key, elige el modelo y guarda.</li>
            <li>La key se almacena solo en este servidor (<code>config/gemini.json</code>), pensado para uso local.</li>
          </ol>
          <p>
            Si el modelo elegido está saturado, la app puede probar automáticamente otros modelos gratuitos disponibles.
          </p>
        </section>

        <section>
          <h3>Limitaciones</h3>
          <ul>
            <li>Solo recursos <strong>públicos</strong>; no se clona código ni se eluden paywalls/login.</li>
            <li>No analiza todo el código fuente ni el DOM completo de SPAs renderizadas solo en cliente.</li>
            <li>GitHub y Gemini tienen límites de tasa; Chromium debe estar instalado para capturas.</li>
          </ul>
        </section>

        <section class="bloque-disclaimer">
          <h3>Aviso de responsabilidad</h3>
          <p>
            reVERSEand0 se ofrece «tal cual», sin garantías de ningún tipo. El software y los prompts
            generados son una ayuda orientativa: pueden contener inexactitudes, omisiones o inferencias
            incorrectas respecto al origen analizado.
          </p>
          <p>
            Tú eres el único responsable de revisar, validar y usar los resultados. Los autores y
            colaboradores no se responsabilizan de daños, pérdidas, infracciones de licencia, uso
            indebido de código o diseños de terceros ni de cualquier consecuencia derivada del uso de esta
            herramienta o de los prompts que produce.
          </p>
          <p>
            Respeta las licencias y marcas de los sitios/repositorios analizados y las condiciones de uso
            de GitHub y de Google Gemini. No publiques la aplicación en Internet sin proteger la configuración
            de la API key.
          </p>
        </section>

        <div class="dialogo-docs-accion">
          <a
            class="boton boton-primario"
            href="https://github.com/entreunosyceros/reVERSEando"
            target="_blank"
            rel="noopener noreferrer"
          >
            Ver en GitHub
          </a>
        </div>
      </div>
    </dialog>

    <dialog id="dialogo-gemini" class="dialogo-gemini" aria-labelledby="titulo-dialogo-gemini">
      <form id="formulario-gemini" method="dialog" class="formulario-gemini">
        <h2 id="titulo-dialogo-gemini">Configurar Gemini</h2>
        <p class="ayuda">
          La API key se guarda solo en este servidor (uso local).
          <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">
            Obtener API key en Google AI Studio
          </a>
        </p>

        <label class="ayuda" for="campo-api-key">API key de Gemini</label>
        <div class="fila-accion">
          <input
            id="campo-api-key"
            class="campo"
            type="password"
            name="api_key"
            autocomplete="off"
            placeholder="Pega tu API key (vacío = conservar)"
          >
          <button class="boton boton-secundario" type="button" id="boton-mostrar-key">
            Mostrar
          </button>
        </div>

        <label class="ayuda" for="campo-modelo">Modelo</label>
        <select id="campo-modelo" class="campo" name="modelo">
          <option value="gemini-3.5-flash-lite" selected>gemini-3.5-flash-lite (recomendado, gratis)</option>
          <option value="gemini-3.6-flash">gemini-3.6-flash</option>
          <option value="gemini-3.8-flash">gemini-3.8-flash</option>
        </select>
        <p class="ayuda">Si el modelo elegido está saturado, se probarán automáticamente los otros.</p>

        <p id="mensaje-config-gemini" class="mensaje-config-gemini" role="status" aria-live="polite"></p>

        <div class="fila-accion">
          <button class="boton boton-primario" type="submit" id="boton-guardar-gemini">
            Guardar
          </button>
          <button class="boton boton-secundario" type="button" id="boton-cancelar-gemini">
            Cancelar
          </button>
          <button class="boton boton-secundario" type="button" id="boton-quitar-key">
            Quitar API key
          </button>
        </div>
      </form>
    </dialog>

    <main>
      <section class="heroe" aria-labelledby="titulo-principal">
        <h1 id="titulo-principal" class="titulo-heroe">Del repositorio al prompt</h1>
        <p id="texto-heroe" class="texto-heroe">
          Analiza un repositorio público de GitHub y genera un prompt de desarrollo
          para reconstruir o extender un proyecto similar.
        </p>

        <div class="interruptor-modo" role="group" aria-label="Tipo de origen">
          <button type="button" class="boton-modo activo" id="boton-modo-repositorio" data-modo="repositorio">
            Repositorio
          </button>
          <button type="button" class="boton-modo" id="boton-modo-web" data-modo="web">
            Página web
          </button>
        </div>

        <form id="formulario-repositorio" class="formulario-principal" novalidate>
          <label class="ayuda" for="campo-url" id="etiqueta-url">URL del repositorio de GitHub</label>
          <div class="fila-accion">
            <input
              id="campo-url"
              class="campo"
              type="url"
              name="url"
              placeholder="https://github.com/propietario/repositorio"
              autocomplete="off"
              required
            >
            <button class="boton boton-primario" type="submit" id="boton-obtener">
              Obtener prompt
            </button>
          </div>
          <p class="ayuda" id="ayuda-url">
            También puedes usar el atajo <code>propietario/repositorio</code>.
          </p>
          <div class="fila-accion">
            <button class="boton boton-secundario" type="button" id="boton-control-manual">
              Control manual
            </button>
          </div>
        </form>

        <div class="zona-ejemplos">
          <p class="etiqueta-ejemplos">Ejemplos</p>
          <ul class="lista-ejemplos" id="lista-ejemplos"></ul>
        </div>

        <script type="application/json" id="datos-ejemplos"><?= json_encode([
            'repositorio' => $ejemplosRepositorio,
            'web' => $ejemplosWeb,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

        <section id="panel-manual" class="panel-manual" aria-label="Control manual">
          <h2>Control manual</h2>
          <p class="ayuda">
            Pega un título y una descripción de tu idea o base de código.
            No se consultará GitHub.
          </p>
          <form id="formulario-manual" class="formulario-principal" novalidate>
            <label class="ayuda" for="campo-titulo">Título del proyecto</label>
            <input id="campo-titulo" class="campo" type="text" name="titulo" placeholder="Mi aplicación">

            <label class="ayuda" for="campo-descripcion">Descripción o notas</label>
            <textarea
              id="campo-descripcion"
              class="campo campo-area"
              name="descripcion"
              placeholder="Describe el producto, el stack deseado y las funciones principales…"
            ></textarea>

            <button class="boton boton-primario" type="submit" id="boton-generar-manual">
              Generar prompt manual
            </button>
          </form>
        </section>

        <div id="barra-progreso" class="barra-progreso" hidden>
          <div class="barra-progreso-cabecera">
            <p id="estado-carga" class="estado-carga-texto" role="status" aria-live="polite">
              Preparando…
            </p>
            <span id="porcentaje-progreso" class="porcentaje-progreso" aria-hidden="true">0%</span>
          </div>
          <div
            class="barra-progreso-pista"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="0"
            aria-labelledby="estado-carga"
            id="pista-progreso"
          >
            <div id="relleno-progreso" class="barra-progreso-relleno"></div>
          </div>
        </div>
        <p id="mensaje-error" class="mensaje-error" role="alert"></p>
      </section>

      <section id="resultado" class="resultado" aria-live="polite">
        <div class="resultado-cabecera">
          <h2>Prompt generado</h2>
          <button class="boton boton-secundario" type="button" id="boton-copiar">
            Copiar prompt
          </button>
        </div>
        <textarea
          id="area-prompt"
          class="campo campo-area area-prompt"
          readonly
          aria-label="Prompt generado"
        ></textarea>
      </section>
    </main>

    <footer class="pie">
      <p>reVERSEand0 — del repositorio o la web al prompt. Solo orígenes públicos.</p>
    </footer>
  </div>

  <script src="assets/js/aplicacion.js" defer></script>
</body>
</html>
