(function () {
    function initRegistrantsInscripciones() {
        const cfg = window.REGISTRANTS_INSC_CFG || {};
        const apiUrl = cfg.apiUrl || 'api/inscripcion_admin.php';
        const csrf = cfg.csrf || '';

        function postForm(data) {
            if (!csrf) {
                return Promise.reject(new Error('Sesión expirada o token CSRF ausente. Recargue la página.'));
            }
            const body = new URLSearchParams();
            Object.keys(data).forEach((k) => body.append(k, data[k]));
            return fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: body.toString(),
            }).then(async (r) => {
                const ct = r.headers.get('Content-Type') || '';
                if (!r.ok) {
                    const t = await r.text();
                    const msg = t.includes('Not Found') || r.status === 404
                        ? 'Servicio no encontrado (404). Verifique que exista public/api/inscripcion_admin.php'
                        : (t.replace(/<[^>]+>/g, '').trim() || 'Error ' + r.status);
                    throw new Error(msg);
                }
                if (ct.indexOf('application/json') === -1) {
                    const t = await r.text();
                    throw new Error(t.replace(/<[^>]+>/g, '').trim() || 'Respuesta no válida del servidor');
                }
                return r.json();
            });
        }

        function confirmacionDoble(mensaje1, mensaje2) {
            if (!confirm(mensaje1)) {
                return false;
            }
            return confirm(mensaje2);
        }

        const filterForm = document.getElementById('registrantsFilterForm');
        if (filterForm) {
            const submitFiltro = () => filterForm.submit();
            filterForm.querySelectorAll('.js-filtro-estatus-insc').forEach((radio) => {
                radio.addEventListener('change', function () {
                    if (this.checked) submitFiltro();
                });
            });
            filterForm.querySelectorAll('.registrants-filtro-estatus label.btn').forEach((lbl) => {
                lbl.addEventListener('click', function () {
                    window.setTimeout(submitFiltro, 15);
                });
            });
            const busqueda = filterForm.querySelector('.js-filtro-busqueda-insc');
            if (busqueda) {
                busqueda.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        filterForm.submit();
                    }
                });
            }
        }

        const modalEl = document.getElementById('modalReciboInscrito');
        const modalBody = document.getElementById('modalReciboInscritoBody');
        const btnPrint = document.getElementById('btnReciboInscritoImprimir');
        let modal;

        function showRecibo(html) {
            if (!modalEl || !modalBody) {
                alert('No se encontró el modal de recibo en la página. Recargue e intente de nuevo.');
                return;
            }
            modalBody.innerHTML = html;
            function abrirModal(intentos) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    modal = modal || new bootstrap.Modal(modalEl, {
                        backdrop: 'static',
                        keyboard: false,
                    });
                    modal.show();
                    return;
                }
                if (intentos > 60) {
                    alert('No se pudo abrir el recibo: Bootstrap no está disponible.');
                    return;
                }
                window.setTimeout(function () {
                    abrirModal(intentos + 1);
                }, 50);
            }
            abrirModal(0);
        }

        if (btnPrint && modalBody) {
            btnPrint.addEventListener('click', () => {
                const w = window.open('', '_blank');
                if (!w) {
                    alert('Permita ventanas emergentes para imprimir el recibo.');
                    return;
                }
                w.document.write(
                    '<html><head><title>Recibo</title><style>body{font-family:sans-serif;padding:1rem;}</style></head><body>' +
                        modalBody.innerHTML +
                        '</body></html>'
                );
                w.document.close();
                w.focus();
                w.print();
            });
        }

        function marcarPagoConfirmado(btn) {
            btn.classList.remove('btn-warning', 'text-dark');
            btn.classList.add('btn-success');
            btn.textContent = 'Confirmado';
            btn.dataset.estado = 'confirmado';
            btn.title = 'Ver recibo de pago emitido';
            const row = btn.closest('tr[data-inscripcion-row]');
            if (!row) return;
            const retBtn = row.querySelector('.js-retirar-inscrito');
            if (retBtn) retBtn.dataset.pagoConfirmado = '1';
            const actions = row.querySelector('.registrants-report-actions, .btn-group');
            if (actions && !actions.querySelector('.js-revertir-pago-inscrito')) {
                const inscripcionId = btn.dataset.inscripcionId || '';
                const torneoId = btn.dataset.torneoId || '';
                const nombre = btn.dataset.nombre || '';
                const recBtn = document.createElement('button');
                recBtn.type = 'button';
                recBtn.className = 'btn btn-outline-success js-recibo-inscrito';
                recBtn.title = 'Ver recibo';
                recBtn.dataset.inscripcionId = inscripcionId;
                recBtn.dataset.torneoId = torneoId;
                recBtn.innerHTML = '<i class="fas fa-receipt"></i>';
                const revBtn = document.createElement('button');
                revBtn.type = 'button';
                revBtn.className = 'btn btn-outline-secondary js-revertir-pago-inscrito';
                revBtn.title = 'Revertir a pendiente (confirmación doble)';
                revBtn.dataset.inscripcionId = inscripcionId;
                revBtn.dataset.torneoId = torneoId;
                revBtn.dataset.nombre = nombre;
                revBtn.innerHTML = '<i class="fas fa-undo"></i>';
                actions.appendChild(recBtn);
                actions.appendChild(revBtn);
            }
        }

        function setEstatus(inscripcionId, torneoId, estado, onSuccess, confirmacionDobleFlag) {
            const payload = {
                accion: 'toggle_estatus',
                inscripcion_id: inscripcionId,
                torneo_id: torneoId,
                estado: estado,
                csrf_token: csrf,
            };
            if (confirmacionDobleFlag) {
                payload.confirmacion_doble = '1';
            }
            return postForm(payload)
                .then((data) => {
                    if (!data.ok) {
                        alert(data.message || 'Error al actualizar');
                        return false;
                    }
                    if (typeof onSuccess === 'function') onSuccess(data);
                    return true;
                })
                .catch((err) => {
                    alert(err && err.message ? err.message : 'Error de conexión');
                    return false;
                });
        }

        function verRecibo(inscripcionId, torneoId) {
            return postForm({
                accion: 'ver_recibo',
                inscripcion_id: inscripcionId,
                torneo_id: torneoId,
                csrf_token: csrf,
            }).then((data) => {
                if (data.ok && data.recibo_html) {
                    showRecibo(data.recibo_html);
                    return true;
                }
                alert(data.message || 'No se pudo cargar el recibo');
                return false;
            });
        }

        function mostrarReciboTrasPago(data, inscripcionId, torneoId) {
            if (data.recibo_html) {
                showRecibo(data.recibo_html);
                return;
            }
            if (data.recibo_warning) {
                alert(data.recibo_warning);
                verRecibo(inscripcionId, torneoId).catch(function () {});
                return;
            }
            verRecibo(inscripcionId, torneoId).catch(function (err) {
                alert(err && err.message ? err.message : 'Pago confirmado, pero no se pudo cargar el recibo.');
            });
        }

        function onPagoClick(btn) {
            const inscripcionId = btn.dataset.inscripcionId || '';
            const torneoId = btn.dataset.torneoId || '';
            const nombre = btn.dataset.nombre || 'este jugador';
            const estado = btn.dataset.estado || 'pendiente';

            if (!inscripcionId || !torneoId) {
                alert('Datos de inscripción incompletos en la fila. Recargue la página.');
                return;
            }

            if (estado === 'confirmado') {
                btn.disabled = true;
                verRecibo(inscripcionId, torneoId)
                    .catch((err) => alert(err && err.message ? err.message : 'Error de conexión'))
                    .finally(() => {
                        btn.disabled = false;
                    });
                return;
            }

            if (
                !confirm(
                    '¿Registrar pago de ' +
                        nombre +
                        ' y emitir recibo?\n\nTras emitir el recibo el registro quedará bloqueado en inscripción en sitio.'
                )
            ) {
                return;
            }

            btn.disabled = true;
            setEstatus(inscripcionId, torneoId, 'confirmado', (data) => {
                marcarPagoConfirmado(btn);
                mostrarReciboTrasPago(data, inscripcionId, torneoId);
            }).finally(() => {
                btn.disabled = false;
            });
        }

        function onRevertirClick(btn) {
            const inscripcionId = btn.dataset.inscripcionId || '';
            const torneoId = btn.dataset.torneoId || '';
            const nombre = btn.dataset.nombre || 'este jugador';
            if (
                !confirmacionDoble(
                    '¿Revertir el pago de ' + nombre + ' a estado Pagar?',
                    'Confirme definitivamente: el recibo emitido quedará sin efecto administrativo.'
                )
            ) {
                return;
            }
            btn.disabled = true;
            setEstatus(inscripcionId, torneoId, 'pendiente', () => {
                window.location.reload();
            }, true).finally(() => {
                btn.disabled = false;
            });
        }

        function onRetirarClick(btn) {
            const inscripcionId = btn.dataset.inscripcionId || '';
            const torneoId = btn.dataset.torneoId || '';
            const nombre = btn.dataset.nombre || 'este jugador';
            const pagoConfirmado = btn.dataset.pagoConfirmado === '1';
            let ok = confirm('¿Retirar a ' + nombre + ' del torneo?\n\nSe eliminará la inscripción y el atleta quedará en disponibles.');
            if (ok && pagoConfirmado) {
                ok = confirmacionDoble(
                    'El inscrito tiene recibo de pago emitido.',
                    'Confirme definitivamente el retiro y liberación del atleta.'
                );
            }
            if (!ok) return;
            btn.disabled = true;
            setEstatus(inscripcionId, torneoId, 'retirado', () => {
                const row = btn.closest('tr[data-inscripcion-row]');
                if (row) row.remove();
                else window.location.reload();
            }, pagoConfirmado).finally(() => {
                btn.disabled = false;
            });
        }

        function onReciboClick(btn) {
            const inscripcionId = btn.dataset.inscripcionId || '';
            const torneoId = btn.dataset.torneoId || '';
            btn.disabled = true;
            verRecibo(inscripcionId, torneoId)
                .catch((err) => alert(err && err.message ? err.message : 'Error de conexión'))
                .finally(() => {
                    btn.disabled = false;
                });
        }

        document.body.addEventListener('click', function (e) {
            const pagoBtn = e.target.closest('.js-pago-celda-inscrito');
            if (pagoBtn) {
                e.preventDefault();
                onPagoClick(pagoBtn);
                return;
            }
            const revBtn = e.target.closest('.js-revertir-pago-inscrito');
            if (revBtn) {
                e.preventDefault();
                onRevertirClick(revBtn);
                return;
            }
            const retBtn = e.target.closest('.js-retirar-inscrito');
            if (retBtn) {
                e.preventDefault();
                onRetirarClick(retBtn);
                return;
            }
            const recBtn = e.target.closest('.js-recibo-inscrito');
            if (recBtn) {
                e.preventDefault();
                onReciboClick(recBtn);
                return;
            }
            const msgBtn = e.target.closest('.js-enviar-mensaje-inscrito');
            if (msgBtn) {
                e.preventDefault();
                const inscripcionId = msgBtn.dataset.inscripcionId;
                const torneoId = msgBtn.dataset.torneoId;
                if (!confirm('¿Enviar mensaje al inscrito por notificación web y Telegram?')) {
                    return;
                }
                msgBtn.disabled = true;
                postForm({
                    accion: 'recordatorio_pago',
                    inscripcion_id: inscripcionId,
                    torneo_id: torneoId,
                    csrf_token: csrf,
                })
                    .then((data) => {
                        const base = data.ok
                            ? 'Mensaje enviado por notificación web y Telegram.'
                            : data.message || 'Error al enviar';
                        alert(base);
                        if (data.ok && data.whatsapp_url) {
                            window.open(data.whatsapp_url, '_blank');
                        }
                    })
                    .catch((err) => alert(err && err.message ? err.message : 'Error de conexión'))
                    .finally(() => {
                        msgBtn.disabled = false;
                    });
                return;
            }
            const delBtn = e.target.closest('.js-eliminar-inscripcion-retirado');
            if (delBtn) {
                e.preventDefault();
                const inscripcionId = delBtn.dataset.inscripcionId || '';
                const torneoId = delBtn.dataset.torneoId || '';
                const nombre = delBtn.dataset.nombre || 'este jugador';
                if (
                    !confirm(
                        '¿Eliminar la inscripción de ' +
                            nombre +
                            '?\n\nSe borrará el registro y el atleta quedará disponible para inscribir de nuevo.'
                    )
                ) {
                    return;
                }
                delBtn.disabled = true;
                postForm({
                    accion: 'eliminar_inscripcion',
                    inscripcion_id: inscripcionId,
                    torneo_id: torneoId,
                    csrf_token: csrf,
                })
                    .then((data) => {
                        if (data.ok) {
                            const row = delBtn.closest('tr[data-inscripcion-row]');
                            if (row) row.remove();
                            else window.location.reload();
                        } else {
                            alert(data.message || 'No se pudo eliminar');
                        }
                    })
                    .catch((err) => alert(err && err.message ? err.message : 'Error de conexión'))
                    .finally(() => {
                        delBtn.disabled = false;
                    });
            }
        });

        document.querySelectorAll('.js-cambiar-asoc-inscrito').forEach((sel) => {
            if (cfg.permiteCambiarAsoc === false) {
                return;
            }
            sel.dataset.prevClub = sel.value;
            sel.addEventListener('change', function () {
                const el = this;
                const prev = el.dataset.prevClub || el.value;
                const inscripcionId = el.dataset.inscripcionId || '';
                const torneoId = el.dataset.torneoId || '';
                const nuevoClub = el.value;
                if (!inscripcionId || !torneoId || !nuevoClub) {
                    return;
                }
                const row = el.closest('tr[data-inscripcion-row]');
                const pagoBtn = row ? row.querySelector('.js-pago-celda-inscrito') : null;
                const pagoConfirmado = pagoBtn && pagoBtn.dataset.estado === 'confirmado';
                if (pagoConfirmado) {
                    if (
                        !confirmacionDoble(
                            'El inscrito tiene recibo de pago emitido.',
                            'Confirme definitivamente el cambio de asociación.'
                        )
                    ) {
                        el.value = prev;
                        return;
                    }
                }
                el.disabled = true;
                const payload = {
                    accion: 'cambiar_asociacion',
                    inscripcion_id: inscripcionId,
                    torneo_id: torneoId,
                    id_club: nuevoClub,
                    csrf_token: csrf,
                };
                if (pagoConfirmado) {
                    payload.confirmacion_doble = '1';
                }
                postForm(payload)
                    .then((data) => {
                        if (data.ok) {
                            el.dataset.prevClub = String(nuevoClub);
                            window.location.reload();
                        } else {
                            el.value = prev;
                            alert(data.message || 'No se pudo cambiar la asociación');
                        }
                    })
                    .catch((err) => {
                        el.value = prev;
                        alert(err && err.message ? err.message : 'Error de conexión');
                    })
                    .finally(() => {
                        el.disabled = false;
                    });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRegistrantsInscripciones);
    } else {
        initRegistrantsInscripciones();
    }
})();
