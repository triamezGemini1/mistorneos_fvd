/**
 * Buscadores centralizados — activos desde 3 caracteres.
 */
(function (global) {
  'use strict';

  var MIN_CHARS = 3;
  var DEBOUNCE_MS = 350;

  function trim(s) {
    return (s || '').trim();
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var ctx = this;
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(ctx, args);
      }, ms);
    };
  }

  function isReady(q) {
    return trim(q).length >= MIN_CHARS;
  }

  function isReadyPersonaQuery(raw) {
    raw = trim(raw);
    if (!raw) {
      return false;
    }
    if (/[a-zA-Z\u00C0-\u024F]/.test(raw)) {
      return raw.length >= MIN_CHARS;
    }
    var digits = raw.replace(/\D/g, '');
    return digits.length >= MIN_CHARS;
  }

  function hintShort() {
    return 'Escriba al menos ' + MIN_CHARS + ' caracteres para buscar.';
  }

  function escapeHtml(str) {
    if (str == null) {
      return '';
    }
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function resolvePublicBase(explicit) {
    if (explicit) {
      return String(explicit).replace(/\/$/, '');
    }
    if (global.APP_PUBLIC_BASE) {
      return String(global.APP_PUBLIC_BASE).replace(/\/$/, '');
    }
    if (global.APP_BASE_URL) {
      return String(global.APP_BASE_URL).replace(/\/$/, '') + '/public';
    }
    return '';
  }

  function fetchJson(url, options) {
    options = options || {};
    var opts = {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    };
    if (options.signal) {
      opts.signal = options.signal;
    }
    return fetch(url, opts).then(function (r) {
      return r.text().then(function (text) {
        try {
          return text ? JSON.parse(text) : {};
        } catch (e) {
          return { ok: false, error: 'Respuesta no válida' };
        }
      });
    });
  }

  function ensureDropdown(wrap) {
    var dd = wrap.querySelector('.app-search-dropdown');
    if (!dd) {
      dd = document.createElement('div');
      dd.className = 'app-search-dropdown';
      dd.setAttribute('role', 'listbox');
      wrap.appendChild(dd);
    }
    return dd;
  }

  function showDropdownMessage(wrap, html) {
    var dd = ensureDropdown(wrap);
    dd.innerHTML = '<div class="app-search-hint">' + html + '</div>';
    dd.style.display = 'block';
  }

  function hideDropdown(wrap) {
    var dd = wrap.querySelector('.app-search-dropdown');
    if (dd) {
      dd.style.display = 'none';
      dd.innerHTML = '';
    }
  }

  function attachLive(input, config) {
    if (!input) {
      return { destroy: function () {} };
    }
    config = config || {};
    var scope = config.scope || 'usuarios';
    var base = resolvePublicBase(config.apiBase);
    var apiPrefix = base ? base + '/' : '';
    var wrap = config.wrap || input.closest('.app-search-wrap') || input.parentElement;
    if (!wrap.classList.contains('app-search-wrap')) {
      wrap.classList.add('app-search-wrap');
    }
    var controller = null;
    var onSelect = typeof config.onSelect === 'function' ? config.onSelect : null;
    var renderItem =
      typeof config.renderItem === 'function'
        ? config.renderItem
        : function (item) {
            return (
              '<button type="button" class="app-search-item" data-id="' +
              escapeHtml(item.id || '') +
              '"><strong>' +
              escapeHtml(item.title || '') +
              '</strong><br><small class="text-muted">' +
              escapeHtml(item.subtitle || '') +
              '</small></button>'
            );
          };

    var run = debounce(function () {
      var q = trim(input.value);
      if (!isReady(q)) {
        hideDropdown(wrap);
        if (q.length > 0) {
          showDropdownMessage(wrap, hintShort());
        }
        return;
      }
      if (controller) {
        controller.abort();
      }
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      showDropdownMessage(wrap, '<i class="fas fa-spinner fa-spin me-1"></i> Buscando…');
      var url =
        apiPrefix +
        'api/app_search.php?scope=' +
        encodeURIComponent(scope) +
        '&q=' +
        encodeURIComponent(q);
      if (config.clubId) {
        url += '&club_id=' + encodeURIComponent(config.clubId);
      }
      fetchJson(url, { signal: controller ? controller.signal : null })
        .then(function (data) {
          if (!data.ok && data.error) {
            showDropdownMessage(wrap, data.error);
            return;
          }
          if (data.active === false && data.message) {
            showDropdownMessage(wrap, data.message);
            return;
          }
          var items = data.items || data.results || [];
          if (!items.length) {
            showDropdownMessage(wrap, 'Sin resultados');
            return;
          }
          var dd = ensureDropdown(wrap);
          dd.innerHTML = items.map(renderItem).join('');
          dd.style.display = 'block';
          dd.querySelectorAll('.app-search-item').forEach(function (btn, idx) {
            btn.addEventListener('click', function () {
              hideDropdown(wrap);
              if (onSelect) {
                onSelect(items[idx], input);
              }
            });
          });
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') {
            return;
          }
          showDropdownMessage(wrap, 'Error al buscar');
        });
    }, config.debounceMs || DEBOUNCE_MS);

    input.addEventListener('input', run);
    input.addEventListener('focus', function () {
      if (isReady(input.value)) {
        run();
      }
    });
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) {
        hideDropdown(wrap);
      }
    });

    return { destroy: function () {} };
  }

  function buscarPersona(options) {
    options = options || {};
    var raw = trim(options.query || '');
    if (!isReadyPersonaQuery(raw)) {
      return Promise.resolve({ accion: 'error', mensaje: hintShort() });
    }
    var base = resolvePublicBase(options.apiBase);
    var apiPrefix = base ? base + '/' : '';
    var nac = (options.nacionalidad || 'V').toUpperCase();
    var torneoId = options.torneoId || 0;
    var ced = raw.replace(/\D/g, '');
    var qs =
      'torneo_id=' +
      encodeURIComponent(torneoId) +
      '&nacionalidad=' +
      encodeURIComponent(nac) +
      '&busqueda=' +
      encodeURIComponent(raw) +
      '&cedula=' +
      encodeURIComponent(ced);
    if (/^[0-9]{1,8}$/.test(raw)) {
      qs += '&user_id=' + encodeURIComponent(raw);
    }
    return fetchJson(apiPrefix + 'api/search_persona.php?' + qs, {
      signal: options.signal || null,
    });
  }

  function attachPersona(input, config) {
    config = config || {};
    var getNac =
      typeof config.getNacionalidad === 'function'
        ? config.getNacionalidad
        : function () {
            return 'V';
          };
    var onResult = typeof config.onResult === 'function' ? config.onResult : null;
    var onMessage = typeof config.onMessage === 'function' ? config.onMessage : null;
    var torneoId = config.torneoId || 0;
    var base = resolvePublicBase(config.apiBase);
    var controller = null;
    var isSearching = false;

    var run = debounce(function () {
      if (isSearching) {
        return;
      }
      var raw = trim(input.value);
      if (!isReadyPersonaQuery(raw)) {
        if (onMessage && raw.length > 0) {
          onMessage(hintShort(), 'warning');
        }
        return;
      }
      if (controller) {
        controller.abort();
      }
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      isSearching = true;
      if (onMessage) {
        onMessage('<i class="fas fa-spinner fa-spin me-1"></i> Buscando…', 'info');
      }
      buscarPersona({
        query: raw,
        nacionalidad: getNac(),
        torneoId: torneoId,
        apiBase: base,
        signal: controller ? controller.signal : null,
      })
        .then(function (data) {
          isSearching = false;
          if (onResult) {
            onResult(data, raw);
          }
        })
        .catch(function () {
          isSearching = false;
          if (onMessage) {
            onMessage('Error de conexión al buscar.', 'danger');
          }
        });
    }, config.debounceMs || DEBOUNCE_MS);

    input.addEventListener('input', run);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        run();
      }
    });

    return { trigger: run, destroy: function () {} };
  }

  function globalSearch(term, apiBase) {
    if (!isReady(term)) {
      return Promise.resolve({ results: [], active: false });
    }
    var base = resolvePublicBase(apiBase);
    var apiPrefix = base ? base + '/' : '';
    return fetchJson(
      apiPrefix + 'api/app_search.php?scope=global&q=' + encodeURIComponent(trim(term))
    ).then(function (data) {
      return {
        results: data.results || data.items || [],
        active: data.active !== false,
        query: term,
        message: data.message || '',
      };
    });
  }

  var dashboardResults = null;

  function hideDashboardResults() {
    if (dashboardResults && dashboardResults.parentNode) {
      dashboardResults.parentNode.removeChild(dashboardResults);
    }
    dashboardResults = null;
  }

  function showDashboardResults(results, query) {
    hideDashboardResults();
    var searchBox = document.querySelector('.search-box');
    if (!searchBox) {
      return;
    }
    dashboardResults = document.createElement('div');
    dashboardResults.className = 'search-results';
    if (!results || !results.length) {
      dashboardResults.innerHTML =
        '<div class="search-results-header"><small class="text-muted">Sin resultados para "' +
        escapeHtml(query) +
        '"</small><button type="button" class="btn-close" aria-label="Cerrar"></button></div>';
    } else {
      dashboardResults.innerHTML =
        '<div class="search-results-header"><small class="text-muted">Resultados para "' +
        escapeHtml(query) +
        '"</small><button type="button" class="btn-close" aria-label="Cerrar"></button></div><div class="search-results-list">' +
        results
          .map(function (r) {
            return (
              '<a href="' +
              escapeHtml(r.url) +
              '" class="search-result-item"><div class="search-result-icon"><i class="' +
              escapeHtml(r.icon) +
              '"></i></div><div class="search-result-content"><div class="search-result-title">' +
              escapeHtml(r.title) +
              '</div><div class="search-result-subtitle">' +
              escapeHtml(r.subtitle) +
              '</div></div><div class="search-result-badge"><span class="badge bg-secondary">' +
              escapeHtml(r.badge) +
              '</span></div></a>'
            );
          })
          .join('') +
        '</div>';
    }
    var closeBtn = dashboardResults.querySelector('.btn-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', hideDashboardResults);
    }
    searchBox.appendChild(dashboardResults);
  }

  function wireDashboardSearch() {
    var searchInput = document.getElementById('searchInput');
    if (!searchInput || searchInput.dataset.appSearchWired === '1') {
      return;
    }
    searchInput.dataset.appSearchWired = '1';
    searchInput.setAttribute('minlength', String(MIN_CHARS));
    searchInput.setAttribute('autocomplete', 'off');

    var run = debounce(function () {
      var term = trim(searchInput.value);
      if (!isReady(term)) {
        hideDashboardResults();
        return;
      }
      globalSearch(term).then(function (data) {
        showDashboardResults(data.results, term);
      });
    }, DEBOUNCE_MS);

    searchInput.addEventListener('input', run);
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.search-box')) {
        hideDashboardResults();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        hideDashboardResults();
        searchInput.blur();
      }
    });
  }

  global.AppSearch = {
    MIN_CHARS: MIN_CHARS,
    debounce: debounce,
    isReady: isReady,
    isReadyPersonaQuery: isReadyPersonaQuery,
    hintShort: hintShort,
    fetchJson: fetchJson,
    attachLive: attachLive,
    attachPersona: attachPersona,
    buscarPersona: buscarPersona,
    globalSearch: globalSearch,
    wireDashboardSearch: wireDashboardSearch,
    hideDashboardResults: hideDashboardResults,
  };

  global.hideSearchResults = hideDashboardResults;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireDashboardSearch);
  } else {
    wireDashboardSearch();
  }
})(typeof window !== 'undefined' ? window : this);
