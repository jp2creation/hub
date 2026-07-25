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
  clearMobileSession?: () => void;
  authenticateSavedMobileSession?: (requestId: string) => void;
  clearAppCode?: () => void;
  checkForUpdates?: () => void;
  getMobileAuthStatus?: () => string;
  getVersionCode?: () => string;
  getVersionName?: () => string;
  openDeviceSecuritySettings?: () => void;
  saveMobileSession?: (payload: string) => string;
  setAppCode?: () => void;
};

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
  if (status.hasSession && status.label) {
    return status.label;
  }

  if (status.available) {
    return status.label || 'Disponible';
  }

  return status.message || 'Non configurée';
}

function mobileAuthSessionLabel(status: MobileAuthStatus): string {
  if (status.hasSession) {
    return 'Active';
  }

  if (status.available) {
    return 'À activer';
  }

  return status.label || 'Non configurée';
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

function isApplePlatform(label: string): boolean {
  return label === 'iPhone' || label === 'iPad' || label === 'macOS';
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
          <section class="crm-mobile-app-settings-quick-card" aria-label="Autorisations rapides">
            <label class="crm-mobile-app-settings-row crm-mobile-app-settings-switch">
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Autoriser la localisation</strong>
              </span>
              <input data-crm-mobile-location-enabled type="checkbox">
              <i aria-hidden="true"></i>
            </label>
            <label class="crm-mobile-app-settings-row crm-mobile-app-settings-switch">
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Activer la haute précision</strong>
              </span>
              <input data-crm-mobile-location-accuracy type="checkbox">
              <i aria-hidden="true"></i>
            </label>
          </section>

          <section class="crm-mobile-app-settings-card crm-mobile-app-settings-security">
            <div class="crm-mobile-app-settings-card-heading">
              <span class="crm-mobile-app-settings-card-icon is-red">${settingsIcon('shield')}</span>
              <div>
                <h3>Sécurité de l’app</h3>
                <p>Déverrouillage par empreinte, visage ou code de l’appareil.</p>
              </div>
              <span class="crm-mobile-app-settings-pill" data-crm-mobile-auth-summary>À configurer</span>
            </div>

            <div class="crm-mobile-app-settings-methods" aria-label="Méthodes de sécurité">
              <div>
                <span class="crm-mobile-app-settings-method-icon">${settingsIcon('fingerprint')}</span>
                <strong>Empreinte</strong>
                <small data-crm-mobile-fingerprint-status>Android</small>
              </div>
              <div>
                <span class="crm-mobile-app-settings-method-icon">${settingsIcon('face')}</span>
                <strong>Visage</strong>
                <small data-crm-mobile-face-status>Android</small>
              </div>
              <div>
                <span class="crm-mobile-app-settings-method-icon">${settingsIcon('key')}</span>
                <strong>Code appareil</strong>
                <small data-crm-mobile-device-code-status>À configurer</small>
              </div>
            </div>

            <button class="crm-mobile-app-settings-row is-button" type="button" data-crm-mobile-device-security>
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Paramétrer la sécurité de l’appareil</strong>
                <small data-crm-mobile-device-security-detail>Ouvrir l’écran de sécurité du téléphone</small>
              </span>
              <em>›</em>
            </button>
          </section>

          <section class="crm-mobile-app-settings-card">
            <div class="crm-mobile-app-settings-card-heading">
              <span class="crm-mobile-app-settings-card-icon is-yellow">${settingsIcon('lock')}</span>
              <div>
                <h3>Code app Martin Sols</h3>
                <p data-crm-mobile-app-code-detail>Créer un code app de 4 à 8 chiffres.</p>
              </div>
              <button class="crm-mobile-app-settings-mini-action" type="button" data-crm-mobile-set-app-code>
                <span data-crm-mobile-set-app-code-label>Définir</span>
              </button>
            </div>

            <div class="crm-mobile-app-settings-row is-static">
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Protection par code app</strong>
                <small>Sécurise l’ouverture rapide si le téléphone n’a pas encore de verrouillage.</small>
              </span>
              <em data-crm-mobile-app-code-status>Non défini</em>
            </div>

            <button class="crm-mobile-app-settings-row is-button" type="button" data-crm-mobile-clear-app-code>
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Supprimer le code app</strong>
                <small>Retirer le code local Martin Sols</small>
              </span>
              <em>›</em>
            </button>
          </section>

          <section class="crm-mobile-app-settings-card">
            <div class="crm-mobile-app-settings-card-heading">
              <span class="crm-mobile-app-settings-card-icon is-blue">${settingsIcon('fingerprint')}</span>
              <div>
                <h3>Connexion rapide</h3>
                <p>Retrouver le HUB sans retaper le mot de passe.</p>
              </div>
              <span class="crm-mobile-app-settings-pill" data-crm-mobile-auth-section-status>Non configurée</span>
            </div>

            <div class="crm-mobile-app-settings-row is-static">
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Session enregistrée</strong>
                <small>État de la connexion rapide protégée.</small>
              </span>
              <em data-crm-mobile-auth-status>Non configurée</em>
            </div>

            <button class="crm-mobile-app-settings-row is-button" type="button" data-crm-mobile-clear-auth>
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Supprimer la connexion rapide</strong>
                <small>Effacer la session enregistrée sur cet appareil</small>
              </span>
              <em>›</em>
            </button>
          </section>

          <section class="crm-mobile-app-settings-card">
            <div class="crm-mobile-app-settings-card-heading">
              <span class="crm-mobile-app-settings-card-icon is-green">${settingsIcon('location')}</span>
              <div>
                <h3>Localisation terrain</h3>
                <p>Tester la position utilisée par les futurs modules HUB.</p>
              </div>
              <span class="crm-mobile-app-settings-pill" data-crm-mobile-location-status>Désactivée</span>
            </div>

            <button class="crm-mobile-app-settings-row is-button" type="button" data-crm-mobile-test-location>
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Tester la localisation</strong>
                <small>Vérifier l’accès GPS de l’application</small>
              </span>
              <em>›</em>
            </button>
          </section>

          <section class="crm-mobile-app-settings-card">
            <div class="crm-mobile-app-settings-card-heading">
              <span class="crm-mobile-app-settings-card-icon is-red">${settingsIcon('refresh')}</span>
              <div>
                <h3>Mises à jour</h3>
                <p data-crm-mobile-update-status>Contrôle automatique actif</p>
              </div>
            </div>

            <button class="crm-mobile-app-settings-row is-button" type="button" data-crm-mobile-check-update>
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Rechercher une mise à jour</strong>
                <small>Vérifier la version disponible sur GitHub</small>
              </span>
              <em>›</em>
            </button>
          </section>

          <section class="crm-mobile-app-settings-card">
            <div class="crm-mobile-app-settings-card-heading">
              <span class="crm-mobile-app-settings-card-icon is-blue">${settingsIcon('device')}</span>
              <div>
                <h3>Informations</h3>
                <p>Version, plateforme et état réseau.</p>
              </div>
            </div>

            <div class="crm-mobile-app-settings-row is-static">
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Version</strong>
                <small data-crm-mobile-app-version>App mobile</small>
              </span>
            </div>
            <div class="crm-mobile-app-settings-row is-static">
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Plateforme</strong>
                <small data-crm-mobile-platform>Android WebView</small>
              </span>
            </div>
            <div class="crm-mobile-app-settings-row is-static">
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Réseau</strong>
                <small data-crm-mobile-network-status>En ligne</small>
              </span>
            </div>
            <div class="crm-mobile-app-settings-row is-static">
              <span class="crm-mobile-app-settings-row-copy">
                <strong>Réglages à venir</strong>
                <small>Les futurs paramètres seront ajoutés ici</small>
              </span>
              <em>Prévu</em>
            </div>
          </section>

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
  const appCodeDetail = document.querySelector<HTMLElement>('[data-crm-mobile-app-code-detail]');
  const authSummary = document.querySelector<HTMLElement>('[data-crm-mobile-auth-summary]');
  const authSectionStatus = document.querySelector<HTMLElement>('[data-crm-mobile-auth-section-status]');
  const fingerprintStatus = document.querySelector<HTMLElement>('[data-crm-mobile-fingerprint-status]');
  const faceStatus = document.querySelector<HTMLElement>('[data-crm-mobile-face-status]');
  const deviceCodeStatus = document.querySelector<HTMLElement>('[data-crm-mobile-device-code-status]');
  const updateStatus = document.querySelector<HTMLElement>('[data-crm-mobile-update-status]');
  const errorBox = document.querySelector<HTMLElement>('[data-crm-mobile-settings-error]');
  const testLocation = document.querySelector<HTMLButtonElement>('[data-crm-mobile-test-location]');
  const checkUpdate = document.querySelector<HTMLButtonElement>('[data-crm-mobile-check-update]');
  const clearAuth = document.querySelector<HTMLButtonElement>('[data-crm-mobile-clear-auth]');
  const deviceSecurity = document.querySelector<HTMLButtonElement>('[data-crm-mobile-device-security]');
  const deviceSecurityDetail = document.querySelector<HTMLElement>('[data-crm-mobile-device-security-detail]');
  const setAppCode = document.querySelector<HTMLButtonElement>('[data-crm-mobile-set-app-code]');
  const setAppCodeLabel = document.querySelector<HTMLElement>('[data-crm-mobile-set-app-code-label]');
  const clearAppCode = document.querySelector<HTMLButtonElement>('[data-crm-mobile-clear-app-code]');

  if (!modal || !locationEnabled || !locationAccuracy) {
    return;
  }

  let lastLocation: MobileLocation | null = null;
  let locationLoading = false;
  const settings = readSettings();

  const writeSettings = () => {
    localStorage.setItem(storageKey, JSON.stringify(settings));
  };

  const showError = (message: string) => {
    if (!errorBox) {
      return;
    }

    errorBox.textContent = message || '';
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
    const applePlatform = isApplePlatform(currentPlatformLabel);

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
    const deviceSecurityStateLabel =
      currentAuthStatus.available === false && currentAuthStatus.label
        ? currentAuthStatus.label
        : isDeviceSecurityReady
          ? 'Configuré'
          : 'À configurer';

    if (authStatus) {
      authStatus.textContent = mobileAuthLabel(currentAuthStatus);
    }

    if (appCodeStatus) {
      appCodeStatus.textContent = hasAppCode ? 'Défini' : 'Non défini';
    }

    if (appCodeDetail) {
      appCodeDetail.textContent = hasAppCode ? 'Code app Martin Sols actif' : 'Créer un code app de 4 à 8 chiffres';
    }

    if (authSummary) {
      authSummary.textContent =
        hasQuickLogin || hasAppCode || isDeviceSecurityReady
          ? 'Protégé'
          : currentAuthStatus.available === false && currentAuthStatus.label
            ? currentAuthStatus.label
            : 'À configurer';
      authSummary.classList.toggle('is-ready', hasQuickLogin || hasAppCode || isDeviceSecurityReady);
    }

    if (fingerprintStatus) {
      fingerprintStatus.textContent = deviceSecurityStateLabel;
    }

    if (faceStatus) {
      faceStatus.textContent = deviceSecurityStateLabel;
    }

    if (deviceCodeStatus) {
      deviceCodeStatus.textContent = deviceSecurityStateLabel;
    }

    if (authSectionStatus) {
      authSectionStatus.textContent = mobileAuthSessionLabel(currentAuthStatus);
    }

    if (testLocation) {
      testLocation.disabled = locationLoading || !settings.locationEnabled;
    }

    if (clearAuth) {
      clearAuth.disabled = !currentAuthStatus.hasSession;
    }

    if (deviceSecurity) {
      deviceSecurity.disabled = !nativeBridge()?.openDeviceSecuritySettings;
    }

    if (deviceSecurityDetail) {
      deviceSecurityDetail.textContent = applePlatform ? 'Ouvrir les réglages iOS' : 'Ouvrir la sécurité Android';
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

  const requestLocation = () => {
    if (!navigator.geolocation) {
      showError('Localisation indisponible sur ce navigateur.');
      return;
    }

    locationLoading = true;
    showError('');
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
        renderSettings();
      },
      (error) => {
        locationLoading = false;
        showError(error.message || 'Localisation indisponible.');
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
    showError('');
    renderSettings();
  };

  const closeSettings = () => {
    modal.hidden = true;
    document.body.classList.remove('crm-mobile-app-settings-open');
  };

  const requestUpdateCheck = () => {
    showError('');

    if (!nativeBridge()?.checkForUpdates) {
      showError('Recherche de mise à jour disponible uniquement dans l’app installée.');
      return;
    }

    if (updateStatus) {
      updateStatus.textContent = 'Recherche lancée...';
    }

    nativeBridge()?.checkForUpdates?.();
  };

  closeButtons.forEach((button) => {
    button.addEventListener('click', closeSettings);
  });

  locationEnabled.addEventListener('change', () => {
    settings.locationEnabled = locationEnabled.checked;
    writeSettings();
    showError('');
    renderSettings();

    if (settings.locationEnabled) {
      requestLocation();
      return;
    }

    lastLocation = null;
    publishLocation();
  });

  locationAccuracy.addEventListener('change', () => {
    settings.highAccuracyLocation = locationAccuracy.checked;
    writeSettings();
    showError('');
    renderSettings();
  });

  testLocation?.addEventListener('click', requestLocation);
  checkUpdate?.addEventListener('click', requestUpdateCheck);
  deviceSecurity?.addEventListener('click', () => {
    nativeBridge()?.openDeviceSecuritySettings?.();
  });
  setAppCode?.addEventListener('click', () => {
    nativeBridge()?.setAppCode?.();
  });
  clearAppCode?.addEventListener('click', () => {
    nativeBridge()?.clearAppCode?.();
    showError('');
    renderSettings();
  });
  clearAuth?.addEventListener('click', () => {
    nativeBridge()?.clearMobileSession?.();
    showError('');
    renderSettings();
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
