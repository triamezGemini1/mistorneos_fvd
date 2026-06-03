<?php
/**
 * Vista: Tabla de Posiciones
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$es_parejas_posiciones = in_array((int)($torneo['modalidad'] ?? 0), [2, 4], true);
$genero_ranking = $genero_ranking ?? 'M';
?>

<?php if (!$use_standalone): ?>
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-2">
                <i class="fas fa-trophy text-primary"></i> Tabla de Posiciones
                <small class="text-muted">- <?php echo htmlspecialchars($torneo['nombre']); ?></small>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $base_url; ?>">Gestión de Torneos</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
                    <li class="breadcrumb-item active">Posiciones</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <?php
            $from_pos = $_GET['from'] ?? '';
            if ($from_pos === 'notificaciones') {
                $cu = function_exists('Auth') ? Auth::user() : null;
                $rol = $cu ? ($cu['role'] ?? '') : '';
                if ($rol === 'usuario') {
                    $urlVolver = AppHelpers::url('user_portal.php', ['section' => 'notificaciones']);
                } else {
                    $urlVolver = AppHelpers::dashboard('user_notificaciones');
                }
                $labelVolver = 'Volver a Notificaciones';
            } else {
                $urlVolver = $base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . $torneo['id'];
                $labelVolver = 'Volver al Panel';
            }
            ?>
            <a href="<?php echo htmlspecialchars($urlVolver); ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i> <?php echo htmlspecialchars($labelVolver); ?>
            </a>
            <?php if (class_exists('AppHelpers')): $tid = (int)($torneo['id'] ?? 0);
                $u = static function (string $a, array $x = []) use ($tid) {
                    return AppHelpers::url('index.php', array_merge(['page' => 'torneo_gestion', 'action' => $a, 'torneo_id' => $tid], $x));
                }; ?>
            <a href="<?php echo htmlspecialchars(AppHelpers::url('export_resultados_pdf.php', ['torneo_id' => $tid, 'tipo' => 'posiciones', 'genero' => $genero_ranking])); ?>" target="_blank" rel="noopener" class="btn btn-danger text-dark fw-bold border border-dark">
                <i class="fas fa-file-pdf mr-1"></i> PDF posiciones
            </a>
            <a href="<?php echo htmlspecialchars($u('resultados_reportes_print', array_merge(['tipo' => 'posiciones'], ['genero' => $genero_ranking]))); ?>" target="_blank" rel="noopener" class="btn btn-warning text-dark fw-bold border border-dark">
                <i class="fas fa-print mr-1"></i> Imprimir / vista
            </a>
            <a href="<?php echo htmlspecialchars($u('resultados_reportes', ['genero' => $genero_ranking])); ?>" class="btn btn-outline-secondary fw-bold">
                <i class="fas fa-file-alt mr-1"></i> Todos los reportes
            </a>
            <a href="<?php echo htmlspecialchars(AppHelpers::url('export_resultados_pdf.php', ['torneo_id' => $tid, 'tipo' => 'todos', 'genero' => $genero_ranking])); ?>" target="_blank" rel="noopener" class="btn btn-outline-danger fw-bold border border-dark" title="Un solo PDF con clasificación, clubes y consolidado">
                <i class="fas fa-file-pdf mr-1"></i> PDF (todos)
            </a>
            <a href="<?php echo htmlspecialchars($u('resultados_reportes_print', array_merge(['tipo' => 'todos'], ['genero' => $genero_ranking]))); ?>" target="_blank" rel="noopener" class="btn btn-outline-info fw-bold border border-dark" title="Vista imprimible con todos los bloques">
                <i class="fas fa-print mr-1"></i> Imprimir todos
            </a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="breadcrumb-modern mb-0">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo $base_url; ?>">Gestión de Torneos</a></li>
            <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
            <li class="breadcrumb-item active">Posiciones</li>
        </ol>
    </div>
    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>" class="btn btn-primary btn-sm flex-shrink-0">
        <i class="fas fa-arrow-left me-1"></i> Volver al Panel de Control
    </a>
    <?php if (class_exists('AppHelpers')): $tid = (int)($torneo['id'] ?? 0);
        $u2 = static function (string $a, array $x = []) use ($tid) {
            return AppHelpers::url('index.php', array_merge(['page' => 'torneo_gestion', 'action' => $a, 'torneo_id' => $tid], $x));
        }; ?>
    <a href="<?php echo htmlspecialchars(AppHelpers::url('export_resultados_pdf.php', ['torneo_id' => $tid, 'tipo' => 'posiciones', 'genero' => $genero_ranking])); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-danger text-dark fw-bold border border-dark">PDF</a>
    <a href="<?php echo htmlspecialchars($u2('resultados_reportes_print', array_merge(['tipo' => 'posiciones'], ['genero' => $genero_ranking]))); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-warning text-dark fw-bold">Imprimir</a>
    <a href="<?php echo htmlspecialchars($u2('resultados_reportes', ['genero' => $genero_ranking])); ?>" class="btn btn-sm btn-outline-secondary fw-bold">Reportes</a>
    <a href="<?php echo htmlspecialchars(AppHelpers::url('export_resultados_pdf.php', ['torneo_id' => $tid, 'tipo' => 'todos', 'genero' => $genero_ranking])); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-danger fw-bold border border-dark" title="Un solo PDF con todos los bloques">PDF todos</a>
    <a href="<?php echo htmlspecialchars($u2('resultados_reportes_print', array_merge(['tipo' => 'todos'], ['genero' => $genero_ranking]))); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info fw-bold border border-dark" title="Vista imprimible con todos los bloques">Impr. todos</a>
    <?php endif; ?>
</div>
<?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo $es_parejas_posiciones ? 'Clasificación General de Parejas' : 'Clasificación General'; ?></h5>
                </div>
                <div class="card-body">
                    <?php
                    $urlPosGenero = static function (string $g) use ($base_url, $use_standalone, $torneo): string {
                        $p = ['action' => 'posiciones', 'torneo_id' => (int) ($torneo['id'] ?? 0), 'genero' => $g];

                        return $base_url . ($use_standalone ? '?' : '&') . http_build_query($p);
                    };
                    ?>
                    <div class="mb-3 btn-group flex-wrap" role="group" aria-label="Clasificación por género">
                        <a href="<?php echo htmlspecialchars($urlPosGenero('M')); ?>" class="btn btn-sm <?php echo $genero_ranking === 'M' ? 'btn-primary' : 'btn-outline-primary'; ?>">Masculino</a>
                        <a href="<?php echo htmlspecialchars($urlPosGenero('F')); ?>" class="btn btn-sm <?php echo $genero_ranking === 'F' ? 'btn-primary' : 'btn-outline-primary'; ?>">Femenino</a>
                    </div>
                    <p class="small text-muted mb-3">Ranking mostrado solo para el género seleccionado (posiciones reenumeradas dentro del grupo).</p>
                    <?php if (empty($posiciones)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle mr-2"></i>
                            Aún no hay jugadores inscritos o no hay posiciones calculadas.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Pos</th>
                                        <th><?php echo $es_parejas_posiciones ? 'Código pareja' : 'ID Usuario'; ?></th>
                                        <th><?php echo $es_parejas_posiciones ? 'Pareja' : 'Jugador'; ?></th>
                                        <th>Equipo</th>
                                        <th>Club</th>
                                        <th>G</th>
                                        <th>P</th>
                                        <th>GFF</th>
                                        <th>Efect.</th>
                                        <th>Puntos</th>
                                        <th>Pts. Rnk</th>
                                        <th>Sanc.</th>
                                        <th>Tarj.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Calcular paginación antes del loop
                                    if (!isset($items_por_pagina_pos)) {
                                        require_once __DIR__ . '/../../lib/Tournament/Services/PaginationService.php';
                                        $items_por_pagina_pos = 30;
                                        $pagina_raw_pos = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;
                                        $total_posiciones = count($posiciones);
                                        $p_pos = \Tournament\Services\PaginationService::getParams($total_posiciones, $pagina_raw_pos, $items_por_pagina_pos);
                                        $pagina_actual_pos = $p_pos['page'];
                                        $total_paginas_pos = $p_pos['total_pages'];
                                        $posiciones_paginadas = ($total_paginas_pos > 1)
                                            ? array_slice($posiciones, $p_pos['offset'], $p_pos['per_page'])
                                            : $posiciones;
                                    }
                                    
                                    $pos_idx = 0;
                                    foreach ($posiciones_paginadas as $pos): 
                                        $pos_idx++;
                                        // Usar la posición calculada directamente desde la base de datos
                                        $posicion_actual = (int)($pos['posicion'] ?? 0);
                                        
                                        // Si la posición es 0 o no existe, calcularla basándose en el orden
                                        if ($posicion_actual == 0) {
                                            // Esto no debería pasar si recalcularPosiciones se ejecutó correctamente
                                            $posicion_actual = (int)($pos['posicion'] ?? 0);
                                        }
                                        
                                        $medalla_class = '';
                                        if ($posicion_actual == 1) $medalla_class = 'table-warning';
                                        elseif ($posicion_actual == 2) $medalla_class = 'table-secondary';
                                        elseif ($posicion_actual == 3) $medalla_class = 'table-light';
                                        $stripe_pos = ($medalla_class === '' && ($pos_idx % 2 === 0)) ? 'bg-light' : '';
                                    ?>
                                        <tr class="<?php echo $medalla_class; ?> <?php echo $stripe_pos; ?>">
                                            <td>
                                                <strong><?php echo $posicion_actual; ?></strong>
                                                <?php if ($posicion_actual <= 3): ?>
                                                    <i class="fas fa-medal text-warning"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo htmlspecialchars((string)($pos['id_usuario'] ?? 'N/A')); ?></code></td>
                                            <td>
                                                <?php if ($es_parejas_posiciones): ?>
                                                    <span class="font-weight-bold text-dark">
                                                        <i class="fas fa-user-friends mr-1"></i>
                                                        <?php echo htmlspecialchars($pos['nombre_completo'] ?? $pos['nombre'] ?? 'N/A'); ?>
                                                    </span>
                                                <?php else: ?>
                                                <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=resumen_individual&torneo_id=<?php echo $torneo['id']; ?>&inscrito_id=<?php echo $pos['id_usuario']; ?>&from=posiciones" 
                                                   class="text-primary">
                                                    <i class="fas fa-user mr-1"></i>
                                                    <?php echo htmlspecialchars($pos['nombre_completo'] ?? $pos['nombre'] ?? 'N/A'); ?>
                                                </a>
                                                <?php endif; ?>
                                                <?php 
                                                $es_retirado = (isset($pos['estatus']) && ((int)$pos['estatus'] === 4 || $pos['estatus'] === 'retirado'));
                                                if ($es_retirado): ?>
                                                    <span class="badge badge-dark ml-1">Retirado</span>
                                                <?php endif; ?>
                                                <?php if (!empty($pos['sexo'])): ?>
                                                    <small class="text-muted">(<?php echo $pos['sexo'] == 'M' || $pos['sexo'] == 1 ? '♂' : '♀'; ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $nombre_equipo = $pos['nombre_equipo'] ?? '';
                                                $codigo_equipo = $pos['codigo_equipo'] ?? '';
                                                if (!empty($codigo_equipo)) {
                                                    echo '<i class="fas fa-users mr-1 text-purple-600"></i>';
                                                    if (!empty($nombre_equipo)) {
                                                        echo htmlspecialchars($nombre_equipo);
                                                        if (!empty($codigo_equipo)) {
                                                            echo ' <small class="text-muted">(' . htmlspecialchars($codigo_equipo) . ')</small>';
                                                        }
                                                    } else {
                                                        echo '<small class="text-muted">Equipo ' . htmlspecialchars($codigo_equipo) . '</small>';
                                                    }
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($pos['club_nombre'] ?? 'Sin Club'); ?></td>
                                            <td><strong><?php echo (int)($pos['ganados'] ?? 0); ?></strong></td>
                                            <td><?php echo (int)($pos['perdidos'] ?? 0); ?></td>
                                            <td>
                                                <span class="badge badge-danger" style="color: red !important; background-color: #f8d7da;"><?php echo (int)($pos['ganadas_por_forfait'] ?? $pos['gff'] ?? 0); ?></span>
                                                <?php 
                                                $partidas_bye = (int)($pos['partidas_bye'] ?? 0); 
                                                if ($partidas_bye > 0): 
                                                ?>
                                                    <span class="badge ml-1" style="background-color: #0d9488; color: #fff; font-weight: bold;" title="Partidas con descanso (BYE): partida ganada, 100% puntos, 50% efectividad"><?php echo $partidas_bye; ?> BYE</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo (int)($pos['efectividad'] ?? 0); ?></td>
                                            <td><strong><?php echo (int)($pos['puntos'] ?? 0); ?></strong></td>
                                            <td><strong class="text-primary"><?php echo $es_retirado ? '—' : (int)($pos['ptosrnk'] ?? 0); ?></strong></td>
                                            <td>
                                                <?php 
                                                $sancion = (int)($pos['sancion'] ?? 0);
                                                if ($sancion > 0) {
                                                    echo '<span class="badge badge-warning" style="color: orange !important;">' . $sancion . '</span>';
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $tarjeta = (int)($pos['tarjeta'] ?? 0);
                                                if ($tarjeta > 0) {
                                                    if ($tarjeta == 1) {
                                                        echo '<span class="badge badge-warning" title="Tarjeta Amarilla">🟨</span>';
                                                    } elseif ($tarjeta == 3) {
                                                        echo '<span class="badge badge-danger" title="Tarjeta Roja">🟥</span>';
                                                    } elseif ($tarjeta == 4) {
                                                        echo '<span class="badge badge-dark" title="Tarjeta Negra">⬛</span>';
                                                    }
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php 
                        // Mostrar paginador si hay más de una página
                        if ($total_paginas_pos > 1): 
                            // Construir URL base para el paginador
                            $parametros_get_pos = ['action' => 'posiciones', 'torneo_id' => $torneo['id']];
                            // Preservar otros parámetros GET si existen
                            foreach ($_GET as $key => $value) {
                                if ($key !== 'pagina' && $key !== 'action' && $key !== 'torneo_id') {
                                    $parametros_get_pos[$key] = $value;
                                }
                            }
                        ?>
                            <div class="mt-4 d-flex justify-content-center align-items-center gap-3">
                                <?php if ($pagina_actual_pos > 1): ?>
                                    <?php $parametros_get_pos['pagina'] = 1; ?>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&') . http_build_query($parametros_get_pos); ?>" class="btn btn-sm btn-secondary">
                                        <i class="fas fa-angle-double-left"></i> Primera
                                    </a>
                                    <?php $parametros_get_pos['pagina'] = $pagina_actual_pos - 1; ?>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&') . http_build_query($parametros_get_pos); ?>" class="btn btn-sm btn-secondary">
                                        <i class="fas fa-angle-left"></i> Anterior
                                    </a>
                                <?php else: ?>
                                    <span class="btn btn-sm btn-secondary disabled"><i class="fas fa-angle-double-left"></i> Primera</span>
                                    <span class="btn btn-sm btn-secondary disabled"><i class="fas fa-angle-left"></i> Anterior</span>
                                <?php endif; ?>
                                
                                <span class="badge badge-info">Página <?php echo $pagina_actual_pos; ?> de <?php echo $total_paginas_pos; ?></span>
                                
                                <?php if ($pagina_actual_pos < $total_paginas_pos): ?>
                                    <?php $parametros_get_pos['pagina'] = $pagina_actual_pos + 1; ?>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&') . http_build_query($parametros_get_pos); ?>" class="btn btn-sm btn-secondary">
                                        Siguiente <i class="fas fa-angle-right"></i>
                                    </a>
                                    <?php $parametros_get_pos['pagina'] = $total_paginas_pos; ?>
                                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&') . http_build_query($parametros_get_pos); ?>" class="btn btn-sm btn-secondary">
                                        Última <i class="fas fa-angle-double-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="btn btn-sm btn-secondary disabled">Siguiente <i class="fas fa-angle-right"></i></span>
                                    <span class="btn btn-sm btn-secondary disabled">Última <i class="fas fa-angle-double-right"></i></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <strong>Leyenda:</strong>
                                <span class="badge badge-warning">1°</span> Oro |
                                <span class="badge badge-secondary">2°</span> Plata |
                                <span class="badge badge-light">3°</span> Bronce
                                <br>
                                <strong>G:</strong> Ganados | <strong>P:</strong> Perdidos | <strong>GFF:</strong> Ganadas por Forfait | <strong>BYE:</strong> Partidas con descanso (información) | <strong>Efect.:</strong> Efectividad | <strong>Puntos:</strong> Puntos del torneo | <strong>Pts. Rnk:</strong> Puntos de Ranking | <strong>Sanc.:</strong> Sanciones | <strong>Tarj.:</strong> Estado de tarjeta en el torneo (🟨 Amarilla, 🟥 Roja, ⬛ Negra)
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

