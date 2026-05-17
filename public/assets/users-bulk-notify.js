/**
 * Envío masivo de credenciales — lista de usuarios (modules/users/list.php)
 */
(function () {
  'use strict';

  var cfg = window.USERS_BULK_NOTIFY_CONFIG || {};
  var batchUrl = cfg.batchUrl || '';
  var table = document.getElementById('users-list-table');
  if (!table || !batchUrl) {
    return;
  }

  var bar = document.getElementById('users-bulk-bar');
  var countEl = document.getElementById('users-bulk-count');
  var masterCb = document.getElementById('users-bulk-select-all');
  var progressOverlay = document.getElementById('users-bulk-progress');
  var progressText = document.getElementById('users-bulk-progress-text');
  var progressFill = document.getElementById('users-bulk-progress-fill');
  var btnWa = document.getElementById('users-bulk-btn-whatsapp');
  var btnTg = document.getElementById('users-bulk-btn-telegram');
  var btnWeb = document.getElementById('users-bulk-btn-web');
  var btnClear = document.getElementById('users-bulk-btn-clear');

  function rowCheckboxes() {
    return table.querySelectorAll('tbody .users-row-checkbox');
  }

  function selectedIds() {
    var ids = [];
    rowCheckboxes().forEach(function (cb) {
      if (cb.checked) {
        ids.push(parseInt(cb.value, 10));
      }
    });
    return ids.filter(function (id) {
      return id > 0;
    });
  }

  function updateBar() {
    var ids = selectedIds();
    var n = ids.length;
    if (countEl) {
      countEl.textContent = n === 1 ? '1 usuario seleccionado' : n + ' usuarios seleccionados';
    }
    if (bar) {
      bar.classList.toggle('is-visible', n > 0);
    }
    document.body.classList.toggle('users-bulk-bar-active', n > 0);
    if (masterCb) {
      var boxes = rowCheckboxes();
      var checked = 0;
      boxes.forEach(function (cb) {
        if (cb.checked) checked++;
      });
      masterCb.checked = boxes.length > 0 && checked === boxes.length;
      masterCb.indeterminate = checked > 0 && checked < boxes.length;
    }
  }

  function setBusy(busy) {
    [btnWa, btnTg, btnWeb, btnClear].forEach(function (btn) {
      if (btn) btn.disabled = !!busy;
    });
  }

  function showProgress(show, text, pct) {
    if (!progressOverlay) return;
    progressOverlay.classList.toggle('is-active', !!show);
    if (progressText && text !== undefined) {
      progressText.textContent = text;
    }
    if (progressFill && pct !== undefined) {
      progressFill.style.width = Math.min(100, Math.max(0, pct)) + '%';
    }
  }

  function postBatch(userIds, canal) {
    var body = new URLSearchParams();
    userIds.forEach(function (id) {
      body.append('user_ids[]', String(id));
    });
    body.append('canal', canal);

    return fetch(batchUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    }).then(function (res) {
      return res.json().then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  function summarizeResult(data) {
    var ok = data.succeeded || 0;
    var fail = data.failed || 0;
    var msg = 'Procesados: ' + (data.processed || 0) + '. Éxitos: ' + ok + ', fallos: ' + fail + '.';
    if (fail > 0 && Array.isArray(data.results)) {
      var errs = data.results
        .filter(function (r) {
          return !r.ok;
        })
        .slice(0, 3)
        .map(function (r) {
          return (r.username || '#' + r.user_id) + ': ' + (r.error || 'error');
        });
      if (errs.length) {
        msg += '\n' + errs.join('\n');
      }
    }
    return msg;
  }

  function openWhatsAppQueue(queue) {
    if (!queue || !queue.length) {
      alert('Ningún usuario del lote tiene celular válido para WhatsApp.');
      return;
    }

    var modalEl = document.getElementById('users-wa-queue-modal');
    var listEl = document.getElementById('users-wa-queue-list');
    var counterEl = document.getElementById('users-wa-queue-counter');
    var btnNext = document.getElementById('users-wa-queue-next');
    var btnOpenAll = document.getElementById('users-wa-queue-open-manual');
    if (!modalEl || !listEl) {
      if (queue[0] && queue[0].redirect) {
        window.open(queue[0].redirect, '_blank', 'noopener,noreferrer');
      }
      return;
    }

    var index = 0;
    listEl.innerHTML = '';

    queue.forEach(function (item, i) {
      var li = document.createElement('li');
      li.className = 'list-group-item d-flex justify-content-between align-items-center';
      li.dataset.index = String(i);
      li.innerHTML =
        '<span><strong>' +
        escapeHtml(item.nombre || item.username) +
        '</strong> <small class="text-muted">@' +
        escapeHtml(item.username) +
        '</small></span>' +
        '<span class="wa-queue-status badge bg-secondary">Pendiente</span>';
      listEl.appendChild(li);
    });

    function refreshCounter() {
      if (counterEl) {
        counterEl.textContent = index + 1 + ' / ' + queue.length;
      }
    }

    function markDone(i, state) {
      var row = listEl.querySelector('[data-index="' + i + '"]');
      if (!row) return;
      var badge = row.querySelector('.wa-queue-status');
      if (!badge) return;
      if (state === 'done') {
        badge.className = 'wa-queue-status badge bg-success';
        badge.textContent = 'Abierto';
      } else if (state === 'skip') {
        badge.className = 'wa-queue-status badge bg-warning text-dark';
        badge.textContent = 'Omitido';
      }
    }

    function openCurrent() {
      if (index >= queue.length) {
        if (btnNext) {
          btnNext.disabled = true;
          btnNext.textContent = 'Cola completada';
        }
        return;
      }
      var item = queue[index];
      refreshCounter();
      if (item.redirect) {
        window.open(item.redirect, '_blank', 'noopener,noreferrer');
        markDone(index, 'done');
      } else {
        markDone(index, 'skip');
      }
      index++;
      refreshCounter();
      if (index >= queue.length && btnNext) {
        btnNext.disabled = true;
        btnNext.textContent = 'Cola completada';
      }
    }

    if (btnNext) {
      btnNext.disabled = false;
      btnNext.textContent = 'Abrir siguiente WhatsApp';
      btnNext.onclick = openCurrent;
    }

    if (btnOpenAll) {
      btnOpenAll.onclick = function () {
        if (
          !confirm(
            'Se abrirán ' +
              queue.length +
              ' pestañas de WhatsApp. El navegador puede bloquearlas. ¿Continuar?'
          )
        ) {
          return;
        }
        queue.forEach(function (item, i) {
          setTimeout(function () {
            if (item.redirect) {
              window.open(item.redirect, '_blank', 'noopener,noreferrer');
              markDone(i, 'done');
            }
          }, i * 1200);
        });
        index = queue.length;
        refreshCounter();
        if (btnNext) {
          btnNext.disabled = true;
          btnNext.textContent = 'Cola completada';
        }
      };
    }

    refreshCounter();
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  function runBatch(canal) {
    var ids = selectedIds();
    if (!ids.length) {
      alert('Seleccione al menos un usuario.');
      return;
    }

    var labels = {
      whatsapp: 'WhatsApp',
      telegram: 'Telegram',
      web: 'notificación web',
    };
    if (
      !confirm(
        '¿Enviar credenciales por ' +
          (labels[canal] || canal) +
          ' a ' +
          ids.length +
          ' usuario(s)? Se generará un enlace de cambio de clave para cada uno.'
      )
    ) {
      return;
    }

    setBusy(true);
    if (canal !== 'whatsapp') {
      showProgress(true, 'Procesando lote…', 10);
    }

    postBatch(ids, canal)
      .then(function (wrap) {
        var data = wrap.data || {};
        if (canal === 'whatsapp') {
          showProgress(false);
          if (data.whatsapp_queue && data.whatsapp_queue.length) {
            openWhatsAppQueue(data.whatsapp_queue);
          }
          alert(summarizeResult(data));
        } else {
          showProgress(true, 'Finalizando…', 100);
          setTimeout(function () {
            showProgress(false);
            alert(summarizeResult(data));
          }, 400);
        }
        if ((data.succeeded || 0) > 0) {
          rowCheckboxes().forEach(function (cb) {
            cb.checked = false;
          });
          updateBar();
        }
      })
      .catch(function () {
        showProgress(false);
        alert('Error de red al procesar el lote. Intente de nuevo.');
      })
      .finally(function () {
        setBusy(false);
      });
  }

  rowCheckboxes().forEach(function (cb) {
    cb.addEventListener('change', updateBar);
  });

  if (masterCb) {
    masterCb.addEventListener('change', function () {
      var checked = masterCb.checked;
      rowCheckboxes().forEach(function (cb) {
        cb.checked = checked;
      });
      updateBar();
    });
  }

  if (btnWa) btnWa.addEventListener('click', function () {
    runBatch('whatsapp');
  });
  if (btnTg) btnTg.addEventListener('click', function () {
    runBatch('telegram');
  });
  if (btnWeb) btnWeb.addEventListener('click', function () {
    runBatch('web');
  });
  if (btnClear) {
    btnClear.addEventListener('click', function () {
      rowCheckboxes().forEach(function (cb) {
        cb.checked = false;
      });
      updateBar();
    });
  }

  updateBar();
})();
