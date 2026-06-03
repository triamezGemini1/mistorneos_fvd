<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('asociacion/torneo_ver');

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$torneoId = (int) ($_GET['torneo_id'] ?? 0);
if ($torneoId <= 0 || !Auth::canAccessTournament($torneoId)) {
    header('Location: ' . AppHelpers::dashboard('asociacion_panel', ['error' => 'Torneo no válido']));
    exit;
}

$pdo = DB::pdo();
$st = $pdo->prepare('
    SELECT t.*, c.nombre AS club_nombre
    FROM tournaments t
    LEFT JOIN clubes c ON c.id = t.club_responsable
    WHERE t.id = ?
    LIMIT 1
');
$st->execute([$torneoId]);
$torneo = $st->fetch(PDO::FETCH_ASSOC);
if (!$torneo) {
    header('Location: ' . AppHelpers::dashboard('asociacion_panel', ['error' => 'Torneo no encontrado']));
    exit;
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Torneo', 2 => 'Campeonato'];
$modalidad = $modalidades[(int) ($torneo['modalidad'] ?? 1)] ?? '—';
$clase = $clases[(int) ($torneo['clase'] ?? 1)] ?? '—';
$urlPanel = AppHelpers::dashboard('asociacion_panel', ['torneo_id' => $torneoId]);
?>
<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($urlPanel) ?>">Panel asociación</a></li>
            <li class="breadcrumb-item active">Configuración del torneo</li>
        </ol>
    </nav>
    <div class="alert alert-info border-0 shadow-sm mb-4">
        <i class="fas fa-eye me-2"></i>
        <strong>Solo lectura.</strong> La configuración del torneo la define la FVD.
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h4 fw-bold mb-1"><?= htmlspecialchars((string) $torneo['nombre']) ?></h1>
            <p class="text-muted mb-0">ID <?= (int) $torneoId ?> · <?= htmlspecialchars($clase) ?> · <?= htmlspecialchars($modalidad) ?></p>
        </div>
        <a href="<?= htmlspecialchars($urlPanel) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al panel</a>
    </div>
    <div class="card shadow-sm border-0">
        <div class="card-body row g-3">
            <div class="col-md-6 col-lg-4">
                <div class="text-muted small">Fecha</div>
                <div class="fw-semibold"><?= !empty($torneo['fechator']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $torneo['fechator']))) : '—' ?></div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="text-muted small">Lugar</div>
                <div class="fw-semibold"><?= htmlspecialchars((string) ($torneo['lugar'] ?? '—')) ?></div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="text-muted small">Costo inscripción</div>
                <div class="fw-semibold">$<?= number_format((float) ($torneo['costo'] ?? 0), 2) ?></div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="text-muted small">Inscripción en línea</div>
                <div class="fw-semibold"><?= (int) ($torneo['permite_inscripcion_linea'] ?? 1) === 1 ? 'Permitida' : 'Solo en sitio' ?></div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="text-muted small">Evento masivo</div>
                <div class="fw-semibold"><?= (int) ($torneo['es_evento_masivo'] ?? 0) > 0 ? 'Sí' : 'No' ?></div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="text-muted small">Club responsable</div>
                <div class="fw-semibold"><?= htmlspecialchars((string) ($torneo['club_nombre'] ?? '—')) ?></div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="text-muted small">Rondas / puntos / tiempo</div>
                <div class="fw-semibold"><?= (int) ($torneo['rondas'] ?? 0) ?> rondas · <?= (int) ($torneo['puntos'] ?? 0) ?> pts · <?= (int) ($torneo['tiempo'] ?? 0) ?> min</div>
            </div>
        </div>
    </div>
</div>
