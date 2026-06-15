<?php
require_once __DIR__ . '/../config/session_start_early.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';

AuthService::requireAuth();

$userId = (int)($_GET['user_id'] ?? Auth::id());
if ($userId !== (int)Auth::id()) {
    http_response_code(403);
    echo 'No autorizado';
    exit;
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_data) {
    http_response_code(404);
    echo 'Usuario no encontrado';
    exit;
}

$public_url = rtrim(AppHelpers::getPublicUrl(), '/');
$photo_url = AppHelpers::userPhotoUrl($user_data['photo_path'] ?? '');
$credencial_acceso_url = $public_url . '/entrar_credencial.php?id=' . $userId;
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=1&data=' . rawurlencode($credencial_acceso_url);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credencial - <?= htmlspecialchars($user_data['nombre'] ?? $user_data['username']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: #f0f0f0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .credential-wrapper { margin-bottom: 2rem; }
        .credential {
            width: 350px;
            height: 550px;
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            border-radius: 20px;
            padding: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }
        .credential-header { text-align: center; margin-bottom: 1.5rem; position: relative; z-index: 1; }
        .credential-header h3 { font-size: 1.1rem; font-weight: 600; letter-spacing: 1px; }
        .credential-header small { opacity: 0.7; font-size: 0.75rem; }
        .credential-photo {
            width: 120px; height: 120px; border-radius: 50%; object-fit: cover;
            border: 4px solid #48bb78; margin: 0 auto 1rem; display: block; position: relative; z-index: 1;
        }
        .credential-photo-placeholder {
            width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,0.2);
            margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center;
            font-size: 3rem; position: relative; z-index: 1;
        }
        .credential-info { text-align: center; position: relative; z-index: 1; }
        .credential-name { font-size: 1.4rem; font-weight: 700; margin-bottom: 0.25rem; }
        .credential-cedula { opacity: 0.8; font-size: 0.95rem; margin-bottom: 1rem; }
        .credential-uuid {
            background: rgba(255,255,255,0.15); padding: 0.5rem 1rem; border-radius: 20px;
            font-family: 'Courier New', monospace; font-size: 0.7rem; letter-spacing: 0.5px;
            display: inline-block; margin-bottom: 1rem;
        }
        .credential-qr { background: white; padding: 10px; border-radius: 10px; display: inline-block; margin-top: 0.5rem; }
        .credential-qr img { display: block; width: 100px; height: 100px; }
        .credential-footer {
            position: absolute; bottom: 1.5rem; left: 0; right: 0; text-align: center;
            font-size: 0.65rem; opacity: 0.5; z-index: 1;
        }
        .actions { display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center; }
        .btn {
            padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-family: 'Poppins', sans-serif;
            font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex;
            align-items: center; gap: 0.5rem;
        }
        .btn-primary { background: #48bb78; color: white; }
        .btn-secondary { background: #4a5568; color: white; }
    </style>
</head>
<body>
    <div class="credential-wrapper">
        <div class="credential" id="credential">
            <div class="credential-header">
                <h3>LA ESTACION DEL DOMINO</h3>
                <small>Credencial de Jugador</small>
            </div>
            <?php if ($photo_url): ?>
                <img src="<?= htmlspecialchars($photo_url) ?>" alt="Foto" class="credential-photo" crossorigin="anonymous">
            <?php else: ?>
                <div class="credential-photo-placeholder">&#128100;</div>
            <?php endif; ?>
            <div class="credential-info">
                <div class="credential-name"><?= htmlspecialchars($user_data['nombre'] ?? $user_data['username']) ?></div>
                <div class="credential-cedula"><?= htmlspecialchars($user_data['cedula'] ?? 'N/A') ?></div>
                <div class="credential-uuid"><?= htmlspecialchars($user_data['uuid'] ?? 'N/A') ?></div>
                <div class="credential-qr">
                    <img src="<?= htmlspecialchars($qr_url) ?>" alt="QR Code" crossorigin="anonymous">
                </div>
            </div>
            <div class="credential-footer">Escanee para acceder a su perfil</div>
        </div>
    </div>
    <div class="actions">
        <button class="btn btn-primary" onclick="downloadCredential()">Descargar imagen</button>
        <a href="user_portal.php?section=credencial" class="btn btn-secondary">Volver</a>
    </div>
    <script>
    function downloadCredential() {
        const credential = document.getElementById('credential');
        html2canvas(credential, { scale: 2, useCORS: true, allowTaint: true, backgroundColor: null }).then(canvas => {
            const link = document.createElement('a');
            link.download = 'credencial_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $user_data['nombre'] ?? $user_data['username']) ?>.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    }
    </script>
</body>
</html>
