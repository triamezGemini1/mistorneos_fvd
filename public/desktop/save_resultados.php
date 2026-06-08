<?php
/**
 * Guardar resultados de mesa (Desktop) — Pasos 6 y 7 del ciclo.
 *
 * Flujo crítico:
 * 1. Recepción de datos: Puntos (resultado1/2), Sanciones, Tarjetas, Faltas (ff), Observaciones.
 * 2. Transacción SQLite: todo o nada; rollback si algo falla.
 * 3. Estatus: marca la mesa como completada (registrado=1) para que el contador del Panel baje.
 * 4. Ejecución del core: actualizarEstadisticasInscritos() sincroniza stats; la posición oficial se cierra al generar la siguiente ronda.
 *    Esto debe ejecutarse antes de permitir el Paso 8 (Generar Ronda X+1).
 * 5. Redirección: panel_torneo.php?torneo_id=X&msg=resultados_guardados
 */
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/desktop_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['guardar_resultados'])) {
    $tid = (int)($_POST['torneo_id'] ?? 0);
    ob_end_clean();
    header('Location: ' . ($tid > 0 ? 'panel_torneo.php?torneo_id=' . $tid : 'torneos.php'));
    exit;
}

require_once __DIR__ . '/../../desktop/core/db_bridge.php';
require_once __DIR__ . '/../../desktop/core/logica_torneo.php';
require_once __DIR__ . '/../../lib/TorneoCampoNumerico.php';

$torneo_id = (int)($_POST['torneo_id'] ?? 0);
$partida = (int)($_POST['partida'] ?? 0);
$mesa = (int)($_POST['mesa'] ?? 0);
$formato = trim((string)($_POST['formato'] ?? ''));
$jugadores = is_array($_POST['jugadores'] ?? null) ? array_values($_POST['jugadores']) : [];
$resultados = is_array($_POST['resultados'] ?? null) ? $_POST['resultados'] : [];

// Solo se usan valores que el operador envía: resultado1, resultado2, sancion, ff, tarjeta, chancleta, zapato, observaciones.
// Los checkboxes no enviados (falta desmarcada) se interpretan como 0.
$usarJugadores = ($formato === 'jugadores' && count($jugadores) === 4);
if ($torneo_id <= 0 || $partida <= 0 || $mesa <= 0) {
    ob_end_clean();
    header('Location: ' . ($torneo_id > 0 ? 'panel_torneo.php?torneo_id=' . $torneo_id : 'torneos.php'));
    exit;
}
if (!$usarJugadores && empty($resultados)) {
    ob_end_clean();
    header('Location: captura.php?torneo_id=' . $torneo_id . '&partida=' . $partida . '&mesa=' . $mesa . '&error=' . urlencode('Datos incompletos'));
    exit;
}

$redirectCaptura = 'captura.php?torneo_id=' . $torneo_id . '&partida=' . $partida . '&mesa=' . $mesa;
$redirectError = $usarJugadores ? $redirectCaptura : 'resultados.php?torneo_id=' . $torneo_id . '&partida=' . $partida . '&mesa=' . $mesa;

$pdo = null;
try {
    $pdo = DB::pdo();
    $pdo->beginTransaction();

    if ($usarJugadores) {
        $observaciones = trim((string)($_POST['observaciones'] ?? ''));
        $st = $pdo->prepare("SELECT COALESCE(puntos, 200) AS puntos FROM tournaments WHERE id = ?");
        $st->execute([$torneo_id]);
        $torneoRow = $st->fetch(PDO::FETCH_ASSOC);
        $puntosTorneo = (int)($torneoRow['puntos'] ?? 200);

        foreach ($jugadores as $jugador) {
            $id = (int)($jugador['id'] ?? 0);
            if ($id <= 0) continue;
            $resultado1 = TorneoCampoNumerico::intEstadistica($jugador['resultado1'] ?? 0);
            $resultado2 = TorneoCampoNumerico::intEstadistica($jugador['resultado2'] ?? 0);
            $sancion = min(80, max(0, TorneoCampoNumerico::intEstadistica($jugador['sancion'] ?? 0)));
            $ff = isset($jugador['ff']) && ($jugador['ff'] === '1' || $jugador['ff'] === true || $jugador['ff'] === 'on') ? 1 : 0;
            $tarjeta = TorneoCampoNumerico::codigoTarjeta($jugador['tarjeta'] ?? 0);
            $chancleta = TorneoCampoNumerico::intEstadistica($jugador['chancleta'] ?? 0);
            $zapato = TorneoCampoNumerico::intEstadistica($jugador['zapato'] ?? 0);

            if ($ff === 1 || $tarjeta === 3 || $tarjeta === 4) {
                $efectividad = -$puntosTorneo;
            } else {
                $efectividad = $resultado1 - $resultado2;
            }

            $stmt = $pdo->prepare("
                UPDATE partiresul SET resultado1 = ?, resultado2 = ?, efectividad = ?, ff = ?,
                    tarjeta = ?, sancion = ?, chancleta = ?, zapato = ?, registrado = 1
                WHERE id = ? AND id_torneo = ?
            ");
            $stmt->execute([$resultado1, $resultado2, $efectividad, $ff, $tarjeta, $sancion, $chancleta, $zapato, $id, $torneo_id]);
        }

        if ($observaciones !== '') {
            $stObs = $pdo->prepare("UPDATE partiresul SET observaciones = ? WHERE id_torneo = ? AND partida = ? AND mesa = ?");
            $stObs->execute([$observaciones, $torneo_id, $partida, $mesa]);
        }
    } else {
        foreach ($resultados as $partiresul_id => $resultado) {
            $partiresul_id = (int)$partiresul_id;
            if ($partiresul_id <= 0) continue;

            $puntos1 = TorneoCampoNumerico::intEstadistica($resultado['resultado1'] ?? 0);
            $puntos2 = TorneoCampoNumerico::intEstadistica($resultado['resultado2'] ?? 0);
            $efectividad = TorneoCampoNumerico::intEstadistica($resultado['efectividad'] ?? 0);
            $ff = isset($resultado['ff']) ? 1 : 0;
            $tarjeta = TorneoCampoNumerico::codigoTarjeta($resultado['tarjeta'] ?? 0);
            $sancion = min(80, max(0, TorneoCampoNumerico::intEstadistica($resultado['sancion'] ?? 0)));
            $chancleta = TorneoCampoNumerico::intEstadistica($resultado['chancleta'] ?? 0);
            $zapato = TorneoCampoNumerico::intEstadistica($resultado['zapato'] ?? 0);
            $observaciones = trim((string)($resultado['observaciones'] ?? ''));
            $registrado = isset($resultado['registrado']) ? 1 : 0;

            $stmt = $pdo->prepare("
                UPDATE partiresul SET resultado1 = ?, resultado2 = ?, efectividad = ?, ff = ?,
                    tarjeta = ?, sancion = ?, chancleta = ?, zapato = ?,
                    observaciones = ?, registrado = ?
                WHERE id = ? AND id_torneo = ?
            ");
            $stmt->execute([
                $puntos1, $puntos2, $efectividad, $ff,
                $tarjeta, $sancion, $chancleta, $zapato,
                $observaciones, $registrado,
                $partiresul_id, $torneo_id
            ]);
        }

        $stmtMesa = $pdo->prepare("UPDATE partiresul SET registrado = 1 WHERE id_torneo = ? AND partida = ? AND mesa = ?");
        $stmtMesa->execute([$torneo_id, $partida, $mesa]);
    }

    actualizarEstadisticasInscritos($torneo_id);
    reclasificarSiUltimaRondaTorneoCompleta($torneo_id);
    $pdo->commit();

    ob_end_clean();
    if ($usarJugadores) {
        header('Location: ' . $redirectCaptura . '&msg=resultados_guardados');
    } else {
        header('Location: panel_torneo.php?torneo_id=' . $torneo_id . '&msg=resultados_guardados');
    }
    exit;
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    header('Location: ' . $redirectError . '&error=' . urlencode($e->getMessage()));
    exit;
}
