/**
 * Dashboard init - Carga diferida para reducir TBT.
 * Usa requestIdleCallback para ejecutar cuando el navegador esté idle.
 */
(function () {
  'use strict';

  function initDashboard() {
    // Menú de usuario: inicializar Bootstrap dropdown y fallback si no abre (p. ej. en inscripción en sitio / subrutas)
    var userDropdown = document.getElementById('user-menu-dropdown');
    var userMenuButton = document.getElementById('userMenuButton');
    var userMenuList = userDropdown && userDropdown.querySelector('.dropdown-menu');
    if (userDropdown && typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
      try {
        bootstrap.Dropdown.getOrCreateInstance(userDropdown);
      } catch (e) { /* ignorar */ }
    }
    // Fallback: si al hacer clic en el botón el menú no se abre en 150ms, abrirlo manualmente (Bootstrap puede fallar en subrutas/inscripción en sitio)
    if (userMenuButton && userMenuList) {
      userMenuButton.addEventListener('click', function () {
        var menu = userMenuList;
        setTimeout(function () {
          if (!menu.classList.contains('show')) {
            menu.classList.add('show');
            userMenuButton.setAttribute('aria-expanded', 'true');
            var closeOnClickOutside = function (ev) {
              if (!userDropdown.contains(ev.target)) {
                menu.classList.remove('show');
                userMenuButton.setAttribute('aria-expanded', 'false');
                document.removeEventListener('click', closeOnClickOutside);
              }
            };
            setTimeout(function () { document.addEventListener('click', closeOnClickOutside); }, 10);
          }
        }, 150);
      });
    }
    // Forzar navegación en cualquier enlace del menú usuario
    if (userDropdown) {
      userDropdown.addEventListener('click', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (link && link.href && link.getAttribute('href') !== '#') {
          e.preventDefault();
          e.stopPropagation();
          window.location.href = link.href;
        }
      });
    }

    // Mover alertas del contenido a la zona superpuesta (no desplazan el layout)
    var flashContainer = document.getElementById('app-flash-messages');
    var main = document.querySelector('main');
    if (flashContainer && main) {
      var alerts = main.querySelectorAll('.alert.alert-dismissible, .alert.alert-success, .alert.alert-danger, .alert.alert-warning, .alert.alert-info');
      alerts.forEach(function (el) {
        if (el.closest('.modal') || el.closest('[role="dialog"]')) return;
        el.classList.add('app-flash-item', 'show');
        flashContainer.appendChild(el);
      });
    }

    // Toggle sidebar
    const toggleBtn = document.getElementById('menu-toggle');
    const wrapper = document.getElementById('wrapper');
    if (toggleBtn && wrapper) {
      toggleBtn.addEventListener('click', function () {
        wrapper.classList.toggle('toggled');
        localStorage.setItem('sidebarCollapsed', wrapper.classList.contains('toggled'));
      });
      if (localStorage.getItem('sidebarCollapsed') === 'true') {
        wrapper.classList.add('toggled');
      }
    }

    // Toggle submenu - expuesto globalmente para onclick en HTML
    window.toggleSubmenu = function (submenuId, linkElement) {
      const submenu = document.getElementById(submenuId);
      const chevron = linkElement && linkElement.querySelector ? linkElement.querySelector('.submenu-icon') : null;
      if (submenu) {
        const isOpen = submenu.classList.contains('show');
        submenu.classList.toggle('show', !isOpen);
        if (chevron) {
          chevron.classList.toggle('fa-chevron-up', !isOpen);
          chevron.classList.toggle('fa-chevron-down', isOpen);
        }
      }
    };

    // Búsqueda global: public/assets/app-search.js (AppSearch.wireDashboardSearch)

    // Auto-hide alerts
    setTimeout(function () {
      document.querySelectorAll('.alert.alert-success, .alert.alert-info').forEach(function (a) {
        a.style.transition = 'opacity 0.5s';
        a.style.opacity = '0';
        setTimeout(function () { a.remove(); }, 500);
      });
    }, 3000);

    // torneo_id en sessionStorage
    var torneoId = (new URLSearchParams(window.location.search)).get('torneo_id');
    if (torneoId) sessionStorage.setItem('current_torneo_id', torneoId);

    window.toggleMobileSearch = function () {
      var sb = document.querySelector('.search-box');
      if (sb) {
        sb.classList.toggle('mobile-visible');
        var inp = sb.querySelector('input');
        if (inp && sb.classList.contains('mobile-visible')) inp.focus();
      }
    };

    if (typeof actualizarCampanitaYToast === 'function') {
      setTimeout(actualizarCampanitaYToast, 2000);
      setInterval(actualizarCampanitaYToast, 60000);
    }
  }

  if (typeof requestIdleCallback !== 'undefined') {
    requestIdleCallback(initDashboard, { timeout: 800 });
  } else {
    setTimeout(initDashboard, 0);
  }
})();
