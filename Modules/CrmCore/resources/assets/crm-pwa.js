(function () {
  if (window.__martinSolsPwaReady) {
    return;
  }

  window.__martinSolsPwaReady = true;

  var installPrompt = null;
  var installButtonId = 'crm-pwa-install-button';
  var installStyleId = 'crm-pwa-install-style';
  var updateReloading = false;
  var hadServiceWorkerController = false;
  var installAllowedPaths = ['/', '/login'];

  function dispatch(name, detail) {
    try {
      window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    } catch (error) {
      // Optional browser event; never block the HUB UI.
    }
  }

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  }

  function currentPath() {
    return window.location.pathname.replace(/\/+$/, '') || '/';
  }

  function isMobileShell() {
    return (
      document.body &&
      (document.body.classList.contains('crm-mobile-app') || document.body.classList.contains('crm-mobile-embed'))
    );
  }

  function isInstallButtonAllowedPath() {
    return installAllowedPaths.indexOf(currentPath()) !== -1;
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) {
      return;
    }

    hadServiceWorkerController = Boolean(navigator.serviceWorker.controller);

    navigator.serviceWorker.addEventListener('controllerchange', reloadForUpdatedWorker);
    navigator.serviceWorker.addEventListener('message', handleServiceWorkerMessage);

    navigator.serviceWorker
      .register('/sw.js', { scope: '/', updateViaCache: 'none' })
      .then(function (registration) {
        watchRegistration(registration);

        if (registration.waiting) {
          registration.waiting.postMessage({ type: 'SKIP_WAITING' });
        }

        registration.update().catch(function () {});

        return navigator.serviceWorker.ready;
      })
      .then(function () {
        dispatch('crm:pwa-ready');
      })
      .catch(function () {
        // PWA installability is optional; the HUB must continue normally.
      });
  }

  function watchRegistration(registration) {
    registration.addEventListener('updatefound', function () {
      var worker = registration.installing;

      if (!worker) {
        return;
      }

      worker.addEventListener('statechange', function () {
        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
          worker.postMessage({ type: 'SKIP_WAITING' });
          dispatch('crm:pwa-update-found');
        }
      });
    });
  }

  function handleServiceWorkerMessage(event) {
    if (!event.data || event.data.type !== 'CRM_SW_ACTIVATED') {
      return;
    }

    dispatch('crm:pwa-updated', { version: event.data.version || null });
  }

  function reloadForUpdatedWorker() {
    if (!hadServiceWorkerController) {
      hadServiceWorkerController = true;
      return;
    }

    if (updateReloading) {
      return;
    }

    updateReloading = true;
    window.setTimeout(function () {
      window.location.reload();
    }, 250);
  }

  function checkForUpdates() {
    if (!('serviceWorker' in navigator) || !navigator.serviceWorker.controller) {
      return;
    }

    navigator.serviceWorker
      .getRegistration('/')
      .then(function (registration) {
        if (registration) {
          registration.update().catch(function () {});
        }
      })
      .catch(function () {});
  }

  function ensureInstallStyle() {
    if (document.getElementById(installStyleId)) {
      return;
    }

    var style = document.createElement('style');
    style.id = installStyleId;
    style.textContent =
      '' +
      '#crm-pwa-install-button{--pwa-primary:rgb(var(--theme-primary));position:fixed;right:18px;bottom:18px;z-index:9999;display:grid;grid-template-columns:3rem 1px minmax(0,1fr) 1.45rem;align-items:center;gap:1rem;width:min(390px,calc(100vw - 32px));min-height:4.45rem;border:1px solid rgb(var(--theme-primary) / .22);border-radius:1.15rem;background:linear-gradient(135deg,#fff 0%,#fff7fb 100%);color:#0f172a;padding:.72rem 1rem .72rem .75rem;font:850 1rem/1.2 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;box-shadow:0 18px 46px rgba(15,23,42,.13);cursor:pointer;opacity:.97;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease,background .18s ease;}' +
      '#crm-pwa-install-button:hover{transform:translateY(-1px);border-color:rgb(var(--theme-primary) / .42);background:linear-gradient(135deg,#fff 0%,#fff1f7 100%);box-shadow:0 22px 54px rgba(15,23,42,.16);opacity:1;}' +
      '#crm-pwa-install-button:focus-visible{outline:3px solid rgb(var(--theme-primary) / .18);outline-offset:3px;}' +
      '#crm-pwa-install-button .crm-pwa-install-icon{display:grid;place-items:center;width:3rem;height:3rem;border-radius:999px;background:rgb(var(--theme-primary) / .1);color:var(--pwa-primary);}' +
      '#crm-pwa-install-button .crm-pwa-install-icon svg,#crm-pwa-install-button .crm-pwa-install-chevron svg{width:1.5rem;height:1.5rem;fill:none;stroke:currentColor;stroke-width:2.25;stroke-linecap:round;stroke-linejoin:round;}' +
      '#crm-pwa-install-button .crm-pwa-install-divider{display:block;width:1px;height:2.25rem;background:rgba(15,23,42,.1);}' +
      '#crm-pwa-install-button .crm-pwa-install-label{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left;letter-spacing:0;}' +
      '#crm-pwa-install-button .crm-pwa-install-chevron{display:grid;place-items:center;color:var(--pwa-primary);}' +
      '@media (max-width: 640px){#crm-pwa-install-button{right:14px;bottom:78px;width:calc(100vw - 28px);grid-template-columns:2.75rem 1px minmax(0,1fr) 1.35rem;gap:.82rem;min-height:4.05rem;border-radius:1rem;padding:.65rem .85rem .65rem .65rem;font-size:.95rem;box-shadow:0 14px 34px rgba(15,23,42,.14);}#crm-pwa-install-button .crm-pwa-install-icon{width:2.75rem;height:2.75rem;}#crm-pwa-install-button .crm-pwa-install-divider{height:2rem;}}';

    document.head.appendChild(style);
  }

  function removeInstallButton() {
    var button = document.getElementById(installButtonId);

    if (button) {
      button.remove();
    }
  }

  function renderInstallButton() {
    if (!installPrompt || isStandalone() || isMobileShell() || !isInstallButtonAllowedPath() || !document.body) {
      removeInstallButton();
      return;
    }

    ensureInstallStyle();

    var button = document.getElementById(installButtonId);

    if (!button) {
      button = document.createElement('button');
      button.id = installButtonId;
      button.type = 'button';
      button.addEventListener('click', promptInstall);
      document.body.appendChild(button);
    }

    button.innerHTML =
      '' +
      '<span class="crm-pwa-install-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 19h14"></path></svg></span>' +
      '<span class="crm-pwa-install-divider" aria-hidden="true"></span>' +
      '<span class="crm-pwa-install-label">Installer l&apos;application</span>' +
      '<span class="crm-pwa-install-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"></path></svg></span>';
    button.title = "Installer l'application";
    button.setAttribute('aria-label', "Installer l'application");
    button.hidden = false;
  }

  function promptInstall() {
    if (!installPrompt) {
      return Promise.resolve(null);
    }

    var promptEvent = installPrompt;
    installPrompt = null;
    removeInstallButton();
    promptEvent.prompt();

    return promptEvent.userChoice
      .then(function (choice) {
        dispatch('crm:pwa-install-choice', choice);
        return choice;
      })
      .catch(function () {
        return null;
      });
  }

  function boot() {
    registerServiceWorker();
    renderInstallButton();
    window.setTimeout(checkForUpdates, 3000);
  }

  function routeChanged() {
    renderInstallButton();
  }

  window.addEventListener('focus', checkForUpdates);
  window.addEventListener('popstate', routeChanged);
  window.addEventListener('crm:navigation', routeChanged);

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      checkForUpdates();
    }
  });

  window.setInterval(checkForUpdates, 15 * 60 * 1000);

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    installPrompt = event;
    renderInstallButton();
    dispatch('crm:pwa-install-available');
  });

  window.addEventListener('appinstalled', function () {
    installPrompt = null;
    removeInstallButton();
    dispatch('crm:pwa-installed');
  });

  window.MartinSolsPwa = {
    canInstall: function () {
      return Boolean(installPrompt);
    },
    install: promptInstall,
    refreshInstallButton: renderInstallButton,
    checkForUpdates: checkForUpdates,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
