<?php
/**
 * Panel de Control Especial - Solo Admin General
 * Incluye: Exportaciones avanzadas, Credenciales por lote, Numeración
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

// Solo admin_general puede acceder
Auth::requireRole(['admin_general']);

// Obtener datos para filtros
$action = $_GET['action'] ?? 'main';
$success_message = $_GET['success'] ?? null;
$error_message = $_GET['error'] ?? null;

// Obtener lista de torneos
$tournaments_list = [];
try {
    $stmt = DB::pdo()->query("
        SELECT id, nombre, fechator,
               CASE WHEN fechator < CURDATE() THEN 1 ELSE 0 END as pasado
        FROM tournaments 
        ORDER BY fechator DESC
    ");
    $tournaments_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error al cargar torneos: " . $e->getMessage();
}

// Obtener lista de clubes
$clubs_list = [];
try {
    $stmt = DB::pdo()->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
    $clubs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error al cargar clubes: " . $e->getMessage();
}

// Si se solicita vista previa de credenciales
$preview_registrants = [];
if ($action === 'preview_credentials') {
    $club_id = $_GET['club_id'] ?? null;
    $torneo_id = $_GET['torneo_id'] ?? null;
    
    if ($club_id && $torneo_id) {
        try {
            $stmt = DB::pdo()->prepare("
                SELECT r.*, c.nombre as club_nombre, c.logo as club_logo,
                       t.nombre as torneo_nombre, t.fechator
                FROM inscripciones r
                LEFT JOIN clubes c ON r.club_id = c.id
                LEFT JOIN tournaments t ON r.torneo_id = t.id
                WHERE r.club_id = ? AND r.torneo_id = ?
                ORDER BY r.identificador ASC, r.nombre ASC
            ");
            $stmt->execute([$club_id, $torneo_id]);
            $preview_registrants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error_message = "Error al cargar vista previa: " . $e->getMessage();
        }
    }
}
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2><i class="fas fa-tools me-2 text-primary"></i>Panel de Control Especial</h2>
                <span class="badge bg-danger fs-6">
                    <i class="fas fa-shield-alt me-1"></i>Solo Admin General
                </span>
            </div>
            <p class="text-muted">Herramientas avanzadas para gestión de inscritos, exportaciones y credenciales</p>
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
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($action === 'main'): ?>
        <!-- Panel Principal -->
        <div class="row g-4">
            <!-- Sección: Exportaciones Avanzadas -->
            <div class="col-lg-4">
                <div class="card h-100 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-file-excel me-2"></i>Exportaciones Avanzadas
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Exportar datos en formatos especializados para an�lisis y reportes</p>
                        
                        <form id="formExportacion">
                            <div class="mb-3">
                                <label class="form-label">Torneo (Opcional)</label>
                                <select class="form-select" name="torneo_id" id="export_torneo">
                                    <option value="">Todos los torneos</option>
                                    <?php foreach ($tournaments_list as $t): ?>
                                        <option value="<?= $t['id'] ?>">
                                            <?= htmlspecialchars($t['nombre']) ?>
                                            <?= $t['pasado'] ? ' (Pasado)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Clubes (Opcional)</label>
                                <select class="form-select" name="club_ids[]" id="export_clubs" multiple size="4">
                                    <?php foreach ($clubs_list as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Mant�n Ctrl para seleccionar múltiples</small>
                            </div>
                        </form>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-success" onclick="exportarInscritosCompleto()">
                                <i class="fas fa-table me-2"></i>Inscritos Formato Completo
                            </button>
                            <button class="btn btn-primary" onclick="exportarJugadoresFormato()">
                                <i class="fas fa-file-export me-2"></i>Jugadores (Formato Espec�fico)
                            </button>
                            <button class="btn btn-warning" onclick="exportarClubes()">
                                <i class="fas fa-building me-2"></i>Listado de Clubes (ID, NOMBRE)
                            </button>
                        </div>
                        <small class="text-muted mt-2 d-block">
                            <strong>Formato Espec�fico:</strong> id_club, id_torneo, indicador=1, cedula, nombre, identificador, sexo, telefono, categ
                        </small>
                    </div>
                </div>
            </div>

            <!-- Sección: Credenciales Masivas -->
            <div class="col-lg-4">
                <div class="card h-100 border-info">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-id-card me-2"></i>Credenciales por Lote
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Generar y previsualizar credenciales de jugadores por club</p>
                        
                        <form id="formCredenciales">
                            <div class="mb-3">
                                <label class="form-label">Torneo *</label>
                                <select class="form-select" name="torneo_id" id="cred_torneo" required>
                                    <option value="">Seleccione torneo</option>
                                    <?php foreach ($tournaments_list as $t): ?>
                                        <option value="<?= $t['id'] ?>">
                                            <?= htmlspecialchars($t['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Club *</label>
                                <select class="form-select" name="club_id" id="cred_club" required>
                                    <option value="">Seleccione club</option>
                                    <?php foreach ($clubs_list as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary" onclick="previsualizarCredenciales()">
                                <i class="fas fa-eye me-2"></i>Vista Previa de Credenciales
                            </button>
                            <button class="btn btn-info" onclick="generarCredencialesZip()">
                                <i class="fas fa-download me-2"></i>Descargar ZIP de Credenciales
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sección: Numeración -->
            <div class="col-lg-4">
                <div class="card h-100 border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-sort-numeric-down me-2"></i>Numeración de Inscritos
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Asignar números identificadores a los inscritos del torneo</p>
                        
                        <form id="formNumeracion">
                            <div class="mb-3">
                                <label class="form-label">Torneo *</label>
                                <select class="form-select" name="torneo_id" id="num_torneo" required>
                                    <option value="">Seleccione torneo</option>
                                    <?php foreach ($tournaments_list as $t): ?>
                                        <option value="<?= $t['id'] ?>">
                                            <?= htmlspecialchars($t['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Clubes (Opcional)</label>
                                <select class="form-select" name="club_ids[]" id="num_clubs" multiple size="3">
                                    <?php foreach ($clubs_list as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Dejar vacío para numerar todos</small>
                            </div>
                        </form>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary" onclick="numerarConsecutivo()">
                                <i class="fas fa-list-ol me-2"></i>Numerar Consecutivo (1, 2, 3...)
                            </button>
                            <button class="btn btn-secondary" onclick="numerarPorClub()">
                                <i class="fas fa-users me-2"></i>Numerar por Club
                            </button>
                        </div>
                        
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong><i class="fas fa-info-circle me-2"></i>Información de Numeración:</strong>
                            <hr class="my-2">
                            <small>
                                <strong>?? Consecutivo:</strong> Numeración global (1, 2, 3...) para todo el torneo. 
                                Los clubs normales se numeran primero, el club responsable al final.<br><br>
                                <strong>?? Por Club:</strong> Cada club tendrá su propia numeración independiente (1, 2, 3...). 
                                Los jugadores se ordenan alfabéticamente dentro de cada club.<br><br>
                                <strong>?? IMPORTANTE:</strong> Estas acciones actualizan la columna IDENTIFICADOR directamente en la base de datos.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    <?php elseif ($action === 'preview_credentials'): ?>
        <!-- Vista Previa de Credenciales -->
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-eye me-2"></i>Vista Previa de Credenciales
                </h5>
                <a href="index.php?page=control_admin" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Volver
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($preview_registrants)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No se encontraron inscritos para este club y torneo.
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-4">
                        <strong>Total de credenciales:</strong> <?= count($preview_registrants) ?>
                        <br>
                        <strong>Club:</strong> <?= htmlspecialchars($preview_registrants[0]['club_nombre'] ?? '') ?>
                        <br>
                        <strong>Torneo:</strong> <?= htmlspecialchars($preview_registrants[0]['torneo_nombre'] ?? '') ?>
                    </div>
                    
                    <!-- Previsualización en grid -->
                    <div class="row g-3">
                        <?php foreach ($preview_inscripciones AS $player): ?>
                            <div class="col-md-4">
                                <div class="card shadow-sm border">
                                    <div class="card-body p-2">
                                        <!-- Mini versión de la credencial -->
                                        <div class="credential-mini" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; border-radius: 8px; font-size: 0.75rem;">
                                            <div class="text-center mb-2">
                                                <strong style="font-size: 0.9rem;"><?= htmlspecialchars($player['club_nombre']) ?></strong>
                                            </div>
                                            <div style="background: white; color: #333; padding: 10px; border-radius: 5px;">
                                                <div><strong>ID:</strong> <?= $player['identificador'] ?></div>
                                                <div><strong>Nombre:</strong> <?= htmlspecialchars($player['nombre']) ?></div>
                                                <div><strong>Cédula:</strong> <?= htmlspecialchars($player['cedula']) ?></div>
                                                <div><strong>Categoría:</strong> 
                                                    <?php
                                                    $cat_names = [1 => 'JUNIOR', 2 => 'LIBRE', 3 => 'MASTER'];
                                                    echo $cat_names[$player['categ']] ?? '';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-center mt-2">
                                            <a href="../modules/registrants/generate_credential.php?action=single&id=<?= $player['id'] ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-4 text-center">
                        <a href="../modules/registrants/generate_credential.php?action=bulk&tournament_id=<?= $preview_registrants[0]['torneo_id'] ?? '' ?>&club_id=<?= $_GET['club_id'] ?? '' ?>" 
                           class="btn btn-success btn-lg">
                            <i class="fas fa-download me-2"></i>Descargar Todas en ZIP
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Función para exportar inscritos formato completo
function exportarInscritosCompleto() {
    const form = document.getElementById('formExportacion');
    const formData = new FormData(form);
    
    const params = new URLSearchParams();
    params.append('torneo_id', formData.get('torneo_id') || '');
    
    const clubs = formData.getAll('club_ids[]');
    clubs.forEach(club => params.append('club_ids[]', club));
    
    window.location.href = '../modules/registrants/export_inscritos_formato.php?' + params.toString();
}

// Función para exportar jugadores en formato específico
async function exportarJugadoresFormato() {
    const form = document.getElementById('formExportacion');
    const formData = new FormData(form);
    const torneoId = formData.get('torneo_id') || '';
    const clubs = formData.getAll('club_ids[]');
    
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verificando...';
    
    // Construir parámetros para verificación
    const verifyParams = new URLSearchParams();
    if (torneoId) verifyParams.append('torneo_id', torneoId);
    clubs.forEach(club => verifyParams.append('club_ids[]', club));
    
    try {
        // Verificar que todos tengan identificadores
        const response = await fetch(`../modules/registrants/verificar_identificadores.php?${verifyParams.toString()}`);
        const data = await response.json();
        
        if (!data.success) {
            alert(`? ERROR: ${data.message}\n\n${data.detalles || ''}`);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            return;
        }
        
        if (data.sin_identificador > 0) {
            alert(`? ERROR: Hay ${data.sin_identificador} jugador(es) sin identificador válido\n\nTodos los jugadores deben tener un número identificador antes de exportar.\n\n?? Use la opción "Numeración de Inscritos" para asignar identificadores.\n\nTotal de jugadores: ${data.total}\nCon identificador: ${data.con_identificador}\nSin identificador: ${data.sin_identificador}`);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            return;
        }
        
        // Todo OK, proceder con exportación
        const params = new URLSearchParams();
        params.append('torneo_id', torneoId);
        clubs.forEach(club => params.append('club_ids[]', club));
        
        window.location.href = '../modules/registrants/export_jugadores_formato.php?' + params.toString();
        
        setTimeout(() => {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }, 1000);
        
    } catch (error) {
        console.error('Error:', error);
        alert('✓ Error al verificar identificadores');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

// Función para exportar clubes
function exportarClubes() {
    window.location.href = '../modules/registrants/export_clubes.php';
}

// Función para previsualizar credenciales
async function previsualizarCredenciales() {
    const torneoSelect = document.getElementById('cred_torneo');
    const clubSelect = document.getElementById('cred_club');
    const torneoId = torneoSelect.value;
    const clubId = clubSelect.value;
    
    if (!torneoId || !clubId) {
        alert('✓ ERROR: Debe seleccionar torneo y club');
        if (!torneoId) torneoSelect.focus();
        else clubSelect.focus();
        return;
    }
    
    // Verificar si el torneo est• activo
    const selectedOption = torneoSelect.options[torneoSelect.selectedIndex];
    const torneoText = selectedOption.text;
    
    if (torneoText.includes('(Pasado)')) {
        alert('✓ ERROR: No se pueden generar credenciales de un torneo finalizado\n\nSolo torneos activos o futuros.');
        return;
    }
    
    // Verificar que todos los jugadores tengan identificador
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verificando...';
    
    try {
        const response = await fetch(`../modules/registrants/verificar_identificadores.php?torneo_id=${torneoId}&club_id=${clubId}`);
        const data = await response.json();
        
        if (!data.success) {
            alert(`? ERROR: ${data.message}\n\n${data.detalles || ''}`);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            return;
        }
        
        if (data.sin_identificador > 0) {
            alert(`? ERROR: Hay ${data.sin_identificador} jugador(es) sin identificador\n\nTodos los jugadores deben tener un número identificador antes de generar credenciales.\n\n?? Use la opción "Numeración de Inscritos" para asignar identificadores.`);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            return;
        }
        
        // Todo OK, redirigir a vista previa
        window.location.href = `index.php?page=control_admin&action=preview_credentials&torneo_id=${torneoId}&club_id=${clubId}`;
        
    } catch (error) {
        console.error('Error:', error);
        alert('✓ Error al verificar identificadores');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

// Función para generar credenciales en ZIP
async function generarCredencialesZip() {
    const torneoSelect = document.getElementById('cred_torneo');
    const clubSelect = document.getElementById('cred_club');
    const torneoId = torneoSelect.value;
    const clubId = clubSelect.value;
    
    if (!torneoId || !clubId) {
        alert('✓ ERROR: Debe seleccionar torneo y club');
        if (!torneoId) torneoSelect.focus();
        else clubSelect.focus();
        return;
    }
    
    // Verificar si el torneo est• activo
    const selectedOption = torneoSelect.options[torneoSelect.selectedIndex];
    const torneoText = selectedOption.text;
    
    if (torneoText.includes('(Pasado)')) {
        alert('✓ ERROR: No se pueden generar credenciales de un torneo finalizado\n\nSolo torneos activos o futuros.');
        return;
    }
    
    // Verificar que todos los jugadores tengan identificador
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verificando...';
    
    try {
        const response = await fetch(`../modules/registrants/verificar_identificadores.php?torneo_id=${torneoId}&club_id=${clubId}`);
        const data = await response.json();
        
        if (!data.success) {
            alert(`? ERROR: ${data.message}\n\n${data.detalles || ''}`);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            return;
        }
        
        if (data.sin_identificador > 0) {
            alert(`? ERROR: Hay ${data.sin_identificador} jugador(es) sin identificador\n\nTodos los jugadores deben tener un número identificador antes de generar credenciales.\n\n?? Use la opción "Numeración de Inscritos" para asignar identificadores.`);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            return;
        }
        
        // Confirmar y generar
        if (!confirm(`? Validación exitosa: ${data.total} jugador(es) con identificador\n\n¿Desea generar todas las credenciales del club en un archivo ZIP?`)) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            return;
        }
        
        window.location.href = `../modules/registrants/generate_credential.php?action=bulk&tournament_id=${torneoId}&club_id=${clubId}`;
        
        setTimeout(() => {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }, 2000);
        
    } catch (error) {
        console.error('Error:', error);
        alert('✓ Error al verificar identificadores');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

// Función para numerar consecutivamente
function numerarConsecutivo() {
    const torneoSelect = document.getElementById('num_torneo');
    const torneoId = torneoSelect.value;
    
    if (!torneoId) {
        alert('✓ ERROR: Debe seleccionar un torneo obligatoriamente');
        torneoSelect.focus();
        return;
    }
    
    // Verificar si el torneo est• activo (no finalizado)
    const selectedOption = torneoSelect.options[torneoSelect.selectedIndex];
    const torneoText = selectedOption.text;
    
    if (torneoText.includes('(Pasado)')) {
        alert('✓ ERROR: No se puede numerar un torneo finalizado\n\nSolo se permite numerar torneos activos o futuros.');
        return;
    }
    
    if (!confirm('¿Desea asignar números consecutivos (1, 2, 3...) a los inscritos del torneo seleccionado?\n\n⚠ Esta acción actualizará la base de datos.\n? El torneo est• activo.')) {
        return;
    }
    
    const form = document.getElementById('formNumeracion');
    const formData = new FormData(form);
    
    const params = new URLSearchParams();
    params.append('torneo_id', torneoId);
    
    const clubs = formData.getAll('club_ids[]');
    clubs.forEach(club => params.append('club_ids[]', club));
    
    // Deshabilitar botón
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Numerando...';
    
    fetch('../modules/registrants/numerar_identificador.php?' + params.toString(), {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ ' + data.message + '\n\nRegistros numerados: ' + data.registros_actualizados);
            location.reload();
        } else {
            alert('✓ Error: ' + (data.message || 'Error desconocido'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('✓ Error de conexión');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}

// Función para numerar por club
function numerarPorClub() {
    const torneoSelect = document.getElementById('num_torneo');
    const torneoId = torneoSelect.value;
    
    if (!torneoId) {
        alert('✓ ERROR: Debe seleccionar un torneo obligatoriamente');
        torneoSelect.focus();
        return;
    }
    
    // Verificar si el torneo est• activo (no finalizado)
    const selectedOption = torneoSelect.options[torneoSelect.selectedIndex];
    const torneoText = selectedOption.text;
    
    if (torneoText.includes('(Pasado)')) {
        alert('✓ ERROR: No se puede numerar un torneo finalizado\n\nSolo se permite numerar torneos activos o futuros.');
        return;
    }
    
    if (!confirm('¿Desea numerar los inscritos por club del torneo seleccionado?\n\n• Cada club tendrá su propia numeración (1, 2, 3...)\n⚠ Esta acción actualizará la base de datos.\n? El torneo est• activo.')) {
        return;
    }
    
    const form = document.getElementById('formNumeracion');
    const formData = new FormData(form);
    
    const params = new URLSearchParams();
    params.append('torneo_id', torneoId);
    
    const clubs = formData.getAll('club_ids[]');
    clubs.forEach(club => params.append('club_ids[]', club));
    
    // Deshabilitar botón
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Numerando...';
    
    fetch('../modules/registrants/numerar_por_club.php?' + params.toString(), {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ ' + data.message + '\n\nClubs numerados: ' + data.clubs_numerados + '\nRegistros numerados: ' + data.registros_actualizados);
            location.reload();
        } else {
            alert('✓ Error: ' + (data.message || 'Error desconocido'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('✓ Error de conexión');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    });
}
</script>

<style>
.credential-mini {
    transition: transform 0.2s;
}
.credential-mini:hover {
    transform: scale(1.05);
}
</style>

