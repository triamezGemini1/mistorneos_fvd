<?php
/**
 * Accesos rápidos del dashboard (solo presentación).
 */
$u = static function (string $page, array $q = []): string {
    return htmlspecialchars(AppHelpers::dashboard($page, $q));
};
?>
<aside class="rounded-lg border border-slate-200 bg-white shadow-sm overflow-hidden h-full">
    <div class="bg-blue-900 text-white px-3 py-2">
        <h2 class="text-sm font-semibold tracking-wide"><i class="fas fa-bolt me-2 text-amber-400"></i>Accesos rápidos</h2>
    </div>
    <div class="p-3 flex flex-col gap-2">
        <a href="<?= $u('users') ?>" class="flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-800 hover:border-amber-400 hover:bg-amber-50 transition-colors">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-blue-900 text-amber-400"><i class="fas fa-user-plus"></i></span>
            <span>Inscribir atleta</span>
        </a>
        <a href="<?= $u('tournaments', ['action' => 'new']) ?>" class="flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-800 hover:border-amber-400 hover:bg-amber-50 transition-colors">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-blue-900 text-amber-400"><i class="fas fa-trophy"></i></span>
            <span>Crear torneo</span>
        </a>
        <a href="<?= $u('calendario') ?>" class="flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-800 hover:border-amber-400 hover:bg-amber-50 transition-colors">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-blue-900 text-amber-400"><i class="fas fa-calendar-alt"></i></span>
            <span>Ver calendario</span>
        </a>
    </div>
</aside>
