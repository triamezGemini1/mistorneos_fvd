<?php
/**
 * Portal exclusivo: descarga Excel para Microsoft Access (inscritos + partidas).
 *
 * @var array $torneo
 * @var int $torneo_id
 * @var int $n_inscritos
 * @var int $n_partidas
 */
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}

$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$action_param = $use_standalone ? '?' : '&';

$torneo_id = (int) ($torneo_id ?? ($torneo['id'] ?? 0));
$n_inscritos = (int) ($n_inscritos ?? 0);
$n_partidas = (int) ($n_partidas ?? 0);
$torneoNombre = (string) ($torneo['nombre'] ?? 'Torneo');

$urlPanel = $base_url . $action_param . 'action=panel&torneo_id=' . $torneo_id;
$urlInscritos = $base_url . $action_param . 'action=export_access_excel&torneo_id=' . $torneo_id . '&tipo=inscritos';
$urlPartidas = $base_url . $action_param . 'action=export_access_excel&torneo_id=' . $torneo_id . '&tipo=partidas';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(AppHelpers::url('assets/dist/output.css'), ENT_QUOTES, 'UTF-8'); ?>">

<div class="max-w-3xl mx-auto px-4 py-8">
    <nav class="text-sm text-slate-500 mb-4" aria-label="breadcrumb">
        <a href="<?php echo htmlspecialchars($urlPanel, ENT_QUOTES, 'UTF-8'); ?>" class="text-emerald-700 hover:underline">
            <i class="fas fa-arrow-left mr-1"></i> Volver al panel
        </a>
    </nav>

    <header class="mb-8 text-center">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-100 text-emerald-700 mb-3">
            <i class="fas fa-database text-2xl"></i>
        </div>
        <h1 class="text-2xl font-bold text-slate-800">Exportación para Microsoft Access</h1>
        <p class="text-slate-600 mt-2"><?php echo htmlspecialchars($torneoNombre); ?> · Torneo #<?php echo $torneo_id; ?></p>
        <p class="text-xs text-slate-500 mt-1">Descarga exclusiva desde Gestión de Mesas</p>
    </header>

    <div class="grid gap-6 md:grid-cols-2">
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="bg-gradient-to-r from-blue-600 to-blue-500 px-4 py-3 text-white">
                <h2 class="text-lg font-semibold mb-0 flex items-center gap-2">
                    <i class="fas fa-users"></i> inscritos para access
                </h2>
            </div>
            <div class="p-5 space-y-4">
                <p class="text-sm text-slate-600">
                    Listado de jugadores inscritos del torneo activo, listo para importar en Access.
                </p>
                <ul class="text-xs text-slate-500 space-y-1 list-disc list-inside">
                    <li>asociacion (código), torneo, equipo</li>
                    <li>cedula, nombre, numfvd</li>
                    <li>sexo, telefono, email</li>
                </ul>
                <p class="text-sm font-medium text-slate-700">
                    <?php echo number_format($n_inscritos, 0, ',', '.'); ?> registro(s)
                </p>
                <a href="<?php echo htmlspecialchars($urlInscritos, ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition-colors">
                    <i class="fas fa-file-excel"></i> Descargar inscritos para access.xls
                </a>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="bg-gradient-to-r from-teal-600 to-emerald-500 px-4 py-3 text-white">
                <h2 class="text-lg font-semibold mb-0 flex items-center gap-2">
                    <i class="fas fa-table"></i> partidas para access
                </h2>
            </div>
            <div class="p-5 space-y-4">
                <p class="text-sm text-slate-600">
                    Resultados de <strong>partiresul</strong> del torneo (todas las rondas registradas).
                </p>
                <ul class="text-xs text-slate-500 space-y-1 list-disc list-inside">
                    <li>indi (=1 si secuencia=1), torneo, partida, mesa, secuencia</li>
                    <li>pareja, ff, sancion, result1, result2</li>
                    <li>sancion p, efectividad, act, ganado, perdido</li>
                </ul>
                <p class="text-sm font-medium text-slate-700">
                    <?php echo number_format($n_partidas, 0, ',', '.'); ?> fila(s) en partiresul
                </p>
                <?php if ($n_partidas === 0): ?>
                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                        Aún no hay partidas en partiresul para este torneo. El archivo se generará con la cabecera.
                    </p>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($urlPartidas, ENT_QUOTES, 'UTF-8'); ?>"
                   class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-teal-600 px-4 py-3 text-sm font-semibold text-white hover:bg-teal-700 transition-colors">
                    <i class="fas fa-file-excel"></i> Descargar partidas para access.xls
                </a>
            </div>
        </section>
    </div>
</div>
