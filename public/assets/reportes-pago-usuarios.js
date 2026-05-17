(function () {
  'use strict';

  const cfg = window.REPORTES_PAGO_CFG || {};
  const apiUrl = cfg.apiUrl || '';
  const csrf = cfg.csrf || '';

  function postForm(data) {
    const body = new FormData();
    Object.keys(data).forEach(function (k) {
      body.append(k, data[k]);
    });
    body.append('csrf_token', csrf);
    return fetch(apiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (text) {
          var trimmed = (text || '').trim();
          if (!trimmed || trimmed.charAt(0) === '<') {
            throw new Error('Respuesta no válida del servidor');
          }
          return JSON.parse(trimmed);
        });
      });
  }

  function showReciboModal(html, autoPrint) {
    var body = document.getElementById('modalReciboBody');
    var modalEl = document.getElementById('modalRecibo');
    if (!body || !modalEl || typeof bootstrap === 'undefined') {
      return;
    }
    body.innerHTML = html;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    if (autoPrint) {
      modalEl.addEventListener('shown.bs.modal', function onShown() {
        modalEl.removeEventListener('shown.bs.modal', onShown);
        setTimeout(function () {
          window.print();
        }, 400);
      });
    }
  }

  function toast(msg, ok) {
    if (typeof window.showToast === 'function') {
      window.showToast(msg, ok ? 'success' : 'danger');
      return;
    }
    alert(msg);
  }

  document.querySelectorAll('.rpu-switch-confirmado').forEach(function (el) {
    el.addEventListener('change', function () {
      var reporteId = el.getAttribute('data-reporte-id');
      var confirmado = el.checked ? '1' : '0';
      var prev = el.checked;
      el.disabled = true;
      postForm({
        accion: 'toggle_confirmado',
        reporte_id: reporteId,
        confirmado: confirmado,
        notificar: '1',
      })
        .then(function (res) {
          if (!res.ok) {
            el.checked = !prev;
            toast(res.message || 'Error', false);
            return;
          }
          toast(res.message || 'Actualizado', true);
          var row = el.closest('tr');
          if (row) {
            var badge = row.querySelector('.rpu-estatus-badge');
            if (badge) {
              if (el.checked) {
                badge.className = 'badge bg-success rpu-estatus-badge';
                badge.textContent = 'Confirmado';
              } else {
                badge.className = 'badge bg-warning text-dark rpu-estatus-badge';
                badge.textContent = 'Pendiente';
              }
            }
          }
          if (el.checked && res.recibo_html) {
            showReciboModal(res.recibo_html, true);
          }
        })
        .catch(function (err) {
          el.checked = !prev;
          toast(err.message || 'Error de red', false);
        })
        .finally(function () {
          el.disabled = false;
        });
    });
  });

  document.querySelectorAll('[data-rpu-accion]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var accion = btn.getAttribute('data-rpu-accion');
      var reporteId = btn.getAttribute('data-reporte-id');
      if (accion === 'ver') {
        postForm({ accion: 'ver_recibo', reporte_id: reporteId })
          .then(function (res) {
            if (!res.ok) {
              toast(res.message || 'No se pudo cargar', false);
              return;
            }
            showReciboModal(res.recibo_html, false);
          })
          .catch(function (err) {
            toast(err.message || 'Error', false);
          });
        return;
      }
      if (accion === 'imprimir') {
        postForm({ accion: 'ver_recibo', reporte_id: reporteId })
          .then(function (res) {
            if (!res.ok) {
              toast(res.message || 'No se pudo cargar', false);
              return;
            }
            showReciboModal(res.recibo_html, true);
          })
          .catch(function (err) {
            toast(err.message || 'Error', false);
          });
        return;
      }
      if (accion === 'notificar') {
        var canal = btn.getAttribute('data-canal') || 'ambos';
        btn.disabled = true;
        postForm({ accion: 'notificar', reporte_id: reporteId, canal: canal })
          .then(function (res) {
            toast(res.message || (res.ok ? 'Enviado' : 'Error'), res.ok);
            if (res.ok && res.whatsapp_url) {
              window.open(res.whatsapp_url, '_blank', 'noopener');
            }
          })
          .catch(function (err) {
            toast(err.message || 'Error', false);
          })
          .finally(function () {
            btn.disabled = false;
          });
      }
    });
  });

  var btnPrintModal = document.getElementById('btnReciboImprimir');
  if (btnPrintModal) {
    btnPrintModal.addEventListener('click', function () {
      window.print();
    });
  }
})();
