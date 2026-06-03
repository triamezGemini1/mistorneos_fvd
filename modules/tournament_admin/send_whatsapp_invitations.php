<?php
/**
 * Enviar Invitaciones por WhatsApp a Usuarios Seleccionados
 * Abre WhatsApp Web con mensaje preformateado para cada usuario
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$torneo_id = (int)($_GET['torneo_id'] ?? 0);
$usuarios_ids = isset($_GET['usuarios']) ? explode(',', $_GET['usuarios']) : [];

if ($torneo_id <= 0 || empty($usuarios_ids)) {
    die('Parámetros inválidos');
}

$pdo = DB::pdo();
$user = Auth::user();

// Verificar acceso al torneo
if (!Auth::canAccessTournament($torneo_id)) {
    die('No tiene permisos para acceder a este torneo');
}

// Obtener información del torneo
$stmt = $pdo->prepare("
    SELECT t.*, c.nombre as club_nombre, c.delegado, c.telefono as club_telefono
    FROM tournaments t
    LEFT JOIN clubes c ON t.club_responsable = c.id
    WHERE t.id = ?
");
$stmt->execute([$torneo_id]);
$torneo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$torneo) {
    die('Torneo no encontrado');
}

// Obtener usuarios seleccionados
$placeholders = implode(',', array_fill(0, count($usuarios_ids), '?'));
$stmt = $pdo->prepare("
    SELECT u.*, c.nombre as club_nombre
    FROM usuarios u
    LEFT JOIN clubes c ON u.club_id = c.id
    WHERE u.id IN ($placeholders) AND u.role = 'usuario' AND u.status = 'approved'
");
$stmt->execute($usuarios_ids);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generar links de inscripción y mensajes
$invitaciones = [];
foreach ($usuarios as $usuario) {
    if (empty($usuario['celular'])) {
        continue; // Saltar usuarios sin teléfono
    }
    
    // Generar link de inscripción pública
    $app_url = $_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd';
    $inscripcion_link = AppHelpers::url("tournament_register.php?torneo_id=") . $torneo_id . "&user_id=" . $usuario['id'];
    
    // Formatear teléfono
    $telefono = preg_replace('/[^0-9]/', '', $usuario['celular']);
    if ($telefono && $telefono[0] == '0') {
        $telefono = substr($telefono, 1);
    }
    if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
        $telefono = '58' . $telefono;
    }
    
    // Generar mensaje
    $modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
    $clases = [1 => 'Torneo', 2 => 'Campeonato'];
    $modalidad = $modalidades[$torneo['modalidad']] ?? 'Individual';
    $clase = $clases[$torneo['clase']] ?? 'Torneo';
    
    $mensaje = "🏆 *INVITACIÓN A TORNEO*\n\n";
    $mensaje .= "Hola *" . $usuario['nombre'] . "*\n\n";
    $mensaje .= "Te invitamos a participar en nuestro torneo de dominó.\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "📋 *INFORMACIÓN DEL TORNEO*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "🏆 *Nombre:* " . $torneo['nombre'] . "\n";
    $mensaje .= "📅 *Fecha:* " . date('d/m/Y', strtotime($torneo['fechator'])) . "\n";
    if ($torneo['lugar']) {
        $mensaje .= "📍 *Lugar:* " . $torneo['lugar'] . "\n";
    }
    $mensaje .= "🏢 *Organizador:* " . $torneo['club_nombre'] . "\n";
    $mensaje .= "📊 *Modalidad:* " . $modalidad . "\n";
    $mensaje .= "🏅 *Tipo:* " . $clase . "\n";
    if ($torneo['costo'] > 0) {
        $mensaje .= "💰 *Costo:* $" . number_format($torneo['costo'], 2) . "\n";
    }
    $mensaje .= "\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "🔗 *INSCRÍBETE AQUÍ*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "Haz clic en el siguiente enlace para inscribirte:\n\n";
    $mensaje .= $inscripcion_link . "\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "¡Esperamos contar contigo! 🎲\n\n";
    $mensaje .= "_" . $torneo['club_nombre'] . "_";
    
    $mensaje_encoded = urlencode($mensaje);
    $whatsapp_url = "https://wa.me/{$telefono}?text={$mensaje_encoded}";
    
    $invitaciones[] = [
        'usuario' => $usuario,
        'whatsapp_url' => $whatsapp_url
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enviar Invitaciones por WhatsApp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .container-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="container-card">
            <div class="text-center mb-4">
                <i class="fab fa-whatsapp fa-3x text-success mb-3"></i>
                <h2>Enviando Invitaciones por WhatsApp</h2>
                <p class="text-muted">Se abrirán <?= count($invitaciones) ?> conversaciones de WhatsApp</p>
            </div>
            
            <div class="progress mb-4" style="height: 30px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                     role="progressbar" 
                     id="progressBar"
                     style="width: 0%">
                    <span id="progressText">0 / <?= count($invitaciones) ?></span>
                </div>
            </div>
            
            <div id="statusMessage" class="alert alert-info text-center">
                <i class="fas fa-spinner fa-spin me-2"></i>
                Preparando invitaciones...
            </div>
            
            <div id="invitacionesList" class="list-group mb-4" style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($invitaciones as $index => $inv): ?>
                    <div class="list-group-item" id="inv_<?= $inv['usuario']['id'] ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($inv['usuario']['nombre']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($inv['usuario']['celular']) ?></small>
                            </div>
                            <div>
                                <span class="badge bg-secondary" id="status_<?= $inv['usuario']['id'] ?>">Pendiente</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="text-center">
                <a href="index.php?page=tournament_admin&torneo_id=<?= $torneo_id ?>&action=invitar_whatsapp" 
                   class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Volver
                </a>
            </div>
        </div>
    </div>
    
    <script>
    const invitaciones = <?= json_encode($invitaciones) ?>;
    let indice = 0;
    const DELAY = 2000; // 2 segundos entre cada envío
    
    function enviarSiguiente() {
        if (indice >= invitaciones.length) {
            // Completado
            document.getElementById('statusMessage').innerHTML = `
                <i class="fas fa-check-circle me-2 text-success"></i>
                <strong>¡Todas las invitaciones han sido enviadas!</strong>
            `;
            document.getElementById('statusMessage').className = 'alert alert-success text-center';
            document.getElementById('progressBar').style.width = '100%';
            document.getElementById('progressBar').classList.remove('progress-bar-animated');
            document.getElementById('progressText').textContent = `${invitaciones.length} / ${invitaciones.length}`;
            return;
        }
        
        const invitacion = invitaciones[indice];
        const progreso = Math.round(((indice + 1) / invitaciones.length) * 100);
        
        // Actualizar progreso
        document.getElementById('progressBar').style.width = progreso + '%';
        document.getElementById('progressText').textContent = `${indice + 1} / ${invitaciones.length}`;
        
        // Actualizar estado
        const statusBadge = document.getElementById('status_' + invitacion.usuario.id);
        const invItem = document.getElementById('inv_' + invitacion.usuario.id);
        if (statusBadge) {
            statusBadge.textContent = 'Enviando...';
            statusBadge.className = 'badge bg-warning';
        }
        if (invItem) {
            invItem.classList.add('active');
        }
        
        // Abrir WhatsApp
        window.location.href = invitacion.whatsapp_url;
        
        // Marcar como enviado
        setTimeout(() => {
            if (statusBadge) {
                statusBadge.textContent = 'Enviado';
                statusBadge.className = 'badge bg-success';
            }
            if (invItem) {
                invItem.classList.remove('active');
                invItem.classList.add('list-group-item-success');
            }
        }, 500);
        
        // Siguiente invitación
        indice++;
        setTimeout(enviarSiguiente, DELAY);
    }
    
    // Iniciar proceso después de 1 segundo
    setTimeout(enviarSiguiente, 1000);
    </script>
</body>
</html>



