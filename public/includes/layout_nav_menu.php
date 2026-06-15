<?php
/**
 * Ítems del menú principal (barra superior).
 * Incluido desde layout.php; requiere variables de contexto del layout.
 */
?>
<ul class="fvd-top-nav-list list-unstyled mb-0">
        <?php if ($user['role'] !== 'admin_general'): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('home')) ?>" class="nav-link <?= $current_page === 'home' ? 'active' : '' ?>">
            <i class="fas fa-home me-3"></i>
            <span class="nav-text">Inicio</span>
          </a>
        </li>
        <?php endif; ?>
        
        <?php if ($user['role'] === 'admin_club'): ?>
        <?php
        // Detectar si estamos en una página de gestión de torneos
        $is_torneo_gestion = ($current_page === 'torneo_gestion');
        
        // Obtener torneo_id desde diferentes fuentes
        $torneo_id_selected = (int)($_GET['torneo_id'] ?? $_REQUEST['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
        if ($torneo_id_selected === 0 && isset($_SESSION['current_torneo_id'])) {
          $torneo_id_selected = (int)$_SESSION['current_torneo_id'];
        }
        
        $torneo_action = $_GET['action'] ?? $_REQUEST['action'] ?? '';
        $is_torneo_menu_active = $is_torneo_gestion || in_array($torneo_action, ['index', 'panel', 'panel_equipos', 'reportes_inscritos', 'mesas', 'rondas', 'posiciones', 'galeria_fotos', 'inscripciones', 'notificaciones', 'inscribir_sitio', 'inscribir_equipo_sitio', 'carga_masiva_equipos_sitio', 'carga_masiva_equipos_plantilla', 'carga_masiva_parejas_sitio', 'carga_masiva_parejas_plantilla', 'gestionar_inscripciones_equipos', 'sustituir_jugador', 'cuadricula', 'hojas_anotacion', 'registrar_resultados', 'registrar_resultados_v2', 'agregar_mesa', 'reasignar_mesa', 'podio', 'podios', 'podios_equipos', 'resultados_por_club', 'resultados_reportes', 'resultados_general', 'resultados_equipos_resumido', 'resultados_equipos_detallado', 'resumen_individual', 'equipos', 'verificar_actas', 'verificar_acta', 'verificar_actas_index', 'verificar_resultados']) || in_array($current_page, ['invitations', 'notificaciones_masivas']);
        $is_torneo_submenu_open = $torneo_id_selected > 0 || $is_torneo_menu_active;
        
        if ($torneo_id_selected > 0) {
          if ($current_page === 'registrants') { $is_torneo_menu_active = true; $is_torneo_submenu_open = true; $torneo_action = 'inscripciones'; }
          elseif ($current_page === 'player_invitations' || $current_page === 'tournaments/invitation_link') { $is_torneo_menu_active = true; $is_torneo_submenu_open = true; }
        }
        
        $filtro_actual_ac = $_GET['filtro'] ?? '';
        $admin_club_org_id = Auth::getUserOrganizacionId();
        ?>
        
        <!-- Mi Organización: acceso único y canónico -->
        <?php if (!$layout_torneos_solo && $admin_club_org_id): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('organizaciones', ['id' => $admin_club_org_id])) ?>" class="nav-link <?= ($current_page === 'organizaciones' && (int)($_GET['id'] ?? 0) === $admin_club_org_id) ? 'active' : '' ?>">
            <i class="fas fa-building me-3"></i>
            <span class="nav-text">Mi Organización</span>
          </a>
        </li>
        <?php endif; ?>
        <?php if ($layout_modulos_extendidos && (!empty($show_link_panel_asociacion) || (($user['role'] ?? '') === 'admin_club' && (int) ($user['entidad'] ?? 0) > 0))): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('finanzas/resumen_asociacion')) ?>" class="nav-link <?= $current_page === 'finanzas/resumen_asociacion' ? 'active' : '' ?>">
            <i class="fas fa-coins me-3"></i>
            <span class="nav-text">Finanzas asociación</span>
          </a>
        </li>
        <?php endif; ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('admin_torneo_operadores')) ?>" class="nav-link <?= $current_page === 'admin_torneo_operadores' ? 'active' : '' ?>">
            <i class="fas fa-user-cog me-3"></i>
            <span class="nav-text">Admin Torneo y Operadores</span>
          </a>
        </li>
        <?php if ($layout_modulos_extendidos): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('cuentas_bancarias')) ?>" class="nav-link <?= $current_page === 'cuentas_bancarias' ? 'active' : '' ?>">
            <i class="fas fa-university me-3"></i>
            <span class="nav-text">Cuentas Bancarias</span>
          </a>
        </li>
        <?php endif; ?>
        <?php
        $is_complementos_club_open = in_array($current_page, ['calendario', 'bannerclock', 'directorio_clubes', 'comments_public'], true);
        ?>
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_complementos_club_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('complementos-club-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-puzzle-piece me-3"></i>
            <span class="nav-text">Complementos</span>
            <i class="fas fa-chevron-<?= $is_complementos_club_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_complementos_club_open ? 'show' : '' ?>" id="complementos-club-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('calendario')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'calendario' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt me-2"></i>
                <span>Calendario</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('bannerclock')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'bannerclock' ? 'active' : '' ?>">
                <i class="fas fa-bullhorn me-2"></i>
                <span>Banner Reloj</span>
              </a>
            </li>
            <?php if ($layout_modulos_extendidos): ?>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('comments_public')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'comments_public' ? 'active' : '' ?>">
                <i class="fas fa-comment-dots me-2"></i>
                <span>Comentarios</span>
              </a>
            </li>
            <?php endif; ?>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('directorio_clubes')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'directorio_clubes' ? 'active' : '' ?>">
                <i class="fas fa-address-book me-2"></i>
                <span>Directorio de asociaciones</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($menu_url('manuales_web/manual_usuario.php')) ?>" class="nav-link nav-sub-sub-link" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-book me-2"></i>
                <span>Manual de Usuario</span>
                <i class="fas fa-external-link-alt ms-1" style="font-size: 0.65rem;"></i>
              </a>
            </li>
          </ul>
        </li>
        <!-- Portal Público -->
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('landing-spa.php')) ?>" class="nav-link">
            <i class="fas fa-id-card me-3"></i>
            <span class="nav-text">Portal Público</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <?php endif; ?>
        
        <?php if (Auth::isAdminGeneral()): ?>
        <?php if ($layout_torneos_solo): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('home')) ?>" class="nav-link <?= $current_page === 'home' ? 'active' : '' ?>">
            <i class="fas fa-home me-3"></i>
            <span class="nav-text">Inicio</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('torneo_gestion', ['action' => 'index'])) ?>" class="nav-link <?= $current_page === 'torneo_gestion' ? 'active' : '' ?>">
            <i class="fas fa-trophy me-3"></i>
            <span class="nav-text">Administración de torneos</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('landing-spa.php')) ?>" class="nav-link">
            <i class="fas fa-id-card me-3"></i>
            <span class="nav-text">Portal Público</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <?php else: ?>
        <?php
        $is_inicio_open = ($current_page === 'home');
        $is_complementos_open = in_array($current_page, [
            'calendario', 'bannerclock', 'directorio_clubes',
            'whatsapp_config', 'comments', 'comments_public',
        ], true);
        $is_recursos_open = in_array($current_page, [
            'control_admin', 'admin_atletas_sync',
            'torneo_split_ranking', 'ranking_numfvd_admin', 'ranking_numfvd_detalle', 'archivos_web', 'fvd_guia_ui',
            'estadisticas_web',
        ], true);
        $nav_fvd_org_id = class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_ID : 1;
        $nav_mi_org_href = $dashboard_href('organizaciones', ['id' => $nav_fvd_org_id]);
        $nav_mi_org_active = in_array($current_page, ['organizaciones', 'mi_organizacion'], true)
            && (int) ($_GET['id'] ?? $nav_fvd_org_id) === $nav_fvd_org_id;
        ?>
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_inicio_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('inicio-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-home me-3"></i>
            <span class="nav-text">Inicio</span>
            <i class="fas fa-chevron-<?= $is_inicio_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_inicio_open ? 'show' : '' ?>" id="inicio-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('home')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'home' ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt me-2"></i>
                <span>Dashboard</span>
              </a>
            </li>
          </ul>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($nav_mi_org_href) ?>" class="nav-link <?= $nav_mi_org_active ? 'active' : '' ?>">
            <i class="fas fa-building me-3"></i>
            <span class="nav-text">Mi organización</span>
          </a>
        </li>
        <?php if ($layout_modulos_extendidos): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('finanzas/resumen_asociacion')) ?>" class="nav-link <?= $current_page === 'finanzas/resumen_asociacion' ? 'active' : '' ?>">
            <i class="fas fa-coins me-3"></i>
            <span class="nav-text">Finanzas por asociación</span>
          </a>
        </li>
        <?php endif; ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('users')) ?>" class="nav-link <?= $current_page === 'users' ? 'active' : '' ?>">
            <i class="fas fa-user-cog me-3"></i>
            <span class="nav-text">Gestión de Usuarios y Roles</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('auditoria')) ?>" class="nav-link <?= $current_page === 'auditoria' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list me-3"></i>
            <span class="nav-text">Reporte de actividad</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('estadisticas_web')) ?>" class="nav-link <?= $current_page === 'estadisticas_web' ? 'active' : '' ?>">
            <i class="fas fa-chart-line me-3"></i>
            <span class="nav-text">Estadísticas web</span>
          </a>
        </li>
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_complementos_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('complementos-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-puzzle-piece me-3"></i>
            <span class="nav-text">Complementos</span>
            <i class="fas fa-chevron-<?= $is_complementos_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_complementos_open ? 'show' : '' ?>" id="complementos-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('calendario')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'calendario' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt me-2"></i>
                <span>Calendario</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('bannerclock')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'bannerclock' ? 'active' : '' ?>">
                <i class="fas fa-bullhorn me-2"></i>
                <span>Banner Reloj</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('whatsapp_config')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'whatsapp_config' ? 'active' : '' ?>">
                <i class="fab fa-whatsapp me-2"></i>
                <span>Mensajes WhatsApp</span>
              </a>
            </li>
            <?php if ($layout_modulos_extendidos): ?>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('comments')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'comments' ? 'active' : '' ?>">
                <i class="fas fa-comments me-2"></i>
                <span>Comentarios (aprobación)</span>
                <?php
                try {
                    $pendientes = DB::pdo()->query("SELECT COUNT(*) FROM comentariossugerencias WHERE estatus = 'pendiente'")->fetchColumn();
                    if ($pendientes > 0):
                ?>
                  <span class="badge bg-danger rounded-pill ms-2"><?= $pendientes ?></span>
                <?php endif;
                } catch (Exception $e) {}
                ?>
              </a>
            </li>
            <?php endif; ?>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('directorio_clubes')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'directorio_clubes' ? 'active' : '' ?>">
                <i class="fas fa-address-book me-2"></i>
                <span>Directorio de asociaciones</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($menu_url('manuales_web/manual_usuario.php')) ?>" class="nav-link nav-sub-sub-link" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-book me-2"></i>
                <span>Manual de Usuario</span>
                <i class="fas fa-external-link-alt ms-1" style="font-size: 0.65rem;"></i>
              </a>
            </li>
          </ul>
        </li>
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_recursos_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('recursos-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-toolbox me-3"></i>
            <span class="nav-text">Recursos adicionales</span>
            <i class="fas fa-chevron-<?= $is_recursos_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_recursos_open ? 'show' : '' ?>" id="recursos-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('control_admin')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'control_admin' ? 'active' : '' ?>">
                <i class="fas fa-tools me-2"></i>
                <span>Control Especial</span>
              </a>
            </li>
            <?php if ($layout_modulos_extendidos): ?>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('admin_atletas_sync')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'admin_atletas_sync' ? 'active' : '' ?>">
                <i class="fas fa-database me-2"></i>
                <span>Atletas → Usuarios</span>
              </a>
            </li>
            <?php endif; ?>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('torneo_split_ranking')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'torneo_split_ranking' ? 'active' : '' ?>">
                <i class="fas fa-code-branch me-2"></i>
                <span>Segmentar torneo (equipos)</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('ranking_numfvd_admin')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'ranking_numfvd_admin' ? 'active' : '' ?>">
                <i class="fas fa-medal me-2"></i>
                <span>Ranking NUMFVD / posi_rnk</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('archivos_web')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'archivos_web' ? 'active' : '' ?>">
                <i class="fas fa-folder-open me-2"></i>
                <span>Archivos descargables</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('fvd_guia_ui')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'fvd_guia_ui' ? 'active' : '' ?>">
                <i class="fas fa-palette me-2"></i>
                <span>Guía UI / Identidad</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('estadisticas_web')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'estadisticas_web' ? 'active' : '' ?>">
                <i class="fas fa-chart-line me-2"></i>
                <span>Estadísticas web (Umami)</span>
              </a>
            </li>
          </ul>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('landing-spa.php')) ?>" class="nav-link">
            <i class="fas fa-id-card me-3"></i>
            <span class="nav-text">Portal Público</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($user['role'] === 'admin_torneo'): ?>
        <?php if (!$layout_torneos_solo): ?>
        <?php
        $nav_fvd_torneo = class_exists('FvdConfig') ? FvdConfig::ORGANIZACION_ID : 1;
        $href_mi_org_at = $dashboard_href('organizaciones', ['id' => $nav_fvd_torneo]);
        $active_mi_org_at = in_array($current_page, ['organizaciones', 'mi_organizacion'], true)
            && (int) ($_GET['id'] ?? $nav_fvd_torneo) === $nav_fvd_torneo;
        ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($href_mi_org_at) ?>" class="nav-link <?= $active_mi_org_at ? 'active' : '' ?>">
            <i class="fas fa-building me-3"></i>
            <span class="nav-text">Mi organización</span>
          </a>
        </li>
        <?php endif; ?>
        <?php if ($layout_modulos_extendidos): ?>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($dashboard_href('cuentas_bancarias')) ?>" class="nav-link <?= $current_page === 'cuentas_bancarias' ? 'active' : '' ?>">
            <i class="fas fa-university me-3"></i>
            <span class="nav-text">Cuentas Bancarias</span>
          </a>
        </li>
        <?php endif; ?>
        <?php
        $is_complementos_at_open = in_array($current_page, ['calendario', 'bannerclock'], true);
        ?>
        <li class="mb-2">
          <a href="#" class="nav-link <?= $is_complementos_at_open ? 'active' : '' ?>"
             onclick="event.preventDefault(); toggleSubmenu('complementos-at-submenu', this);"
             style="cursor: pointer;">
            <i class="fas fa-puzzle-piece me-3"></i>
            <span class="nav-text">Complementos</span>
            <i class="fas fa-chevron-<?= $is_complementos_at_open ? 'up' : 'down' ?> ms-auto submenu-icon"></i>
          </a>
          <ul class="list-unstyled ps-4 mt-1 collapse-submenu <?= $is_complementos_at_open ? 'show' : '' ?>" id="complementos-at-submenu">
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('calendario')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'calendario' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt me-2"></i>
                <span>Calendario</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($dashboard_href('bannerclock')) ?>" class="nav-link nav-sub-sub-link <?= $current_page === 'bannerclock' ? 'active' : '' ?>">
                <i class="fas fa-bullhorn me-2"></i>
                <span>Banner Reloj</span>
              </a>
            </li>
            <li class="mb-1">
              <a href="<?= htmlspecialchars($menu_url('manuales_web/manual_usuario.php')) ?>" class="nav-link nav-sub-sub-link" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-book me-2"></i>
                <span>Manual de Usuario</span>
                <i class="fas fa-external-link-alt ms-1" style="font-size: 0.65rem;"></i>
              </a>
            </li>
          </ul>
        </li>
        <li class="mb-2">
          <a href="<?= htmlspecialchars($menu_url('landing-spa.php')) ?>" class="nav-link">
            <i class="fas fa-id-card me-3"></i>
            <span class="nav-text">Portal Público</span>
            <i class="fas fa-external-link-alt ms-auto" style="font-size: 0.75rem;"></i>
          </a>
        </li>
        <?php endif; ?>
      </ul>
