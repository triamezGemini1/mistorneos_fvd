<?php
$modal_role = $modal_role ?? 'admin_torneo';
$rol_label = $modal_role === 'operador' ? 'Operador' : 'Admin Torneo';
$club_id_val = (int)($club_id ?? 0);
$entidades_options = $entidades_options ?? [];
?>
<form method="POST" action="index.php?page=users" id="form_crear_<?= $modal_role ?>">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="return_to" value="admin_torneo_operadores">
    <input type="hidden" name="return_tab" value="<?= $modal_role === 'operador' ? 'operadores' : $modal_role ?>">
    <input type="hidden" name="return_club_id" value="<?= $club_id_val ?>">
    <?php if (!empty($return_torneo_id)): ?><input type="hidden" name="return_torneo_id" value="<?= (int) $return_torneo_id ?>"><?php endif; ?>
    <input type="hidden" name="role" value="<?= htmlspecialchars($modal_role) ?>">
    <?php if (!$is_admin_club && $club_id_val <= 0 && !empty($clubes_options)): ?>
    <div class="row mb-2">
        <div class="col-md-8">
            <label class="form-label">Asociación *</label>
            <select class="form-select form-select-sm" name="club_id" required>
                <option value="">-- Seleccione asociación --</option>
                <?php foreach ($clubes_options as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php else: ?>
    <input type="hidden" name="club_id" value="<?= $club_id_val ?>">
    <?php endif; ?>

    <div class="row mb-2">
        <div class="col-md-3">
            <label class="form-label">Nacionalidad</label>
            <select class="form-select form-select-sm" id="nacionalidad_crear_<?= $modal_role ?>">
                <option value="V">V</option>
                <option value="E">E</option>
                <option value="J">J</option>
                <option value="P">P</option>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label">Cédula *</label>
            <input type="text" class="form-control form-control-sm" name="cedula" required placeholder="Ej: 12345678" id="cedula_crear_<?= $modal_role ?>">
        </div>
    </div>
    <div class="row mb-2">
        <div class="col-md-8">
            <label class="form-label">Nombre completo *</label>
            <input type="text" class="form-control form-control-sm" name="nombre" required>
        </div>
    </div>
    <div class="row mb-2">
        <div class="col-md-6">
            <label class="form-label">Nombre de usuario *</label>
            <input type="text" class="form-control form-control-sm" name="username" required minlength="3" placeholder="Mín. 3 caracteres">
        </div>
        <div class="col-md-6">
            <label class="form-label">Contraseña *</label>
            <input type="password" class="form-control form-control-sm" name="password" required minlength="6" placeholder="Mín. 6 caracteres">
        </div>
    </div>
    <div class="row mb-2">
        <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" class="form-control form-control-sm" name="email" placeholder="opcional">
        </div>
        <div class="col-md-6">
            <label class="form-label">Celular</label>
            <input type="text" class="form-control form-control-sm" name="celular" placeholder="opcional">
        </div>
    </div>
    <div class="row mb-2">
        <div class="col-md-6">
            <label class="form-label">Entidad (ubicación) *</label>
            <select class="form-select form-select-sm" name="entidad" required>
                <option value="">-- Seleccione --</option>
                <?php foreach ($entidades_options as $ent): ?>
                    <option value="<?= htmlspecialchars($ent['codigo'] ?? '') ?>"><?= htmlspecialchars($ent['nombre'] ?? $ent['codigo'] ?? '') ?></option>
                <?php endforeach; ?>
                <?php if (empty($entidades_options)): ?>
                    <option value="" disabled>No hay entidades configuradas</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Fecha nacimiento</label>
            <input type="text" class="form-control form-control-sm" name="fechnac" placeholder="YYYY-MM-DD (opcional)">
        </div>
    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-user-plus me-1"></i> Registrar y asignar como <?= htmlspecialchars($rol_label) ?></button>
    </div>
</form>
<script>
(function() {
    var modalRole = '<?= $modal_role ?>';
    var form = document.getElementById('form_crear_'+modalRole);
    var cedulaInput = document.getElementById('cedula_crear_'+modalRole);
    var nacSelect = document.getElementById('nacionalidad_crear_'+modalRole);
    if (form && cedulaInput && nacSelect) {
        form.addEventListener('submit', function() {
            var num = (cedulaInput.value || '').trim().replace(/^\s*[VEJP]\s*/i, '');
            var nac = (nacSelect.value || 'V').toUpperCase();
            if (num && !/^[VEJP]\d+/i.test(cedulaInput.value.trim())) {
                cedulaInput.value = nac + num;
            }
        });
    }
})();
</script>
