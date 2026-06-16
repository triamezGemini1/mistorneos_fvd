/**
 * Añade botón "Volver al origen" en la línea del breadcrumb o en contenedor global.
 * Política: siempre regresar al origen; solo la navegación expedita (clic en enlace) va a otra página.
 * El origen se obtiene de: data-nav-origin, enlace padre del breadcrumb, referrer (mismo origen) o home.
 */
(function() {
    'use strict';

    function injectStyles() {
        if (document.getElementById('breadcrumb-back-styles')) return;
        var style = document.createElement('style');
        style.id = 'breadcrumb-back-styles';
        style.textContent = [
            '.breadcrumb-with-back .btn-breadcrumb-back {',
            '  background-color: #0d6efd; color: #fff; border-color: #0d6efd; font-weight: 500;',
            '}',
            '.breadcrumb-with-back .btn-breadcrumb-back:hover {',
            '  background-color: #0b5ed7; border-color: #0a58ca; color: #fff;',
            '}',
            '.breadcrumb-with-back .breadcrumb, .breadcrumb-with-back .breadcrumb-item, .breadcrumb-with-back .breadcrumb-item a, .breadcrumb-with-back .breadcrumb-item.active { color: #000 !important; }',
            '.breadcrumb-with-back nav[aria-label="breadcrumb"], .breadcrumb-with-back nav[aria-label="breadcrumb"] ol, .breadcrumb-with-back nav[aria-label="breadcrumb"] li, .breadcrumb-with-back nav[aria-label="breadcrumb"] a { color: #000 !important; }',
            '.breadcrumb-with-back .breadcrumb-item a:hover, .breadcrumb-with-back nav[aria-label="breadcrumb"] a:hover { color: #333 !important; text-decoration: underline; }',
            '.breadcrumb-with-back .breadcrumb-item + .breadcrumb-item::before { color: #000 !important; }',
            '.nav-origin-global .btn-breadcrumb-back { background-color: #0d6efd; color: #fff; border-color: #0d6efd; }',
            '.nav-origin-global .btn-breadcrumb-back:hover { background-color: #0b5ed7; color: #fff; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function getOriginUrl() {
        var origin = (document.body && document.body.getAttribute('data-nav-origin')) || '';
        if (origin) return origin;

        var breadcrumbLinks = document.querySelectorAll('nav[aria-label="breadcrumb"] a, .breadcrumb-modern a, .breadcrumb a');
        var lastHref = null;
        for (var i = 0; i < breadcrumbLinks.length; i++) {
            var href = breadcrumbLinks[i].getAttribute('href');
            if (href && href !== '#' && href.indexOf('javascript:') !== 0) lastHref = href;
        }
        if (lastHref) return lastHref;

        var ref = document.referrer || '';
        if (ref) {
            try {
                var refOrigin = new URL(ref).origin;
                var curOrigin = window.location.origin;
                if (refOrigin === curOrigin && ref !== window.location.href) return ref;
            } catch (e) {}
        }

        try {
            var baseEl = document.querySelector('base');
            var baseHref = baseEl ? baseEl.getAttribute('href') : '';
            if (baseHref) {
                var baseUrl = new URL(baseHref, window.location.href);
                return baseUrl.origin + baseUrl.pathname.replace(/\/?$/, '/') + 'index.php?page=home';
            }
        } catch (e) {}
        return 'index.php?page=home';
    }

    function createVolverBtn() {
        var btn = document.createElement('a');
        btn.href = 'javascript:void(0)';
        btn.className = 'btn btn-outline-secondary btn-sm btn-breadcrumb-back flex-shrink-0';
        btn.title = 'Volver al origen';
        btn.setAttribute('aria-label', 'Volver');
        btn.innerHTML = '<i class="fas fa-arrow-left me-1"></i> Volver';
        btn.addEventListener('click', function() {
            window.location.href = getOriginUrl();
        });
        return btn;
    }

    function initBreadcrumbBack() {
        if (document.body && document.body.getAttribute('data-skip-volver') === '1') {
            return;
        }
        injectStyles();
        var slot = document.getElementById('breadcrumb-back-slot');
        if (slot && !slot.querySelector('.btn-breadcrumb-back')) {
            slot.className = (slot.className ? slot.className + ' ' : '') + 'nav-origin-global';
            slot.appendChild(createVolverBtn());
            return;
        }
        var besidePrint = document.getElementById('breadcrumb-back-beside-print');
        if (besidePrint && !besidePrint.querySelector('.btn-breadcrumb-back')) {
            besidePrint.className = (besidePrint.className ? besidePrint.className + ' ' : '') + 'nav-origin-global';
            besidePrint.appendChild(createVolverBtn());
            return;
        }
        var selectors = [
            'nav[aria-label="breadcrumb"]',
            '.breadcrumb-modern'
        ];
        var seen = new Set();

        selectors.forEach(function(selector) {
            var elements = document.querySelectorAll(selector);
            elements.forEach(function(el) {
                if (seen.has(el)) return;
                seen.add(el);
                if (el.closest('.breadcrumb-with-back')) return;

                var btn = createVolverBtn();
                var wrap = document.createElement('div');
                wrap.className = 'breadcrumb-with-back d-flex align-items-center gap-2 flex-wrap';
                el.parentNode.insertBefore(wrap, el);
                wrap.appendChild(btn);
                wrap.appendChild(el);
            });
        });

        var globalContainer = document.getElementById('global-volver-container');
        if (globalContainer && !globalContainer.querySelector('.btn-breadcrumb-back') && !document.querySelector('.breadcrumb-with-back')) {
            globalContainer.className = 'nav-origin-global mb-3';
            globalContainer.appendChild(createVolverBtn());
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBreadcrumbBack);
    } else {
        initBreadcrumbBack();
    }
})();
