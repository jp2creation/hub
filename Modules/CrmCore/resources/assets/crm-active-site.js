(function () {
  var storageKey = 'crm:active-site-id';
  var eventName = 'crm:active-site-changed';
  var rootId = 'crm-active-site-switcher';
  var styleId = 'crm-active-site-style';
  var addvanceUrl = 'https://martinsols.addvancesolutions.fr';
  var state = {
    loading: false,
    loaded: false,
    sites: [],
    activeSiteId: numberOrNull(readStorage()),
  };

  function numberOrNull(value) {
    var number = Number(value || 0);
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function siteById(siteId) {
    return state.sites.find(function (site) {
      return Number(site.id) === Number(siteId);
    }) || null;
  }

  function setSiteId(siteId, options) {
    var next = numberOrNull(siteId);

    if (!next || state.activeSiteId === next) {
      renderSwitcher();
      return state.activeSiteId;
    }

    state.activeSiteId = next;
    writeStorage(next);
    renderSwitcher();

    if (!options || options.silent !== true) {
      window.dispatchEvent(new CustomEvent(eventName, {
        detail: {
          siteId: next,
          site: siteById(next),
        },
      }));
    }

    return next;
  }

  function activeSite() {
    return siteById(state.activeSiteId) || state.sites[0] || null;
  }

  function siteLabel(site) {
    if (!site) {
      return state.loading ? 'Chargement...' : 'Aucun site';
    }

    return site.name || site.slug || ('Site #' + site.id);
  }

  function setMenuOpen(host, open) {
    if (!host) {
      return;
    }

    var button = host.querySelector('[data-crm-active-site-toggle]');
    var menu = host.querySelector('[data-crm-active-site-menu]');

    host.classList.toggle('is-open', open);

    if (button) {
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (menu) {
      menu.hidden = !open;
    }
  }

  function toggleMenu(host) {
    var menu = host && host.querySelector('[data-crm-active-site-menu]');
    setMenuOpen(host, !menu || menu.hidden);
  }

  function closeAllMenus(except) {
    document.querySelectorAll('.crm-active-site-switcher').forEach(function (host) {
      if (host !== except) {
        setMenuOpen(host, false);
      }
    });
  }

  function allowedSitesFromPayload(payload) {
    var sites = Array.isArray(payload && payload.sites) ? payload.sites : [];
    var allowedIds = Array.isArray(payload && payload.user && payload.user.siteIds)
      ? payload.user.siteIds.map(Number)
      : [];

    var activeSites = sites.filter(function (site) {
      return site && site.active !== false;
    });

    if (!allowedIds.length) {
      return activeSites;
    }

    return activeSites.filter(function (site) {
      return allowedIds.includes(Number(site.id));
    });
  }

  async function fetchBootstrap(url) {
    var response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
      throw new Error('Bootstrap unavailable');
    }

    return response.json();
  }

  async function loadSites() {
    if (state.loading || state.loaded) {
      return;
    }

    state.loading = true;

    var urls = [
      '/api/reservations?action=bootstrap',
      '/api/equipment-rentals?action=bootstrap',
      '/api/controle-caisse?action=bootstrap',
      '/api/demandes-acompte?action=bootstrap',
      '/api/conges?action=bootstrap',
      '/api/equipes?action=bootstrap',
      '/api/administration?action=bootstrap',
    ];

    for (var index = 0; index < urls.length; index += 1) {
      try {
        var payload = await fetchBootstrap(urls[index]);
        var sites = allowedSitesFromPayload(payload);

        if (sites.length) {
          state.sites = sites;
          state.loaded = true;
          state.loading = false;

          var saved = numberOrNull(readStorage());
          var savedAllowed = saved && sites.some(function (site) {
            return Number(site.id) === saved;
          });

          setSiteId(savedAllowed ? saved : Number(sites[0].id), { silent: true });
          renderSwitcher();
          return;
        }
      } catch (error) {
        // Try the next HUB API.
      }
    }

    state.loaded = true;
    state.loading = false;
    renderSwitcher();
  }

  function ensureStyle() {
    if (document.getElementById(styleId)) {
      return;
    }

    var style = document.createElement('style');
    style.id = styleId;
    style.textContent = [
      '.crm-active-site-switcher{position:relative;display:flex;align-items:center;gap:.35rem;min-width:0;max-width:36vw}',
      '.crm-active-site-label{font-size:.72rem;font-weight:800;line-height:1;text-transform:uppercase;color:var(--color-secondary-500,#64748b);white-space:nowrap}',
      '.crm-active-site-control{position:relative;min-width:0}',
      '.crm-active-site-trigger{display:grid;grid-template-columns:.62rem minmax(0,1fr) 1rem;align-items:center;gap:.55rem;width:100%;height:2.2rem;max-width:8rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:.55rem;background:var(--color-surface-50,#f8fafc);padding:0 .65rem;font:inherit;font-size:.78rem;font-weight:800;color:var(--color-secondary-900,#0f172a);text-align:left;outline:none;cursor:pointer;box-shadow:0 1px 2px rgb(15 23 42 / .03)}',
      '.crm-active-site-trigger:hover,.crm-active-site-trigger:focus{border-color:rgb(var(--theme-primary) / .55);box-shadow:0 0 0 3px rgb(var(--theme-primary) / .12)}',
      '.crm-active-site-trigger:disabled{cursor:wait;opacity:.72}',
      '.crm-active-site-dot{display:block;width:.62rem;height:.62rem;border-radius:999px;background:var(--active-site-color,transparent);box-shadow:0 0 0 2px #fff,0 0 0 3px color-mix(in srgb,var(--active-site-color,#cbd5e1) 28%,transparent);opacity:0}',
      '.crm-active-site-trigger.has-site-dot .crm-active-site-dot{opacity:1}',
      '.crm-active-site-current{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.crm-active-site-chevron{width:1rem;height:1rem;transition:transform .18s cubic-bezier(.2,0,0,1)}',
      '.crm-active-site-switcher.is-open .crm-active-site-chevron{transform:rotate(180deg)}',
      '.crm-active-site-menu{position:absolute;top:calc(100% + .62rem);right:0;z-index:var(--z-dropdown,1000);width:min(14rem,calc(100vw - 1.5rem));padding:.5rem;border:1px solid var(--color-surface-200,#e2e8f0);border-radius:1rem;background:#fff;box-shadow:0 16px 34px rgb(15 23 42 / .12),0 2px 8px rgb(15 23 42 / .05);transform-origin:top right}',
      '.crm-active-site-menu[hidden]{display:none!important}',
      '.crm-active-site-menu:before{position:absolute;top:-.35rem;right:1.15rem;width:.7rem;height:.7rem;border-top:1px solid var(--color-surface-200,#e2e8f0);border-left:1px solid var(--color-surface-200,#e2e8f0);background:#fff;content:"";transform:rotate(45deg)}',
      '.crm-active-site-option{position:relative;z-index:1;display:grid;width:100%;grid-template-columns:1.85rem .62rem minmax(0,1fr);align-items:center;gap:.65rem;border:0;border-radius:.8rem;background:transparent;padding:.7rem .78rem;color:var(--color-secondary-800,#243b53);font:inherit;text-align:left;cursor:pointer}',
      '.crm-active-site-option:hover,.crm-active-site-option:focus-visible{background:rgb(var(--theme-primary) / .08);color:rgb(var(--theme-primary))}',
      '.crm-active-site-option.is-active{background:rgb(var(--theme-primary) / .11);color:rgb(var(--theme-primary))}',
      '.crm-active-site-option-icon{display:grid;width:1.85rem;height:1.85rem;place-items:center;border-radius:.65rem;background:rgb(var(--theme-primary) / .08);color:rgb(var(--theme-primary))}',
      '.crm-active-site-option-icon svg{width:1rem;height:1rem;stroke:currentColor;stroke-width:2;fill:none;stroke-linecap:round;stroke-linejoin:round}',
      '.crm-active-site-option-dot{display:block;width:.62rem;height:.62rem;border-radius:999px;background:var(--option-site-color,transparent);box-shadow:0 0 0 2px #fff,0 0 0 3px color-mix(in srgb,var(--option-site-color,#cbd5e1) 24%,transparent);opacity:0}',
      '.crm-active-site-option.has-site-dot .crm-active-site-option-dot{opacity:1}',
      '.crm-active-site-option-label{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.9rem;font-weight:800}',
      '.crm-active-site-empty{padding:.65rem .75rem;color:var(--color-secondary-500,#64748b);font-size:.84rem;font-weight:700}',
      '.dark .crm-active-site-trigger,.dark .crm-active-site-menu{border-color:var(--color-surface-700,#334155);background:var(--color-surface-800,#1e293b);color:#fff}',
      '.dark .crm-active-site-menu:before{border-color:var(--color-surface-700,#334155);background:var(--color-surface-800,#1e293b)}',
      '@media (max-width:767px){.crm-active-site-label{display:none}.crm-active-site-switcher{flex:0 1 clamp(8.5rem,36vw,10.8rem);max-width:clamp(8.5rem,36vw,10.8rem)}.crm-active-site-trigger{max-width:none;grid-template-columns:.45rem minmax(0,1fr) .8rem;gap:.3rem;padding:0 .45rem}.crm-active-site-dot{width:.45rem;height:.45rem}.crm-active-site-chevron{width:.8rem;height:.8rem}}',
      '@media (min-width:768px){.crm-active-site-switcher{gap:.45rem;flex:0 0 auto;max-width:none}.crm-active-site-trigger{height:2.35rem;min-width:9.5rem;max-width:13rem;padding:0 .8rem;font-size:.84rem}}',
    ].join('');
    document.head.appendChild(style);
  }

  function headerActions() {
    return document.querySelector('.layout-header .ms-auto');
  }

  function renderSwitcher() {
    if (!document.body) {
      return;
    }

    ensureStyle();

    var host = document.getElementById(rootId);
    var actions = headerActions();

    if (!actions) {
      return;
    }

    if (!state.sites.length && !state.loading) {
      if (host) {
        host.remove();
      }
      return;
    }

    if (!host) {
      host = document.createElement('div');
      host.id = rootId;
      host.className = 'crm-active-site-switcher';
      host.innerHTML = [
        '<span class="crm-active-site-label">Site</span>',
        '<div class="crm-active-site-control">',
        '<button class="crm-active-site-trigger" type="button" data-crm-active-site-toggle aria-haspopup="listbox" aria-expanded="false" aria-label="Changer de site">',
        '<span class="crm-active-site-dot" data-crm-active-site-dot aria-hidden="true"></span>',
        '<span class="crm-active-site-current" data-crm-active-site-current>Chargement...</span>',
        '<svg class="crm-active-site-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>',
        '</button>',
        '<div class="crm-active-site-menu" data-crm-active-site-menu hidden role="listbox" aria-label="Sites"></div>',
        '</div>',
      ].join('');

      var divider = actions.querySelector('.header-actions-divider');
      actions.insertBefore(host, divider || actions.firstChild);

      host.querySelector('[data-crm-active-site-toggle]').addEventListener('click', function (event) {
        event.preventDefault();
        closeAllMenus(host);
        toggleMenu(host);
      });

      host.querySelector('[data-crm-active-site-menu]').addEventListener('click', function (event) {
        var option = event.target instanceof Element
          ? event.target.closest('[data-crm-active-site-option]')
          : null;

        if (!option) {
          return;
        }

        event.preventDefault();
        setSiteId(option.getAttribute('data-crm-active-site-option'));
        setMenuOpen(host, false);
      });
    }

    var button = host.querySelector('[data-crm-active-site-toggle]');
    var current = host.querySelector('[data-crm-active-site-current]');
    var menu = host.querySelector('[data-crm-active-site-menu]');

    if (!state.sites.length) {
      button.disabled = true;
      button.classList.remove('has-site-dot');
      button.style.removeProperty('--active-site-color');
      current.textContent = state.loading ? 'Chargement...' : 'Aucun site';
      menu.innerHTML = '<div class="crm-active-site-empty">' + escapeHtml(current.textContent) + '</div>';
      setMenuOpen(host, false);
      return;
    }

    button.disabled = false;
    var selectedSite = activeSite();
    var selectedColor = siteColor(selectedSite);
    current.textContent = siteLabel(selectedSite);
    button.classList.toggle('has-site-dot', Boolean(selectedColor));
    if (selectedColor) {
      button.style.setProperty('--active-site-color', selectedColor);
    } else {
      button.style.removeProperty('--active-site-color');
    }

    var options = state.sites.map(function (site) {
      var active = Number(site.id) === Number(state.activeSiteId || state.sites[0].id);
      var color = siteColor(site);
      var style = color
        ? ' style="--option-site-color:' + escapeHtml(color) + '"'
        : '';

      return [
        '<button class="crm-active-site-option' + (active ? ' is-active' : '') + (color ? ' has-site-dot' : '') + '" type="button" role="option" aria-selected="' + (active ? 'true' : 'false') + '" data-crm-active-site-option="' + String(site.id) + '"' + style + '>',
        '<span class="crm-active-site-option-icon"><svg viewBox="0 0 24 24" aria-hidden="true">' + (active ? '<path d="m5 12 4 4 10-10"></path>' : '<path d="M12 21s7-4.4 7-11a7 7 0 1 0-14 0c0 6.6 7 11 7 11Z"></path><circle cx="12" cy="10" r="2.4"></circle>') + '</svg></span>',
        '<span class="crm-active-site-option-dot" aria-hidden="true"></span>',
        '<span class="crm-active-site-option-label">' + escapeHtml(siteLabel(site)) + '</span>',
        '</button>',
      ].join('');
    }).join('');

    if (menu.innerHTML !== options) {
      menu.innerHTML = options;
    }
  }

  function isAddvanceAnchor(anchor) {
    var href = anchor.getAttribute('href') || '';
    var text = (anchor.textContent || '').trim().toLowerCase();

    return href.indexOf(addvanceUrl) !== -1
      || href.indexOf('/https://martinsols.addvancesolutions.fr') !== -1
      || text === 'addvance';
  }

  function normalizeExternalLinks() {
    document.querySelectorAll('a[href]').forEach(function (anchor) {
      if (!isAddvanceAnchor(anchor)) {
        return;
      }

      anchor.setAttribute('href', addvanceUrl);
      anchor.setAttribute('target', '_blank');
      anchor.setAttribute('rel', 'noopener noreferrer');
      anchor.setAttribute('data-crm-external-link', 'addvance');
    });
  }

  function handleExternalLinkClick(event) {
    var anchor = event.target instanceof Element
      ? event.target.closest('a[data-crm-external-link="addvance"], a[href*="martinsols.addvancesolutions.fr"]')
      : null;

    if (!anchor || !isAddvanceAnchor(anchor)) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    if (typeof event.stopImmediatePropagation === 'function') {
      event.stopImmediatePropagation();
    }

    window.open(addvanceUrl, '_blank', 'noopener,noreferrer');
  }

  function siteColor(site) {
    var color = String((site && site.color) || '').trim();

    return /^#[0-9a-fA-F]{6}$/.test(color) ? color.toLowerCase() : '';
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  window.CRM_ACTIVE_SITE = {
    getSiteId: function () {
      return state.activeSiteId || numberOrNull(readStorage());
    },
    setSiteId: function (siteId) {
      return setSiteId(siteId);
    },
    getSites: function () {
      return state.sites.slice();
    },
    reload: function () {
      state.loaded = false;
      state.loading = false;
      return loadSites();
    },
  };

  function readStorage() {
    try {
      return window.localStorage.getItem(storageKey);
    } catch (error) {
      return null;
    }
  }

  function writeStorage(siteId) {
    try {
      window.localStorage.setItem(storageKey, String(siteId));
    } catch (error) {
      // Keeping the site in memory is enough for the current page.
    }
  }

  function start() {
    loadSites();
    renderSwitcher();
    normalizeExternalLinks();
    document.addEventListener('click', handleExternalLinkClick, true);
    document.addEventListener('click', function (event) {
      var target = event.target instanceof Element ? event.target : null;

      if (!target || !target.closest('.crm-active-site-switcher')) {
        closeAllMenus(null);
      }
    }, true);
    window.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeAllMenus(null);
      }
    });

    var attempts = 0;
    var timer = window.setInterval(function () {
      attempts += 1;
      renderSwitcher();
      if (document.getElementById(rootId) || attempts >= 40) {
        window.clearInterval(timer);
      }
    }, 250);

    window.setTimeout(normalizeExternalLinks, 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
