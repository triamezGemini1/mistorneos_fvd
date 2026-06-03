<?php
require_once __DIR__ . '/../config/session_start_early.php';
/**
 * Acceso denegado. Patrón en bloque: conexión única → seguridad → validación inmediata.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/environment.php';
AuthService::requireAuth();
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado - Serviclubes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .access-denied-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            padding: 3rem;
            text-align: center;
            max-width: 500px;
            width: 90%;
        }
        .icon-container {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        .icon-container i {
            font-size: 2.5rem;
            color: white;
        }
        .btn-custom {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            color: white;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            margin: 0.5rem;
        }
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            color: white;
        }
        .user-info {
            background: rgba(102, 126, 234, 0.1);
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="access-denied-card">
        <div class="icon-container">
            <i class="fas fa-ban"></i>
        </div>
        
        <h1 class="h2 mb-3 text-danger">Acceso Denegado</h1>
        <p class="lead mb-4">No tienes permisos para acceder a esta sección del sistema.</p>
        
        <div class="user-info">
            <p class="mb-1"><strong>Usuario:</strong> <?= htmlspecialchars($user['username']) ?></p>
            <p class="mb-0"><strong>Rol:</strong> <?= htmlspecialchars($user['role']) ?></p>
        </div>
        
        <div class="mb-4">
            <p class="text-muted">
                Tu rol actual no te permite acceder a esta funcionalidad. 
                Contacta al administrador del sistema si necesitas acceso adicional.
            </p>
        </div>
        
        <div class="d-flex flex-column flex-md-row justify-content-center gap-2">
        <a href="<?= htmlspecialchars(AppHelpers::dashboard('registrants')) ?>" class="btn-custom">
            <i class="fas fa-users me-2"></i>Ir a Inscripciones
        </a>
        <a href="<?= htmlspecialchars(AppHelpers::logout()) ?>" class="btn-custom">
            <i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión
        </a>
        </div>
        
        <div class="mt-4">
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                Si crees que esto es un error, contacta al administrador del sistema.
            </small>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>










