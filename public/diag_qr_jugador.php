<?php
declare(strict_types=1);
/**
 * Diagnóstico paso a paso del QR jugador. Eliminar tras resolver.
 * URL: diag_qr_jugador.php?t=TOKEN
 */
header('Content-Type: application/json; charset=utf-8');

$token = trim((string) ($_GET['t'] ?? ''));
$out = ['ok' => false, 'token_presente' => $token !== '', 'pasos' => []];

$log = static function (string $paso, $detalle = null) use (&$out): void {
    $row = ['paso' => $paso];
    if ($detalle !== null) {
        $row['detalle'] = $detalle;
    }
    $out['pasos'][] = $row;
};

try {
    $log('php', PHP_VERSION);
    require_once __DIR__ . '/../config/bootstrap.php';
    $log('bootstrap', 'ok');
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/TorneoJugadorQrToken.php';
    require_once __DIR__ . '/../lib/PartiresulJugadorHelper.php';
    require_once __DIR__ . '/../lib/NumfvdHelper.php';
    require_once __DIR__ . '/../lib/PublicInfoTorneoMesasService.php';
    require_once __DIR__ . '/../lib/PublicTorneoPortalHelper.php';
    require_once __DIR__ . '/../lib/TorneoQrJugadorMesaPartial.php';

    if ($token === '') {
        $out['error'] = 'Falta parámetro t';
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $decoded = TorneoJugadorQrToken::decode($token);
    $log('decode', $decoded);
    if ($decoded === null) {
        $out['error'] = 'Token inválido (APP_KEY o firma)';
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $pdo = DB::pdo();
    $log('db', 'conectado');
    PartiresulJugadorHelper::refrescarEsquemaPartiresul($pdo);
    $log('esquema_partiresul', [
        'numfvd' => PartiresulJugadorHelper::tieneColumnaNumfvd($pdo),
        'id_usuario' => PartiresulJugadorHelper::tieneColumnaIdUsuario($pdo),
        'solo_numfvd' => PartiresulJugadorHelper::soloNumfvdEnPartiresul($pdo),
        'inscritos_numfvd' => NumfvdHelper::inscritosTieneColumnaNumfvd($pdo),
        'env_solo' => getenv('FVD_PARTIRESUL_SOLO_NUMFVD') ?: '(vacío)',
    ]);

    $torneoId = (int) $decoded['torneo_id'];
    $ident = (int) $decoded['id_usuario'];
    $uid = PublicInfoTorneoMesasService::resolverIdInscritoQr($pdo, $torneoId, $ident);
    $log('resolver_inscrito', ['entrada' => $ident, 'id_usuario' => $uid]);
    if ($uid === null) {
        $out['error'] = 'Jugador no inscrito activo';
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $torneo = PublicTorneoPortalHelper::getTorneoParaQrJugador($pdo, $torneoId);
    $log('torneo', $torneo ? ['id' => $torneo['id'], 'nombre' => $torneo['nombre'] ?? ''] : null);
    if (!$torneo) {
        $out['error'] = 'Torneo no activo para QR';
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $ronda = PublicInfoTorneoMesasService::ultimaRondaConPartidas($pdo, $torneoId);
    $claves = PartiresulJugadorHelper::clavesBusqueda($pdo, $torneoId, $uid);
    $log('claves_partiresul', $claves);

    $mesa = PublicInfoTorneoMesasService::mesaDelJugador($pdo, $torneoId, $ronda, $uid);
    $log('mesa', $mesa);

    $asignacion = PublicInfoTorneoMesasService::resumenAsignacion($pdo, $torneoId, $ronda, $uid);
    $log('resumen_asignacion', [
        'tipo' => $asignacion['tipo'] ?? null,
        'mesa' => $asignacion['mesa'] ?? null,
        'jugadores' => isset($asignacion['jugadores']) ? count($asignacion['jugadores']) : 0,
    ]);

    $resumen = PublicTorneoPortalHelper::fetchResumenParticipacion($pdo, $torneoId, $uid);
    $log('fetch_resumen', [
        'nombre' => $resumen['jugador']['nombre'] ?? '',
        'numfvd' => $resumen['jugador']['numfvd'] ?? 0,
        'posicion' => $resumen['posicion'] ?? 0,
    ]);

    $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
    $out['archivo'] = $e->getFile();
    $out['linea'] = $e->getLine();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
