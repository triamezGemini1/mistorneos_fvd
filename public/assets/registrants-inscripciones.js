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

    document.querySelectorAll('.js-switch-pago-inscrito').forEach((el) => {
        el.addEventListener('change', function () {
            const inscripcionId = this.dataset.inscripcionId;
            const torneoId = this.dataset.torneoId;
            const pagado = this.checked ? '1' : '0';
            const label = this.closest('.form-check')?.querySelector('.js-switch-pago-label');
            const prev = !this.checked;
            this.disabled = true;

            postForm({
                accion: 'toggle_pago',
                inscripcion_id: inscripcionId,
                torneo_id: torneoId,
                pagado: pagado,
                csrf_token: csrf,
            })
                .then((data) => {
                    if (!data.ok) {
                        alert(data.message || 'Error al actualizar');
                        this.checked = prev;
                        return;
                    }
                    if (label) {
                        label.textContent = this.checked ? 'Confirmado' : 'Pendiente';
                    }
                    if (this.checked && data.recibo_html) {
                        showRecibo(data.recibo_html);
                    }
                    setTimeout(() => window.location.reload(), this.checked && data.recibo_html ? 800 : 400);
                })
                .catch(() => {
                    alert('Error de conexión');
                    this.checked = prev;
                })
                .finally(() => {
                    this.disabled = false;
                });
        });
    });

    document.querySelectorAll('.js-notif-inscrito').forEach((btn) => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const inscripcionId = this.dataset.inscripcionId;
            const torneoId = this.dataset.torneoId;
            this.disabled = true;
            postForm({
                accion: 'recordatorio_pago',
                inscripcion_id: inscripcionId,
                torneo_id: torneoId,
                csrf_token: csrf,
            })
                .then((data) => {
                    alert(data.message || (data.ok ? 'Enviado' : 'Error'));
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

    const modalEl = document.getElementById('modalReciboInscrito');
    const modalBody = document.getElementById('modalReciboInscritoBody');
    const btnPrint = document.getElementById('btnReciboInscritoImprimir');
    let modal;

    function showRecibo(html) {
        if (!modalEl || !modalBody) return;
        modalBody.innerHTML = html;
        if (typeof bootstrap !== 'undefined') {
            modal = modal || new bootstrap.Modal(modalEl);
            modal.show();
        }
    }

    if (btnPrint) {
        btnPrint.addEventListener('click', () => {
            const w = window.open('', '_blank');
            if (!w) return;
            w.document.write('<html><head><title>Recibo</title></head><body>' + modalBody.innerHTML + '</body></html>');
            w.document.close();
            w.focus();
            w.print();
        });
    }
})();
