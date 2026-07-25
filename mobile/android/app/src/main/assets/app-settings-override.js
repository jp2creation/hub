(function () {
  if (window.MartinSolsAndroidSettingsOverride) {
    return;
  }

  window.MartinSolsAndroidSettingsOverride = true;

  var storageKey = 'martin-sols-native-settings';
  var lastLocation = null;
  var locationLoading = false;
  var quickLoginLoading = false;
  var noticeTimer = null;

  function bridge() {
    return window.MartinSolsNativeApp || null;
  }

  function defaultSettings() {
    return {
      locationEnabled: false,
      highAccuracyLocation: true,
    };
  }

  function readSettings() {
    try {
      return Object.assign(defaultSettings(), JSON.parse(localStorage.getItem(storageKey) || '{}'));
    } catch (error) {
      return defaultSettings();
    }
  }

  var settings = readSettings();

  function writeSettings() {
    try {
      localStorage.setItem(storageKey, JSON.stringify(settings));
    } catch (error) {
      return;
    }
  }

  function icon(name) {
    var icons = {
      back: '<path d="m15 18-6-6 6-6"></path>',
      code: '<path d="M7 8h10M7 12h10M7 16h6"></path><rect x="4" y="4" width="16" height="16" rx="4"></rect>',
      device: '<rect x="7" y="2.75" width="10" height="18.5" rx="2.5"></rect><path d="M10.5 18h3"></path>',
      fingerprint: '<path d="M8.5 11.5a3.5 3.5 0 0 1 7 0c0 2.8-1.1 4.9-2.7 6.7"></path><path d="M6.5 14.8c.3-2.9.2-4.5 1.4-6a5.3 5.3 0 0 1 8.4-.1c1.3 1.7 1.4 3.2 1.1 5.8"></path><path d="M11.5 12c0 3-.7 5-2 6.8M14 12.3c0 2.4-.6 4.1-1.7 5.4"></path>',
      key: '<path d="M15 7a4 4 0 1 1-1.2 2.85L6 17.65V21H2v-4h3.35l7.8-7.8A4 4 0 0 1 15 7Z"></path><path d="M17 7h.01"></path>',
      location: '<path d="M12 21s6-5.2 6-11a6 6 0 1 0-12 0c0 5.8 6 11 6 11Z"></path><circle cx="12" cy="10" r="2"></circle>',
      lock: '<rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path>',
      refresh: '<path d="M20 11a8 8 0 0 0-14.4-4.8L4 8"></path><path d="M4 4v4h4"></path><path d="M4 13a8 8 0 0 0 14.4 4.8L20 16"></path><path d="M20 20v-4h-4"></path>',
      shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"></path><path d="m9 12 2 2 4-4"></path>',
      wifi: '<path d="M5 12.5a10 10 0 0 1 14 0"></path><path d="M8.5 16a5 5 0 0 1 7 0"></path><path d="M12 19h.01"></path>',
    };

    return '<svg viewBox="0 0 24 24" aria-hidden="true">' + (icons[name] || icons.device) + '</svg>';
  }

  function installStyles() {
    if (document.getElementById('martin-sols-native-settings-style')) {
      return;
    }

    var style = document.createElement('style');
    style.id = 'martin-sols-native-settings-style';
    style.textContent = [
      '.ms-native-settings[hidden]{display:none!important}',
      'body.ms-native-settings-open{overflow:hidden!important}',
      '.ms-native-settings{position:fixed;inset:0;z-index:2147483647;overflow:hidden;background:#f4f7fb;color:#1d354f;font-family:"DM Sans",system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.ms-native-settings-panel{width:100%;height:100dvh;min-height:100dvh;overflow:auto;background:#f4f7fb}',
      '.ms-native-settings-header,.ms-native-settings-content{width:min(100%,560px);margin:0 auto}',
      '.ms-native-settings-header{display:grid;gap:18px;padding:max(22px,calc(env(safe-area-inset-top) + 14px)) 24px 14px}',
      '.ms-native-settings-back{display:inline-flex;align-items:center;gap:6px;justify-self:start;min-height:30px;padding:0;border:0;background:transparent;color:#95002e;font:inherit;font-size:.95rem;font-weight:850}',
      '.ms-native-settings-header h2{max-width:12ch;margin:0;color:#1d354f;font-size:1.5rem;font-weight:950;letter-spacing:0;line-height:1.12}',
      '.ms-native-settings-content{display:grid;gap:14px;padding:0 24px max(28px,calc(env(safe-area-inset-bottom) + 18px))}',
      '.ms-native-settings-group{overflow:hidden;border:1px solid rgb(226 232 240 / .76);border-radius:8px;background:#fff;box-shadow:0 10px 24px rgb(15 23 42 / .04)}',
      '.ms-native-settings-group-title{display:flex;align-items:center;gap:8px;padding:14px 18px 4px;color:#6d7f91;font-size:.75rem;font-weight:950;line-height:1;text-transform:uppercase}',
      '.ms-native-settings-group-title svg,.ms-native-settings-row-icon svg,.ms-native-settings-back svg{width:1rem;height:1rem;fill:none;stroke:currentColor;stroke-width:2.15;stroke-linecap:round;stroke-linejoin:round}',
      '.ms-native-settings-back svg{width:1.14rem;height:1.14rem}',
      '.ms-native-settings-row{display:grid;grid-template-columns:34px minmax(0,1fr) auto;align-items:center;gap:12px;min-height:62px;width:100%;padding:11px 18px;border:0;border-top:1px solid rgb(226 232 240 / .82);background:transparent;color:#1d354f;font:inherit;text-align:left}',
      '.ms-native-settings-group-title+.ms-native-settings-row,.ms-native-settings-row:first-child{border-top:0}',
      '.ms-native-settings-row.is-button{cursor:pointer}',
      '.ms-native-settings-row:disabled{cursor:not-allowed;opacity:.48}',
      '.ms-native-settings-row-icon{display:grid;width:34px;height:34px;place-items:center;border-radius:8px;background:rgb(149 0 46 / .08);color:#95002e}',
      '.ms-native-settings-row-icon.is-yellow{background:rgb(245 178 18 / .14);color:#bf8000}',
      '.ms-native-settings-row-icon.is-blue{background:rgb(14 165 233 / .1);color:#0284c7}',
      '.ms-native-settings-row-icon.is-green{background:rgb(34 197 94 / .1);color:#16a34a}',
      '.ms-native-settings-copy{display:grid;min-width:0;gap:3px}',
      '.ms-native-settings-copy strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#1d354f;font-size:.98rem;font-weight:900;line-height:1.2}',
      '.ms-native-settings-copy small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#5d7287;font-size:.78rem;font-weight:720;line-height:1.25}',
      '.ms-native-settings-row em{justify-self:end;color:#6d7f91;font-size:.78rem;font-style:normal;font-weight:850;line-height:1.2;text-align:right;white-space:nowrap}',
      '.ms-native-settings-row.is-button em{color:#95002e;font-size:1.35rem;font-weight:500}',
      '.ms-native-settings-pill{justify-self:end;padding:7px 9px;border-radius:999px;background:#f1f5f9;color:#5d7287;font-size:.72rem;font-weight:900;line-height:1;white-space:nowrap}',
      '.ms-native-settings-pill.is-ready{background:#dcfce7;color:#166534}',
      '.ms-native-settings-pill.is-warn{background:#fff7ed;color:#9a3412}',
      '.ms-native-settings-action{justify-self:end;min-height:32px;padding:7px 11px;border:0;border-radius:8px;background:#95002e;color:#fff;font:inherit;font-size:.76rem;font-weight:900;white-space:nowrap}',
      '.ms-native-settings-switch{cursor:pointer}',
      '.ms-native-settings-switch input{position:absolute;opacity:0;pointer-events:none}',
      '.ms-native-settings-switch i{position:relative;flex:none;width:50px;height:30px;border-radius:999px;background:#cbd5e1;transition:background-color .16s ease}',
      '.ms-native-settings-switch i:after{content:"";position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:999px;background:#fff;box-shadow:0 3px 8px rgb(15 23 42 / .18);transition:transform .16s ease}',
      '.ms-native-settings-switch input:checked+i{background:#95002e}',
      '.ms-native-settings-switch input:checked+i:after{transform:translateX(20px)}',
      '.ms-native-settings-notice{display:none;margin:0;padding:10px 12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-size:.84rem;font-weight:820;line-height:1.35}',
      '.ms-native-settings-notice.is-visible{display:block}',
      '.ms-native-settings-notice.is-error{border-color:#fecaca;background:#fef2f2;color:#b91c1c}',
      '@media (max-width:380px){.ms-native-settings-header,.ms-native-settings-content{padding-right:18px;padding-left:18px}.ms-native-settings-row{grid-template-columns:32px minmax(0,1fr) auto;padding-right:16px;padding-left:16px}.ms-native-settings-header h2{font-size:1.4rem}.ms-native-settings-action{padding-right:9px;padding-left:9px}}',
    ].join('');

    document.head.appendChild(style);
  }

  function ensurePanel() {
    installStyles();

    var panel = document.querySelector('[data-martin-sols-native-settings]');

    if (panel) {
      return panel;
    }

    var wrapper = document.createElement('div');
    wrapper.innerHTML = [
      '<div class="ms-native-settings" data-martin-sols-native-settings hidden>',
      '<section class="ms-native-settings-panel" role="dialog" aria-modal="true" aria-label="Paramètres de l app">',
      '<header class="ms-native-settings-header">',
      '<button class="ms-native-settings-back" type="button" data-ms-native-close aria-label="Fermer les paramètres de l app">' + icon('back') + '<span>Retour</span></button>',
      '<h2>Paramétrage de l’application</h2>',
      '</header>',
      '<div class="ms-native-settings-content">',
      group('', [
        switchRow('Autoriser la localisation', 'Active l’accès GPS dans la WebView', 'data-ms-native-location-enabled'),
        switchRow('Activer la haute précision', 'Utilise le GPS précis si disponible', 'data-ms-native-location-accuracy'),
      ]),
      group('Sécurité', [
        actionRow('Sécurité de l’appareil', 'Empreinte, visage ou code Android', 'shield', 'data-ms-native-device-security', '<span class="ms-native-settings-pill" data-ms-native-device-status>À configurer</span>'),
        actionRow('Code app Martin Sols', 'Code local de 4 à 8 chiffres', 'lock', 'data-ms-native-set-app-code', '<span class="ms-native-settings-action" data-ms-native-set-app-code-action>Définir</span>'),
        staticRow('État du code app', 'Protection locale Martin Sols', 'code', 'data-ms-native-app-code-status', 'Non défini'),
        actionRow('Supprimer le code app', 'Retirer le code local Martin Sols', 'key', 'data-ms-native-clear-app-code', '›'),
      ]),
      group('Connexion rapide', [
        actionRow('Activer la connexion rapide', 'Enregistrer cette session sur ce téléphone', 'fingerprint', 'data-ms-native-enable-auth', '<span class="ms-native-settings-action" data-ms-native-enable-auth-action>Activer</span>'),
        staticRow('Session enregistrée', 'Ouverture sans retaper le mot de passe', 'fingerprint', 'data-ms-native-auth-status', 'Non configurée'),
        actionRow('Supprimer la connexion rapide', 'Effacer la session protégée', 'key', 'data-ms-native-clear-auth', '›'),
      ]),
      group('Localisation terrain', [
        actionRow('Tester la localisation', 'Vérifier l’accès GPS de l’application', 'location', 'data-ms-native-test-location', '<span class="ms-native-settings-pill" data-ms-native-location-status>Désactivée</span>'),
      ]),
      group('Mises à jour', [
        actionRow('Rechercher une mise à jour', 'Contrôle via GitHub', 'refresh', 'data-ms-native-check-update', '›'),
        staticRow('Version installée', 'Martin Sols Android', 'device', 'data-ms-native-app-version', 'App mobile'),
      ]),
      group('Informations', [
        staticRow('Réseau', 'État de connexion actuel', 'wifi', 'data-ms-native-network-status', 'En ligne'),
        staticRow('Plateforme', 'WebView intégrée', 'device', 'data-ms-native-platform', 'Android WebView'),
      ]),
      '<p class="ms-native-settings-notice" data-ms-native-notice></p>',
      '</div>',
      '</section>',
      '</div>',
    ].join('');

    document.body.appendChild(wrapper.firstChild);
    bindPanel();

    return document.querySelector('[data-martin-sols-native-settings]');
  }

  function group(title, rows) {
    var heading = title ? '<div class="ms-native-settings-group-title">' + title + '</div>' : '';

    return '<section class="ms-native-settings-group">' + heading + rows.join('') + '</section>';
  }

  function switchRow(label, description, attr) {
    return '<label class="ms-native-settings-row ms-native-settings-switch"><span class="ms-native-settings-row-icon is-green">' + icon('location') + '</span><span class="ms-native-settings-copy"><strong>' + label + '</strong><small>' + description + '</small></span><input ' + attr + ' type="checkbox"><i aria-hidden="true"></i></label>';
  }

  function actionRow(title, description, iconName, attr, action) {
    var actionHtml = action === '›' ? '<em>›</em>' : action;

    return '<button class="ms-native-settings-row is-button" type="button" ' + attr + '><span class="ms-native-settings-row-icon">' + icon(iconName) + '</span><span class="ms-native-settings-copy"><strong>' + title + '</strong><small>' + description + '</small></span>' + actionHtml + '</button>';
  }

  function staticRow(title, description, iconName, attr, fallback) {
    return '<div class="ms-native-settings-row"><span class="ms-native-settings-row-icon is-blue">' + icon(iconName) + '</span><span class="ms-native-settings-copy"><strong>' + title + '</strong><small>' + description + '</small></span><em ' + attr + '>' + fallback + '</em></div>';
  }

  function query(selector) {
    return document.querySelector(selector);
  }

  function setText(selector, value) {
    var element = query(selector);

    if (element) {
      element.textContent = value;
    }
  }

  function setPill(selector, value, ready, warn) {
    var element = query(selector);

    if (!element) {
      return;
    }

    element.textContent = value;
    element.classList.toggle('is-ready', Boolean(ready));
    element.classList.toggle('is-warn', Boolean(warn));
  }

  function getAuthStatus() {
    try {
      var nativeBridge = bridge();

      if (!nativeBridge) {
        return {};
      }

      return JSON.parse(nativeBridge.getMobileAuthStatus() || '{}');
    } catch (error) {
      return {};
    }
  }

  function authLabel(status) {
    if (status.hasSession) {
      return 'Active';
    }

    if (status.available) {
      return 'À activer';
    }

    return 'Non configurée';
  }

  function locationLabel() {
    if (locationLoading) {
      return 'Recherche...';
    }

    if (lastLocation) {
      return Math.round(lastLocation.accuracy) + ' m';
    }

    return settings.locationEnabled ? 'Activée' : 'Désactivée';
  }

  function appVersionLabel() {
    var nativeBridge = bridge();

    if (!nativeBridge) {
      return 'App mobile';
    }

    try {
      return nativeBridge.getVersionName() + ' (' + nativeBridge.getVersionCode() + ')';
    } catch (error) {
      return 'App mobile';
    }
  }

  function renderPanel() {
    var locationEnabled = query('[data-ms-native-location-enabled]');
    var locationAccuracy = query('[data-ms-native-location-accuracy]');
    var status = getAuthStatus();
    var deviceReady = Boolean(status.deviceSecure);
    var hasAppCode = Boolean(status.appCodeConfigured);
    var hasQuickLogin = Boolean(status.hasSession);

    if (locationEnabled) {
      locationEnabled.checked = settings.locationEnabled;
    }

    if (locationAccuracy) {
      locationAccuracy.checked = settings.highAccuracyLocation;
    }

    setText('[data-ms-native-network-status]', navigator.onLine ? 'En ligne' : 'Hors ligne');
    setText('[data-ms-native-platform]', 'Android WebView');
    setText('[data-ms-native-app-version]', appVersionLabel());
    setText('[data-ms-native-auth-status]', authLabel(status));
    setText('[data-ms-native-app-code-status]', hasAppCode ? 'Défini' : 'Non défini');
    setPill('[data-ms-native-device-status]', deviceReady ? 'Configuré' : 'À configurer', deviceReady, !deviceReady);
    setPill('[data-ms-native-location-status]', locationLabel(), Boolean(lastLocation || settings.locationEnabled), false);
    setButtonDisabled('[data-ms-native-test-location]', locationLoading || !settings.locationEnabled);
    setButtonDisabled('[data-ms-native-enable-auth]', quickLoginLoading || hasQuickLogin || !status.available);
    setButtonDisabled('[data-ms-native-clear-app-code]', !hasAppCode);
    setButtonDisabled('[data-ms-native-clear-auth]', !hasQuickLogin);
    setText('[data-ms-native-set-app-code-action]', hasAppCode ? 'Modifier' : 'Définir');
    setText('[data-ms-native-enable-auth-action]', quickLoginLoading ? 'Activation...' : (hasQuickLogin ? 'Active' : 'Activer'));
  }

  function setButtonDisabled(selector, disabled) {
    var button = query(selector);

    if (button) {
      button.disabled = Boolean(disabled);
    }
  }

  function showNotice(message, isError) {
    var notice = query('[data-ms-native-notice]');

    if (!notice) {
      return;
    }

    if (noticeTimer) {
      window.clearTimeout(noticeTimer);
      noticeTimer = null;
    }

    notice.textContent = message || '';
    notice.classList.toggle('is-error', Boolean(isError));
    notice.classList.toggle('is-visible', Boolean(message));

    if (message && !isError) {
      noticeTimer = window.setTimeout(function () {
        notice.classList.remove('is-visible');
      }, 2600);
    }
  }

  function callNative(methodName, feedback) {
    var nativeBridge = bridge();
    var result;

    if (!nativeBridge) {
      showNotice('Action native indisponible. Ferme puis rouvre le HUB.', true);
      return false;
    }

    if (!nativeBridge[methodName]) {
      showNotice('Action native indisponible dans cette version de l’app.', true);
      return false;
    }

    try {
      result = nativeBridge[methodName]();
      result = nativeResult(result);

      if (result && result.ok === false) {
        showNotice(result.message || 'Action Android refusée.', true);

        return false;
      }

      showNotice(result && result.message ? result.message : feedback || 'Action envoyée à Android.', false);
      window.setTimeout(renderPanel, 450);
      window.setTimeout(renderPanel, 1200);

      return true;
    } catch (error) {
      showNotice('Action impossible : ' + (error && error.message ? error.message : 'réessaie après redémarrage de l’app.'), true);

      return false;
    }
  }

  function nativeResult(value) {
    if (!value) {
      return null;
    }

    if (typeof value === 'object') {
      return value;
    }

    try {
      return JSON.parse(String(value));
    } catch (error) {
      return null;
    }
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.content || '' : '';
  }

  function nativeDeviceName() {
    var nativeBridge = bridge();

    if (!nativeBridge || !nativeBridge.getVersionName) {
      return 'Martin Sols Android';
    }

    try {
      return 'Martin Sols Android ' + nativeBridge.getVersionName();
    } catch (error) {
      return 'Martin Sols Android';
    }
  }

  function saveNativeSession(session) {
    var nativeBridge = bridge();

    if (!nativeBridge || !nativeBridge.saveMobileSession) {
      throw new Error('Stockage natif indisponible.');
    }

    var result = nativeResult(nativeBridge.saveMobileSession(JSON.stringify(session)));

    if (result && result.ok === false) {
      throw new Error(result.error || result.message || 'Connexion rapide refusee.');
    }
  }

  function createNativeSession() {
    var headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    };
    var token = csrfToken();

    if (token) {
      headers['X-CSRF-TOKEN'] = token;
    }

    return fetch('/api/mobile/native-session', {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify({
        device_name: nativeDeviceName(),
      }),
    }).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (payload) {
        if (!response.ok || payload.ok !== true || !payload.token || !payload.refreshToken) {
          throw new Error(payload.error || 'Session rapide impossible.');
        }

        return payload;
      });
    });
  }

  function enableQuickLogin() {
    var status = getAuthStatus();

    if (!status.available) {
      showNotice('Configure d’abord un code app ou la sécurité Android.', true);
      return;
    }

    quickLoginLoading = true;
    showNotice('Activation de la connexion rapide...', false);
    renderPanel();

    createNativeSession().then(function (session) {
      saveNativeSession(session);
      quickLoginLoading = false;
      showNotice('Connexion rapide activée sur ce téléphone.', false);
      renderPanel();
    }).catch(function (error) {
      quickLoginLoading = false;
      showNotice(error && error.message ? error.message : 'Connexion rapide impossible.', true);
      renderPanel();
    });
  }

  function requestNativeLocation() {
    var nativeBridge = bridge();
    var requestId = 'location-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    var timeout;

    if (!nativeBridge || !nativeBridge.requestLocation) {
      return false;
    }

    function cleanup() {
      window.clearTimeout(timeout);
      window.removeEventListener('martin-sols:native-location-result', onResult);
    }

    function onResult(event) {
      var detail = event.detail || {};

      if (detail.requestId !== requestId) {
        return;
      }

      cleanup();
      locationLoading = false;

      if (detail.ok === true && detail.location) {
        lastLocation = {
          accuracy: Number(detail.location.accuracy) || 0,
          latitude: Number(detail.location.latitude) || 0,
          longitude: Number(detail.location.longitude) || 0,
          timestamp: Number(detail.location.timestamp) || Date.now(),
        };
        showNotice('Localisation récupérée : précision ' + Math.round(lastLocation.accuracy) + ' m.', false);
        renderPanel();
        return;
      }

      showNotice(detail.error || 'Localisation Android indisponible.', true);
      renderPanel();
    }

    locationLoading = true;
    showNotice('Recherche de la position...', false);
    renderPanel();

    window.addEventListener('martin-sols:native-location-result', onResult);
    timeout = window.setTimeout(function () {
      cleanup();
      locationLoading = false;
      showNotice('Localisation trop longue. Vérifie que le GPS Android est actif.', true);
      renderPanel();
    }, 17000);

    try {
      var result = nativeResult(nativeBridge.requestLocation(requestId, Boolean(settings.highAccuracyLocation)));

      if (result && result.ok === false) {
        cleanup();
        locationLoading = false;
        showNotice(result.message || 'Localisation Android refusée.', true);
        renderPanel();
      }
    } catch (error) {
      cleanup();
      locationLoading = false;
      showNotice(error && error.message ? error.message : 'Localisation Android indisponible.', true);
      renderPanel();
    }

    return true;
  }

  function requestLocation() {
    if (requestNativeLocation()) {
      return;
    }

    if (!navigator.geolocation) {
      showNotice('Localisation indisponible sur ce téléphone.', true);
      return;
    }

    locationLoading = true;
    showNotice('Recherche de la position...', false);
    renderPanel();

    navigator.geolocation.getCurrentPosition(function (position) {
      lastLocation = {
        accuracy: position.coords.accuracy,
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
      };
      locationLoading = false;
      showNotice('Localisation récupérée : précision ' + Math.round(lastLocation.accuracy) + ' m.', false);
      renderPanel();
    }, function (error) {
      locationLoading = false;
      showNotice(error.message || 'Localisation indisponible.', true);
      renderPanel();
    }, {
      enableHighAccuracy: settings.highAccuracyLocation,
      maximumAge: 60000,
      timeout: 12000,
    });
  }

  function bindPanel() {
    var locationEnabled = query('[data-ms-native-location-enabled]');
    var locationAccuracy = query('[data-ms-native-location-accuracy]');
    var closeButton = query('[data-ms-native-close]');
    var testLocationButton = query('[data-ms-native-test-location]');
    var checkUpdateButton = query('[data-ms-native-check-update]');
    var deviceSecurityButton = query('[data-ms-native-device-security]');
    var enableAuthButton = query('[data-ms-native-enable-auth]');
    var setAppCodeButton = query('[data-ms-native-set-app-code]');
    var clearAppCodeButton = query('[data-ms-native-clear-app-code]');
    var clearAuthButton = query('[data-ms-native-clear-auth]');

    if (closeButton) {
      closeButton.addEventListener('click', closePanel);
    }

    if (testLocationButton) {
      testLocationButton.addEventListener('click', requestLocation);
    }

    if (checkUpdateButton) {
      checkUpdateButton.addEventListener('click', function () {
        callNative('checkForUpdates', 'Recherche de mise à jour lancée.');
      });
    }

    if (deviceSecurityButton) {
      deviceSecurityButton.addEventListener('click', function () {
        callNative('openDeviceSecuritySettings', 'Ouverture des réglages de sécurité Android.');
      });
    }

    if (setAppCodeButton) {
      setAppCodeButton.addEventListener('click', function () {
        callNative('setAppCode', 'Ouverture du code app Martin Sols.');
      });
    }

    if (enableAuthButton) {
      enableAuthButton.addEventListener('click', enableQuickLogin);
    }

    if (clearAppCodeButton) {
      clearAppCodeButton.addEventListener('click', function () {
        callNative('clearAppCode', 'Code app supprimé.');
        renderPanel();
      });
    }

    if (clearAuthButton) {
      clearAuthButton.addEventListener('click', function () {
        callNative('clearMobileSession', 'Connexion rapide supprimée.');
        renderPanel();
      });
    }

    if (locationEnabled) {
      locationEnabled.addEventListener('change', function () {
        settings.locationEnabled = Boolean(locationEnabled.checked);
        writeSettings();

        if (settings.locationEnabled) {
          requestLocation();
          return;
        }

        lastLocation = null;
        showNotice('Localisation désactivée.', false);
        renderPanel();
      });
    }

    if (locationAccuracy) {
      locationAccuracy.addEventListener('change', function () {
        settings.highAccuracyLocation = Boolean(locationAccuracy.checked);
        writeSettings();
        showNotice(settings.highAccuracyLocation ? 'Haute précision activée.' : 'Haute précision désactivée.', false);
        renderPanel();
      });
    }
  }

  function openPanel() {
    var panel = ensurePanel();

    if (!panel) {
      return;
    }

    panel.hidden = false;
    document.body.classList.add('ms-native-settings-open');
    showNotice('', false);
    renderPanel();
  }

  function closePanel() {
    var panel = query('[data-martin-sols-native-settings]');

    if (panel) {
      panel.hidden = true;
    }

    document.body.classList.remove('ms-native-settings-open');
  }

  document.addEventListener('click', function (event) {
    var target = event.target;

    if (!target || !target.closest) {
      return;
    }

    if (!target.closest('[data-crm-mobile-settings-toggle]')) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    openPanel();
  }, true);

  document.addEventListener('keydown', function (event) {
    var panel = query('[data-martin-sols-native-settings]');

    if (event.key === 'Escape' && panel && !panel.hidden) {
      closePanel();
    }
  });

  window.addEventListener('online', renderPanel);
  window.addEventListener('offline', renderPanel);
  window.addEventListener('martin-sols:native-auth-status-changed', renderPanel);
})();
