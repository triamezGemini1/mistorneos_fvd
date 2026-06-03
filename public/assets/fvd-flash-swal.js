/**
 * Muestra mensajes flash (window.FVD_FLASH o #app-flash-messages) con SweetAlert2.
 */
(function () {
  'use strict';

  var ORDER = ['error', 'warning', 'success', 'info'];

  var TITLES = {
    success: 'Operación exitosa',
    error: 'Error',
    warning: 'Atención',
    info: 'Información'
  };

  function confirmColor() {
    try {
      var v = getComputedStyle(document.documentElement)
        .getPropertyValue('--fvd-swal-confirm')
        .trim();
      return v || '#0f172a';
    } catch (e) {
      return '#0f172a';
    }
  }

  function showOne(type, text) {
    if (!text || typeof Swal === 'undefined') {
      return Promise.resolve();
    }
    return Swal.fire({
      icon: type === 'error' ? 'error' : type,
      title: TITLES[type] || 'Aviso',
      text: String(text),
      confirmButtonText: 'Aceptar',
      confirmButtonColor: confirmColor(),
      allowOutsideClick: true
    });
  }

  function filterFlashForPage(flash) {
    if (!flash || typeof flash !== 'object') {
      return flash;
    }
    if (document.body && document.body.classList.contains('page-registrar-resultados')) {
      delete flash.success;
    }
    return flash;
  }

  function showFromObject(flash) {
    flash = filterFlashForPage(flash);
    if (!flash || typeof flash !== 'object') {
      return Promise.resolve();
    }
    var chain = Promise.resolve();
    ORDER.forEach(function (type) {
      if (!flash[type]) {
        return;
      }
      var msg = flash[type];
      chain = chain.then(function () {
        return showOne(type, msg);
      });
    });
    return chain;
  }

  /** Compatibilidad: alertas Bootstrap en #app-flash-messages */
  function showFromDom() {
    var container = document.getElementById('app-flash-messages');
    if (!container) {
      return Promise.resolve();
    }
    var items = container.querySelectorAll('.alert');
    if (!items.length) {
      return Promise.resolve();
    }
    var flash = {};
    if (document.body && document.body.classList.contains('page-registrar-resultados')) {
      items.forEach(function (el) {
        el.remove();
      });
      return Promise.resolve();
    }
    items.forEach(function (el) {
      var text = (el.textContent || '').replace(/\s*×\s*$/u, '').trim();
      if (!text) {
        return;
      }
      if (el.classList.contains('alert-danger')) {
        flash.error = text;
      } else if (el.classList.contains('alert-warning')) {
        flash.warning = text;
      } else if (el.classList.contains('alert-info')) {
        flash.info = text;
      } else {
        flash.success = text;
      }
      el.remove();
    });
    return showFromObject(flash);
  }

  function run() {
    var pending = Promise.resolve();
    if (window.FVD_FLASH) {
      var data = window.FVD_FLASH;
      delete window.FVD_FLASH;
      pending = showFromObject(data);
    }
    pending.then(showFromDom);
  }

  function start() {
    if (typeof Swal === 'undefined') {
      setTimeout(start, 50);
      return;
    }
    run();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
