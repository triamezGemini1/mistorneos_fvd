<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/AtletasAdminSyncService.php';
require_once __DIR__ . '/../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('admin_atletas_sync');

Auth::requireRole(['admin_general']);

$flash = $_SESSION['admin_atletas_sync_flash'] ?? null;
unset($_SESSION['admin_atletas_sync_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrf)) {
        $_SESSION['admin_atletas_sync_flash'] = [
            'ok' => false,
            'title' => 'Token CSRF invalido',
            'message' => 'Recargue la pagina e intente nuevamente.',
        ];
        header('Location: index.php?page=admin_atletas_sync');
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');

    try {
        if ($accion === 'copiar_atletas') {
            $result = AtletasAdminSyncService::copiarAtletasDesdeConverma(DB::pdo(), DB::pdoSecondary());
            $_SESSION['admin_atletas_sync_flash'] = [
                'ok' => true,
                'title' => 'Copia completada',
                'message' => 'Atletas copiados desde converma: ' . (int)$result['copiados'],
                'data' => $result,
            ];
        } elseif ($accion === 'sync_usuarios') {
            $csvDir = __DIR__ . '/../storage/reports';
            $result = AtletasAdminSyncService::sincronizarUsuariosDesdeAtletas(DB::pdo(), $csvDir);
            $_SESSION['admin_atletas_sync_flash'] = [
                'ok' => true,
                'title' => 'Sincronizacion completada',
                'message' => 'Usuarios actualizados: ' . (int)$result['actualizados'],
                'data' => $result,
            ];
        } elseif ($accion === 'incluir_faltantes') {
            $result = AtletasAdminSyncService::incluirAtletasFaltantesComoUsuarios(DB::pdo());
            $_SESSION['admin_atletas_sync_flash'] = [
                'ok' => true,
                'title' => 'Inclusión completada',
                'message' => 'Usuarios creados desde atletas faltantes: ' . (int)$result['creados'],
                'data' => $result,
            ];
        } elseif ($accion === 'homologar_utf8') {
            $result = AtletasAdminSyncService::homologarUtf8AtletasUsuarios(DB::pdo());
            $_SESSION['admin_atletas_sync_flash'] = [
                'ok' => true,
                'title' => 'Homologación UTF-8 completada',
                'message' => 'Atletas actualizados: ' . (int)$result['atletas_actualizados'] . ' | Usuarios actualizados: ' . (int)$result['usuarios_actualizados'],
                'data' => $result,
            ];
        } else {
            $_SESSION['admin_atletas_sync_flash'] = [
                'ok' => false,
                'title' => 'Accion invalida',
                'message' => 'No se pudo procesar la solicitud.',
            ];
        }
    } catch (Throwable $e) {
        error_log('admin_atletas_sync: ' . $e->getMessage());
        $_SESSION['admin_atletas_sync_flash'] = [
            'ok' => false,
            'title' => 'Error',
            'message' => $e->getMessage(),
        ];
    }

    header('Location: index.php?page=admin_atletas_sync');
    exit;
}
?>
<div class="container-fluid py-3">
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h4 class="mb-2"><i class="fas fa-database me-2 text-primary"></i>Sincronizacion Atletas -> Usuarios</h4>
            <p class="text-muted mb-0">
                Uso exclusivo de <strong>admin_general</strong>. Incluye copia de tabla <code>atletas</code> desde converma y
                actualizacion masiva de usuarios por <code>cedula</code>.
            </p>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert <?= !empty($flash['ok']) ? 'alert-success' : 'alert-danger' ?>">
            <strong><?= htmlspecialchars((string)($flash['title'] ?? 'Resultado')) ?>:</strong>
            <?= htmlspecialchars((string)($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <strong>1) Copiar tabla atletas (converma -> mistorneos)</strong>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Reemplaza completamente la tabla <code>atletas</code> local con la tabla <code>atletas</code> de la base secundaria.
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="copiar_atletas">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-copy me-1"></i> Copiar atletas desde converma
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <strong>2) Actualizar usuarios desde atletas (por cedula)</strong>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Campos sincronizados:</p>
                    <ul class="small mb-3">
                        <li><code>sexo</code> <- <code>sexo</code></li>
                        <li><code>numfvd</code> <- <code>numfvd</code></li>
                        <li><code>club_id</code> <- <code>asociacion</code></li>
                        <li><code>celular</code> <- <code>celular</code></li>
                        <li><code>email</code> <- <code>email</code></li>
                        <li><code>fechnac</code> <- <code>fechnac</code></li>
                    </ul>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="sync_usuarios">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-sync-alt me-1"></i> Ejecutar actualizacion de usuarios
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <strong>3) Incluir atletas faltantes en usuarios</strong>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Crea usuarios para atletas que aún no existan en <code>usuarios</code>, usando el flujo estándar
                        <code>Security::createUser</code> y completando datos de atleta.
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="incluir_faltantes">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-user-plus me-1"></i> Incluir atletas faltantes
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <strong>4) Homologar caracteres UTF-8</strong>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Normaliza caracteres en <code>atletas</code> y <code>usuarios</code> (tildes, eñes y símbolos),
                        para prevenir pérdida de información por diferencias de codificación.
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="homologar_utf8">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="fas fa-language me-1"></i> Ejecutar homologación UTF-8
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($flash['data']) && is_array($flash['data'])): ?>
        <?php $d = $flash['data']; ?>
        <div class="card mt-3 shadow-sm">
            <div class="card-header bg-white">
                <strong>Reporte de ejecucion</strong>
            </div>
            <div class="card-body">
                <?php if (isset($d['total_atletas'])): ?>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3"><span class="badge bg-secondary">Atletas: <?= (int)$d['total_atletas'] ?></span></div>
                        <div class="col-md-3"><span class="badge bg-info text-dark">Coincidencias: <?= (int)$d['coincidencias'] ?></span></div>
                        <div class="col-md-3"><span class="badge bg-success">Actualizados: <?= (int)$d['actualizados'] ?></span></div>
                        <div class="col-md-3"><span class="badge bg-warning text-dark">Sin cambios: <?= (int)$d['sin_cambios'] ?></span></div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><small>Celulares actualizados: <strong><?= (int)$d['celulares_actualizados'] ?></strong></small></div>
                        <div class="col-md-4"><small>Emails actualizados: <strong><?= (int)$d['email_actualizados'] ?></strong></small></div>
                        <div class="col-md-4"><small>Fechas nac. actualizadas: <strong><?= (int)$d['fechnac_actualizados'] ?></strong></small></div>
                        <div class="col-md-4"><small>Club ID actualizados: <strong><?= (int)$d['club_id_actualizados'] ?></strong></small></div>
                        <div class="col-md-4"><small>Entidad actualizadas: <strong><?= (int)($d['entidad_actualizados'] ?? 0) ?></strong></small></div>
                        <div class="col-md-4"><small>cod_org actualizados: <strong><?= (int)($d['cod_org_actualizados'] ?? $d['organizacion_id_actualizados'] ?? 0) ?></strong></small></div>
                        <div class="col-md-4"><small>NumFVD actualizados: <strong><?= (int)$d['numfvd_actualizados'] ?></strong></small></div>
                        <div class="col-md-4"><small>Sexo actualizados: <strong><?= (int)$d['sexo_actualizados'] ?></strong></small></div>
                    </div>

                    <p class="mb-2">
                        Cedulas no encontradas: <strong><?= (int)$d['no_encontradas'] ?></strong>
                        <?php if (!empty($d['csv_path'])): ?>
                            <br><small>CSV generado en: <code><?= htmlspecialchars((string)$d['csv_path']) ?></code></small>
                        <?php endif; ?>
                    </p>

                    <?php if (!empty($d['por_club']) && is_array($d['por_club'])): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Club ID</th>
                                        <th>Total actualizados</th>
                                        <th>M</th>
                                        <th>F</th>
                                        <th>O</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($d['por_club'] as $clubId => $row): ?>
                                    <tr>
                                        <td><?= (int)$clubId ?></td>
                                        <td><?= (int)($row['total'] ?? 0) ?></td>
                                        <td><?= (int)($row['m'] ?? 0) ?></td>
                                        <td><?= (int)($row['f'] ?? 0) ?></td>
                                        <td><?= (int)($row['o'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php elseif (isset($d['copiados'])): ?>
                    <p class="mb-1">Registros copiados: <strong><?= (int)$d['copiados'] ?></strong></p>
                    <p class="mb-0 small text-muted">Columnas: <?= htmlspecialchars(implode(', ', (array)($d['columnas'] ?? []))) ?></p>
                <?php elseif (isset($d['atletas_procesados'])): ?>
                    <p class="mb-1">Atletas procesados: <strong><?= (int)$d['atletas_procesados'] ?></strong></p>
                    <p class="mb-1">Ya existían en usuarios: <strong><?= (int)$d['ya_existian'] ?></strong></p>
                    <p class="mb-1">Usuarios creados: <strong><?= (int)$d['creados'] ?></strong></p>
                    <p class="mb-0">Errores: <strong><?= (int)$d['errores'] ?></strong></p>
                    <?php if (!empty($d['detalle_errores']) && is_array($d['detalle_errores'])): ?>
                        <div class="mt-2 small text-danger">
                            <?= htmlspecialchars(implode(' | ', array_slice($d['detalle_errores'], 0, 5))) ?>
                        </div>
                    <?php endif; ?>
                <?php elseif (isset($d['atletas_revisados'])): ?>
                    <p class="mb-1">Atletas revisados: <strong><?= (int)$d['atletas_revisados'] ?></strong></p>
                    <p class="mb-1">Atletas actualizados: <strong><?= (int)$d['atletas_actualizados'] ?></strong></p>
                    <p class="mb-1">Usuarios revisados: <strong><?= (int)$d['usuarios_revisados'] ?></strong></p>
                    <p class="mb-0">Usuarios actualizados: <strong><?= (int)$d['usuarios_actualizados'] ?></strong></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

