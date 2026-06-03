<?php
/**
 * Panel de Inscripción de Jugadores
 */

session_start();

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/db.php';

try {
    $pdo = DB::pdo();
    
    $torneo_id = $_SESSION['torneo_id'];
    $club_id = $_SESSION['club_id'];
    
    // Obtener información del torneo
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener información del club
    $stmt = $pdo->prepare("SELECT * FROM clubes WHERE id = ?");
    $stmt->execute([$club_id]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener jugadores inscritos
    $stmt = $pdo->prepare("
        SELECT * FROM inscripciones 
        WHERE torneo_id = ? AND club_id = ? 
        ORDER BY nombre ASC
    ");
    $stmt->execute([$torneo_id, $club_id]);
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas por género
    // En la tabla registrants: 1 = Masculino, 2 = Femenino
    $total_inscritos = count($inscritos);
    $hombres = count(array_filter($inscritos, function($r) { 
        return $r['sexo'] == 1 || strtoupper($r['sexo']) === 'M'; 
    }));
    $mujeres = count(array_filter($inscritos, function($r) { 
        return $r['sexo'] == 2 || strtoupper($r['sexo']) === 'F'; 
    }));
    
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripción de Jugadores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .stats-card {
            border-radius: 10px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }
        .stats-card h3 {
            font-size: 2rem;
            margin: 10px 0;
        }
    </style>
</head>
<body>

<div class="container" style="max-width: 60%;">
<div class="mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3>?? Inscripción de Jugadores</h3>
            <p class="mb-0"><strong>Torneo:</strong> <?= htmlspecialchars($_SESSION['torneo_nombre']) ?></p>
            <p class="mb-0"><strong>Club:</strong> <?= htmlspecialchars($_SESSION['club_nombre']) ?></p>
        </div>
        <div>
            <a href="logout.php" class="btn btn-secondary">?? Cerrar Sesión</a>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stats-card bg-primary">
                <h5>Total Inscritos</h5>
                <h3><?= $total_inscritos ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card bg-info">
                <h5>Hombres</h5>
                <h3><?= $hombres ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card bg-success">
                <h5>Mujeres</h5>
                <h3><?= $mujeres ?></h3>
            </div>
        </div>
    </div>

    <!-- Mensaje -->
    <div id="mensaje" class="alert d-none"></div>

    <!-- Formulario de Inscripción -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">? Inscribir Nuevo Jugador</h5>
        </div>
        <div class="card-body">
            <form id="formInscribir">
                <div class="row">
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label">Nacionalidad <span class="text-danger">*</span></label>
                            <select name="nacionalidad" id="nacionalidad" class="form-select" required>
                                <option value="">...</option>
                                <option value="V" selected>V - Venezolano</option>
                                <option value="E">E - Extranjero</option>
                                <option value="J">J - Jur�dico</option>
                                <option value="P">P - Pasaporte</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Número de Cédula <span class="text-danger">*</span></label>
                            <input type="text" name="numero_cedula" id="numero_cedula" class="form-control" 
                                   placeholder="Ej: 12345678" required autofocus>
                            <small class="text-muted">Solo números</small>
                            <input type="hidden" name="cedula" id="cedula">
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="mb-3">
                            <label class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" id="nombre" class="form-control" 
                                   placeholder="Nombre completo del jugador" required readonly>
                            <small class="text-muted">Se cargar• automáticamente al buscar</small>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Sexo <span class="text-danger">*</span></label>
                            <select name="sexo" id="sexo" class="form-select" required>
                                <option value="">Seleccione...</option>
                                <option value="M">Masculino</option>
                                <option value="F">Femenino</option>
                            </select>
                        </div>
                    </div>
                    <!-- Fecha Nacimiento (oculto) -->
                    <input type="hidden" name="fechnac" id="fechnac">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Categoría</label>
                            <input type="text" id="categDisplay" class="form-control" readonly 
                                   placeholder="Se calculará automáticamente" 
                                   style="background-color: #f8f9fa; cursor: not-allowed;">
                            <input type="hidden" name="categ" id="categ" value="0">
                            <small class="text-muted">Basada en edad</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Celular</label>
                            <input type="text" name="celular" id="celular" class="form-control" 
                                   placeholder="04XX-XXXXXXX">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-lg w-100" id="btnInscribir" disabled>
                            ? Inscribir Jugador
                        </button>
                        <div id="mensaje-busqueda" class="mt-2"></div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de Inscritos -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">?? Jugadores Inscritos (<?= $total_inscritos ?>)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="tablaInscritos">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Cédula</th>
                            <th>Nombre</th>
                            <th>Sexo</th>
                            <th>Categoría</th>
                            <th>Celular</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inscritos)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <p class="text-muted mb-0">No hay jugadores inscritos todav�a</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inscritos as $idx => $jugador): 
                                // Determinar categoría para mostrar
                                $categNombre = 'N/A';
                                if ($jugador['categ'] == 1) {
                                    $categNombre = '• Junior';
                                } elseif ($jugador['categ'] == 2) {
                                    $categNombre = '• Libre';
                                } elseif ($jugador['categ'] == 3) {
                                    $categNombre = '• Master';
                                }
                                
                                // Convertir sexo de num�rico a texto para mostrar
                                $sexoTexto = $jugador['sexo'] == 2 ? 'F' : 'M';
                            ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><?= htmlspecialchars($jugador['cedula']) ?></td>
                                    <td><?= htmlspecialchars($jugador['nombre']) ?></td>
                                    <td><?= $sexoTexto ?></td>
                                    <td><?= $categNombre ?></td>
                                    <td><?= htmlspecialchars($jugador['celular'] ?: 'N/A') ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="retirarJugador(<?= $jugador['id'] ?>)">
                                            ??? Retirar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
// Función para calcular categoría basada en fecha de nacimiento
function calcularCategoria(fechaNac) {
    if (!fechaNac) {
        return { valor: 0, nombre: '' };
    }
    
    const hoy = new Date();
    const nacimiento = new Date(fechaNac);
    let edad = hoy.getFullYear() - nacimiento.getFullYear();
    const mes = hoy.getMonth() - nacimiento.getMonth();
    
    // Ajustar edad si a�n no ha cumplido años este a�o
    if (mes < 0 || (mes === 0 && hoy.getDate() < nacimiento.getDate())) {
        edad--;
    }
    
    // Determinar categoría según edad
    if (edad < 19) {
        return { valor: 1, nombre: 'Junior (< 19 años)' };
    } else if (edad > 60) {
        return { valor: 3, nombre: 'Master (> 60 años)' };
    } else {
        return { valor: 2, nombre: 'Libre (19-60 años)' };
    }
}

// Calcular categoría al cambiar fecha de nacimiento
document.getElementById('fechnac').addEventListener('change', function() {
    const categoria = calcularCategoria(this.value);
    document.getElementById('categ').value = categoria.valor;
    document.getElementById('categDisplay').value = categoria.nombre;
});

// Función para buscar persona
async function buscarPersona() {
    const nacionalidad = document.getElementById('nacionalidad').value;
    const numeroCedula = document.getElementById('numero_cedula').value.trim();
    const mensajeBusqueda = document.getElementById('mensaje-busqueda');
    const btnInscribir = document.getElementById('btnInscribir');
    
    // Limpiar campos
    document.getElementById('nombre').value = '';
    document.getElementById('sexo').value = '';
    document.getElementById('fechnac').value = '';
    document.getElementById('categ').value = '0';
    document.getElementById('categDisplay').value = '';
    document.getElementById('cedula').value = '';
    btnInscribir.disabled = true;
    mensajeBusqueda.innerHTML = '';
    
    if (!nacionalidad || !numeroCedula) {
        return;
    }
    
    // Validar que el número solo contenga d�gitos (permitir V/E/J/P opcional al inicio)
    const numeroCedulaLimpio = numeroCedula.replace(/^[VEJP]/i, '');
    if (!/^\d+$/.test(numeroCedulaLimpio)) {
        mensajeBusqueda.innerHTML = '<div class="alert alert-warning">?? El número de cédula solo debe contener d�gitos</div>';
        return;
    }
    
    // Actualizar campo oculto (solo el número, sin nacionalidad)
    document.getElementById('cedula').value = numeroCedulaLimpio;
    
    // Mostrar indicador de carga
    const cedulaCompleta = nacionalidad + numeroCedulaLimpio;
    mensajeBusqueda.innerHTML = '<div class="alert alert-info">?? Buscando ' + cedulaCompleta + ' en base de datos persona...</div>';
    
    console.log('• Buscando:', { nacionalidad, cedula: numeroCedulaLimpio });
    
    try {
        // Enviar nacionalidad y cedula por separado
        const url = 'buscar_persona.php?nacionalidad=' + encodeURIComponent(nacionalidad) + '&cedula=' + encodeURIComponent(numeroCedulaLimpio);
        console.log('• URL:', url);
        
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('• Respuesta:', data);
        
        // Verificar si ya est• inscrito en este torneo
        if (data.ya_inscrito) {
            mensajeBusqueda.innerHTML = '<div class="alert alert-warning">' + data.error + '</div>';
            btnInscribir.disabled = true;
            return;
        }
        
        if (data.encontrado && data.persona) {
            // Llenar campos automáticamente
            document.getElementById('nombre').value = data.persona.nombre || '';
            document.getElementById('sexo').value = data.persona.sexo || '';
            document.getElementById('fechnac').value = data.persona.fechnac || '';
            
            // Calcular categoría automáticamente
            if (data.persona.fechnac) {
                const categoria = calcularCategoria(data.persona.fechnac);
                document.getElementById('categ').value = categoria.valor;
                document.getElementById('categDisplay').value = categoria.nombre;
            }
            
            // Habilitar botón de inscripción
            btnInscribir.disabled = false;
            
            const fuente = data.fuente === 'local' ? '(BD Local)' : '(BD Externa)';
            mensajeBusqueda.innerHTML = '<div class="alert alert-success">? Persona encontrada ' + fuente + ': ' + data.persona.nombre + '</div>';
        } else {
            mensajeBusqueda.innerHTML = '<div class="alert alert-danger">? ' + (data.error || 'No se encontr• la persona con cédula ' + cedulaCompleta) + '</div>';
            btnInscribir.disabled = true;
        }
    } catch (error) {
        console.error('? Error:', error);
        mensajeBusqueda.innerHTML = '<div class="alert alert-danger">? Error de conexión: ' + error.message + '</div>';
        btnInscribir.disabled = true;
    }
}

// Buscar persona al perder foco en el campo número de cédula
document.getElementById('numero_cedula').addEventListener('blur', buscarPersona);

// Buscar al presionar Enter en el campo número de cédula
document.getElementById('numero_cedula').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        buscarPersona();
    }
});

// Tambi�n buscar al cambiar la nacionalidad si ya hay un número
document.getElementById('nacionalidad').addEventListener('change', function() {
    const numeroCedula = document.getElementById('numero_cedula').value.trim();
    if (numeroCedula) {
        buscarPersona();
    }
});

// Inscribir jugador
document.getElementById('formInscribir').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const mensaje = document.getElementById('mensaje');
    
    try {
        const response = await fetch('inscribir_jugador.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            mostrarMensaje(data.message, 'success');
            this.reset();
            document.getElementById('btnInscribir').disabled = true;
            document.getElementById('mensaje-busqueda').innerHTML = '';
            setTimeout(() => location.reload(), 1500);
        } else {
            mostrarMensaje(data.message, 'danger');
        }
    } catch (error) {
        mostrarMensaje('Error de conexión: ' + error.message, 'danger');
    }
});

// Retirar jugador
async function retirarJugador(id) {
    if (!confirm('¿Está seguro de retirar este jugador?')) {
        return;
    }
    
    try {
        const response = await fetch('retirar_jugador.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `id=${id}`
        });
        
        const data = await response.json();
        
        if (data.success) {
            mostrarMensaje(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            mostrarMensaje(data.message, 'danger');
        }
    } catch (error) {
        mostrarMensaje('Error de conexión: ' + error.message, 'danger');
    }
}

// Mostrar mensaje
function mostrarMensaje(texto, tipo) {
    const mensaje = document.getElementById('mensaje');
    mensaje.className = `alert alert-${tipo}`;
    mensaje.textContent = texto;
    mensaje.classList.remove('d-none');
    
    setTimeout(() => {
        mensaje.classList.add('d-none');
    }, 5000);
}
</script>

</body>
</html>

