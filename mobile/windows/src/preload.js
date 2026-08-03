const { contextBridge, ipcRenderer } = require('electron');

function isTrustedPage() {
  return window.location.hostname.toLowerCase() === 'crm.jp2.fr';
}

function denied() {
  return { ok: false, message: 'Page HUB non autorisee.' };
}

function invoke(channel, ...args) {
  if (!isTrustedPage()) {
    return Promise.resolve(denied());
  }

  return ipcRenderer.invoke(channel, ...args);
}

function dispatchLocationResult(detail) {
  window.dispatchEvent(
    new CustomEvent('martin-sols:native-location-result', {
      detail,
    }),
  );
}

ipcRenderer.on('martin-sols:native-auth-result', (_event, detail) => {
  window.dispatchEvent(
    new CustomEvent('martin-sols:native-auth-result', {
      detail,
    }),
  );
});

const bridge = {
  getVersionName() {
    return '1.0.0';
  },

  getVersionCode() {
    return '1';
  },

  getPlatformName() {
    return 'Windows';
  },

  getMobileAuthStatus() {
    if (!isTrustedPage()) {
      return JSON.stringify(denied());
    }

    return ipcRenderer.sendSync('martin-sols:get-mobile-auth-status');
  },

  saveMobileSession(payload) {
    return invoke('martin-sols:save-mobile-session', payload);
  },

  authenticateSavedMobileSession(requestId) {
    return invoke('martin-sols:authenticate-saved-mobile-session', requestId || '');
  },

  clearMobileSession() {
    return invoke('martin-sols:clear-mobile-session');
  },

  setAppCode() {
    return invoke('martin-sols:set-app-code');
  },

  clearAppCode() {
    return invoke('martin-sols:clear-app-code');
  },

  openDeviceSecuritySettings() {
    return invoke('martin-sols:open-device-security-settings');
  },

  checkForUpdates() {
    return invoke('martin-sols:check-for-updates');
  },

  requestLocation(requestId, highAccuracy) {
    if (!isTrustedPage()) {
      return Promise.resolve(denied());
    }

    if (!navigator.geolocation) {
      const detail = {
        requestId: requestId || '',
        ok: false,
        error: 'Localisation Windows indisponible.',
      };
      dispatchLocationResult(detail);

      return Promise.resolve({ ok: false, message: detail.error });
    }

    return new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          const detail = {
            requestId: requestId || '',
            ok: true,
            location: {
              accuracy: position.coords.accuracy || 0,
              latitude: position.coords.latitude,
              longitude: position.coords.longitude,
              timestamp: position.timestamp || Date.now(),
            },
          };

          dispatchLocationResult(detail);
          resolve({ ok: true, message: 'Localisation Windows recuperee.' });
        },
        (error) => {
          const detail = {
            requestId: requestId || '',
            ok: false,
            error: error.message || 'Localisation Windows indisponible.',
          };

          dispatchLocationResult(detail);
          resolve({ ok: false, message: detail.error });
        },
        {
          enableHighAccuracy: Boolean(highAccuracy),
          maximumAge: 60000,
          timeout: 15000,
        },
      );
    });
  },
};

contextBridge.exposeInMainWorld('MartinSolsNativeApp', bridge);
