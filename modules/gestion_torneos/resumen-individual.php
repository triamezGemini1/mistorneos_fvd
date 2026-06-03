<?php
/**
 * Vista: Resumen Individual de Jugador
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
?>

<?php if (!$use_standalone): ?>
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-2">
                <i class="fas fa-user-circle text-primary"></i> Resumen Individual
                <small class="text-muted">- <?php echo htmlspecialchars($inscrito['nombre_completo'] ?? $inscrito['nombre'] ?? 'Jugador'); ?></small>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo $base_url; ?>">Gestión de Torneos</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
                    <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=posiciones&torneo_id=<?php echo $torneo['id']; ?>">Posiciones</a></li>
                    <li class="breadcrumb-item active">Resumen Individual</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <?php 
            // Construir URL de retorno según el origen
            // Prioridad: from (más confiable) > urlRetorno (viene de obtenerDatosResumenIndividual) > vieneDePosiciones > panel por defecto
            $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . $torneo['id']; // Valor por defecto
            
            // Primero verificar el parámetro from directamente (más confiable)
            if (isset($_GET['from']) && !empty($_GET['from'])) {
                $from_param = $_GET['from'];
                if ($from_param === 'notificaciones') {
                    $cu = function_exists('Auth') ? Auth::user() : null;
                    $rol = $cu ? ($cu['role'] ?? '') : '';
                    if ($rol === 'usuario') {
                        $urlRetornoFinal = AppHelpers::url('user_portal.php', ['section' => 'notificaciones']);
                    } else {
                        $urlRetornoFinal = AppHelpers::dashboard('user_notificaciones');
                    }
                } elseif ($from_param === 'posiciones') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=posiciones&torneo_id=' . $torneo['id'];
                } elseif ($from_param === 'resultados_equipos_detallado') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=resultados_equipos_detallado&torneo_id=' . $torneo['id'];
                } elseif ($from_param === 'resultados_equipos_resumido') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=resultados_equipos_resumido&torneo_id=' . $torneo['id'];
                } elseif ($from_param === 'resultados_general') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=resultados_general&torneo_id=' . $torneo['id'];
                } elseif ($from_param === 'resultados_por_club') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=resultados_por_club&torneo_id=' . $torneo['id'];
                }
            } elseif (isset($from) && !empty($from)) {
                // Usar la variable $from si está disponible (viene de extract)
                if ($from === 'notificaciones') {
                    $cu = function_exists('Auth') ? Auth::user() : null;
                    $rol = $cu ? ($cu['role'] ?? '') : '';
                    if ($rol === 'usuario') {
                        $urlRetornoFinal = AppHelpers::url('user_portal.php', ['section' => 'notificaciones']);
                    } else {
                        $urlRetornoFinal = AppHelpers::dashboard('user_notificaciones');
                    }
                } elseif ($from === 'posiciones') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=posiciones&torneo_id=' . $torneo['id'];
                } elseif ($from === 'resultados_equipos_detallado') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=resultados_equipos_detallado&torneo_id=' . $torneo['id'];
                } elseif ($from === 'resultados_equipos_resumido') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=resultados_equipos_resumido&torneo_id=' . $torneo['id'];
                } elseif ($from === 'resultados_general') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=resultados_general&torneo_id=' . $torneo['id'];
                } elseif ($from === 'resultados_por_club') {
                    $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=resultados_por_club&torneo_id=' . $torneo['id'];
                }
            } elseif (isset($urlRetorno) && !empty($urlRetorno) && strpos($urlRetorno, 'panel') === false) {
                // Usar urlRetorno solo si no es el panel por defecto
                $urlRetornoFinal = $urlRetorno;
            } elseif (isset($vieneDePosiciones) && $vieneDePosiciones) {
                $urlRetornoFinal = $base_url . ($use_standalone ? '?' : '&') . 'action=posiciones&torneo_id=' . $torneo['id'];
            }
            $volverLabel = (isset($_GET['from']) && $_GET['from'] === 'notificaciones') ? 'Volver a Notificaciones' : 'Volver';
            ?>
            <a href="<?php echo htmlspecialchars($urlRetornoFinal); ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i> <?php echo htmlspecialchars($volverLabel); ?>
            </a>
        </div>
    </div>
<?php else: ?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="breadcrumb-modern mb-0">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo $base_url; ?>">Gestión de Torneos</a></li>
            <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
            <li class="breadcrumb-item"><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=posiciones&torneo_id=<?php echo $torneo['id']; ?>">Posiciones</a></li>
            <li class="breadcrumb-item active">Resumen Individual</li>
        </ol>
    </div>
    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=posiciones&torneo_id=<?php echo $torneo['id']; ?>" class="btn btn-primary btn-sm flex-shrink-0">
        <i class="fas fa-arrow-left me-1"></i> Volver al listado de posiciones
    </a>
</div>
<?php endif; ?>

    <?php $esEqResumen = !empty($es_modalidad_equipos) && !empty($equipo); ?>
    <!-- Información del Jugador -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white py-2">
                    <h6 class="mb-0"><i class="fas fa-user mr-2"></i> Información del Jugador</h6>
                </div>
                <div class="card-body p-3">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <th width="40%" class="py-1">Nombre:</th>
                            <td class="py-1"><strong style="text-transform: uppercase;"><?php echo htmlspecialchars($inscrito['nombre_completo'] ?? $inscrito['nombre'] ?? 'N/A'); ?></strong></td>
                        </tr>
                        <tr>
                            <th class="py-1">Club:</th>
                            <td class="py-1"><?php echo htmlspecialchars($inscrito['nombre_club'] ?? 'Sin Club'); ?></td>
                        </tr>
                        <?php if ($esEqResumen): ?>
                        <tr>
                            <th class="py-1">Equipo:</th>
                            <td class="py-1"><?php echo htmlspecialchars(trim(($equipo['codigo_equipo'] ?? '') . ' ' . ($equipo['nombre_equipo'] ?? ''))); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <?php
            if ($esEqResumen) {
                $posCabecera = (int)($inscrito['clasiequi'] ?? 0);
                $gCabecera = (int)($equipo['ganados'] ?? 0);
                $pCabecera = (int)($equipo['perdidos'] ?? 0);
                $efCabecera = (int)($equipo['efectividad'] ?? 0);
                $ptsCabecera = (int)($equipo['puntos'] ?? 0);
                $sancCabecera = (int)($equipo['sancion'] ?? 0);
                $tituloStats = 'Estadísticas del equipo';
                $lblPos = 'Pos. equipo';
            } else {
                $posCabecera = (int)($inscrito['posicion'] ?? 0);
                $gCabecera = (int)($inscrito['ganados'] ?? 0);
                $pCabecera = (int)($inscrito['perdidos'] ?? 0);
                $efCabecera = (int)($inscrito['efectividad'] ?? 0);
                $ptsCabecera = (int)($inscrito['puntos'] ?? 0);
                $sancCabecera = (int)($inscrito['sancion'] ?? 0);
                $tituloStats = 'Estadísticas generales';
                $lblPos = 'Posición';
            }
            ?>
            <div class="card">
                <div class="card-header bg-success text-white py-2">
                    <h6 class="mb-0"><i class="fas fa-chart-line mr-2"></i> <?php echo htmlspecialchars($tituloStats); ?></h6>
                </div>
                <div class="card-body p-3">
                    <?php if ($esEqResumen): ?>
                    <p class="small text-muted mb-3">Totales agregados del equipo (mismos para todos los integrantes). El desglose por ronda es tu participación individual.</p>
                    <?php endif; ?>
                    <div class="row text-center">
                        <div class="col-2">
                            <div class="border rounded p-3 bg-light">
                                <h3 class="text-primary mb-0"><?php echo $posCabecera > 0 ? $posCabecera . '°' : '—'; ?></h3>
                                <small class="text-muted"><?php echo htmlspecialchars($lblPos); ?></small>
                            </div>
                        </div>
                        <div class="col-2">
                            <div class="border rounded p-3 bg-light">
                                <h3 class="text-success mb-0"><?php echo $gCabecera; ?></h3>
                                <small class="text-muted">Ganados</small>
                            </div>
                        </div>
                        <div class="col-2">
                            <div class="border rounded p-3 bg-light">
                                <h3 class="text-danger mb-0"><?php echo $pCabecera; ?></h3>
                                <small class="text-muted">Perdidos</small>
                            </div>
                        </div>
                        <div class="col-2">
                            <div class="border rounded p-3 bg-light">
                                <h3 class="text-info mb-0"><?php echo $efCabecera; ?></h3>
                                <small class="text-muted">Efectividad</small>
                            </div>
                        </div>
                        <div class="col-2">
                            <div class="border rounded p-3 bg-light">
                                <h3 class="text-primary mb-0"><?php echo $ptsCabecera; ?></h3>
                                <small class="text-muted">Puntos</small>
                            </div>
                        </div>
                        <div class="col-2">
                            <div class="border rounded p-3 bg-warning">
                                <h3 class="text-dark mb-0"><?php echo $sancCabecera; ?></h3>
                                <small class="text-muted">Sanciones</small>
                            </div>
                        </div>
                    </div>
                    <?php if ($esEqResumen): ?>
                    <p class="small text-muted mt-3 mb-0">
                        Registro individual en el torneo:
                        G <?php echo (int)($inscrito['ganados'] ?? 0); ?> ·
                        P <?php echo (int)($inscrito['perdidos'] ?? 0); ?> ·
                        pos. en listado general <?php echo (int)($inscrito['posicion'] ?? 0); ?>° ·
                        pts. ranking <?php echo (int)($inscrito['ptosrnk'] ?? 0); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Resumen de Participación -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list mr-2"></i> Resumen de Participación por Ronda</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($resumenParticipacion)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle mr-2"></i>
                            Este jugador aún no ha participado en ninguna ronda.
                        </div>
                    <?php else: ?>
                        <style>
                            .tabla-resumen th, .tabla-resumen td { padding: 0.35rem 0.5rem; vertical-align: middle; font-size: calc(0.9rem + 1px); font-weight: bold; }
                            .tabla-resumen .col-rnd { width: 3%; }
                            .tabla-resumen .col-mesa { width: 4%; }
                            .tabla-resumen .col-pareja { width: 20%; min-width: 120px; }
                            .tabla-resumen .col-contrarios { width: 22%; min-width: 130px; }
                            .tabla-resumen .col-r1, .tabla-resumen .col-r2 { width: 5%; }
                            .tabla-resumen .col-efect { width: 5%; }
                            .tabla-resumen .col-sanc, .tabla-resumen .col-tarj { width: 4%; }
                            .tabla-resumen .col-res { width: 7%; }
                            .tabla-resumen .col-accion { width: 8%; }
                            .tabla-resumen .nombre-compacto { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; display: inline-block; }
                        </style>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover tabla-resumen">
                                <thead class="thead-dark">
                                    <tr>
                                        <th class="col-rnd" title="Ronda">Rnd</th>
                                        <th class="col-mesa" title="Mesa">M</th>
                                        <th class="col-pareja" title="Compañero de pareja">Pareja</th>
                                        <th class="col-contrarios" title="Jugadores contrarios">Contrarios</th>
                                        <th class="col-r1" title="Puntos pareja">R1</th>
                                        <th class="col-r2" title="Puntos contrarios">R2</th>
                                        <th class="col-efect" title="Efectividad">Efect</th>
                                        <th class="col-sanc" title="Sanción">Sanc</th>
                                        <th class="col-tarj" title="Tarjeta">T</th>
                                        <th class="col-res" title="Resultado">Res</th>
                                        <th class="col-accion">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resumenParticipacion as $partida): ?>
                                        <tr>
                                            <td><?php echo $partida['partida']; ?></td>
                                            <td><?php echo $partida['mesa']; ?></td>
                                            <td class="col-pareja" style="background-color: #e0e0e0;">
                                                <?php 
                                                if (!empty($partida['compañero'])) {
                                                    echo '<span class="nombre-compacto" title="' . htmlspecialchars($partida['compañero']['nombre']) . '">' . htmlspecialchars($partida['compañero']['nombre']) . '</span>';
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="col-contrarios" style="background-color: #c0c0c0;">
                                                <?php 
                                                if (!empty($partida['contrarios'])) {
                                                    $lineas = [];
                                                    foreach ($partida['contrarios'] as $contrario) {
                                                        $lineas[] = htmlspecialchars($contrario['nombre']);
                                                    }
                                                    echo implode('<br>', $lineas);
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (isset($partida['resultado1']) && $partida['resultado1'] !== null) {
                                                    $resultado1 = (int)$partida['resultado1'];
                                                    $sancion = (int)($partida['sancion'] ?? 0);
                                                    if ($sancion > 0) {
                                                        // Mostrar resultado con sanción aplicada
                                                        $resultadoAjustado = max(0, $resultado1 - $sancion);
                                                        echo $resultado1 . ' <span class="text-danger" title="Sanción: -' . $sancion . ' puntos">(' . $resultadoAjustado . ')</span>';
                                                    } else {
                                                        echo $resultado1;
                                                    }
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (isset($partida['resultado2']) && $partida['resultado2'] !== null) {
                                                    echo (int)$partida['resultado2'];
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="<?php echo ((int)($partida['efectividad'] ?? 0)) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php 
                                                if (isset($partida['efectividad']) && $partida['efectividad'] !== null) {
                                                    echo (int)$partida['efectividad'];
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (isset($partida['sancion']) && $partida['sancion'] !== null) {
                                                    $sancion = (int)$partida['sancion'];
                                                    if ($sancion > 0) {
                                                        echo '<span class="badge badge-danger" style="color: red !important;">' . $sancion . '</span>';
                                                    } else {
                                                        echo '<span style="color: red;">0</span>';
                                                    }
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (isset($partida['tarjeta']) && $partida['tarjeta'] !== null) {
                                                    $tarjeta = (int)$partida['tarjeta'];
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
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (isset($partida['gano']) && $partida['gano'] !== null) {
                                                    if ($partida['gano']): ?>
                                                        <span style="color: blue; font-weight: bold;"><i class="fas fa-check mr-1"></i> Ganó</span>
                                                    <?php else: ?>
                                                        <span style="color: red; font-weight: bold;"><i class="fas fa-times mr-1"></i> Perdió</span>
                                                    <?php endif;
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                // Preservar el parámetro from original para regresar correctamente
                                                $from_original = isset($_GET['from']) ? $_GET['from'] : (isset($from) ? $from : 'panel');
                                                $from_param = !empty($from_original) && $from_original !== 'resumen' ? '&from_original=' . urlencode($from_original) : '';
                                                ?>
                                                <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=registrar_resultados&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $partida['partida']; ?>&mesa=<?php echo $partida['mesa']; ?>&from=resumen&inscrito_id=<?php echo $inscrito['id_usuario']; ?><?php echo $from_param; ?>" 
                                                   class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye mr-1"></i> Ver Mesa
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <th colspan="4">TOTALES</th>
                                        <th><?php echo $totales['resultado1']; ?></th>
                                        <th><?php echo $totales['resultado2']; ?></th>
                                        <th class="<?php echo ((int)$totales['efectividad']) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo (int)$totales['efectividad']; ?>
                                        </th>
                                        <th class="text-danger">
                                            <?php 
                                            $totalSancion = (int)($totales['sancion'] ?? 0);
                                            if ($totalSancion > 0) {
                                                echo '<span class="badge badge-danger" style="color: red !important;">' . $totalSancion . '</span>';
                                            } else {
                                                echo '<span style="color: red !important;">0</span>';
                                            }
                                            ?>
                                        </th>
                                        <th><span class="text-muted">-</span></th>
                                        <th>
                                            <span class="badge badge-success"><?php echo (int)$totales['ganados']; ?> G</span>
                                            <span class="badge badge-danger"><?php echo (int)$totales['perdidos']; ?> P</span>
                                        </th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

