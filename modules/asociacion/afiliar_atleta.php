<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../lib/FvdAfiliacionAtletaService.php';
require_once __DIR__ . '/../../lib/FvdMovimientoTorneoHelper.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/FvdAdminGate.php';

FvdAdminGate::rejectPageIfDisabled('asociacion/afiliar_atleta');

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

if (!Auth::isOperativoSoloAsociacion() && !Auth::isAdminGeneral()) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-4">Acceso restringido.</div>';
    return;
}

$pdo = DB::pdo();
$user = Auth::user();
$role = (string) ($user['role'] ?? '');
$club = Auth::clubOperativoAsociacion();
$esAdmin = Auth::isAdminGeneral();
if ($club === null && !$esAdmin) {
    echo '<div class="alert alert-warning m-4">No se encontró la asociación asignada.</div>';
    return;
}

$torneoId = (int) ($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
if ($torneoId < 1) {
    $torneoId = (int) (FvdMovimientoTorneoHelper::torneoActivoId($pdo) ?? 0);
}
$modo = trim((string) ($_GET['modo'] ?? 'nuevo'));
$urlPanel = AppHelpers::dashboard('asociacion_panel', array_filter(['torneo_id' => $torneoId ?: null]));
$mensaje = '';
$error = '';
$userEdit = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::validate();
    $uploadDir = dirname(__DIR__, 2) . '/public/upload/afiliaciones';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        $error = 'No se pudo crear la carpeta de subidas.';
    } else {
        $cedulaBase = FvdMovimientoTorneoHelper::normalizarCedula((string) ($_POST['cedula'] ?? ''));
        $rutas = ['foto' => null, 'cedula_img' => null];
        $allowed = ['jpg' => true, 'jpeg' => true, 'png' => true, 'webp' => true];
        foreach (['foto_atleta' => 'foto', 'imagen_cedula' => 'cedula_img'] as $field => $key) {
            if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($_FILES[$field]['error'] ?? 0) !== UPLOAD_ERR_OK) {
                $error = 'Error al subir imagen.';
                break;
            }
            $ext = strtolower(pathinfo((string) $_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (!isset($allowed[$ext])) {
                $error = 'Formato de imagen no permitido (JPG, PNG, WebP).';
                break;
            }
            $safe = preg_replace('/\W/', '_', $cedulaBase) ?: 'ced';
            $name = $safe . '_' . $key . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . '/' . $name)) {
                $rutas[$key === 'foto' ? 'foto' : 'cedula_img'] = 'upload/afiliaciones/' . $name;
            }
        }
        if ($error === '') {
            try {
                $res = FvdAfiliacionAtletaService::guardar($pdo, $_POST, $rutas, $club, $esAdmin);
                $tidOk = (int) ($res['torneo_id'] ?? 0);
                $params = array_filter([
                    'torneo_id' => $tidOk > 0 ? $tidOk : ($torneoId > 0 ? $torneoId : null),
                    'ok' => 1,
                    'cedula' => FvdMovimientoTorneoHelper::normalizarCedula((string) ($_POST['cedula'] ?? '')),
                ]);
                header('Location: ' . AppHelpers::dashboard('asociacion/reportes/afiliaciones', $params));
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$cedulaGet = trim((string) ($_GET['cedula'] ?? ''));
if ($cedulaGet !== '' && $modo !== 'nuevo') {
    $ver = FvdAfiliacionAtletaService::verificarAccesoConsultaCedula($pdo, $cedulaGet, $club, $esAdmin);
    if ($ver['allowed'] && ($ver['user'] ?? null) !== null) {
        $userEdit = $ver['user'];
    }
}

$apiCedula = AppHelpers::url('api/fvd_afiliacion_check_cedula.php');
$csrf = CSRF::token();
$readonlyCedula = $modo === 'editar' && $userEdit !== null;
$fotoPrev = $userEdit ? (string) ($userEdit['urlimgfoto'] ?? $userEdit['photo_path'] ?? '') : '';
$cedPrev = $userEdit ? (string) ($userEdit['urlimgcedula'] ?? $userEdit['foto_cedula'] ?? '') : '';
$cssFvd = AppHelpers::assetVersion('assets/css/fvd-afiliacion-forms.css');
$nomAsociacion = $club !== null ? trim((string) ($club['nombre'] ?? '')) : '';
$swalFlash = null;
if ($error !== '') {
    $swalFlash = ['icon' => 'error', 'title' => 'No se pudo guardar', 'text' => $error];
}
$numfvdVal = (int) ($userEdit['numfvd'] ?? 0);
?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssFvd) ?>">
<div class="fvd-afiliacion-wrap">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb breadcrumb--fvd mb-0">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($urlPanel) ?>">Panel asociación</a></li>
            <li class="breadcrumb-item active">Afiliar atleta</li>
        </ol>
    </nav>

    <div class="afiliacion-card afiliacion-card--form">
        <h2 class="afiliacion-title-center">Afiliación de atleta</h2>
        <?php if ($nomAsociacion !== ''): ?>
            <p class="afiliacion-asoc-center"><?= htmlspecialchars($nomAsociacion) ?></p>
        <?php endif; ?>
        <p class="afiliacion-hint-center">Consulte la cédula al salir del campo. Sin Nº FVD la alta queda pendiente de validación FVD.</p>

        <form method="post" enctype="multipart/form-data" id="formAfiliarAtleta" class="afiliacion-form-layout">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="user_id" id="user_id" value="<?= (int) ($userEdit['id'] ?? $_POST['user_id'] ?? 0) ?>">
            <?php if ($torneoId > 0): ?>
                <input type="hidden" name="torneo_id" value="<?= (int) $torneoId ?>">
            <?php endif; ?>

            <div class="afiliacion-split-6040">
                <div class="afiliacion-fields-pane">
                    <div class="afiliacion-row afiliacion-row--cedula-nombre">
                        <label class="afiliacion-field afiliacion-field--cedula">
                            <span class="form-label">Cédula *</span>
                            <input type="text" class="form-control" name="cedula" id="cedula" required maxlength="20"
                                   value="<?= htmlspecialchars((string) ($userEdit['cedula'] ?? $cedulaGet)) ?>"
                                <?= $readonlyCedula ? 'readonly' : '' ?>>
                        </label>
                        <label class="afiliacion-field afiliacion-field--nombre">
                            <span class="form-label">Nombre completo *</span>
                            <input type="text" class="form-control" name="nombre" id="nombre" required
                                   value="<?= htmlspecialchars((string) ($userEdit['nombre'] ?? '')) ?>">
                        </label>
                    </div>

                    <div class="afiliacion-row afiliacion-row--sexo-fnac">
                        <label class="afiliacion-field afiliacion-field--wide">
                            <span class="form-label">Sexo</span>
                            <select class="form-select" name="sexo" id="sexo">
                                <option value="1" <?= (int) ($userEdit['sexo'] ?? 1) === 1 ? 'selected' : '' ?>>Masculino</option>
                                <option value="2" <?= (int) ($userEdit['sexo'] ?? 0) === 2 ? 'selected' : '' ?>>Femenino</option>
                            </select>
                        </label>
                        <label class="afiliacion-field afiliacion-field--wide">
                            <span class="form-label">Fecha de nacimiento</span>
                            <input type="date" class="form-control" name="fechnac" id="fechnac"
                                   value="<?= htmlspecialchars(substr((string) ($userEdit['fechnac'] ?? ''), 0, 10)) ?>">
                        </label>
                    </div>

                    <div class="afiliacion-row afiliacion-row--email-celular">
                        <label class="afiliacion-field">
                            <span class="form-label">Email *</span>
                            <input type="email" class="form-control" name="email" id="email" required
                                   value="<?= htmlspecialchars((string) ($userEdit['email'] ?? '')) ?>">
                        </label>
                        <label class="afiliacion-field">
                            <span class="form-label">Celular</span>
                            <input type="text" class="form-control" name="celular" id="celular" maxlength="20"
                                   value="<?= htmlspecialchars((string) ($userEdit['celular'] ?? '')) ?>">
                        </label>
                    </div>

                    <div class="afiliacion-row afiliacion-row--numfvd-categ">
                        <label class="afiliacion-field afiliacion-field--numfvd">
                            <span class="form-label">Nº FVD</span>
                            <?php if ($esAdmin): ?>
                                <input type="number" class="form-control" name="numfvd" id="numfvd" min="0"
                                       value="<?= $numfvdVal ?>">
                            <?php else: ?>
                                <input type="text" class="form-control" id="numfvd_display" readonly
                                       value="<?= $numfvdVal > 0 ? (string) $numfvdVal : 'Pendiente asignación' ?>">
                                <input type="hidden" name="numfvd" id="numfvd" value="<?= $numfvdVal ?>">
                            <?php endif; ?>
                        </label>
                        <label class="afiliacion-field afiliacion-field--categ">
                            <span class="form-label">Categoría (edad)</span>
                            <input type="text" class="form-control afiliacion-categoria-input" id="categoria_edad"
                                   readonly tabindex="-1" value="—" aria-live="polite">
                        </label>
                    </div>

                    <?php if ($esAdmin && $club === null): ?>
                    <div class="afiliacion-row">
                        <label class="afiliacion-field afiliacion-field--club-admin">
                            <span class="form-label">Club / asociación (ID)</span>
                            <input type="number" class="form-control" name="club_id" min="1"
                                   value="<?= (int) ($userEdit['club_id'] ?? 0) ?>">
                        </label>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="afiliacion-media-pane">
                    <h3 class="afiliacion-block-title">Foto carnet e imagen de cédula</h3>
                    <div class="afiliacion-media-stack">
                        <div class="afiliacion-media-block">
                            <label class="afiliacion-field" for="foto_atleta"><span>Foto carnet</span></label>
                            <input type="file" name="foto_atleta" id="foto_atleta" accept="image/jpeg,image/png,image/webp">
                            <div class="afiliacion-preview" id="preview_foto">
                                <?php if ($fotoPrev !== ''): ?>
                                    <img src="<?= htmlspecialchars(AppHelpers::url($fotoPrev)) ?>" alt="" class="afiliacion-preview-img" id="img_preview_foto" style="display:block">
                                <?php else: ?>
                                    <span class="afiliacion-preview-ph">Vista previa foto</span>
                                    <img src="" alt="" class="afiliacion-preview-img" id="img_preview_foto">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="afiliacion-media-block">
                            <label class="afiliacion-field" for="imagen_cedula"><span>Imagen cédula</span></label>
                            <input type="file" name="imagen_cedula" id="imagen_cedula" accept="image/jpeg,image/png,image/webp">
                            <div class="afiliacion-preview" id="preview_cedula">
                                <?php if ($cedPrev !== ''): ?>
                                    <img src="<?= htmlspecialchars(AppHelpers::url($cedPrev)) ?>" alt="" class="afiliacion-preview-img" id="img_preview_cedula" style="display:block">
                                <?php else: ?>
                                    <span class="afiliacion-preview-ph">Vista previa cédula</span>
                                    <img src="" alt="" class="afiliacion-preview-img" id="img_preview_cedula">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="afiliacion-actions afiliacion-actions--center">
                <button type="submit" class="btn fvd-btn-primary"><i class="fas fa-save me-1"></i>Guardar afiliación</button>
                <a href="<?= htmlspecialchars($urlPanel) ?>" class="btn fvd-btn-secondary">Panel</a>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var CATEGORIAS_FVD = [
        { min: 0, max: 12, label: 'Infantil' },
        { min: 13, max: 17, label: 'Juvenil' },
        { min: 18, max: 49, label: 'Mayor / Absoluto' },
        { min: 50, max: 59, label: 'Máster A' },
        { min: 60, max: 69, label: 'Máster B' },
        { min: 70, max: 120, label: 'Máster C' }
    ];
    var swalFlash = <?= json_encode($swalFlash, JSON_UNESCAPED_UNICODE) ?>;
    var apiCedula = <?= json_encode($apiCedula, JSON_UNESCAPED_UNICODE) ?>;

    function withSwal(fn) {
        if (typeof Swal !== 'undefined') {
            fn();
            return;
        }
        var n = 0;
        var iv = setInterval(function () {
            if (typeof Swal !== 'undefined' || ++n > 80) {
                clearInterval(iv);
                if (typeof Swal !== 'undefined') {
                    fn();
                } else if (swalFlash) {
                    alert(swalFlash.text || swalFlash.title || '');
                }
            }
        }, 40);
    }

    function swalAlert(opts) {
        withSwal(function () {
            Swal.fire(Object.assign({
                confirmButtonColor: '#2e2e8e',
                cancelButtonColor: '#6c757d'
            }, opts));
        });
    }

    function calcularEdad(fechaIso) {
        if (!fechaIso || !/^\d{4}-\d{2}-\d{2}$/.test(fechaIso)) {
            return null;
        }
        var hoy = new Date();
        var nac = new Date(fechaIso + 'T12:00:00');
        if (isNaN(nac.getTime())) {
            return null;
        }
        var edad = hoy.getFullYear() - nac.getFullYear();
        var m = hoy.getMonth() - nac.getMonth();
        if (m < 0 || (m === 0 && hoy.getDate() < nac.getDate())) {
            edad--;
        }
        return edad;
    }

    function categoriaPorEdad(edad) {
        if (edad === null || edad < 0) {
            return '—';
        }
        for (var i = 0; i < CATEGORIAS_FVD.length; i++) {
            var r = CATEGORIAS_FVD[i];
            if (edad >= r.min && edad <= r.max) {
                return r.label;
            }
        }
        return 'Fuera de tabla';
    }

    function actualizarCategoria() {
        var inp = document.getElementById('fechnac');
        var out = document.getElementById('categoria_edad');
        if (!inp || !out) {
            return;
        }
        var edad = calcularEdad(inp.value);
        out.value = edad === null ? '—' : categoriaPorEdad(edad) + ' (' + edad + ' años)';
    }

    function cargarUsuario(u) {
        document.getElementById('user_id').value = u.id || '';
        ['nombre', 'email', 'celular'].forEach(function (k) {
            var el = document.querySelector('[name="' + k + '"]');
            if (el && u[k]) {
                el.value = u[k];
            }
        });
        var nf = document.querySelector('[name="numfvd"]');
        if (nf && u.numfvd) {
            nf.value = u.numfvd;
        }
        var nfDisp = document.getElementById('numfvd_display');
        if (nfDisp) {
            nfDisp.value = u.numfvd && parseInt(u.numfvd, 10) > 0 ? String(u.numfvd) : 'Pendiente asignación';
        }
        var fn = document.getElementById('fechnac');
        if (fn && u.fechnac) {
            fn.value = String(u.fechnac).substring(0, 10);
        }
        var sx = document.getElementById('sexo');
        if (sx && u.sexo) {
            sx.value = u.sexo;
        }
        actualizarCategoria();
    }

    function bindPreview(inputId, imgId) {
        var input = document.getElementById(inputId);
        var img = document.getElementById(imgId);
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            var box = input.closest('.afiliacion-media-block');
            var ph = box ? box.querySelector('.afiliacion-preview-ph') : null;
            if (!file || !img) {
                return;
            }
            var reader = new FileReader();
            reader.onload = function (e) {
                img.src = e.target.result;
                img.style.display = 'block';
                if (ph) {
                    ph.style.display = 'none';
                }
            };
            reader.readAsDataURL(file);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var fechnac = document.getElementById('fechnac');
        if (fechnac) {
            fechnac.addEventListener('change', actualizarCategoria);
            fechnac.addEventListener('input', actualizarCategoria);
            actualizarCategoria();
        }
        bindPreview('foto_atleta', 'img_preview_foto');
        bindPreview('imagen_cedula', 'img_preview_cedula');

        if (swalFlash) {
            swalAlert({
                icon: swalFlash.icon || 'info',
                title: swalFlash.title || '',
                text: swalFlash.text || ''
            });
        }

        var ced = document.getElementById('cedula');
        if (!ced || !apiCedula) {
            return;
        }
        var timer;
        ced.addEventListener('blur', function () {
            clearTimeout(timer);
            var v = ced.value.trim();
            if (v.length < 4) {
                return;
            }
            timer = setTimeout(function () {
                fetch(apiCedula + '?cedula=' + encodeURIComponent(v), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) {
                            swalAlert({ icon: 'warning', title: 'Consulta', text: d.message || 'Error' });
                            return;
                        }
                        if (!d.exists || !d.user) {
                            return;
                        }
                        withSwal(function () {
                            Swal.fire({
                                icon: 'question',
                                title: 'Cédula registrada',
                                text: 'Ya existe un registro con esta cédula. ¿Cargar datos para actualizar?',
                                showCancelButton: true,
                                confirmButtonText: 'Sí, cargar',
                                cancelButtonText: 'No',
                                confirmButtonColor: '#2e2e8e'
                            }).then(function (res) {
                                if (res.isConfirmed) {
                                    cargarUsuario(d.user);
                                }
                            });
                        });
                    })
                    .catch(function () {});
            }, 200);
        });
    });
})();
</script>
