<!doctype html>
<html lang="fr">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow" />
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}" />
    @include('partials.pwa-head')
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="{{ \App\Support\CrmAsset::url('modules/crm-core/brand-morph-loader.css') }}">
    <title>Connexion - Martin Sols HUB</title>
    <style>
      :root {
        color-scheme: light;
        --primary: #a50034;
        --primary-dark: #85002b;
        --ink: #102033;
        --muted: #697386;
        --line: #dce2ea;
        --surface: #ffffff;
        --page: #f3f6fa;
      }

      * {
        box-sizing: border-box;
      }

      html {
        min-height: 100%;
        background: var(--page);
      }

      body {
        min-height: 100vh;
        min-height: 100svh;
        margin: 0;
        display: grid;
        place-items: center;
        padding: 24px;
        background:
          linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(243, 246, 250, 0.96)),
          var(--page);
        color: var(--ink);
        font-family: "DM Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      }

      main {
        width: min(100%, 460px);
        display: grid;
        gap: 14px;
      }

      .login-card {
        display: grid;
        gap: 24px;
        padding: 30px;
        border: 1px solid rgba(220, 226, 234, 0.96);
        border-radius: 14px;
        background: var(--surface);
        box-shadow: 0 22px 58px rgba(16, 32, 51, 0.1);
      }

      .brand {
        display: grid;
        justify-items: center;
        gap: 14px;
        text-align: center;
      }

      .brand img {
        display: block;
        width: min(250px, 72vw);
        height: auto;
        max-height: 96px;
        object-fit: contain;
      }

      .app-install {
        display: grid;
        gap: 8px;
        justify-items: center;
        padding: 0 4px;
        border: 0;
        border-radius: 0;
        background: transparent;
      }

      .app-install[hidden] {
        display: none;
      }

      .app-install__head {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
      }

      .app-install__actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        min-width: 0;
      }

      .app-install__badge svg {
        width: 24px;
        height: 24px;
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 2.4;
      }

      .app-install__brand-logo {
        fill: currentColor;
        stroke: none;
      }

      .app-install__brand-logo path {
        stroke: none;
      }

      .app-install__badge span:first-child > span {
        font-size: 1.44rem;
        font-weight: 900;
        line-height: 1;
      }

      .app-install__help {
        margin: 0;
        color: var(--muted);
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1.35;
      }

      .app-install__help:empty {
        display: none;
      }

      .app-install__badge {
        display: inline-flex;
        align-items: center;
        justify-content: flex-start;
        gap: 10px;
        min-width: 158px;
        min-height: 48px;
        padding: 6px 12px;
        border: 1px solid var(--install-border, #0b0f14);
        border-radius: 10px;
        background: var(--install-bg, #05070a);
        color: var(--install-color, #fff);
        text-decoration: none;
        box-shadow: 0 12px 24px var(--install-shadow, rgba(0, 0, 0, 0.16));
        transition:
          transform 0.16s ease,
          box-shadow 0.16s ease,
          border-color 0.16s ease;
      }

      .app-install__badge[hidden] {
        display: none;
      }

      .app-install__badge.is-android {
        --install-bg: #0b0f14;
        --install-border: #0b0f14;
        --install-color: #fff;
        --install-shadow: rgba(11, 15, 20, 0.18);
      }

      .app-install__badge.is-webapk {
        --install-bg: #0b0f14;
        --install-border: #0b0f14;
        --install-color: #fff;
        --install-shadow: rgba(11, 15, 20, 0.18);
      }

      .app-install__badge.is-iphone,
      .app-install__badge.is-ipad,
      .app-install__badge.is-macos {
        --install-bg: #000;
        --install-border: #000;
        --install-color: #fff;
        --install-shadow: rgba(0, 0, 0, 0.14);
      }

      .app-install__badge.is-web {
        --install-bg: #0b0f14;
        --install-border: #0b0f14;
        --install-color: #fff;
        --install-shadow: rgba(11, 15, 20, 0.18);
      }

      .app-install.is-android {
        background: transparent;
      }

      .app-install.is-iphone,
      .app-install.is-ipad,
      .app-install.is-macos {
        background: transparent;
      }

      .app-install.is-web {
        background: transparent;
      }

      .app-install__badge:focus {
        outline: 4px solid rgba(165, 0, 52, 0.16);
        outline-offset: 2px;
      }

      .app-install__badge:hover {
        border-color: #1d2633;
        box-shadow: 0 16px 28px var(--install-shadow, rgba(0, 0, 0, 0.18));
        transform: translateY(-1px);
      }

      .app-install__badge span:first-child {
        display: grid;
        place-items: center;
        width: 27px;
        height: 27px;
        flex: 0 0 auto;
      }

      .app-install__badge > span:last-child {
        display: grid;
        gap: 2px;
        justify-items: start;
        min-width: 0;
        text-align: left;
      }

      .app-install__badge small {
        color: currentColor;
        font-size: 0.56rem;
        font-weight: 800;
        letter-spacing: 0;
        line-height: 1;
        opacity: 0.82;
      }

      .app-install__badge strong {
        color: currentColor;
        font-size: 1.02rem;
        font-weight: 950;
        line-height: 1;
        letter-spacing: 0;
        white-space: nowrap;
      }

      .native-login {
        display: grid;
        gap: 8px;
        padding: 12px;
        border: 1px solid rgba(220, 226, 234, 0.95);
        border-radius: 12px;
        background: #f8fafc;
      }

      .native-login[hidden] {
        display: none;
      }

      .native-login__button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        width: 100%;
        min-height: 48px;
        border: 1px solid var(--line);
        background: #fff;
        color: var(--ink);
        box-shadow: 0 10px 22px rgba(16, 32, 51, 0.08);
      }

      .native-login__button:hover {
        background: #fff;
        border-color: rgba(165, 0, 52, 0.38);
        color: var(--primary);
      }

      .native-login__button[disabled],
      button[disabled] {
        cursor: progress;
        opacity: 0.72;
      }

      .native-login__help,
      .native-login__error {
        margin: 0;
        font-size: 0.78rem;
        font-weight: 750;
        line-height: 1.35;
      }

      .native-login__help {
        color: var(--muted);
      }

      .native-login__error {
        display: none;
        color: #991b1b;
      }

      .native-login__error.is-visible {
        display: block;
      }

      form {
        display: grid;
        gap: 17px;
      }

      .field {
        display: grid;
        gap: 8px;
      }

      label {
        color: var(--ink);
        font-size: 0.94rem;
        font-weight: 800;
      }

      input[type="email"],
      input[type="password"] {
        width: 100%;
        min-height: 52px;
        padding: 0 14px;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: #fff;
        color: var(--ink);
        font: inherit;
        font-size: 1rem;
        font-weight: 650;
      }

      input:focus {
        outline: 4px solid rgba(165, 0, 52, 0.14);
        border-color: var(--primary);
      }

      .remember {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        width: fit-content;
        color: var(--muted);
        font-size: 0.94rem;
        font-weight: 700;
      }

      .remember input {
        width: 20px;
        height: 20px;
        margin: 0;
        accent-color: var(--primary);
      }

      .error {
        padding: 11px 13px;
        border: 1px solid rgba(185, 28, 28, 0.24);
        border-radius: 10px;
        background: #fef2f2;
        color: #991b1b;
        font-size: 0.92rem;
        font-weight: 700;
      }

      button {
        min-height: 54px;
        border: 0;
        border-radius: 10px;
        background: var(--primary);
        color: #fff;
        font: inherit;
        font-size: 1rem;
        font-weight: 800;
        cursor: pointer;
        box-shadow: 0 14px 30px rgba(165, 0, 52, 0.2);
      }

      button:focus {
        outline: 4px solid rgba(165, 0, 52, 0.2);
        outline-offset: 2px;
      }

      button:hover {
        background: var(--primary-dark);
      }

      #crm-pwa-install-button {
        display: none !important;
      }

      @media (max-width: 520px) {
        body {
          place-items: center;
          padding: 16px;
        }

        .login-card {
          gap: 16px;
          padding: 20px;
          border-radius: 12px;
        }

        .brand {
          gap: 10px;
        }

        .brand img {
          width: min(190px, 60vw);
          max-height: 74px;
        }

        form {
          gap: 14px;
        }

        input[type="email"],
        input[type="password"] {
          min-height: 48px;
        }

        .app-install {
          padding: 0;
        }

        .app-install__badge {
          min-width: 148px;
        }

        button {
          min-height: 50px;
        }
      }

      @media (max-width: 380px) {
        body {
          padding: 14px;
        }

        .login-card {
          padding: 22px;
        }

        .app-install__head {
          display: flex;
        }

        .app-install__badge {
          width: 100%;
        }
      }
    </style>
  </head>
  <body data-login-mobile-app="{{ $loginIsMobileApp ? '1' : '0' }}">
    @include('partials.brand-morph-loader')
    <script>
      (function () {
        var loader = document.getElementById('brand-morph-loader')
        if (!loader) return

        loader.classList.add('is-visible')
        loader.setAttribute('aria-hidden', 'false')
      })()
    </script>
    <main>
      <section class="login-card" aria-label="Connexion Martin Sols">
        <div class="brand">
          <img src="{{ asset('martin-sols-logo.png') }}" alt="Martin Sols" />
        </div>

        <form method="post" action="{{ route('login') }}" autocomplete="on" data-login-form>
          @csrf

          @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
          @endif

          <div class="field">
            <label for="email">Adresse e-mail</label>
            <input
              id="email"
              name="email"
              type="email"
              value="{{ old('email') }}"
              autocomplete="username"
              inputmode="email"
              autocapitalize="none"
              spellcheck="false"
              data-login-email
              required
              autofocus
            />
          </div>

          <div class="field">
            <label for="password">Mot de passe</label>
            <input
              id="password"
              name="password"
              type="password"
              autocomplete="current-password"
              data-login-password
              required
            />
          </div>

          <input type="hidden" name="remember" value="0" />
          <label class="remember">
            <input name="remember" type="checkbox" value="1" data-login-remember @checked(old('remember', true)) />
            <span>Rester connecté</span>
          </label>

          <button type="submit">Se connecter</button>

          <section class="native-login" data-native-login hidden aria-label="Connexion rapide Martin Sols">
            <button class="native-login__button" type="button" data-native-login-button>
              Connexion rapide
            </button>
            <p class="native-login__help" data-native-login-help>Empreinte, visage ou code de l’appareil</p>
            <p class="native-login__error" data-native-login-error></p>
          </section>
        </form>
      </section>

      <section
        class="app-install"
        data-login-app-install
        data-android-url="{{ $loginInstallLinks['androidApkUrl'] ?? '' }}"
        data-ios-url="{{ $loginInstallLinks['iosInstallUrl'] ?? '' }}"
        data-macos-url="{{ $loginInstallLinks['macosPkgUrl'] ?? '' }}"
        aria-label="Installer l'application Martin Sols"
        hidden
      >
        <div class="app-install__head">
          <div class="app-install__actions">
            <a class="app-install__badge" href="#" data-login-app-button rel="noopener">
              <span data-login-app-icon aria-hidden="true"></span>
              <span>
                <small data-login-app-small>Installer</small>
                <strong data-login-app-label>Web APK</strong>
              </span>
            </a>
            <a class="app-install__badge" href="#" data-login-webview-button rel="noopener" hidden>
              <span data-login-webview-icon aria-hidden="true"></span>
              <span>
                <small data-login-webview-small>Télécharger</small>
                <strong data-login-webview-label>Android</strong>
              </span>
            </a>
          </div>
        </div>
        <p class="app-install__help" data-login-app-help></p>
      </section>
    </main>
    @include('partials.pwa-scripts')
    <script src="{{ \App\Support\CrmAsset::url('modules/crm-core/brand-morph-loader.js') }}"></script>
    <script src="{{ \App\Support\CrmAsset::url('modules/crm-core/brand-morph-loader-app.js') }}"></script>
    <script>
      (() => {
        const storageKey = 'martin-sols:login:remembered-email';
        const form = document.querySelector('[data-login-form]');
        const email = document.querySelector('[data-login-email]');
        const remember = document.querySelector('[data-login-remember]');

        if (!form || !email || !remember) {
          return;
        }

        try {
          const rememberedEmail = window.localStorage.getItem(storageKey);

          if (rememberedEmail && !email.value) {
            email.value = rememberedEmail;
            remember.checked = true;
          }
        } catch (error) {
          // Private browsing modes can block localStorage; the Laravel remember cookie still works.
        }

        form.addEventListener('submit', () => {
          try {
            const normalizedEmail = email.value.trim();

            if (remember.checked && normalizedEmail) {
              window.localStorage.setItem(storageKey, normalizedEmail);
            } else {
              window.localStorage.removeItem(storageKey);
            }
          } catch (error) {
            // Do not block login if localStorage is unavailable.
          }
        });
      })();
    </script>
    <script>
      (() => {
        const nativeApp = window.MartinSolsNativeApp;
        const form = document.querySelector('[data-login-form]');
        const email = document.querySelector('[data-login-email]');
        const password = document.querySelector('[data-login-password]');
        const quickLogin = document.querySelector('[data-native-login]');
        const quickButton = document.querySelector('[data-native-login-button]');
        const quickHelp = document.querySelector('[data-native-login-help]');
        const quickError = document.querySelector('[data-native-login-error]');
        const submitButton = form?.querySelector('button[type="submit"]');

        if (!nativeApp || !form || !email || !password || !quickLogin || !quickButton || !submitButton) {
          return;
        }

        const status = readNativeStatus();

        if (status.available && status.hasSession) {
          quickLogin.hidden = false;
        } else if (status.available && quickHelp) {
          quickHelp.textContent = 'La connexion rapide sera active après votre première connexion.';
        }

        form.addEventListener('submit', (event) => {
          if (form.dataset.nativeLoginFallback === '1') {
            return;
          }

          const normalizedEmail = email.value.trim();
          const currentPassword = password.value;

          if (!normalizedEmail || !currentPassword) {
            return;
          }

          event.preventDefault();
          void loginWithPassword(normalizedEmail, currentPassword);
        });

        quickButton.addEventListener('click', () => {
          void loginWithSavedSession();
        });

        async function loginWithPassword(normalizedEmail, currentPassword) {
          setBusy(true, 'Connexion...');
          setNativeError('');

          try {
            const session = await issueMobileToken(normalizedEmail, currentPassword);
            saveNativeSession(session);
            await openWebSession(session);
          } catch (error) {
            setBusy(false);
            form.dataset.nativeLoginFallback = '1';
            HTMLFormElement.prototype.submit.call(form);
          }
        }

        async function loginWithSavedSession() {
          setBusy(true, 'Déverrouillage...');
          setNativeError('');

          try {
            const session = await authenticateNativeSession();
            await openWebSession(session);
          } catch (error) {
            setNativeError(error instanceof Error ? error.message : 'Connexion rapide impossible.');
            setBusy(false);
          }
        }

        async function issueMobileToken(normalizedEmail, currentPassword) {
          const response = await fetch('/api/mobile/token', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              email: normalizedEmail,
              password: currentPassword,
              device_name: nativeDeviceName(),
            }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok || payload.ok !== true || !payload.token || !payload.refreshToken) {
            throw new Error(payload.error || 'Identifiants invalides.');
          }

          return payload;
        }

        async function openWebSession(session) {
          try {
            const webSession = await createWebSession(session.token);
            window.location.replace(webSession.url);
          } catch (error) {
            if (!session.refreshToken) {
              throw error;
            }

            const refreshed = await refreshMobileToken(session.refreshToken);
            saveNativeSession(refreshed);
            const webSession = await createWebSession(refreshed.token);
            window.location.replace(webSession.url);
          }
        }

        async function refreshMobileToken(refreshToken) {
          const response = await fetch('/api/mobile/refresh', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              refreshToken,
              device_name: nativeDeviceName(),
            }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok || payload.ok !== true || !payload.token || !payload.refreshToken) {
            nativeApp.clearMobileSession?.();
            throw new Error('Connexion rapide expirée. Reconnectez-vous une fois avec le mot de passe.');
          }

          return payload;
        }

        async function createWebSession(token) {
          const response = await fetch('/api/mobile/web-session', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
              Authorization: `Bearer ${token}`,
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              redirectPath: '/',
              embed: false,
              plain: false,
            }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok || payload.ok !== true || !payload.url) {
            throw new Error(payload.error || 'Session HUB impossible.');
          }

          return payload;
        }

        function authenticateNativeSession() {
          return new Promise((resolve, reject) => {
            const requestId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
            const timeout = window.setTimeout(() => {
              window.removeEventListener('martin-sols:native-auth-result', onResult);
              reject(new Error('Authentification trop longue. Réessayez.'));
            }, 45000);

            function onResult(event) {
              const detail = event.detail || {};

              if (detail.requestId !== requestId) {
                return;
              }

              window.clearTimeout(timeout);
              window.removeEventListener('martin-sols:native-auth-result', onResult);

              if (detail.ok === true && detail.session) {
                resolve(detail.session);
                return;
              }

              reject(new Error(detail.error || 'Authentification annulée.'));
            }

            window.addEventListener('martin-sols:native-auth-result', onResult);
            nativeApp.authenticateSavedMobileSession?.(requestId);
          });
        }

        function readNativeStatus() {
          try {
            return JSON.parse(nativeApp.getMobileAuthStatus?.() || '{}');
          } catch (error) {
            return {};
          }
        }

        function saveNativeSession(session) {
          try {
            const result = JSON.parse(nativeApp.saveMobileSession?.(JSON.stringify(session)) || '{}');

            if (result.ok === true) {
              quickLogin.hidden = false;
            }
          } catch (error) {
            // Connexion web OK même si Android ne peut pas conserver la session rapide.
          }
        }

        function nativeDeviceName() {
          const version = nativeApp.getVersionName?.() || 'app';

          return `Martin Sols Android ${version}`;
        }

        function setBusy(isBusy, label) {
          submitButton.disabled = isBusy;
          quickButton.disabled = isBusy;

          if (label) {
            quickButton.textContent = label;
          } else {
            quickButton.textContent = 'Connexion rapide';
          }
        }

        function setNativeError(message) {
          if (!quickError) {
            return;
          }

          quickError.textContent = message || '';
          quickError.classList.toggle('is-visible', Boolean(message));
        }
      })();
    </script>
    <script>
      (() => {
        const card = document.querySelector('[data-login-app-install]');

        if (!card) {
          return;
        }

        const button = card.querySelector('[data-login-app-button]');
        const webviewButton = card.querySelector('[data-login-webview-button]');
        const help = card.querySelector('[data-login-app-help]');
        const small = card.querySelector('[data-login-app-small]');
        const label = card.querySelector('[data-login-app-label]');
        const icon = card.querySelector('[data-login-app-icon]');
        const webviewSmall = card.querySelector('[data-login-webview-small]');
        const webviewLabel = card.querySelector('[data-login-webview-label]');
        const webviewIcon = card.querySelector('[data-login-webview-icon]');
        const params = new URLSearchParams(window.location.search);

        const isStandalone = window.matchMedia?.('(display-mode: standalone)').matches === true
          || window.navigator.standalone === true;
        const isNativeApp = document.body.dataset.loginMobileApp === '1'
          || params.has('mobile_app')
          || params.has('mobile_embed')
          || Boolean(window.MartinSolsNativeApp)
          || window.Capacitor?.isNativePlatform?.() === true;

        if (
          isStandalone
          || isNativeApp
          || !button
          || !webviewButton
          || !help
          || !small
          || !label
          || !icon
          || !webviewSmall
          || !webviewLabel
          || !webviewIcon
        ) {
          card.remove();

          return;
        }

        const userAgent = window.navigator.userAgent || '';
        const platform = window.navigator.platform || '';
        const userAgentDataPlatform = window.navigator.userAgentData?.platform || '';
        const platformSignature = `${userAgent} ${platform} ${userAgentDataPlatform}`.toLowerCase();
        const isAndroid = /Android/i.test(userAgent);
        const isIpad = /iPad/i.test(userAgent)
          || (platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
        const isIphone = /iPhone|iPod/i.test(userAgent);
        const isIos = isIphone || isIpad;
        const isMacos = !isIos && !isAndroid && /\b(macintosh|mac os x|macintel|macos|mac)\b/i.test(platformSignature);
        const androidUrl = card.dataset.androidUrl || '';
        const iosUrl = card.dataset.iosUrl || '';
        const macosUrl = card.dataset.macosUrl || '';
        const icons = {
          google: '<svg class="app-install__brand-logo" viewBox="0 0 24 24"><path fill="#4285f4" d="M21.6 12.23c0-.74-.07-1.45-.19-2.14H12v4.05h5.38a4.6 4.6 0 0 1-2 3.02v2.51h3.24c1.9-1.75 2.98-4.32 2.98-7.44Z"></path><path fill="#34a853" d="M12 22c2.7 0 4.97-.89 6.62-2.33l-3.24-2.51c-.9.6-2.05.96-3.38.96-2.6 0-4.8-1.76-5.6-4.12H3.06v2.59A10 10 0 0 0 12 22Z"></path><path fill="#fbbc05" d="M6.4 14a6 6 0 0 1 0-3.82V7.59H3.06a10 10 0 0 0 0 8.82L6.4 14Z"></path><path fill="#ea4335" d="M12 5.89c1.47 0 2.8.5 3.84 1.5l2.86-2.86A9.6 9.6 0 0 0 12 2a10 10 0 0 0-8.94 5.59l3.34 2.59C7.2 7.82 9.4 5.89 12 5.89Z"></path></svg>',
          apple: '<span aria-hidden="true"></span>',
          chrome: '<svg class="app-install__brand-logo" viewBox="0 0 24 24"><path fill="#ea4335" d="M12 12h9.75A9.75 9.75 0 0 0 3.56 7.13L8.4 15.5A4.3 4.3 0 0 1 12 7.7h8.77A9.75 9.75 0 0 0 12 2.25a9.74 9.74 0 0 0-8.44 4.88L8.4 15.5A4.3 4.3 0 0 1 12 12Z"></path><path fill="#fbbc04" d="M3.56 7.13A9.75 9.75 0 0 0 12 21.75l4.84-8.38A4.3 4.3 0 0 1 8.4 15.5L3.56 7.13Z"></path><path fill="#34a853" d="M12 21.75A9.75 9.75 0 0 0 21.75 12H12a4.3 4.3 0 0 1 4.84 1.37L12 21.75Z"></path><circle cx="12" cy="12" r="4.1" fill="#4285f4"></circle><circle cx="12" cy="12" r="2.35" fill="#fff"></circle></svg>',
          download: '<svg viewBox="0 0 24 24"><path d="M12 3v11"></path><path d="m7.5 9.5 4.5 4.5 4.5-4.5"></path><path d="M5 18.5h14"></path></svg>',
        };

        const setupButton = (target, kind, textTargets, options) => {
          target.className = `app-install__badge is-${kind}`;
          target.href = options.href || '#';
          target.toggleAttribute('download', options.download === true);
          textTargets.small.textContent = options.small;
          textTargets.label.textContent = options.label;
          textTargets.icon.innerHTML = options.icon;
          target.onclick = options.onClick || null;
          target.hidden = false;
        };

        const hideWebviewButton = () => {
          webviewButton.hidden = true;
          webviewButton.onclick = null;
          webviewButton.removeAttribute('download');
        };

        const installPwa = (event) => {
          event.preventDefault();

          if (window.MartinSolsPwa?.install) {
            window.MartinSolsPwa.install();
            return;
          }

          help.textContent = 'Dans Chrome ou Edge : menu du navigateur, puis Installer l’application.';
        };

        const configure = (kind, options) => {
          card.className = `app-install is-${kind}`;
          setupButton(button, kind, { small, label, icon }, options);
          hideWebviewButton();
          help.textContent = options.help;
          card.hidden = false;
        };

        if (isAndroid && androidUrl) {
          card.className = 'app-install is-android';
          setupButton(button, 'webapk', { small, label, icon }, {
            href: '#',
            icon: icons.chrome,
            small: 'Installer',
            label: 'Web APK',
            help: '',
            onClick: installPwa,
          });
          setupButton(webviewButton, 'android', {
            small: webviewSmall,
            label: webviewLabel,
            icon: webviewIcon,
          }, {
            href: androidUrl,
            download: true,
            icon: icons.google,
            small: 'Télécharger',
            label: 'Android',
            help: '',
          });
          help.textContent = '';
          card.hidden = false;

          return;
        }

        if (isIos) {
          const iosKind = isIpad ? 'ipad' : 'iphone';
          const iosLabel = isIpad ? 'iPad' : 'iPhone';

          configure(iosKind, {
            href: iosUrl || '#',
            icon: icons.apple,
            small: iosUrl ? 'Installer via' : 'Ajouter sur',
            label: iosLabel,
            help: '',
            onClick: iosUrl ? null : (event) => {
              event.preventDefault();
              help.textContent = 'Safari > Partager > Ajouter à l’écran d’accueil. Le HUB s’ouvrira ensuite en plein écran.';
            },
          });

          return;
        }

        if (isMacos) {
          configure('macos', {
            href: macosUrl || '#',
            download: Boolean(macosUrl),
            icon: icons.apple,
            small: macosUrl ? 'Télécharger' : 'Bientôt',
            label: 'macOS',
            help: '',
            onClick: macosUrl ? null : (event) => {
              event.preventDefault();
              help.textContent = 'Le téléchargement Mac sera proposé ici dès que le paquet sera publié.';
            },
          });

          return;
        }

        configure('web', {
          href: '#',
          icon: icons.chrome,
          small: 'Installer',
          label: 'Web APK',
          help: '',
          onClick: installPwa,
        });
      })();
    </script>
  </body>
</html>
