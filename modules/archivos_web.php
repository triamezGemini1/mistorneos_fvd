<?php
/**
 * Archivos descargables - CRUD para Admin General
 * Subir, listar, renombrar y eliminar: Documentos oficiales, Logos de clientes, Invitaciones FVD
 * Los archivos se muestran y descargan desde el portal público.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/ImageOptimizer.php';
require_once __DIR__ . '/../lib/InvitacionesFvdWebService.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();

// Umbrales (bytes) para comprimir: por encima de esto se optimiza
const UMBRAL_COMPRIMIR_IMAGEN = 1024 * 1024;   // 1 MB
const UMBRAL_COMPRIMIR_PDF     = 3 * 1024 * 1024; // 3 MB

$base_dir = dirname(__DIR__);
$folders = [
    'documentos' => [
        'path' => 'upload/documentos_oficiales',
        'titulo' => 'Documentos oficiales de dominó',
        'desc' => 'PDF y documentos que se muestran en la sección "Documentos" del portal. Los visitantes pueden ver en línea y descargar.',
        'extensions' => ['pdf', 'doc', 'docx'],
    ],
    'logos_clientes' => [
        'path' => 'upload/logos_clientes',
        'titulo' => 'Logos de clientes',
        'desc' => 'Imágenes para el cintillo de clientes en el landing. Se muestran junto a los logos de clubes.',
        'extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
    ],
    'invitaciones_fvd' => [
        'path' => 'upload/invitaciones_fvd',
        'titulo' => 'Invitaciones FVD',
        'desc' => 'Invitaciones y documentos de la FVD. Se muestran en el portal hasta la fecha del evento; después quedan inactivas automáticamente.',
        'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'],
    ],
];

// Crear carpetas si no existen
foreach ($folders as $key => $cfg) {
    $full = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cfg['path']);
    if (!is_dir($full)) {
        @mkdir($full, 0755, true);
    }
}

$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;

$registrarInvitacionMeta = static function (string $filename, string $seccionPost) use ($pdo): void {
    if ($seccionPost !== 'invitaciones_fvd') {
        return;
    }
    $titulo = trim((string) ($_POST['nombre_guardar'] ?? ''));
    InvitacionesFvdWebService::registrar(
        $pdo,
        $filename,
        $titulo !== '' ? $titulo : null,
        (string) ($_POST['fecha_evento'] ?? '')
    );
};

// POST: subida o eliminación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validate();
    $action = $_POST['action'] ?? '';
    $seccion = $_POST['seccion'] ?? '';

    if (!isset($folders[$seccion])) {
        header('Location: index.php?page=archivos_web&error=' . rawurlencode('Sección no válida'));
        exit;
    }

    $cfg = $folders[$seccion];
    $dir_full = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cfg['path']);
    $dir_full = realpath($dir_full);
    $base_real = realpath($base_dir);
    if ($dir_full === false || $base_real === false || strpos($dir_full, $base_real) !== 0) {
        header('Location: index.php?page=archivos_web&error=' . rawurlencode('Ruta no permitida'));
        exit;
    }

    if ($action === 'delete') {
        $archivo = $_POST['archivo'] ?? '';
        $archivo = basename(str_replace(['../', '..\\'], '', $archivo));
        if ($archivo === '') {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode('Archivo no indicado'));
            exit;
        }
        $full_path = $dir_full . DIRECTORY_SEPARATOR . $archivo;
        $real_full = realpath($full_path);
        $real_dir = realpath($dir_full);
        if ($real_full && $real_dir && is_file($real_full) && (strpos($real_full, $real_dir) === 0 || strpos(str_replace('\\', '/', $real_full), str_replace('\\', '/', $real_dir)) === 0)) {
            if (@unlink($real_full)) {
                if ($seccion === 'invitaciones_fvd') {
                    InvitacionesFvdWebService::eliminarPorArchivo($pdo, $archivo);
                }
                header('Location: index.php?page=archivos_web&success=' . rawurlencode('Archivo eliminado'));
            } else {
                header('Location: index.php?page=archivos_web&error=' . rawurlencode('No se pudo eliminar el archivo'));
            }
        } else {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode('Archivo no encontrado o ruta no permitida'));
        }
        exit;
    }

    if ($action === 'rename') {
        $archivo = $_POST['archivo'] ?? '';
        $nuevo_nombre = $_POST['nuevo_nombre'] ?? '';
        $archivo = basename(str_replace(['../', '..\\'], '', $archivo));
        $nuevo_nombre = basename(preg_replace('/[^a-zA-Z0-9._-]/', '_', $nuevo_nombre));
        $ext_orig = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        $ext_nuevo = strtolower(pathinfo($nuevo_nombre, PATHINFO_EXTENSION));
        if ($archivo === '' || $nuevo_nombre === '') {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode('Nombre no válido'));
            exit;
        }
        if (!in_array($ext_nuevo, $cfg['extensions'], true)) {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode('Extensión no permitida. Use: ' . implode(', ', $cfg['extensions'])));
            exit;
        }
        $path_orig = $dir_full . DIRECTORY_SEPARATOR . $archivo;
        $path_nuevo = $dir_full . DIRECTORY_SEPARATOR . $nuevo_nombre;
        $real_orig = realpath($path_orig);
        $real_dir = realpath($dir_full);
        if ($real_orig && $real_dir && is_file($real_orig) && (strpos($real_orig, $real_dir) === 0 || strpos(str_replace('\\', '/', $real_orig), str_replace('\\', '/', $real_dir)) === 0)) {
            if (@rename($path_orig, $path_nuevo)) {
                if ($seccion === 'invitaciones_fvd') {
                    InvitacionesFvdWebService::renombrarArchivo($pdo, $archivo, $nuevo_nombre);
                }
                header('Location: index.php?page=archivos_web&success=' . rawurlencode('Archivo renombrado correctamente'));
            } else {
                header('Location: index.php?page=archivos_web&error=' . rawurlencode('No se pudo renombrar'));
            }
        } else {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode('Archivo no encontrado'));
        }
        exit;
    }

    if ($action === 'update_vigencia' && $seccion === 'invitaciones_fvd') {
        $archivo = basename(str_replace(['../', '..\\'], '', (string) ($_POST['archivo'] ?? '')));
        $fecha_evento = (string) ($_POST['fecha_evento'] ?? '');
        if ($archivo === '') {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode('Archivo no indicado'));
            exit;
        }
        try {
            InvitacionesFvdWebService::actualizarFechaLimite($pdo, $archivo, $fecha_evento);
            header('Location: index.php?page=archivos_web&success=' . rawurlencode('Vigencia actualizada'));
        } catch (Throwable $e) {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    if ($action === 'upload' && !empty($_FILES['archivo']['name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        if ($seccion === 'invitaciones_fvd') {
            $fecha_evento = trim((string) ($_POST['fecha_evento'] ?? ''));
            if ($fecha_evento === '') {
                header('Location: index.php?page=archivos_web&error=' . rawurlencode('Indique la fecha límite del evento para la invitación'));
                exit;
            }
        }
        $original_name = $_FILES['archivo']['name'];
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $cfg['extensions'], true)) {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode('Tipo de archivo no permitido. Use: ' . implode(', ', $cfg['extensions'])));
            exit;
        }
        $nombre_custom = trim((string)($_POST['nombre_guardar'] ?? ''));
        if ($nombre_custom !== '') {
            $nombre_custom = basename(preg_replace('/[^a-zA-Z0-9._\-]/', '_', $nombre_custom));
            $nombre_custom = pathinfo($nombre_custom, PATHINFO_FILENAME);
            if ($nombre_custom === '') {
                $nombre_custom = pathinfo($original_name, PATHINFO_FILENAME);
            }
            $name = $nombre_custom . '.' . $ext;
        } else {
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);
        }
        $tmp = $_FILES['archivo']['tmp_name'];
        $dest = $dir_full . DIRECTORY_SEPARATOR . $name;
        $size = filesize($tmp);

        $comprimir_imagen = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && $size > UMBRAL_COMPRIMIR_IMAGEN;
        $comprimir_pdf = ($ext === 'pdf' && $size > UMBRAL_COMPRIMIR_PDF);

        if ($comprimir_imagen && class_exists('ImageOptimizer')) {
            if (!move_uploaded_file($tmp, $dest)) {
                header('Location: index.php?page=archivos_web&error=' . rawurlencode('Error al guardar el archivo'));
                exit;
            }
            $res = ImageOptimizer::optimize($dest, $dest, [
                'quality' => 82,
                'max_width' => 1920,
                'max_height' => 1920,
                'create_webp' => false,
            ]);
            $msg = $res['success'] && isset($res['savings_percent']) && $res['savings_percent'] > 0
                ? 'Archivo subido y comprimido correctamente (' . (int)$res['savings_percent'] . '% menos).'
                : 'Archivo subido correctamente.';
            $registrarInvitacionMeta($name, $seccion);
            header('Location: index.php?page=archivos_web&success=' . rawurlencode($msg));
            exit;
        }

        if ($comprimir_pdf) {
            if (!move_uploaded_file($tmp, $dest)) {
                header('Location: index.php?page=archivos_web&error=' . rawurlencode('Error al guardar el archivo'));
                exit;
            }
            $dest_compr = $dir_full . DIRECTORY_SEPARATOR . '.tmp_compress_' . $name;
            $compressed = false;
            if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))), true)) {
                $gs = trim((string)exec('which gs 2>/dev/null') ?: (string)exec('where gs 2>nul'));
                if ($gs === '') {
                    $gs = 'gs';
                }
                $arg_dest = str_replace(['\\', '"'], ['/', '\\"'], $dest);
                $arg_out = str_replace(['\\', '"'], ['/', '\\"'], $dest_compr);
                $cmd = sprintf('%s -sDEVICE=pdfwrite -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH -sOutputFile="%s" "%s" 2>/dev/null', $gs, $arg_out, $arg_dest);
                @exec($cmd);
                if (is_file($dest_compr) && filesize($dest_compr) < $size) {
                    @unlink($dest);
                    @rename($dest_compr, $dest);
                    $compressed = true;
                } else {
                    if (is_file($dest_compr)) {
                        @unlink($dest_compr);
                    }
                }
            }
            $msg = $compressed ? 'Archivo subido y comprimido correctamente.' : 'Archivo subido correctamente (compresión PDF no disponible en este servidor).';
            $registrarInvitacionMeta($name, $seccion);
            header('Location: index.php?page=archivos_web&success=' . rawurlencode($msg));
            exit;
        }

        if (move_uploaded_file($tmp, $dest)) {
            $registrarInvitacionMeta($name, $seccion);
            header('Location: index.php?page=archivos_web&success=' . rawurlencode('Archivo subido correctamente'));
        } else {
            header('Location: index.php?page=archivos_web&error=' . rawurlencode('Error al guardar el archivo'));
        }
        exit;
    }
}

// Listar archivos por carpeta
$listados = [];
foreach ($folders as $key => $cfg) {
    $dir_full = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cfg['path']);
    if ($key === 'invitaciones_fvd') {
        $listados[$key] = InvitacionesFvdWebService::listarAdmin($pdo, $dir_full);
        continue;
    }
    $listados[$key] = [];
    if (is_dir($dir_full)) {
        foreach (new DirectoryIterator($dir_full) as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (in_array($ext, $cfg['extensions'], true)) {
                $listados[$key][] = ['nombre' => $f->getFilename(), 'path' => $cfg['path'] . '/' . $f->getFilename()];
            }
        }
    }
}

$current_user = Auth::user();
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="h2 mb-2 fw-bold"><i class="fas fa-file-download me-2 text-primary"></i>Archivos descargables</h1>
            <p class="text-muted mb-0">CRUD: subir, ver, renombrar y eliminar documentos oficiales, logos de clientes e invitaciones FVD del portal público</p>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php foreach ($folders as $key => $cfg): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-1">
                    <?php if ($key === 'documentos'): ?><i class="fas fa-file-alt me-2 text-primary"></i>
                    <?php elseif ($key === 'logos_clientes'): ?><i class="fas fa-images me-2 text-info"></i>
                    <?php else: ?><i class="fas fa-envelope-open-text me-2 text-success"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($cfg['titulo']) ?>
                </h5>
                <p class="text-muted small mb-0"><?= htmlspecialchars($cfg['desc']) ?></p>
            </div>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="mb-4">
                <?= CSRF::input() ?>
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="seccion" value="<?= htmlspecialchars($key) ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-<?= $key === 'invitaciones_fvd' ? '4' : '5' ?>">
                        <label class="form-label">Subir archivo</label>
                        <input type="file" name="archivo" class="form-control" accept="<?= htmlspecialchars(implode(',', array_map(fn($e) => '.' . $e, $cfg['extensions']))) ?>" required>
                    </div>
                    <div class="col-md-<?= $key === 'invitaciones_fvd' ? '3' : '4' ?>">
                        <label class="form-label">Nombre al guardar <span class="text-muted fw-normal">(opcional)</span></label>
                        <input type="text" name="nombre_guardar" class="form-control" placeholder="Ej: invitacion_abril_2026 (sin extensión)">
                        <small class="text-muted">Si se deja vacío se usa el nombre del archivo.<?= $key !== 'invitaciones_fvd' ? ' Archivos pesados se comprimen automáticamente.' : '' ?></small>
                    </div>
                    <?php if ($key === 'invitaciones_fvd'): ?>
                    <div class="col-md-3">
                        <label class="form-label">Fecha límite (evento) <span class="text-danger">*</span></label>
                        <input type="date" name="fecha_evento" class="form-control" required>
                        <small class="text-muted">Visible en el portal hasta esta fecha (inclusive).</small>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-<?= $key === 'invitaciones_fvd' ? '2' : '3' ?>">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-upload me-2"></i>Subir</button>
                    </div>
                </div>
            </form>

            <h6 class="text-muted mb-2">Archivos actuales (<?= count($listados[$key]) ?>)</h6>
            <?php if (empty($listados[$key])): ?>
            <p class="text-muted small">Aún no hay archivos. Suba el primero arriba.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead><tr>
                        <th>Nombre</th>
                        <?php if ($key === 'invitaciones_fvd'): ?>
                        <th>Fecha límite</th>
                        <th>Estado</th>
                        <?php endif; ?>
                        <th class="text-end">Acciones</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($listados[$key] as $f):
                        $nombreArchivo = (string) ($f['nombre'] ?? '');
                        $pathArchivo = (string) ($f['path'] ?? ($cfg['path'] . '/' . $nombreArchivo));
                    ?>
                    <tr class="<?= ($key === 'invitaciones_fvd' && !($f['activo'] ?? true)) ? 'table-secondary' : '' ?>">
                        <td>
                            <?php if (in_array(strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION)), ['png','jpg','jpeg','gif','webp','svg'], true)): ?>
                            <img src="<?= htmlspecialchars((function_exists('app_base_url') ? rtrim(app_base_url(), '/') : '') . '/public/view_image.php?path=' . rawurlencode($pathArchivo)) ?>" alt="" style="max-height:32px;max-width:80px;object-fit:contain" class="me-2">
                            <?php endif; ?>
                            <span><?= htmlspecialchars($nombreArchivo) ?></span>
                            <?php if ($key === 'invitaciones_fvd' && !empty($f['sin_vigencia'])): ?>
                            <span class="badge bg-warning text-dark ms-1">Sin fecha</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($key === 'invitaciones_fvd'): ?>
                        <td>
                            <form method="post" class="d-flex gap-1 align-items-center flex-wrap">
                                <?= CSRF::input() ?>
                                <input type="hidden" name="action" value="update_vigencia">
                                <input type="hidden" name="seccion" value="invitaciones_fvd">
                                <input type="hidden" name="archivo" value="<?= htmlspecialchars($nombreArchivo) ?>">
                                <input type="date" name="fecha_evento" class="form-control form-control-sm" style="max-width:11rem"
                                       value="<?= !empty($f['fecha_limite']) ? htmlspecialchars(substr((string)$f['fecha_limite'], 0, 10)) : '' ?>" required>
                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Guardar fecha"><i class="fas fa-save"></i></button>
                            </form>
                        </td>
                        <td>
                            <?php if ($f['activo'] ?? false): ?>
                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Activo</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Inactivo</span>
                            <?php endif; ?>
                            <?php if (!empty($f['fecha_limite'])): ?>
                            <div class="small text-muted">hasta <?= htmlspecialchars(date('d/m/Y', strtotime((string)$f['fecha_limite']))) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-end">
                            <?php
                            $url_ver = ($key === 'logos_clientes') ? 'view_image.php?path=' . rawurlencode($pathArchivo) : 'view_documento.php?path=' . rawurlencode($pathArchivo);
                            ?>
                            <a href="<?= htmlspecialchars($url_ver) ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Ver"><i class="fas fa-external-link-alt"></i></a>
                            <?php if ($key !== 'logos_clientes'): ?>
                            <a href="<?= htmlspecialchars($url_ver . (strpos($url_ver, '?') !== false ? '&' : '?') . 'download=1') ?>" class="btn btn-sm btn-outline-secondary me-1" title="Descargar"><i class="fas fa-download"></i></a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-warning me-1" title="Renombrar" data-bs-toggle="modal" data-bs-target="#modalRename" data-archivo="<?= htmlspecialchars($nombreArchivo) ?>" data-seccion="<?= htmlspecialchars($key) ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este archivo?');">
                                <?= CSRF::input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="seccion" value="<?= htmlspecialchars($key) ?>">
                                <input type="hidden" name="archivo" value="<?= htmlspecialchars($nombreArchivo) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

<!-- Modal Renombrar -->
<div class="modal fade" id="modalRename" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= CSRF::input() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="seccion" id="renameSeccion">
                <input type="hidden" name="archivo" id="renameArchivo">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Renombrar archivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Nuevo nombre del archivo</label>
                    <input type="text" name="nuevo_nombre" id="renameNuevoNombre" class="form-control" required placeholder="nombre_archivo.pdf">
                    <p class="text-muted small mt-2 mb-0">Use solo letras, números, guiones y puntos. La extensión debe coincidir con las permitidas.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Renombrar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('modalRename').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    if (btn) {
        document.getElementById('renameSeccion').value = btn.getAttribute('data-seccion') || '';
        document.getElementById('renameArchivo').value = btn.getAttribute('data-archivo') || '';
        document.getElementById('renameNuevoNombre').value = btn.getAttribute('data-archivo') || '';
        document.getElementById('renameNuevoNombre').focus();
    }
});
</script>
</div>
