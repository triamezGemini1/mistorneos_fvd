<?php
/**
 * Vista: Mesas de una Ronda — dashboard compacto / inmersivo (14" + móvil)
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$action_param = $use_standalone ? '?' : '&';

$mesas_render_jugador = static function (array $jugador, bool $es_modalidad_equipos): string {
    $ag = !empty($jugador['alerta_genero']);
    $nombre = htmlspecialchars($jugador['nombre'] ?? $jugador['nombre_completo'] ?? 'Sin nombre');
    $club = !empty($jugador['club_nombre']) ? '<span class="text-slate-400">(' . htmlspecialchars($jugador['club_nombre']) . ')</span>' : '';
    $equipo = ($es_modalidad_equipos && !empty($jugador['codigo_equipo_inscrito']))
        ? '<span class="text-primary-600 font-semibold">[' . htmlspecialchars($jugador['codigo_equipo_inscrito']) . ']</span> '
        : '';
    $alerta = $ag
        ? '<span class="inline-flex items-center rounded px-1 py-0 text-[10px] bg-amber-100 text-amber-800 mr-1" title="Revisar género en registro"><i class="fas fa-venus-mars"></i></span>'
        : '';
    $wrap = $ag ? 'rounded bg-amber-50/90 border-l-2 border-amber-400 pl-1.5 pr-1 py-0.5' : '';
    return '<li class="leading-snug truncate ' . $wrap . '" title="' . $nombre . '">' . $alerta . $equipo . $nombre . ' ' . $club . '</li>';
};
?>

<div class="w-full max-w-full text-sm fvd-mesas-dashboard px-0">
    <header class="mb-3 md:mb-4">
        <h1 class="font-display text-lg md:text-xl font-semibold text-slate-800 flex flex-wrap items-center gap-2 mb-1">
            <i class="fas fa-chess-board text-primary-500"></i>
            <span>Mesas · Ronda <?php echo (int) $ronda; ?></span>
            <span class="text-slate-500 font-normal text-sm truncate max-w-full"><?php echo htmlspecialchars($torneo['nombre']); ?></span>
        </h1>
        <p class="text-xs text-slate-500 mb-2">Asignaciones de la ronda. El scroll es interno al panel; menú y barra superior permanecen visibles.</p>
        <nav aria-label="breadcrumb" class="text-xs">
            <ol class="flex flex-wrap items-center gap-1 text-slate-500 list-none p-0 m-0">
                <li><a href="<?php echo $base_url; ?>" class="text-primary-600 hover:underline">Torneos</a></li>
                <li class="text-slate-300">/</li>
                <li><a href="<?php echo $base_url . $action_param; ?>action=panel&torneo_id=<?php echo (int) $torneo['id']; ?>" class="text-primary-600 hover:underline truncate max-w-[12rem] inline-block align-bottom"><?php echo htmlspecialchars($torneo['nombre']); ?></a></li>
                <li class="text-slate-300">/</li>
                <li class="text-slate-700 font-medium">Ronda <?php echo (int) $ronda; ?></li>
            </ol>
        </nav>
    </header>

    <div class="flex flex-wrap items-center gap-2 mb-3">
        <a href="<?php echo $base_url . $action_param; ?>action=panel&torneo_id=<?php echo (int) $torneo['id']; ?>"
           class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
            <i class="fas fa-arrow-left"></i> Panel
        </a>
        <a href="<?php echo $base_url . $action_param; ?>action=rondas&torneo_id=<?php echo (int) $torneo['id']; ?>"
           class="inline-flex items-center gap-1.5 rounded-md bg-primary-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-600">
            <i class="fas fa-layer-group"></i> Rondas
        </a>
    </div>

    <?php if (!empty($es_operador_ambito) && !empty($mesas)): ?>
    <div class="rounded-md border border-sky-200 bg-sky-50 text-sky-900 px-3 py-2 mb-3 text-xs flex items-start gap-2">
        <i class="fas fa-user-cog mt-0.5 shrink-0"></i>
        <span><strong>Su ámbito:</strong> <?php echo count($mesas); ?> mesa(s) asignada(s) en esta ronda.</span>
    </div>
    <?php endif; ?>

    <?php if (empty($mesas)): ?>
        <div class="rounded-md border px-3 py-3 text-xs <?php echo !empty($es_operador_ambito) ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-slate-200 bg-slate-50 text-slate-700'; ?>">
            <i class="fas fa-info-circle mr-1"></i>
            <?php if (!empty($es_operador_ambito)): ?>
                No tiene mesas asignadas para esta ronda. Contacte al administrador del torneo.
            <?php else: ?>
                No hay mesas asignadas para esta ronda aún.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php
        $mesas_normales = [];
        $mesas_bye = [];
        foreach ($mesas as $mesa_data) {
            if (isset($mesa_data['numero']) && $mesa_data['numero'] !== 'BYE') {
                $mesas_normales[] = $mesa_data;
            } elseif (isset($mesa_data['BYE'])) {
                $mesas_bye = $mesa_data['BYE'];
            }
        }
        usort($mesas_normales, static function ($a, $b) {
            return ($a['numero'] ?? 0) <=> ($b['numero'] ?? 0);
        });
        $es_modalidad_equipos_mesas = (int) ($torneo['modalidad'] ?? 0) === 3;
        ?>

        <?php if (!empty($mesas_normales)): ?>
        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 mb-3 flex flex-wrap items-center gap-2">
            <label for="ir-a-mesa-select" class="text-xs font-semibold text-slate-600 shrink-0">Ir a mesa</label>
            <select id="ir-a-mesa-select" class="form-select form-select-sm w-auto max-w-full py-1.5 text-sm" onchange="irAMesa(this.value)">
                <option value="">— Todas (<?php echo count($mesas_normales); ?>) —</option>
                <?php foreach ($mesas_normales as $md): $n = (int) ($md['numero'] ?? 0); if ($n <= 0) continue; ?>
                <option value="mesa-<?php echo $n; ?>">Mesa <?php echo $n; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3 w-full">
            <?php foreach ($mesas_normales as $mesa_data): ?>
                <?php
                $num_mesa = (int) ($mesa_data['numero'] ?? 0);
                $jugadores = $mesa_data['jugadores'] ?? [];
                $pareja_a = array_filter($jugadores, static function ($j) {
                    return is_array($j) && isset($j['secuencia']) && in_array((int) $j['secuencia'], [1, 2], true);
                });
                $pareja_b = array_filter($jugadores, static function ($j) {
                    return is_array($j) && isset($j['secuencia']) && in_array((int) $j['secuencia'], [3, 4], true);
                });
                $tiene_resultados = false;
                $resultado1 = 0;
                $resultado2 = 0;
                foreach ($jugadores as $j) {
                    if (is_array($j) && (!empty($j['resultado1']) || !empty($j['resultado2']))) {
                        $tiene_resultados = true;
                        $primer = reset($jugadores);
                        if (is_array($primer)) {
                            $resultado1 = (int) ($primer['resultado1'] ?? 0);
                            $resultado2 = (int) ($primer['resultado2'] ?? 0);
                        }
                        break;
                    }
                }
                $mesa_chancleta = false;
                $mesa_zapato = false;
                foreach ($jugadores as $j) {
                    if (!is_array($j)) {
                        continue;
                    }
                    if (!empty($j['chancleta']) && (int) $j['chancleta'] > 0) {
                        $mesa_chancleta = true;
                    }
                    if (!empty($j['zapato']) && (int) $j['zapato'] > 0) {
                        $mesa_zapato = true;
                    }
                }
                ?>
                <article id="mesa-<?php echo $num_mesa; ?>" class="relative flex flex-col rounded-lg border border-slate-200 bg-white shadow-sm hover:shadow-md hover:border-primary-200 transition-shadow min-h-[11rem]">
                    <?php if ($mesa_chancleta || $mesa_zapato): ?>
                    <div class="absolute top-1.5 right-1.5 flex flex-col gap-0.5 z-10">
                        <?php if ($mesa_chancleta): ?>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-rose-100 text-rose-800 border border-rose-200" title="Chancleta">🥿</span>
                        <?php endif; ?>
                        <?php if ($mesa_zapato): ?>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-violet-100 text-violet-800 border border-violet-200" title="Zapato">👞</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <header class="flex items-center justify-between px-2.5 py-1.5 border-b border-slate-100 bg-primary-50/80 rounded-t-lg">
                        <span class="font-display text-sm font-semibold text-primary-700">
                            <i class="fas fa-chess-board text-primary-500 mr-1"></i>Mesa <?php echo $num_mesa; ?>
                        </span>
                        <?php if (count($jugadores) !== 4): ?>
                        <span class="text-[10px] font-medium text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded"><?php echo count($jugadores); ?>/4</span>
                        <?php endif; ?>
                    </header>

                    <?php if (count($jugadores) === 4): ?>
                    <div class="flex-1 grid grid-rows-[1fr_auto_1fr] gap-0 px-2 py-1.5 text-xs min-h-0">
                        <div class="min-h-0 overflow-hidden">
                            <p class="text-[10px] uppercase tracking-wide text-primary-600 font-semibold mb-0.5">Pareja A</p>
                            <ul class="list-none p-0 m-0 space-y-0.5 text-slate-800">
                                <?php foreach ($pareja_a as $jugador): ?>
                                    <?php if (is_array($jugador)) echo $mesas_render_jugador($jugador, $es_modalidad_equipos_mesas); ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="text-center py-1.5 my-0.5 border-y border-dashed border-slate-200 bg-slate-50/80 rounded">
                            <?php if ($tiene_resultados): ?>
                            <span class="font-mono text-lg md:text-xl font-bold tabular-nums text-slate-900 tracking-tight">
                                <?php echo $resultado1; ?><span class="text-slate-400 mx-1">:</span><?php echo $resultado2; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-[10px] uppercase text-slate-400 font-medium">Sin resultado</span>
                            <?php endif; ?>
                        </div>
                        <div class="min-h-0 overflow-hidden">
                            <p class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold mb-0.5">Pareja B</p>
                            <ul class="list-none p-0 m-0 space-y-0.5 text-slate-800">
                                <?php foreach ($pareja_b as $jugador): ?>
                                    <?php if (is_array($jugador)) echo $mesas_render_jugador($jugador, $es_modalidad_equipos_mesas); ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="flex-1 px-2 py-2 text-xs text-slate-600">
                        <p class="mb-1 text-amber-700 font-medium">Mesa incompleta</p>
                        <ul class="list-none p-0 m-0 space-y-0.5">
                            <?php foreach ($jugadores as $jugador): ?>
                                <?php if (is_array($jugador)) echo $mesas_render_jugador($jugador, $es_modalidad_equipos_mesas); ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <footer class="mt-auto flex flex-wrap gap-1 p-2 border-t border-slate-100 bg-slate-50/90 rounded-b-lg">
                        <a href="<?php echo $base_url . $action_param; ?>action=registrar_resultados&torneo_id=<?php echo (int) $torneo['id']; ?>&ronda=<?php echo (int) $ronda; ?>&mesa=<?php echo $num_mesa; ?>"
                           class="inline-flex flex-1 min-w-[5.5rem] items-center justify-center gap-1 rounded-md bg-primary-500 px-2 py-1 text-[11px] font-semibold text-white hover:bg-primary-600">
                            <i class="fas fa-keyboard"></i> Resultados
                        </a>
                        <a href="<?php echo $base_url . $action_param; ?>action=reasignar_mesa&torneo_id=<?php echo (int) $torneo['id']; ?>&ronda=<?php echo (int) $ronda; ?>&mesa=<?php echo $num_mesa; ?>"
                           class="inline-flex flex-1 min-w-[5.5rem] items-center justify-center gap-1 rounded-md bg-teal-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-teal-700">
                            <i class="fas fa-exchange-alt"></i> Reasignar
                        </a>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($mesas_bye)): ?>
        <section class="mt-4 rounded-lg border border-amber-200 bg-amber-50/50 overflow-hidden">
            <h2 class="text-sm font-display font-semibold text-amber-900 px-3 py-2 border-b border-amber-200 bg-amber-100/80">
                <i class="fas fa-ban mr-1"></i> Jugadores BYE (descanso)
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 p-3 text-xs">
                <?php foreach ($mesas_bye as $jugador): ?>
                <div class="rounded-md bg-white border border-amber-100 px-2 py-1.5 truncate">
                    <i class="fas fa-user text-amber-600 mr-1"></i>
                    <?php echo htmlspecialchars($jugador['nombre']); ?>
                    <?php if (!empty($jugador['club_nombre'])): ?>
                    <span class="text-slate-400">(<?php echo htmlspecialchars($jugador['club_nombre']); ?>)</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function() {
    function irAMesa(id) {
        if (!id) return;
        var el = document.getElementById(id);
        var scroller = document.querySelector('main.fvd-main-scroll');
        if (el && scroller) {
            var top = el.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop - 8;
            scroller.scrollTo({ top: top, behavior: 'smooth' });
            return;
        }
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    window.irAMesa = irAMesa;
})();
</script>
