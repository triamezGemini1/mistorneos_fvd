<?php
// Si aún no se ha enviado salida, redirigir a la página 404 personalizada
if (!headers_sent()) {
    header('Location: ' . AppHelpers::url('404.php'), true, 302);
    exit;
}
// Si ya hay salida (p. ej. incluido desde layout), mostrar 404 dentro del contenido
if (!function_exists('app_base_url')) {
    require_once __DIR__ . '/../config/bootstrap.php';
    require_once __DIR__ . '/../lib/app_helpers.php';
}
$base = rtrim(app_base_url(), '/');
?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5">
    <h1 class="display-4 text-muted">404</h1>
    <p class="lead">Página no encontrada</p>
    <p class="text-secondary">La página que buscas no existe o no tienes permiso para verla.</p>
    <a href="<?= htmlspecialchars($base . '/') ?>" class="btn btn-primary">Ir al inicio</a>
  </div>
</div>
