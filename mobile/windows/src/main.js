const {
  app,
  BrowserWindow,
  Menu,
  dialog,
  ipcMain,
  safeStorage,
  session,
  shell,
} = require('electron');
const crypto = require('node:crypto');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const HUB_URL = 'https://crm.jp2.fr/?mobile_app=1&source=windows_app';
const UPDATE_MANIFEST_URL = 'https://raw.githubusercontent.com/jp2creation/hub/main/mobile/releases/martin-sols-update.json';
const SPLASH_DURATION_MS = 12600;
const UPDATE_CHECK_DELAY_MS = 1500;
const APP_CODE_HASH_ITERATIONS = 120000;
const APP_CODE_HASH_BYTES = 32;
const APP_CODE_SALT_BYTES = 16;
const CURRENT_BUILD_NUMBER = 1;
const TRUSTED_HOSTS = new Set(['crm.jp2.fr']);

let mainWindow = null;
let updateCheckStarted = false;

function nativeActionResult(ok, message, extra = {}) {
  return { ok, message, ...extra };
}

function crmUrl() {
  const url = new URL(HUB_URL);
  url.searchParams.set('mobile_app', '1');
  url.searchParams.set('source', 'windows_app');

  return url.toString();
}

function isTrustedUrl(rawUrl) {
  try {
    const url = new URL(rawUrl);

    return ['https:', 'http:'].includes(url.protocol) && TRUSTED_HOSTS.has(url.hostname.toLowerCase());
  } catch {
    return false;
  }
}

function isTrustedSender(event) {
  return isTrustedUrl(event?.senderFrame?.url || '');
}

function storeFilePath() {
  return path.join(app.getPath('userData'), 'native-auth.json');
}

function emptyStore() {
  return {
    appCode: null,
    session: null,
  };
}

function readStoreSync() {
  try {
    const raw = fs.readFileSync(storeFilePath(), 'utf8');
    const parsed = JSON.parse(raw);

    return {
      ...emptyStore(),
      ...parsed,
    };
  } catch {
    return emptyStore();
  }
}

async function readStore() {
  try {
    const raw = await fsp.readFile(storeFilePath(), 'utf8');
    const parsed = JSON.parse(raw);

    return {
      ...emptyStore(),
      ...parsed,
    };
  } catch {
    return emptyStore();
  }
}

async function writeStore(store) {
  await fsp.mkdir(path.dirname(storeFilePath()), { recursive: true });
  await fsp.writeFile(storeFilePath(), JSON.stringify(store, null, 2), 'utf8');
}

function hasSavedSession(store) {
  return Boolean(store?.session?.kind === 'safeStorage' && store.session.cipherText);
}

function mobileAuthStatusDictionary() {
  const store = readStoreSync();
  const encryptionAvailable = safeStorage.isEncryptionAvailable();
  const appCodeConfigured = Boolean(store.appCode?.hash && store.appCode?.salt);
  const hasSession = hasSavedSession(store);

  return {
    ok: true,
    available: encryptionAvailable || appCodeConfigured,
    configured: hasSession,
    deviceSecure: encryptionAvailable,
    appCodeConfigured,
    hasSession,
    label: 'Windows',
  };
}

function hashAppCode(code, salt) {
  return crypto
    .pbkdf2Sync(String(code), Buffer.from(salt, 'base64'), APP_CODE_HASH_ITERATIONS, APP_CODE_HASH_BYTES, 'sha256')
    .toString('base64');
}

function verifyAppCode(code, appCode) {
  if (!appCode?.hash || !appCode?.salt) {
    return true;
  }

  const expected = Buffer.from(appCode.hash, 'base64');
  const actual = Buffer.from(hashAppCode(code, appCode.salt), 'base64');

  return expected.length === actual.length && crypto.timingSafeEqual(expected, actual);
}

function animationPath() {
  if (app.isPackaged) {
    return path.join(process.resourcesPath, 'assets', 'opening-animation.gif');
  }

  return path.resolve(__dirname, '..', '..', 'src', 'assets', 'opening-animation.gif');
}

function iconPath() {
  if (app.isPackaged) {
    return path.join(process.resourcesPath, 'assets', 'icon.png');
  }

  return path.resolve(__dirname, '..', 'build', 'icon.png');
}

function trustedWebContents() {
  if (!mainWindow || mainWindow.isDestroyed()) {
    return null;
  }

  if (!isTrustedUrl(mainWindow.webContents.getURL())) {
    return null;
  }

  return mainWindow.webContents;
}

function dispatchAuthStatusChanged(webContents = trustedWebContents()) {
  if (!webContents || webContents.isDestroyed()) {
    return;
  }

  const status = mobileAuthStatusDictionary();
  const detail = JSON.stringify(status);
  const script = `
    window.__martinSolsNativeAuthStatus = ${detail};
    window.dispatchEvent(new CustomEvent('martin-sols:native-auth-status-changed', { detail: ${detail} }));
  `;

  webContents.executeJavaScript(script).catch(() => {});
}

function dispatchNativeAuthResult(webContents, detail) {
  if (!webContents || webContents.isDestroyed()) {
    return;
  }

  webContents.send('martin-sols:native-auth-result', detail);
}

function compareVersions(left, right) {
  const leftParts = String(left || '0').split('.').map((part) => Number.parseInt(part, 10) || 0);
  const rightParts = String(right || '0').split('.').map((part) => Number.parseInt(part, 10) || 0);
  const length = Math.max(leftParts.length, rightParts.length);

  for (let index = 0; index < length; index += 1) {
    const delta = (leftParts[index] || 0) - (rightParts[index] || 0);

    if (delta !== 0) {
      return delta;
    }
  }

  return 0;
}

function isNewerWindowsRelease(windowsRelease) {
  if (!windowsRelease) {
    return false;
  }

  const buildNumber = Number(windowsRelease.buildNumber || 0);

  if (buildNumber > CURRENT_BUILD_NUMBER) {
    return true;
  }

  return compareVersions(windowsRelease.version, app.getVersion()) > 0;
}

async function checkForUpdates(notifyWhenCurrent = true) {
  const response = await fetch(UPDATE_MANIFEST_URL, { cache: 'no-store' });

  if (!response.ok) {
    throw new Error(`Manifest HTTP ${response.status}`);
  }

  const manifest = await response.json();
  const windowsRelease = manifest.windows || {};
  const updateAvailable = isNewerWindowsRelease(windowsRelease);
  const downloadUrl = windowsRelease.installerUrl || windowsRelease.portableUrl || '';
  const releaseNotes = windowsRelease.releaseNotes || 'Nouvelle version Windows disponible.';

  if (updateAvailable && downloadUrl) {
    const result = await dialog.showMessageBox(mainWindow, {
      type: 'info',
      buttons: ['Telecharger', 'Plus tard'],
      defaultId: 0,
      cancelId: 1,
      title: 'Mise a jour Martin Sols HUB',
      message: `Version ${windowsRelease.version || ''} disponible.`,
      detail: releaseNotes,
    });

    if (result.response === 0) {
      await shell.openExternal(downloadUrl);
    }

    return nativeActionResult(true, 'Mise a jour Windows disponible.', { updateAvailable: true });
  }

  if (updateAvailable) {
    await dialog.showMessageBox(mainWindow, {
      type: 'info',
      title: 'Mise a jour Martin Sols HUB',
      message: `Version ${windowsRelease.version || ''} disponible.`,
      detail: 'Le manifeste annonce une nouvelle version Windows, mais aucune URL de telechargement Windows n est encore renseignee.',
    });

    return nativeActionResult(true, 'Mise a jour Windows disponible.', { updateAvailable: true });
  }

  if (notifyWhenCurrent) {
    await dialog.showMessageBox(mainWindow, {
      type: 'info',
      title: 'Martin Sols HUB',
      message: 'Application Windows a jour.',
      detail: `Version installee : ${app.getVersion()} (${CURRENT_BUILD_NUMBER}).`,
    });
  }

  return nativeActionResult(true, 'Application Windows a jour.', { updateAvailable: false });
}

function scheduleUpdateCheck() {
  if (updateCheckStarted) {
    return;
  }

  updateCheckStarted = true;
  setTimeout(() => {
    checkForUpdates(false).catch(() => {});
  }, UPDATE_CHECK_DELAY_MS);
}

function openExternalUrl(url) {
  shell.openExternal(url).catch(() => {});
}

function configureNavigation(window) {
  window.webContents.setWindowOpenHandler(({ url }) => {
    if (isTrustedUrl(url)) {
      window.loadURL(url);
    } else {
      openExternalUrl(url);
    }

    return { action: 'deny' };
  });

  window.webContents.on('will-navigate', (event, url) => {
    if (isTrustedUrl(url)) {
      return;
    }

    event.preventDefault();
    openExternalUrl(url);
  });

  window.webContents.on('did-finish-load', () => {
    if (isTrustedUrl(window.webContents.getURL())) {
      scheduleUpdateCheck();
    }
  });

  window.webContents.on('before-input-event', (event, input) => {
    if (input.type !== 'keyDown') {
      return;
    }

    if ((input.control || input.meta) && input.key.toLowerCase() === 'r') {
      window.webContents.reload();
    }

    if (input.alt && input.key === 'ArrowLeft' && window.webContents.canGoBack()) {
      event.preventDefault();
      window.webContents.goBack();
    }

    if (input.alt && input.key === 'ArrowRight' && window.webContents.canGoForward()) {
      event.preventDefault();
      window.webContents.goForward();
    }
  });
}

function configurePermissions() {
  session.defaultSession.setPermissionRequestHandler((webContents, permission, callback) => {
    const allowedPermissions = new Set(['geolocation', 'media', 'notifications']);
    const trusted = isTrustedUrl(webContents.getURL());

    callback(trusted && allowedPermissions.has(permission));
  });
}

function loadSplash(window) {
  window.loadFile(path.join(__dirname, 'splash.html'), {
    query: {
      animation: pathToFileURL(animationPath()).href,
    },
  });

  setTimeout(() => {
    if (!window.isDestroyed()) {
      window.loadURL(crmUrl());
    }
  }, SPLASH_DURATION_MS);
}

function createMainWindow() {
  const window = new BrowserWindow({
    width: 1180,
    height: 780,
    minWidth: 900,
    minHeight: 640,
    backgroundColor: '#fffaf7',
    title: 'Martin Sols HUB',
    icon: iconPath(),
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      preload: path.join(__dirname, 'preload.js'),
      sandbox: false,
    },
  });

  mainWindow = window;
  configureNavigation(window);
  loadSplash(window);

  window.once('closed', () => {
    if (mainWindow === window) {
      mainWindow = null;
    }
  });

  return window;
}

function openAppSettings() {
  const webContents = trustedWebContents();

  if (!webContents) {
    return;
  }

  const script = `
    document.querySelector('[data-crm-mobile-settings-toggle]')?.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true, view: window })
    );
  `;

  webContents.executeJavaScript(script).catch(() => {});
}

function configureMenu() {
  const template = [
    {
      label: 'Martin Sols HUB',
      submenu: [
        {
          label: 'Parametres de l app',
          accelerator: 'CmdOrCtrl+,',
          click: openAppSettings,
        },
        {
          label: 'Verifier les mises a jour',
          click: () => {
            checkForUpdates(true).catch((error) => {
              dialog.showMessageBox(mainWindow, {
                type: 'warning',
                title: 'Mise a jour indisponible',
                message: 'Impossible de verifier la mise a jour Windows.',
                detail: error.message,
              });
            });
          },
        },
        { type: 'separator' },
        { role: 'quit', label: 'Quitter' },
      ],
    },
    {
      label: 'Navigation',
      submenu: [
        {
          label: 'Retour',
          accelerator: 'Alt+Left',
          click: () => mainWindow?.webContents.canGoBack() && mainWindow.webContents.goBack(),
        },
        {
          label: 'Avancer',
          accelerator: 'Alt+Right',
          click: () => mainWindow?.webContents.canGoForward() && mainWindow.webContents.goForward(),
        },
        {
          label: 'Actualiser',
          accelerator: 'CmdOrCtrl+R',
          click: () => mainWindow?.webContents.reload(),
        },
        {
          label: 'Ouvrir dans le navigateur',
          click: () => {
            const url = mainWindow?.webContents.getURL() || crmUrl();
            openExternalUrl(url);
          },
        },
      ],
    },
    { role: 'editMenu', label: 'Edition' },
    { role: 'viewMenu', label: 'Affichage' },
  ];

  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

async function openPrompt({ title, message, mode }) {
  return new Promise((resolve) => {
    const parent = BrowserWindow.getFocusedWindow() || mainWindow;
    const channel = `martin-sols-prompt:${crypto.randomUUID()}`;
    const promptWindow = new BrowserWindow({
      width: 430,
      height: mode === 'set-code' ? 330 : 250,
      parent,
      modal: Boolean(parent),
      resizable: false,
      maximizable: false,
      minimizable: false,
      show: false,
      title,
      backgroundColor: '#fffaf7',
      webPreferences: {
        contextIsolation: false,
        nodeIntegration: true,
      },
    });

    let settled = false;
    const finish = (payload) => {
      if (settled) {
        return;
      }

      settled = true;
      ipcMain.removeAllListeners(channel);

      if (!promptWindow.isDestroyed()) {
        promptWindow.close();
      }

      resolve(payload || { ok: false, cancelled: true });
    };

    ipcMain.once(channel, (_event, payload) => finish(payload));
    promptWindow.once('ready-to-show', () => promptWindow.show());
    promptWindow.once('closed', () => finish({ ok: false, cancelled: true }));
    promptWindow.loadFile(path.join(__dirname, 'prompt.html'), {
      query: {
        channel,
        mode,
        title,
        message,
      },
    });
  });
}

async function setAppCode(webContents) {
  const prompt = await openPrompt({
    title: 'Code app Martin Sols',
    message: 'Choisis un code de 4 a 8 chiffres pour proteger la connexion rapide.',
    mode: 'set-code',
  });

  if (!prompt.ok) {
    return nativeActionResult(false, 'Configuration annulee.');
  }

  const code = String(prompt.values?.code || '');
  const confirmation = String(prompt.values?.confirmation || '');

  if (!/^\d{4,8}$/.test(code)) {
    return nativeActionResult(false, 'Le code doit contenir 4 a 8 chiffres.');
  }

  if (code !== confirmation) {
    return nativeActionResult(false, 'Les deux codes ne correspondent pas.');
  }

  const store = await readStore();
  const salt = crypto.randomBytes(APP_CODE_SALT_BYTES).toString('base64');
  store.appCode = {
    salt,
    hash: hashAppCode(code, salt),
    iterations: APP_CODE_HASH_ITERATIONS,
    updatedAt: new Date().toISOString(),
  };

  await writeStore(store);
  dispatchAuthStatusChanged(webContents);

  return nativeActionResult(true, 'Code app Martin Sols enregistre.');
}

async function clearAppCode(webContents) {
  const store = await readStore();
  store.appCode = null;
  await writeStore(store);
  dispatchAuthStatusChanged(webContents);

  return nativeActionResult(true, 'Code app supprime.');
}

async function saveMobileSession(webContents, payload) {
  const sessionPayload = String(payload || '');

  if (!sessionPayload) {
    return nativeActionResult(false, 'Session HUB invalide.');
  }

  if (!safeStorage.isEncryptionAvailable()) {
    return nativeActionResult(false, 'Protection Windows indisponible. Active le verrouillage Windows et reconnecte-toi.');
  }

  const store = await readStore();
  store.session = {
    kind: 'safeStorage',
    cipherText: safeStorage.encryptString(sessionPayload).toString('base64'),
    updatedAt: new Date().toISOString(),
  };

  await writeStore(store);
  dispatchAuthStatusChanged(webContents);

  return nativeActionResult(true, 'Connexion rapide Windows enregistree.');
}

function decryptSavedSession(store) {
  if (!hasSavedSession(store)) {
    throw new Error('Aucune connexion rapide n est enregistree.');
  }

  return safeStorage.decryptString(Buffer.from(store.session.cipherText, 'base64'));
}

function parseSessionPayload(payload) {
  try {
    return JSON.parse(payload);
  } catch {
    return payload;
  }
}

async function authenticateSavedMobileSession(webContents, requestId) {
  const store = await readStore();

  if (!hasSavedSession(store)) {
    const detail = {
      requestId,
      ok: false,
      error: 'Aucune connexion rapide n est enregistree.',
    };
    dispatchNativeAuthResult(webContents, detail);

    return nativeActionResult(false, detail.error);
  }

  if (store.appCode?.hash) {
    const prompt = await openPrompt({
      title: 'Connexion rapide',
      message: 'Saisis le code app pour ouvrir la session HUB.',
      mode: 'code',
    });
    const code = String(prompt.values?.code || '');

    if (!prompt.ok || !verifyAppCode(code, store.appCode)) {
      const detail = {
        requestId,
        ok: false,
        error: 'Code incorrect.',
      };
      dispatchNativeAuthResult(webContents, detail);

      return nativeActionResult(false, detail.error);
    }
  }

  try {
    const payload = decryptSavedSession(store);
    const detail = {
      requestId,
      ok: true,
      session: parseSessionPayload(payload),
    };
    dispatchNativeAuthResult(webContents, detail);

    return nativeActionResult(true, 'Connexion rapide Windows validee.');
  } catch {
    const detail = {
      requestId,
      ok: false,
      error: 'Connexion rapide expiree. Reconnecte-toi une fois.',
    };
    dispatchNativeAuthResult(webContents, detail);

    return nativeActionResult(false, detail.error);
  }
}

async function clearMobileSession(webContents) {
  const store = await readStore();
  store.session = null;
  await writeStore(store);
  dispatchAuthStatusChanged(webContents);

  return nativeActionResult(true, 'Connexion rapide supprimee.');
}

function registerNativeBridge() {
  ipcMain.on('martin-sols:get-mobile-auth-status', (event) => {
    if (!isTrustedSender(event)) {
      event.returnValue = JSON.stringify(nativeActionResult(false, 'Page HUB non autorisee.'));

      return;
    }

    event.returnValue = JSON.stringify(mobileAuthStatusDictionary());
  });

  ipcMain.handle('martin-sols:get-version-name', () => app.getVersion());
  ipcMain.handle('martin-sols:get-version-code', () => String(CURRENT_BUILD_NUMBER));
  ipcMain.handle('martin-sols:get-platform-name', () => 'Windows');

  ipcMain.handle('martin-sols:save-mobile-session', async (event, payload) => {
    if (!isTrustedSender(event)) {
      return nativeActionResult(false, 'Page HUB non autorisee.');
    }

    return saveMobileSession(event.sender, payload);
  });

  ipcMain.handle('martin-sols:authenticate-saved-mobile-session', async (event, requestId) => {
    if (!isTrustedSender(event)) {
      return nativeActionResult(false, 'Page HUB non autorisee.');
    }

    return authenticateSavedMobileSession(event.sender, String(requestId || ''));
  });

  ipcMain.handle('martin-sols:clear-mobile-session', async (event) => {
    if (!isTrustedSender(event)) {
      return nativeActionResult(false, 'Page HUB non autorisee.');
    }

    return clearMobileSession(event.sender);
  });

  ipcMain.handle('martin-sols:set-app-code', async (event) => {
    if (!isTrustedSender(event)) {
      return nativeActionResult(false, 'Page HUB non autorisee.');
    }

    return setAppCode(event.sender);
  });

  ipcMain.handle('martin-sols:clear-app-code', async (event) => {
    if (!isTrustedSender(event)) {
      return nativeActionResult(false, 'Page HUB non autorisee.');
    }

    return clearAppCode(event.sender);
  });

  ipcMain.handle('martin-sols:open-device-security-settings', async (event) => {
    if (!isTrustedSender(event)) {
      return nativeActionResult(false, 'Page HUB non autorisee.');
    }

    await shell.openExternal('ms-settings:signinoptions');

    return nativeActionResult(true, 'Ouverture des reglages de securite Windows.');
  });

  ipcMain.handle('martin-sols:check-for-updates', async (event) => {
    if (!isTrustedSender(event)) {
      return nativeActionResult(false, 'Page HUB non autorisee.');
    }

    try {
      return await checkForUpdates(true);
    } catch (error) {
      return nativeActionResult(false, error.message || 'Mise a jour indisponible.');
    }
  });
}

app.whenReady().then(() => {
  configurePermissions();
  configureMenu();
  registerNativeBridge();
  createMainWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createMainWindow();
    }
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});
