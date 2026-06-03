<?php
/**
 * Enviar Invitaciones por WhatsApp a Jugadores
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';

Auth::requireRole(['admin_club']);

$pdo = DB::pdo();
$user = Auth::user();
$base_url = app_base_url();

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$jugadores_ids = isset($_GET['jugadores']) ? explode(',', $_GET['jugadores']) : [];

if (empty($torneo_id) || empty($jugadores_ids)) {
    header('Location: index.php?page=player_invitations&error=' . urlencode('Parámetros inválidos'));
    exit;
}

// Obtener datos del torneo
$club_principal_id = $user['club_id'];
$clubes_gestionados = ClubHelper::getClubesSupervised($club_principal_id);
$placeholders = implode(',', array_fill(0, count($clubes_gestionados), '?'));

$stmt = $pdo->prepare("
    SELECT t.*, c.nombre as club_nombre, c.delegado, c.telefono, c.email as club_email
    FROM tournaments t
    LEFT JOIN clubes c ON t.club_responsable = c.id
    WHERE t.id = ? AND t.club_responsable IN ($placeholders)
");
$stmt->execute(array_merge([$torneo_id], $clubes_gestionados));
$torneo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$torneo) {
    header('Location: index.php?page=player_invitations&error=' . urlencode('Torneo no encontrado o sin permisos'));
    exit;
}

// Obtener jugadores seleccionados
$jugadores_placeholders = implode(',', array_fill(0, count($jugadores_ids), '?'));
$stmt = $pdo->prepare("
    SELECT u.*, c.nombre as club_nombre
    FROM usuarios u
    LEFT JOIN clubes c ON u.club_id = c.id
    WHERE u.id IN ($jugadores_placeholders) AND u.club_id IN ($placeholders) AND u.role = 'usuario'
");
$stmt->execute(array_merge($jugadores_ids, $clubes_gestionados));
$jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Abierto', 2 => 'Por Categorías'];

// Generar links de inscripción para cada jugador
$invitaciones = [];
foreach ($jugadores as $jugador) {
    if (empty($jugador['celular'])) {
        continue; // Saltar jugadores sin teléfono
    }
    
    // Generar token único para la invitación directa
    $token = bin2hex(random_bytes(16));
    
    // Crear registro de invitación directa (si no existe tabla, la creamos)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS player_invitations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                torneo_id INT NOT NULL,
                user_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                enviado_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                usado_at DATETIME NULL,
                FOREIGN KEY (torneo_id) REFERENCES tournaments(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    } catch (Exception $e) {
        // Tabla ya existe
    }
    
    // Verificar si ya existe invitación
    $stmt = $pdo->prepare("SELECT id, token FROM player_invitations WHERE torneo_id = ? AND user_id = ?");
    $stmt->execute([$torneo_id, $jugador['id']]);
    $inv_existente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($inv_existente) {
        $token = $inv_existente['token'];
    } else {
        // Crear nueva invitación
        $stmt = $pdo->prepare("INSERT INTO player_invitations (torneo_id, user_id, token) VALUES (?, ?, ?)");
        $stmt->execute([$torneo_id, $jugador['id'], $token]);
    }
    
    // Generar link de inscripción directa
    $inscripcion_link = AppHelpers::url('player_register.php', ['torneo' => $torneo_id, 'token' => $token]);
    
    // Formatear teléfono
    $telefono = preg_replace('/[^0-9]/', '', $jugador['celular']);
    if ($telefono && $telefono[0] == '0') {
        $telefono = substr($telefono, 1);
    }
    if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
        $telefono = '58' . $telefono;
    }
    
    // Generar mensaje
    $mensaje = "🎲 *INVITACIÓN A TORNEO*\n\n";
    $mensaje .= "Hola *" . $jugador['nombre'] . "*\n\n";
    $mensaje .= "¡Has sido invitado a participar en un torneo!\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "🏆 *INFORMACIÓN DEL TORNEO*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "📋 *Torneo:* " . $torneo['nombre'] . "\n";
    $mensaje .= "📅 *Fecha:* " . date('d/m/Y', strtotime($torneo['fechator'])) . "\n";
    if ($torneo['lugar']) {
        $mensaje .= "📍 *Lugar:* " . $torneo['lugar'] . "\n";
    }
    $mensaje .= "🎯 *Modalidad:* " . ($modalidades[$torneo['modalidad']] ?? 'N/A') . "\n";
    $mensaje .= "📊 *Clase:* " . ($clases[$torneo['clase']] ?? 'N/A') . "\n";
    if ($torneo['costo'] > 0) {
        $mensaje .= "💰 *Costo:* $" . number_format($torneo['costo'], 2) . "\n";
    }
    $mensaje .= "\n";
    $mensaje .= "🏢 *Organizado por:*\n";
    $mensaje .= $torneo['club_nombre'] . "\n";
    if ($torneo['delegado']) {
        $mensaje .= "👤 " . $torneo['delegado'] . "\n";
    }
    if ($torneo['telefono']) {
        $mensaje .= "📞 " . $torneo['telefono'] . "\n";
    }
    $mensaje .= "\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "🔗 *INSCRÍBETE AQUÍ:*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= $inscripcion_link . "\n\n";
    $mensaje .= "👉 Haz clic en el enlace para inscribirte directamente al torneo.\n\n";
    $mensaje .= "¡Te esperamos! 🎲\n\n";
    $mensaje .= "_La Estación del Dominó_";
    
    $mensaje_encoded = urlencode($mensaje);
    
    if ($telefono && strlen($telefono) >= 10) {
        $whatsapp_url = "https://api.whatsapp.com/send?phone={$telefono}&text={$mensaje_encoded}";
    } else {
        $whatsapp_url = "https://api.whatsapp.com/send?text={$mensaje_encoded}";
    }
    
    $invitaciones[] = [
        'jugador' => $jugador,
        'telefono' => $telefono,
        'telefono_formateado' => $telefono ? '+' . $telefono : '',
        'mensaje' => $mensaje,
        'whatsapp_url' => $whatsapp_url,
        'inscripcion_link' => $inscripcion_link
    ];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Enviar Invitaciones - La Estación del Dominó</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            max-width: 900px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            color: white;
            padding: 30px;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .mensaje-preview {
            background: #DCF8C6;
            border-radius: 10px;
            padding: 15px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
            position: relative;
        }
        .jugador-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        .jugador-card:hover {
            border-color: #25D366;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="container-card">
    <div class="header">
        <h2 class="mb-0"><i class="fab fa-whatsapp me-2"></i>Enviar Invitaciones por WhatsApp</h2>
        <p class="mb-0 opacity-75">Torneo: <?= htmlspecialchars($torneo['nombre']) ?></p>
    </div>
    
    <div class="p-4">
        <?php if (empty($invitaciones)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Los jugadores seleccionados no tienen teléfono registrado.
            </div>
            <div class="text-center">
                <a href="?page=player_invitations&torneo_id=<?= $torneo_id ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </a>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Se enviarán invitaciones a <strong><?= count($invitaciones) ?></strong> jugador(es). 
                Haz clic en cada botón para enviar por WhatsApp.
            </div>
            
            <?php foreach ($invitaciones as $index => $inv): ?>
                <div class="jugador-card">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h6 class="mb-1">
                                <i class="fas fa-user me-2"></i><?= htmlspecialchars($inv['jugador']['nombre']) ?>
                            </h6>
                            <small class="text-muted">
                                <i class="fas fa-phone me-1"></i><?= htmlspecialchars($inv['telefono_formateado']) ?><br>
                                <i class="fas fa-building me-1"></i><?= htmlspecialchars($inv['jugador']['club_nombre']) ?>
                            </small>
                        </div>
                        <div class="col-md-6 text-end">
                            <button class="btn btn-success btn-sm mb-2" 
                                    onclick="copiarMensaje(<?= $index ?>)"
                                    title="Copiar mensaje">
                                <i class="fas fa-copy me-1"></i>Copiar
                            </button>
                            <a href="<?= htmlspecialchars($inv['whatsapp_url']) ?>" 
                               class="btn btn-success">
                                <i class="fab fa-whatsapp me-1"></i>Enviar por WhatsApp
                            </a>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button class="btn btn-link btn-sm p-0 text-start" 
                                type="button" 
                                data-bs-toggle="collapse" 
                                data-bs-target="#mensaje<?= $index ?>">
                            <i class="fas fa-eye me-1"></i>Ver mensaje
                        </button>
                        <div class="collapse mt-2" id="mensaje<?= $index ?>">
                            <div class="mensaje-preview"><?= htmlspecialchars($inv['mensaje']) ?></div>
                        </div>
                    </div>
                </div>
                
                <textarea id="mensaje<?= $index ?>" style="position: absolute; left: -9999px;"><?= htmlspecialchars($inv['mensaje']) ?></textarea>
            <?php endforeach; ?>
            
            <div class="text-center mt-4">
                <a href="?page=player_invitations&torneo_id=<?= $torneo_id ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
function copiarMensaje(index) {
    const textarea = document.getElementById('mensaje' + index);
    textarea.select();
    document.execCommand('copy');
    alert('✅ Mensaje copiado al portapapeles');
}
</script>

</body>
</html>


