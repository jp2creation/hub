<!doctype html>
<html lang="fr" style="{{ \App\Support\CrmTheme::styleAttribute() }}">
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
    <title>Connexion - JP2 Hub</title>
    <style>
      :root {
        color-scheme: light;
        --primary: var(--theme-primary-color);
        --primary-dark: var(--theme-primary-dark-color);
        --ink: #102033;
        --muted: #697386;
        --line: #dce2ea;
        --surface: #ffffff;
        --field: #f9fbfd;
        --page: #f7f8fb;
        --shadow: 0 20px 42px rgba(16, 32, 51, 0.08);
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
        padding: 28px;
        background: var(--page);
        color: var(--ink);
        font-family: "DM Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      }

      body::before {
        display: none;
      }

      main {
        position: relative;
        z-index: 1;
        width: min(100%, 480px);
        display: grid;
        gap: 16px;
      }

      .login-card {
        display: grid;
        gap: 24px;
        padding: 34px;
        border: 1px solid rgba(220, 226, 234, 0.92);
        border-radius: 12px;
        background: var(--surface);
        box-shadow: var(--shadow);
      }

      .brand {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        text-align: center;
      }

      .brand-main-logo {
        display: block;
        width: min(190px, 58vw);
        height: auto;
        max-height: 72px;
        object-fit: contain;
      }

      .brand-hub-signature {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 0;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
      }

      .login-title {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 9px;
      }

      .login-title .brand-hub-signature {
        transform: translateY(1px);
      }

      .login-title .brand-hub-signature img {
        width: 72px;
        max-height: 21px;
      }

      .brand-hub-signature img {
        display: block;
        width: 64px;
        height: auto;
        max-height: 18px;
        object-fit: contain;
      }

      .login-copy {
        display: grid;
        gap: 7px;
      }

      .login-eyebrow {
        margin: 0;
        color: var(--primary);
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0;
        text-transform: uppercase;
      }

      .login-card h1 {
        margin: 0;
        color: var(--ink);
        font-size: 1.78rem;
        font-weight: 600;
        line-height: 1.15;
        letter-spacing: 0;
      }

      .login-copy p:last-child {
        margin: 0;
        color: var(--muted);
        font-size: 0.9rem;
        font-weight: 400;
        line-height: 1.45;
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
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        max-width: 100%;
        min-width: 0;
      }

      .app-install__help {
        margin: 0;
        color: var(--muted);
        font-size: 0.76rem;
        font-weight: 400;
        line-height: 1.35;
      }

      .app-install__help:empty {
        display: none;
      }

      .app-install__badge {
        display: block;
        width: 100%;
        min-width: 0;
        aspect-ratio: 618 / 211;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        color: inherit;
        text-decoration: none;
        box-shadow: none;
        transition:
          transform 0.16s ease,
          filter 0.16s ease;
      }

      .app-install__badge[hidden] {
        display: none;
      }

      .app-install__badge.is-current {
        filter: drop-shadow(0 12px 20px rgb(var(--theme-primary) / 0.16));
      }

      .app-install__badge:focus {
        outline: 4px solid rgb(var(--theme-primary) / 0.16);
        outline-offset: 2px;
      }

      .app-install__badge:hover {
        transform: translateY(-1px);
      }

      .app-install__badge-image {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
        object-position: center;
        border: 0;
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
        border-color: rgb(var(--theme-primary) / 0.38);
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
        gap: 16px;
      }

      .field {
        display: grid;
        gap: 7px;
      }

      label {
        color: #17243a;
        font-size: 0.82rem;
        font-weight: 600;
      }

      input[type="email"],
      input[type="password"] {
        width: 100%;
        min-height: 42px;
        padding: 0 13px;
        border: 1px solid var(--line);
        border-radius: 9px;
        background: var(--field);
        color: var(--ink);
        font: inherit;
        font-size: 0.92rem;
        font-weight: 400;
      }

      input:focus {
        outline: 3px solid rgb(var(--theme-primary) / 0.12);
        border-color: var(--primary);
        background: #fff;
      }

      .remember {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        width: fit-content;
        color: var(--muted);
        font-size: 0.84rem;
        font-weight: 400;
      }

      .remember input {
        width: 16px;
        height: 16px;
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
        font-weight: 400;
      }

      button {
        min-height: 44px;
        border: 0;
        border-radius: 9px;
        background: var(--primary);
        color: #fff;
        font: inherit;
        font-size: 0.92rem;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 14px 26px rgb(var(--theme-primary) / 0.2);
      }

      button:focus {
        outline: 4px solid rgb(var(--theme-primary) / 0.2);
        outline-offset: 2px;
      }

      button:hover {
        background: var(--primary-dark);
      }

      #crm-pwa-install-button {
        display: none !important;
      }

      @media (max-width: 680px) {
        .brand-hub-signature {
          min-height: 0;
          padding: 0;
        }

        .login-title .brand-hub-signature img {
          width: 66px;
          max-height: 20px;
        }

        .app-install__actions {
          gap: 8px;
        }
      }

      @media (max-width: 520px) {
        body {
          place-items: center;
          padding: 16px;
        }

        .login-card {
          gap: 20px;
          padding: 24px;
          border-radius: 12px;
        }

        .brand {
          gap: 9px;
        }

        .brand-main-logo {
          width: min(158px, 50vw);
          max-height: 58px;
        }

        .brand-hub-signature {
          min-height: 0;
          padding: 0;
        }

        .brand-hub-signature img {
          width: 60px;
          max-height: 17px;
        }

        .login-title {
          gap: 7px;
        }

        .login-title .brand-hub-signature img {
          width: 64px;
          max-height: 19px;
        }

        form {
          gap: 15px;
        }

        input[type="email"],
        input[type="password"] {
          min-height: 44px;
        }

        .app-install {
          padding: 0;
        }

        .app-install__actions {
          grid-template-columns: repeat(2, minmax(0, 1fr));
          max-width: 340px;
        }

        button {
          min-height: 46px;
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

        .app-install__actions {
          gap: 7px;
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
      <div class="brand" aria-label="Martin Sols HUB">
        <img class="brand-main-logo" src="{{ asset('martin-sols-logo.png') }}" alt="Martin Sols" />
      </div>

      <section class="login-card" aria-label="Connexion Martin Sols">
        <header class="login-copy">
          <p class="login-eyebrow">Accès sécurisé</p>
          <h1 class="login-title">
            <span>Bienvenue sur le</span>
            <span class="brand-hub-signature" aria-label="HUB Martin Sols">
              <img src="{{ asset('hub-ms.svg') }}" alt="HUB" />
            </span>
          </h1>
          <p>Connectez-vous pour accéder à vos modules Martin Sols.</p>
        </header>

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
        aria-label="Installer l'application Martin Sols"
        hidden
      >
        <div class="app-install__head">
          <div class="app-install__actions">
            <a class="app-install__badge is-app-store" href="#" data-login-app-kind="ios" aria-label="Disponible sur l'App Store" rel="noopener">
              <img class="app-install__badge-image" src="{{ asset('login-app-store.png') }}" alt="Disponible sur l'App Store" />
            </a>
            <a class="app-install__badge is-google-play" href="#" data-login-app-kind="android" aria-label="Disponible sur Google Play" rel="noopener">
              <img class="app-install__badge-image" src="{{ asset('login-google-play.png') }}" alt="Disponible sur Google Play" />
            </a>
            <a class="app-install__badge is-windows" href="#" data-login-app-kind="windows" aria-label="Disponible sur Windows" rel="noopener">
              <img class="app-install__badge-image" src="{{ asset('login-windows.svg') }}" alt="Disponible sur Windows" />
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
            await saveNativeSession(session);
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
            await saveNativeSession(refreshed);
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

        async function saveNativeSession(session) {
          try {
            const result = await nativeResult(nativeApp.saveMobileSession?.(JSON.stringify(session)));

            if (result.ok === true) {
              quickLogin.hidden = false;
            }
          } catch (error) {
            // Connexion web OK même si l’app ne peut pas conserver la session rapide.
          }
        }

        async function nativeResult(value) {
          const resolved = await Promise.resolve(value || '{}');

          if (typeof resolved === 'object') {
            return resolved || {};
          }

          return JSON.parse(String(resolved || '{}'));
        }

        function nativeDeviceName() {
          const version = nativeApp.getVersionName?.() || 'app';
          const platform = nativeApp.getPlatformName?.() || nativePlatformLabel();

          return `Martin Sols ${platform} ${version}`;
        }

        function nativePlatformLabel() {
          const userAgent = window.navigator.userAgent || '';
          const platform = window.navigator.platform || '';

          if (/Android/i.test(userAgent)) {
            return 'Android';
          }

          if (/iPhone|iPod/i.test(userAgent)) {
            return 'iPhone';
          }

          if (/iPad/i.test(userAgent) || (platform === 'MacIntel' && window.navigator.maxTouchPoints > 1)) {
            return 'iPad';
          }

          if (/Macintosh|Mac OS X|MacIntel/i.test(`${userAgent} ${platform}`)) {
            return 'macOS';
          }

          return 'Application mobile';
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

        const help = card.querySelector('[data-login-app-help]');
        const badges = Array.from(card.querySelectorAll('[data-login-app-kind]'));
        const badgeByKind = new Map(badges.map((badge) => [badge.dataset.loginAppKind || '', badge]));
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
          || !help
          || badges.length === 0
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
        const isWindows = !isIos && !isAndroid && /\b(windows|win32|win64|wow64)\b/i.test(platformSignature);
        const androidUrl = card.dataset.androidUrl || '';
        const iosUrl = card.dataset.iosUrl || '';

        const installPwa = (event) => {
          event.preventDefault();

          if (window.MartinSolsPwa?.install) {
            window.MartinSolsPwa.install();
            return;
          }

          help.textContent = 'Dans Chrome ou Edge : menu du navigateur, puis Installer l’application.';
        };

        const configureBadge = (kind, options) => {
          const badge = badgeByKind.get(kind);

          if (!badge) {
            return;
          }

          badge.href = options.href || '#';
          badge.toggleAttribute('download', options.download === true);
          badge.onclick = options.onClick || null;
        };

        const showIosHelp = (event) => {
          event.preventDefault();
          help.textContent = 'Safari > Partager > Ajouter à l’écran d’accueil. Le HUB s’ouvrira ensuite en plein écran.';
        };

        const showAndroidHelp = (event) => {
          event.preventDefault();
          help.textContent = 'Le lien Google Play sera disponible ici dès publication.';
        };

        configureBadge('android', {
          href: androidUrl || '#',
          download: Boolean(androidUrl),
          onClick: androidUrl ? null : showAndroidHelp,
        });

        configureBadge('ios', {
          href: iosUrl || '#',
          onClick: iosUrl ? null : showIosHelp,
        });

        configureBadge('windows', {
          href: '#',
          onClick: installPwa,
        });

        const currentKind = isAndroid
          ? 'android'
          : isIos || isMacos
            ? 'ios'
            : 'windows';
        const currentBadge = badgeByKind.get(currentKind);

        card.className = `app-install is-${currentKind}`;
        badges.forEach((badge) => {
          badge.classList.toggle('is-current', badge === currentBadge);
          badge.hidden = false;
        });

        if (currentBadge?.parentElement) {
          currentBadge.parentElement.prepend(currentBadge);
        }

        help.textContent = '';
        card.hidden = false;
      })();
    </script>
  </body>
</html>
