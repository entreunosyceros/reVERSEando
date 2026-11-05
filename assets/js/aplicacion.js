/**
 * Lógica de interfaz para reVERSEand0:
 * modos repositorio/web, formularios, Gemini y copia del prompt.
 */

(function () {
  'use strict';

  const rutaApi = 'api/generar_prompt.php';
  const rutaConfigGemini = 'api/configuracion_gemini.php';

  const formularioRepositorio = document.getElementById('formulario-repositorio');
  const formularioManual = document.getElementById('formulario-manual');
  const formularioGemini = document.getElementById('formulario-gemini');
  const campoUrl = document.getElementById('campo-url');
  const campoTitulo = document.getElementById('campo-titulo');
  const campoDescripcion = document.getElementById('campo-descripcion');
  const campoApiKey = document.getElementById('campo-api-key');
  const campoModelo = document.getElementById('campo-modelo');
  const botonObtener = document.getElementById('boton-obtener');
  const botonControlManual = document.getElementById('boton-control-manual');
  const botonGenerarManual = document.getElementById('boton-generar-manual');
  const botonCopiar = document.getElementById('boton-copiar');
  const botonConfigurarGemini = document.getElementById('boton-configurar-gemini');
  const botonCancelarGemini = document.getElementById('boton-cancelar-gemini');
  const botonGuardarGemini = document.getElementById('boton-guardar-gemini');
  const botonMostrarKey = document.getElementById('boton-mostrar-key');
  const botonQuitarKey = document.getElementById('boton-quitar-key');
  const botonTema = document.getElementById('boton-tema');
  const botonLogoDocs = document.getElementById('boton-logo-docs');
  const botonCerrarDocs = document.getElementById('boton-cerrar-docs');
  const botonModoRepositorio = document.getElementById('boton-modo-repositorio');
  const botonModoWeb = document.getElementById('boton-modo-web');
  const CLAVE_TEMA = 'reverseando-tema';
  const panelManual = document.getElementById('panel-manual');
  const dialogoGemini = document.getElementById('dialogo-gemini');
  const dialogoDocumentacion = document.getElementById('dialogo-documentacion');
  const barraProgreso = document.getElementById('barra-progreso');
  const estadoCarga = document.getElementById('estado-carga');
  const porcentajeProgreso = document.getElementById('porcentaje-progreso');
  const pistaProgreso = document.getElementById('pista-progreso');
  const rellenoProgreso = document.getElementById('relleno-progreso');
  const estadoGemini = document.getElementById('estado-gemini');
  const mensajeError = document.getElementById('mensaje-error');
  const mensajeConfigGemini = document.getElementById('mensaje-config-gemini');
  const resultado = document.getElementById('resultado');
  const areaPrompt = document.getElementById('area-prompt');
  const fichaCalidad = document.getElementById('ficha-calidad');
  const listaCalidad = document.getElementById('lista-calidad');
  const panelRegenerar = document.getElementById('panel-regenerar');
  const listaSeccionesRegen = document.getElementById('lista-secciones-regen');
  const botonRegenerarSeleccion = document.getElementById('boton-regenerar-seleccion');
  const botonRegenTodas = document.getElementById('boton-regen-todas');
  const botonRegenNinguna = document.getElementById('boton-regen-ninguna');
  const listaEjemplos = document.getElementById('lista-ejemplos');
  const etiquetaUrl = document.getElementById('etiqueta-url');
  const ayudaUrl = document.getElementById('ayuda-url');
  const tituloPrincipal = document.getElementById('titulo-principal');
  const textoHeroe = document.getElementById('texto-heroe');
  const nodoEjemplos = document.getElementById('datos-ejemplos');

  const SECCIONES_DEFECTO = [
    '1. Objetivo del proyecto',
    '2. Funcionalidades',
    '3. Arquitectura propuesta',
    '4. Tecnologías',
    '5. Estructura de directorios',
    '6. Modelos de datos',
    '7. Interfaces y experiencia de usuario',
    '8. Seguridad',
    '9. Requisitos técnicos',
    '10. Plan de implementación',
    '11. Criterios de aceptación',
  ];

  /** @type {'repositorio'|'web'} */
  let modoEntrada = 'repositorio';
  /** @type {number[]} */
  let temporizadoresProgreso = [];
  let porcentajeActual = 0;

  /** @type {{modoOrigen: string, contexto: Record<string, unknown>|null}} */
  let estadoRegeneracion = {
    modoOrigen: 'repositorio',
    contexto: null,
  };

  /** @type {Record<'repositorio'|'web'|'manual'|'regenerar', Array<{enMs: number, pct: number, texto: string}>>} */
  const etapasProgreso = {
    repositorio: [
      { enMs: 0, pct: 8, texto: 'Validando URL…' },
      { enMs: 700, pct: 22, texto: 'Consultando GitHub…' },
      { enMs: 2200, pct: 45, texto: 'Leyendo metadatos y archivos…' },
      { enMs: 4200, pct: 68, texto: 'Preparando contexto…' },
      { enMs: 6500, pct: 82, texto: 'Generando prompt…' },
      { enMs: 11000, pct: 92, texto: 'Casi listo…' },
    ],
    web: [
      { enMs: 0, pct: 8, texto: 'Validando URL…' },
      { enMs: 600, pct: 20, texto: 'Obteniendo página…' },
      { enMs: 2000, pct: 42, texto: 'Analizando HTML y CSS…' },
      { enMs: 4000, pct: 62, texto: 'Capturando / preparando contexto…' },
      { enMs: 6500, pct: 80, texto: 'Generando prompt…' },
      { enMs: 12000, pct: 92, texto: 'Casi listo…' },
    ],
    manual: [
      { enMs: 0, pct: 12, texto: 'Preparando contexto…' },
      { enMs: 800, pct: 40, texto: 'Generando prompt…' },
      { enMs: 3500, pct: 75, texto: 'Refinando respuesta…' },
      { enMs: 7000, pct: 90, texto: 'Casi listo…' },
    ],
    regenerar: [
      { enMs: 0, pct: 15, texto: 'Preparando secciones…' },
      { enMs: 700, pct: 45, texto: 'Regenerando selección…' },
      { enMs: 3000, pct: 75, texto: 'Fusionando prompt…' },
      { enMs: 6500, pct: 90, texto: 'Casi listo…' },
    ],
  };

  /** @type {{repositorio: Array<{nombre: string, url: string}>, web: Array<{nombre: string, url: string}>}} */
  let ejemplosPorModo = { repositorio: [], web: [] };

  try {
    ejemplosPorModo = JSON.parse(nodoEjemplos ? nodoEjemplos.textContent || '{}' : '{}');
  } catch (error) {
    ejemplosPorModo = { repositorio: [], web: [] };
  }

  /** @type {{configurado: boolean, sufijo_key: string|null, modelo: string|null}} */
  let configGeminiActual = {
    configurado: false,
    sufijo_key: null,
    modelo: null,
  };

  function mostrarError(texto) {
    mensajeError.textContent = texto;
    mensajeError.classList.remove('aviso');
    mensajeError.classList.add('visible');
  }

  function ocultarError() {
    mensajeError.textContent = '';
    mensajeError.classList.remove('visible', 'aviso');
  }

  function mostrarMensajeConfig(texto, esError) {
    mensajeConfigGemini.textContent = texto;
    mensajeConfigGemini.classList.add('visible');
    mensajeConfigGemini.classList.toggle('error', Boolean(esError));
  }

  function ocultarMensajeConfig() {
    mensajeConfigGemini.textContent = '';
    mensajeConfigGemini.classList.remove('visible', 'error');
  }

  function pintarProgreso(pct, texto) {
    porcentajeActual = Math.max(0, Math.min(100, pct));
    if (texto) {
      estadoCarga.textContent = texto;
    }
    porcentajeProgreso.textContent = Math.round(porcentajeActual) + '%';
    rellenoProgreso.style.width = porcentajeActual + '%';
    pistaProgreso.setAttribute('aria-valuenow', String(Math.round(porcentajeActual)));
  }

  function detenerTemporizadoresProgreso() {
    temporizadoresProgreso.forEach(function (id) {
      clearTimeout(id);
    });
    temporizadoresProgreso = [];
  }

  /**
   * @param {'repositorio'|'web'|'manual'|'regenerar'} tipo
   */
  function iniciarProgreso(tipo) {
    detenerTemporizadoresProgreso();
    porcentajeActual = 0;
    const etapas = etapasProgreso[tipo] || etapasProgreso.repositorio;

    barraProgreso.hidden = false;
    barraProgreso.classList.add('visible');
    botonObtener.disabled = true;
    botonGenerarManual.disabled = true;
    if (botonRegenerarSeleccion) {
      botonRegenerarSeleccion.disabled = true;
    }

    etapas.forEach(function (etapa) {
      const id = window.setTimeout(function () {
        if (etapa.pct > porcentajeActual) {
          pintarProgreso(etapa.pct, etapa.texto);
        }
      }, etapa.enMs);
      temporizadoresProgreso.push(id);
    });

    pintarProgreso(etapas[0].pct, etapas[0].texto);
  }

  function ocultarProgreso() {
    detenerTemporizadoresProgreso();
    barraProgreso.classList.remove('visible');
    barraProgreso.hidden = true;
    pintarProgreso(0, 'Preparando…');
    botonObtener.disabled = false;
    botonGenerarManual.disabled = false;
    if (botonRegenerarSeleccion) {
      botonRegenerarSeleccion.disabled = false;
    }
  }

  function completarProgreso() {
    detenerTemporizadoresProgreso();
    pintarProgreso(100, 'Completado');
    return new Promise(function (resolve) {
      const id = window.setTimeout(resolve, 320);
      temporizadoresProgreso.push(id);
    });
  }

  function limpiarResultadoPrompt() {
    areaPrompt.value = '';
    resultado.classList.remove('visible');
    ocultarCalidadYRegeneracion();
    estadoRegeneracion = { modoOrigen: modoEntrada, contexto: null };
    ocultarProgreso();
    ocultarError();
  }

  function ocultarCalidadYRegeneracion() {
    if (fichaCalidad) {
      fichaCalidad.hidden = true;
    }
    if (listaCalidad) {
      listaCalidad.innerHTML = '';
    }
    if (panelRegenerar) {
      panelRegenerar.hidden = true;
    }
    if (listaSeccionesRegen) {
      listaSeccionesRegen.innerHTML = '';
    }
  }

  /**
   * @param {{items?: Array<{id?: string, etiqueta?: string, estado?: string, detalle?: string}>}|null|undefined} calidad
   */
  function renderizarCalidad(calidad) {
    if (!fichaCalidad || !listaCalidad) {
      return;
    }
    const items = calidad && Array.isArray(calidad.items) ? calidad.items : [];
    if (items.length === 0) {
      fichaCalidad.hidden = true;
      listaCalidad.innerHTML = '';
      return;
    }

    const etiquetasEstado = {
      ok: 'OK',
      parcial: 'Parcial',
      no: 'No',
      aviso: 'Aviso',
    };

    listaCalidad.innerHTML = '';
    items.forEach(function (item) {
      const estado = String(item.estado || 'aviso');
      const li = document.createElement('li');
      const badge = document.createElement('span');
      badge.className = 'calidad-estado';
      badge.setAttribute('data-estado', estado);
      badge.textContent = etiquetasEstado[estado] || estado;

      const etiqueta = document.createElement('span');
      etiqueta.className = 'calidad-etiqueta';
      etiqueta.textContent = String(item.etiqueta || item.id || 'Ítem');

      const detalle = document.createElement('span');
      detalle.className = 'calidad-detalle';
      detalle.textContent = String(item.detalle || '');

      li.appendChild(badge);
      li.appendChild(etiqueta);
      li.appendChild(detalle);
      listaCalidad.appendChild(li);
    });
    fichaCalidad.hidden = false;
  }

  /**
   * @param {string[]} secciones
   */
  function renderizarPanelRegenerar(secciones) {
    if (!panelRegenerar || !listaSeccionesRegen) {
      return;
    }
    const lista = Array.isArray(secciones) && secciones.length > 0 ? secciones : SECCIONES_DEFECTO;
    listaSeccionesRegen.innerHTML = '';
    lista.forEach(function (titulo, indice) {
      const id = 'regen-sec-' + indice;
      const label = document.createElement('label');
      label.setAttribute('for', id);
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.id = id;
      input.name = 'seccion-regen';
      input.value = titulo;
      const texto = document.createElement('span');
      texto.textContent = titulo;
      label.appendChild(input);
      label.appendChild(texto);
      listaSeccionesRegen.appendChild(label);
    });
    panelRegenerar.hidden = false;
  }

  function obtenerSeccionesSeleccionadas() {
    if (!listaSeccionesRegen) {
      return [];
    }
    return Array.from(listaSeccionesRegen.querySelectorAll('input[type="checkbox"]:checked')).map(
      function (nodo) {
        return String(nodo.value);
      }
    );
  }

  /**
   * @param {boolean} marcar
   */
  function marcarSeccionesRegen(marcar) {
    if (!listaSeccionesRegen) {
      return;
    }
    listaSeccionesRegen.querySelectorAll('input[type="checkbox"]').forEach(function (nodo) {
      nodo.checked = marcar;
    });
  }

  function seleccionarModelo(modelo) {
    if (!modelo) {
      return;
    }
    const existe = Array.from(campoModelo.options).some(function (opcion) {
      return opcion.value === modelo;
    });
    if (!existe) {
      const opcion = document.createElement('option');
      opcion.value = modelo;
      opcion.textContent = modelo;
      campoModelo.appendChild(opcion);
    }
    campoModelo.value = modelo;
  }

  function actualizarEstadoGemini(config) {
    configGeminiActual = {
      configurado: Boolean(config && config.configurado),
      sufijo_key: config && config.sufijo_key ? String(config.sufijo_key) : null,
      modelo: config && config.modelo ? String(config.modelo) : null,
    };

    if (configGeminiActual.configurado) {
      const sufijo = configGeminiActual.sufijo_key ? ` ···${configGeminiActual.sufijo_key}` : '';
      const modelo = configGeminiActual.modelo ? ` (${configGeminiActual.modelo})` : '';
      estadoGemini.textContent = `Gemini configurado${sufijo}${modelo}`;
    } else {
      estadoGemini.textContent = 'Usando plantilla local';
    }
  }

  function obtenerTemaActual() {
    const actual = document.documentElement.getAttribute('data-tema');
    return actual === 'claro' ? 'claro' : 'oscuro';
  }

  function sincronizarBotonTema(tema) {
    if (!botonTema) {
      return;
    }
    const esOscuro = tema === 'oscuro';
    botonTema.setAttribute('aria-pressed', esOscuro ? 'true' : 'false');
    botonTema.setAttribute(
      'aria-label',
      esOscuro ? 'Activar tema claro' : 'Activar tema oscuro'
    );
  }

  function aplicarTema(tema) {
    const siguiente = tema === 'claro' ? 'claro' : 'oscuro';
    document.documentElement.setAttribute('data-tema', siguiente);
    try {
      localStorage.setItem(CLAVE_TEMA, siguiente);
    } catch (error) {
      // Ignorar si el almacenamiento no está disponible.
    }
    sincronizarBotonTema(siguiente);
  }

  function alternarTema() {
    aplicarTema(obtenerTemaActual() === 'oscuro' ? 'claro' : 'oscuro');
  }

  /**
   * @param {string} prompt
   * @param {string} [aviso]
   * @param {{
   *   calidadAnalisis?: {items?: Array<{id?: string, etiqueta?: string, estado?: string, detalle?: string}>},
   *   secciones?: string[],
   *   contextoRegeneracion?: Record<string, unknown>|null,
   *   modoOrigen?: string,
   *   conservarCalidad?: boolean
   * }} [extra]
   */
  function mostrarPrompt(prompt, aviso, extra) {
    areaPrompt.value = prompt;
    resultado.classList.add('visible');

    const opts = extra || {};
    if (!opts.conservarCalidad) {
      renderizarCalidad(opts.calidadAnalisis || null);
      if (opts.contextoRegeneracion) {
        estadoRegeneracion = {
          modoOrigen: String(opts.modoOrigen || opts.contextoRegeneracion.modo || modoEntrada),
          contexto: opts.contextoRegeneracion,
        };
        renderizarPanelRegenerar(opts.secciones || SECCIONES_DEFECTO);
      } else if (opts.secciones) {
        renderizarPanelRegenerar(opts.secciones);
      }
    }

    resultado.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (aviso) {
      mensajeError.textContent = String(aviso);
      mensajeError.classList.add('visible', 'aviso');
    } else {
      mensajeError.textContent = '';
      mensajeError.classList.remove('visible', 'aviso');
    }
  }

  function renderizarEjemplos() {
    const lista = ejemplosPorModo[modoEntrada] || [];
    listaEjemplos.innerHTML = '';
    lista.forEach(function (ejemplo) {
      const li = document.createElement('li');
      const boton = document.createElement('button');
      boton.type = 'button';
      boton.className = 'enlace-ejemplo';
      boton.setAttribute('data-url', ejemplo.url);
      boton.textContent = ejemplo.nombre;
      li.appendChild(boton);
      listaEjemplos.appendChild(li);
    });
  }

  /**
   * @param {'repositorio'|'web'} modo
   * @param {{inicial?: boolean}} [opciones]
   */
  function establecerModoEntrada(modo, opciones) {
    const siguiente = modo === 'web' ? 'web' : 'repositorio';
    const cambio = siguiente !== modoEntrada;
    modoEntrada = siguiente;
    botonModoRepositorio.classList.toggle('activo', modoEntrada === 'repositorio');
    botonModoWeb.classList.toggle('activo', modoEntrada === 'web');
    botonModoRepositorio.setAttribute('aria-pressed', modoEntrada === 'repositorio' ? 'true' : 'false');
    botonModoWeb.setAttribute('aria-pressed', modoEntrada === 'web' ? 'true' : 'false');

    if (modoEntrada === 'web') {
      tituloPrincipal.textContent = 'De la web al prompt';
      textoHeroe.textContent =
        'Analiza una página pública (HTML, CSS y captura) y genera un prompt para recrear su tema y distribución.';
      etiquetaUrl.textContent = 'URL de la página web';
      campoUrl.placeholder = 'https://ejemplo.com';
      ayudaUrl.innerHTML =
        'Se analizará el HTML/CSS y, con Gemini, una captura local con Chrome/Chromium.';
    } else {
      tituloPrincipal.textContent = 'Del repositorio al prompt';
      textoHeroe.textContent =
        'Analiza un repositorio público de GitHub y genera un prompt de desarrollo para reconstruir o extender un proyecto similar.';
      etiquetaUrl.textContent = 'URL del repositorio de GitHub';
      campoUrl.placeholder = 'https://github.com/propietario/repositorio';
      ayudaUrl.innerHTML =
        'También puedes usar el atajo <code>propietario/repositorio</code>.';
    }

    campoUrl.value = '';
    renderizarEjemplos();

    if (cambio || (opciones && opciones.inicial)) {
      limpiarResultadoPrompt();
    }
  }

  async function solicitarGeneracion(carga) {
    const respuesta = await fetch(rutaApi, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(carga),
    });

    let datos;
    try {
      datos = await respuesta.json();
    } catch (error) {
      throw new Error('La respuesta del servidor no es válida.');
    }

    if (!respuesta.ok || !datos.exito) {
      throw new Error(datos.error || 'No se pudo generar el prompt.');
    }

    return datos;
  }

  async function cargarConfiguracionGemini() {
    try {
      const respuesta = await fetch(rutaConfigGemini, {
        method: 'GET',
        headers: { Accept: 'application/json' },
      });
      const datos = await respuesta.json();
      if (!respuesta.ok || !datos.exito) {
        throw new Error(datos.error || 'No se pudo cargar la configuración.');
      }
      actualizarEstadoGemini(datos.configuracion || {});
      if (datos.configuracion && datos.configuracion.modelo) {
        seleccionarModelo(datos.configuracion.modelo);
      }
    } catch (error) {
      estadoGemini.textContent = 'Usando plantilla local';
    }
  }

  async function guardarConfiguracionGemini(opciones) {
    const limpiarApiKey = Boolean(opciones && opciones.limpiarApiKey);
    ocultarMensajeConfig();
    botonGuardarGemini.disabled = true;
    botonQuitarKey.disabled = true;

    try {
      const carga = {
        modelo: campoModelo.value.trim() || 'gemini-3.5-flash-lite',
        limpiar_api_key: limpiarApiKey,
      };

      if (!limpiarApiKey) {
        carga.api_key = campoApiKey.value.trim();
      }

      const respuesta = await fetch(rutaConfigGemini, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(carga),
      });

      let datos;
      try {
        datos = await respuesta.json();
      } catch (error) {
        throw new Error('La respuesta del servidor no es válida.');
      }

      if (!respuesta.ok || !datos.exito) {
        throw new Error(datos.error || 'No se pudo guardar la configuración.');
      }

      actualizarEstadoGemini(datos.configuracion || {});
      mostrarMensajeConfig(
        limpiarApiKey
          ? 'API key eliminada. Se usará la plantilla local.'
          : (datos.mensaje || 'Configuración guardada.')
      );
      campoApiKey.value = '';

      window.setTimeout(function () {
        if (typeof dialogoGemini.close === 'function') {
          dialogoGemini.close();
        }
        ocultarMensajeConfig();
      }, 700);
    } catch (error) {
      mostrarMensajeConfig(
        error instanceof Error ? error.message : 'Error al guardar.',
        true
      );
    } finally {
      botonGuardarGemini.disabled = false;
      botonQuitarKey.disabled = false;
    }
  }

  async function generarDesdeUrl(url) {
    ocultarError();
    resultado.classList.remove('visible');
    areaPrompt.value = '';
    ocultarCalidadYRegeneracion();
    iniciarProgreso(modoEntrada);

    try {
      const datos = await solicitarGeneracion({
        modo: modoEntrada,
        url: url.trim(),
      });
      await completarProgreso();
      ocultarProgreso();
      mostrarPrompt(datos.prompt || '', datos.aviso || '', {
        calidadAnalisis: datos.calidadAnalisis,
        secciones: datos.secciones,
        contextoRegeneracion: datos.contextoRegeneracion || null,
        modoOrigen: datos.modo || modoEntrada,
      });
    } catch (error) {
      ocultarProgreso();
      mostrarError(error instanceof Error ? error.message : 'Error desconocido.');
    }
  }

  async function generarDesdeManual(titulo, descripcion) {
    ocultarError();
    resultado.classList.remove('visible');
    areaPrompt.value = '';
    ocultarCalidadYRegeneracion();
    iniciarProgreso('manual');

    try {
      const datos = await solicitarGeneracion({
        modo: 'manual',
        titulo: titulo.trim(),
        descripcion: descripcion.trim(),
      });
      await completarProgreso();
      ocultarProgreso();
      mostrarPrompt(datos.prompt || '', datos.aviso || '', {
        calidadAnalisis: datos.calidadAnalisis,
        secciones: datos.secciones,
        contextoRegeneracion: datos.contextoRegeneracion || null,
        modoOrigen: 'manual',
      });
    } catch (error) {
      ocultarProgreso();
      mostrarError(error instanceof Error ? error.message : 'Error desconocido.');
    }
  }

  async function regenerarSeleccion() {
    const secciones = obtenerSeccionesSeleccionadas();
    if (secciones.length === 0) {
      mostrarError('Selecciona al menos una sección para regenerar.');
      return;
    }
    const promptActual = areaPrompt.value.trim();
    if (!promptActual) {
      mostrarError('No hay un prompt generado para regenerar.');
      return;
    }
    if (!estadoRegeneracion.contexto) {
      muestraErrorContextoRegen();
      return;
    }

    ocultarError();
    iniciarProgreso('regenerar');

    try {
      const datos = await solicitarGeneracion({
        modo: 'regenerar',
        promptActual: promptActual,
        secciones: secciones,
        modoOrigen: estadoRegeneracion.modoOrigen,
        contexto: estadoRegeneracion.contexto,
      });
      await completarProgreso();
      ocultarProgreso();
      mostrarPrompt(datos.prompt || promptActual, datos.aviso || '', {
        conservarCalidad: true,
      });
    } catch (error) {
      ocultarProgreso();
      mostrarError(error instanceof Error ? error.message : 'Error desconocido.');
    }
  }

  function muestraErrorContextoRegen() {
    mostrarError(
      'Falta el contexto del análisis. Genera de nuevo el prompt antes de regenerar secciones.'
    );
  }

  async function copiarPrompt() {
    const texto = areaPrompt.value;
    if (!texto) {
      return;
    }

    try {
      await navigator.clipboard.writeText(texto);
      const etiquetaOriginal = botonCopiar.textContent;
      botonCopiar.textContent = 'Copiado';
      window.setTimeout(() => {
        botonCopiar.textContent = etiquetaOriginal;
      }, 1600);
    } catch (error) {
      areaPrompt.focus();
      areaPrompt.select();
      mostrarError('No se pudo copiar automáticamente. Selecciona el texto y cópialo manualmente.');
    }
  }

  function alternarControlManual() {
    panelManual.classList.toggle('visible');
    if (panelManual.classList.contains('visible')) {
      campoTitulo.focus();
    }
  }

  function abrirDialogoGemini() {
    ocultarMensajeConfig();
    campoApiKey.value = '';
    campoApiKey.type = 'password';
    botonMostrarKey.textContent = 'Mostrar';

    if (configGeminiActual.configurado) {
      const sufijo = configGeminiActual.sufijo_key || '****';
      campoApiKey.placeholder = `Key guardada (···${sufijo}) — vacío = conservar`;
    } else {
      campoApiKey.placeholder = 'Pega tu API key';
    }

    if (configGeminiActual.modelo) {
      seleccionarModelo(configGeminiActual.modelo);
    }

    if (typeof dialogoGemini.showModal === 'function') {
      dialogoGemini.showModal();
    }
    if (!configGeminiActual.configurado) {
      campoApiKey.focus();
    } else {
      campoModelo.focus();
    }
  }

  function cerrarDialogoGemini() {
    if (typeof dialogoGemini.close === 'function') {
      dialogoGemini.close();
    }
    ocultarMensajeConfig();
  }

  function abrirDialogoDocumentacion() {
    if (typeof dialogoDocumentacion.showModal === 'function') {
      dialogoDocumentacion.showModal();
    }
    botonCerrarDocs.focus();
  }

  function cerrarDialogoDocumentacion() {
    if (typeof dialogoDocumentacion.close === 'function') {
      dialogoDocumentacion.close();
    }
  }

  formularioRepositorio.addEventListener('submit', function (evento) {
    evento.preventDefault();
    const url = campoUrl.value.trim();
    if (!url) {
      mostrarError(
        modoEntrada === 'web'
          ? 'Pega una URL de página web o elige un ejemplo.'
          : 'Pega una URL de GitHub o elige un ejemplo.'
      );
      campoUrl.focus();
      return;
    }
    generarDesdeUrl(url);
  });

  formularioManual.addEventListener('submit', function (evento) {
    evento.preventDefault();
    generarDesdeManual(campoTitulo.value, campoDescripcion.value);
  });

  formularioGemini.addEventListener('submit', function (evento) {
    evento.preventDefault();
    guardarConfiguracionGemini();
  });

  botonModoRepositorio.addEventListener('click', function () {
    establecerModoEntrada('repositorio');
  });
  botonModoWeb.addEventListener('click', function () {
    establecerModoEntrada('web');
  });

  botonControlManual.addEventListener('click', alternarControlManual);
  botonCopiar.addEventListener('click', copiarPrompt);
  botonConfigurarGemini.addEventListener('click', abrirDialogoGemini);
  botonCancelarGemini.addEventListener('click', cerrarDialogoGemini);
  botonLogoDocs.addEventListener('click', abrirDialogoDocumentacion);
  botonCerrarDocs.addEventListener('click', cerrarDialogoDocumentacion);

  if (botonRegenerarSeleccion) {
    botonRegenerarSeleccion.addEventListener('click', regenerarSeleccion);
  }
  if (botonRegenTodas) {
    botonRegenTodas.addEventListener('click', function () {
      marcarSeccionesRegen(true);
    });
  }
  if (botonRegenNinguna) {
    botonRegenNinguna.addEventListener('click', function () {
      marcarSeccionesRegen(false);
    });
  }

  dialogoDocumentacion.addEventListener('click', function (evento) {
    if (evento.target === dialogoDocumentacion) {
      cerrarDialogoDocumentacion();
    }
  });

  botonMostrarKey.addEventListener('click', function () {
    const visible = campoApiKey.type === 'text';
    campoApiKey.type = visible ? 'password' : 'text';
    botonMostrarKey.textContent = visible ? 'Mostrar' : 'Ocultar';
  });

  botonQuitarKey.addEventListener('click', function () {
    guardarConfiguracionGemini({ limpiarApiKey: true });
  });

  listaEjemplos.addEventListener('click', function (evento) {
    const objetivo = evento.target;
    if (!(objetivo instanceof HTMLElement)) {
      return;
    }
    const boton = objetivo.closest('.enlace-ejemplo');
    if (!(boton instanceof HTMLElement)) {
      return;
    }
    const url = boton.getAttribute('data-url');
    if (!url) {
      return;
    }
    campoUrl.value = url;
    campoUrl.focus();
  });

  if (botonTema) {
    botonTema.addEventListener('click', alternarTema);
    sincronizarBotonTema(obtenerTemaActual());
  }

  establecerModoEntrada('repositorio', { inicial: true });
  cargarConfiguracionGemini();
})();
