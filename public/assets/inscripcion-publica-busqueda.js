/**
 * Búsqueda secuencial al salir del campo cédula (inscripción pública desde landing):
 * 1) usuarios → 2) base externa personas → 3) captura manual
 * API: public/api/search_persona.php
 */
(function (global) {
    'use strict';

    function splitNombreCompleto(full) {
        full = String(full || '').trim().replace(/\s+/g, ' ');
        if (!full) {
            return { nombre: '', apellido: '' };
        }
        const i = full.indexOf(' ');
        if (i === -1) {
            return { nombre: full, apellido: '' };
        }
        return { nombre: full.slice(0, i), apellido: full.slice(i + 1).trim() };
    }

    function setField(id, value) {
        if (value === null || value === undefined || value === '') {
            return;
        }
        const el = document.getElementById(id);
        if (el) {
            el.value = value;
        }
    }

    function setByName(name, value) {
        if (value === null || value === undefined || value === '') {
            return;
        }
        const el = document.querySelector('[name="' + name + '"]');
        if (el) {
            el.value = String(value);
        }
    }

    function normalizarSexo(raw) {
        const s = String(raw || '').toUpperCase();
        if (s === 'M' || s === '1' || s === 'MASCULINO') {
            return 'M';
        }
        if (s === 'F' || s === '2' || s === 'FEMENINO') {
            return 'F';
        }
        if (s === 'O') {
            return 'O';
        }
        return '';
    }

    function normalizarCedulaEnFormulario(cedulaEl, nacEl) {
        let cedula = cedulaEl.value.trim();
        let nacionalidad = nacEl && nacEl.value ? nacEl.value : 'V';
        const match = cedula.match(/^([VEJP])(\d+)$/i);
        if (match) {
            nacionalidad = match[1].toUpperCase();
            cedula = match[2];
            if (nacEl) {
                nacEl.value = nacionalidad;
            }
            cedulaEl.value = cedula;
        }
        return {
            cedula: cedula.replace(/\D/g, ''),
            nacionalidad: nacionalidad,
        };
    }

    function limpiarCamposPersonales(opts, mantenerCedula) {
        const ids = opts.camposLimpiar || [
            'nombre', 'apellido', 'sexo', 'celular', 'email', 'fechnac', 'username',
        ];
        ids.forEach(function (id) {
            const el = document.getElementById(id);
            if (el) {
                el.value = '';
            }
        });
        (opts.selectsLimpiar || ['entidad', 'club_id']).forEach(function (name) {
            const el = document.querySelector('[name="' + name + '"]');
            if (el) {
                el.value = '';
            }
        });
        if (!mantenerCedula) {
            const ced = document.getElementById(opts.cedulaId || 'cedula');
            if (ced) {
                ced.value = '';
            }
        }
    }

    function aplicarPersona(persona, opts) {
        if (!persona) {
            return;
        }

        if (persona.nacionalidad) {
            setField(opts.nacionalidadId || 'nacionalidad', String(persona.nacionalidad).toUpperCase());
            setByName('nacionalidad', persona.nacionalidad);
        }

        const nombreCompleto = persona.nombre_completo
            || persona.nombre
            || [persona.nombre, persona.apellido].filter(Boolean).join(' ').trim();

        if (opts.modoNombre === 'split') {
            let nombre = persona.nombre || '';
            let apellido = persona.apellido || '';
            if (!apellido && nombre.indexOf(' ') !== -1) {
                const partes = splitNombreCompleto(nombre);
                nombre = partes.nombre;
                apellido = partes.apellido;
            }
            setField('nombre', nombre);
            setField('apellido', apellido);
        } else {
            setField(opts.nombreId || 'nombre', nombreCompleto);
            setByName('nombre', nombreCompleto);
        }

        const sexo = normalizarSexo(persona.sexo);
        if (sexo) {
            setField(opts.sexoId || 'sexo', sexo);
            setByName('sexo', sexo);
        }

        setField(opts.celularId || 'celular', persona.celular || persona.telefono);
        setByName('celular', persona.celular || persona.telefono);
        setField(opts.emailId || 'email', persona.email);
        setByName('email', persona.email);
        setField(opts.fechnacId || 'fechnac', persona.fechnac);
        setByName('fechnac', persona.fechnac);

        if (persona.username) {
            setField(opts.usernameId || 'username', persona.username);
            setByName('username', persona.username);
        }

        if (persona.entidad) {
            setByName('entidad', persona.entidad);
        }
        if (persona.club_id) {
            setByName('club_id', persona.club_id);
        }
    }

    function setEstadoFormulario(opts, bloqueado) {
        const form = document.getElementById(opts.formId);
        const btn = opts.submitSelector
            ? document.querySelector(opts.submitSelector)
            : (form ? form.querySelector('button[type="submit"]') : null);
        if (btn) {
            btn.disabled = !!bloqueado;
            btn.classList.toggle('opacity-50', !!bloqueado);
            btn.classList.toggle('cursor-not-allowed', !!bloqueado);
        }
        if (form) {
            form.style.opacity = bloqueado ? '0.65' : '1';
        }
    }

    function mensajeHtml(tipo, texto) {
        const icon = {
            info: 'fa-info-circle',
            ok: 'fa-check-circle',
            warn: 'fa-user-plus',
            err: 'fa-times-circle',
            spin: 'fa-spinner fa-spin',
        };
        const cls = {
            info: 'text-blue-600',
            ok: 'text-green-700 fw-semibold',
            warn: 'text-amber-700 fw-semibold',
            err: 'text-red-600 fw-semibold',
            spin: 'text-blue-600',
        };
        return '<span class="' + (cls[tipo] || 'text-muted') + '">'
            + '<i class="fas ' + (icon[tipo] || 'fa-info-circle') + ' me-1"></i>'
            + texto + '</span>';
    }

    async function buscarPorCedula(opts) {
        const cedulaEl = document.getElementById(opts.cedulaId || 'cedula');
        const nacEl = document.getElementById(opts.nacionalidadId || 'nacionalidad')
            || document.querySelector('[name="nacionalidad"]');
        const resultadoDiv = document.getElementById(opts.resultadoId || 'busqueda_resultado');

        if (!cedulaEl || !resultadoDiv) {
            return;
        }

        const raw = cedulaEl.value.trim();
        if (!raw || raw.replace(/\D/g, '').length < 5) {
            resultadoDiv.innerHTML = '';
            setEstadoFormulario(opts, false);
            return;
        }

        const norm = normalizarCedulaEnFormulario(cedulaEl, nacEl);
        resultadoDiv.innerHTML = mensajeHtml('spin', 'Buscando en usuarios y padrón nacional...');
        setEstadoFormulario(opts, false);

        const base = String(opts.baseUrl || '').replace(/\/$/, '');
        const url = base + '/public/api/search_persona.php?'
            + 'cedula=' + encodeURIComponent(norm.cedula)
            + '&nacionalidad=' + encodeURIComponent(norm.nacionalidad)
            + '&torneo_id=' + encodeURIComponent(String(opts.torneoId || 0));

        try {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            const data = await response.json();
            const accion = data.accion || '';
            const persona = data.persona || null;

            if (accion === 'ya_inscrito') {
                resultadoDiv.innerHTML = mensajeHtml('err', data.mensaje || 'Ya está inscrito en este torneo.');
                setEstadoFormulario(opts, true);
                return;
            }

            if (accion === 'error') {
                resultadoDiv.innerHTML = mensajeHtml('err', data.mensaje || 'Error en la búsqueda.');
                return;
            }

            if (accion === 'encontrado_usuario' && persona) {
                aplicarPersona(persona, opts);
                const fuente = data.fuente === 'usuarios' ? 'la plataforma FVD' : 'el sistema';
                resultadoDiv.innerHTML = mensajeHtml(
                    'ok',
                    (data.mensaje || 'Datos encontrados en ' + fuente + '. Revise y pulse inscribirse.')
                );
                opts.onEncontrado && opts.onEncontrado('usuario', persona, data);
                return;
            }

            if (accion === 'encontrado_persona' && persona) {
                aplicarPersona(persona, opts);
                resultadoDiv.innerHTML = mensajeHtml(
                    'warn',
                    data.mensaje || 'Datos encontrados en el padrón externo. Revise y complete; al inscribirse se creará su usuario.'
                );
                opts.onEncontrado && opts.onEncontrado('externa', persona, data);
                return;
            }

            if (accion === 'nuevo') {
                limpiarCamposPersonales(opts, true);
                resultadoDiv.innerHTML = mensajeHtml(
                    'warn',
                    data.mensaje || 'No encontrado. Complete los datos manualmente para registrarse e inscribirse.'
                );
                const foco = document.getElementById(
                    opts.modoNombre === 'split' ? 'nombre' : (opts.nombreId || 'nombre')
                );
                if (foco) {
                    foco.focus();
                }
                opts.onNuevo && opts.onNuevo(data);
                return;
            }

            resultadoDiv.innerHTML = mensajeHtml(
                'info',
                'No se obtuvo resultado claro. Complete el formulario manualmente.'
            );
        } catch (err) {
            console.error('InscripcionPublicaBusqueda:', err);
            resultadoDiv.innerHTML = mensajeHtml('err', 'Error al buscar. Intente de nuevo.');
        }
    }

    function init(userOpts) {
        const opts = Object.assign({
            cedulaId: 'cedula',
            nacionalidadId: 'nacionalidad',
            resultadoId: 'busqueda_resultado',
            modoNombre: 'completo',
            torneoId: 0,
            baseUrl: '',
            minCedula: 5,
        }, userOpts || {});

        const cedulaEl = document.getElementById(opts.cedulaId);
        if (!cedulaEl) {
            return opts;
        }

        const ejecutar = function () {
            buscarPorCedula(opts);
        };

        cedulaEl.addEventListener('blur', ejecutar);

        const nacEl = document.getElementById(opts.nacionalidadId)
            || document.querySelector('[name="nacionalidad"]');
        if (nacEl) {
            nacEl.addEventListener('change', function () {
                if (cedulaEl.value.trim().replace(/\D/g, '').length >= (opts.minCedula || 5)) {
                    ejecutar();
                }
            });
        }

        global.buscarPersonaInscripcionPublica = ejecutar;
        return opts;
    }

    global.InscripcionPublicaBusqueda = {
        init: init,
        buscar: buscarPorCedula,
        aplicarPersona: aplicarPersona,
    };
})(typeof window !== 'undefined' ? window : this);
