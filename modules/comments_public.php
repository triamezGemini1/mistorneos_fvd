<?php
/**
 * Módulo para que admin_club pueda ver comentarios públicos y enviar comentarios
 * Similar al landing pero dentro del dashboard
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('comments_public');

// Verificar que solo admin_club pueda acceder
Auth::requireRole(['admin_club']);

$user = Auth::user();
$pdo = DB::pdo();

// Obtener comentarios aprobados
$comentarios = [];
try {
    $stmt = $pdo->query("
        SELECT 
            c.*,
            u.username as usuario_username,
            u.nombre as usuario_nombre
        FROM comentariossugerencias c
        LEFT JOIN usuarios u ON c.usuario_id = u.id
        WHERE c.estatus = 'aprobado'
        ORDER BY c.fecha_creacion DESC
        LIMIT 20
    ");
    $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo comentarios: " . $e->getMessage());
}
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-2">
                <i class="fas fa-comment-dots text-primary"></i> Comentarios y Testimonios
            </h1>
            <p class="text-muted">La opinión de nuestra comunidad es muy importante para nosotros</p>
        </div>
    </div>

    <!-- Mensajes de éxito/error -->
    <?php if (isset($_SESSION['comment_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?= htmlspecialchars($_SESSION['comment_success']); unset($_SESSION['comment_success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['comment_errors'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <ul class="mb-0">
            <?php foreach ($_SESSION['comment_errors'] as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php unset($_SESSION['comment_errors']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Formulario de Comentarios -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-comment-dots me-2"></i>Envía tu Comentario
                    </h5>
                </div>
                <div class="card-body">
                    <form action="<?= htmlspecialchars(AppHelpers::dashboard('comments/save') . '&from=dashboard') ?>" method="POST" id="comment-form">
                        <?php 
                        require_once __DIR__ . '/../config/csrf.php';
                        $csrf_token = CSRF::token();
                        ?>
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-user-check me-2"></i>
                            <small>Comentando como: <strong><?= htmlspecialchars($user['nombre'] ?? $user['username']) ?></strong></small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Tipo <span class="text-danger">*</span></label>
                            <select name="tipo" required class="form-select">
                                <option value="comentario">Comentario</option>
                                <option value="sugerencia">Sugerencia</option>
                                <option value="testimonio">Testimonio</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Calificación (opcional)</label>
                            <div class="d-flex gap-2" id="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <label class="cursor-pointer">
                                    <input type="radio" name="calificacion" value="<?= $i ?>" class="d-none star-rating">
                                    <i class="far fa-star text-warning fs-4 hover-text-warning"></i>
                                </label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Mensaje <span class="text-danger">*</span></label>
                            <textarea name="contenido" rows="5" required 
                                      placeholder="Escribe tu comentario aquí..." 
                                      class="form-control"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-paper-plane me-2"></i>Enviar Comentario
                        </button>
                        
                        <p class="text-muted small text-center mt-3 mb-0">
                            <i class="fas fa-shield-alt me-1"></i>Los comentarios son moderados antes de publicarse
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Lista de Comentarios -->
        <div class="col-lg-8">
            <?php if (!empty($comentarios)): ?>
                <!-- Estadísticas -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="display-6 text-primary mb-1">
                                    <?= count(array_filter($comentarios, fn($c) => $c['tipo'] === 'comentario')) ?>
                                </div>
                                <div class="text-muted small">Comentarios</div>
                            </div>
                            <div class="col-4">
                                <div class="display-6 text-purple mb-1">
                                    <?= count(array_filter($comentarios, fn($c) => $c['tipo'] === 'sugerencia')) ?>
                                </div>
                                <div class="text-muted small">Sugerencias</div>
                            </div>
                            <div class="col-4">
                                <div class="display-6 text-warning mb-1">
                                    <?= count(array_filter($comentarios, fn($c) => $c['tipo'] === 'testimonio')) ?>
                                </div>
                                <div class="text-muted small">Testimonios</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Lista de Comentarios -->
                <div class="space-y-3">
                    <?php foreach ($comentarios as $comentario): ?>
                    <div class="card shadow-sm hover-shadow transition-shadow">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                         style="width: 48px; height: 48px; font-weight: bold;">
                                        <?= strtoupper(substr($comentario['nombre'], 0, 1)) ?>
                                    </div>
                                    <div class="ms-3">
                                        <h6 class="mb-0 fw-bold">
                                            <?= htmlspecialchars($comentario['nombre']) ?>
                                            <?php if ($comentario['usuario_username']): ?>
                                                <span class="badge bg-info text-dark ms-2">
                                                    <i class="fas fa-user-check"></i> Usuario registrado
                                                </span>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?= date('d/m/Y H:i', strtotime($comentario['fecha_creacion'])) ?>
                                        </small>
                                    </div>
                                </div>
                                <?php
                                $tipo_badges = ['comentario' => 'bg-primary', 'sugerencia' => 'bg-purple', 'testimonio' => 'bg-warning'];
                                $tipo_icons = ['comentario' => 'fa-comment-dots', 'sugerencia' => 'fa-lightbulb', 'testimonio' => 'fa-star'];
                                $t = $comentario['tipo'] ?? 'comentario';
                                ?>
                                <span class="badge <?= $tipo_badges[$t] ?? 'bg-secondary' ?>">
                                    <i class="fas <?= $tipo_icons[$t] ?? 'fa-circle' ?> me-1"></i>
                                    <?= ucfirst($t) ?>
                                </span>
                            </div>
                            
                            <?php if ($comentario['calificacion']): ?>
                            <div class="mb-2">
                                <?php for ($i = 0; $i < 5; $i++): ?>
                                    <i class="fas fa-star <?= $i < $comentario['calificacion'] ? 'text-warning' : 'text-muted' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <?php endif; ?>
                            
                            <p class="mb-0 text-muted" style="white-space: pre-wrap;">
                                <?= nl2br(htmlspecialchars($comentario['contenido'])) ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card shadow-sm text-center py-5">
                    <div class="card-body">
                        <i class="fas fa-comment-slash text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 mb-2">No hay comentarios aún</h4>
                        <p class="text-muted mb-0">Sé el primero en compartir tu opinión con la comunidad.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Estrellas interactivas para calificación
    const starInputs = document.querySelectorAll('.star-rating');
    starInputs.forEach(input => {
        input.addEventListener('change', function() {
            const value = parseInt(this.value);
            const container = this.closest('#rating-stars');
            const stars = container.querySelectorAll('i');
            stars.forEach((star, index) => {
                if (index < value) {
                    star.classList.remove('far');
                    star.classList.add('fas');
                    star.classList.add('text-warning');
                } else {
                    star.classList.remove('fas');
                    star.classList.add('far');
                    star.classList.remove('text-warning');
                }
            });
        });
    });
});
</script>

<style>
.cursor-pointer {
    cursor: pointer;
}
.space-y-3 > * + * {
    margin-top: 1rem;
}
.hover-shadow {
    transition: box-shadow 0.2s;
}
.hover-shadow:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}
.transition-shadow {
    transition: box-shadow 0.2s;
}
.text-purple {
    color: #6f42c1;
}
.bg-purple {
    background-color: #6f42c1;
}
</style>

