<?php
/**
 * Galería de Fotos del Torneo
 * Permite subir y gestionar fotos de un torneo
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/TournamentPhotoService.php';

// Verificar permisos
Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$pdo = DB::pdo();
$user = Auth::user();

$maxFotos = TournamentPhotoService::MAX_PHOTOS_PER_TORNEO;
$maxFileSize = TournamentPhotoService::MAX_UPLOAD_BYTES;
$publicBase = rtrim(AppHelpers::getPublicUrl(), '/') . '/';

// Obtener fotos del torneo
$fotos = [];
$totalFotos = 0;

try {
        $stmt = $pdo->prepare("
            SELECT 
                tp.*,
                u.nombre as subido_por_nombre
            FROM club_photos tp
            LEFT JOIN usuarios u ON tp.subido_por = u.id
            WHERE tp.torneo_id = ? AND (tp.activa = 1 OR tp.activa IS NULL)
            ORDER BY tp.orden ASC, tp.fecha_subida DESC
        ");
    $stmt->execute([$torneo_id]);
    $fotos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalFotos = count($fotos);
} catch (Exception $e) {
    error_log("Error obteniendo fotos: " . $e->getMessage());
}

// Obtener siguiente orden
$siguienteOrden = $totalFotos + 1;
?>

<div class="card shadow-lg border-0">
    <div class="card-header bg-gradient-to-r from-primary-600 to-primary-700 text-white py-3">
        <h5 class="mb-0 d-flex align-items-center">
            <i class="fas fa-images me-2"></i>Galería de Fotos del Torneo
        </h5>
    </div>
    <div class="card-body p-4">
        <!-- Información del Torneo -->
        <div class="alert alert-primary border-0 shadow-sm mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h6 class="mb-2 fw-bold">
                        <i class="fas fa-trophy me-2 text-warning"></i><?= htmlspecialchars($torneo['nombre']) ?>
                    </h6>
                    <div class="d-flex flex-wrap gap-3">
                        <small class="text-muted">
                            <i class="fas fa-calendar me-1"></i><?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                        </small>
                        <small class="text-muted">
                            <i class="fas fa-images me-1"></i><strong><?= $totalFotos ?></strong> / <?= $maxFotos ?> fotos
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Área de Subida -->
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-light border-0">
                <h6 class="mb-0 fw-bold">
                    <i class="fas fa-upload me-2 text-primary"></i>Subir Fotos
                </h6>
            </div>
            <div class="card-body">
                <?php if ($totalFotos >= $maxFotos): ?>
                    <div class="alert alert-warning border-0 shadow-sm">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Límite alcanzado:</strong> Has alcanzado el límite máximo de <?= $maxFotos ?> fotos. Elimina algunas fotos para subir nuevas.
                    </div>
                <?php else: ?>
                    <div class="border border-3 border-dashed border-primary rounded-3 p-5 text-center bg-light position-relative overflow-hidden" style="transition: all 0.3s;">
                        <div class="position-absolute top-0 start-0 w-100 h-100 bg-primary opacity-0" style="transition: opacity 0.3s;" id="upload-overlay"></div>
                        <input type="file" 
                               id="input-foto" 
                               accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" 
                               multiple
                               class="d-none"
                               data-preview-target="tournament-photos-preview"
                               data-preview-mode="multiple">
                        <label for="input-foto" class="cursor-pointer position-relative" style="z-index: 1;">
                            <i class="fas fa-cloud-upload-alt fa-4x text-primary mb-3 d-block"></i>
                            <p class="text-dark fw-bold mb-2 fs-5">Haz clic para seleccionar fotos</p>
                            <p class="text-muted mb-1">Máximo 10MB por foto (se optimiza al subir)</p>
                            <p class="text-muted small mb-0">Formatos: JPG, PNG, GIF, WEBP</p>
                            <div class="mt-3">
                                <span class="badge bg-primary fs-6 px-3 py-2">
                                    Puedes subir hasta <?= $maxFotos - $totalFotos ?> foto(s) más
                                </span>
                            </div>
                        </label>
                    </div>
                    <div id="upload-progress" class="d-none mt-3">
                        <div class="alert alert-info border-0 shadow-sm">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-spinner fa-spin me-3 fs-4"></i>
                                <span class="fw-semibold">Subiendo fotos...</span>
                            </div>
                        </div>
                    </div>
                    <div id="tournament-photos-preview"></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Galería de Fotos -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-images me-2 text-primary"></i>Fotos del Torneo 
                        <span class="badge bg-primary ms-2"><?= $totalFotos ?></span>
                    </h6>
                    <?php if (!empty($fotos)): ?>
                        <div class="d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="seleccionarTodas()">
                                <i class="fas fa-check-square me-1"></i>Seleccionar Todas
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deseleccionarTodas()">
                                <i class="fas fa-square me-1"></i>Deseleccionar Todas
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" id="btn-eliminar-seleccionadas" onclick="eliminarSeleccionadas()" disabled>
                                <i class="fas fa-trash me-1"></i>Eliminar Seleccionadas (<span id="contador-seleccionadas">0</span>)
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($fotos)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-images fa-5x text-muted mb-4 opacity-50"></i>
                        <p class="text-muted fs-5 mb-2">No hay fotos subidas aún</p>
                        <p class="text-muted">Sube fotos del torneo para crear una galería</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4" id="galeria-fotos">
                        <?php foreach ($fotos as $foto): ?>
                            <div class="col-lg-3 col-md-4 col-sm-6" data-foto-id="<?= $foto['id'] ?>">
                                <div class="position-relative group foto-item">
                                    <div class="form-check position-absolute top-0 start-0 m-2" style="z-index: 10;">
                                        <input class="form-check-input foto-checkbox" 
                                               type="checkbox" 
                                               value="<?= $foto['id'] ?>" 
                                               id="foto_<?= $foto['id'] ?>"
                                               onchange="actualizarContador()"
                                               style="width: 20px; height: 20px; background-color: white; border: 2px solid #007bff;">
                                        <label class="form-check-label" for="foto_<?= $foto['id'] ?>" style="display: none;"></label>
                                    </div>
                                    <div class="ratio ratio-1x1 rounded-3 overflow-hidden shadow-lg hover:shadow-xl transition-all foto-imagen" style="transition: all 0.3s;">
                                        <?php
                                        $imagenUrl = TournamentPhotoService::publicUrl((string) ($foto['ruta_imagen'] ?? ''), $publicBase);
                                        ?>
                                        <img src="<?= htmlspecialchars($imagenUrl) ?>"
                                             alt="Foto del torneo"
                                             class="w-100 h-100 object-fit-cover"
                                             style="transition: transform 0.3s;"
                                             loading="lazy"
                                             onmouseover="this.style.transform='scale(1.05)'"
                                             onmouseout="this.style.transform='scale(1)'"
                                             onerror="this.style.display='none'; this.parentElement.innerHTML='<div class=\'d-flex align-items-center justify-content-center h-100 bg-light rounded-3\'><i class=\'fas fa-image fa-3x text-muted\'></i></div>';">
                                    </div>
                                    <button onclick="eliminarFoto(<?= $foto['id'] ?>, this)" 
                                            class="btn btn-sm btn-danger position-absolute top-0 end-0 m-2 shadow-lg rounded-circle btn-eliminar-individual"
                                            title="Eliminar foto"
                                            style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; opacity: 0.9; transition: all 0.3s; z-index: 10;"
                                            onmouseover="this.style.opacity='1'; this.style.transform='scale(1.1)'"
                                            onmouseout="this.style.opacity='0.9'; this.style.transform='scale(1)'">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <div class="mt-2 text-center">
                                        <small class="text-muted d-block fw-semibold">Orden: <?= $foto['orden'] ?></small>
                                        <?php if ($foto['fecha_subida']): ?>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar-alt me-1"></i><?= date('d/m/Y', strtotime($foto['fecha_subida'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const torneoId = <?= $torneo_id ?>;
const maxFotos = <?= $maxFotos ?>;
let totalFotos = <?= $totalFotos ?>;

// Efecto hover en el área de subida
document.getElementById('input-foto')?.addEventListener('mouseenter', function() {
    const overlay = document.getElementById('upload-overlay');
    if (overlay) overlay.style.opacity = '0.1';
});

document.getElementById('input-foto')?.closest('div')?.addEventListener('mouseleave', function() {
    const overlay = document.getElementById('upload-overlay');
    if (overlay) overlay.style.opacity = '0';
});

document.getElementById('input-foto')?.addEventListener('change', async function(e) {
    const files = Array.from(e.target.files);
    
    if (files.length === 0) return;
    
    // Verificar límite
    if (totalFotos + files.length > maxFotos) {
        alert(`⚠️ Solo puedes subir ${maxFotos - totalFotos} foto(s) más. El límite es de ${maxFotos} fotos por torneo.`);
        this.value = '';
        return;
    }
    
    // Mostrar progreso
    const progressDiv = document.getElementById('upload-progress');
    if (progressDiv) {
        progressDiv.classList.remove('d-none');
    }
    
    let subidasExitosas = 0;
    let errores = [];
    
    // Subir cada archivo
    for (const file of files) {
        // Validar tamaño (10MB)
        if (file.size > <?= $maxFileSize ?>) {
            errores.push(`${file.name}: El archivo es demasiado grande (máximo 10MB)`);
            continue;
        }
        
        const formData = new FormData();
        formData.append('foto', file);
        formData.append('torneo_id', torneoId);
        
        try {
            const response = await fetch('<?= app_base_url() ?>/public/api/tournament_photos_upload.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                subidasExitosas++;
                totalFotos++;
            } else {
                errores.push(`${file.name}: ${result.error || 'Error al subir'}`);
            }
        } catch (error) {
            errores.push(`${file.name}: Error de conexión`);
        }
    }
    
    // Ocultar progreso
    if (progressDiv) {
        progressDiv.classList.add('d-none');
    }
    
    // Mostrar resultados
    if (subidasExitosas > 0) {
        if (errores.length > 0) {
            alert(`✅ ${subidasExitosas} foto(s) subida(s) correctamente.\n\n❌ Errores:\n${errores.join('\n')}`);
        } else {
            alert(`✅ ${subidasExitosas} foto(s) subida(s) correctamente.`);
        }
        window.location.reload();
    } else {
        alert(`❌ No se pudo subir ninguna foto.\n\nErrores:\n${errores.join('\n')}`);
    }
    
    this.value = '';
});

// Funciones de selección
function seleccionarTodas() {
    const checkboxes = document.querySelectorAll('.foto-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = true;
    });
    actualizarContador();
}

function deseleccionarTodas() {
    const checkboxes = document.querySelectorAll('.foto-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    actualizarContador();
}

function actualizarContador() {
    const checkboxes = document.querySelectorAll('.foto-checkbox:checked');
    const contador = document.getElementById('contador-seleccionadas');
    const btnEliminar = document.getElementById('btn-eliminar-seleccionadas');
    
    if (contador) {
        contador.textContent = checkboxes.length;
    }
    
    if (btnEliminar) {
        btnEliminar.disabled = checkboxes.length === 0;
    }
    
    // Resaltar fotos seleccionadas
    document.querySelectorAll('.foto-item').forEach(item => {
        const checkbox = item.querySelector('.foto-checkbox');
        const imagen = item.querySelector('.foto-imagen');
        if (checkbox && checkbox.checked) {
            item.style.border = '3px solid #007bff';
            item.style.borderRadius = '12px';
            item.style.padding = '2px';
            if (imagen) {
                imagen.style.opacity = '0.7';
            }
        } else {
            item.style.border = 'none';
            item.style.padding = '0';
            if (imagen) {
                imagen.style.opacity = '1';
            }
        }
    });
}

async function eliminarSeleccionadas() {
    const checkboxes = document.querySelectorAll('.foto-checkbox:checked');
    const fotosIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    if (fotosIds.length === 0) {
        alert('⚠️ No hay fotos seleccionadas');
        return;
    }
    
    const mensaje = fotosIds.length === 1 
        ? '¿Estás seguro de eliminar esta foto?\n\nEsta acción no se puede deshacer.'
        : `¿Estás seguro de eliminar ${fotosIds.length} fotos seleccionadas?\n\nEsta acción no se puede deshacer.`;
    
    if (!confirm(mensaje)) {
        return;
    }
    
    // Deshabilitar botón
    const btnEliminar = document.getElementById('btn-eliminar-seleccionadas');
    if (btnEliminar) {
        btnEliminar.disabled = true;
        btnEliminar.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Eliminando...';
    }
    
    let eliminadas = 0;
    let errores = [];
    
    // Eliminar cada foto
    for (const fotoId of fotosIds) {
        try {
            const response = await fetch('<?= app_base_url() ?>/public/api/tournament_photos_delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    foto_id: fotoId,
                    torneo_id: torneoId
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                eliminadas++;
            } else {
                errores.push(`Foto ID ${fotoId}: ${data.error || 'Error desconocido'}`);
            }
        } catch (error) {
            errores.push(`Foto ID ${fotoId}: Error de conexión`);
        }
    }
    
    // Mostrar resultados
    if (eliminadas > 0) {
        if (errores.length > 0) {
            alert(`✅ ${eliminadas} foto(s) eliminada(s) correctamente.\n\n❌ Errores:\n${errores.join('\n')}`);
        } else {
            alert(`✅ ${eliminadas} foto(s) eliminada(s) correctamente.`);
        }
        window.location.reload();
    } else {
        alert(`❌ No se pudo eliminar ninguna foto.\n\nErrores:\n${errores.join('\n')}`);
        if (btnEliminar) {
            btnEliminar.disabled = false;
            btnEliminar.innerHTML = '<i class="fas fa-trash me-1"></i>Eliminar Seleccionadas (<span id="contador-seleccionadas">0</span>)';
        }
    }
}

async function eliminarFoto(fotoId, button) {
    if (!confirm('¿Estás seguro de eliminar esta foto?\n\nEsta acción no se puede deshacer.')) {
        return;
    }
    
    // Deshabilitar botón
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.style.pointerEvents = 'none';
    
    try {
        const response = await fetch('<?= app_base_url() ?>/public/api/tournament_photos_delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                foto_id: fotoId,
                torneo_id: torneoId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Foto eliminada correctamente');
            window.location.reload();
        } else {
            alert('❌ ' + (data.error || 'No se pudo eliminar la foto'));
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-trash"></i>';
            button.style.pointerEvents = 'auto';
        }
    } catch (error) {
        alert('❌ Error de conexión. Por favor, intenta nuevamente.');
        button.disabled = false;
        button.innerHTML = '<i class="fas fa-trash"></i>';
        button.style.pointerEvents = 'auto';
    }
}
</script>

<style>
.cursor-pointer {
    cursor: pointer;
    transition: all 0.3s;
}

.cursor-pointer:hover {
    transform: translateY(-2px);
}

.group:hover .btn-danger {
    opacity: 1 !important;
}

.shadow-lg {
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
}

.shadow-xl {
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
}

.transition-all {
    transition: all 0.3s ease;
}

.foto-item {
    transition: all 0.3s;
}

.foto-item:hover {
    transform: translateY(-2px);
}

.foto-checkbox {
    cursor: pointer;
    z-index: 10;
}

.foto-checkbox:checked {
    background-color: #007bff;
    border-color: #007bff;
}

.foto-checkbox:focus {
    box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.25);
}
</style>



