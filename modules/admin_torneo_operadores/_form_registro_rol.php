<?php
$modal_role = $modal_role ?? 'admin_torneo';
$rol_label = $modal_role === 'operador' ? 'Operador' : 'Admin Torneo';
$club_id_val = $club_id ?? 0;
$clubes_options = $clubes_options ?? [];
?>
<?php if (!$is_admin_club && (int)$club_id_val <= 0 && !empty($clubes_options)): ?>
<div class="mb-3">
    <label class="form-label">Asociación *</label>
    <select class="form-select form-select-sm" id="club_asignar_<?= $modal_role ?>">
        <option value="">-- Seleccione asociación --</option>
        <?php foreach ($clubes_options as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
<div class="mb-3">
    <label class="form-label">¿Cómo desea agregar?</label>
    <div class="btn-group w-100 mb-3" role="group">
        <input type="radio" class="btn-check" name="opcion_agregar_<?= $modal_role ?>" id="opcion_buscar_<?= $modal_role ?>" value="buscar" checked>
        <label class="btn btn-outline-primary btn-sm" for="opcion_buscar_<?= $modal_role ?>"><i class="fas fa-search me-1"></i> Buscar usuario existente</label>
        <input type="radio" class="btn-check" name="opcion_agregar_<?= $modal_role ?>" id="opcion_registrar_<?= $modal_role ?>" value="registrar">
        <label class="btn btn-outline-primary btn-sm" for="opcion_registrar_<?= $modal_role ?>"><i class="fas fa-user-plus me-1"></i> Registrar nuevo usuario</label>
    </div>
</div>

<div id="bloque_buscar_<?= $modal_role ?>" class="opcion-bloque">
<div class="mb-3">
    <label class="form-label">Buscar por</label>
    <div class="d-flex gap-3 mb-2">
        <label class="form-check"><input type="radio" class="form-check-input" name="buscar_por_<?= $modal_role ?>" value="id_usuario" checked> ID Usuario</label>
        <label class="form-check"><input type="radio" class="form-check-input" name="buscar_por_<?= $modal_role ?>" value="cedula"> Cédula</label>
    </div>
</div>
<div id="busqueda_por_id_usuario_<?= $modal_role ?>" class="row mb-2">
    <div class="col-md-6">
        <label class="form-label">ID de Usuario</label>
        <div class="input-group input-group-sm">
            <input type="number" class="form-control" id="id_usuario_<?= $modal_role ?>" placeholder="Ej: 123 (busca al salir del campo)" min="1" data-app-search-persona="1">
            <button type="button" class="btn btn-primary app-search-submit-hidden" onclick="buscarPersonaRol('<?= $modal_role ?>')" tabindex="-1" aria-hidden="true"><i class="fas fa-search"></i> Buscar</button>
        </div>
    </div>
</div>
<div id="busqueda_por_cedula_<?= $modal_role ?>" class="row mb-2" style="display:none;">
    <div class="col-md-3">
        <label class="form-label">Nacionalidad</label>
        <select class="form-select form-select-sm" id="nacionalidad_<?= $modal_role ?>">
            <option value="V">V</option>
            <option value="E">E</option>
            <option value="J">J</option>
            <option value="P">P</option>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Cédula</label>
        <div class="input-group input-group-sm">
            <input type="text" class="form-control" id="cedula_<?= $modal_role ?>" placeholder="Cédula (mín. 3; busca al salir del campo)" data-app-search-persona="1">
            <button type="button" class="btn btn-primary app-search-submit-hidden" onclick="buscarPersonaRol('<?= $modal_role ?>')" tabindex="-1" aria-hidden="true"><i class="fas fa-search"></i> Buscar</button>
        </div>
    </div>
</div>
<div id="busqueda_resultado_<?= $modal_role ?>" class="mt-2"></div>
</div>

<div id="bloque_registrar_<?= $modal_role ?>" class="opcion-bloque" style="display:none;">
<?php include __DIR__ . '/_form_crear_usuario_rol.php'; ?>
</div>

<script>
(function() {
    var base = '<?= htmlspecialchars($api_base ?? '') ?>';
    var formAction = '<?= htmlspecialchars($form_action_users ?? 'index.php?page=users') ?>';
    var clubId = '<?= (int)$club_id_val ?>';
    var returnTorneoId = '<?= (int)($return_torneo_id ?? 0) ?>';
    var modalRole = '<?= $modal_role ?>';
    var rolLabel = '<?= htmlspecialchars($rol_label) ?>';

    window.buscarPersonaRol = function(role) {
        var clubSel = document.getElementById('club_asignar_' + role);
        var clubIdEffective = clubId;
        if (clubSel && clubSel.value) {
            clubIdEffective = clubSel.value;
        }
        if (!clubIdEffective) {
            document.getElementById('busqueda_resultado_'+role).innerHTML = '<div class="alert alert-warning py-2">Seleccione la asociación a la que pertenecerá el operador.</div>';
            return;
        }
        var buscarPorRadio = document.querySelector('input[name="buscar_por_'+role+'"]:checked');
        var buscarPor = buscarPorRadio ? buscarPorRadio.value : 'id_usuario';
        var resultado = document.getElementById('busqueda_resultado_'+role);
        var apiUrl;
        
        if (buscarPor === 'id_usuario') {
            var idUsuario = document.getElementById('id_usuario_'+role).value.trim();
            if (!idUsuario) { resultado.innerHTML = '<div class="alert alert-warning py-2">Ingrese el ID de usuario</div>'; return; }
            apiUrl = base + '/api/search_user_persona.php?buscar_por=id&user_id=' + encodeURIComponent(idUsuario);
            if (clubIdEffective) apiUrl += '&club_id=' + clubIdEffective;
        } else if (buscarPor === 'cedula') {
            var cedula = document.getElementById('cedula_'+role).value.trim();
            var nac = document.getElementById('nacionalidad_'+role).value;
            if (!cedula) { resultado.innerHTML = '<div class="alert alert-warning py-2">Ingrese cédula</div>'; return; }
            apiUrl = base + '/api/search_user_persona.php?cedula=' + encodeURIComponent(cedula) + '&nacionalidad=' + encodeURIComponent(nac);
            if (clubIdEffective) apiUrl += '&club_id=' + clubIdEffective;
        }
        
        resultado.innerHTML = '<div class="text-center py-2"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
        fetch(apiUrl, { credentials: 'same-origin', cache: 'no-store' }).then(function(r) { return r.json(); }).then(function(data) {
            if (!data.success) { resultado.innerHTML = '<div class="alert alert-danger py-2">' + (data.error || 'Error') + '</div>'; return; }
            var d = data.data;
            if (d.encontrado && d.existe_usuario && d.usuario_existente) {
                var u = d.usuario_existente;
                var action = role === 'admin_torneo' ? 'assign_admin_torneo' : 'assign_operador';
                var formHtml = '<form method="POST" action="' + formAction + '" class="mt-2">' +
                    '<input type="hidden" name="action" value="' + action + '">' +
                    '<input type="hidden" name="return_to" value="admin_torneo_operadores">' +
                    '<input type="hidden" name="return_tab" value="' + (role === 'operador' ? 'operadores' : role) + '">' +
                    '<input type="hidden" name="return_club_id" value="' + (clubIdEffective || '') + '">' +
                    (returnTorneoId ? '<input type="hidden" name="return_torneo_id" value="' + returnTorneoId + '">' : '') +
                    '<input type="hidden" name="user_id" value="' + u.id + '">' +
                    '<input type="hidden" name="club_id" value="' + (clubIdEffective || u.club_id || '') + '">';
                formHtml += '<button type="submit" class="btn btn-success btn-sm"><i class="fas fa-user-tag me-1"></i> Asignar como ' + rolLabel + '</button></form>';
                resultado.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle"></i> Usuario encontrado. Puede asignarlo como ' + rolLabel + '.<br><strong>ID: ' + u.id + '</strong> - ' + (u.nombre||'') + ' (' + (u.username||'') + ')<br>Cédula: ' + (u.cedula||'N/A') + formHtml + '</div>';
                return;
            }
            if (d.encontrado && d.en_solicitudes) {
                resultado.innerHTML = '<div class="alert alert-info py-2"><i class="fas fa-info-circle"></i> ' + d.mensaje + '<br>Use el menú <strong>Usuarios</strong> para registrar a la persona y luego asígnela aquí.</div>';
                return;
            }
            if (d.encontrado && d.persona) {
                resultado.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle"></i> Persona encontrada: <strong>' + (d.persona.nombre||'') + '</strong>. Use el menú Usuarios para registrarla primero.</div>';
                return;
            }
            resultado.innerHTML = '<div class="alert alert-info py-2"><i class="fas fa-info-circle"></i> ' + (d.mensaje || 'No encontrado. Debe registrarse primero en la plataforma.') + '</div>';
        }).catch(function() {
            resultado.innerHTML = '<div class="alert alert-danger py-2">Error al buscar.</div>';
        });
    };

    document.querySelectorAll('input[name="buscar_por_'+modalRole+'"]').forEach(function(r) {
        r.addEventListener('change', function() {
            document.getElementById('busqueda_por_id_usuario_'+modalRole).style.display = this.value === 'id_usuario' ? '' : 'none';
            document.getElementById('busqueda_por_cedula_'+modalRole).style.display = this.value === 'cedula' ? '' : 'none';
            document.getElementById('busqueda_resultado_'+modalRole).innerHTML = '';
        });
    });
    document.querySelectorAll('input[name="opcion_agregar_'+modalRole+'"]').forEach(function(r) {
        r.addEventListener('change', function() {
            var buscar = document.getElementById('bloque_buscar_'+modalRole);
            var registrar = document.getElementById('bloque_registrar_'+modalRole);
            if (this.value === 'buscar') {
                if (buscar) buscar.style.display = '';
                if (registrar) registrar.style.display = 'none';
            } else {
                if (buscar) buscar.style.display = 'none';
                if (registrar) registrar.style.display = '';
            }
        });
    });
})();
</script>
