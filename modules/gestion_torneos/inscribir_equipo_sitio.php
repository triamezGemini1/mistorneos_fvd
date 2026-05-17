<?php
/**
 * Vista: Inscribir en sitio (modalidad parejas = 2 o equipos = 3)
 * Misma UI de tres columnas; el torneo define integrantes vía pareclub y reglas de parejas vía modalidad.
 */
// Buffer amplio: evita flush por trozos (p. ej. 4KB) que hace ver primero Disponibles y luego el resto.
if (ob_get_level() < 5) {
    ob_start(null, 2 * 1024 * 1024);
}
$torneo = $view_data['torneo'] ?? [];
$contadores_inscripcion = $view_data['contadores_inscripcion'] ?? [];
if ($contadores_inscripcion === [] && !empty($torneo['id'])) {
    require_once __DIR__ . '/../../lib/InscritosHelper.php';
    $contadores_inscripcion = InscritosHelper::contadoresResumenInscripcionTorneo(DB::pdo(), (int) $torneo['id'], (int) ($torneo['modalidad'] ?? 0));
}
$jugadores_disponibles = $view_data['jugadores_disponibles'] ?? [];
$clubes_disponibles = $view_data['clubes_disponibles'] ?? [];
$equipos_registrados = $view_data['equipos_registrados'] ?? [];
$jugadores_por_equipo = max(2, (int)($view_data['jugadores_por_equipo'] ?? ($torneo['pareclub'] ?? 4)));
$modalidad_torneo = (int)($torneo['modalidad'] ?? 0);
$es_parejas = ($modalidad_torneo === 2);
$jugadores_lista_lazy = !empty($view_data['jugadores_lista_lazy']);
$etiqueta_equipo = $es_parejas ? 'Pareja' : 'Equipo';
$etiqueta_equipos = $es_parejas ? 'Parejas' : 'Equipos';

/** Base URL hacia public/api/ — obligatoria para buscar_jugador, obtener_equipo, eliminar_equipo */
$api_base_path = (function_exists('AppHelpers') ? AppHelpers::getPublicPath() : '/mistorneos_fvd/public/') . 'api/';

// Determinar si el torneo ya inició (tiene rondas)
$torneo_iniciado = false;
if (!empty($torneo['id'])) {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT MAX(CAST(partida AS UNSIGNED)) AS ultima_ronda FROM partiresul WHERE id_torneo = ? AND mesa > 0");
        $stmt->execute([(int)$torneo['id']]);
        $ultima_ronda = (int)($stmt->fetchColumn() ?? 0);
        // Equipos: bloquear desde la primera ronda
        $torneo_iniciado = $ultima_ronda >= 1;
    } catch (Exception $e) {
        $torneo_iniciado = false;
    }
}

$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';

// Guardado vía index.php / admin_torneo (misma sesión; no depender de guardar_equipo.php en /api/)
$api_guardar_equipo = $base_url . ($use_standalone ? '?' : '&') . 'action=guardar_equipo_sitio&torneo_id=' . (int)($torneo['id'] ?? 0);
?>
<!-- inscribir_equipo_sitio: POST interno action=guardar_equipo_sitio (no public/api) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
    body {
        background-color: #f8f9fa;
    }
    /* Formulario en columna izquierda; lista disponibles debajo */
    .page-inscripcion-sitio {
        box-sizing: border-box;
        padding: 0.35rem 0.5rem !important;
    }
    .page-inscripcion-sitio .breadcrumb { margin-bottom: 0.25rem !important; padding: 0.25rem 0; font-size: 0.8rem; }
    .page-inscripcion-sitio .card.mb-4:first-of-type { margin-bottom: 0.35rem !important; }
    .page-inscripcion-sitio .card.mb-4:first-of-type .card-body { padding: 0.5rem 0.75rem !important; }
    .page-inscripcion-sitio .card.mb-4:first-of-type h2 { font-size: 1rem !important; margin-bottom: 0 !important; }
    .page-inscripcion-sitio .row.row-inscripcion-dos-columnas {
        margin-left: 0;
        margin-right: 0;
        align-items: stretch;
        min-height: 280px;
    }
    .page-inscripcion-sitio .row.row-inscripcion-dos-columnas > [class^="col-"] {
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    .page-inscripcion-sitio .col-disponibles #bloqueFormularioInscripcion {
        flex-shrink: 0;
        min-width: 0;
    }
    .page-inscripcion-sitio .col-disponibles > .card-panel-disponibles,
    .page-inscripcion-sitio .col-insc-equipos > .card {
        flex: 1 1 auto;
        min-height: 200px;
        max-height: min(50vh, 560px);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .page-inscripcion-sitio .col-disponibles .card-body.p-0,
    .page-inscripcion-sitio .equipo-sidebar-card .card-body {
        flex: 1 1 0;
        min-height: 0;
        overflow-y: auto;
        max-height: none !important;
    }
    .jugador-item {
        padding: 8px 12px;
        border-bottom: 1px solid #e9ecef;
        transition: background-color 0.2s;
        cursor: pointer;
    }
    .jugador-item:hover {
        background-color: #e9ecef;
    }
    .jugador-item.selected {
        background-color: #cfe2ff;
        border-left: 3px solid #0d6efd;
    }
    .page-inscripcion-sitio .search-box {
        position: sticky;
        top: 0;
        background: white;
        padding: 0.35rem 0.5rem;
        border-bottom: 1px solid #e9ecef;
        z-index: 10;
        flex-shrink: 0;
    }
    .page-inscripcion-sitio .separador-jugador,
    .card-formulario-inscripcion .separador-jugador {
        border-top: 1px dashed #0d6efd;
        margin: 2px 0 !important;
        opacity: 0.45;
    }
    .equipo-registrado-item {
        border: 1px solid #dee2e6;
        border-radius: 6px;
        background: white;
        transition: all 0.2s;
    }
    .equipo-registrado-item:hover {
        background-color: #f8f9fa;
        border-color: #0d6efd;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .equipo-registrado-item.selected {
        background-color: #e7f3ff;
        border-color: #0d6efd;
        border-width: 2px;
    }
    .equipo-registrado-item > div:first-child:hover {
        color: #0d6efd;
    }
    /* Disponibles ~52% | Inscritos ~46% (izquierda +20% frente a 43%; formulario arriba en columna izquierda) */
    .page-inscripcion-sitio .row-inscripcion-dos-columnas {
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        gap: 0.75rem;
        align-items: stretch;
    }
    @media (max-width: 991px) {
        .page-inscripcion-sitio .row-inscripcion-dos-columnas {
            flex-wrap: wrap;
        }
        .page-inscripcion-sitio .row-inscripcion-dos-columnas .col-disponibles,
        .page-inscripcion-sitio .row-inscripcion-dos-columnas .col-insc-equipos {
            flex: 0 0 100% !important;
            max-width: 100% !important;
        }
    }
    @media (min-width: 992px) {
        .page-inscripcion-sitio .row-inscripcion-dos-columnas .col-disponibles {
            flex: 0 0 calc(51.6% - 0.375rem) !important;
            max-width: calc(51.6% - 0.375rem) !important;
        }
        .page-inscripcion-sitio .row-inscripcion-dos-columnas .col-insc-equipos {
            flex: 0 0 calc(46.4% - 0.375rem) !important;
            max-width: calc(46.4% - 0.375rem) !important;
        }
    }
    .page-inscripcion-sitio .row-inscripcion-dos-columnas .col-disponibles {
        background: linear-gradient(180deg, #e8f4fc 0%, #f0f7ff 100%);
        border-radius: 0.5rem;
        padding: 0.5rem;
        min-width: 0;
    }
    .page-inscripcion-sitio .row-inscripcion-dos-columnas .col-insc-equipos {
        background: linear-gradient(180deg, #e8f5e9 0%, #f1faf1 100%);
        border-radius: 0.5rem;
        padding: 0.5rem;
        min-width: 0;
    }
    .page-inscripcion-sitio .inscripcion-stats-sobre-inscritos {
        padding-bottom: 0.4rem;
        margin-bottom: 0.35rem;
        border-bottom: 1px solid rgba(25, 135, 84, 0.22);
    }
    .card-formulario-inscripcion {
        background: linear-gradient(180deg, #e0f7fa 0%, #b2ebf2 55%, #e0f7fa 100%);
        border-color: #4dd0e1 !important;
        border-width: 2px !important;
    }
    .card-formulario-inscripcion .card-formulario-inscripcion-body {
        max-height: min(48vh, 520px);
        overflow-y: auto;
        overflow-x: hidden;
        padding: 0.45rem 0.55rem !important;
        display: flex;
        flex-direction: column;
    }
    .card-formulario-inscripcion #formEquipo {
        display: flex;
        flex-direction: column;
        min-height: 0;
        flex: 1 1 auto;
        overflow: hidden;
    }
    .card-formulario-inscripcion .formulario-equipo-dos-filas {
        flex-shrink: 0;
        margin-bottom: 0.35rem !important;
    }
    .card-formulario-inscripcion #jugadores-container {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
    }
    .col-disponibles .jugador-item,
    .col-disponibles .jugador-item .small,
    .col-disponibles .jugador-item span { font-weight: 700 !important; }
    .col-disponibles .search-box small { font-weight: 600; }
    /* Club + nombre equipo: misma línea, mismo alto */
    .fila-club-nombre-equipo {
        display: flex;
        flex-wrap: nowrap;
        align-items: flex-end;
        gap: 0.5rem;
        width: 100%;
        margin-bottom: 0.75rem;
    }
    .fila-club-nombre-equipo .campo-club,
    .fila-club-nombre-equipo .campo-nombre-equipo {
        flex: 1 1 50%;
        min-width: 0;
    }
    .fila-club-nombre-equipo .form-label { margin-bottom: 0.15rem; }
    /* Dos filas: (1) club + código + Guardar · (2) nombre + Nueva */
    .formulario-equipo-dos-filas .fila-form-equipo-1 .btn,
    .formulario-equipo-dos-filas .fila-form-equipo-2 .btn {
        white-space: nowrap;
        padding: 0.2rem 0.45rem;
        font-size: 0.75rem;
    }
    .formulario-equipo-dos-filas #wrap_codigo_equipo_barra {
        margin-bottom: 0.15rem;
    }
    .formulario-equipo-dos-filas #wrap_codigo_equipo_barra .badge {
        font-size: 0.7rem !important;
        padding: 0.2rem 0.35rem !important;
    }
    @media (max-width: 576px) {
        .formulario-equipo-dos-filas .fila-form-equipo-1 .campo-club,
        .formulario-equipo-dos-filas .fila-form-equipo-2 .campo-nombre-equipo {
            flex: 1 1 100%;
        }
        .formulario-equipo-dos-filas .fila-form-equipo-1,
        .formulario-equipo-dos-filas .fila-form-equipo-2 {
            justify-content: flex-end;
        }
    }
    .card-formulario-inscripcion #club_id.form-select,
    .card-formulario-inscripcion #nombre_equipo.form-control {
        min-height: 2.35rem !important;
        height: 2.35rem !important;
        padding: 0.4rem 0.5rem !important;
        font-size: 0.8rem !important;
        line-height: 1.3 !important;
        box-sizing: border-box !important;
    }
    .jugador-item { padding: 4px 8px !important; font-size: 0.8rem; line-height: 1.25; }
    .fila-jugador-compacta {
        margin-bottom: 0.35rem !important;
        align-items: center !important;
        min-height: 0;
    }
    /* Controles compactos (no club/nombre ni filas jugador: tienen reglas propias) */
    .card-formulario-inscripcion .form-control-sm:not(.jugador-id-usuario):not(.jugador-cedula):not(.jugador-nombre),
    .card-formulario-inscripcion .form-select-sm:not(#club_id) {
        padding: 0.08rem 0.28rem !important;
        font-size: 0.7rem !important;
        line-height: 1.05 !important;
        min-height: calc(0.72em + 0.16rem) !important;
    }
    /* Filas jugador compactas para caber en viewport sin scroll global */
    .card-formulario-inscripcion .fila-jugador-compacta {
        margin-bottom: 0.15rem !important;
    }
    .card-formulario-inscripcion .fila-jugador-compacta .jugador-id-usuario,
    .card-formulario-inscripcion .fila-jugador-compacta .jugador-cedula,
    .card-formulario-inscripcion .fila-jugador-compacta .jugador-nombre {
        padding: 0.1rem 0.25rem !important;
        font-size: 0.72rem !important;
        line-height: 1.15 !important;
        min-height: 1.5rem !important;
        box-sizing: border-box !important;
    }
    .fila-jugador-compacta .wrap-inputs-jugador {
        display: flex !important;
        flex: 0 0 80% !important;
        width: 80% !important;
        max-width: 80% !important;
        min-width: 0;
        align-items: center;
        gap: 0.12rem;
    }
    .fila-jugador-compacta.row {
        flex-wrap: nowrap;
    }
    @media (max-width: 576px) {
        .fila-jugador-compacta.row { flex-wrap: wrap; }
        .fila-jugador-compacta .wrap-inputs-jugador {
            flex: 0 0 100% !important;
            width: 100% !important;
            max-width: 100% !important;
        }
    }
    .fila-jugador-compacta .input-id-usuario {
        flex: 4.608 1 0;
        min-width: 0;
        max-width: none;
    }
    .fila-jugador-compacta .input-cedula {
        flex: 6.656 1 0;
        min-width: 0;
        max-width: none;
    }
    .fila-jugador-compacta .input-nombre-jug {
        flex: 15.616 1 0;
        min-width: 0;
        max-width: none;
    }
    /* Parejas: una fila visual por pareja de mesa (2 integrantes); sin línea separadora entre ellos dentro del bloque */
    .fila-pareja-bloque {
        margin-bottom: 0.35rem;
        padding-bottom: 0.25rem;
        border-bottom: 1px dashed rgba(25, 135, 84, 0.28);
    }
    .fila-pareja-bloque:last-of-type {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    .fila-pareja-bloque .pareja-integrante-card {
        background: rgba(255, 255, 255, 0.6);
        border: 1px solid rgba(25, 135, 84, 0.2);
        border-radius: 0.4rem;
        padding: 0.35rem 0.45rem;
        height: 100%;
    }
    .fila-pareja-bloque .fila-jugador-compacta .wrap-inputs-jugador {
        flex: 1 1 auto !important;
        width: auto !important;
        max-width: none !important;
    }
    .fila-pareja-bloque .fila-jugador-compacta.row,
    .fila-pareja-bloque .fila-jugador-compacta.d-flex {
        flex-wrap: nowrap;
    }
    @media (max-width: 767px) {
        .fila-pareja-bloque .fila-jugador-compacta.d-flex {
            flex-wrap: wrap;
        }
    }
    .equipo-sidebar-item {
        border: 1px solid #dee2e6;
        border-radius: 6px;
        margin-bottom: 0.5rem;
        background: #fff;
    }
    .equipo-sidebar-header {
        padding: 0.4rem 0.5rem;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 700;
    }
    .equipo-sidebar-header:hover { background: #f8f9fa; }
    .equipo-sidebar-header .btn { cursor: pointer; font-weight: 600; }
    .equipo-sidebar-integrantes {
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0 0.5rem 0.4rem;
        border-top: 1px dashed #e9ecef;
    }
    .equipo-sidebar-integrantes li { padding: 0.15rem 0; font-weight: 700; }
    #wrap_codigo_equipo_barra { min-height: 1.5rem; }
    .btn-editar-equipo-form { font-size: 0.7rem; padding: 0.1rem 0.35rem; }
</style>

<div class="container-fluid py-4 page-inscripcion-sitio">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo $base_url; ?>">Gestión de Torneos</a></li>
            <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
            <li class="breadcrumb-item active">Inscribir en Sitio</li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h2 class="h4 mb-0">
                        <i class="fas fa-user-plus text-warning me-2"></i>Inscribir <?php echo $etiqueta_equipo; ?> en Sitio
                    </h2>
                </div>
                <div class="mt-2 mt-md-0 d-flex flex-wrap gap-2 align-items-center justify-content-end">
                    <button type="button" class="btn btn-warning text-dark" id="btnAbrirFormularioInscripcion"
                            aria-controls="collapseFormInscripcion"
                            title="Abrir el formulario de inscripción">
                        <i class="fas fa-edit me-1"></i>Inscribir <?php echo htmlspecialchars($etiqueta_equipo); ?>
                    </button>
                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Retornar al Panel
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Dos columnas: disponibles | inscritos -->
    <div class="row g-2 g-lg-3 row-inscripcion-dos-columnas">
        <div class="col-12 col-disponibles">
            <!-- Formulario compacto (columna izquierda, encima de atletas disponibles) -->
            <div id="bloqueFormularioInscripcion" class="mb-2">
            <div class="card border-info shadow-sm card-formulario-inscripcion" role="region" aria-label="Inscripción de <?php echo htmlspecialchars($etiqueta_equipo, ENT_QUOTES, 'UTF-8'); ?> en sitio">
                <div id="collapseFormInscripcion" class="collapse show">
                    <div class="card-body card-formulario-inscripcion-body">
                    <?php if ($torneo_iniciado): ?>
                        <div class="alert alert-warning py-1 px-2 mb-2 small">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            El torneo ya inició (hay rondas generadas). No se permiten nuevas inscripciones de <?php echo strtolower($etiqueta_equipos); ?>. Solo información de control.
                        </div>
                    <?php endif; ?>
                    <form id="formEquipo">
                        <?php require_once __DIR__ . '/../../config/csrf.php'; ?>
                        <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                        <input type="hidden" id="equipo_id" name="equipo_id" value="">
                        <input type="hidden" id="torneo_id" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                        <input type="hidden" id="codigo_club_prefijo" name="codigo_club_prefijo" value="">
                        <input type="hidden" id="codigo_equipo" name="codigo_equipo" value="">

                        <!-- Fila 1: club + código + Guardar · Fila 2: nombre + Nueva -->
                        <div class="formulario-equipo-dos-filas">
                            <div class="fila-form-equipo-1 d-flex flex-wrap align-items-end gap-2">
                                <div class="campo-club flex-grow-1 min-w-0">
                                    <label class="form-label small mb-0" for="club_id">Club *</label>
                                    <select id="club_id" name="club_id" class="form-select form-select-sm w-100" required>
                                        <option value="">Club *</option>
                                        <?php if (!empty($clubes_disponibles)): ?>
                                            <?php foreach ($clubes_disponibles as $club): ?>
                                                <option value="<?php echo $club['id']; ?>" data-codigo-prefijo="<?php echo htmlspecialchars((string)($club['codigo_prefijo'] ?? $club['id']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($club['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No hay clubes disponibles</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div id="wrap_codigo_equipo_barra" class="d-flex align-items-center gap-2 flex-shrink-0" style="visibility:hidden;" aria-hidden="true">
                                    <span class="small fw-bold mb-0" style="color:#006064;">Cód.</span>
                                    <span id="codigo_equipo_visible" class="badge bg-secondary fs-6 px-2 py-1"></span>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm py-1 flex-shrink-0" id="btnGuardarEquipo" <?= $torneo_iniciado ? 'disabled' : '' ?>>
                                    <i class="fas fa-save me-1"></i>Guardar <?php echo $etiqueta_equipo; ?>
                                </button>
                            </div>
                            <div class="fila-form-equipo-2 d-flex flex-wrap align-items-end gap-2 mt-1">
                                <div class="campo-nombre-equipo flex-grow-1 min-w-0">
                                    <label class="form-label small mb-0" for="nombre_equipo">Nombre de la <?php echo strtolower($etiqueta_equipo); ?><?php echo $es_parejas ? ' (opcional)' : ' *'; ?></label>
                                    <input type="text"
                                           id="nombre_equipo"
                                           name="nombre_equipo"
                                           class="form-control form-control-sm w-100"
                                           <?php echo $es_parejas ? '' : 'required '; ?>
                                           placeholder="<?php echo $es_parejas ? 'Opcional (sin nombre)' : 'Nombre del ' . $etiqueta_equipo . ' *'; ?>">
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm py-1 flex-shrink-0" onclick="limpiarFormulario()" <?= $torneo_iniciado ? 'disabled' : '' ?>>
                                    <i class="fas fa-redo me-1"></i>Nueva <?php echo $etiqueta_equipo; ?>
                                </button>
                            </div>
                        </div>
                        <?php if (empty($clubes_disponibles) && !empty($is_admin_club ?? false)): ?>
                            <small class="text-muted d-block mb-2">
                                <a href="<?php echo (function_exists('AppHelpers') ? AppHelpers::dashboard('clubes_asociados') : 'index.php?page=clubes_asociados'); ?>">Registrar asociación</a> en Asociaciones de la organización
                            </small>
                        <?php endif; ?>

                        <hr class="my-1">

                            <div id="jugadores-container">
                                <?php if ($es_parejas): ?>
                                <?php
                                $num_parejas_mesa = (int) max(1, ceil($jugadores_por_equipo / 2));
                                ?>
                                <p class="small text-muted mb-2"><i class="fas fa-info-circle me-1 text-success"></i><strong><?php echo (int) $jugadores_por_equipo; ?> jugadores</strong> (como siempre en el servidor): se muestran en <strong><?php echo $num_parejas_mesa; ?> fila(s)</strong>, una por pareja de mesa (jugadores 1–2, 3–4, …). Se quitó la línea separadora <em>entre</em> los dos integrantes de la misma pareja. <strong>Club</strong> y <strong>nombre del equipo</strong> son comunes a todos. Cédula de cada uno (blur → buscar).</p>
                                <?php
                                for ($p = 0; $p < $num_parejas_mesa; $p++) {
                                    $i1 = $p * 2 + 1;
                                    $i2 = min($i1 + 1, $jugadores_por_equipo);
                                    if ($i1 > $jugadores_por_equipo) {
                                        break;
                                    }
                                    $dos_en_pareja = ($i1 < $i2);
                                    ?>
                                <div class="row g-2 fila-pareja-bloque align-items-stretch">
                                    <div class="col-12 d-flex align-items-center gap-2 flex-wrap pb-1">
                                        <span class="badge bg-success">Pareja <?php echo $p + 1; ?></span>
                                        <span class="small text-muted mb-0">Jugadores <?php echo $i1; ?><?php echo $dos_en_pareja ? ' y ' . $i2 : ''; ?> · mismo registro de equipo para ambos</span>
                                    </div>
                                    <?php for ($i = $i1; $i <= $i2; $i++) : ?>
                                    <div class="col-12 <?php echo $dos_en_pareja ? 'col-md-6' : ''; ?>">
                                        <div class="pareja-integrante-card">
                                            <div class="d-flex align-items-center justify-content-between mb-1 flex-wrap gap-1">
                                                <span class="small fw-bold text-success mb-0">Jugador <?php echo $i; ?></span>
                                                <?php if ($i === 1) : ?>
                                                <span class="badge bg-warning text-dark" style="font-size:0.65rem;">Capitán del equipo</span>
                                                <?php endif; ?>
                                            </div>
                                            <input type="hidden"
                                                   id="es_capitan_<?php echo $i; ?>"
                                                   name="jugadores[<?php echo $i; ?>][es_capitan]"
                                                   value="<?php echo $i == 1 ? '1' : '0'; ?>">
                                            <div class="d-flex align-items-center flex-nowrap gap-1 fila-jugador-compacta w-100" data-posicion="<?php echo $i; ?>" data-jugador-asignado="">
                                                <div class="wrap-inputs-jugador flex-grow-1 min-w-0 d-flex flex-nowrap align-items-center">
                                                    <input type="text"
                                                           class="form-control form-control-sm jugador-id-usuario input-id-usuario"
                                                           id="jugador_id_usuario_<?php echo $i; ?>"
                                                           placeholder="ID"
                                                           readonly
                                                           style="background-color: #e9ecef; font-weight: bold;">
                                                    <input type="hidden"
                                                           id="jugador_id_usuario_h_<?php echo $i; ?>"
                                                           name="jugadores[<?php echo $i; ?>][id_usuario]">
                                                    <input type="text"
                                                           class="form-control form-control-sm jugador-cedula input-cedula"
                                                           id="jugador_cedula_<?php echo $i; ?>"
                                                           name="jugadores[<?php echo $i; ?>][cedula]"
                                                           placeholder="Cédula (blur → buscar)"
                                                           data-posicion="<?php echo $i; ?>"
                                                           onblur="buscarJugadorPorCedula(this)"
                                                           oninput="validarFormulario()"
                                                           style="background-color: #fff;">
                                                    <input type="hidden"
                                                           class="jugador-id-inscrito"
                                                           id="jugador_id_inscrito_<?php echo $i; ?>"
                                                           name="jugadores[<?php echo $i; ?>][id_inscrito]">
                                                    <input type="text"
                                                           class="form-control form-control-sm jugador-nombre input-nombre-jug"
                                                           id="jugador_nombre_<?php echo $i; ?>"
                                                           name="jugadores[<?php echo $i; ?>][nombre]"
                                                           placeholder="Nombre"
                                                           readonly
                                                           style="background-color: #e9ecef;"
                                                           oninput="validarFormulario()">
                                                </div>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-danger py-0 px-1 flex-shrink-0"
                                                        onclick="limpiarJugadorYDevolver(<?php echo $i; ?>)"
                                                        title="Quitar"
                                                        id="btn_limpiar_<?php echo $i; ?>"
                                                        style="display: none; font-size:0.7rem;"
                                                        disabled>
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                    <?php
                                }
                                ?>
                                <?php else: ?>
                                <?php for ($i = 1; $i <= $jugadores_por_equipo; $i++): ?>
                                    <div class="row g-1 align-items-center fila-jugador-compacta" data-posicion="<?php echo $i; ?>" data-jugador-asignado="">
                                        <div class="col-auto text-center pe-0" style="width:1.5rem;">
                                            <?php if ($i == 1): ?>
                                                <span class="badge bg-warning text-dark" style="font-size:0.65rem;">★</span>
                                            <?php else: ?>
                                                <span class="small"><?php echo $i; ?></span>
                                            <?php endif; ?>
                                            <input type="hidden"
                                                   id="es_capitan_<?php echo $i; ?>"
                                                   name="jugadores[<?php echo $i; ?>][es_capitan]"
                                                   value="<?php echo $i == 1 ? '1' : '0'; ?>">
                                        </div>
                                        <div class="col px-1 min-w-0 wrap-inputs-jugador flex-shrink-0">
                                            <input type="text"
                                                   class="form-control form-control-sm jugador-id-usuario input-id-usuario"
                                                   id="jugador_id_usuario_<?php echo $i; ?>"
                                                   placeholder="ID"
                                                   readonly
                                                   style="background-color: #e9ecef; font-weight: bold;">
                                            <input type="hidden"
                                                   id="jugador_id_usuario_h_<?php echo $i; ?>"
                                                   name="jugadores[<?php echo $i; ?>][id_usuario]">
                                            <input type="text"
                                                   class="form-control form-control-sm jugador-cedula input-cedula"
                                                   id="jugador_cedula_<?php echo $i; ?>"
                                                   name="jugadores[<?php echo $i; ?>][cedula]"
                                                   placeholder="Cédula (blur o Enter)"
                                                   data-posicion="<?php echo $i; ?>"
                                                   onblur="buscarJugadorPorCedula(this)"
                                                   oninput="validarFormulario()"
                                                   style="background-color: #fff;">
                                            <input type="hidden"
                                                   class="jugador-id-inscrito"
                                                   id="jugador_id_inscrito_<?php echo $i; ?>"
                                                   name="jugadores[<?php echo $i; ?>][id_inscrito]">
                                            <input type="text"
                                                   class="form-control form-control-sm jugador-nombre input-nombre-jug"
                                                   id="jugador_nombre_<?php echo $i; ?>"
                                                   name="jugadores[<?php echo $i; ?>][nombre]"
                                                   placeholder="Nombre"
                                                   readonly
                                                   style="background-color: #e9ecef;"
                                                   oninput="validarFormulario()">
                                        </div>
                                        <div class="col-auto ps-0">
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger py-0 px-1"
                                                    onclick="limpiarJugadorYDevolver(<?php echo $i; ?>)"
                                                    title="Quitar"
                                                    id="btn_limpiar_<?php echo $i; ?>"
                                                    style="display: none; font-size:0.7rem;"
                                                    disabled>
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php if ($i < $jugadores_por_equipo): ?>
                                        <div class="separador-jugador mb-1" style="margin-top:0;"></div>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php endif; ?>
                            </div>
                    </form>
                    </div>
                </div>
            </div>
            </div>

            <div class="card border-0 shadow-sm h-100 d-flex flex-column overflow-hidden card-panel-disponibles">
                <div class="card-header bg-primary text-white py-2">
                    <h6 class="mb-0 small">
                        <i class="fas fa-user-friends me-1"></i><?php echo $es_parejas ? 'Atletas de su entidad' : 'Disponibles'; ?>
                    </h6>
                </div>

                <!-- Buscador: parejas = solo lista + cédula en fila (blur); equipos = lista o lazy con botón -->
                <div class="search-box">
                    <?php if ($es_parejas): ?>
                        <small class="text-muted d-block">Atletas de su entidad. Seleccione club y escriba cédula en la fila del jugador; al salir del campo o Enter se busca automáticamente.</small>
                        <input type="text" id="searchJugadores" class="form-control form-control-sm mt-1 d-none" disabled aria-hidden="true">
                    <?php elseif ($jugadores_lista_lazy): ?>
                        <label class="form-label small mb-1 fw-semibold" for="buscarCedulaLazy">Buscar por cédula (añadir a disponibles)</label>
                        <div class="input-group input-group-sm mb-1">
                            <input type="text"
                                   id="buscarCedulaLazy"
                                   class="form-control"
                                   placeholder="Cédula del jugador"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   aria-describedby="hintLazyCedula">
                            <button type="button" class="btn btn-primary app-search-submit-hidden" id="btnBuscarCedulaLazy" title="Consultar y añadir a la lista" tabindex="-1" aria-hidden="true">
                                <i class="fas fa-plus"></i> Añadir
                            </button>
                        </div>
                        <small id="hintLazyCedula" class="text-muted d-block">1) Club y nombre del equipo. 2) Escriba la cédula y salga del campo para consultar; el jugador aparece abajo para asignar.</small>
                        <input type="text" id="searchJugadores" class="form-control form-control-sm mt-1 d-none" disabled aria-hidden="true">
                    <?php else: ?>
                    <input type="text"
                           id="searchJugadores"
                           class="form-control"
                           placeholder="Buscar por ID, cédula o nombre..."
                           disabled>
                    <small class="text-muted">Seleccione el Club y Nombre del <?php echo $etiqueta_equipo; ?> para habilitar</small>
                    <?php endif; ?>
                </div>

                <!-- Lista de Jugadores: parejas = siempre lista de entidad; equipos = lista o lazy -->
                <div class="card-body p-0" style="flex:1;min-height:0;overflow-y:auto;">
                    <?php if (!$es_parejas && $jugadores_lista_lazy): ?>
                        <div class="small text-muted px-2 py-1 border-bottom bg-white fw-bold" style="font-size:0.7rem;">Disponibles (búsqueda por cédula)</div>
                        <div id="listaJugadores"></div>
                    <?php elseif (empty($jugadores_disponibles)): ?>
                        <div class="text-center py-3 text-muted small">
                            <i class="fas fa-user-slash fa-2x mb-2 opacity-50"></i>
                            <p class="mb-0 small">Sin disponibles</p>
                        </div>
                    <?php else: ?>
                        <div class="small text-muted px-2 py-1 border-bottom bg-light fw-bold" style="font-size:0.7rem;">ID | Céd. | Nombre</div>
                        <div id="listaJugadores">
                            <?php foreach ($jugadores_disponibles as $jugador): ?>
                                <div class="jugador-item <?= $torneo_iniciado ? 'disabled' : '' ?>" 
                                     data-nombre="<?php echo strtolower(htmlspecialchars($jugador['nombre'] ?? '')); ?>"
                                     data-cedula="<?php echo htmlspecialchars($jugador['cedula'] ?? ''); ?>"
                                     data-id-usuario="<?php echo $jugador['id_usuario'] ?? ''; ?>"
                                     data-id="<?php echo $jugador['id'] ?? ''; ?>"
                                     data-jugador='<?php echo htmlspecialchars(json_encode($jugador, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>'
                                     <?php if (!$torneo_iniciado): ?>
                                     onclick="seleccionarJugador(this)"
                                     <?php endif; ?>
                                     style="cursor: <?= $torneo_iniciado ? 'not-allowed' : 'pointer' ?>;">
                                    <div class="small">
                                        <span class="text-muted fw-bold"><?php echo htmlspecialchars($jugador['id_usuario'] ?? '-'); ?></span>
                                        <span class="mx-1">|</span>
                                        <span class="text-muted"><?php echo htmlspecialchars($jugador['cedula'] ?? 'Sin cédula'); ?></span>
                                        <span class="mx-1">|</span>
                                        <span class="text-dark"><?php echo htmlspecialchars($jugador['nombre'] ?? 'Sin nombre'); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Inscritos -->
        <div class="col-12 col-insc-equipos">
            <div class="inscripcion-stats-sobre-inscritos">
                <?php
                $torneo_inscripcion_badges_group_class = 'mb-0';
                require __DIR__ . '/../../resources/views/partials/torneo_inscripcion_badges_bs5.php';
                unset($torneo_inscripcion_badges_group_class);
                ?>
            </div>
            <div class="card border-0 shadow-sm equipo-sidebar-card h-100">
                <div class="card-header bg-success text-white py-2">
                    <h6 class="mb-0">
                        <i class="fas fa-users me-1"></i><?php echo $etiqueta_equipos; ?> inscritos (<?php echo count($equipos_registrados); ?>)
                    </h6>
                    <small class="opacity-75 fw-bold">Clic en la fila: mostrar / ocultar integrantes · «Editar» carga el formulario</small>
                </div>
                <div class="card-body p-2">
                    <?php if (empty($equipos_registrados)): ?>
                        <div class="text-center py-3 text-muted small">
                            <i class="fas fa-users-slash fa-2x mb-2 opacity-50"></i>
                            <p class="mb-0">Aún no hay <?php echo strtolower($etiqueta_equipos); ?></p>
                        </div>
                    <?php else: ?>
                        <div id="listaEquiposRegistrados">
                            <?php foreach ($equipos_registrados as $equipo):
                                $eid = (int)$equipo['id'];
                                $jugEq = $equipo['jugadores'] ?? [];
                                $collapseId = 'int-equipo-' . $eid;
                            ?>
                            <div class="equipo-sidebar-item equipo-registrado-item" data-equipo-id="<?php echo $eid; ?>">
                                <div class="equipo-sidebar-header d-flex align-items-center justify-content-between gap-1 flex-wrap"
                                     role="button" tabindex="0"
                                     data-collapse-target="<?php echo htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8'); ?>"
                                     aria-expanded="false" aria-controls="<?php echo $collapseId; ?>">
                                    <div class="flex-grow-1 min-w-0">
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($equipo['codigo_equipo']); ?></span>
                                        <span class="fw-semibold text-primary"><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></span>
                                        <div class="text-muted" style="font-size:0.72rem;"><?php echo htmlspecialchars($equipo['nombre_club'] ?? ''); ?></div>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-editar-equipo-form"
                                                onclick="event.stopPropagation(); cargarEquipo(<?php echo $eid; ?>); window.enfocarPanelFormularioFlotante();"
                                                title="Cargar en formulario para editar">Editar</button>
                                        <span class="btn btn-sm btn-outline-secondary py-0 px-1 mb-0 integrantes-chevron" title="Mostrar/ocultar integrantes">
                                            <i class="fas fa-chevron-down small"></i>
                                        </span>
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                                onclick="event.stopPropagation(); eliminarEquipo(<?php echo $eid; ?>, '<?php echo htmlspecialchars($equipo['nombre_equipo'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="collapse integrantes-collapse" id="<?php echo $collapseId; ?>">
                                    <ul class="list-unstyled mb-0 equipo-sidebar-integrantes">
                                        <?php if (empty($jugEq)): ?>
                                            <li class="text-muted">Sin jugadores en lista</li>
                                        <?php else: ?>
                                            <?php foreach ($jugEq as $j): ?>
                                                <li>
                                                    <span class="text-muted"><?php echo htmlspecialchars($j['cedula'] ?? ''); ?></span>
                                                    — <?php echo htmlspecialchars($j['nombre'] ?? ''); ?>
                                                    <span class="badge bg-light text-dark" style="font-size:0.65rem;">#<?php echo (int)($j['id_usuario'] ?? 0); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
<script>
window.enfocarPanelFormularioFlotante = function () {
    var col = document.getElementById('collapseFormInscripcion');
    var bloque = document.getElementById('bloqueFormularioInscripcion');
    if (col && typeof bootstrap !== 'undefined') {
        try {
            bootstrap.Collapse.getOrCreateInstance(col, { toggle: false }).show();
        } catch (e0) {}
    }
    if (bloque) {
        try {
            bloque.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {}
    }
    requestAnimationFrame(function () {
        try {
            var club = document.getElementById('club_id');
            if (club && !club.disabled) {
                club.focus({ preventScroll: true });
            }
        } catch (e2) {}
    });
};
const JUGADORES_POR_EQUIPO = <?php echo $jugadores_por_equipo; ?>;
const ES_PAREJAS = <?php echo $es_parejas ? 'true' : 'false'; ?>;
const TORNEO_ID = <?php echo $torneo['id']; ?>;
const JUGADORES_LISTA_LAZY = <?php echo $jugadores_lista_lazy ? 'true' : 'false'; ?>;

function fetchJsonBuscarJugador(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json, text/plain, */*' } });
}
/** Parsea JSON y lanza si HTTP error (evita asumir 200 con body HTML). */
async function fetchJsonBuscarJugadorParse(url) {
    const response = await fetchJsonBuscarJugador(url);
    const text = await response.text();
    let data = {};
    try {
        data = text ? JSON.parse(text) : {};
    } catch (e) {
        if (!response.ok) {
            throw new Error('Error del servidor (' + response.status + ')');
        }
        throw new Error('Respuesta no válida');
    }
    if (!response.ok) {
        throw new Error(data.message || ('Error HTTP ' + response.status));
    }
    return data;
}
/** Datos para editar: todo viene del servidor al cargar la página — sin fetch a obtener_equipo */
const EQUIPOS_EDITAR = <?php
$map = [];
foreach ($equipos_registrados as $eq) {
    $id = (int)($eq['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $map[(string)$id] = [
        'id' => $id,
        'codigo_equipo' => $eq['codigo_equipo'] ?? '',
        'nombre_equipo' => $eq['nombre_equipo'] ?? '',
        'id_club' => (int)($eq['id_club'] ?? 0),
        'club_nombre' => $eq['nombre_club'] ?? 'Sin Club',
        'jugadores' => array_values(array_map(static function ($j) {
            return [
                'id_inscrito' => (int)($j['id_inscrito'] ?? 0),
                'id_usuario' => (int)($j['id_usuario'] ?? 0),
                'cedula' => (string)($j['cedula'] ?? ''),
                'nombre' => (string)($j['nombre'] ?? ''),
            ];
        }, $eq['jugadores'] ?? [])),
    ];
}
echo json_encode($map, JSON_UNESCAPED_UNICODE);
?>;

// Validar formulario al cargar
document.addEventListener('DOMContentLoaded', function() {
    validarFormulario();
    actualizarBloqueoSeleccionJugadores();
    document.querySelectorAll('.jugador-cedula').forEach(function (el) {
        el.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();
            buscarJugadorPorCedula(el);
        });
    });
    var cedulaBuscarParejas = document.getElementById('cedula_buscar_parejas');
    if (cedulaBuscarParejas) {
        cedulaBuscarParejas.addEventListener('blur', buscarCedulaParejasGlobal);
    }
    /* Integrantes: despliegue/repliegue manual (evita fallos del toggle en cabecera con botones) */
    (function initToggleIntegrantes() {
        var lista = document.getElementById('listaEquiposRegistrados');
        if (!lista || typeof bootstrap === 'undefined') return;
        function chevron(header, abajo) {
            var i = header && header.querySelector('.integrantes-chevron i');
            if (!i) return;
            i.classList.remove('fa-chevron-down', 'fa-chevron-up');
            i.classList.add(abajo ? 'fa-chevron-down' : 'fa-chevron-up');
        }
        function syncAria(header, collapseEl) {
            var open = collapseEl.classList.contains('show');
            header.setAttribute('aria-expanded', open ? 'true' : 'false');
            chevron(header, !open);
        }
        lista.querySelectorAll('.integrantes-collapse').forEach(function (collapseEl) {
            var header = collapseEl.previousElementSibling;
            if (!header || !header.classList.contains('equipo-sidebar-header')) return;
            collapseEl.addEventListener('shown.bs.collapse', function () { syncAria(header, collapseEl); });
            collapseEl.addEventListener('hidden.bs.collapse', function () { syncAria(header, collapseEl); });
        });
        lista.addEventListener('click', function (e) {
            if (e.target.closest('.btn-editar-equipo-form')) return;
            if (e.target.closest('button.btn-outline-danger')) return;
            var header = e.target.closest('.equipo-sidebar-header');
            if (!header || !lista.contains(header)) return;
            var id = header.getAttribute('data-collapse-target');
            if (!id) return;
            var collapseEl = document.getElementById(id);
            if (!collapseEl) return;
            var inst = bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false });
            inst.toggle();
        });
        lista.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var header = e.target.closest('.equipo-sidebar-header');
            if (!header || e.target.closest('button')) return;
            e.preventDefault();
            var id = header.getAttribute('data-collapse-target');
            var collapseEl = id && document.getElementById(id);
            if (collapseEl) bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false }).toggle();
        });
    })();
    document.getElementById('nombre_equipo').addEventListener('input', () => {
        validarFormulario();
        actualizarBloqueoSeleccionJugadores();
    });
    function syncCodigoClubPrefijo() {
        var sel = document.getElementById('club_id');
        var hid = document.getElementById('codigo_club_prefijo');
        if (!sel || !hid) return;
        var opt = sel.options[sel.selectedIndex];
        hid.value = opt ? (opt.getAttribute('data-codigo-prefijo') || '') : '';
    }
    syncCodigoClubPrefijo();
    document.getElementById('club_id').addEventListener('change', () => {
        syncCodigoClubPrefijo();
        validarFormulario();
        actualizarBloqueoSeleccionJugadores();
    });
    var collapseForm = document.getElementById('collapseFormInscripcion');
    var btnToggleForm = document.getElementById('btnToggleFormFlotante');
    if (collapseForm && btnToggleForm) {
        collapseForm.addEventListener('shown.bs.collapse', function () {
            btnToggleForm.setAttribute('aria-expanded', 'true');
            var ic = btnToggleForm.querySelector('i');
            if (ic) ic.className = 'fas fa-chevron-up';
        });
        collapseForm.addEventListener('hidden.bs.collapse', function () {
            btnToggleForm.setAttribute('aria-expanded', 'false');
            var ic = btnToggleForm.querySelector('i');
            if (ic) ic.className = 'fas fa-chevron-down';
        });
    }
    var btnAbrirForm = document.getElementById('btnAbrirFormularioInscripcion');
    if (btnAbrirForm) {
        btnAbrirForm.addEventListener('click', function (ev) {
            ev.preventDefault();
            window.enfocarPanelFormularioFlotante();
        });
    }
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('abrir_form') === '1' || params.get('abrir_form') === 'true') {
            window.enfocarPanelFormularioFlotante();
        }
    } catch (e3) {}
});

// Búsqueda en tiempo real
document.getElementById('searchJugadores')?.addEventListener('input', function(e) {
    if (!puedeSeleccionarJugadores()) {
        e.target.value = '';
        return;
    }
    const searchTerm = e.target.value.toLowerCase();
    const items = document.querySelectorAll('.jugador-item');
    
    items.forEach(item => {
        const nombre = item.getAttribute('data-nombre') || '';
        const cedula = item.getAttribute('data-cedula') || '';
        const idUsuario = (item.getAttribute('data-id-usuario') || '').toString();
        
        if (nombre.includes(searchTerm) || cedula.includes(searchTerm) || idUsuario.includes(searchTerm)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
});

// Seleccionar jugador desde la lista
function seleccionarJugador(element) {
    if (!puedeSeleccionarJugadores()) {
        Swal.fire({
            icon: 'warning',
            title: 'Atención',
            text: ES_PAREJAS ? 'Primero seleccione el Club.' : 'Primero seleccione el Club y el Nombre del Equipo.',
            confirmButtonColor: '#3b82f6'
        });
        return;
    }
    const jugadorData = JSON.parse(element.getAttribute('data-jugador'));
    
    // Verificar que no esté jugando (ya tiene codigo_equipo)
    if (jugadorData.codigo_equipo) {
        Swal.fire({
            icon: 'warning',
            title: 'Jugador no disponible',
            text: 'Este jugador ya está asignado a un ' + (ES_PAREJAS ? 'pareja' : 'equipo') + ' (código: ' + jugadorData.codigo_equipo + ')',
            confirmButtonColor: '#3b82f6'
        });
        return;
    }
    
    // Buscar primera posición vacía
    for (let i = 1; i <= JUGADORES_POR_EQUIPO; i++) {
        const cedula = document.getElementById(`jugador_cedula_${i}`).value.trim();
        if (!cedula) {
            asignarJugadorAPosicion(i, jugadorData);
            element.remove();
            actualizarContadorDisponibles();
            return;
        }
    }
    
    Swal.fire({
        icon: 'info',
        title: 'Posiciones completas',
        text: 'Todas las posiciones están ocupadas. Use el botón X para quitar un jugador.',
        confirmButtonColor: '#3b82f6'
    });
}

// Asignar jugador a una posición
function asignarJugadorAPosicion(posicion, jugador) {
    const idInscritoEl = document.getElementById(`jugador_id_inscrito_${posicion}`);
    if (idInscritoEl) idInscritoEl.value = jugador.id_inscrito || jugador.id || '';
    
    const idUsuarioEl = document.getElementById(`jugador_id_usuario_${posicion}`);
    const idUsuarioHEl = document.getElementById(`jugador_id_usuario_h_${posicion}`);
    const idUsuario = jugador.id_usuario || '';
    if (idUsuarioEl) idUsuarioEl.value = idUsuario;
    if (idUsuarioHEl) idUsuarioHEl.value = idUsuario;
    
    document.getElementById(`jugador_cedula_${posicion}`).value = jugador.cedula || '';
    document.getElementById(`jugador_nombre_${posicion}`).value = jugador.nombre || '';
    
    const fila = document.querySelector(`[data-posicion="${posicion}"]`);
    if (fila) {
        fila.setAttribute('data-jugador-asignado', JSON.stringify(jugador));
        const btnLimpiar = document.getElementById(`btn_limpiar_${posicion}`);
        if (btnLimpiar) btnLimpiar.style.display = 'inline-block';
    }
    
    validarFormulario();
    actualizarBloqueoSeleccionJugadores();
}

// Limpiar jugador y devolverlo al listado
async function limpiarJugadorYDevolver(posicion) {
    const fila = document.querySelector(`[data-posicion="${posicion}"]`);
    const jugadorDataStr = fila ? fila.getAttribute('data-jugador-asignado') : null;
    
    // Obtener nombre del jugador para mostrar en la confirmación
    const nombreJugador = document.getElementById(`jugador_nombre_${posicion}`)?.value || '';
    const cedulaJugador = document.getElementById(`jugador_cedula_${posicion}`)?.value || '';
    const jugadorTexto = nombreJugador ? `"${nombreJugador}"` : (cedulaJugador ? `con cédula ${cedulaJugador}` : 'este jugador');
    
    // Confirmar antes de retirar
    const result = await Swal.fire({
        icon: 'question',
        title: '¿Retirar jugador?',
        html: ES_PAREJAS
            ? `¿Retirar ${jugadorTexto} de esta pareja?<br><br>Podrá asignarlo de nuevo desde disponibles.`
            : `¿Está seguro de retirar ${jugadorTexto} del equipo?<br><br>El jugador quedará disponible para asignarlo a otra posición.`,
        showCancelButton: true,
        confirmButtonText: 'Sí, retirar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6c757d',
        reverseButtons: true
    });
    
    // Si el usuario cancela, no hacer nada
    if (!result.isConfirmed) {
        return;
    }
    
    // Ejecutar la acción de retirar
    limpiarJugador(posicion);
    
    if (jugadorDataStr) {
        try {
            const jugador = JSON.parse(jugadorDataStr);
            devolverJugadorAListado(jugador);
            fila.setAttribute('data-jugador-asignado', '');
        } catch (e) {
            console.error('Error al parsear jugador:', e);
        }
    }
    
    const btnLimpiar = document.getElementById(`btn_limpiar_${posicion}`);
    if (btnLimpiar) btnLimpiar.style.display = 'none';
    
    // Mostrar mensaje de confirmación de éxito
    Swal.fire({
        icon: 'success',
        title: 'Jugador retirado',
        text: 'El jugador ha sido retirado del equipo y está disponible para asignación.',
        confirmButtonColor: '#10b981',
        timer: 2000,
        timerProgressBar: true
    });
}

// Devolver jugador al listado
function devolverJugadorAListado(jugador) {
    const listaJugadores = document.getElementById('listaJugadores');
    if (!listaJugadores) return;
    
    const ready = puedeSeleccionarJugadores();
    const jugadorHtml = `
        <div class="jugador-item" 
             data-nombre="${(jugador.nombre || '').toLowerCase()}"
             data-cedula="${jugador.cedula || ''}"
             data-id-usuario="${jugador.id_usuario || ''}"
             data-id="${jugador.id || ''}"
             data-jugador='${JSON.stringify(jugador).replace(/'/g, "&#39;")}'
             onclick="seleccionarJugador(this)"
             style="cursor: ${ready ? 'pointer' : 'not-allowed'}; pointer-events: ${ready ? 'auto' : 'none'}; opacity: ${ready ? '1' : '0.6'};">
            <div class="small">
                <span class="text-muted fw-bold">${jugador.id_usuario || '-'}</span>
                <span class="mx-1">|</span>
                <span class="text-muted">${jugador.cedula || 'Sin cédula'}</span>
                <span class="mx-1">|</span>
                <span class="text-dark">${jugador.nombre || 'Sin nombre'}</span>
            </div>
        </div>
    `;
    
    listaJugadores.insertAdjacentHTML('beforeend', jugadorHtml);
    actualizarContadorDisponibles();
    // Asegurar que el bloqueo se actualice después de agregar
    actualizarBloqueoSeleccionJugadores();
}

// Actualizar contador
function actualizarContadorDisponibles() {
    const numItems = document.querySelectorAll('#listaJugadores .jugador-item').length;
}

/** Misma API que inscripción en sitio: usuarios → afiliados → manual (lib/BusquedaJugadorInscripcionService). */
function urlBuscarJugadorEquipoApi(cedula) {
    return `<?php echo $api_base_path; ?>buscar_jugador_inscripcion.php?cedula=${encodeURIComponent(cedula)}&torneo_id=${TORNEO_ID}&nacionalidad=V`;
}

function desbloquearNombreJugadorFila(posicion) {
    const nombreEl = document.getElementById(`jugador_nombre_${posicion}`);
    if (nombreEl) {
        nombreEl.readOnly = false;
        nombreEl.style.backgroundColor = '#fff';
    }
}

/** Admin general (lista lazy): API + añade fila en #listaJugadores (mismo flujo que admin club). */
async function buscarCedulaLazyAnadir() {
    if (!JUGADORES_LISTA_LAZY) return;
    if (!puedeSeleccionarJugadores()) {
        Swal.fire({ icon: 'warning', title: 'Atención', text: 'Indique Club y nombre del equipo primero.', confirmButtonColor: '#3b82f6' });
        return;
    }
    const input = document.getElementById('buscarCedulaLazy');
    if (!input) return;
    const cedula = (input.value || '').trim();
    if (!cedula) {
        Swal.fire({ icon: 'info', title: 'Cédula', text: 'Escriba la cédula del jugador.', confirmButtonColor: '#3b82f6' });
        return;
    }
    try {
        const data = await fetchJsonBuscarJugadorParse(urlBuscarJugadorEquipoApi(cedula));
        if (data.success && data.resultado === 'no_encontrado') {
            Swal.fire({ icon: 'info', title: 'Sin registro', text: data.message || 'No consta en plataforma ni afiliados. Añada el jugador manualmente o verifique la cédula.', confirmButtonColor: '#3b82f6' });
            return;
        }
        if (!data.success || !data.jugador) {
            Swal.fire({ icon: 'error', title: 'No disponible', text: data.message || 'Jugador no disponible', confirmButtonColor: '#3b82f6' });
            return;
        }
        if (data.jugador.codigo_equipo) {
            Swal.fire({ icon: 'warning', title: 'No disponible', text: 'Ya está en un equipo: ' + data.jugador.codigo_equipo, confirmButtonColor: '#3b82f6' });
            return;
        }
        if (data.resultado === 'persona_externa') {
            Swal.fire({ icon: 'info', title: 'Afiliados', text: data.message || 'Datos desde base de afiliados.', confirmButtonColor: '#3b82f6' });
        }
        let dup = false;
        document.querySelectorAll('#listaJugadores .jugador-item').forEach(function (el) {
            if (el.getAttribute('data-cedula') === cedula) dup = true;
        });
        if (dup) {
            Swal.fire({ icon: 'info', title: 'Ya en lista', text: 'Ese jugador ya está en disponibles.', confirmButtonColor: '#3b82f6' });
            return;
        }
        data.jugador.id = data.jugador.id_inscrito ?? data.jugador.id ?? null;
        devolverJugadorAListado(data.jugador);
        input.value = '';
        actualizarContadorDisponibles();
    } catch (e) {
        console.error(e);
        Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo consultar la cédula.', confirmButtonColor: '#3b82f6' });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (!JUGADORES_LISTA_LAZY) return;
    const btn = document.getElementById('btnBuscarCedulaLazy');
    const inp = document.getElementById('buscarCedulaLazy');
    if (btn) btn.addEventListener('click', function () { buscarCedulaLazyAnadir(); });
    if (inp) {
        inp.addEventListener('blur', function () {
            if (inp.value.trim().replace(/\D/g, '').length >= 3 || inp.value.trim().length >= 3) {
                buscarCedulaLazyAnadir();
            }
        });
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); buscarCedulaLazyAnadir(); }
        });
    }
});

// Buscar jugador por cédula
async function buscarJugadorPorCedula(input) {
    const cedula = input.value.trim();
    const posicion = input.getAttribute('data-posicion');
    
    if (!tieneClubSeleccionado()) {
        Swal.fire({
            icon: 'warning',
            title: 'Atención',
            text: 'Primero seleccione el Club.',
            confirmButtonColor: '#3b82f6'
        });
        input.value = '';
        return;
    }
    
    if (!cedula) {
        limpiarJugador(posicion);
        return;
    }
    
    try {
        const data = await fetchJsonBuscarJugadorParse(urlBuscarJugadorEquipoApi(cedula));
        if (data.success && data.resultado === 'no_encontrado') {
            Swal.fire({ icon: 'info', title: 'Sin registro', text: data.message || 'Complete el nombre en la fila.', confirmButtonColor: '#3b82f6' });
            desbloquearNombreJugadorFila(posicion);
            return;
        }
        if (data.success && data.jugador) {
            if (data.jugador.codigo_equipo) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Jugador no disponible',
                    text: 'Este jugador ya está asignado a un equipo (código: ' + data.jugador.codigo_equipo + ')',
                    confirmButtonColor: '#3b82f6'
                });
                limpiarJugador(posicion);
                return;
            }
            if (data.resultado === 'persona_externa') {
                Swal.fire({ icon: 'info', title: 'Afiliados', text: data.message || 'Datos desde base de afiliados.', confirmButtonColor: '#3b82f6' });
            }
            asignarJugadorAPosicion(posicion, data.jugador);
            const items = document.querySelectorAll('.jugador-item');
            items.forEach(item => {
                const itemCedula = item.getAttribute('data-cedula');
                if (itemCedula === cedula) {
                    item.remove();
                }
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'No disponible',
                text: data.message || 'No se pudo completar la búsqueda.',
                confirmButtonColor: '#3b82f6'
            });
            limpiarJugador(posicion);
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error al buscar jugador por cédula',
            confirmButtonColor: '#3b82f6'
        });
        limpiarJugador(posicion);
    }
}

// Cédula a buscar (campo global parejas): blur = buscar y asignar a primera posición vacía
async function buscarCedulaParejasGlobal() {
    const input = document.getElementById('cedula_buscar_parejas');
    if (!input) return;
    const cedula = input.value.trim();
    if (!cedula) return;
    if (!puedeSeleccionarJugadores()) {
        Swal.fire({ icon: 'warning', title: 'Atención', text: 'Primero seleccione el Club.', confirmButtonColor: '#3b82f6' });
        return;
    }
    try {
        const data = await fetchJsonBuscarJugadorParse(urlBuscarJugadorEquipoApi(cedula));
        if (data.success && data.resultado === 'no_encontrado') {
            Swal.fire({ icon: 'info', title: 'Sin registro', text: data.message || 'Use cédula con datos en sistema o complete manualmente.', confirmButtonColor: '#3b82f6' });
            input.value = '';
            return;
        }
        if (data.success && data.jugador) {
            if (data.jugador.codigo_equipo) {
                Swal.fire({ icon: 'warning', title: 'No disponible', text: 'Ya está asignado (código: ' + data.jugador.codigo_equipo + ')', confirmButtonColor: '#3b82f6' });
                input.value = '';
                return;
            }
            if (data.resultado === 'persona_externa') {
                Swal.fire({ icon: 'info', title: 'Afiliados', text: data.message || 'Datos desde base de afiliados.', confirmButtonColor: '#3b82f6' });
            }
            for (let i = 1; i <= JUGADORES_POR_EQUIPO; i++) {
                const cedulaEl = document.getElementById('jugador_cedula_' + i);
                if (cedulaEl && !cedulaEl.value.trim()) {
                    asignarJugadorAPosicion(String(i), data.jugador);
                    input.value = '';
                    validarFormulario();
                    return;
                }
            }
            Swal.fire({ icon: 'info', title: 'Completo', text: 'Todas las posiciones ya tienen jugador.', confirmButtonColor: '#3b82f6' });
        } else {
            Swal.fire({ icon: 'error', title: 'No disponible', text: data.message || 'Verifique la cédula.', confirmButtonColor: '#3b82f6' });
        }
        input.value = '';
    } catch (e) {
        console.error(e);
        Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo buscar.', confirmButtonColor: '#3b82f6' });
        input.value = '';
    }
}

// Limpiar jugador
function limpiarJugador(posicion) {
    const idInscritoEl = document.getElementById(`jugador_id_inscrito_${posicion}`);
    const idUsuarioEl = document.getElementById(`jugador_id_usuario_${posicion}`);
    const idUsuarioHEl = document.getElementById(`jugador_id_usuario_h_${posicion}`);
    const cedulaEl = document.getElementById(`jugador_cedula_${posicion}`);
    const nombreEl = document.getElementById(`jugador_nombre_${posicion}`);
    const btnLimpiar = document.getElementById(`btn_limpiar_${posicion}`);
    
    if (idInscritoEl) idInscritoEl.value = '';
    if (idUsuarioEl) idUsuarioEl.value = '';
    if (idUsuarioHEl) idUsuarioHEl.value = '';
    if (cedulaEl) cedulaEl.value = '';
    if (nombreEl) nombreEl.value = '';
    if (btnLimpiar) btnLimpiar.style.display = 'none';
    
    const fila = document.querySelector(`[data-posicion="${posicion}"]`);
    if (fila) fila.setAttribute('data-jugador-asignado', '');
    
    validarFormulario();
    actualizarBloqueoSeleccionJugadores();
}

// Validar formulario
function validarFormulario() {
    let jugadoresCompletos = 0;
    
    for (let i = 1; i <= JUGADORES_POR_EQUIPO; i++) {
        const cedula = document.getElementById(`jugador_cedula_${i}`).value.trim();
        const nombre = document.getElementById(`jugador_nombre_${i}`).value.trim();
        
        if (cedula && nombre) {
            jugadoresCompletos++;
        }
    }
    
    const nombreEquipo = document.getElementById('nombre_equipo').value.trim();
    const clubId = document.getElementById('club_id').value;
    const nombreOk = ES_PAREJAS ? true : nombreEquipo;
    
    const btnGuardar = document.getElementById('btnGuardarEquipo');
    
    if (jugadoresCompletos === JUGADORES_POR_EQUIPO && nombreOk && clubId) {
        btnGuardar.disabled = false;
        btnGuardar.classList.remove('btn-secondary');
        btnGuardar.classList.add('btn-success');
    } else {
        btnGuardar.disabled = true;
        btnGuardar.classList.remove('btn-success');
        btnGuardar.classList.add('btn-secondary');
    }
}

// Bloqueo/Desbloqueo
function puedeSeleccionarJugadores() {
    const nombreEquipo = document.getElementById('nombre_equipo').value.trim();
    const clubId = document.getElementById('club_id').value;
    return !!(clubId && (ES_PAREJAS || nombreEquipo));
}

/** Solo club: permite escribir cédula y llamar a la API (nombre del equipo sigue siendo obligatorio al guardar en modalidad equipos). */
function tieneClubSeleccionado() {
    return !!document.getElementById('club_id').value;
}

function actualizarBloqueoSeleccionJugadores() {
    const ready = puedeSeleccionarJugadores();
    const editandoEquipo = parseInt(document.getElementById('equipo_id').value || '0', 10) > 0;
    const puedeEditarCedula = tieneClubSeleccionado() || editandoEquipo;

    const searchInput = document.getElementById('searchJugadores');
    if (searchInput && !searchInput.classList.contains('d-none')) {
        searchInput.disabled = !ready;
        if (!ready) searchInput.value = '';
    }
    const lazyCed = document.getElementById('buscarCedulaLazy');
    const lazyBtn = document.getElementById('btnBuscarCedulaLazy');
    if (lazyCed) {
        lazyCed.disabled = !ready;
        if (!ready) lazyCed.value = '';
    }
    if (lazyBtn) lazyBtn.disabled = !ready;
    
    // Actualizar contenedor y cada item individual
    const lista = document.getElementById('listaJugadores');
    if (lista) {
        lista.style.pointerEvents = ready ? 'auto' : 'none';
        lista.style.opacity = ready ? '1' : '0.6';
    }
    
    // Actualizar cada item de jugador individual
    const items = document.querySelectorAll('.jugador-item');
    items.forEach(item => {
        if (ready) {
            item.style.pointerEvents = 'auto';
            item.style.opacity = '1';
            item.style.cursor = 'pointer';
        } else {
            item.style.pointerEvents = 'none';
            item.style.opacity = '0.6';
            item.style.cursor = 'not-allowed';
        }
    });
    
    for (let i = 1; i <= JUGADORES_POR_EQUIPO; i++) {
        const cedulaEl = document.getElementById(`jugador_cedula_${i}`);
        const limpiarBtn = document.getElementById(`btn_limpiar_${i}`);
        const filaTieneJugador = cedulaEl && cedulaEl.value.trim() !== '';
        if (cedulaEl) {
            cedulaEl.readOnly = !puedeEditarCedula;
            cedulaEl.style.backgroundColor = puedeEditarCedula ? '' : '#f1f1f1';
        }
        if (limpiarBtn) {
            limpiarBtn.disabled = !filaTieneJugador;
        }
    }
}

// Limpiar formulario
function limpiarFormulario() {
    // Devolver todos los jugadores asignados a la lista
    for (let i = 1; i <= JUGADORES_POR_EQUIPO; i++) {
        const fila = document.querySelector(`[data-posicion="${i}"]`);
        const jugadorDataStr = fila ? fila.getAttribute('data-jugador-asignado') : null;
        if (jugadorDataStr) {
            try {
                const jugador = JSON.parse(jugadorDataStr);
                devolverJugadorAListado(jugador);
            } catch (e) {}
        }
        limpiarJugador(i);
    }
    
    document.getElementById('formEquipo').reset();
    document.getElementById('equipo_id').value = '';
    document.getElementById('codigo_equipo').value = '';
    var barraCod = document.getElementById('wrap_codigo_equipo_barra');
    var codVis = document.getElementById('codigo_equipo_visible');
    if (barraCod) { barraCod.style.visibility = 'hidden'; barraCod.setAttribute('aria-hidden', 'true'); }
    if (codVis) { codVis.textContent = ''; }
    
    // Limpiar selección visual de equipo
    document.querySelectorAll('.equipo-registrado-item').forEach(item => {
        item.classList.remove('selected');
    });
    
    validarFormulario();
    actualizarBloqueoSeleccionJugadores();
}

// Guardar equipo
document.getElementById('formEquipo').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    console.log('=== INICIO GUARDAR EQUIPO (JavaScript) ===');
    
    if (!puedeSeleccionarJugadores()) {
        console.log('ERROR: Validación falló - falta Club' + (ES_PAREJAS ? '' : ' o Nombre del Equipo'));
        Swal.fire({
            icon: 'warning',
            title: 'Atención',
            text: ES_PAREJAS ? 'Primero seleccione el Club.' : 'Primero seleccione el Club y el Nombre del Equipo.',
            confirmButtonColor: '#3b82f6'
        });
        return;
    }
    
    const form = this;
    const formData = new FormData();
    
    const equipo_id = document.getElementById('equipo_id').value || '';
    const torneo_id = document.getElementById('torneo_id').value || '';
    const nombre_equipo = document.getElementById('nombre_equipo').value || '';
    const club_id = document.getElementById('club_id').value || '';
    
    console.log('Datos del equipo:', { equipo_id, torneo_id, nombre_equipo, club_id });
    
    formData.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
    formData.append('equipo_id', equipo_id);
    formData.append('torneo_id', torneo_id);
    formData.append('nombre_equipo', nombre_equipo);
    formData.append('club_id', club_id);
    formData.append('codigo_club_prefijo', (document.getElementById('codigo_club_prefijo') && document.getElementById('codigo_club_prefijo').value) || '');
    
    let posicionJugador = 1;
    const jugadoresEnviados = [];
    for (let i = 1; i <= JUGADORES_POR_EQUIPO; i++) {
        const cedula = document.getElementById(`jugador_cedula_${i}`).value.trim();
        const nombre = document.getElementById(`jugador_nombre_${i}`).value.trim();
        
        if (cedula && nombre) {
            const id_inscritoEl = document.getElementById(`jugador_id_inscrito_${i}`);
            const id_inscrito = id_inscritoEl ? id_inscritoEl.value : '';
            const id_usuario_hel = document.getElementById(`jugador_id_usuario_h_${i}`);
            const id_usuario = id_usuario_hel ? id_usuario_hel.value : '';
            const es_capitan = document.getElementById(`es_capitan_${i}`)?.value == '1' ? 1 : 0;
            
            const jugadorData = { cedula, nombre, id_inscrito, id_usuario, es_capitan, posicion: i };
            jugadoresEnviados.push(jugadorData);
            console.log(`Jugador ${posicionJugador} (posición ${i}):`, jugadorData);
            
            formData.append(`jugadores[${posicionJugador}][cedula]`, cedula);
            formData.append(`jugadores[${posicionJugador}][nombre]`, nombre);
            formData.append(`jugadores[${posicionJugador}][id_inscrito]`, id_inscrito || '');
            formData.append(`jugadores[${posicionJugador}][id_usuario]`, id_usuario || '');
            formData.append(`jugadores[${posicionJugador}][es_capitan]`, es_capitan);
            posicionJugador++;
        }
    }
    
    console.log('Total de jugadores a enviar:', jugadoresEnviados.length);
    var _urlGuardar = <?php echo json_encode($api_guardar_equipo); ?>;
    console.log('[Inscribir equipo] POST a:', _urlGuardar);
    if (_urlGuardar.indexOf('guardar_equipo_sitio') === -1) {
        console.error('ERROR: Debe usar action=guardar_equipo_sitio (index/admin). Sube inscribir_equipo_sitio.php y torneo_gestion.php.');
    }
    try {
        const response = await fetch(_urlGuardar, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        
        console.log('Respuesta recibida, status:', response.status);
        console.log('Content-Type:', response.headers.get('content-type'));
        
        // Obtener el texto de la respuesta primero
        const responseText = await response.text();
        console.log('Respuesta completa (primeros 500 caracteres):', responseText.substring(0, 500));
        
        // Intentar parsear como JSON
        let data;
        try {
            data = JSON.parse(responseText);
            console.log('Datos de respuesta (JSON parseado):', data);
        } catch (parseError) {
            console.error('=== ERROR: La respuesta no es JSON válido ===');
            console.error('Error de parseo:', parseError);
            console.error('Respuesta completa:', responseText);
            
            // Si la respuesta contiene HTML (página de error), intentar extraer el mensaje
            if (responseText.includes('<!DOCTYPE') || responseText.includes('<html')) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error del servidor',
                    html: 'El servidor devolvió una página de error HTML. Revisa la consola para más detalles.<br><br>Verifica los logs de PHP en el servidor.',
                    confirmButtonColor: '#3b82f6'
                });
                console.error('HTML recibido en lugar de JSON - probablemente un error de PHP');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de respuesta',
                    html: 'Respuesta del servidor no válida. Revisa la consola para más detalles.<br><br>' + responseText.substring(0, 200),
                    confirmButtonColor: '#3b82f6'
                });
            }
            return;
        }
        
        if (data.success) {
            console.log('=== ÉXITO: Equipo guardado correctamente ===');
            Swal.fire({
                icon: 'success',
                title: '¡Éxito!',
                text: data.message || 'Equipo guardado exitosamente',
                confirmButtonColor: '#10b981',
                timer: 2000,
                timerProgressBar: true
            }).then(() => {
                location.reload();
            });
        } else {
            console.error('=== ERROR: ' + (data.message || 'Error al guardar el equipo') + ' ===');
            console.error('Detalles del error:', data);
            var isCsrf = (data.error_type === 'CSRF_INVALID');
            Swal.fire({
                icon: 'error',
                title: isCsrf ? 'Token de seguridad expirado' : 'Error al guardar',
                text: data.message || 'Error al guardar el equipo',
                confirmButtonColor: '#3b82f6',
                showCancelButton: isCsrf,
                cancelButtonText: 'Cerrar',
                confirmButtonText: isCsrf ? 'Recargar página' : 'Entendido'
            }).then(function(result) {
                if (isCsrf && result.isConfirmed) {
                    location.reload();
                }
            });
        }
    } catch (error) {
        console.error('=== ERROR en fetch: ===', error);
        console.error('Stack trace:', error.stack);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            html: 'Error al guardar el equipo: ' + error.message + '<br><br>Revisa la consola para más detalles.',
            confirmButtonColor: '#3b82f6'
        });
    }
});

// Editar equipo: solo lectura de EQUIPOS_EDITAR (misma carga de página — cero APIs)
function cargarEquipo(equipoId) {
    const equipo = EQUIPOS_EDITAR[String(equipoId)] || EQUIPOS_EDITAR[equipoId];
    if (!equipo) {
        Swal.fire({ icon: 'info', title: 'Recargar', text: 'No hay datos en memoria para este equipo. Recarga la página (F5).', confirmButtonColor: '#3b82f6' });
        return;
    }
    document.querySelectorAll('.equipo-registrado-item').forEach(function (item) { item.classList.remove('selected'); });
    var el = document.querySelector('[data-equipo-id="' + equipoId + '"]');
    if (el) {
        el.classList.add('selected');
    }
    document.getElementById('equipo_id').value = equipo.id;
    document.getElementById('codigo_equipo').value = equipo.codigo_equipo || '';
    var barraCod = document.getElementById('wrap_codigo_equipo_barra');
    var codVis = document.getElementById('codigo_equipo_visible');
    if (barraCod && codVis) {
        barraCod.style.visibility = 'visible';
        barraCod.setAttribute('aria-hidden', 'false');
        codVis.textContent = equipo.codigo_equipo || '—';
    }
    document.getElementById('nombre_equipo').value = equipo.nombre_equipo || '';
    var selClub = document.getElementById('club_id');
    var idClub = (equipo.id_club !== undefined && equipo.id_club !== null) ? String(equipo.id_club) : '';
    if (idClub && selClub) {
        var opt = selClub.querySelector('option[value="' + idClub.replace(/"/g, '\\"') + '"]');
        if (!opt) {
            opt = document.createElement('option');
            opt.value = idClub;
            opt.textContent = equipo.club_nombre || ('Club #' + idClub);
            selClub.appendChild(opt);
        }
        selClub.value = idClub;
    } else if (selClub) {
        selClub.value = '';
    }

    for (var i = 1; i <= JUGADORES_POR_EQUIPO; i++) {
        var fila = document.querySelector('[data-posicion="' + i + '"]');
        var jugadorDataStr = fila ? fila.getAttribute('data-jugador-asignado') : null;
        if (jugadorDataStr) {
            try {
                devolverJugadorAListado(JSON.parse(jugadorDataStr));
            } catch (e) {}
        }
        limpiarJugador(i);
    }

    (equipo.jugadores || []).forEach(function (jugador, index) {
        var posicion = index + 1;
        if (posicion > JUGADORES_POR_EQUIPO) {
            return;
        }
        asignarJugadorAPosicion(posicion, {
            id: jugador.id_inscrito,
            id_inscrito: jugador.id_inscrito,
            id_usuario: jugador.id_usuario,
            cedula: jugador.cedula || '',
            nombre: jugador.nombre || '',
            club_nombre: equipo.club_nombre || 'Sin Club'
        });
        document.querySelectorAll('.jugador-item').forEach(function (item) {
            if (item.getAttribute('data-id-usuario') == jugador.id_usuario) {
                item.remove();
            }
        });
    });

    actualizarBloqueoSeleccionJugadores();
    window.enfocarPanelFormularioFlotante();
    validarFormulario();
}

// Eliminar equipo
async function eliminarEquipo(equipoId, nombreEquipo) {
    const result = await Swal.fire({
        icon: 'warning',
        title: '¿Eliminar equipo?',
        html: `¿Está seguro de eliminar el equipo <strong>"${nombreEquipo}"</strong>?<br><br>Se eliminarán el equipo y los <strong>inscritos</strong> de sus integrantes en este torneo (no se borran usuarios del sistema).`,
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6c757d',
        reverseButtons: true
    });
    
    if (!result.isConfirmed) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('equipo_id', equipoId);
        formData.append('csrf_token', '<?php echo CSRF::token(); ?>');
        
        const response = await fetch('<?php echo $api_base_path; ?>eliminar_equipo.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Eliminado!',
                text: data.message || 'Equipo eliminado exitosamente',
                confirmButtonColor: '#10b981',
                timer: 2000,
                timerProgressBar: true
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'Error al eliminar el equipo',
                confirmButtonColor: '#3b82f6'
            });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error al eliminar el equipo: ' + error.message,
            confirmButtonColor: '#3b82f6'
        });
    }
}
</script>

