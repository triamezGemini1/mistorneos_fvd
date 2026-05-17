<?php
/**
 * M�dulo de Finanzas - Serviclubes LED
 * Gesti�n completa de deudas y pagos de clubs
 */



require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/Pagination.php';

// Verificar permisos
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

// Obtener informaci�n del usuario actual
$current_user = Auth::user();
$user_role = $current_user['role'] ?? '';
$user_club_id = Auth::getUserClubId();
$is_admin_general = Auth::isAdminGeneral();
$is_admin_torneo = Auth::isAdminTorneo();
$is_admin_club = Auth::isAdminClub();

// Validar permisos: admin_torneo requiere club, admin_club requiere organización
if ($is_admin_torneo && !$user_club_id) {
    $error_message = "Error: Su usuario no tiene un club asignado. Contacte al administrador general.";
} elseif ($is_admin_club && !Auth::getUserOrganizacionId()) {
    $error_message = "Error: Su usuario no tiene una organización asignada. Contacte al administrador general.";
}

// Obtener acci�n
$action = $_GET['action'] ?? 'dashboard';
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? $error_message ?? null;
$auto_update = isset($_GET['auto_update']) ? (int)$_GET['auto_update'] : 0;

// Obtener lista de torneos para filtro (filtrada seg�n el rol)
$torneos_list = [];
try {
    $tournament_filter = Auth::getTournamentFilterForRole('t');
    $where_clause = "";
    
    if (!empty($tournament_filter['where'])) {
        $where_clause = "WHERE " . $tournament_filter['where'];
    }
    
    $stmt = DB::pdo()->prepare("
        SELECT t.id, t.nombre, t.fechator, t.costo,
               CASE WHEN t.fechator < CURDATE() THEN 1 ELSE 0 END as pasado
        FROM tournaments t
        {$where_clause}
        ORDER BY t.fechator DESC
    ");
    $stmt->execute($tournament_filter['params']);
    $torneos_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error al cargar torneos: " . $e->getMessage();
}

// Solo calcular estad�sticas y cargar datos si hay un torneo seleccionado
$stats = [
    'total_deuda' => 0,
    'total_pagado' => 0,
    'total_pendiente' => 0,
    'total_inscritos' => 0,
    'total_clubs' => 0
];

$deudas_list = [];
$pagination = null;
$mostrar_datos = false;

if ($torneo_id > 0) {
    // Validar que el admin_torneo y admin_club solo accedan a torneos de sus clubes
    if (!Auth::canAccessTournament($torneo_id)) {
        header('Location: index.php?page=finances&error=' . urlencode('No tiene permisos para acceder a este torneo'));
        exit;
    }
    
    // Verificar si el torneo ya pas� (solo mostrar advertencia, no bloquear)
    $torneo_pasado = Auth::isTournamentPast($torneo_id);
    if ($torneo_pasado && $is_admin_torneo) {
        $warning_message = "?? Este torneo ya finaliz�. Los datos son de solo lectura.";
    }
    
    $mostrar_datos = true;
    $pdo = DB::pdo();
    
    try {
        // Calcular estad�sticas del torneo seleccionado
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(d.monto_total), 0) as total_deuda,
                COALESCE(SUM(d.abono), 0) as total_pagado,
                COALESCE(SUM(d.monto_total - d.abono), 0) as total_pendiente,
                COALESCE(SUM(d.total_inscritos), 0) as total_inscritos,
                COUNT(DISTINCT d.club_id) as total_clubs
            FROM deuda_clubes d
            WHERE d.torneo_id = ?
        ");
        $stmt->execute([$torneo_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $stats = [
                'total_deuda' => (float)$result['total_deuda'],
                'total_pagado' => (float)$result['total_pagado'],
                'total_pendiente' => (float)$result['total_pendiente'],
                'total_inscritos' => (int)$result['total_inscritos'],
                'total_clubs' => (int)$result['total_clubs']
            ];
        }
    } catch (Exception $e) {
        // Silencio
    }
    
    // Obtener listado de deudas por club con paginaci�n
    try {
        // Configurar paginaci�n
        $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
        $per_page = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 25;
        
        // Contar total de registros
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM deuda_clubes d
            WHERE d.torneo_id = ?
        ");
        $stmt->execute([$torneo_id]);
        $total_records = (int)$stmt->fetchColumn();
        
        // Crear objeto de paginaci�n
        $pagination = new Pagination($total_records, $current_page, $per_page);
        
        // Obtener registros de la p�gina actual
        $stmt = $pdo->prepare("
            SELECT 
                d.*,
                c.nombre as club_nombre,
                c.delegado as club_delegado,
                c.telefono as club_telefono,
                t.nombre as torneo_nombre,
                t.fechator as torneo_fecha
            FROM deuda_clubes d
            INNER JOIN clubes c ON d.club_id = c.id
            INNER JOIN tournaments t ON d.torneo_id = t.id
            WHERE d.torneo_id = ?
            ORDER BY (d.monto_total - d.abono) DESC, c.nombre ASC
            LIMIT {$pagination->getLimit()} OFFSET {$pagination->getOffset()}
        ");
        $stmt->execute([$torneo_id]);
        $deudas_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = "Error al cargar deudas: " . $e->getMessage();
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-dollar-sign me-2"></i>Finanzas
                    </h1>
                    <p class="text-muted mb-0">Gesti�n de deudas y pagos de clubs</p>
                </div>
                <div>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-success" onclick="exportarDeudaExcel()" <?= !$mostrar_datos ? 'disabled' : '' ?>>
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </button>
                        <button type="button" class="btn btn-danger" onclick="exportarDeudaPDF()" <?= !$mostrar_datos ? 'disabled' : '' ?>>
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Alertas -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($is_admin_torneo && $user_club_id): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Modo Administrador de Torneo:</strong> Solo puede ver finanzas de torneos asignados a su club (ID: <?= $user_club_id ?>).
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($is_admin_club && $user_club_id): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Modo Administrador de organización:</strong> Solo puede ver finanzas de torneos de sus clubes supervisados.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($is_admin_club && $user_club_id): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Modo Administrador de organización:</strong> Solo puede ver finanzas de torneos de sus clubes supervisados.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($is_admin_club && $user_club_id): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Modo Administrador de organización:</strong> Solo puede ver finanzas de torneos de sus clubes supervisados.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Filtro por Torneo -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="fas fa-trophy me-1"></i>Seleccione un Torneo para Ver Estados de Cuenta</h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="index.php" id="formFiltroTorneo" class="row g-3">
                        <input type="hidden" name="page" value="finances">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Torneo:</label>
                            <select name="torneo_id" id="selectTorneo" class="form-select form-select-lg" required onchange="cargarTorneoConActualizacion()">
                                <option value="0">-- Seleccione un Torneo --</option>
                                <?php foreach ($torneos_list as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= $torneo_id == $t['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['nombre']) ?> - <?= date('d/m/Y', strtotime($t['fechator'])) ?> 
                                        (Costo: $<?= number_format((float)$t['costo'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                La informaci�n se cargar� autom�ticamente al seleccionar un torneo.
                            </small>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="btn-group w-100" role="group">
                                <button type="button" class="btn btn-primary btn-lg" onclick="cargarTorneoConActualizacion(event)">
                                    <i class="fas fa-sync me-2"></i>Actualizar y Cargar
                                </button>
                                <?php if ($torneo_id > 0): ?>
                                    <a href="index.php?page=finances" class="btn btn-secondary btn-lg">
                                        <i class="fas fa-times me-2"></i>Limpiar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!$mostrar_datos): ?>
    <!-- Mensaje cuando no hay torneo seleccionado -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-info">
                <div class="card-body text-center py-5">
                    <i class="fas fa-info-circle fa-4x text-info mb-3"></i>
                    <h4 class="text-muted">Seleccione un Torneo</h4>
                    <p class="text-muted mb-0">
                        Para ver los estados de cuenta y gestionar pagos, por favor seleccione un torneo del men� superior.<br>
                        <small>Las deudas se actualizar�n autom�ticamente al seleccionar el torneo.</small>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Dashboard de Estad�sticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-primary mb-2">
                        <i class="fas fa-users fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= $stats['total_inscritos'] ?></h4>
                    <p class="text-muted mb-0">Jugadores Inscritos</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-danger mb-2">
                        <i class="fas fa-exclamation-circle fs-1"></i>
                    </div>
                    <h4 class="mb-1">$<?= number_format((float)$stats['total_deuda'], 2) ?></h4>
                    <p class="text-muted mb-0">Deuda Total</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-success mb-2">
                        <i class="fas fa-check-circle fs-1"></i>
                    </div>
                    <h4 class="mb-1">$<?= number_format((float)$stats['total_pagado'], 2) ?></h4>
                    <p class="text-muted mb-0">Pagado</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-warning mb-2">
                        <i class="fas fa-hourglass-half fs-1"></i>
                    </div>
                    <h4 class="mb-1">$<?= number_format((float)$stats['total_pendiente'], 2) ?></h4>
                    <p class="text-muted mb-0">Pendiente</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Listado de Deudas por Club -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0">
                <i class="fas fa-building me-2"></i>Deudas por Club
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($deudas_list)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No hay deudas registradas.
                    <?php if ($torneo_id): ?>
                        <br><small>Puede actualizar las deudas desde el bot�n "Actualizar Deudas"</small>
                    <?php else: ?>
                        <br><small>Seleccione un torneo para ver las deudas</small>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="tableDeudas">
                        <thead>
                            <tr>
                                <th>Club</th>
                                <th>Torneo</th>
                                <th class="text-center">Inscritos</th>
                                <th class="text-end">Deuda Total</th>
                                <th class="text-end">Pagado</th>
                                <th class="text-end">Pendiente</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deudas_list as $deuda): 
                                $pendiente = (float)$deuda['monto_total'] - (float)$deuda['abono'];
                                $puede_pagar = $pendiente > 0;
                                $pendiente_json = number_format($pendiente, 2, '.', '');
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($deuda['club_nombre']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($deuda['club_delegado'] ?? 'Sin delegado') ?></small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($deuda['torneo_nombre']) ?>
                                        <br><small class="text-muted"><?= date('d/m/Y', strtotime($deuda['torneo_fecha'])) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= $deuda['total_inscritos'] ?></span>
                                    </td>
                                    <td class="text-end">
                                        <strong>$<?= number_format((float)$deuda['monto_total'], 2) ?></strong>
                                    </td>
                                    <td class="text-end text-success">
                                        $<?= number_format((float)$deuda['abono'], 2) ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge bg-<?= $pendiente > 0 ? 'warning' : 'success' ?> fs-6">
                                            $<?= number_format((float)$pendiente, 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-info" 
                                                    title="Ver Pagos"
                                                    onclick='verPagos(<?= $deuda['torneo_id'] ?>, <?= $deuda['club_id'] ?>, "<?= htmlspecialchars($deuda['club_nombre'], ENT_QUOTES) ?>")'>
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-success" 
                                                    title="Registrar Pago"
                                                    onclick="abrirModalPago(<?= $deuda['torneo_id'] ?>, <?= $deuda['club_id'] ?>, '<?= addslashes($deuda['club_nombre']) ?>', <?= $pendiente_json ?>, <?= $deuda['total_inscritos'] ?>, <?= number_format((float)$deuda['monto_total'], 2, '.', '') ?>, <?= number_format((float)$deuda['abono'], 2, '.', '') ?>)"
                                                    <?= !$puede_pagar ? 'disabled' : '' ?>>
                                                <i class="fas fa-dollar-sign"></i>
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-primary" 
                                                    title="Enviar Notificaci�n WhatsApp"
                                                    onclick="enviarNotificacionWhatsApp(<?= $deuda['torneo_id'] ?>, <?= $deuda['club_id'] ?>)"
                                                    <?= !$puede_pagar ? 'disabled' : '' ?>>
                                                <i class="fab fa-whatsapp"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginaci�n -->
                <?php if ($pagination): ?>
                    <?= $pagination->render() ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <?php endif; // Fin de mostrar_datos ?>
</div>

<!-- Modal de Registro de Pago -->
<div class="modal fade" id="modalPago" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-dollar-sign me-2"></i>Registrar Pago
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 0; display: flex; flex-direction: column; max-height: 600px;">
                <!-- Estado de Cuenta - SIEMPRE VISIBLE (NO HACE SCROLL) -->
                <div style="flex-shrink: 0; padding: 1rem; background-color: #d1ecf1; border-bottom: 2px solid #0d6efd; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div id="infoPagoClub" style="color: #0c5460;">
                        <!-- Se llenar� con JavaScript -->
                    </div>
                </div>
                
                <!-- Formulario con Scroll -->
                <div style="flex: 1; overflow-y: auto; padding: 1rem;">
                    <form id="formPago" method="POST" action="modules/finances/save_payment.php">
                        <?= CSRF::input(); ?>
                        <input type="hidden" name="torneo_id" id="pago_torneo_id">
                        <input type="hidden" name="club_id" id="pago_club_id">
                        <input type="hidden" name="tasa_cambio" id="pago_tasa_cambio" value="0">
                        <input type="hidden" name="monto_dolares" id="pago_monto_dolares" value="0">
                        <input type="hidden" name="monto_total" id="pago_monto_total" value="0">
                        
                        <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Moneda</label>
                                <select name="moneda" id="pago_moneda" class="form-select" required onchange="calcularMontos()">
                                    <option value="USD">D�lares (USD)</option>
                                    <option value="BS">Bol�vares (BS)</option>
                                </select>
                            </div>
                            
                            <div class="mb-3" id="div_tasa_cambio" style="display: none;">
                                <label class="form-label">Tasa de Cambio BCV</label>
                                <div class="input-group">
                                    <span class="input-group-text">Bs.</span>
                                    <input type="text" class="form-control" id="display_tasa_cambio" readonly>
                                    <button type="button" class="btn btn-outline-primary" onclick="cargarTasaBCV()">
                                        <i class="fas fa-sync"></i>
                                    </button>
                                </div>
                                <small class="text-muted" id="fecha_tasa"></small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Monto a Pagar en D�lares (USD)</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="monto" 
                                           step="0.01" min="0.01" required id="pago_monto" onkeyup="calcularMontos()" onchange="calcularMontos()">
                                </div>
                                <small class="text-muted">Este monto se descontar� de la deuda en d�lares</small>
                            </div>
                            
                            <div class="mb-3" id="div_monto_bs" style="display: none;">
                                <label class="form-label fw-bold">Monto Total en Bol�vares</label>
                                <div class="input-group">
                                    <span class="input-group-text">Bs.</span>
                                    <input type="text" class="form-control" id="display_monto_bs" readonly>
                                </div>
                                <small class="text-success">Este es el monto que el club pag� en bol�vares</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Fecha de Pago</label>
                                <input type="date" class="form-control" name="fecha" 
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tipo de Pago</label>
                                <select name="tipo_pago" id="pago_tipo_pago" class="form-select" required>
                                    <option value="efectivo">Efectivo</option>
                                    <option value="transferencia">Transferencia Bancaria</option>
                                    <option value="pago_movil">Pago M�vil</option>
                                    <option value="zelle">Zelle</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Referencia/N� de Transacci�n</label>
                                <input type="text" class="form-control" name="referencia" 
                                       placeholder="Ej: 123456789">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Banco</label>
                                <input type="text" class="form-control" name="banco" 
                                       placeholder="Ej: Banco Venezuela">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Observaciones</label>
                                <textarea class="form-control" name="observaciones" rows="3" 
                                          placeholder="Notas adicionales..."></textarea>
                            </div>
                        </div>
                    </div>
                    </form>
                </div>
                <!-- Fin �rea con scroll -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="btnCancelarPago">
                    <i class="fas fa-times me-2"></i>Cancelar
                </button>
                <button type="button" class="btn btn-success" onclick="guardarPago()" id="btnGuardarPago">
                    <i class="fas fa-save me-2"></i>Guardar Pago
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Ver Pagos -->
<div class="modal fade" id="modalVerPagos" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-list me-2"></i>Historial de Pagos
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalVerPagosBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$finances_actualizar_deudas_url = class_exists('AppHelpers')
    ? rtrim(AppHelpers::getPublicUrl(), '/') . '/api/finances_actualizar_deudas.php'
    : '/public/api/finances_actualizar_deudas.php';
?>
<script>
const FINANCES_ACTUALIZAR_DEUDAS_URL = <?= json_encode($finances_actualizar_deudas_url, JSON_UNESCAPED_UNICODE) ?>;

function parseFinancesJsonResponse(response) {
    return response.text().then(function (text) {
        const trimmed = (text || '').trim();
        if (!trimmed) {
            throw new Error('Respuesta vacía del servidor');
        }
        if (trimmed.charAt(0) === '<') {
            throw new Error('El servidor devolvió HTML en lugar de JSON. Verifique sesión o permisos.');
        }
        try {
            return JSON.parse(trimmed);
        } catch (e) {
            throw new Error('Respuesta no válida del servidor');
        }
    });
}
console.log('M�dulo de finanzas cargado');

// ============================================
// SISTEMA DE TASA DE CAMBIO BCV
// ============================================

// Variable global para almacenar la tasa de cambio
let tasaBCVActual = 0;

// Funci�n para consultar la API del BCV
async function cargarTasaBCV() {
    console.log('?? Consultando tasa BCV...');
    
    const btnSync = event ? event.target.closest('button') : null;
    const originalHTML = btnSync ? btnSync.innerHTML : '';
    
    if (btnSync) {
        btnSync.disabled = true;
        btnSync.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }
    
    try {
        // Usar API local del servidor
        const response = await fetch('modules/finances/get_tasa_bcv.php');
        
        if (!response.ok) {
            throw new Error('Error al consultar la API');
        }
        
        const data = await response.json();
        console.log('?? Respuesta API BCV:', data);
        
        // La API devuelve: { success: true, tasa: 36.50, fuente: "BCV", fecha: "..." }
        if (data && data.success && data.tasa) {
            tasaBCVActual = parseFloat(data.tasa);
            
            // Actualizar campos
            document.getElementById('pago_tasa_cambio').value = tasaBCVActual;
            document.getElementById('display_tasa_cambio').value = tasaBCVActual.toFixed(2);
            
            // Actualizar fecha y fuente
            const fechaInfo = 'Fuente: ' + data.fuente + ' - ' + data.fecha;
            document.getElementById('fecha_tasa').textContent = fechaInfo;
            
            // Calcular montos
            calcularMontos();
            
            alert('? Tasa BCV cargada: Bs. ' + tasaBCVActual.toFixed(2) + ' (' + data.fuente + ')');
        } else {
            throw new Error(data.error || 'Formato de respuesta inesperado');
        }
    } catch (error) {
        console.error('? Error al cargar tasa BCV:', error);
        alert('? No se pudo obtener la tasa del BCV. Por favor, intente nuevamente o ingrese la tasa manualmente.');
        
        // Permitir ingreso manual
        const tasaManual = prompt('Ingrese la tasa de cambio BCV manualmente (Bs.):');
        if (tasaManual && !isNaN(tasaManual)) {
            tasaBCVActual = parseFloat(tasaManual);
            document.getElementById('pago_tasa_cambio').value = tasaBCVActual;
            document.getElementById('display_tasa_cambio').value = tasaBCVActual.toFixed(2);
            document.getElementById('fecha_tasa').textContent = 'Tasa manual - ' + new Date().toLocaleDateString();
            calcularMontos();
        }
    } finally {
        if (btnSync) {
            btnSync.disabled = false;
            btnSync.innerHTML = originalHTML;
        }
    }
}

// Funci�n para calcular montos seg�n la moneda
function calcularMontos() {
    const moneda = document.getElementById('pago_moneda').value;
    const montoDolares = parseFloat(document.getElementById('pago_monto').value) || 0;
    const tasaCambio = parseFloat(document.getElementById('pago_tasa_cambio').value) || 0;
    const maxPendiente = parseFloat(document.getElementById('pago_monto').max) || 0;
    
    console.log('?? Calculando:', { moneda, montoDolares, tasaCambio, maxPendiente });
    
    // Validar que el monto no exceda el pendiente
    const campoMonto = document.getElementById('pago_monto');
    const btnGuardar = document.getElementById('btnGuardarPago');
    
    if (montoDolares > maxPendiente && maxPendiente > 0) {
        campoMonto.classList.add('is-invalid');
        if (!document.getElementById('error_monto_excede')) {
            const errorDiv = document.createElement('div');
            errorDiv.id = 'error_monto_excede';
            errorDiv.className = 'invalid-feedback';
            errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>El monto excede el saldo pendiente ($' + maxPendiente.toFixed(2) + ')';
            campoMonto.parentNode.appendChild(errorDiv);
        }
        if (btnGuardar) {
            btnGuardar.disabled = true;
            btnGuardar.innerHTML = '<i class="fas fa-ban me-2"></i>Monto Excede Saldo';
        }
    } else {
        campoMonto.classList.remove('is-invalid');
        const errorDiv = document.getElementById('error_monto_excede');
        if (errorDiv) {
            errorDiv.remove();
        }
        if (btnGuardar && maxPendiente > 0 && montoDolares > 0) {
            rehabilitarBotonPago();
        }
    }
    
    // Mostrar/ocultar campos seg�n la moneda
    if (moneda === 'BS') {
        document.getElementById('div_tasa_cambio').style.display = 'block';
        document.getElementById('div_monto_bs').style.display = 'block';
        
        // Si no hay tasa, cargarla autom�ticamente
        if (tasaCambio === 0) {
            cargarTasaBCV();
        } else {
            // Calcular monto en bol�vares
            const montoBS = montoDolares * tasaCambio;
            document.getElementById('display_monto_bs').value = montoBS.toFixed(2);
            document.getElementById('pago_monto_total').value = montoBS.toFixed(2);
        }
    } else {
        // Si es USD, ocultar campos de bol�vares
        document.getElementById('div_tasa_cambio').style.display = 'none';
        document.getElementById('div_monto_bs').style.display = 'none';
        document.getElementById('pago_tasa_cambio').value = 0;
        document.getElementById('pago_monto_total').value = montoDolares.toFixed(2);
    }
    
    // El monto en d�lares siempre es el monto que se descuenta
    document.getElementById('pago_monto_dolares').value = montoDolares.toFixed(2);
}

// ============================================
// FIN SISTEMA DE TASA DE CAMBIO
// ============================================

// Funci�n para cargar torneo con actualizaci�n autom�tica de deudas
async function cargarTorneoConActualizacion(event) {
    const selectTorneo = document.getElementById('selectTorneo');
    const torneoId = parseInt(selectTorneo.value);
    
    if (torneoId <= 0) {
        // Solo mostrar alerta si se dispar� desde el bot�n, no desde el select
        if (event && event.target && event.target.tagName === 'BUTTON') {
            alert('?? Por favor seleccione un torneo');
        }
        return;
    }
    
    // Determinar el bot�n para mostrar indicador (puede ser el bot�n o crear uno temporal)
    let btn = null;
    let originalHTML = '';
    let isButton = false;
    
    if (event && event.target && event.target.tagName === 'BUTTON') {
        btn = event.target;
        originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Actualizando deudas...';
        isButton = true;
    } else {
        // Si se dispar� desde el select, mostrar indicador en el select
        selectTorneo.disabled = true;
        console.log('?? Cargando torneo autom�ticamente...');
    }
    
    try {
        const url = (typeof FINANCES_ACTUALIZAR_DEUDAS_URL === 'string' && FINANCES_ACTUALIZAR_DEUDAS_URL)
            ? FINANCES_ACTUALIZAR_DEUDAS_URL
            : ((window.APP_PUBLIC_BASE || '') + '/api/finances_actualizar_deudas.php');

        console.log('Actualizando deudas del torneo', torneoId, 'en:', url);

        const response = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({ torneo_id: torneoId })
        });

        const data = await parseFinancesJsonResponse(response);
        console.log('Resultado actualizaci�n:', data);
        
        if (data.success) {
            // Redirigir con el torneo seleccionado
            window.location.href = window.location.pathname + '?page=finances&torneo_id=' + torneoId + '&success=' + encodeURIComponent('Deudas actualizadas. ' + data.message);
        } else {
            alert('?? Error al actualizar deudas: ' + (data.message || 'Error desconocido'));
            if (isButton && btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            } else {
                selectTorneo.disabled = false;
            }
        }
    } catch (error) {
        console.error('Error:', error);
        alert('? Error al actualizar deudas: ' + error.message);
        if (isButton && btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        } else {
            selectTorneo.disabled = false;
        }
    }
}

// Abrir modal de pago
function abrirModalPago(torneoId, clubId, clubNombre, pendiente, totalInscritos, deudaTotal, abonoTotal) {
    console.log('?? Abriendo modal de pago', {torneoId, clubId, clubNombre, pendiente, totalInscritos, deudaTotal, abonoTotal});
    
    // Obtener el modal
    const modalElement = document.getElementById('modalPago');
    const form = document.getElementById('formPago');
    
    // Limpiar formulario completamente
    if (form) {
        form.reset();
    }
    
    // Resetear campos de tasa de cambio
    tasaBCVActual = 0;
    document.getElementById('pago_moneda').value = 'USD';
    document.getElementById('pago_tasa_cambio').value = 0;
    document.getElementById('pago_monto_dolares').value = 0;
    document.getElementById('pago_monto_total').value = 0;
    document.getElementById('div_tasa_cambio').style.display = 'none';
    document.getElementById('div_monto_bs').style.display = 'none';
    
    // Limpiar validaciones visuales
    const campoMonto = document.getElementById('pago_monto');
    if (campoMonto) {
        campoMonto.classList.remove('is-invalid');
    }
    const errorDiv = document.getElementById('error_monto_excede');
    if (errorDiv) {
        errorDiv.remove();
    }
    
    // CR�TICO: Clonar bot�n para eliminar event listeners problem�ticos
    const btnGuardar = document.getElementById('btnGuardarPago');
    if (btnGuardar) {
        const nuevoBtn = btnGuardar.cloneNode(true);
        btnGuardar.parentNode.replaceChild(nuevoBtn, btnGuardar);
    }
    
    // Rehabilitar despu�s del reemplazo
    setTimeout(() => {
        rehabilitarBotonPago();
    }, 50);
    
    // Establecer valores
    document.getElementById('pago_torneo_id').value = torneoId;
    document.getElementById('pago_club_id').value = clubId;
    document.getElementById('pago_monto').max = pendiente;
    document.getElementById('pago_monto').value = ''; // Limpiar monto
    
    // Convertir a n�meros
    totalInscritos = parseInt(totalInscritos) || 0;
    deudaTotal = parseFloat(deudaTotal) || 0;
    abonoTotal = parseFloat(abonoTotal) || 0;
    pendiente = parseFloat(pendiente) || 0;
    
    // Calcular costo por inscrito (para mostrar)
    const costoPorInscrito = totalInscritos > 0 ? (deudaTotal / totalInscritos) : 0;
    
    // Guardar valores para usar despu�s de abrir el modal
    const estadoCuentaHTML = `
        <div class="row align-items-center">
            <div class="col-12 mb-3">
                <h6 class="mb-0 text-center">
                    <i class="fas fa-building me-2 text-primary"></i>
                    <strong>${clubNombre}</strong>
                </h6>
            </div>
        </div>
        <div class="row g-2">
            <div class="col-3">
                <div class="text-center p-2 bg-info bg-opacity-10 rounded border border-info">
                    <div class="text-info small fw-bold">Inscritos</div>
                    <div class="text-info fs-4 fw-bold">${totalInscritos}</div>
                    <small class="text-muted d-block" style="font-size: 0.7rem;">$${costoPorInscrito.toFixed(2)} c/u</small>
                </div>
            </div>
            <div class="col-3">
                <div class="text-center p-2 bg-primary bg-opacity-10 rounded border border-primary">
                    <div class="text-primary small fw-bold">Deuda Total</div>
                    <div class="text-primary fs-4 fw-bold">$${deudaTotal.toFixed(2)}</div>
                    <small class="text-muted d-block" style="font-size: 0.7rem;">${totalInscritos} � $${costoPorInscrito.toFixed(2)}</small>
                </div>
            </div>
            <div class="col-3">
                <div class="text-center p-2 bg-success bg-opacity-10 rounded border border-success">
                    <div class="text-success small fw-bold">Abonos</div>
                    <div class="text-success fs-4 fw-bold">$${abonoTotal.toFixed(2)}</div>
                    <small class="text-muted d-block" style="font-size: 0.7rem;">Pagado</small>
                </div>
            </div>
            <div class="col-3">
                <div class="text-center p-2 bg-danger bg-opacity-10 rounded border border-danger">
                    <div class="text-danger small fw-bold">Pendiente</div>
                    <div class="text-danger fs-4 fw-bold">$${pendiente.toFixed(2)}</div>
                    <small class="text-muted d-block" style="font-size: 0.7rem;">${deudaTotal.toFixed(2)} - ${abonoTotal.toFixed(2)}</small>
                </div>
            </div>
        </div>
        <div class="text-center mt-2">
            <small class="text-muted d-block">
                <i class="fas fa-info-circle me-1"></i>
                El monto a pagar no puede exceder el saldo pendiente
            </small>
        </div>
    `;
    
    // Destruir modal anterior si existe
    const existingModal = bootstrap.Modal.getInstance(modalElement);
    if (existingModal) {
        existingModal.dispose();
    }
    
    // Esperar un momento y abrir nuevo modal
    setTimeout(() => {
        // Rehabilitar JUSTO antes de abrir
        rehabilitarBotonPago();
        
        // Abrir modal NUEVO
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: true,
            keyboard: true
        });
        modal.show();
        
        // Rehabilitar m�ltiples veces despu�s de abrir
        setTimeout(rehabilitarBotonPago, 100);
        setTimeout(rehabilitarBotonPago, 300);
        setTimeout(rehabilitarBotonPago, 500);
    }, 150);
    
    // CR�TICO: Establecer estado de cuenta DESPU�S de que el modal se muestre
    modalElement.addEventListener('shown.bs.modal', function mostrarEstadoCuenta() {
        console.log('?? Estableciendo estado de cuenta');
        document.getElementById('infoPagoClub').innerHTML = estadoCuentaHTML;
        
        // Deshabilitar bot�n de guardar si no hay deuda pendiente
        const btnGuardar = document.getElementById('btnGuardarPago');
        if (btnGuardar && parseFloat(pendiente) <= 0) {
            btnGuardar.disabled = true;
            btnGuardar.innerHTML = '<i class="fas fa-ban me-2"></i>Sin Deuda Pendiente';
            btnGuardar.classList.remove('btn-success');
            btnGuardar.classList.add('btn-secondary');
        }
        
        // Remover el listener para evitar duplicados
        modalElement.removeEventListener('shown.bs.modal', mostrarEstadoCuenta);
    }, { once: true });
}

// Variables globales para manejo del modal
let modalPagoInstance = null;
let btnGuardarPagoElement = null;
const BTN_GUARDAR_ORIGINAL = '<i class="fas fa-save me-2"></i>Guardar Pago';

// Funci�n para rehabilitar el bot�n (usar en todos los casos)
function rehabilitarBotonPago() {
    // Usar el ID para encontrar el bot�n directamente
    const btn = document.getElementById('btnGuardarPago');
    
    if (btn) {
        // Forzar rehabilitaci�n de forma agresiva
        btn.disabled = false;
        btn.removeAttribute('disabled');
        btn.innerHTML = BTN_GUARDAR_ORIGINAL;
        btn.style.pointerEvents = 'auto'; // Asegurar que sea clickeable
        btn.classList.remove('disabled');
        console.log('? Bot�n de pago rehabilitado (ID: btnGuardarPago)');
        return true;
    } else {
        console.warn('?? No se encontr� el bot�n de guardar pago (ID: btnGuardarPago)');
        return false;
    }
}

// Funci�n para limpiar completamente el modal
function limpiarModalPago() {
    console.log('?? Limpiando modal de pago');
    
    const form = document.getElementById('formPago');
    if (form) {
        form.reset();
    }
    
    // Limpiar validaci�n visual del monto
    const campoMonto = document.getElementById('pago_monto');
    if (campoMonto) {
        campoMonto.classList.remove('is-invalid');
    }
    
    const errorDiv = document.getElementById('error_monto_excede');
    if (errorDiv) {
        errorDiv.remove();
    }
    
    rehabilitarBotonPago();
}

// Sistema de gesti�n del modal de pago - SIMPLIFICADO Y MEJORADO
document.addEventListener('DOMContentLoaded', function() {
    const modalPago = document.getElementById('modalPago');
    const btnCancelar = document.getElementById('btnCancelarPago');
    const btnCerrar = modalPago ? modalPago.querySelector('.btn-close') : null;
    
    if (!modalPago) {
        console.error('?? Modal de pago no encontrado');
        return;
    }
    
    // Guardar referencia al bot�n
    btnGuardarPagoElement = document.getElementById('btnGuardarPago');
    
    // Rehabilitaci�n inicial
    rehabilitarBotonPago();
    console.log('?? Sistema de pago inicializado');
    
    // EVENTO CR�TICO: Cuando el modal se cierra (por cualquier motivo)
    modalPago.addEventListener('hidden.bs.modal', function() {
        console.log('?? Modal cerrado - rehabilitando bot�n');
        limpiarModalPago();
        
        // Rehabilitar m�ltiples veces para asegurar
        rehabilitarBotonPago();
        setTimeout(rehabilitarBotonPago, 100);
        setTimeout(rehabilitarBotonPago, 300);
        setTimeout(rehabilitarBotonPago, 600);
    });
    
    // EVENTO: Cuando el modal se abre
    modalPago.addEventListener('show.bs.modal', function() {
        console.log('?? Modal abri�ndose - preparando');
        rehabilitarBotonPago();
    });
    
    modalPago.addEventListener('shown.bs.modal', function() {
        console.log('?? Modal abierto - verificando');
        setTimeout(rehabilitarBotonPago, 100);
    });
    
    // EVENTO DIRECTO: Click en bot�n Cancelar
    if (btnCancelar) {
        btnCancelar.addEventListener('click', function(e) {
            console.log('? CANCELAR presionado');
            
            // Rehabilitar inmediatamente (antes de que se cierre el modal)
            setTimeout(function() {
                rehabilitarBotonPago();
                console.log('?? Rehabilitado despu�s de cancelar (100ms)');
            }, 100);
            
            setTimeout(function() {
                rehabilitarBotonPago();
                console.log('?? Rehabilitado despu�s de cancelar (300ms)');
            }, 300);
            
            setTimeout(function() {
                rehabilitarBotonPago();
                console.log('?? Rehabilitado despu�s de cancelar (600ms)');
            }, 600);
            
            setTimeout(function() {
                rehabilitarBotonPago();
                console.log('?? Rehabilitado despu�s de cancelar (1000ms)');
            }, 1000);
        });
    }
    
    // EVENTO DIRECTO: Click en bot�n Cerrar (X)
    if (btnCerrar) {
        btnCerrar.addEventListener('click', function(e) {
            console.log('? CERRAR (X) presionado');
            setTimeout(rehabilitarBotonPago, 100);
            setTimeout(rehabilitarBotonPago, 300);
            setTimeout(rehabilitarBotonPago, 600);
        });
    }
    
    // VIGILANTE: Revisar cada segundo si el bot�n necesita rehabilitarse
    setInterval(function() {
        const modalElement = document.getElementById('modalPago');
        const btn = document.getElementById('btnGuardarPago');
        
        if (!btn) return;
        
        // Si el modal no est� visible Y el bot�n est� deshabilitado, rehabilitar
        const modalVisible = modalElement && modalElement.classList.contains('show');
        
        if (!modalVisible && btn.disabled) {
            console.log('?? VIGILANTE: Modal cerrado, bot�n deshabilitado - rehabilitando');
            rehabilitarBotonPago();
        }
        
        // Si el modal est� visible pero el bot�n est� deshabilitado sin spinner
        if (modalVisible && btn.disabled && !btn.innerHTML.includes('spinner-border')) {
            console.log('?? VIGILANTE: Bot�n deshabilitado sin raz�n - rehabilitando');
            rehabilitarBotonPago();
        }
    }, 500); // Cada medio segundo para ser m�s r�pido
});

// Guardar pago
async function guardarPago() {
    console.log('?? Intentando guardar pago');
    
    const form = document.getElementById('formPago');
    const btn = document.getElementById('btnGuardarPago');
    
    if (!btn) {
        console.error('? No se encontr� el bot�n de guardar');
        return;
    }
    
    // Validar formulario
    if (!form.checkValidity()) {
        console.log('?? Formulario no v�lido');
        form.reportValidity();
        // Asegurar que el bot�n est� habilitado para reintentar
        rehabilitarBotonPago();
        return;
    }
    
    // Validar que el monto no exceda el pendiente
    const montoDolares = parseFloat(document.getElementById('pago_monto').value) || 0;
    const maxPendiente = parseFloat(document.getElementById('pago_monto').max) || 0;
    
    if (montoDolares > maxPendiente && maxPendiente > 0) {
        alert('? Error: El monto a pagar ($' + montoDolares.toFixed(2) + ') excede el saldo pendiente ($' + maxPendiente.toFixed(2) + ')');
        rehabilitarBotonPago();
        return;
    }
    
    if (montoDolares <= 0) {
        alert('? Error: El monto debe ser mayor que cero');
        rehabilitarBotonPago();
        return;
    }
    
    if (maxPendiente <= 0) {
        alert('?? Este club no tiene deuda pendiente');
        rehabilitarBotonPago();
        return;
    }
    
    // Deshabilitar bot�n y mostrar spinner (solo durante el guardado)
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando...';
    console.log('? Bot�n deshabilitado temporalmente para guardar');
    
    try {
        const formData = new FormData(form);
        
        // Usar la misma URL que el action del formulario
        const saveUrl = 'modules/finances/save_payment.php';
        
        console.log('?? Guardando pago en:', saveUrl);
        console.log('?? Datos del formulario:', {
            torneo_id: formData.get('torneo_id'),
            club_id: formData.get('club_id'),
            monto: formData.get('monto'),
            fecha: formData.get('fecha'),
            tipo_pago: formData.get('tipo_pago')
        });
        
        const response = await fetch(saveUrl, {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Error en la respuesta del servidor: ' + response.status);
        }
        
        const data = await response.json();
        console.log('?? Respuesta del servidor:', data);
        
        if (data.success) {
            console.log('? Pago guardado exitosamente');
            
            // Rehabilitar ANTES de cerrar (cr�tico)
            rehabilitarBotonPago();
            
            // Cerrar modal
            const modalElement = document.getElementById('modalPago');
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
            
            // Limpiar formulario
            limpiarModalPago();
            
            // Recargar p�gina para actualizar datos
            const torneoId = document.getElementById('pago_torneo_id').value;
            window.location.href = window.location.pathname + '?page=finances&torneo_id=' + torneoId + '&success=' + encodeURIComponent('Pago registrado exitosamente');
        } else {
            console.error('? Error del servidor:', data.error);
            alert('? Error: ' + (data.error || 'No se pudo guardar el pago'));
            // Rehabilitar bot�n usando la funci�n centralizada
            rehabilitarBotonPago();
        }
    } catch (error) {
        console.error('? Error al guardar pago:', error);
        alert('? Error al guardar el pago: ' + error.message);
        // Rehabilitar bot�n usando la funci�n centralizada
        rehabilitarBotonPago();
    }
}

// Ver pagos del club
async function verPagos(torneoId, clubId, clubNombre) {
    console.log('verPagos', torneoId, clubId, clubNombre);
    
    const modal = new bootstrap.Modal(document.getElementById('modalVerPagos'));
    modal.show();
    
    const body = document.getElementById('modalVerPagosBody');
    body.innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div></div>';
    
    try {
        const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
        const url = baseUrl + `/../modules/finances/get_pagos.php?torneo_id=${torneoId}&club_id=${clubId}`;
        
        console.log('Cargando pagos desde:', url);
        
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('Pagos recibidos:', data);
        
        if (data.success) {
            let html = `<h5>${clubNombre}</h5><hr>`;
            
            if (data.pagos.length > 0) {
                html += `<div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Monto</th>
                            <th>Referencia</th>
                            <th>Banco</th>
                            <th>Observaciones</th>
                        </tr></thead>
                        <tbody>`;
                
                data.pagos.forEach(p => {
                    html += `<tr>
                        <td>${p.fecha}</td>
                        <td>${p.tipo_pago}</td>
                        <td class="text-end"><strong>$${parseFloat(p.monto).toFixed(2)}</strong></td>
                        <td>${p.referencia || '-'}</td>
                        <td>${p.banco || '-'}</td>
                        <td>${p.observaciones || '-'}</td>
                    </tr>`;
                });
                
                html += `</tbody></table></div>`;
                html += `<div class="alert alert-success mt-3">
                    <strong>Total Pagado:</strong> $${parseFloat(data.total_pagado).toFixed(2)}
                </div>`;
            } else {
                html += '<div class="alert alert-warning">No hay pagos registrados para este club</div>';
            }
            
            body.innerHTML = html;
        } else {
            body.innerHTML = '<div class="alert alert-danger">Error al cargar pagos</div>';
        }
    } catch (error) {
        console.error('Error:', error);
        body.innerHTML = '<div class="alert alert-danger">Error de conexi�n</div>';
    }
}

// Enviar notificaci�n de deuda por WhatsApp
function enviarNotificacionWhatsApp(torneoId, clubId) {
    console.log('enviarNotificacionWhatsApp', torneoId, clubId);
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    window.location.href = baseUrl + `/../modules/finances/enviar_notificacion_whatsapp.php?torneo_id=${torneoId}&club_id=${clubId}`;
}

// Exportar Excel
function exportarDeudaExcel() {
    const torneoId = <?= $torneo_id ?>;
    console.log('exportarDeudaExcel', torneoId);
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    window.location.href = baseUrl + `/../modules/finances/export_excel.php?torneo_id=${torneoId}`;
}

// Exportar PDF
function exportarDeudaPDF() {
    const torneoId = <?= $torneo_id ?>;
    console.log('exportarDeudaPDF', torneoId);
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    window.location.href = baseUrl + `/../modules/finances/export_pdf.php?torneo_id=${torneoId}`;
}

console.log('Finanzas: Todas las funciones JavaScript cargadas correctamente');
</script>
