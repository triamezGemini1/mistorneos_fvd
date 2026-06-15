/**
 * Landing SPA - Vue 3 Application
 * Mejora UX con navegación fluida, transiciones y carga dinámica
 */

const { createApp, ref, computed, onMounted, nextTick } = Vue;

/** Scroll a ancla de landing tras montar Vue (p. ej. retorno desde asociacion_detalle.php). */
function scrollToLandingHash(retry = 0) {
    const params = new URLSearchParams(window.location.search);
    const id = (window.location.hash || '').replace(/^#/, '').trim()
        || (params.get('section') || '').trim();
    if (!id) return;
    const el = document.getElementById(id);
    if (el) {
        requestAnimationFrame(() => {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        return;
    }
    if (retry < 24) {
        setTimeout(() => scrollToLandingHash(retry + 1), 120);
    }
}

const MODALIDADES = { 1: 'Individual', 2: 'Parejas', 3: 'Equipos' };
const CLASES = { 1: 'Torneo', 2: 'Campeonato' };
const MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const DIAS_SEMANA = ['Do','Lu','Ma','Mi','Ju','Vi','Sa'];
const RANKING_SUBS = [
    { slug: 'sub12', titulo: 'Sub 12' },
    { slug: 'sub15', titulo: 'Sub 15' },
    { slug: 'sub18', titulo: 'Sub 18' },
];

function escapeHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function formatFecha(fechator) {
    return new Date(fechator).toLocaleDateString('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function esHoy(fechator) {
    if (!fechator) return false;
    const today = new Date().toISOString().slice(0, 10);
    const fechaEv = String(fechator).slice(0, 10);
    return fechaEv === today;
}

const LandingContent = {
    name: 'LandingContent',
    props: {
        data: { type: Object, default: null },
        baseUrl: { type: String, default: '' },
        logoUrl: { type: String, default: '' },
        inscripcionLineaPublica: { type: Boolean, default: true },
    },
    emits: ['refresh-comentarios'],
    setup(props, { emit }) {
        const mobileMenuOpen = ref(false);
        const vistaCalendario = ref('anual'); // anual | mes | dia
        const calAnio = ref(new Date().getFullYear());
        const calMes = ref(new Date().getMonth());
        const fechaSeleccionada = ref(null);
        const modalFotos = ref(null);
        const modalImagen = ref(null);
        const commentSending = ref(false);
        const commentSuccess = ref(null);
        const commentErrors = ref([]);
        const commentForm = ref({ tipo: 'comentario', contenido: '', calificacion: null });

        const eventosPorFecha = computed(() => props.data?.eventos_por_fecha || {});
        const hoyStr = new Date().toISOString().slice(0, 10);
        const calendarioFuturo = computed(() => {
            const ef = eventosPorFecha.value;
            return Object.entries(ef)
                .filter(([f]) => f >= hoyStr)
                .sort((a, b) => a[0].localeCompare(b[0]));
        });

        const scrollToSection = (id) => {
            mobileMenuOpen.value = false;
            const el = document.getElementById(id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        const urlRankingOficial = (slug, genero) => {
            const params = new URLSearchParams({ genero: genero === 'F' ? 'F' : 'M' });
            if (slug && slug !== 'absoluto') params.set('categoria', slug);
            return props.baseUrl + 'ranking_atletas.php?' + params.toString();
        };

        const podiosAsociaciones = computed(() => props.data?.podios_asociaciones || { criterio: '', resumen: [], detalle: [] });
        const podiosResumen = computed(() => podiosAsociaciones.value.resumen || []);
        const asociacionesActivas = computed(() => props.data?.asociaciones_activas || []);
        const asocLogosFailed = ref(new Set());
        const urlAsociacionLogo = (asoc) => {
            if (!asoc || asocLogosFailed.value.has(asoc.id)) return '';
            if (asoc.logo_url) return asoc.logo_url;
            if (asoc.logo_path && props.baseUrl) {
                return props.baseUrl + 'view_image.php?path=' + encodeURIComponent(asoc.logo_path);
            }
            return '';
        };
        const onAsocLogoError = (id) => {
            const next = new Set(asocLogosFailed.value);
            next.add(id);
            asocLogosFailed.value = next;
        };
        const ASOC_BADGE_TONES = ['fvd-asoc-badge--blue', 'fvd-asoc-badge--amber', 'fvd-asoc-badge--indigo', 'fvd-asoc-badge--sky'];
        const badgeToneClass = (idx) => ASOC_BADGE_TONES[idx % ASOC_BADGE_TONES.length];
        const urlAsociacionDetalle = (id) => props.baseUrl + 'asociacion_detalle.php?id=' + encodeURIComponent(id);
        const podioAsociacionSeleccionada = ref(null);
        const seleccionarPodioAsociacion = (row) => {
            if (!row) return;
            if (podioAsociacionSeleccionada.value && podioAsociacionSeleccionada.value.entidad_id === row.entidad_id) {
                podioAsociacionSeleccionada.value = null;
                return;
            }
            podioAsociacionSeleccionada.value = row;
        };

        const renderTarjetaEvento = (ev, variant = 'purple') => {
            const esPasado = new Date(ev.fechator) < new Date();
            const permiteOnline = parseInt(ev.permite_inscripcion_linea || 1) === 1;
            const esHoyEv = esHoy(ev.fechator);
            const esMasivo = [1,2,3].includes(parseInt(ev.es_evento_masivo || 0));
            const telContacto = ev.admin_celular || ev.club_telefono || '';
            const modalidad = MODALIDADES[parseInt(ev.modalidad)||1] || 'Individual';
            const clase = CLASES[parseInt(ev.clase)||1] || 'Torneo';
            const nombreTorneo = escapeHtml(ev.nombre_limpio || ev.nombre || '');
            const fechaDmY = formatFecha(ev.fechator);
            const base = props.baseUrl;

            const bgGradient = variant === 'purple' ? 'from-purple-600 via-purple-700 to-indigo-800' : variant === 'blue' ? 'from-blue-600 via-blue-700 to-indigo-800' : 'from-gray-700 via-gray-800 to-gray-900';
            const btnColor = variant === 'purple' ? 'from-yellow-400 to-orange-500 text-purple-900' : variant === 'blue' ? 'from-yellow-400 to-orange-500 text-blue-900' : 'from-yellow-400 text-gray-900';

            let html = `<div class="bg-white/10 backdrop-blur-md rounded-2xl shadow-2xl hover:shadow-3xl transition-all duration-300 overflow-hidden border-2 border-white/20 hover:border-yellow-400 transform hover:-translate-y-2 text-center">`;
            if (ev.portada_url) {
                html += `<div class="w-full h-48 bg-cover bg-center" style="background-image:url('${escapeHtml(ev.portada_url)}')"></div>`;
            } else {
                html += `<div class="w-full h-48 bg-white/20 flex flex-col items-center justify-center p-4">`;
                if (ev.logo_url) html += `<img src="${escapeHtml(ev.logo_url)}" alt="" class="landing-logo-org object-contain mb-2" loading="lazy">`;
                html += `<span class="text-white text-xl font-bold">${escapeHtml(ev.organizacion_nombre || 'Organizador')}</span></div>`;
            }
            html += `<div class="p-6 text-center">`;
            html += `<div class="inline-flex items-center px-3 py-1 bg-yellow-400 ${variant === 'purple' ? 'text-purple-900' : variant === 'blue' ? 'text-blue-900' : 'text-gray-900'} rounded-full text-sm font-bold mb-4"><i class="fas fa-calendar mr-2"></i>${fechaDmY}</div>`;
            html += `<h5 class="text-xl font-bold text-white mb-2">${nombreTorneo}</h5>`;
            html += `<p class="text-white/80 text-sm mb-4 flex items-center justify-center"><i class="fas fa-map-marker-alt mr-2 text-yellow-400"></i>${escapeHtml(ev.lugar || 'No especificado')}</p>`;
            html += `<div class="flex flex-wrap gap-2 mb-4 justify-center">`;
            html += `<span class="px-3 py-1 bg-blue-500/80 text-white rounded-full text-xs font-semibold">${clase}</span>`;
            html += `<span class="px-3 py-1 bg-cyan-500/80 text-white rounded-full text-xs font-semibold">${modalidad}</span>`;
            if (ev.costo > 0) html += `<span class="px-3 py-1 bg-green-500/80 text-white rounded-full text-xs font-semibold">$${parseFloat(ev.costo).toFixed(2)}</span>`;
            html += `<span class="px-3 py-1 bg-yellow-400 ${variant === 'purple' ? 'text-purple-900' : 'text-gray-900'} rounded-full text-xs font-bold"><i class="fas fa-users mr-1"></i>${ev.total_inscritos||0} inscritos</span>`;
            html += `</div>`;

            const detalleUrl = ev.detalle_url || `${base}torneo_detalle.php?torneo_id=${ev.id}`;
            html += `<a href="${detalleUrl}" class="block w-full px-4 py-2 mb-2 bg-white/15 text-white font-semibold rounded-lg hover:bg-white/25 transition-all text-center border border-white/30"><i class="fas fa-info-circle mr-2"></i>Ver ficha del torneo</a>`;

            if (esPasado) {
                html += `<a href="${base}evento_resultados.php?torneo_id=${ev.id}" class="block w-full px-4 py-3 bg-gradient-to-r from-emerald-500 to-emerald-600 text-white font-bold rounded-lg hover:from-emerald-600 hover:to-emerald-700 transition-all text-center shadow-lg"><i class="fas fa-trophy mr-2"></i>Ver Resultados</a>`;
            } else if (props.inscripcionLineaPublica && permiteOnline && !esHoyEv) {
                const urlInsc = esMasivo ? `${base}inscribir_evento_masivo.php?torneo_id=${ev.id}` : `${base}tournament_register.php?torneo_id=${ev.id}`;
                html += `<a href="${urlInsc}" class="block w-full px-4 py-3 bg-gradient-to-r ${btnColor} font-bold rounded-lg hover:from-yellow-500 hover:to-orange-600 transition-all text-center shadow-lg hover:shadow-xl transform hover:scale-105"><i class="fas fa-mobile-alt mr-2"></i>Inscribirme Ahora</a>`;
            } else if (props.inscripcionLineaPublica && permiteOnline && esHoyEv) {
                html += `<div class="bg-yellow-400/20 rounded-lg p-3 border border-yellow-400/50"><p class="text-xs text-center"><i class="fas fa-info-circle mr-1"></i>Inscripción deshabilitada el día del torneo.</p></div>`;
            } else {
                html += `<div class="bg-yellow-400/20 rounded-lg p-3 mb-3 border border-yellow-400/50"><p class="text-xs text-purple-900 text-center mb-2"><i class="fas fa-info-circle mr-1"></i>Inscripción en sitio. Contacta al organizador.</p>`;
                if (telContacto) html += `<a href="tel:${telContacto.replace(/\D/g,'')}" class="block w-full px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-bold rounded-lg hover:from-green-600 hover:to-emerald-700 transition-all text-center shadow-lg"><i class="fas fa-phone mr-2"></i>Contactar administración</a>`;
                else html += `<p class="text-xs text-center text-purple-800">Consulta con el organizador para inscribirte</p>`;
                html += `</div>`;
            }
            html += `</div></div>`;
            return html;
        };

        const viewEventPhotos = async (torneoId, torneoNombre) => {
            const container = document.getElementById('modal-container');
            if (!container) return;
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm';
            modal.innerHTML = `
                <div class="bg-white rounded-2xl max-w-6xl w-full max-h-[90vh] overflow-hidden shadow-2xl">
                    <div class="flex items-center justify-between p-6 border-b border-gray-200">
                        <h5 class="text-xl font-bold text-gray-900 flex items-center"><i class="fas fa-images mr-3 text-primary-500"></i>Fotografías - ${escapeHtml(torneoNombre)}</h5>
                        <button type="button" class="modal-close-btn text-gray-400 hover:text-gray-600"><i class="fas fa-times text-2xl"></i></button>
                    </div>
                    <div class="p-6 overflow-y-auto max-h-[calc(90vh-100px)]">
                        <div class="galeria-loading text-center py-12"><i class="fas fa-spinner fa-spin text-4xl text-primary-500 mb-4"></i><p class="text-gray-600">Cargando...</p></div>
                        <div class="galeria-content hidden"><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 galeria-fotos"></div></div>
                    </div>
                </div>`;
            container.innerHTML = '';
            container.appendChild(modal);

            const closeModal = () => modal.remove();
            modal.querySelector('.modal-close-btn').onclick = closeModal;
            modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

            try {
                const res = await fetch(`${props.baseUrl}api/tournament_photos_get.php?torneo_id=${torneoId}`);
                const data = await res.json();
                const loadingDiv = modal.querySelector('.galeria-loading');
                const contentDiv = modal.querySelector('.galeria-content');
                const fotosContainer = modal.querySelector('.galeria-fotos');

                if (data.success && data.fotos?.length) {
                    loadingDiv.classList.add('hidden');
                    contentDiv.classList.remove('hidden');
                    const imgBase = props.baseUrl.replace(/\/public\/?$/, '') + '/';
                    data.fotos.forEach(f => {
                        const img = document.createElement('img');
                        img.src = f.url || (imgBase + (f.ruta_imagen || '').replace(/^\//, ''));
                        img.alt = f.torneo_nombre || f.titulo || '';
                        img.className = 'w-full h-48 object-cover rounded-lg';
                        fotosContainer.appendChild(img);
                    });
                } else {
                    loadingDiv.innerHTML = '<p class="text-gray-600">No hay fotografías disponibles</p>';
                }
            } catch (err) {
                modal.querySelector('.galeria-loading').innerHTML = '<p class="text-red-600">Error al cargar</p>';
            }
        };

        const enviarComentario = async () => {
            commentErrors.value = [];
            commentSuccess.value = null;
            if (!commentForm.value.contenido || commentForm.value.contenido.trim().length < 10) {
                commentErrors.value = ['El comentario debe tener al menos 10 caracteres'];
                return;
            }
            commentSending.value = true;
            try {
                const res = await fetch(`${props.baseUrl}api/comentarios_save.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': props.data.csrf_token
                    },
                    body: JSON.stringify({
                        tipo: commentForm.value.tipo,
                        contenido: commentForm.value.contenido.trim(),
                        calificacion: commentForm.value.calificacion || null
                    })
                });
                const data = await res.json();
                if (data.success) {
                    commentSuccess.value = data.message;
                    commentForm.value = { tipo: 'comentario', contenido: '', calificacion: null };
                    emit('refresh-comentarios');
                } else {
                    commentErrors.value = data.errors || [data.error || 'Error al enviar'];
                }
            } catch (err) {
                commentErrors.value = ['Error de conexión. Intenta de nuevo.'];
            } finally {
                commentSending.value = false;
            }
        };

        return {
            mobileMenuOpen,
            vistaCalendario,
            calAnio,
            calMes,
            fechaSeleccionada,
            eventosPorFecha,
            calendarioFuturo,
            commentForm,
            commentSending,
            commentSuccess,
            commentErrors,
            inscripcionLineaPublica: computed(() => props.inscripcionLineaPublica !== false),
            scrollToSection,
            urlRankingOficial,
            rankingSubs: RANKING_SUBS,
            podiosAsociaciones,
            podiosResumen,
            podioAsociacionSeleccionada,
            seleccionarPodioAsociacion,
            asociacionesActivas,
            urlAsociacionLogo,
            onAsocLogoError,
            badgeToneClass,
            urlAsociacionDetalle,
            renderTarjetaEvento,
            viewEventPhotos,
            enviarComentario,
            MODALIDADES,
            CLASES,
            MESES,
            DIAS_SEMANA,
            formatFecha,
            escapeHtml,
            esHoy
        };
    },
    template: `#landing-template`
};

createApp({
    setup() {
        const loading = ref(true);
        const error = ref(null);
        const data = ref(null);
        const baseUrl = ref(window.APP_CONFIG?.baseUrl || '');

        const cargarDatos = async () => {
            loading.value = true;
            error.value = null;
            try {
                const res = await fetch(window.APP_CONFIG?.apiUrl || 'api/landing_data.php');
                const json = await res.json();
                if (json.success) {
                    data.value = json;
                    baseUrl.value = json.base_url || baseUrl.value;
                } else {
                    error.value = json.message || json.error || 'Error al cargar los datos';
                }
            } catch (err) {
                error.value = 'No se pudo conectar a la API. ' + (err.message || 'Verifica tu conexión.');
            } finally {
                loading.value = false;
                await nextTick();
                scrollToLandingHash();
            }
        };

        const logoUrl = ref(window.APP_CONFIG?.logoUrl || '');
        const inscripcionLineaPublica = ref(window.APP_CONFIG?.inscripcionLineaPublica !== false);

        const effectiveLogoUrl = computed(() => {
            if (logoUrl.value) return logoUrl.value;
            const b = (baseUrl.value || '').replace(/\/$/, '');
            return b ? b + '/view_image.php?path=' + encodeURIComponent('lib/Assets/mislogos/logo4.png') : '';
        });

        onMounted(() => {
            if (window.APP_CONFIG?.logoUrl) logoUrl.value = window.APP_CONFIG.logoUrl;
            cargarDatos();
            window.addEventListener('hashchange', () => scrollToLandingHash());
        });

        return { loading, error, data, baseUrl, logoUrl: effectiveLogoUrl, inscripcionLineaPublica, cargarDatos };
    },
    components: { LandingContent }
}).mount('#app');
