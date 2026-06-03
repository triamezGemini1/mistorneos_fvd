<?php
/**
 * Link de Invitación para Torneo
 * Página dedicada para admin_club para compartir link de invitación a un torneo específico
 * Diseño Moderno y Responsivo
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';

Auth::requireRole(['admin_club']);

$current_user = Auth::user();
$user_club_id = $current_user['club_id'] ?? null;

// Obtener torneos del admin_club
$torneos = [];
$torneo_selected = null;
$error_message = '';

try {
    if (!$user_club_id) {
        throw new Exception('No tienes un club asignado');
    }
    
    // Obtener clubes gestionados
    $clubes_gestionados = ClubHelper::getClubesSupervised($user_club_id);
    
    if (empty($clubes_gestionados)) {
        throw new Exception('No tienes clubes gestionados');
    }
    
    // Obtener torneos de los clubes gestionados
    $placeholders = implode(',', array_fill(0, count($clubes_gestionados), '?'));
    $stmt = DB::pdo()->prepare("
        SELECT t.*, c.nombre as club_nombre, c.delegado, c.telefono as club_telefono
        FROM tournaments t
        LEFT JOIN clubes c ON t.club_responsable = c.id
        WHERE t.club_responsable IN ($placeholders) AND t.estatus = 1
        ORDER BY t.fechator DESC, t.nombre ASC
    ");
    $stmt->execute($clubes_gestionados);
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener torneo seleccionado
    $torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
    
    if ($torneo_id > 0) {
        $stmt = DB::pdo()->prepare("
            SELECT t.*, c.nombre as club_nombre, c.delegado, c.telefono as club_telefono, c.direccion as club_direccion
            FROM tournaments t
            LEFT JOIN clubes c ON t.club_responsable = c.id
            WHERE t.id = ? AND t.club_responsable IN ($placeholders) AND t.estatus = 1
        ");
        $stmt->execute(array_merge([$torneo_id], $clubes_gestionados));
        $torneo_selected = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$torneo_selected) {
            $error_message = 'Torneo no encontrado o no tienes permisos para acceder a él';
        }
    }
    
    // Si hay torneo seleccionado, obtener información adicional
    if ($torneo_selected && !$error_message) {
        // Obtener información adicional del administrador
        $stmt = DB::pdo()->prepare("
            SELECT u.nombre, u.username, u.email, u.celular, u.cedula
            FROM usuarios u
            WHERE u.id = ? AND u.role = 'admin_club'
        ");
        $stmt->execute([$current_user['id']]);
        $admin_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Obtener información completa del club organizador
        $stmt = DB::pdo()->prepare("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM usuarios WHERE club_id = c.id AND role = 'usuario' AND status = 'approved') as total_afiliados,
                   (SELECT COUNT(*) FROM tournaments WHERE club_responsable = c.id AND estatus = 1) as total_torneos
            FROM clubes c
            WHERE c.id = ?
        ");
        $stmt->execute([$torneo_selected['club_responsable']]);
        $club_completo = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("Error obteniendo torneos: " . $e->getMessage());
}

// Generar link de invitación si hay torneo seleccionado
$invitation_link = '';
$admin_nombre = $current_user['nombre'] ?? $current_user['username'] ?? 'Administrador';

if ($torneo_selected) {
    $app_url = $_ENV['APP_URL'] ?? (function_exists('app_base_url') ? app_base_url() : FvdConfig::resolveAppUrl());
    $invitation_link = AppHelpers::url("tournament_register.php?torneo_id=") . $torneo_selected['id'];
    
    // Obtener ruta del PDF de invitación
    $pdf_path = null;
    try {
        require_once __DIR__ . '/../../lib/InvitationPDFGenerator.php';
        $pdf_path = InvitationPDFGenerator::getTournamentPDFPath($torneo_selected['id']);
        // Si no existe el PDF, generarlo
        if (!$pdf_path) {
            $pdf_result = InvitationPDFGenerator::generateTournamentInvitationPDF($torneo_selected['id']);
            if ($pdf_result['success']) {
                $pdf_path = $pdf_result['pdf_path'];
            }
        }
    } catch (Exception $e) {
        error_log("Error obteniendo PDF del torneo: " . $e->getMessage());
    }
    $pdf_url = $pdf_path ? ($app_url . '/' . $pdf_path) : null;
} else {
    $pdf_url = null;
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Torneo', 2 => 'Campeonato'];
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --success-gradient: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
    --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    --card-shadow-hover: 0 8px 30px rgba(0, 0, 0, 0.12);
    --border-radius: 16px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.invitation-page {
    background: var(--primary-gradient);
    min-height: 100vh;
    padding: 2rem 0;
    position: relative;
    overflow-x: hidden;
}

.invitation-page::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 20% 30%, rgba(37, 211, 102, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(102, 126, 234, 0.1) 0%, transparent 50%);
    pointer-events: none;
}

.invitation-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1.5rem;
    position: relative;
    z-index: 1;
}

/* Header Moderno */
.page-header {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    border-radius: var(--border-radius);
    padding: 3rem 2.5rem;
    margin-bottom: 2rem;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
}

.page-header > div:first-child {
    text-align: center;
}

.page-header .icon-wrapper {
    width: 90px;
    height: 90px;
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    box-shadow: 0 8px 25px rgba(245, 158, 11, 0.35);
    position: relative;
    animation: float 3s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
}

.page-header .icon-wrapper::after {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    opacity: 0.3;
    z-index: -1;
    animation: pulse-ring 2s ease-out infinite;
}

@keyframes pulse-ring {
    0% { transform: scale(0.8); opacity: 1; }
    100% { transform: scale(1.2); opacity: 0; }
}

.page-header .icon-wrapper i {
    font-size: 2.75rem;
    color: white;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}

.page-header h1 {
    color: #1a202c;
    font-weight: 700;
    margin-bottom: 0.75rem;
    font-size: 2.25rem;
    letter-spacing: -0.5px;
}

.page-header p {
    color: #4a5568;
    font-size: 1.125rem;
    margin: 0;
    font-weight: 400;
}

/* Gadgets Grid */
.gadgets-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
    width: 100%;
}

@media (min-width: 1400px) {
    .gadgets-grid {
        gap: 1.25rem;
    }
}

/* Forzar que los gadgets se mantengan en una fila */
.gadgets-grid .gadget-card {
    flex: 0 0 auto;
    width: 100%;
}

.gadget-card {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    border-radius: var(--border-radius);
    overflow: hidden;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    min-height: 300px;
    max-height: 400px;
}

.gadget-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--card-shadow-hover);
}

.gadget-header {
    padding: 1.25rem 1.5rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.875rem;
    position: relative;
}

.gadget-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: rgba(255, 255, 255, 0.3);
}

.gadget-header.bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.gadget-header.bg-info { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
.gadget-header.bg-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
.gadget-header.bg-success { background: var(--success-gradient); }

.gadget-header i {
    font-size: 1.5rem;
    opacity: 0.95;
    flex-shrink: 0;
}

.gadget-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: white;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

.gadget-body {
    padding: 1.5rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
}

.gadget-body::-webkit-scrollbar {
    width: 6px;
}

.gadget-body::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.gadget-body::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 3px;
}

.info-row {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.info-row:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.info-row i {
    font-size: 1.1rem;
    margin-top: 0.2rem;
    flex-shrink: 0;
    width: 20px;
    text-align: center;
}

.info-row strong {
    display: block;
    color: #4a5568;
    font-size: 0.75rem;
    margin-bottom: 0.25rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-row span {
    color: #2d3748;
    font-size: 0.9rem;
    word-break: break-word;
    line-height: 1.4;
}

.select-modern {
    width: 100%;
    padding: 0.75rem 1rem;
    font-size: 0.9rem;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    transition: var(--transition);
    background: white;
    cursor: pointer;
}

.select-modern:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
}

.link-input-modern {
    position: relative;
}

.link-input-modern input {
    width: 100%;
    padding: 0.75rem 1rem;
    font-size: 0.85rem;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    transition: var(--transition);
    background: #f7fafc;
    font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
}

.link-input-modern input:focus {
    outline: none;
    border-color: #25D366;
    background: white;
    box-shadow: 0 0 0 4px rgba(37, 211, 102, 0.1);
}

.btn-copy-small {
    width: 100%;
    margin-top: 0.5rem;
    padding: 0.625rem 1rem;
    font-size: 0.875rem;
    font-weight: 600;
    border: none;
    border-radius: 8px;
    background: var(--success-gradient);
    color: white;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn-copy-small:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
}

.help-text-modern {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #718096;
    font-size: 0.8rem;
    margin-top: 0.5rem;
}

.empty-state {
    text-align: center;
    padding: 2rem 1rem;
    color: #718096;
}

.empty-state i {
    font-size: 2.5rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

/* Modern Card para Mensaje WhatsApp */
.modern-card {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    border-radius: var(--border-radius);
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: var(--transition);
}

.modern-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--card-shadow-hover);
}

.card-header-modern {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.25rem;
    border-bottom: 2px solid #e2e8f0;
}

.card-header-modern .icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
    background: var(--success-gradient);
}

.card-header-modern h2 {
    margin: 0;
    color: #1a202c;
    font-weight: 600;
    font-size: 1.5rem;
    letter-spacing: -0.3px;
}

.message-preview-modern {
    background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.75rem;
    margin-bottom: 1.5rem;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    white-space: pre-wrap;
    line-height: 1.7;
    font-size: 0.95rem;
    color: #2d3748;
    max-height: 450px;
    overflow-y: auto;
}

.message-preview-modern::-webkit-scrollbar {
    width: 8px;
}

.message-preview-modern::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}

.message-preview-modern::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 4px;
}

.action-buttons-modern {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.btn-modern {
    padding: 1rem 2rem;
    font-weight: 600;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.95rem;
    white-space: nowrap;
}

.btn-modern-success {
    background: var(--success-gradient);
    color: white;
    box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
}

.btn-modern-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4);
}

.btn-modern-outline {
    background: white;
    color: #25D366;
    border: 2px solid #25D366;
}

.btn-modern-outline:hover {
    background: #25D366;
    color: white;
    transform: translateY(-2px);
}

/* Responsive */
@media (max-width: 1400px) {
    .gadgets-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 0.875rem;
    }
}

@media (max-width: 1200px) {
    .gadgets-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
}

@media (max-width: 992px) {
    .invitation-container {
        padding: 0 1rem;
    }
    
    .page-header {
        padding: 2rem 1.5rem;
    }
    
    .page-header h1 {
        font-size: 1.75rem;
    }
    
    .page-header .d-flex {
        flex-direction: column;
        align-items: center !important;
    }
    
    .page-header .btn {
        margin-top: 1rem !important;
        width: 100%;
        max-width: 250px;
    }
    
    .gadgets-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
}

@media (max-width: 768px) {
    .invitation-page {
        padding: 1rem 0;
    }
    
    .page-header {
        padding: 1.5rem 1rem;
    }
    
    .page-header .icon-wrapper {
        width: 70px;
        height: 70px;
    }
    
    .page-header .icon-wrapper i {
        font-size: 2rem;
    }
    
    .page-header h1 {
        font-size: 1.5rem;
    }
    
    .gadgets-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .gadget-card {
        min-height: auto;
    }
    
    .gadget-body {
        padding: 1.25rem;
    }
    
    .modern-card {
        padding: 1.25rem;
    }
    
    .action-buttons-modern {
        flex-direction: column;
    }
    
    .btn-modern {
        width: 100%;
        justify-content: center;
    }
}

/* Animaciones */
.fade-in {
    animation: fadeIn 0.5s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<div class="invitation-page fade-in">
    <div class="invitation-container">
        <!-- Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="flex-grow-1">
                    <div class="icon-wrapper">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <h1>Link de Invitación a Torneo</h1>
                    <p>Comparte este enlace para invitar a tus contactos a participar en un torneo</p>
                </div>
                <a href="?page=home" class="btn btn-outline-light btn-sm" style="white-space: nowrap; margin-top: 0.5rem;">
                    <i class="fas fa-arrow-left me-2"></i>Volver al Dashboard
                </a>
            </div>
        </div>
        
        <?php if ($error_message): ?>
            <div class="modern-card">
                <div class="alert alert-danger border-0 mb-0" style="background: #fee; color: #c33;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Grid de 4 Gadgets -->
        <div class="gadgets-grid">
            <!-- Gadget 1: Selector de Torneo -->
            <div class="gadget-card">
                <div class="gadget-header bg-primary">
                    <i class="fas fa-trophy"></i>
                    <h3>Seleccionar Torneo</h3>
                </div>
                <div class="gadget-body">
                    <label for="torneoSelect" class="form-label fw-bold mb-3" style="font-size: 0.875rem; color: #4a5568;">
                        Elige el torneo
                    </label>
                    <select class="select-modern" id="torneoSelect" onchange="selectTournament()">
                        <option value="">-- Selecciona --</option>
                        <?php foreach ($torneos as $torneo): ?>
                            <option value="<?= $torneo['id'] ?>" <?= ($torneo_selected && $torneo_selected['id'] == $torneo['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($torneo['nombre']) ?> - <?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($torneos)): ?>
                        <div class="empty-state">
                            <i class="fas fa-info-circle"></i>
                            <p class="mb-0">No tienes torneos disponibles</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gadget 2: Información del Administrador -->
            <div class="gadget-card">
                <div class="gadget-header bg-info">
                    <i class="fas fa-user-tie"></i>
                    <h3>Administrador</h3>
                </div>
                <div class="gadget-body">
                    <?php if (isset($admin_info) && $admin_info): ?>
                        <div class="info-row">
                            <i class="fas fa-user text-info"></i>
                            <div>
                                <strong>Nombre</strong>
                                <span><?= htmlspecialchars($admin_info['nombre'] ?? $admin_info['username'] ?? 'N/A') ?></span>
                            </div>
                        </div>
                        <?php if ($admin_info['celular']): ?>
                        <div class="info-row">
                            <i class="fas fa-phone text-info"></i>
                            <div>
                                <strong>Teléfono</strong>
                                <span><?= htmlspecialchars($admin_info['celular']) ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($admin_info['email']): ?>
                        <div class="info-row">
                            <i class="fas fa-envelope text-info"></i>
                            <div>
                                <strong>Email</strong>
                                <span><?= htmlspecialchars($admin_info['email']) ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-info-circle"></i>
                            <p class="mb-0">Selecciona un torneo</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gadget 3: Información del Torneo -->
            <div class="gadget-card">
                <div class="gadget-header bg-warning">
                    <i class="fas fa-info-circle"></i>
                    <h3>Info del Torneo</h3>
                </div>
                <div class="gadget-body">
                    <?php if ($torneo_selected): ?>
                        <div class="info-row">
                            <i class="fas fa-chess text-warning"></i>
                            <div>
                                <strong>Nombre</strong>
                                <span><?= htmlspecialchars($torneo_selected['nombre']) ?></span>
                            </div>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-calendar-alt text-warning"></i>
                            <div>
                                <strong>Fecha</strong>
                                <span><?= date('d/m/Y', strtotime($torneo_selected['fechator'])) ?></span>
                            </div>
                        </div>
                        <?php if ($torneo_selected['lugar']): ?>
                        <div class="info-row">
                            <i class="fas fa-map-marker-alt text-warning"></i>
                            <div>
                                <strong>Lugar</strong>
                                <span><?= htmlspecialchars($torneo_selected['lugar']) ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <i class="fas fa-building text-warning"></i>
                            <div>
                                <strong>Organizador</strong>
                                <span><?= htmlspecialchars($torneo_selected['club_nombre']) ?></span>
                            </div>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-users text-warning"></i>
                            <div>
                                <strong>Modalidad</strong>
                                <span><?= $modalidades[$torneo_selected['modalidad']] ?? 'N/A' ?></span>
                            </div>
                        </div>
                        <?php if ($torneo_selected['costo'] > 0): ?>
                        <div class="info-row">
                            <i class="fas fa-dollar-sign text-warning"></i>
                            <div>
                                <strong>Costo</strong>
                                <span>$<?= number_format($torneo_selected['costo'], 2) ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-info-circle"></i>
                            <p class="mb-0">Selecciona un torneo</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gadget 4: Link de Invitación -->
            <div class="gadget-card">
                <div class="gadget-header bg-success">
                    <i class="fas fa-link"></i>
                    <h3>Link Invitación</h3>
                </div>
                <div class="gadget-body">
                    <?php if ($torneo_selected): ?>
                        <div class="link-input-modern">
                            <input type="text" 
                                   class="form-control" 
                                   id="invitationLink" 
                                   value="<?= htmlspecialchars($invitation_link) ?>" 
                                   readonly>
                            <button class="btn-copy-small" 
                                    type="button" 
                                    onclick="copyInvitationLink()">
                                <i class="fas fa-copy"></i>
                                <span>Copiar Link</span>
                            </button>
                        </div>
                        <div class="help-text-modern">
                            <i class="fas fa-check-circle text-success" id="copySuccess" style="display: none;"></i>
                            <span id="copyMessage" class="small">Haz clic para copiar</span>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-info-circle"></i>
                            <p class="mb-0">Selecciona un torneo</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if ($torneo_selected): ?>
            <!-- Sección: Mensaje para WhatsApp -->
            <div class="modern-card">
                <div class="card-header-modern">
                    <div class="icon-box">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                    <h2>Mensaje para WhatsApp</h2>
                </div>
                
                <p class="text-muted mb-4" style="color: #718096;">
                    <i class="fas fa-info-circle me-2 text-info"></i>
                    Usa este mensaje preformateado para invitar a tus contactos por WhatsApp.
                </p>
                
                <div class="message-preview-modern" id="whatsappMessage">
🏆 *INVITACIÓN A TORNEO DE DOMINÓ*

Hola, soy *<?= htmlspecialchars(isset($admin_info) && $admin_info ? ($admin_info['nombre'] ?? $admin_info['username'] ?? $admin_nombre) : $admin_nombre) ?>*

Te invito a participar en nuestro torneo de dominó.

━━━━━━━━━━━━━━━━━━
👤 *INFORMACIÓN DEL INVITADOR*
━━━━━━━━━━━━━━━━━━

👨‍💼 *Administrador:* <?= htmlspecialchars(isset($admin_info) && $admin_info ? ($admin_info['nombre'] ?? $admin_info['username'] ?? 'Administrador') : 'Administrador') ?>

<?php if (isset($admin_info) && $admin_info && $admin_info['celular']): ?>
📞 *Teléfono:* <?= htmlspecialchars($admin_info['celular']) ?>

<?php endif; ?>
<?php if (isset($admin_info) && $admin_info && $admin_info['email']): ?>
📧 *Email:* <?= htmlspecialchars($admin_info['email']) ?>

<?php endif; ?>
━━━━━━━━━━━━━━━━━━
🏢 *INFORMACIÓN DEL CLUB ORGANIZADOR*
━━━━━━━━━━━━━━━━━━

📍 *Nombre del Club:* <?= htmlspecialchars($torneo_selected['club_nombre']) ?>

<?php if ($torneo_selected['delegado']): ?>
👤 *Delegado:* <?= htmlspecialchars($torneo_selected['delegado']) ?>

<?php endif; ?>
<?php if ($torneo_selected['club_telefono']): ?>
📞 *Teléfono del Club:* <?= htmlspecialchars($torneo_selected['club_telefono']) ?>

<?php endif; ?>
<?php if ($torneo_selected['club_direccion']): ?>
📍 *Dirección:* <?= htmlspecialchars($torneo_selected['club_direccion']) ?>

<?php endif; ?>
<?php if (isset($club_completo) && $club_completo['total_afiliados']): ?>
👥 *Afiliados:* <?= number_format($club_completo['total_afiliados']) ?> miembros

<?php endif; ?>
<?php if (isset($club_completo) && $club_completo['total_torneos']): ?>
🏆 *Torneos Organizados:* <?= number_format($club_completo['total_torneos']) ?>

<?php endif; ?>
━━━━━━━━━━━━━━━━━━
🏆 *INFORMACIÓN DEL TORNEO*
━━━━━━━━━━━━━━━━━━

📍 *Nombre:* <?= htmlspecialchars($torneo_selected['nombre']) ?>

📅 *Fecha:* <?= date('d/m/Y', strtotime($torneo_selected['fechator'])) ?>

<?php if ($torneo_selected['lugar']): ?>
📍 *Lugar:* <?= htmlspecialchars($torneo_selected['lugar']) ?>

<?php endif; ?>
📊 *Modalidad:* <?= $modalidades[$torneo_selected['modalidad']] ?? 'N/A' ?>

🏅 *Tipo:* <?= $clases[$torneo_selected['clase']] ?? 'Torneo' ?>

<?php if ($torneo_selected['costo'] > 0): ?>
💰 *Costo de Inscripción:* $<?= number_format($torneo_selected['costo'], 2) ?>

<?php endif; ?>
<?php if ($torneo_selected['rondas']): ?>
🔄 *Rondas Programadas:* <?= $torneo_selected['rondas'] ?>

<?php endif; ?>
━━━━━━━━━━━━━━━━━━
✨ *BENEFICIOS DE PARTICIPAR*
━━━━━━━━━━━━━━━━━━

✅ Competir con jugadores de diferentes clubes
✅ Obtener reconocimiento y premios
✅ Mejorar tus habilidades de juego
✅ Disfrutar de una experiencia única
✅ Formar parte de nuestra comunidad

━━━━━━━━━━━━━━━━━━
🔗 *INSCRÍBETE AQUÍ*
━━━━━━━━━━━━━━━━━━

Haz clic en el siguiente enlace para inscribirte directamente:

<?= htmlspecialchars($invitation_link) ?>

━━━━━━━━━━━━━━━━━━
📞 *CONTACTO*
━━━━━━━━━━━━━━━━━━

Si tienes alguna pregunta, puedes contactarnos:

<?php if (isset($admin_info) && $admin_info && $admin_info['celular']): ?>
📱 *Administrador:* <?= htmlspecialchars($admin_info['celular']) ?>

<?php endif; ?>
<?php if ($torneo_selected['club_telefono']): ?>
📞 *Club:* <?= htmlspecialchars($torneo_selected['club_telefono']) ?>

<?php endif; ?>
━━━━━━━━━━━━━━━━━━

¡Esperamos contar contigo! 🎲

_<?= htmlspecialchars($torneo_selected['club_nombre']) ?>_
_La Estación del Dominó_
                </div>
                
                <div class="action-buttons-modern">
                    <button class="btn-modern btn-modern-success" 
                            onclick="copyWhatsAppMessage()"
                            type="button">
                        <i class="fas fa-copy"></i>
                        <span>Copiar Mensaje</span>
                    </button>
                    <button class="btn-modern btn-modern-outline" 
                            onclick="shareToWhatsApp()"
                            type="button">
                        <i class="fab fa-whatsapp"></i>
                        <span>Compartir por WhatsApp</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function selectTournament() {
    const select = document.getElementById('torneoSelect');
    const torneoId = select.value;
    
    if (torneoId) {
        window.location.href = '?page=tournaments/invitation_link&torneo_id=' + torneoId;
    } else {
        window.location.href = '?page=tournaments/invitation_link';
    }
}

function copyInvitationLink() {
    const linkInput = document.getElementById('invitationLink');
    if (!linkInput) return;
    
    linkInput.select();
    linkInput.setSelectionRange(0, 99999);
    
    const text = linkInput.value;
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showCopySuccess();
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const tempTextarea = document.createElement('textarea');
    tempTextarea.value = text;
    tempTextarea.style.position = 'fixed';
    tempTextarea.style.opacity = '0';
    document.body.appendChild(tempTextarea);
    tempTextarea.select();
    tempTextarea.setSelectionRange(0, 99999);
    
    try {
        document.execCommand('copy');
        document.body.removeChild(tempTextarea);
        showCopySuccess();
    } catch (err) {
        document.body.removeChild(tempTextarea);
        alert('Error al copiar. Por favor, selecciona y copia manualmente.');
    }
}

function showCopySuccess() {
    const copySuccess = document.getElementById('copySuccess');
    const copyMessage = document.getElementById('copyMessage');
    if (copySuccess && copyMessage) {
        copySuccess.style.display = 'inline';
        copyMessage.textContent = '¡Link copiado!';
        copyMessage.className = 'text-success small';
        
        setTimeout(() => {
            copySuccess.style.display = 'none';
            copyMessage.textContent = 'Haz clic para copiar';
            copyMessage.className = 'small';
        }, 3000);
    }
}

function copyWhatsAppMessage() {
    const messageDiv = document.getElementById('whatsappMessage');
    if (!messageDiv) return;
    
    const text = messageDiv.textContent || messageDiv.innerText;
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showMessageCopySuccess();
        }).catch(() => {
            fallbackCopyMessage(text);
        });
    } else {
        fallbackCopyMessage(text);
    }
}

function fallbackCopyMessage(text) {
    const tempTextarea = document.createElement('textarea');
    tempTextarea.value = text;
    tempTextarea.style.position = 'fixed';
    tempTextarea.style.opacity = '0';
    document.body.appendChild(tempTextarea);
    tempTextarea.select();
    tempTextarea.setSelectionRange(0, 99999);
    
    try {
        document.execCommand('copy');
        document.body.removeChild(tempTextarea);
        showMessageCopySuccess();
    } catch (err) {
        document.body.removeChild(tempTextarea);
        alert('Error al copiar. Por favor, selecciona y copia manualmente.');
    }
}

function showMessageCopySuccess() {
    const btn = event.target.closest('button');
    if (btn) {
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i><span>¡Copiado!</span>';
        btn.classList.remove('btn-modern-success', 'btn-modern-outline');
        btn.style.background = 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';
        btn.style.color = 'white';
        
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.style.background = '';
            btn.style.color = '';
            if (originalText.includes('Copiar Mensaje')) {
                btn.classList.add('btn-modern-success');
            } else {
                btn.classList.add('btn-modern-outline');
            }
        }, 2000);
    }
}

function shareToWhatsApp() {
    const messageDiv = document.getElementById('whatsappMessage');
    if (!messageDiv) return;
    
    let text = messageDiv.textContent || messageDiv.innerText;
    
    // Agregar información del PDF si está disponible
    const pdfUrl = '<?= isset($pdf_url) && $pdf_url ? htmlspecialchars($pdf_url, ENT_QUOTES, "UTF-8") : "" ?>';
    if (pdfUrl) {
        text += '\n\n━━━━━━━━━━━━━━━━━━\n';
        text += '📄 *DOCUMENTO PDF DE INVITACIÓN*\n';
        text += '━━━━━━━━━━━━━━━━━━\n\n';
        text += 'Descarga la invitación completa en formato PDF:\n';
        text += pdfUrl + '\n\n';
        text += 'El PDF incluye toda la información del torneo y el link de inscripción.';
    }
    
    const encodedText = encodeURIComponent(text);
    const whatsappUrl = 'https://wa.me/?text=' + encodedText;
    window.location.href = whatsappUrl;
}
</script>
