type MobileLocation = {
  accuracy: number;
  latitude: number;
  longitude: number;
  updatedAt: string;
};

type MobileSettings = {
  highAccuracyLocation: boolean;
  locationEnabled: boolean;
};

type MobileAuthStatus = {
  appCodeConfigured?: boolean;
  available?: boolean;
  configured?: boolean;
  deviceSecure?: boolean;
  hasSession?: boolean;
  label?: string;
  message?: string;
  ok?: boolean;
};

type NativeAppBridge = {
  clearMobileSession?: () => NativeBridgeResponse;
  authenticateSavedMobileSession?: (requestId: string) => NativeBridgeResponse;
  clearAppCode?: () => NativeBridgeResponse;
  checkForUpdates?: () => NativeBridgeResponse;
  getPlatformName?: () => string;
  getMobileAuthStatus?: () => string;
  getVersionCode?: () => string;
  getVersionName?: () => string;
  openDeviceSecuritySettings?: () => NativeBridgeResponse;
  requestLocation?: (requestId: string, highAccuracy: boolean) => NativeBridgeResponse;
  saveMobileSession?: (payload: string) => NativeBridgeResponse;
  setAppCode?: () => NativeBridgeResponse;
};

type NativeActionResult = { error?: string; message?: string; ok?: boolean };
type NativeBridgeResponse = NativeActionResult | Promise<NativeActionResult | string | null | undefined> | string | null | undefined;

const storageKey = 'martin-sols.crm.mobile-app.settings';
let settingsInstalled = false;

function readSettings(): MobileSettings {
  try {
    const stored = JSON.parse(localStorage.getItem(storageKey) || '{}') as Partial<MobileSettings>;

    return {
      highAccuracyLocation: Boolean(stored.highAccuracyLocation),
      locationEnabled: Boolean(stored.locationEnabled),
    };
  } catch {
    return {
      highAccuracyLocation: false,
      locationEnabled: false,
    };
  }
}

function dispatchLocation(settings: MobileSettings, lastLocation: MobileLocation | null): void {
  window.dispatchEvent(
    new CustomEvent('martin-sols:mobile-location', {
      detail: {
        enabled: settings.locationEnabled,
        last: lastLocation,
      },
    }),
  );
}

function isMobileApp(): boolean {
  return (
    window.MartinSolsCrmConfig?.mobile.app === true ||
    Boolean(window.MartinSolsNativeApp) ||
    document.body.classList.contains('crm-mobile-app')
  );
}

function nativeBridge(): NativeAppBridge | undefined {
  return window.MartinSolsNativeApp;
}

function appVersionLabel(): string {
  try {
    const versionName = nativeBridge()?.getVersionName?.() || '';
    const versionCode = nativeBridge()?.getVersionCode?.() || '';

    if (versionName && versionCode) {
      return `${versionName} (${versionCode})`;
    }

    return versionName || 'App mobile';
  } catch {
    return 'App mobile';
  }
}

function mobileAuthStatus(): MobileAuthStatus {
  try {
    const rawStatus = nativeBridge()?.getMobileAuthStatus?.();

    return rawStatus ? (JSON.parse(rawStatus) as MobileAuthStatus) : {};
  } catch {
    return {};
  }
}

function mobileAuthLabel(status: MobileAuthStatus): string {
  if (status.hasSession) {
    return 'Active';
  }

  if (status.available) {
    return 'À activer';
  }

  return 'Non configurée';
}

function platformLabel(): string {
  const userAgent = navigator.userAgent || '';
  const platform = navigator.platform || '';

  if (/Android/i.test(userAgent)) {
    return 'Android WebView';
  }

  if (/iPhone|iPod/i.test(userAgent)) {
    return 'iPhone';
  }

  if (/iPad/i.test(userAgent) || (platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
    return 'iPad';
  }

  if (/Macintosh|Mac OS X|MacIntel/i.test(`${userAgent} ${platform}`)) {
    return 'macOS';
  }

  return 'Application web';
}

function isIosPlatform(label: string): boolean {
  return label === 'iPhone' || label === 'iPad';
}

function nativePlatformName(): string {
  try {
    const bridgePlatform = nativeBridge()?.getPlatformName?.();

    if (bridgePlatform) {
      return bridgePlatform;
    }
  } catch {
    return platformLabel();
  }

  const label = platformLabel();

  return label === 'Android WebView' ? 'Android' : label;
}

function nativeSecurityName(): string {
  const label = nativePlatformName();

  if (isIosPlatform(label)) {
    return 'iOS';
  }

  if (label === 'macOS') {
    return 'macOS';
  }

  return 'Android';
}

function deviceSecuritySettingsMessage(): string {
  const securityName = nativeSecurityName();

  if (securityName === 'iOS') {
    return 'Ouverture des réglages iOS.';
  }

  if (securityName === 'macOS') {
    return 'Ouverture des réglages de sécurité macOS.';
  }

  return 'Ouverture des réglages de sécurité Android.';
}

function settingsIcon(name: string): string {
  const icons: Record<string, string> = {
    back: '<path d="m15 18-6-6 6-6"></path>',
    code: '<path d="M7 8h10M7 12h10M7 16h6"></path><rect x="4" y="4" width="16" height="16" rx="4"></rect>',
    device: '<rect x="7" y="2.75" width="10" height="18.5" rx="2.5"></rect><path d="M10.5 18h3"></path>',
    face: '<circle cx="12" cy="12" r="8"></circle><path d="M9 10h.01M15 10h.01M9.5 15a4 4 0 0 0 5 0"></path>',
    fingerprint:
      '<path d="M8.5 11.5a3.5 3.5 0 0 1 7 0c0 2.8-1.1 4.9-2.7 6.7"></path><path d="M6.5 14.8c.3-2.9.2-4.5 1.4-6a5.3 5.3 0 0 1 8.4-.1c1.3 1.7 1.4 3.2 1.1 5.8"></path><path d="M11.5 12c0 3-.7 5-2 6.8M14 12.3c0 2.4-.6 4.1-1.7 5.4"></path>',
    key: '<path d="M15 7a4 4 0 1 1-1.2 2.85L6 17.65V21H2v-4h3.35l7.8-7.8A4 4 0 0 1 15 7Z"></path><path d="M17 7h.01"></path>',
    location:
      '<path d="M12 21s6-5.2 6-11a6 6 0 1 0-12 0c0 5.8 6 11 6 11Z"></path><circle cx="12" cy="10" r="2"></circle>',
    lock: '<rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path>',
    refresh:
      '<path d="M20 11a8 8 0 0 0-14.4-4.8L4 8"></path><path d="M4 4v4h4"></path><path d="M4 13a8 8 0 0 0 14.4 4.8L20 16"></path><path d="M20 20v-4h-4"></path>',
    satellite:
      '<path d="M12 18a6 6 0 0 0 0-12"></path><path d="M16.2 20.2a10 10 0 0 0 0-16.4"></path><path d="M8 9.5 4.5 13 8 16.5 11.5 13 8 9.5Z"></path><path d="m10.5 14.5 2 2"></path>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"></path><path d="m9 12 2 2 4-4"></path>',
    wifi: '<path d="M5 12.5a10 10 0 0 1 14 0"></path><path d="M8.5 16a5 5 0 0 1 7 0"></path><path d="M12 19h.01"></path>',
  };

  return `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[name] || icons.device}</svg>`;
}

function settingsGroup(title: string, rows: string[]): string {
  const heading = title ? `<div class="crm-mobile-app-settings-group-title">${title}</div>` : '';

  return `<section class="crm-mobile-app-settings-group">${heading}${rows.join('')}</section>`;
}

function settingsSwitchRow(label: string, description: string, icon: string, attr: string): string {
  return `
    <label class="crm-mobile-app-settings-row crm-mobile-app-settings-switch">
      <span class="crm-mobile-app-settings-row-icon is-green">${settingsIcon(icon)}</span>
      <span class="crm-mobile-app-settings-row-copy">
        <strong>${label}</strong>
        <small>${description}</small>
      </span>
      <input ${attr} type="checkbox">
      <i aria-hidden="true"></i>
    </label>
  `;
}

function settingsActionRow(title: string, description: string, icon: string, attr: string, action: string): string {
  const actionHtml = action === '›' ? '<em>›</em>' : action;

  return `
    <button class="crm-mobile-app-settings-row is-button" type="button" ${attr}>
      <span class="crm-mobile-app-settings-row-icon">${settingsIcon(icon)}</span>
      <span class="crm-mobile-app-settings-row-copy">
        <strong>${title}</strong>
        <small>${description}</small>
      </span>
      ${actionHtml}
    </button>
  `;
}

function settingsStaticRow(title: string, description: string, icon: string, attr: string, fallback: string): string {
  const statusAttr = attr ? ` ${attr}` : '';

  return `
    <div class="crm-mobile-app-settings-row is-static">
      <span class="crm-mobile-app-settings-row-icon is-blue">${settingsIcon(icon)}</span>
      <span class="crm-mobile-app-settings-row-copy">
        <strong>${title}</strong>
        <small>${description}</small>
      </span>
      <em${statusAttr}>${fallback}</em>
    </div>
  `;
}

function mountSettingsMarkup(): boolean {
  if (!isMobileApp()) {
    return false;
  }

  if (document.querySelector('[data-crm-mobile-settings]')) {
    return true;
  }

  const wrapper = document.createElement('div');

  wrapper.innerHTML = `
    <div class="crm-mobile-app-settings" data-crm-mobile-settings hidden>
      <section class="crm-mobile-app-settings-panel" role="dialog" aria-modal="true" aria-label="Paramètres de l'app">
        <header class="crm-mobile-app-settings-header">
          <button class="crm-mobile-app-settings-back" type="button" data-crm-mobile-settings-close aria-label="Fermer les paramètres de l'app">
            ${settingsIcon('back')}
            <span>Retour</span>
          </button>
          <h2>Paramétrage de l’application</h2>
        </header>

        <div class="crm-mobile-app-settings-content">
          ${settingsGroup('', [
            settingsSwitchRow('Autoriser la localisation', 'Active l’accès GPS dans la WebView', 'location', 'data-crm-mobile-location-enabled'),
            settingsSwitchRow('Activer la haute précision', 'Utilise le GPS précis si disponible', 'satellite', 'data-crm-mobile-location-accuracy'),
          ])}

          ${settingsGroup('Sécurité', [
            settingsActionRow(
              'Sécurité de l’appareil',
              'Empreinte, visage ou code appareil',
              'shield',
              'data-crm-mobile-device-security',
              '<span class="crm-mobile-app-settings-pill" data-crm-mobile-auth-summary>À configurer</span>',
            ),
            settingsActionRow(
              'Code app Martin Sols',
              'Code local de 4 à 8 chiffres',
              'lock',
              'data-crm-mobile-set-app-code',
              '<span class="crm-mobile-app-settings-mini-action" data-crm-mobile-set-app-code-label>Définir</span>',
            ),
            settingsStaticRow('État du code app', 'Protection locale Martin Sols', 'code', 'data-crm-mobile-app-code-status', 'Non défini'),
            settingsActionRow('Supprimer le code app', 'Retirer le code local Martin Sols', 'key', 'data-crm-mobile-clear-app-code', '›'),
          ])}

          ${settingsGroup('Connexion rapide', [
            settingsActionRow(
              'Activer la connexion rapide',
              'Enregistrer cette session sur cet appareil',
              'fingerprint',
              'data-crm-mobile-enable-auth',
              '<span class="crm-mobile-app-settings-mini-action" data-crm-mobile-enable-auth-label>Activer</span>',
            ),
            settingsStaticRow('Session enregistrée', 'Ouverture sans retaper le mot de passe', 'fingerprint', 'data-crm-mobile-auth-status', 'Non configurée'),
            settingsActionRow('Supprimer la connexion rapide', 'Effacer la session protégée', 'key', 'data-crm-mobile-clear-auth', '›'),
          ])}

          ${settingsGroup('Localisation terrain', [
            settingsActionRow(
              'Tester la localisation',
              'Vérifier l’accès GPS de l’application',
              'location',
              'data-crm-mobile-test-location',
              '<span class="crm-mobile-app-settings-pill" data-crm-mobile-location-status>Désactivée</span>',
            ),
          ])}

          ${settingsGroup('Mises à jour', [
            settingsActionRow('Rechercher une mise à jour', 'Vérifier la version disponible sur GitHub', 'refresh', 'data-crm-mobile-check-update', '›'),
            settingsStaticRow('Version installée', 'Martin Sols', 'device', 'data-crm-mobile-app-version', 'App mobile'),
          ])}

          ${settingsGroup('Informations', [
            settingsStaticRow('Réseau', 'État de connexion actuel', 'wifi', 'data-crm-mobile-network-status', 'En ligne'),
            settingsStaticRow('Plateforme', 'WebView intégrée', 'device', 'data-crm-mobile-platform', 'Application mobile'),
            settingsStaticRow('Réglages à venir', 'Les futurs paramètres seront ajoutés ici', 'device', '', 'Prévu'),
          ])}

          <p class="crm-mobile-app-settings-error" data-crm-mobile-settings-error></p>
        </div>
      </section>
    </div>
  `;

  document.body.append(...Array.from(wrapper.childNodes));

  return true;
}

export function installMobileAppSettings(): void {
  if (!mountSettingsMarkup()) {
    return;
  }

  const modal = document.querySelector<HTMLElement>('[data-crm-mobile-settings]');
  const closeButtons = document.querySelectorAll<HTMLButtonElement>('[data-crm-mobile-settings-close]');
  const locationEnabled = document.querySelector<HTMLInputElement>('[data-crm-mobile-location-enabled]');
  const locationAccuracy = document.querySelector<HTMLInputElement>('[data-crm-mobile-location-accuracy]');
  const networkStatus = document.querySelector<HTMLElement>('[data-crm-mobile-network-status]');
  const locationStatus = document.querySelector<HTMLElement>('[data-crm-mobile-location-status]');
  const platformStatus = document.querySelector<HTMLElement>('[data-crm-mobile-platform]');
  const appVersion = document.querySelector<HTMLElement>('[data-crm-mobile-app-version]');
  const authStatus = document.querySelector<HTMLElement>('[data-crm-mobile-auth-status]');
  const appCodeStatus = document.querySelector<HTMLElement>('[data-crm-mobile-app-code-status]');
  const authSummary = document.querySelector<HTMLElement>('[data-crm-mobile-auth-summary]');
  const errorBox = document.querySelector<HTMLElement>('[data-crm-mobile-settings-error]');
  const testLocation = document.querySelector<HTMLButtonElement>('[data-crm-mobile-test-location]');
  const checkUpdate = document.querySelector<HTMLButtonElement>('[data-crm-mobile-check-update]');
  const enableAuth = document.querySelector<HTMLButtonElement>('[data-crm-mobile-enable-auth]');
  const enableAuthLabel = document.querySelector<HTMLElement>('[data-crm-mobile-enable-auth-label]');
  const clearAuth = document.querySelector<HTMLButtonElement>('[data-crm-mobile-clear-auth]');
  const deviceSecurity = document.querySelector<HTMLButtonElement>('[data-crm-mobile-device-security]');
  const setAppCode = document.querySelector<HTMLButtonElement>('[data-crm-mobile-set-app-code]');
  const setAppCodeLabel = document.querySelector<HTMLElement>('[data-crm-mobile-set-app-code-label]');
  const clearAppCode = document.querySelector<HTMLButtonElement>('[data-crm-mobile-clear-app-code]');

  if (!modal || !locationEnabled || !locationAccuracy) {
    return;
  }

  let lastLocation: MobileLocation | null = null;
  let locationLoading = false;
  let quickLoginLoading = false;
  const settings = readSettings();

  const writeSettings = () => {
    localStorage.setItem(storageKey, JSON.stringify(settings));
  };

  const showNotice = (message: string, isError = false) => {
    if (!errorBox) {
      return;
    }

    errorBox.textContent = message || '';
    errorBox.classList.toggle('is-error', Boolean(isError));
    errorBox.classList.toggle('is-visible', Boolean(message));
  };

  const locationLabel = () => {
    if (locationLoading) {
      return 'Recherche...';
    }

    if (lastLocation) {
      return [
        lastLocation.latitude.toFixed(5),
        lastLocation.longitude.toFixed(5),
        `${Math.round(lastLocation.accuracy)} m`,
      ].join(' / ');
    }

    return settings.locationEnabled ? 'Activée' : 'Désactivée';
  };

  const renderSettings = () => {
    locationEnabled.checked = settings.locationEnabled;
    locationAccuracy.checked = settings.highAccuracyLocation;

    if (networkStatus) {
      networkStatus.textContent = navigator.onLine ? 'En ligne' : 'Hors ligne';
    }

    if (locationStatus) {
      locationStatus.textContent = locationLabel();
    }

    const currentPlatformLabel = platformLabel();

    if (platformStatus) {
      platformStatus.textContent = currentPlatformLabel;
    }

    if (appVersion) {
      appVersion.textContent = appVersionLabel();
    }

    const currentAuthStatus = mobileAuthStatus();
    const isDeviceSecurityReady = Boolean(currentAuthStatus.deviceSecure);
    const hasAppCode = Boolean(currentAuthStatus.appCodeConfigured);
    const hasQuickLogin = Boolean(currentAuthStatus.hasSession);
    if (authStatus) {
      authStatus.textContent = mobileAuthLabel(currentAuthStatus);
    }

    if (appCodeStatus) {
      appCodeStatus.textContent = hasAppCode ? 'Défini' : 'Non défini';
    }

    if (authSummary) {
      const hasLocalProtection = hasAppCode || isDeviceSecurityReady;
      authSummary.textContent = hasLocalProtection ? 'Configuré' : 'À configurer';
      authSummary.classList.toggle('is-ready', hasLocalProtection);
      authSummary.classList.toggle('is-warn', !hasLocalProtection);
    }

    if (testLocation) {
      testLocation.disabled = locationLoading || !settings.locationEnabled;
    }

    if (clearAuth) {
      clearAuth.disabled = !currentAuthStatus.hasSession;
    }

    if (enableAuth) {
      enableAuth.disabled = quickLoginLoading || hasQuickLogin || !currentAuthStatus.available || !nativeBridge()?.saveMobileSession;
    }

    if (enableAuthLabel) {
      enableAuthLabel.textContent = quickLoginLoading ? 'Activation...' : hasQuickLogin ? 'Active' : 'Activer';
    }

    if (deviceSecurity) {
      deviceSecurity.disabled = !nativeBridge()?.openDeviceSecuritySettings;
    }

    if (setAppCode) {
      setAppCode.disabled = !nativeBridge()?.setAppCode;
    }

    if (setAppCodeLabel) {
      setAppCodeLabel.textContent = hasAppCode ? 'Modifier' : 'Définir';
    }

    if (clearAppCode) {
      clearAppCode.disabled = !hasAppCode || !nativeBridge()?.clearAppCode;
    }
  };

  const publishLocation = () => dispatchLocation(settings, lastLocation);

  const nativeResult = async (value: NativeBridgeResponse): Promise<NativeActionResult | null> => {
    const resolved = await Promise.resolve(value);

    if (!resolved) {
      return null;
    }

    if (typeof resolved === 'object') {
      return resolved as NativeActionResult;
    }

    try {
      return JSON.parse(String(resolved)) as NativeActionResult;
    } catch {
      return null;
    }
  };

  const csrfToken = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

  const nativeDeviceName = () => {
    try {
      return `Martin Sols ${nativePlatformName()} ${nativeBridge()?.getVersionName?.() || ''}`.trim();
    } catch {
      return `Martin Sols ${nativePlatformName()}`;
    }
  };

  const createNativeSession = async (): Promise<Record<string, unknown>> => {
    const token = csrfToken();
    const headers = new Headers({
      Accept: 'application/json',
      'Content-Type': 'application/json',
    });

    if (token) {
      headers.set('X-CSRF-TOKEN', token);
    }

    const response = await fetch('/api/mobile/native-session', {
      method: 'POST',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify({
        device_name: nativeDeviceName(),
      }),
    });
    const payload = (await response.json().catch(() => ({}))) as Record<string, unknown>;

    if (!response.ok || payload.ok !== true || !payload.token || !payload.refreshToken) {
      throw new Error(typeof payload.error === 'string' ? payload.error : 'Session rapide impossible.');
    }

    return payload;
  };

  const saveNativeSession = async (session: Record<string, unknown>) => {
    const result = await nativeResult(nativeBridge()?.saveMobileSession?.(JSON.stringify(session)));

    if (result?.ok === false) {
      throw new Error(result.error || result.message || 'Connexion rapide refusée.');
    }
  };

  const enableQuickLogin = async () => {
    const currentAuthStatus = mobileAuthStatus();

    if (!currentAuthStatus.available) {
      showNotice(`Configure d’abord un code app ou la sécurité ${nativeSecurityName()}.`, true);
      return;
    }

    quickLoginLoading = true;
    showNotice('');
    renderSettings();

    try {
      await saveNativeSession(await createNativeSession());
      quickLoginLoading = false;
      showNotice('Connexion rapide activée sur cet appareil.');
      renderSettings();
    } catch (error) {
      quickLoginLoading = false;
      showNotice(error instanceof Error ? error.message : 'Connexion rapide impossible.', true);
      renderSettings();
    }
  };

  const requestNativeLocation = (): boolean => {
    const requestId = `location-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const native = nativeBridge();

    if (!native?.requestLocation) {
      return false;
    }

    let timeout = 0;

    const onResult = (event: Event): void => {
      const detail = ((event as CustomEvent).detail || {}) as {
        error?: string;
        location?: { accuracy?: number; latitude?: number; longitude?: number; timestamp?: number };
        ok?: boolean;
        requestId?: string;
      };

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
          updatedAt: new Date(Number(detail.location.timestamp) || Date.now()).toISOString(),
        };
        publishLocation();
        renderSettings();
        return;
      }

      showNotice(detail.error || `Localisation ${nativeSecurityName()} indisponible.`, true);
      renderSettings();
    };

    function cleanup(): void {
      window.clearTimeout(timeout);
      window.removeEventListener('martin-sols:native-location-result', onResult);
    }

    locationLoading = true;
    showNotice('');
    renderSettings();
    window.addEventListener('martin-sols:native-location-result', onResult);
    timeout = window.setTimeout(() => {
      cleanup();
      locationLoading = false;
      showNotice(`Localisation trop longue. Vérifiez que le GPS ${nativeSecurityName()} est actif.`, true);
      renderSettings();
    }, 17000);

    void nativeResult(native.requestLocation(requestId, settings.highAccuracyLocation))
      .then((result) => {
        if (result?.ok !== false) {
          return;
        }

        cleanup();
        locationLoading = false;
        showNotice(result.message || `Localisation ${nativeSecurityName()} refusée.`, true);
        renderSettings();
      })
      .catch(() => {
        cleanup();
        locationLoading = false;
        showNotice(`Localisation ${nativeSecurityName()} indisponible.`, true);
        renderSettings();
      });

    return true;
  };

  const requestLocation = () => {
    if (requestNativeLocation()) {
      return;
    }

    if (!navigator.geolocation) {
      showNotice('Localisation indisponible sur ce navigateur.', true);
      return;
    }

    locationLoading = true;
    showNotice('');
    renderSettings();

    navigator.geolocation.getCurrentPosition(
      (position) => {
        lastLocation = {
          accuracy: position.coords.accuracy,
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          updatedAt: new Date(position.timestamp).toISOString(),
        };
        locationLoading = false;
        publishLocation();
        showNotice(`Localisation récupérée : précision ${Math.round(lastLocation.accuracy)} m.`);
        renderSettings();
      },
      (error) => {
        locationLoading = false;
        showNotice(error.message || 'Localisation indisponible.', true);
        renderSettings();
      },
      {
        enableHighAccuracy: settings.highAccuracyLocation,
        maximumAge: 60000,
        timeout: 12000,
      },
    );
  };

  const openSettings = () => {
    modal.hidden = false;
    document.body.classList.add('crm-mobile-app-settings-open');
    showNotice('');
    renderSettings();
  };

  const closeSettings = () => {
    modal.hidden = true;
    document.body.classList.remove('crm-mobile-app-settings-open');
  };

  const runNativeAction = async (action: () => NativeBridgeResponse, successMessage: string) => {
    try {
      const result = await nativeResult(action());

      if (result?.ok === false) {
        showNotice(result.error || result.message || 'Action native indisponible.', true);
        return false;
      }

      showNotice(result?.message || successMessage);
      window.setTimeout(renderSettings, 350);
      window.setTimeout(renderSettings, 1000);

      return true;
    } catch {
      showNotice('Action native indisponible dans cette version de l’app.', true);

      return false;
    }
  };

  const requestUpdateCheck = () => {
    showNotice('');

    if (!nativeBridge()?.checkForUpdates) {
      showNotice('Recherche de mise à jour disponible uniquement dans l’app installée.', true);
      return;
    }

    void runNativeAction(() => nativeBridge()?.checkForUpdates?.(), 'Recherche de mise à jour lancée.');
  };

  closeButtons.forEach((button) => {
    button.addEventListener('click', closeSettings);
  });

  locationEnabled.addEventListener('change', () => {
    settings.locationEnabled = locationEnabled.checked;
    writeSettings();
    renderSettings();

    if (settings.locationEnabled) {
      requestLocation();
      return;
    }

    lastLocation = null;
    publishLocation();
    showNotice('Localisation désactivée.');
  });

  locationAccuracy.addEventListener('change', () => {
    settings.highAccuracyLocation = locationAccuracy.checked;
    writeSettings();
    showNotice(settings.highAccuracyLocation ? 'Haute précision activée.' : 'Haute précision désactivée.');
    renderSettings();
  });

  testLocation?.addEventListener('click', requestLocation);
  checkUpdate?.addEventListener('click', requestUpdateCheck);
  enableAuth?.addEventListener('click', () => {
    void enableQuickLogin();
  });
  deviceSecurity?.addEventListener('click', () => {
    void runNativeAction(() => nativeBridge()?.openDeviceSecuritySettings?.(), deviceSecuritySettingsMessage());
  });
  setAppCode?.addEventListener('click', () => {
    void runNativeAction(() => nativeBridge()?.setAppCode?.(), 'Code app Martin Sols enregistré.');
  });
  clearAppCode?.addEventListener('click', () => {
    void runNativeAction(() => nativeBridge()?.clearAppCode?.(), 'Code app supprimé.');
  });
  clearAuth?.addEventListener('click', () => {
    void runNativeAction(() => nativeBridge()?.clearMobileSession?.(), 'Connexion rapide supprimée.');
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || modal.hidden) {
      return;
    }

    event.preventDefault();
    closeSettings();
  });

  window.addEventListener('online', renderSettings);
  window.addEventListener('offline', renderSettings);
  window.addEventListener('martin-sols:native-auth-status-changed', renderSettings);

  if (!settingsInstalled) {
    settingsInstalled = true;

    document.addEventListener(
      'click',
      (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (!target?.closest('[data-crm-mobile-settings-toggle]')) {
          return;
        }

        event.preventDefault();
        openSettings();
      },
      true,
    );
  }

  window.MartinSolsMobileApp = {
    checkForUpdates: requestUpdateCheck,
    openSettings,
    requestLocation,
  };

  renderSettings();
}
