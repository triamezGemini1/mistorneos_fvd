<?php
/**
 * Registro de Usuario por Club
 *
 * Flujo desde Landing:
 * Paso 1: Tarjetas de entidades con organizaciones
 * Paso 2: Tarjetas de organizaciones de esa entidad
 * Paso 3: Tarjetas de clubes de esa organización + formulario de registro
 *
 * Flujo por invitación directa (club_id en URL):
 * Página única con info del club y formulario de registro (sin selector de entidad)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/LandingDataService.php';
require_once __DIR__ . '/../lib/app_helpers.php';

// Si ya está logueado, redirigir
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}


$pdo = DB::pdo();
$base_url = app_base_url();
$landingService = new LandingDataService($pdo);
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}

$step = (int)($_GET['step'] ?? 1);
$entidad_id = isset($_GET['entidad']) ? (int)$_GET['entidad'] : 0;
$org_id = isset($_GET['org']) ? (int)$_GET['org'] : 0;
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
$es_invitacion = $club_id > 0 && !isset($_GET['step']) && !isset($_GET['entidad']) && !isset($_GET['org']);

$error = '';
$success = '';

// Si es invitación directa, ir directo al paso 3 (formulario)
if ($es_invitacion) {
    $step = 3;
}

// Obtener opciones de entidad para el formulario (selector)
function getEntidadesOptions(): array {
    try {
        $pdo = DB::pdo();
        $columns = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        if (!$columns) {
            return [];
        }
        $codeCandidates = ['codigo', 'cod_entidad', 'id', 'code'];
        $nameCandidates = ['nombre', 'descripcion', 'entidad', 'nombre_entidad'];
        $codeCol = null;
        $nameCol = null;
        foreach ($columns as $col) {
            $field = strtolower($col['Field'] ?? $col['field'] ?? '');
            if (!$codeCol && in_array($field, $codeCandidates, true)) {
                $codeCol = $col['Field'] ?? $col['field'];
            }
            if (!$nameCol && in_array($field, $nameCandidates, true)) {
                $nameCol = $col['Field'] ?? $col['field'];
            }
        }
        if (!$codeCol && isset($columns[0]['Field'])) {
            $codeCol = $columns[0]['Field'];
        }
        if (!$nameCol && isset($columns[1]['Field'])) {
            $nameCol = $columns[1]['Field'];
        }
        if (!$codeCol || !$nameCol) {
            return [];
        }
        $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("No se pudo obtener entidades: " . $e->getMessage());
        return [];
    }
}

$entidades_options = getEntidadesOptions();

// Datos para cada paso: todas las entidades (las sin organizaciones se mostrarán inactivas)
$todas_entidades = $landingService->getTodasEntidadesParaRegistro();
if (empty($todas_entidades)) {
    $fallback = $landingService->getEntidadesConOrganizacionesRegistro();
    if (!empty($fallback)) {
        $todas_entidades = array_map(fn($e) => array_merge($e, ['tiene_organizaciones' => true]), $fallback);
    }
}
$organizaciones = $entidad_id > 0 ? $landingService->getOrganizacionesPorEntidad($entidad_id) : [];
$clubes = $org_id > 0 ? $landingService->getClubesPorOrganizacion($org_id) : [];

$entidad_info = null;
$org_info = null;
$club_info = null;

if ($entidad_id > 0) {
    foreach ($todas_entidades as $e) {
        if ((int)$e['id'] === $entidad_id) {
            $entidad_info = $e;
            break;
        }
    }
    if (!$entidad_info) {
        $entidad_info = ['id' => $entidad_id, 'nombre' => "Entidad $entidad_id", 'total_organizaciones' => 0, 'total_clubes' => 0];
    }
}
if ($org_id > 0) {
    foreach ($organizaciones as $o) {
        if ((int)$o['id'] === $org_id) {
            $org_info = $o;
            break;
        }
    }
}
if ($club_id > 0) {
    $org_join = $has_cod_org
        ? "LEFT JOIN organizaciones o ON (c.cod_org = o.id OR c.cod_org = o.cod_org)"
        : "LEFT JOIN organizaciones o ON c.cod_org = o.id";
    $stmt = $pdo->prepare("SELECT c.*, o.entidad as org_entidad FROM clubes c {$org_join} WHERE c.id = ? AND c.estatus = 1");
    $stmt->execute([$club_id]);
    $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($club_info && !$entidad_id && ($club_info['org_entidad'] ?? $club_info['entidad'] ?? 0) > 0) {
        $entidad_id = (int)($club_info['org_entidad'] ?? $club_info['entidad'] ?? 0);
    }
}

// Validar club en invitación
if ($es_invitacion && !$club_info) {
    $error = 'El club de la invitación no existe o está inactivo.';
    $step = 1;
    $club_id = 0;
}

// Determinar entidad para el formulario (invitación: del club; flujo normal: POST o del club seleccionado)
$entidad_form = isset($_POST['entidad']) ? (int)$_POST['entidad'] : 0;
if ($entidad_form <= 0 && $club_info) {
    $entidad_form = (int)($club_info['org_entidad'] ?? $club_info['entidad'] ?? 0);
}
if ($entidad_form <= 0 && $entidad_id > 0) {
    $entidad_form = $entidad_id;
}
$entidad_nombre = '';
foreach ($entidades_options as $e) {
    if ((int)($e['codigo'] ?? 0) === $entidad_form || (string)($e['codigo'] ?? '') === (string)$entidad_form) {
        $entidad_nombre = $e['nombre'] ?? "Entidad $entidad_form";
        break;
    }
}
if ($entidad_nombre === '' && $entidad_form > 0) {
    $entidad_nombre = "Entidad $entidad_form";
}

// Procesar registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $club_id > 0 && $club_info) {
    require_once __DIR__ . '/../lib/RateLimiter.php';
    if (!RateLimiter::canSubmit('register_by_club', 30)) {
        $error = 'Por favor espera 30 segundos antes de intentar registrarte de nuevo.';
    } else {
        CSRF::validate();

        $nacionalidad = trim($_POST['nacionalidad'] ?? 'V');
        $cedula = preg_replace('/\D/', '', trim($_POST['cedula'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');
        $sexo = strtoupper(trim($_POST['sexo'] ?? ''));
        if (!in_array($sexo, ['M', 'F', 'O'], true)) {
            $sexo = null;
        }
        $email = trim($_POST['email'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $fechnac = trim($_POST['fechnac'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim((string)($_POST['password'] ?? ''));
        $password_confirm = trim((string)($_POST['password_confirm'] ?? ''));
        $entidad_form = (int)($_POST['entidad'] ?? $entidad_form);

        if ($cedula === '' || empty($nombre) || empty($username) || empty($password) || $entidad_form <= 0) {
            $error = 'Todos los campos marcados con * son requeridos (cédula solo números)';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres';
        } elseif ($password !== $password_confirm) {
            $error = 'Las contraseñas no coinciden';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El email no es válido';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ?");
                $stmt->execute([$cedula]);
                if ($stmt->fetch()) {
                    $error = 'Ya existe un usuario registrado con esta cédula';
                } else {
                    $userData = [
                        'username' => $username,
                        'password' => $password,
                        'email' => $email ?: null,
                        'role' => 'usuario',
                        'cedula' => $cedula,
                        'nacionalidad' => in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                        'nombre' => $nombre,
                        'sexo' => $sexo,
                        'celular' => $celular ?: null,
                        'fechnac' => $fechnac ?: null,
                        'club_id' => $club_id,
                        'entidad' => $entidad_form,
                        'status' => 0,
                        '_allow_club_for_usuario' => true,
                    ];

                    $result = Security::createUser($userData);

                    if ($result['success']) {
                        RateLimiter::recordSubmit('register_by_club');
                        session_write_close();
                        header('Location: ' . AppHelpers::url('login.php', ['registered' => '1']));
                        exit;
                    } else {
                        $error = implode(', ', $result['errors']);
                    }
                }
            } catch (Exception $e) {
                $error = 'Error al registrar: ' . $e->getMessage();
            }
        }
    }
    $step = 3;
}

$landing_url = AppHelpers::url('go_landing.php');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#1a365d">
    <title>Registro por Club - La Estación del Dominó</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #1a365d; --secondary: #2d3748; --accent: #48bb78; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); min-height: 100vh; padding: 2rem 0; }
        .main-card { background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
        .header { background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 2rem; text-align: center; }
        .header i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.9; }
        .body-content { padding: 2rem; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 2rem; flex-wrap: wrap; }
        .step { width: 40px; height: 40px; border-radius: 50%; background: #e2e8f0; color: #718096; display: flex; align-items: center; justify-content: center; font-weight: 600; margin: 0 0.5rem; }
        .step.active { background: var(--accent); color: white; }
        .step.completed { background: var(--primary); color: white; }
        .step-line { width: 40px; height: 3px; background: #e2e8f0; align-self: center; }
        .step-line.completed { background: var(--primary); }
        .select-card { border: 2px solid #e2e8f0; border-radius: 15px; padding: 1.5rem; transition: all 0.3s; cursor: pointer; text-decoration: none; color: inherit; display: block; margin-bottom: 1rem; }
        .select-card:hover { border-color: var(--accent); transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: inherit; }
        .select-card.inactive { opacity: 0.55; cursor: not-allowed; pointer-events: none; background: #f8f9fa; }
        .select-card .card-logo { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary); }
        .select-card .card-logo-placeholder { width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.8rem; }
        .club-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 1rem; margin-bottom: 0.75rem; transition: all 0.3s; cursor: pointer; text-decoration: none; color: inherit; display: block; }
        .club-card:hover { border-color: var(--accent); background: #f7fafc; color: inherit; }
        .info-box { background: #e6fffa; border-left: 4px solid var(--accent); padding: 1rem; border-radius: 0 8px 8px 0; margin-bottom: 1.5rem; }
        .form-control:focus, .form-select:focus { border-color: var(--accent); box-shadow: 0 0 0 0.2rem rgba(72, 187, 120, 0.25); }
        .btn-register { background: linear-gradient(135deg, var(--accent) 0%, #38a169 100%); border: none; padding: 12px; font-weight: 600; }
        .btn-register:hover { background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); }
        .search-status { font-size: 0.85rem; margin-top: 0.5rem; }
        .selected-club-badge { background: var(--accent); color: white; padding: 0.5rem 1rem; border-radius: 20px; display: inline-block; margin-bottom: 1rem; }
        @media (max-width: 576px) { .step-line { width: 20px; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-md-10">
                <div class="main-card">
                    <div class="header">
                        <i class="fas fa-user-plus"></i>
                        <h3 class="mb-1">Registro de Jugador</h3>
                        <p class="mb-0 opacity-75">Selecciona tu club y únete a la comunidad</p>
                    </div>

                    <div class="body-content">
                        <?php if (!$es_invitacion): ?>
                        <div class="step-indicator">
                            <div class="step <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">1</div>
                            <div class="step-line <?= $step > 1 ? 'completed' : '' ?>"></div>
                            <div class="step <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">2</div>
                            <div class="step-line <?= $step > 2 ? 'completed' : '' ?>"></div>
                            <div class="step <?= $step >= 3 ? 'active' : '' ?>">3</div>
                        </div>
                        <?php endif; ?>

                        <?php if ($step === 1 && !$es_invitacion): ?>
                        <!-- PASO 1: Todas las entidades (inactivas las que no tienen organizaciones) -->
                        <div class="mb-4">
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al Inicio</a>
                        </div>
                        <h5 class="text-center mb-4"><i class="fas fa-map-marker-alt me-2"></i>Selecciona tu Entidad</h5>
                        <p class="text-center text-muted mb-4">Elige la entidad (estado/región) donde te encuentras. Las entidades sin organizaciones aparecen deshabilitadas.</p>

                        <?php if (empty($todas_entidades)): ?>
                            <div class="alert alert-warning text-center"><i class="fas fa-exclamation-triangle me-2"></i>No hay entidades disponibles.</div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($todas_entidades as $ent): ?>
                                    <?php $activa = !empty($ent['tiene_organizaciones']); ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <?php if ($activa): ?>
                                        <a href="?step=2&entidad=<?= (int)$ent['id'] ?>" class="select-card" title="Seleccionar">
                                        <?php else: ?>
                                        <div class="select-card inactive" title="Sin organizaciones registradas">
                                        <?php endif; ?>
                                            <div class="d-flex align-items-center">
                                                <div class="card-logo-placeholder me-3"><i class="fas fa-map-marker-alt"></i></div>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1"><?= htmlspecialchars($ent['nombre'] ?? '') ?></h6>
                                                    <?php if ($activa): ?>
                                                    <span class="badge bg-primary me-1"><?= (int)($ent['total_organizaciones'] ?? 0) ?> org.</span>
                                                    <span class="badge bg-success"><?= (int)($ent['total_clubes'] ?? 0) ?> clubes</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">Sin organizaciones</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($activa): ?><i class="fas fa-chevron-right text-muted"></i><?php else: ?><i class="fas fa-lock text-muted"></i><?php endif; ?>
                                            </div>
                                        <?php if ($activa): ?></a><?php else: ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php elseif ($step === 2 && $entidad_id > 0 && $entidad_info && !$es_invitacion): ?>
                        <!-- PASO 2: Organizaciones de la entidad -->
                        <div class="mb-4 d-flex flex-wrap gap-2">
                            <a href="?step=1" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Cambiar Entidad</a>
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al Inicio</a>
                        </div>
                        <h5 class="text-center mb-2"><i class="fas fa-building me-2"></i>Organizaciones en <?= htmlspecialchars($entidad_info['nombre'] ?? '') ?></h5>
                        <p class="text-center text-muted mb-4">Selecciona la organización a la que deseas afiliarte</p>

                        <?php if (empty($organizaciones)): ?>
                            <div class="alert alert-warning text-center"><i class="fas fa-exclamation-triangle me-2"></i>No hay organizaciones disponibles.</div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($organizaciones as $org): ?>
                                    <div class="col-md-6 mb-3">
                                        <a href="?step=3&entidad=<?= $entidad_id ?>&org=<?= (int)$org['id'] ?>" class="select-card">
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($org['logo'])): ?>
                                                    <img src="<?= htmlspecialchars(AppHelpers::imageUrl($org['logo'])) ?>" alt="" class="card-logo me-3">
                                                <?php else: ?>
                                                    <div class="card-logo-placeholder me-3"><i class="fas fa-building"></i></div>
                                                <?php endif; ?>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1"><?= htmlspecialchars($org['nombre'] ?? '') ?></h6>
                                                    <?php if (!empty($org['responsable'])): ?><small class="text-muted d-block"><?= htmlspecialchars($org['responsable']) ?></small><?php endif; ?>
                                                    <span class="badge bg-primary me-1"><?= (int)($org['total_clubes'] ?? 0) ?> clubes</span>
                                                    <span class="badge bg-success"><?= (int)($org['torneos_activos'] ?? 0) ?> torneos</span>
                                                </div>
                                                <i class="fas fa-chevron-right text-muted"></i>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php elseif ($step === 3 && (($org_id > 0 && $org_info && !$es_invitacion) || ($club_info && $es_invitacion))): ?>
                        <!-- PASO 3: Clubes + Formulario (o solo formulario si es invitación) -->
                        <?php if ($es_invitacion): ?>
                        <div class="mb-4">
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al Inicio</a>
                        </div>
                        <?php else: ?>
                        <div class="mb-4 d-flex flex-wrap gap-2">
                            <?php if ($club_info): ?>
                            <a href="?step=3&entidad=<?= $entidad_id ?>&org=<?= $org_id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver a clubes</a>
                            <?php endif; ?>
                            <a href="?step=2&entidad=<?= $entidad_id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Cambiar Organización</a>
                            <a href="?step=1" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Cambiar Entidad</a>
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-home me-1"></i>Volver al Inicio</a>
                        </div>
                        <h5 class="text-center mb-2"><i class="fas fa-users me-2"></i>Clubes de <?= htmlspecialchars($org_info['nombre'] ?? '') ?></h5>
                        <p class="text-center text-muted mb-4">Selecciona el club donde deseas registrarte</p>

                        <?php if (empty($clubes)): ?>
                            <div class="alert alert-warning text-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>No hay clubes disponibles.</div>
                        <?php else: ?>
                            <div class="row mb-4">
                                <?php foreach ($clubes as $c): ?>
                                    <?php
                                    $url_club = "?step=3&entidad={$entidad_id}&org={$org_id}&club_id=" . (int)$c['id'];
                                    $es_seleccionado = (int)$c['id'] === (int)$club_id;
                                    ?>
                                    <div class="col-md-6 mb-2">
                                        <a href="<?= htmlspecialchars($url_club) ?>" class="club-card <?= $es_seleccionado ? 'border-primary bg-light' : '' ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?= htmlspecialchars($c['nombre']) ?></h6>
                                                    <?php if (!empty($c['delegado'])): ?><small class="text-muted"><?= htmlspecialchars($c['delegado']) ?></small><?php endif; ?>
                                                </div>
                                                <span class="badge bg-success"><?= (int)($c['torneos_activos'] ?? 0) ?> torneos</span>
                                                <?php if ($es_seleccionado): ?><i class="fas fa-check text-success"></i><?php else: ?><i class="fas fa-chevron-right text-muted"></i><?php endif; ?>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($club_info): ?>
                        <div class="selected-club-badge">
                            <i class="fas fa-building me-2"></i><?= htmlspecialchars($club_info['nombre']) ?>
                            <?php if (!empty($club_info['delegado'])): ?><br><small class="opacity-90"><i class="fas fa-user me-1"></i><?= htmlspecialchars($club_info['delegado']) ?></small><?php endif; ?>
                        </div>

                        <?php if ($es_invitacion): ?>
                        <div class="info-box">
                            <i class="fas fa-info-circle text-success me-2"></i>
                            <strong>Invitación directa:</strong> Te registras en este club. Podrás participar en cualquier torneo de la plataforma.
                        </div>
                        <?php else: ?>
                        <div class="info-box">
                            <i class="fas fa-info-circle text-success me-2"></i>
                            Aunque te registres en este club, podrás participar en cualquier torneo de toda la plataforma sin restricciones.
                        </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                <div class="mt-3 d-flex flex-wrap gap-2 justify-content-center">
                                    <a href="index.php" class="btn btn-success"><i class="fas fa-user me-1"></i>Ingresar a mi Perfil</a>
                                    <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-primary"><i class="fas fa-home me-1"></i>Retornar al Landing</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="registerForm">
                                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                <input type="hidden" name="fechnac" id="fechnac" value="">
                                <input type="hidden" name="club_id" value="<?= (int)$club_id ?>">

                                <h6 class="text-muted mb-3"><i class="fas fa-user me-2"></i>Datos Personales</h6>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Nacionalidad *</label>
                                        <select name="nacionalidad" id="nacionalidad" class="form-select" required>
                                            <option value="V" selected>V</option>
                                            <option value="E">E</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Cédula *</label>
                                        <input type="text" name="cedula" id="cedula" class="form-control" value="" onblur="debouncedBuscarPersona()" required>
                                        <div id="busqueda_resultado" class="search-status"></div>
                                    </div>
                                    <div class="col-md-5 mb-3">
                                        <label class="form-label">Nombre Completo *</label>
                                        <input type="text" name="nombre" id="nombre" class="form-control" value="" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="sexo" class="form-label">Sexo *</label>
                                        <select name="sexo" id="sexo" class="form-select" required>
                                            <option value="">-- Seleccionar --</option>
                                            <option value="M">Masculino</option>
                                            <option value="F">Femenino</option>
                                            <option value="O">Otro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" id="email" class="form-control" value="">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Celular</label>
                                        <input type="text" name="celular" id="celular" class="form-control" value="">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Entidad (Ubicación)</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($entidad_nombre) ?>" readonly>
                                    <input type="hidden" name="entidad" value="<?= (int)$entidad_form ?>">
                                </div>

                                <hr class="my-4">
                                <h6 class="text-muted mb-3"><i class="fas fa-key me-2"></i>Credenciales de Acceso</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Nombre de Usuario *</label>
                                        <input type="text" name="username" id="username" class="form-control" value="" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Contraseña *</label>
                                        <input type="password" name="password" id="password" class="form-control" required>
                                        <small class="text-muted">Mínimo 6 caracteres</small>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Confirmar *</label>
                                        <input type="password" name="password_confirm" id="password_confirm" class="form-control" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-register btn-primary w-100 mt-3">
                                    <i class="fas fa-user-plus me-2"></i>Registrarme en <?= htmlspecialchars($club_info['nombre']) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php elseif ($step === 3 && !$club_info && !$es_invitacion): ?>
                        <p class="text-muted text-center">Debes seleccionar una entidad, organización y club para registrarte.</p>
                        <div class="text-center">
                            <a href="?step=1" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-1"></i>Empezar de nuevo</a>
                        </div>
                        <?php endif; ?>

                        <div class="text-center mt-4 pt-3 border-top">
                            <p class="text-muted mb-2">¿Ya tienes cuenta?</p>
                            <a href="login.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-sign-in-alt me-1"></i>Iniciar Sesión</a>
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm ms-2"><i class="fas fa-home me-1"></i>Inicio</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?= htmlspecialchars(rtrim($base_url ?? app_base_url(), '/') . '/assets/form-utils.js') ?>" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('registerForm');
        if (form) {
            ['cedula','nombre','email','celular','username','password','password_confirm','fechnac'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.value = el.value || '';
            });
            const nac = document.getElementById('nacionalidad');
            if (nac) nac.value = nac.value || 'V';
            const busquedaResultado = document.getElementById('busqueda_resultado');
            if (busquedaResultado) busquedaResultado.innerHTML = '';
            if (typeof preventDoubleSubmit === 'function') preventDoubleSubmit(form);
            if (typeof initCedulaValidation === 'function') initCedulaValidation('cedula');
            if (typeof initEmailValidation === 'function') initEmailValidation('email');
        }
    });
    const debouncedBuscarPersona = typeof debounce === 'function' ? debounce(buscarPersona, 400) : buscarPersona;
    function buscarPersona() {
        const cedula = document.getElementById('cedula');
        const resultadoDiv = document.getElementById('busqueda_resultado');
        if (!cedula || !cedula.value.trim()) { if (resultadoDiv) resultadoDiv.innerHTML = ''; return; }
        resultadoDiv.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Buscando...</span>';
        const nacionalidad = (document.getElementById('nacionalidad') || {}).value || 'V';
        fetch('<?= htmlspecialchars(AppHelpers::url('api/search_user_persona.php')) ?>?cedula=' + encodeURIComponent(cedula.value) + '&nacionalidad=' + encodeURIComponent(nacionalidad))
            .then(r => r.json())
            .then(data => {
                if (data?.success && data?.data?.encontrado) {
                    if (data.data.existe_usuario) {
                        resultadoDiv.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>' + (data.data.mensaje || '') + '</span>';
                    } else {
                        const p = data.data.persona || {};
                        if (cedula) cedula.value = String(p.cedula || cedula.value).replace(/\D/g, '');
                        const nacEl = document.getElementById('nacionalidad'); if (nacEl && p.nacionalidad) nacEl.value = ['V','E','J','P'].includes(String(p.nacionalidad).toUpperCase()) ? String(p.nacionalidad).toUpperCase() : nacEl.value;
                        const nom = document.getElementById('nombre'); if (nom) nom.value = p.nombre || '';
                        const cel = document.getElementById('celular'); if (cel) cel.value = p.celular || '';
                        const em = document.getElementById('email'); if (em) em.value = p.email || '';
                        const fech = document.getElementById('fechnac'); if (fech) fech.value = p.fechnac || '';
                        const sexo = document.getElementById('sexo'); if (sexo && p.sexo) { let sv = String(p.sexo).toUpperCase(); sexo.value = (sv === 'M' || sv === 'F' || sv === 'O') ? sv : sexo.value; }
                        const un = document.getElementById('username'); if (un && !un.value && p.nombre) { const np = p.nombre.toLowerCase().split(' '); if (np.length >= 2) un.value = np[0] + '.' + np[np.length-1]; }
                        resultadoDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Datos completados</span>';
                    }
                } else {
                    resultadoDiv.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>Complete manualmente</span>';
                }
            })
            .catch(() => { if (resultadoDiv) resultadoDiv.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Error</span>'; });
    }
    </script>
</body>
</html>
