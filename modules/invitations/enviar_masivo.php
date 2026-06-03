<?php
/**
 * Envío Masivo de Invitaciones por WhatsApp
 * Permite enviar múltiples invitaciones a la vez
 */

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/whatsapp_sender.php';

// Verificar autenticación (ya verificada en index.php, pero por seguridad)
$user = $_SESSION['user'] ?? Auth::user();
$pagination_page = isset($_GET['pag']) ? (int)$_GET['pag'] : 1;
$per_page = 20;
$offset = ($pagination_page - 1) * $per_page;

// Filtros
$torneo_filter = $_GET['torneo'] ?? '';
$estado_filter = $_GET['estado'] ?? '';

// Variable para modo de procesamiento automático
$modo_auto_envio = false;
$enlaces_whatsapp = [];

// Procesar envío masivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_masivo'])) {
    $invitations_ids = $_POST['invitations'] ?? [];
    
    if (empty($invitations_ids)) {
        $error_message = "No se seleccionaron invitaciones para enviar";
    } else {
        $sent_count = 0;
        $errors = [];
        
        foreach ($invitations_ids as $inv_id) {
            try {
                // Obtener datos de la invitación
                $stmt = DB::pdo()->prepare("
                    SELECT i.*, t.nombre as torneo_nombre, c.nombre as club_nombre, 
                           c.email as club_email, c.telefono as club_telefono
                    FROM invitations i
                    INNER JOIN tournaments t ON i.torneo_id = t.id
                    INNER JOIN clubes c ON i.club_id = c.id
                    WHERE i.id = ?
                ");
                $stmt->execute([$inv_id]);
                $invitation = $stmt->fetch();
                
                if (!$invitation) {
                    $errors[] = "Invitación #$inv_id no encontrada";
                    continue;
                }
                
                // Verificar que tiene teléfono
                if (empty($invitation['club_telefono'])) {
                    $errors[] = "Club '{$invitation['club_nombre']}' no tiene teléfono registrado";
                    continue;
                }
                
                // Generar enlace de WhatsApp
                $result = WhatsAppSender::sendInvitationWhatsApp($invitation, $invitation['club_telefono']);
                
                if ($result['success']) {
                    $sent_count++;
                    // Guardar enlace para envío automático
                    $enlaces_whatsapp[] = [
                        'id' => $inv_id,
                        'club_nombre' => $invitation['club_nombre'],
                        'telefono' => $invitation['club_telefono'],
                        'url' => $result['whatsapp_link']
                    ];
                } else {
                    $errors[] = "Error al generar enlace para '{$invitation['club_nombre']}': " . ($result['message'] ?? $result['error']);
                }
                
            } catch (Exception $e) {
                $errors[] = "Error en invitación #$inv_id: " . $e->getMessage();
            }
        }
        
        // Si hay enlaces generados, activar modo automático
        if (!empty($enlaces_whatsapp)) {
            $modo_auto_envio = true;
            $success_message = "? Se generaron $sent_count enlaces de WhatsApp";
            if (!empty($errors)) {
                $success_message .= ". " . count($errors) . " con errores.";
            }
        } else {
            $error_message = "No se pudo generar ning�n enlace. " . implode(', ', $errors);
        }
    }
}

// Obtener lista de invitaciones con filtros
$where_conditions = [];
$params = [];

if (!empty($torneo_filter)) {
    $where_conditions[] = "i.torneo_id = ?";
    $params[] = $torneo_filter;
}

if (!empty($estado_filter)) {
    $where_conditions[] = "i.estado = ?";
    $params[] = $estado_filter;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Contar total
$count_sql = "
    SELECT COUNT(*) as total
    FROM invitations i
    $where_sql
";
$stmt = DB::pdo()->prepare($count_sql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];
$total_pages = ceil($total / $per_page);

// Obtener invitaciones
$sql = "
    SELECT i.*, 
           t.nombre as torneo_nombre,
           t.fechator,
           c.nombre as club_nombre,
           c.telefono as club_telefono,
           c.email as club_email
    FROM invitations i
    INNER JOIN tournaments t ON i.torneo_id = t.id
    INNER JOIN clubes c ON i.club_id = c.id
    $where_sql
    ORDER BY i.fecha_creacion DESC
    LIMIT ? OFFSET ?
";
$params[] = $per_page;
$params[] = $offset;

$stmt = DB::pdo()->prepare($sql);
$stmt->execute($params);
$invitations = $stmt->fetchAll();

// Obtener lista de torneos para filtro
$tournaments = DB::pdo()->query("SELECT id, nombre FROM tournaments ORDER BY fechator DESC")->fetchAll();

?>

<!-- Módulo de Envío Masivo -->
<style>
    .btn-whatsapp { background: #25D366; color: white; border: none; }
    .btn-whatsapp:hover { background: #128C7E; color: white; }
    .select-all-section { background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    .invitation-row { cursor: pointer; }
    .invitation-row.selected { background-color: #e8f5e9 !important; }
    .phone-missing { color: #dc3545; }
</style>

<!-- Módulo de Envío Masivo -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-2"><i class="fab fa-whatsapp me-3"></i>Envío Masivo de Invitaciones</h1>
        <p class="text-muted mb-0">Selecciona las invitaciones que deseas enviar por WhatsApp</p>
    </div>
</div>

<!-- Mensajes -->
<?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filtros -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="index.php" class="row g-3">
            <input type="hidden" name="page" value="invitations/enviar_masivo">
                    <div class="col-md-4">
                        <label class="form-label">Torneo</label>
                        <select name="torneo" class="form-select">
                            <option value="">Todos los torneos</option>
                            <?php foreach ($tournaments as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $torneo_filter == $t['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Estado</label>
                        <select name="estado" class="form-select">
                            <option value="">Todos</option>
                            <option value="activa" <?= $estado_filter === 'activa' ? 'selected' : '' ?>>Activa</option>
                            <option value="expirada" <?= $estado_filter === 'expirada' ? 'selected' : '' ?>>Expirada</option>
                            <option value="cancelada" <?= $estado_filter === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Filtrar
                        </button>
                    </div>
            <div class="col-md-3 d-flex align-items-end">
                <a href="index.php?page=invitations" class="btn btn-secondary w-100">
                    <i class="fas fa-arrow-left me-2"></i>Volver al Listado
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Formulario de envío masivo -->
<form method="POST" action="index.php?page=invitations/enviar_masivo" id="massiveSendForm">
    <div class="select-all-section">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="selectAll">
                    <label class="form-check-label fw-bold" for="selectAll">
                        Seleccionar todas las invitaciones visibles
                    </label>
                </div>
                <small class="text-muted">
                    <span id="selectedCount">0</span> invitaciones seleccionadas
                </small>
            </div>
            <div class="col-md-6 text-end">
                <button type="submit" name="enviar_masivo" class="btn btn-whatsapp btn-lg" id="sendBtn" disabled>
                    <i class="fab fa-whatsapp me-2"></i>Enviar Seleccionadas (<span id="sendCount">0</span>)
                </button>
            </div>
        </div>
    </div>

    <!-- Tabla de invitaciones -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($invitations)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No se encontraron invitaciones con los filtros seleccionados.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAllHeader" class="form-check-input">
                                        </th>
                                        <th>ID</th>
                                        <th>Torneo</th>
                                        <th>Club</th>
                                        <th>Teléfono</th>
                                        <th>Estado</th>
                                        <th>Fecha Creación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invitations as $inv): ?>
                                        <tr class="invitation-row" data-has-phone="<?= !empty($inv['club_telefono']) ? '1' : '0' ?>">
                                            <td>
                                                <?php if (!empty($inv['club_telefono'])): ?>
                                                    <input type="checkbox" name="invitations[]" value="<?= $inv['id'] ?>" class="form-check-input invitation-checkbox">
                                                <?php else: ?>
                                                    <i class="fas fa-ban phone-missing" title="Sin teléfono"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $inv['id'] ?></td>
                                            <td><?= htmlspecialchars($inv['torneo_nombre']) ?></td>
                                            <td><?= htmlspecialchars($inv['club_nombre']) ?></td>
                                            <td>
                                                <?php if (!empty($inv['club_telefono'])): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-phone me-1"></i><?= htmlspecialchars($inv['club_telefono']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Sin teléfono</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = [
                                                    'activa' => 'bg-success',
                                                    'enviada' => 'bg-info',
                                                    'expirada' => 'bg-warning',
                                                    'cancelada' => 'bg-danger'
                                                ][$inv['estado']] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?= $badge_class ?>">
                                                    <?= ucfirst($inv['estado']) ?>
                                                </span>
                                            </td>
                                            <td><?= date('d/m/Y H:i', strtotime($inv['fecha_creacion'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $i === $pagination_page ? 'active' : '' ?>">
                                <a class="page-link" href="index.php?page=invitations/enviar_masivo&pag=<?= $i ?><?= $torneo_filter ? '&torneo=' . urlencode($torneo_filter) : '' ?><?= $estado_filter ? '&estado=' . urlencode($estado_filter) : '' ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </form>

<script>
        // Selección de checkboxes
        const selectAll = document.getElementById('selectAll');
        const selectAllHeader = document.getElementById('selectAllHeader');
        const checkboxes = document.querySelectorAll('.invitation-checkbox');
        const sendBtn = document.getElementById('sendBtn');
        const selectedCount = document.getElementById('selectedCount');
        const sendCount = document.getElementById('sendCount');

        function updateCount() {
            const checked = document.querySelectorAll('.invitation-checkbox:checked').length;
            selectedCount.textContent = checked;
            sendCount.textContent = checked;
            sendBtn.disabled = checked === 0;
        }

        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = this.checked);
            selectAllHeader.checked = this.checked;
            updateCount();
        });

        selectAllHeader.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = this.checked);
            selectAll.checked = this.checked;
            updateCount();
        });

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateCount);
        });

        // Clic en la fila para seleccionar
        document.querySelectorAll('.invitation-row').forEach(row => {
            row.addEventListener('click', function(e) {
                if (e.target.type !== 'checkbox' && this.dataset.hasPhone === '1') {
                    const checkbox = this.querySelector('.invitation-checkbox');
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                        updateCount();
                    }
                }
            });
        });

        // Confirmación antes de enviar
        document.getElementById('massiveSendForm').addEventListener('submit', function(e) {
            const count = document.querySelectorAll('.invitation-checkbox:checked').length;
            if (!confirm(`�Enviar ${count} invitaciones por WhatsApp?\n\nLos mensajes se abrir�n automáticamente uno por uno.`)) {
                e.preventDefault();
            }
        });
        
        <?php if ($modo_auto_envio && !empty($enlaces_whatsapp)): ?>
        // ENVÍO MASIVO AUTOMÁTICO
        (function() {
            const enlaces = <?= json_encode($enlaces_whatsapp, JSON_UNESCAPED_UNICODE) ?>;
            let indice = 0;
            const total = enlaces.length;
            const DELAY_ENTRE_ENVIOS = 4000; // 4 segundos entre cada envío
            
            // Crear modal de progreso
            const modalHTML = `
                <div class="modal fade" id="modalEnvioMasivo" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title">
                                    <i class="fab fa-whatsapp me-2"></i>Envío Masivo Autom�tico
                                </h5>
                            </div>
                            <div class="modal-body text-center">
                                <div class="mb-3">
                                    <div class="spinner-border text-success" role="status" style="width: 3rem; height: 3rem;">
                                        <span class="visually-hidden">Enviando...</span>
                                    </div>
                                </div>
                                <h4 id="progresoTexto">Preparando envíos...</h4>
                                <p class="text-muted mb-3" id="clubActual"></p>
                                <div class="progress" style="height: 25px;">
                                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                                         role="progressbar" style="width: 0%">
                                        <span id="progressText">0 / ${total}</span>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        ?? Espere entre 4-5 segundos entre cada envío<br>
                                        ?? Confirme el envío en cada ventana de WhatsApp que se abra
                                    </small>
                                </div>
                            </div>
                            <div class="modal-footer justify-content-center" id="modalFooter" style="display: none;">
                                <a href="index.php?page=invitations/enviar_masivo" class="btn btn-primary">
                                    ? Finalizar y Volver
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            const modal = new bootstrap.Modal(document.getElementById('modalEnvioMasivo'));
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const progresoTexto = document.getElementById('progresoTexto');
            const clubActual = document.getElementById('clubActual');
            const modalFooter = document.getElementById('modalFooter');
            
            function enviarSiguiente() {
                if (indice >= total) {
                    // Completado
                    progresoTexto.textContent = '? �Envío Masivo Completado!';
                    progresoTexto.className = 'text-success';
                    clubActual.textContent = `Se abrieron ${total} conversaciones de WhatsApp`;
                    progressBar.style.width = '100%';
                    progressText.textContent = `${total} / ${total}`;
                    progressBar.classList.remove('progress-bar-animated');
                    modalFooter.style.display = 'flex';
                    
                    // Ocultar spinner
                    document.querySelector('.spinner-border').style.display = 'none';
                    return;
                }
                
                const enlace = enlaces[indice];
                const progreso = Math.round(((indice + 1) / total) * 100);
                
                // Actualizar progreso
                progressBar.style.width = progreso + '%';
                progressText.textContent = `${indice + 1} / ${total}`;
                progresoTexto.textContent = `Enviando invitación ${indice + 1} de ${total}`;
                clubActual.innerHTML = `
                    <strong>${enlace.club_nombre}</strong><br>
                    <small>?? ${enlace.telefono}</small>
                `;
                
                // Abrir WhatsApp en nueva ventana
                window.location.href = enlace.url;
                
                // Siguiente envío después del delay
                indice++;
                setTimeout(enviarSiguiente, DELAY_ENTRE_ENVIOS);
            }
            
            // Iniciar proceso automáticamente
            modal.show();
            setTimeout(enviarSiguiente, 1000);
        })();
        <?php endif; ?>
    </script>
<!-- Fin Módulo de Envío Masivo -->
