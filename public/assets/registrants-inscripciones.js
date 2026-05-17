(function () {
    const cfg = window.REGISTRANTS_INSC_CFG || {};
    const apiUrl = cfg.apiUrl || '';
    const csrf = cfg.csrf || '';

    function postForm(data) {
        const body = new URLSearchParams();
        Object.keys(data).forEach((k) => body.append(k, data[k]));
        return fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString(),
        }).then((r) => r.json());
    }

    const filterForm = document.getElementById('registrantsFilterForm');
    if (filterForm) {
        filterForm.querySelectorAll('.js-filtro-estatus-insc').forEach((radio) => {
            radio.addEventListener('change', function () {
                if (this.checked) filterForm.submit();
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
        if (!modalEl || !modalBody) return;
        modalBody.innerHTML = html;
        if (typeof bootstrap !== 'undefined') {
            modal = modal || new bootstrap.Modal(modalEl, {
                backdrop: 'static',
                keyboard: false,
            });
            modal.show();
        }
    }

    if (btnPrint) {
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

    function updatePagoLabel(sw) {
        const label = sw.closest('.form-check')?.querySelector('.js-switch-pago-label');
        if (label) label.textContent = sw.checked ? 'Confirmado' : 'Pendiente';
    }

    function updateRetiradoLabel(sw) {
        const label = sw.closest('.form-check')?.querySelector('.js-switch-retirado-label');
        if (label) label.textContent = sw.checked ? 'Retirado' : 'Activo';
    }

    function setEstatus(inscripcionId, torneoId, estado, onSuccess) {
        return postForm({
            accion: 'toggle_estatus',
            inscripcion_id: inscripcionId,
            torneo_id: torneoId,
            estado: estado,
            csrf_token: csrf,
        })
            .then((data) => {
                if (!data.ok) {
                    alert(data.message || 'Error al actualizar');
                    return false;
                }
                if (typeof onSuccess === 'function') onSuccess(data);
                return true;
            })
            .catch(() => {
                alert('Error de conexión');
                return false;
            });
    }

    document.querySelectorAll('.js-switch-pago-inscrito').forEach((sw) => {
        sw.addEventListener('change', function () {
            const el = this;
            const inscripcionId = el.dataset.inscripcionId || '';
            const torneoId = el.dataset.torneoId || '';
            const estado = el.checked ? 'confirmado' : 'pendiente';
            const prevChecked = !el.checked;
            el.disabled = true;

            setEstatus(inscripcionId, torneoId, estado, (data) => {
                updatePagoLabel(el);
                if (estado === 'confirmado' && data.recibo_html) {
                    showRecibo(data.recibo_html);
                } else {
                    window.location.reload();
                }
            }).then((ok) => {
                if (!ok) {
                    el.checked = prevChecked;
                    updatePagoLabel(el);
                }
            }).finally(() => {
                el.disabled = false;
            });
        });
    });

    document.querySelectorAll('.js-switch-retirado-inscrito').forEach((sw) => {
        sw.addEventListener('change', function () {
            const inscripcionId = this.dataset.inscripcionId || '';
            const torneoId = this.dataset.torneoId || '';
            const marcarRetirado = this.checked;
            const prevChecked = !this.checked;

            if (marcarRetirado) {
                if (!confirm('¿Marcar a este jugador como retirado del torneo?')) {
                    this.checked = false;
                    return;
                }
            }

            this.disabled = true;
            const estado = marcarRetirado ? 'retirado' : 'pendiente';

            setEstatus(inscripcionId, torneoId, estado, () => {
                updateRetiradoLabel(this);
                window.location.reload();
            })
                .then((ok) => {
                    if (!ok) {
                        this.checked = prevChecked;
                        updateRetiradoLabel(this);
                    }
                })
                .finally(() => {
                    this.disabled = false;
                });
        });
    });

    document.querySelectorAll('.js-enviar-mensaje-inscrito').forEach((btn) => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const inscripcionId = this.dataset.inscripcionId;
            const torneoId = this.dataset.torneoId;
            if (!confirm('¿Enviar mensaje al inscrito por notificación web y Telegram?')) {
                return;
            }
            this.disabled = true;
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
                .catch(() => alert('Error de conexión'))
                .finally(() => {
                    this.disabled = false;
                });
        });
    });

    document.querySelectorAll('.js-recibo-inscrito').forEach((btn) => {
        btn.addEventListener('click', function () {
            const inscripcionId = this.dataset.inscripcionId;
            const torneoId = this.dataset.torneoId;
            this.disabled = true;
            postForm({
                accion: 'ver_recibo',
                inscripcion_id: inscripcionId,
                torneo_id: torneoId,
                csrf_token: csrf,
            })
                .then((data) => {
                    if (data.ok && data.recibo_html) {
                        showRecibo(data.recibo_html);
                    } else {
                        alert(data.message || 'No se pudo cargar el recibo');
                    }
                })
                .catch(() => alert('Error de conexión'))
                .finally(() => {
                    this.disabled = false;
                });
        });
    });
})();
