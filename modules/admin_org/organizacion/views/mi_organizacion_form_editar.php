<?php

/**

 * Vista: Formulario editar organización (admin_club y admin_general)

 */

$canEdit = !empty($can_edit_mi_organizacion);

$urlRetorno = class_exists('AppHelpers')

    ? AppHelpers::dashboard('organizaciones', ['id' => (int) ($organizacion['id'] ?? 0)])

    : 'index.php?page=organizaciones&id=' . (int) ($organizacion['id'] ?? 0);

$logo_url_actual = !empty($organizacion['logo'])

    ? AppHelpers::url('view_image.php', ['path' => $organizacion['logo']])

    : '';

?>

<div class="row mb-4">

    <div class="col-lg-6 mb-3 mb-lg-0">

        <div class="card shadow-sm h-100">

            <div class="card-header">

                <h5 class="mb-0"><i class="fas fa-building me-2"></i>Información de la organización</h5>

            </div>

            <div class="card-body">

                <h4 class="mb-1"><?= htmlspecialchars($organizacion['nombre']) ?></h4>

                <?php if (!empty($organizacion['entidad_nombre'])): ?>

                    <p class="mb-2"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($organizacion['entidad_nombre']) ?></p>

                <?php endif; ?>

                <p class="small mb-0 opacity-75">ID: <?= (int) ($organizacion['id'] ?? 0) ?><?php if (!empty($organizacion['cod_org'])): ?> · Código: <?= (int) $organizacion['cod_org'] ?><?php endif; ?></p>

                <?php if ($is_admin_general && !empty($organizacion['admin_nombre'])): ?>

                    <hr class="border-light opacity-25 my-3">

                    <p class="mb-0 small"><i class="fas fa-user-shield me-1"></i><?= htmlspecialchars($organizacion['admin_nombre']) ?></p>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <div class="col-lg-6">

        <div class="card shadow-sm h-100">

            <div class="card-header">

                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Estadísticas</h5>

            </div>

            <div class="card-body">

                <div class="row g-2">

                    <div class="col-6 col-md-4">

                        <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--blue">

                            <strong class="fvd-org-kpi-num"><?= (int)($stats['clubes'] ?? 0) ?></strong>

                            <span>Asociaciones</span>

                        </div>

                    </div>

                    <div class="col-6 col-md-4">

                        <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--green">

                            <strong class="fvd-org-kpi-num"><?= (int)($stats['torneos'] ?? 0) ?></strong>

                            <span>Torneos</span>

                        </div>

                    </div>

                    <div class="col-6 col-md-4">

                        <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--mint">

                            <strong class="fvd-org-kpi-num"><?= (int)($stats['torneos_activos'] ?? 0) ?></strong>

                            <span>En curso</span>

                        </div>

                    </div>

                    <div class="col-6 col-md-4">

                        <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--yellow">

                            <strong class="fvd-org-kpi-num"><?= (int)($stats['afiliados'] ?? 0) ?></strong>

                            <span>Afiliados</span>

                        </div>

                    </div>

                    <div class="col-6 col-md-4">

                        <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--lavender">

                            <strong class="fvd-org-kpi-num"><?= (int)($stats['usuarios'] ?? 0) ?></strong>

                            <span>Usuarios</span>

                        </div>

                    </div>

                    <div class="col-6 col-md-4">

                        <div class="fvd-org-kpi-pastel fvd-org-kpi-pastel--peach">

                            <strong class="fvd-org-kpi-num"><?= (int)($stats['inscripciones'] ?? 0) ?></strong>

                            <span>Inscripciones</span>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>



<div class="row">

    <div class="col-12">

        <div class="card shadow-sm">

            <div class="card-header">

                <i class="fas fa-edit me-2"></i>Editar información

            </div>

            <div class="card-body">

                <?php if (!$canEdit): ?>

                <div class="alert alert-secondary mb-0">

                    <i class="fas fa-eye me-2"></i>Modo solo consulta. Para cambiar datos de la federación, use un perfil con permisos de administración de organización.

                </div>

                <?php else: ?>

                <form method="POST" enctype="multipart/form-data" id="form-org-edit" class="fvd-org-form-3col">

                    <input type="hidden" name="action" value="actualizar">

                    <input type="hidden" name="organizacion_id" value="<?= (int) $organizacion['id'] ?>">



                    <div class="row g-3 align-items-start">

                        <div class="col-md-4">

                            <div class="fvd-org-col-actions">

                                <button type="button" class="btn btn-light fw-semibold" id="btn-org-editar">

                                    <i class="fas fa-edit me-1"></i>Editar

                                </button>

                            </div>

                            <div class="mb-3">

                                <label class="form-label" for="org-nombre">Nombre de la organización <span class="text-warning">*</span></label>

                                <input type="text" name="nombre" id="org-nombre" class="form-control fvd-org-editable fvd-org-field-readonly" value="<?= htmlspecialchars($organizacion['nombre']) ?>" required readonly>

                            </div>

                            <div class="mb-3">

                                <label class="form-label" for="org-email">Email</label>

                                <input type="email" name="email" id="org-email" class="form-control fvd-org-editable fvd-org-field-readonly" value="<?= htmlspecialchars($organizacion['email'] ?? '') ?>" readonly>

                            </div>

                            <div class="mb-0">

                                <label class="form-label" for="org-direccion">Dirección</label>

                                <textarea name="direccion" id="org-direccion" class="form-control fvd-org-editable fvd-org-field-readonly" rows="3" readonly><?= htmlspecialchars($organizacion['direccion'] ?? '') ?></textarea>

                            </div>

                        </div>



                        <div class="col-md-4">

                            <div class="fvd-org-col-actions">

                                <button type="submit" class="btn btn-warning fw-bold text-dark" id="btn-org-guardar" disabled>

                                    <i class="fas fa-save me-1"></i>Guardar

                                </button>

                            </div>

                            <div class="mb-3">

                                <label class="form-label" for="org-responsable">Responsable / Presidente</label>

                                <input type="text" name="responsable" id="org-responsable" class="form-control fvd-org-editable fvd-org-field-readonly" value="<?= htmlspecialchars($organizacion['responsable'] ?? '') ?>" readonly>

                            </div>

                            <div class="mb-0">

                                <label class="form-label" for="org-telefono">Teléfono</label>

                                <input type="text" name="telefono" id="org-telefono" class="form-control fvd-org-editable fvd-org-field-readonly" value="<?= htmlspecialchars($organizacion['telefono'] ?? '') ?>" readonly>

                            </div>

                        </div>



                        <div class="col-md-4">

                            <div class="fvd-org-col-actions" aria-hidden="true"></div>

                            <div class="fvd-org-logo-panel">

                                <label class="form-label mb-0" for="logo-organizacion">Logo</label>

                                <?php if ($logo_url_actual !== ''): ?>

                                    <a href="<?= htmlspecialchars($logo_url_actual) ?>" class="d-inline-block" id="org-logo-actual-link">

                                        <img src="<?= htmlspecialchars($logo_url_actual) ?>" alt="Logo actual" class="img-thumbnail fvd-org-logo-preview" id="org-logo-actual-img">

                                    </a>

                                <?php else: ?>

                                    <div class="bg-white bg-opacity-75 rounded d-flex align-items-center justify-content-center" style="width:140px;height:140px;" id="org-logo-placeholder">

                                        <i class="fas fa-building fa-3x text-secondary"></i>

                                    </div>

                                <?php endif; ?>

                                <input type="file" name="logo" id="logo-organizacion" class="form-control fvd-org-editable-file" accept="image/*" data-preview-target="organizacion-logo-preview" disabled>

                                <small class="text-muted">JPG, PNG, GIF, WEBP. Máx. 2 MB.</small>

                                <div id="organizacion-logo-preview" class="w-100"></div>

                                <a href="<?= htmlspecialchars($urlRetorno) ?>" class="btn btn-light mt-2">

                                    <i class="fas fa-arrow-left me-1"></i>Volver

                                </a>

                            </div>

                        </div>

                    </div>

                </form>

                <script>

                (function () {

                    var form = document.getElementById('form-org-edit');

                    if (!form) return;

                    var btnEdit = document.getElementById('btn-org-editar');

                    var btnSave = document.getElementById('btn-org-guardar');

                    var fields = form.querySelectorAll('.fvd-org-editable');

                    var fileLogo = form.querySelector('.fvd-org-editable-file');

                    var editing = false;

                    var initial = {};



                    fields.forEach(function (f) {

                        initial[f.name] = f.value;

                    });



                    function setLocked(locked) {

                        fields.forEach(function (f) {

                            if (locked) {

                                f.setAttribute('readonly', 'readonly');

                            } else {

                                f.removeAttribute('readonly');

                            }

                        });

                        if (fileLogo) {

                            fileLogo.disabled = locked;

                        }

                    }



                    function isDirty() {

                        for (var i = 0; i < fields.length; i++) {

                            var f = fields[i];

                            if (f.value !== initial[f.name]) {

                                return true;

                            }

                        }

                        if (fileLogo && fileLogo.files && fileLogo.files.length > 0) {

                            return true;

                        }

                        return false;

                    }



                    function syncSaveButton() {

                        btnSave.disabled = !editing || !isDirty();

                    }



                    btnEdit.addEventListener('click', function () {

                        editing = true;

                        setLocked(false);

                        btnEdit.disabled = true;

                        syncSaveButton();

                    });



                    form.addEventListener('input', syncSaveButton);

                    form.addEventListener('change', syncSaveButton);

                })();

                </script>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>


