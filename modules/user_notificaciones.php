<?php
/**
 * Mis notificaciones (campanita): listar notificaciones web pendientes y marcarlas como vistas.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/NotificationManager.php';
require_once __DIR__ . '/../lib/TournamentAppScope.php';

$user = Auth::user();
if (!$user) {
    header('Location: ' . (AppHelpers::url('login.php') ?? 'login.php'));
    exit;
}

$uid = Auth::id();
$pdo = DB::pdo();
$nm = new NotificationManager($pdo);

// Al abrir la página, marcar como vistas las pendientes (entregadas en web)
$nm->marcarWebVistas($uid);

$hasDatosJson = $pdo->query("SHOW COLUMNS FROM notifications_queue LIKE 'datos_json'")->rowCount() > 0;
$stmt = $pdo->prepare("
    SELECT id, mensaje, url_destino, fecha_creacion" . ($hasDatosJson ? ", datos_json" : "") . "
    FROM notifications_queue
    WHERE usuario_id = ? AND canal = 'web'
    ORDER BY fecha_creacion DESC
    LIMIT 100
");
$stmt->execute([$uid]);
$notificaciones = TournamentAppScope::filtrarFilasNotificaciones($stmt->fetchAll(PDO::FETCH_ASSOC));

// Verificar si el usuario tiene Telegram vinculado (para mostrar invitación)
$tiene_telegram = false;
$has_telegram_col = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
if ($has_telegram_col) {
    $tg = $pdo->prepare("SELECT telegram_chat_id FROM usuarios WHERE id = ?");
    $tg->execute([$uid]);
    $tiene_telegram = !empty(trim((string)($tg->fetchColumn() ?: '')));
}
$profile_url = AppHelpers::url('index.php', ['page' => 'users/profile']);
?>
<div class="fade-in">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-bell me-2"></i>Mis Notificaciones
            </h1>
            <p class="text-muted mb-0">Mensajes y avisos del sistema</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" id="btn-probar-notif" title="Ver tarjeta toast genérica y PDF">
                <i class="fas fa-flask me-1"></i>Probar notificación
            </button>
            <button type="button" class="btn btn-outline-primary" id="btn-probar-nueva-ronda" title="Ver tarjeta formateada Nueva Ronda">
                <i class="fas fa-trophy me-1"></i>Probar Nueva Ronda
            </button>
        </div>
    </div>
    <script>
    document.getElementById('btn-probar-notif') && document.getElementById('btn-probar-notif').addEventListener('click', function() {
        if (typeof enviarNotificacion === 'function') {
            enviarNotificacion('Pago Confirmado', 'Tu recibo #1234 ha sido generado exitosamente.', { showPdf: true });
        } else { alert('Recarga la página para probar el toast.'); }
    });
    document.getElementById('btn-probar-nueva-ronda') && document.getElementById('btn-probar-nueva-ronda').addEventListener('click', function() {
        if (typeof enviarNotificacion === 'function') {
            enviarNotificacion('', '', {
                datosEstructurados: {
                    tipo: 'nueva_ronda', ronda: '3', mesa: '5',
                    usuario_id: 135, nombre: 'Alberto López Garrido',
                    ganados: '2', perdidos: '1', efectividad: '66.7', puntos: '120',
                    url_resumen: '#', url_clasificacion: '#'
                }
            });
        } else { alert('Recarga la página para probar.'); }
    });
    </script>

    <?php if (!$tiene_telegram && $has_telegram_col): ?>
    <!-- Invitación especial: Vincular Telegram -->
    <div class="alert alert-info border-0 mb-4" style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);">
        <div class="d-flex align-items-start">
            <div class="me-3 fs-2"><i class="fab fa-telegram-plane text-primary"></i></div>
            <div class="flex-grow-1">
                <h5 class="alert-heading mb-2"><i class="fas fa-magic me-1"></i>¿Quieres recibir notificaciones en tu celular?</h5>
                <p class="mb-2">Vincula tu cuenta con Telegram y recibe al instante avisos de nuevas rondas, torneos y resultados, sin tener que entrar al panel.</p>
                <p class="mb-2"><strong>Es gratis, seguro y toma solo 2 minutos.</strong></p>
                <a href="<?= htmlspecialchars($profile_url) ?>#telegram" class="btn btn-primary">
                    <i class="fab fa-telegram-plane me-1"></i>Vincular Telegram ahora
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($notificaciones)): ?>
                <p class="text-muted mb-0">
                    <i class="fas fa-inbox me-2"></i>No tienes notificaciones.
                </p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($notificaciones as $n):
                        $datos = (!empty($n['datos_json']) ? @json_decode($n['datos_json'], true) : null);
                        $esNuevaRonda = $datos && isset($datos['tipo']) && $datos['tipo'] === 'nueva_ronda';
                        $esResultadosMesa = $datos && isset($datos['tipo']) && $datos['tipo'] === 'resultados_mesa';
                        $esInvitacionFormal = $datos && isset($datos['tipo']) && $datos['tipo'] === 'invitacion_torneo_formal';
                        $esInscripcionTorneo = $datos && isset($datos['tipo']) && $datos['tipo'] === 'inscripcion_torneo_confirmada';
                        $esPagoValidado = $datos && isset($datos['tipo']) && $datos['tipo'] === 'inscripcion_pago_validado';
                    ?>
                        <li class="list-group-item">
                            <?php if ($esInscripcionTorneo): ?>
                                <div class="notif-list-invitacion-formal">
                                    <div class="notif-list-invitacion-org text-center fw-bold text-success">✅ Inscripción exitosa</div>
                                    <div class="notif-list-invitacion-saludo text-center">Hola <?= htmlspecialchars($datos['nombre'] ?? '') ?></div>
                                    <div class="notif-list-invitacion-torneo text-center fw-bold"><?= htmlspecialchars($datos['torneo'] ?? '') ?></div>
                                    <div class="notif-list-invitacion-lugar text-center">
                                        <?= htmlspecialchars($datos['lugar_torneo'] ?? '') ?>
                                        <?php if (!empty($datos['fecha_torneo'])): ?> · <?= htmlspecialchars($datos['fecha_torneo']) ?><?php endif; ?>
                                    </div>
                                    <?php if (!empty($datos['asociacion'])): ?>
                                        <div class="notif-list-invitacion-lugar text-center">Asociación: <?= htmlspecialchars($datos['asociacion']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($datos['costo'])): ?>
                                        <div class="notif-list-invitacion-lugar text-center fw-bold">Costo: $<?= htmlspecialchars($datos['costo']) ?></div>
                                    <?php endif; ?>
                                    <div class="mt-2 text-center">
                                        <?php
                                        $urlPago = isset($datos['url_pago']) ? trim($datos['url_pago']) : ($n['url_destino'] ?? '#');
                                        $base = rtrim(AppHelpers::getBaseUrl(), '/');
                                        $uPago = ltrim($urlPago, '/');
                                        $hrefPago = ($urlPago !== '' && $urlPago !== '#') ? (strpos($urlPago, 'http') === 0 ? $urlPago : $base . (strpos($uPago, 'public/') === 0 ? '/' : '/public/') . $uPago) : '';
                                        if ($hrefPago !== ''): ?>
                                            <a href="<?= htmlspecialchars($hrefPago) ?>" class="btn btn-sm btn-primary"><i class="fas fa-credit-card me-1"></i>Reportar pago</a>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted d-block mt-2 text-center"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                </div>
                            <?php elseif ($esPagoValidado): ?>
                                <div class="notif-list-invitacion-formal">
                                    <div class="notif-list-invitacion-org text-center fw-bold text-success">✅ Pago validado</div>
                                    <div class="notif-list-invitacion-saludo text-center">Hola <?= htmlspecialchars($datos['nombre'] ?? '') ?></div>
                                    <div class="notif-list-invitacion-torneo text-center fw-bold"><?= htmlspecialchars($datos['torneo'] ?? '') ?></div>
                                    <div class="notif-list-invitacion-lugar text-center">
                                        <?= htmlspecialchars($datos['lugar_torneo'] ?? '') ?>
                                        <?php if (!empty($datos['fecha_torneo'])): ?> · <?= htmlspecialchars($datos['fecha_torneo']) ?><?php endif; ?>
                                    </div>
                                    <?php if (!empty($datos['asociacion'])): ?>
                                        <div class="notif-list-invitacion-lugar text-center">Asociación: <?= htmlspecialchars($datos['asociacion']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($datos['monto'])): ?>
                                        <div class="notif-list-invitacion-lugar text-center fw-bold text-success">Monto: $<?= htmlspecialchars($datos['monto']) ?></div>
                                    <?php endif; ?>
                                    <p class="text-center small mb-0 mt-2">Su inscripción quedó <strong>confirmada</strong>.</p>
                                    <small class="text-muted d-block mt-2 text-center"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                </div>
                            <?php elseif ($esResultadosMesa): ?>
                                <div class="notif-list-nueva-ronda">
                                    <div class="notif-list-ronda text-center fw-bold text-primary">RESULTADOS RONDA <?= htmlspecialchars($datos['ronda'] ?? '—') ?> · MESA <?= htmlspecialchars($datos['mesa'] ?? '—') ?></div>
                                    <div class="notif-list-atleta text-center">Atleta: <?= (int)($datos['usuario_id'] ?? 0) ?> <?= htmlspecialchars($datos['nombre'] ?? '') ?></div>
                                    <div class="notif-list-mesa text-center">Usted ha <?= htmlspecialchars($datos['resultado_texto'] ?? '—') ?>.</div>
                                    <div class="notif-list-pareja text-center">Resultado: <?= htmlspecialchars($datos['resultado1'] ?? '0') ?> a <?= htmlspecialchars($datos['resultado2'] ?? '0') ?></div>
                                    <?php if (!empty($datos['sancion']) && (int)$datos['sancion'] > 0 || !empty($datos['tarjeta_texto'])): ?>
                                        <div class="notif-list-pareja text-center text-warning">
                                            <?php
                                            $partes = [];
                                            if (!empty($datos['sancion']) && (int)$datos['sancion'] > 0) $partes[] = 'Sancionado con ' . (int)$datos['sancion'] . ' pts';
                                            if (!empty($datos['tarjeta_texto'])) $partes[] = 'Tarjeta ' . htmlspecialchars($datos['tarjeta_texto']);
                                            echo implode(' y ', $partes);
                                            ?>.
                                        </div>
                                    <?php endif; ?>
                                    <div class="notif-list-pareja text-center small text-muted">Si no está conforme notifique a mesa técnica.</div>
                                    <div class="mt-2 text-center">
                                        <?php
                                        $urlRes = isset($datos['url_resumen']) ? trim($datos['url_resumen']) : '';
                                        $urlCla = isset($datos['url_clasificacion']) ? trim($datos['url_clasificacion']) : '';
                                        $base = rtrim(AppHelpers::getBaseUrl(), '/');
                                        $uRes = ltrim($urlRes, '/');
                                        $uCla = ltrim($urlCla, '/');
                                        $hrefRes = ($urlRes !== '' && $urlRes !== '#') ? (strpos($urlRes, 'http') === 0 ? $urlRes : $base . (strpos($uRes, 'public/') === 0 ? '/' : '/public/') . $uRes) : '';
                                        $hrefCla = ($urlCla !== '' && $urlCla !== '#') ? (strpos($urlCla, 'http') === 0 ? $urlCla : $base . (strpos($uCla, 'public/') === 0 ? '/' : '/public/') . $uCla) : '';
                                        if ($hrefRes !== ''): ?>
                                            <a href="<?= htmlspecialchars($hrefRes) ?>" class="btn btn-sm btn-primary me-1"><i class="fas fa-user-chart me-1"></i>Resumen jugador</a>
                                        <?php endif;
                                        if ($hrefCla !== ''): ?>
                                            <a href="<?= htmlspecialchars($hrefCla) ?>" class="btn btn-sm btn-secondary"><i class="fas fa-list-ol me-1"></i>Listado de clasificación</a>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted d-block mt-2 text-center"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                </div>
                            <?php elseif ($esInvitacionFormal): ?>
                                <div class="notif-list-invitacion-formal">
                                    <div class="notif-list-invitacion-org text-center fw-bold text-primary"><?= htmlspecialchars($datos['organizacion_nombre'] ?? 'Invitación a Torneo') ?></div>
                                    <div class="notif-list-invitacion-saludo text-center"><?= htmlspecialchars($datos['tratamiento'] ?? 'Estimado/a') ?> <?= htmlspecialchars($datos['nombre'] ?? '') ?></div>
                                    <div class="notif-list-invitacion-torneo text-center fw-bold"><?= htmlspecialchars($datos['torneo'] ?? '') ?></div>
                                    <div class="notif-list-invitacion-lugar text-center"><?= htmlspecialchars($datos['lugar_torneo'] ?? '') ?> · <?= htmlspecialchars($datos['fecha_torneo'] ?? '') ?></div>
                                    <div class="mt-2 text-center">
                                        <?php
                                        $urlInsc = isset($datos['url_inscripcion']) ? trim($datos['url_inscripcion']) : ($n['url_destino'] ?? '#');
                                        $base = rtrim(AppHelpers::getBaseUrl(), '/');
                                        $uInsc = ltrim($urlInsc, '/');
                                        $hrefInsc = ($urlInsc !== '' && $urlInsc !== '#') ? (strpos($urlInsc, 'http') === 0 ? $urlInsc : $base . (strpos($uInsc, 'public/') === 0 ? '/' : '/public/') . $uInsc) : '';
                                        if ($hrefInsc !== ''): ?>
                                            <a href="<?= htmlspecialchars($hrefInsc) ?>" class="btn btn-sm btn-primary"><i class="fas fa-pen-fancy me-1"></i>Inscribirse en línea</a>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted d-block mt-2 text-center"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                </div>
                            <?php elseif ($esNuevaRonda): ?>
                                <div class="notif-list-nueva-ronda">
                                    <div class="notif-list-ronda text-center fw-bold text-primary">RONDA <?= htmlspecialchars($datos['ronda'] ?? '—') ?></div>
                                    <div class="notif-list-atleta text-center">Atleta: <?= (int)($datos['usuario_id'] ?? 0) ?> <?= htmlspecialchars($datos['nombre'] ?? '') ?></div>
                                    <div class="notif-list-mesa text-center">Juega en Mesa: <?= htmlspecialchars($datos['mesa'] ?? '—') ?></div>
                                    <div class="notif-list-pareja text-center" title="Compañero de juego del atleta, inscrito con el mismo número de mesa y letra.">Pareja: <?= (int)($datos['pareja_id'] ?? 0) ?> <?= htmlspecialchars($datos['pareja_nombre'] ?? $datos['pareja'] ?? '—') ?></div>
                                    <div class="notif-list-stats-grid text-center text-muted small">
                                        <span class="notif-stats-label fw-bold">Pos.</span>
                                        <span class="notif-stats-label fw-bold">Gana</span>
                                        <span class="notif-stats-label fw-bold">Perdi</span>
                                        <span class="notif-stats-label fw-bold">Efect</span>
                                        <span class="notif-stats-label fw-bold">Ptos</span>
                                        <span class="notif-stats-value"><?= htmlspecialchars($datos['posicion'] ?? '0') ?></span>
                                        <span class="notif-stats-value"><?= htmlspecialchars($datos['ganados'] ?? '0') ?></span>
                                        <span class="notif-stats-value"><?= htmlspecialchars($datos['perdidos'] ?? '0') ?></span>
                                        <span class="notif-stats-value"><?= htmlspecialchars($datos['efectividad'] ?? '0') ?></span>
                                        <span class="notif-stats-value"><?= htmlspecialchars($datos['puntos'] ?? '0') ?></span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <?php
                                        $urlRes = isset($datos['url_resumen']) ? trim($datos['url_resumen']) : '';
                                        $urlCla = isset($datos['url_clasificacion']) ? trim($datos['url_clasificacion']) : '';
                                        $base = rtrim(AppHelpers::getBaseUrl(), '/');
                                        $uRes = ltrim($urlRes, '/');
                                        $uCla = ltrim($urlCla, '/');
                                        $hrefRes = ($urlRes !== '' && $urlRes !== '#') ? (strpos($urlRes, 'http') === 0 ? $urlRes : $base . (strpos($uRes, 'public/') === 0 ? '/' : '/public/') . $uRes) : '';
                                        $hrefCla = ($urlCla !== '' && $urlCla !== '#') ? (strpos($urlCla, 'http') === 0 ? $urlCla : $base . (strpos($uCla, 'public/') === 0 ? '/' : '/public/') . $uCla) : '';
                                        if ($hrefRes !== ''): ?>
                                            <a href="<?= htmlspecialchars($hrefRes) ?>" class="btn btn-sm btn-primary me-1"><i class="fas fa-user-chart me-1"></i>Resumen jugador</a>
                                        <?php endif;
                                        if ($hrefCla !== ''): ?>
                                            <a href="<?= htmlspecialchars($hrefCla) ?>" class="btn btn-sm btn-secondary"><i class="fas fa-list-ol me-1"></i>Listado de clasificación</a>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted d-block mt-2 text-center"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                </div>
                            <?php else: ?>
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="ms-2 me-auto">
                                        <div class="fw-normal"><?= htmlspecialchars($n['mensaje']) ?></div>
                                        <small class="text-muted"><?= date('d/m/Y H:i', strtotime($n['fecha_creacion'])) ?></small>
                                    </div>
                                    <?php if (!empty($n['url_destino']) && $n['url_destino'] !== '#'): ?>
                                        <a href="<?= htmlspecialchars($n['url_destino']) ?>" class="btn btn-sm btn-outline-primary">Ver</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
