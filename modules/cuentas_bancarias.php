<?php
/**
 * Gestión de Cuentas Bancarias
 * Permite a administradores gestionar las cuentas bancarias receptoras de pagos
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/app_helpers.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$pdo = DB::pdo();
$current_user = Auth::user();
$is_admin_club = ($current_user['role'] ?? '') === 'admin_club';
$action = $_GET['action'] ?? 'list';
$cuenta_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Retorno al torneo cuando se llama desde formulario de crear/editar torneo
$return_torneo = !empty($_GET['return_torneo']);
$torneo_action = $_GET['torneo_action'] ?? 'new';
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$url_retorno_torneo = '';
if ($return_torneo) {
    $url_retorno_torneo = AppHelpers::dashboard('tournaments', array_filter([
        'action' => $torneo_action === 'edit' ? 'edit' : 'new',
        'id' => ($torneo_action === 'edit' && $torneo_id > 0) ? $torneo_id : null,
    ]));
}

$error = '';
$success = $_GET['success'] ?? '';

// Asegurar columna de propietario en cuentas bancarias
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cuentas_bancarias")->fetchAll(PDO::FETCH_ASSOC);
    $has_owner = false;
    foreach ($cols as $col) {
        $field = strtolower($col['Field'] ?? $col['field'] ?? '');
        if ($field === 'owner_user_id') {
            $has_owner = true;
            break;
        }
    }
    if (!$has_owner) {
        $pdo->exec("ALTER TABLE cuentas_bancarias ADD COLUMN owner_user_id INT NULL AFTER id");
    }
} catch (Exception $e) {
    // Ignorar errores de alteración
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validate();
    
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear' || $accion === 'editar') {
        $cedula = trim($_POST['cedula_propietario'] ?? '');
        $nombre = trim($_POST['nombre_propietario'] ?? '');
        $telefono = trim($_POST['telefono_afiliado'] ?? '');
        $banco = trim($_POST['banco'] ?? '');
        $numero_cuenta = !empty($_POST['numero_cuenta']) ? trim($_POST['numero_cuenta']) : null;
        $tipo_cuenta = !empty($_POST['tipo_cuenta']) ? $_POST['tipo_cuenta'] : null;
        $estatus = isset($_POST['estatus']) && $_POST['estatus'] === '1' ? 1 : 0;
        
        // Validaciones
        if (empty($cedula) || empty($nombre) || empty($banco)) {
            $error = 'Todos los campos marcados con * son requeridos';
        } else {
            try {
                if ($accion === 'crear') {
                    $stmt = $pdo->prepare("
                        INSERT INTO cuentas_bancarias 
                        (cedula_propietario, nombre_propietario, telefono_afiliado, banco, numero_cuenta, tipo_cuenta, estatus, owner_user_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $owner_id = $is_admin_club ? (int)$current_user['id'] : null;
                    $stmt->execute([$cedula, $nombre, $telefono ?: null, $banco, $numero_cuenta, $tipo_cuenta, $estatus, $owner_id]);
                    // Redirigir: al torneo si vino desde ahí, sino al listado
                    $return_torneo_post = !empty($_POST['return_torneo']);
                    $torneo_action_post = $_POST['torneo_action'] ?? 'new';
                    $torneo_id_post = isset($_POST['torneo_id']) ? (int)$_POST['torneo_id'] : 0;
                    if ($return_torneo_post) {
                        $params = [
                            'action' => $torneo_action_post === 'edit' ? 'edit' : 'new',
                            'success' => 'Cuenta bancaria creada. Seleccione la cuenta en el torneo.',
                        ];
                        if ($torneo_action_post === 'edit' && $torneo_id_post > 0) {
                            $params['id'] = $torneo_id_post;
                        }
                        header('Location: ' . AppHelpers::dashboard('tournaments', $params));
                    } else {
                        header('Location: ' . AppHelpers::dashboard('cuentas_bancarias', ['success' => 'Cuenta bancaria creada exitosamente']));
                    }
                    exit;
                } else {
                    // Editar
                    $id_editar = (int)($_POST['id'] ?? 0);
                    if ($id_editar <= 0) {
                        throw new Exception('ID de cuenta inválido');
                    }

                    if ($is_admin_club) {
                        $stmt = $pdo->prepare("SELECT owner_user_id FROM cuentas_bancarias WHERE id = ?");
                        $stmt->execute([$id_editar]);
                        $owner = $stmt->fetchColumn();
                        if ((int)$owner !== (int)$current_user['id']) {
                            throw new Exception('No tiene permisos para actualizar esta cuenta');
                        }
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE cuentas_bancarias 
                        SET cedula_propietario = ?, nombre_propietario = ?, telefono_afiliado = ?, 
                            banco = ?, numero_cuenta = ?, tipo_cuenta = ?, estatus = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$cedula, $nombre, $telefono ?: null, $banco, $numero_cuenta, $tipo_cuenta, $estatus, $id_editar]);
                    // Redirigir: al torneo si vino desde ahí, sino al listado
                    $return_torneo_post = !empty($_POST['return_torneo']);
                    $torneo_action_post = $_POST['torneo_action'] ?? 'new';
                    $torneo_id_post = isset($_POST['torneo_id']) ? (int)$_POST['torneo_id'] : 0;
                    if ($return_torneo_post) {
                        $params = [
                            'action' => $torneo_action_post === 'edit' ? 'edit' : 'new',
                            'success' => 'Cuenta bancaria actualizada.',
                        ];
                        if ($torneo_action_post === 'edit' && $torneo_id_post > 0) {
                            $params['id'] = $torneo_id_post;
                        }
                        header('Location: ' . AppHelpers::dashboard('tournaments', $params));
                    } else {
                        header('Location: ' . AppHelpers::dashboard('cuentas_bancarias', ['success' => 'Cuenta bancaria actualizada exitosamente']));
                    }
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Error al guardar la cuenta: ' . $e->getMessage();
            }
        }
    } elseif ($accion === 'eliminar') {
        $id_eliminar = (int)($_POST['id'] ?? 0);
        if ($id_eliminar > 0) {
            try {
                if ($is_admin_club) {
                    $stmt = $pdo->prepare("SELECT owner_user_id FROM cuentas_bancarias WHERE id = ?");
                    $stmt->execute([$id_eliminar]);
                    $owner = $stmt->fetchColumn();
                    if ((int)$owner !== (int)$current_user['id']) {
                        throw new Exception('No tiene permisos para eliminar esta cuenta');
                    }
                }

                // Verificar si hay torneos usando esta cuenta
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE cuenta_id = ?");
                $stmt->execute([$id_eliminar]);
                $torneos_usando = $stmt->fetchColumn();
                
                if ($torneos_usando > 0) {
                    $error = 'No se puede eliminar la cuenta porque está siendo usada por ' . $torneos_usando . ' torneo(s)';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM cuentas_bancarias WHERE id = ?");
                    $stmt->execute([$id_eliminar]);
                    // Redirigir al listado con mensaje de éxito
                    header('Location: index.php?page=cuentas_bancarias&success=' . urlencode('Cuenta bancaria eliminada exitosamente'));
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Error al eliminar la cuenta: ' . $e->getMessage();
            }
        }
    }
}

// Obtener lista de cuentas
$cuentas = [];
try {
    if ($is_admin_club) {
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   COUNT(t.id) as torneos_asociados
            FROM cuentas_bancarias c
            LEFT JOIN tournaments t ON t.cuenta_id = c.id
            WHERE c.owner_user_id = ?
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([(int)$current_user['id']]);
        $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query("
            SELECT c.*, 
                   COUNT(t.id) as torneos_asociados
            FROM cuentas_bancarias c
            LEFT JOIN tournaments t ON t.cuenta_id = c.id
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ");
        $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error obteniendo cuentas bancarias: " . $e->getMessage());
    $error = 'Error al cargar las cuentas bancarias';
}

// Obtener cuenta para editar
$cuenta_editar = null;
if ($action === 'edit' && $cuenta_id > 0) {
    try {
        if ($is_admin_club) {
            $stmt = $pdo->prepare("SELECT * FROM cuentas_bancarias WHERE id = ? AND owner_user_id = ?");
            $stmt->execute([$cuenta_id, (int)$current_user['id']]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM cuentas_bancarias WHERE id = ?");
            $stmt->execute([$cuenta_id]);
        }
        $cuenta_editar = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cuenta_editar) {
            $error = 'Cuenta bancaria no encontrada';
            $action = 'list';
        }
    } catch (Exception $e) {
        $error = 'Error al cargar la cuenta: ' . $e->getMessage();
        $action = 'list';
    }
}

$csrf_token = CSRF::token();
?>
<div class="fade-in">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-university me-2"></i>
                Cuentas Bancarias
            </h1>
            <p class="text-muted mb-0">Gestiona las cuentas bancarias receptoras de pagos para torneos</p>
        </div>
        <div>
            <a href="index.php?page=cuentas_bancarias&action=new" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Nueva Cuenta
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($action === 'new' || $action === 'edit'): ?>
        <!-- Formulario de Crear/Editar -->
        <style>
            .cuentas-bancarias-form-container {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 2rem 0;
            }
            .cuentas-bancarias-form-card {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                overflow: hidden;
            }
            .cuentas-bancarias-form-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 1.5rem;
            }
        </style>
        <div class="cuentas-bancarias-form-container">
            <div class="container">
                <div class="cuentas-bancarias-form-card mx-auto" style="max-width: 60%;">
                    <div class="cuentas-bancarias-form-header">
                        <h5 class="mb-0">
                            <i class="fas fa-<?= $action === 'new' ? 'plus' : 'edit' ?> me-2"></i>
                            <?= $action === 'new' ? 'Nueva' : 'Editar' ?> Cuenta Bancaria
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="accion" value="<?= $action === 'new' ? 'crear' : 'editar' ?>">
                            <?php if ($action === 'edit'): ?>
                            <input type="hidden" name="id" value="<?= $cuenta_editar['id'] ?>">
                            <?php endif; ?>
                            <?php if ($return_torneo): ?>
                            <input type="hidden" name="return_torneo" value="1">
                            <input type="hidden" name="torneo_action" value="<?= htmlspecialchars($torneo_action) ?>">
                            <input type="hidden" name="torneo_id" value="<?= $torneo_id ?>">
                            <?php endif; ?>
                            
                            <!-- Campos en vertical -->
                            <div class="mb-3">
                                <label for="nacionalidad_propietario" class="form-label">Nacionalidad</label>
                                <select class="form-select" id="nacionalidad_propietario" name="nacionalidad_propietario" style="width: 20%;">
                                    <option value="V" <?= ($_POST['nacionalidad_propietario'] ?? 'V') === 'V' ? 'selected' : '' ?>>V</option>
                                    <option value="E" <?= ($_POST['nacionalidad_propietario'] ?? '') === 'E' ? 'selected' : '' ?>>E</option>
                                    <option value="J" <?= ($_POST['nacionalidad_propietario'] ?? '') === 'J' ? 'selected' : '' ?>>J</option>
                                    <option value="P" <?= ($_POST['nacionalidad_propietario'] ?? '') === 'P' ? 'selected' : '' ?>>P</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="cedula_propietario" class="form-label">Cédula del Propietario <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="cedula_propietario" name="cedula_propietario" 
                                       value="<?= htmlspecialchars($cuenta_editar['cedula_propietario'] ?? '') ?>" required
                                       onblur="buscarPersonaPorCedula()">
                                <div id="busqueda_resultado" class="mt-1 text-sm"></div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="nombre_propietario" class="form-label">Nombre del Propietario <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nombre_propietario" name="nombre_propietario" 
                                       value="<?= htmlspecialchars($cuenta_editar['nombre_propietario'] ?? '') ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="telefono_afiliado" class="form-label">Teléfono (Pago Móvil)</label>
                                <input type="text" class="form-control" id="telefono_afiliado" name="telefono_afiliado" 
                                       value="<?= htmlspecialchars($cuenta_editar['telefono_afiliado'] ?? '') ?>"
                                       placeholder="Ej: 0412-1234567">
                            </div>
                            
                            <div class="mb-3">
                                <label for="banco" class="form-label">Banco <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="banco" name="banco" 
                                       value="<?= htmlspecialchars($cuenta_editar['banco'] ?? '') ?>" required
                                       placeholder="Ej: Banesco, Mercantil, Banco de Venezuela">
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="estatus" name="estatus" value="1"
                                           <?= ($cuenta_editar['estatus'] ?? 1) == 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="estatus">
                                        Cuenta Activa
                                    </label>
                                </div>
                            </div>
                    
                            <div class="d-flex justify-content-between mt-4">
                                <?php if ($return_torneo && $url_retorno_torneo): ?>
                                <a href="<?= htmlspecialchars($url_retorno_torneo) ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-arrow-left me-2"></i>Volver al Torneo
                                </a>
                                <?php else: ?>
                                <a href="index.php?page=cuentas_bancarias" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>Cancelar
                                </a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                                    <i class="fas fa-save me-2"></i>Guardar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Lista de Cuentas -->
        <div class="card">
            <div class="card-header bg-dark text-warning fw-bold">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Lista de Cuentas Bancarias
                    <span class="badge bg-light text-dark ms-2"><?= count($cuentas) ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($cuentas)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Propietario</th>
                                    <th>Cédula</th>
                                    <th>Banco</th>
                                    <th>Teléfono</th>
                                    <th>Torneos</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cuentas as $cuenta): ?>
                                <tr>
                                    <td><strong>#<?= $cuenta['id'] ?></strong></td>
                                    <td><?= htmlspecialchars($cuenta['nombre_propietario']) ?></td>
                                    <td><code><?= htmlspecialchars($cuenta['cedula_propietario']) ?></code></td>
                                    <td><?= htmlspecialchars($cuenta['banco']) ?></td>
                                    <td>
                                        <?= $cuenta['telefono_afiliado'] ? htmlspecialchars($cuenta['telefono_afiliado']) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= $cuenta['torneos_asociados'] ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $cuenta['estatus'] == 1 ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $cuenta['estatus'] == 1 ? 'Activa' : 'Inactiva' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="index.php?page=cuentas_bancarias&action=edit&id=<?= $cuenta['id'] ?>" 
                                               class="btn btn-outline-primary" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="POST" action="" class="d-inline" 
                                                  onsubmit="return confirm('¿Está seguro de eliminar esta cuenta?');">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?= $cuenta['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-university text-muted fs-1 mb-3"></i>
                        <h5 class="text-muted">No hay cuentas bancarias registradas</h5>
                        <p class="text-muted">Crea tu primera cuenta bancaria para comenzar</p>
                        <a href="index.php?page=cuentas_bancarias&action=new" class="btn btn-primary mt-3">
                            <i class="fas fa-plus me-2"></i>Nueva Cuenta
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
async function buscarPersonaPorCedula() {
    const cedulaInput = document.getElementById('cedula_propietario');
    const nombreInput = document.getElementById('nombre_propietario');
    const telefonoInput = document.getElementById('telefono_afiliado');
    const nacionalidadSelect = document.getElementById('nacionalidad_propietario');
    const resultadoDiv = document.getElementById('busqueda_resultado');
    
    if (!cedulaInput || !nombreInput || !telefonoInput || !nacionalidadSelect || !resultadoDiv) {
        return; // Los elementos no existen (no estamos en el formulario)
    }
    
    const cedula = cedulaInput.value.trim();
    
    if (!cedula) {
        resultadoDiv.innerHTML = '';
        return;
    }
    
    // Extraer nacionalidad y cédula si viene en formato V12345678
    let nacionalidad_final = nacionalidadSelect.value || 'V';
    let cedula_limpia = cedula;
    
    const match = cedula.match(/^([VEJP])(\d+)$/i);
    if (match) {
        nacionalidad_final = match[1].toUpperCase();
        cedula_limpia = match[2];
        nacionalidadSelect.value = nacionalidad_final;
        cedulaInput.value = nacionalidad_final + cedula_limpia;
    }
    
    resultadoDiv.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Buscando...</span>';
    
    try {
        // Obtener la URL base de la aplicación
        const baseUrl = '<?= app_base_url() ?>';
        const response = await fetch(`${baseUrl}/public/api/search_persona.php?cedula=${encodeURIComponent(cedula_limpia)}&nacionalidad=${encodeURIComponent(nacionalidad_final)}`);
        const result = await response.json();
        
        if (result.encontrado && result.persona) {
            const persona = result.persona;
            
            if (persona.nombre) {
                nombreInput.value = persona.nombre;
            }
            
            if (persona.celular) {
                telefonoInput.value = persona.celular;
            }
            
            resultadoDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Datos completados automáticamente</span>';
        } else {
            resultadoDiv.innerHTML = '<span class="text-warning"><i class="fas fa-info-circle me-1"></i>Persona no encontrada. Complete los datos manualmente</span>';
        }
    } catch (error) {
        console.error('Error en la búsqueda:', error);
        resultadoDiv.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Error al buscar. Complete los datos manualmente</span>';
    }
}
</script>

