<?php
/**
 * Vista: Activar organización inactiva — buscar responsable por nacionalidad + cédula,
 * asignar usuario existente o registrar nuevo (desde BD externa / solicitudes / manual).
 * Cuando from=reactivar: solo reactivar (sin búsqueda); los datos ya se validaron en la solicitud de afiliación.
 */
$org_id = (int)($organizacion['id'] ?? 0);
$return_extra = '';
$entidad_id = (int)($_GET['entidad_id'] ?? 0);
if (($_GET['return_to'] ?? '') === 'organizaciones' && $entidad_id > 0) {
    $return_extra = '&return_to=organizaciones&entidad_id=' . $entidad_id;
}
$url_volver = $return_extra !== '' ? 'index.php?page=organizaciones&entidad_id=' . $entidad_id : 'index.php?page=mi_organizacion&id=' . $org_id;
$form_action = 'index.php?page=mi_organizacion&id=' . $org_id . $return_extra;
$es_reactivacion = (($_GET['from'] ?? '') === 'reactivar');
?>
<div class="card shadow-sm">
    <div class="card-header <?= $es_reactivacion ? 'bg-success text-white' : 'bg-warning text-dark' ?>">
        <i class="fas fa-unlock-alt me-2"></i><?= $es_reactivacion ? 'Reactivar organización' : 'Activar organización' ?>
    </div>
    <div class="card-body">
        <?php if ($es_reactivacion): ?>
        <p class="text-muted">La organización se desactivó anteriormente. Al reactivar no es necesario buscar usuario: los datos del responsable ya fueron validados en la solicitud de afiliación. Solo confirme la reactivación.</p>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="<?= htmlspecialchars($form_action) ?>">
            <input type="hidden" name="action" value="activar_reactivar">
            <input type="hidden" name="organizacion_id" value="<?= $org_id ?>">
            <div class="mb-3">
                <label class="form-label">Organización</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($organizacion['nombre'] ?? '') ?>" readonly disabled>
            </div>
            <div class="d-flex justify-content-between mt-3">
                <a href="<?= htmlspecialchars($url_volver) ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Volver</a>
                <button type="submit" class="btn btn-success"><i class="fas fa-check-circle me-1"></i>Reactivar organización</button>
            </div>
        </form>
        <?php else: ?>
        <p class="text-muted">Busque al responsable por <strong>nacionalidad y cédula</strong>. Si está en la plataforma podrá asignarlo; si no, podrá registrarlo desde la base externa o ingresar los datos manualmente.</p>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Búsqueda por nacionalidad + cédula (homologada con el resto de la app) -->
        <div class="card mb-4 border-primary">
            <div class="card-header bg-light">
                <i class="fas fa-search me-1"></i>Buscar responsable
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">Nacionalidad</label>
                        <select class="form-select" id="nacionalidad_busqueda">
                            <option value="V" selected>V</option>
                            <option value="E">E</option>
                            <option value="J">J</option>
                            <option value="P">P</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Cédula</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="cedula_busqueda" placeholder="Número de cédula (mín. 3; busca al salir del campo)" autocomplete="off" data-app-search-persona="1">
                            <button type="button" class="btn btn-primary app-search-submit-hidden" id="btn_buscar_responsable" title="Buscar en usuarios, base externa o solicitudes" tabindex="-1" aria-hidden="true">
                                <i class="fas fa-search"></i> Buscar
                            </button>
                        </div>
                    </div>
                </div>
                <div id="busqueda_resultado_responsable" class="mt-2"></div>
            </div>
        </div>

        <form method="POST" action="<?= htmlspecialchars($form_action) ?>" id="form_activar_org">
            <input type="hidden" name="action" value="activar_guardar">
            <input type="hidden" name="organizacion_id" value="<?= $org_id ?>">
            <input type="hidden" name="admin_user_id" id="admin_user_id" value="">
            <input type="hidden" name="crear_responsable" id="crear_responsable" value="0">

            <div class="mb-3">
                <label class="form-label">Organización</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($organizacion['nombre'] ?? '') ?>" readonly disabled>
            </div>

            <!-- Bloque: usuario existente seleccionado (solo contraseña) -->
            <div id="bloque_usuario_existente" class="mb-3" style="display:none;">
                <div class="alert alert-success py-2">
                    <i class="fas fa-user-check me-1"></i>
                    <strong id="nombre_usuario_existente"></strong>
                    <span id="username_usuario_existente" class="text-muted"></span>
                </div>
                <p class="text-muted small">Asigne una contraseña para este responsable y pulse Activar organización.</p>
            </div>

            <!-- Bloque: datos del nuevo responsable (búsqueda en externa/solicitudes o manual) -->
            <div id="bloque_datos_nuevo" style="display:none;">
                <div class="alert alert-info py-2 mb-2">
                    <i class="fas fa-user-plus me-1"></i>Se creará un nuevo usuario y se asignará como responsable de la organización.
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nombre completo <span class="text-danger">*</span></label>
                        <input type="text" name="nombre_responsable" id="nombre_responsable" class="form-control" maxlength="200">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Nacionalidad</label>
                        <select name="nacionalidad_responsable" id="nacionalidad_responsable" class="form-select">
                            <option value="V" selected>V</option>
                            <option value="E">E</option>
                            <option value="J">J</option>
                            <option value="P">P</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Cédula <span class="text-danger">*</span></label>
                        <input type="text" name="cedula_responsable" id="cedula_responsable" class="form-control" maxlength="20">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email_responsable" id="email_responsable" class="form-control">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Celular</label>
                        <input type="text" name="celular_responsable" id="celular_responsable" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nombre de usuario <span class="text-danger">*</span></label>
                    <input type="text" name="username_responsable" id="username_responsable" class="form-control" required maxlength="100">
                </div>
            </div>

            <!-- Contraseña (siempre visible cuando hay usuario seleccionado o datos nuevos) -->
            <div id="bloque_password" class="mb-3" style="display:none;">
                <label class="form-label">Contraseña <span class="text-danger">*</span></label>
                <input type="password" name="password" id="password_activar" class="form-control" minlength="6" placeholder="Mínimo 6 caracteres">
            </div>
            <div id="bloque_password_confirm" class="mb-3" style="display:none;">
                <label class="form-label">Confirmar contraseña <span class="text-danger">*</span></label>
                <input type="password" name="password_confirm" id="password_confirm_activar" class="form-control" minlength="6" placeholder="Repetir contraseña">
            </div>

            <div class="d-flex justify-content-between mt-3">
                <a href="<?= htmlspecialchars($url_volver) ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Volver</a>
                <button type="submit" class="btn btn-success" id="btn_submit_activar" disabled>
                    <i class="fas fa-check-circle me-1"></i>Activar organización
                </button>
            </div>
        </form>

        <!-- Opción alternativa: listado de admin_club sin organización -->
        <?php if (!empty($admin_sin_organizacion)): ?>
        <hr class="my-3">
        <p class="text-muted small mb-2">O seleccione un usuario admin ya existente sin organización asignada:</p>
        <form method="POST" action="<?= htmlspecialchars($form_action) ?>" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="action" value="activar_guardar">
            <input type="hidden" name="organizacion_id" value="<?= $org_id ?>">
            <select name="admin_user_id" class="form-select form-select-sm" style="max-width:280px;">
                <option value="">-- Seleccionar --</option>
                <?php foreach ($admin_sin_organizacion as $adm): ?>
                    <option value="<?= (int)$adm['id'] ?>"><?= htmlspecialchars($adm['nombre']) ?> (<?= htmlspecialchars($adm['username']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="password" name="password" class="form-control form-control-sm" placeholder="Contraseña" minlength="6" style="max-width:140px;">
            <input type="password" name="password_confirm" class="form-control form-control-sm" placeholder="Confirmar" minlength="6" style="max-width:140px;">
            <button type="submit" class="btn btn-outline-success btn-sm"><i class="fas fa-check me-1"></i>Asignar y activar</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!$es_reactivacion): ?>
<script>
(function() {
    // Misma página (index.php) con page=api_search_user_persona → misma sesión, sin "sesión expirada"
    function getApiSearchQuery(cedula, nacionalidad) {
        return '?page=api_search_user_persona&cedula=' + encodeURIComponent(cedula) + '&nacionalidad=' + encodeURIComponent(nacionalidad);
    }

    var btnBuscar = document.getElementById('btn_buscar_responsable');
    var resultadoDiv = document.getElementById('busqueda_resultado_responsable');
    var bloqueExistente = document.getElementById('bloque_usuario_existente');
    var bloqueNuevo = document.getElementById('bloque_datos_nuevo');
    var bloquePassword = document.getElementById('bloque_password');
    var bloquePasswordConfirm = document.getElementById('bloque_password_confirm');
    var adminUserId = document.getElementById('admin_user_id');
    var crearResponsable = document.getElementById('crear_responsable');
    var btnSubmit = document.getElementById('btn_submit_activar');
    var form = document.getElementById('form_activar_org');

    function ocultarBloques() {
        bloqueExistente.style.display = 'none';
        bloqueNuevo.style.display = 'none';
        bloquePassword.style.display = 'none';
        bloquePasswordConfirm.style.display = 'none';
        adminUserId.value = '';
        crearResponsable.value = '0';
        btnSubmit.disabled = true;
        if (form) {
            form.querySelectorAll('#nombre_responsable, #cedula_responsable, #username_responsable').forEach(function(el) { if (el) el.removeAttribute('required'); });
        }
    }

    function mostrarUsuarioExistente(nombre, username) {
        ocultarBloques();
        document.getElementById('nombre_usuario_existente').textContent = nombre || '';
        document.getElementById('username_usuario_existente').textContent = username ? ' (' + username + ')' : '';
        bloqueExistente.style.display = 'block';
        bloquePassword.style.display = 'block';
        bloquePasswordConfirm.style.display = 'block';
        document.getElementById('password_activar').setAttribute('required', 'required');
        document.getElementById('password_confirm_activar').setAttribute('required', 'required');
        btnSubmit.disabled = false;
    }

    function mostrarDatosNuevo(persona) {
        ocultarBloques();
        persona = persona || {};
        document.getElementById('nombre_responsable').value = persona.nombre || '';
        document.getElementById('cedula_responsable').value = persona.cedula || '';
        document.getElementById('nacionalidad_responsable').value = (persona.nacionalidad || 'V').toUpperCase().replace(/[^VEJP]/, 'V');
        document.getElementById('email_responsable').value = persona.email || '';
        document.getElementById('celular_responsable').value = persona.celular || '';
        var un = (persona.username || '').trim();
        if (!un && (persona.nombre || '').trim()) {
            var parts = (persona.nombre || '').toLowerCase().split(/\s+/).filter(Boolean);
            if (parts.length >= 2) un = parts[0] + '.' + parts[parts.length - 1];
        }
        document.getElementById('username_responsable').value = un;
        bloqueNuevo.style.display = 'block';
        bloquePassword.style.display = 'block';
        bloquePasswordConfirm.style.display = 'block';
        crearResponsable.value = '1';
        document.getElementById('nombre_responsable').setAttribute('required', 'required');
        document.getElementById('cedula_responsable').setAttribute('required', 'required');
        document.getElementById('username_responsable').setAttribute('required', 'required');
        document.getElementById('password_activar').setAttribute('required', 'required');
        document.getElementById('password_confirm_activar').setAttribute('required', 'required');
        btnSubmit.disabled = false;
    }

    function mensajeNoEncontrado() {
        ocultarBloques();
        mostrarDatosNuevo({});
        resultadoDiv.innerHTML = '<div class="alert alert-info py-2"><i class="fas fa-info-circle me-1"></i>No encontrado en usuarios ni en base externa. Ingrese los datos manualmente abajo y pulse Activar organización.</div>';
    }

    function ejecutarBusqueda() {
        var nacionalidad = (document.getElementById('nacionalidad_busqueda').value || 'V').trim();
        var cedula = (document.getElementById('cedula_busqueda').value || '').trim().replace(/\s/g, '');
        if (!cedula) {
            resultadoDiv.innerHTML = '<div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle me-1"></i>Ingrese la cédula para buscar.</div>';
            return;
        }
        resultadoDiv.innerHTML = '<div class="text-center py-2"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
        var url = getApiSearchQuery(cedula, nacionalidad);
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                var ct = (r.headers.get('Content-Type') || '').toLowerCase();
                if (ct.indexOf('application/json') === -1) throw new Error('NO_JSON');
                return r.json();
            })
            .then(function(data) {
                if (!data.success) {
                    resultadoDiv.innerHTML = '<div class="alert alert-danger py-2">' + (data.error || 'Error al buscar') + '</div>';
                    return;
                }
                var d = data.data;
                if (d.encontrado && d.existe_usuario && d.usuario_existente) {
                    var u = d.usuario_existente;
                    resultadoDiv.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle me-1"></i>Usuario encontrado en la plataforma. Puede asignarlo como responsable.</div>';
                    adminUserId.value = u.id;
                    mostrarUsuarioExistente(u.nombre, u.username);
                    return;
                }
                if (d.encontrado && (d.en_solicitudes && d.solicitud)) {
                    var s = d.solicitud;
                    resultadoDiv.innerHTML = '<div class="alert alert-info py-2"><i class="fas fa-info-circle me-1"></i>Encontrado en solicitudes de afiliación. Complete los datos y registre como responsable.</div>';
                    mostrarDatosNuevo({
                        nombre: s.nombre,
                        cedula: s.cedula,
                        email: s.email,
                        celular: s.celular,
                        username: s.username
                    });
                    return;
                }
                if (d.encontrado && d.persona) {
                    resultadoDiv.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle me-1"></i>Encontrado en base de datos externa. Revise los datos y pulse Activar organización.</div>';
                    mostrarDatosNuevo(d.persona);
                    return;
                }
                mensajeNoEncontrado();
            })
            .catch(function(err) {
                console.error(err);
                var msg = 'Error de conexión. Intente de nuevo.';
                if (err.message === 'NO_JSON') msg = 'El servidor no devolvió una respuesta válida. Compruebe su conexión e intente de nuevo.';
                else if (err.message && err.message.indexOf('HTTP') === 0) msg = 'Error del servidor (' + err.message + ').';
                resultadoDiv.innerHTML = '<div class="alert alert-danger py-2"><i class="fas fa-times-circle me-1"></i>' + msg + '</div>';
            });
    }

    if (btnBuscar) {
        btnBuscar.addEventListener('click', ejecutarBusqueda);
    }
    var cedulaInput = document.getElementById('cedula_busqueda');
    if (cedulaInput) {
        cedulaInput.addEventListener('blur', function() {
            var cedula = (this.value || '').trim().replace(/\s/g, '');
            if (cedula.length >= 6) ejecutarBusqueda();
        });
        cedulaInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); ejecutarBusqueda(); }
        });
    }
})();
</script>
<?php endif; ?>
