<!doctype html>
<html lang="fr" style="{{ \App\Support\CrmTheme::styleAttribute() }}">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    @include('partials.pwa-head')
    <title>Pages HUB - Martin Sols</title>

    <script>
      (function () {
        try {
          var defaultTheme = @js(\App\Support\CrmTheme::frontendConfig())
          var saved = localStorage.getItem('martin-sols-crm-theme-v2')
          if (!saved) return

          var config = JSON.parse(saved)
          var root = document.documentElement

          if (config && config.mode === 'dark') root.classList.add('dark')
          if (config && config.direction === 'rtl') root.dir = 'rtl'

          var presets = {
            blue: ['59 130 246', '99 102 241'],
            purple: ['236 72 153', '14 165 233'],
            green: ['34 197 94', '20 184 166'],
            orange: ['249 115 22', '245 158 11'],
            red: ['239 68 68', '244 63 94'],
            cyan: [defaultTheme.primary, defaultTheme.accent],
          }

          if (config && config.color && presets[config.color]) {
            root.style.setProperty('--theme-primary', presets[config.color][0])
            root.style.setProperty('--theme-accent', presets[config.color][1])
          }
        } catch (e) {
          // Theme preference is optional.
        }
      })()
    </script>
    <script>
      (function () {
        try {
          localStorage.setItem('crm-locale', 'fr')
        } catch (e) {
          // Locale preference is optional.
        }
      })()
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap"
      rel="stylesheet"
    />
    <style>
      :root {
        --theme-primary: {{ \App\Support\CrmTheme::primaryRgb() }};
        --theme-accent: {{ \App\Support\CrmTheme::accentRgb() }};
      }

      body {
        margin: 0;
        min-height: 100vh;
        background: #f8fafc;
        color: #0f172a;
        font-family: "DM Sans", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      }

      body.crm-mobile-embed {
        overflow-x: hidden;
      }

      .crm-pages-shell {
        display: grid;
        grid-template-columns: 16.25rem minmax(0, 1fr);
        min-height: 100vh;
      }

      body.crm-mobile-embed .crm-pages-shell {
        display: block;
      }

      body.crm-mobile-embed .crm-pages-sidebar,
      body.crm-mobile-embed .crm-pages-backdrop,
      body.crm-mobile-embed .crm-pages-header {
        display: none !important;
      }

      body.crm-mobile-embed .crm-pages-content {
        padding: .75rem;
      }

      .crm-pages-sidebar {
        position: sticky;
        top: 0;
        z-index: 40;
        display: flex;
        height: 100vh;
        flex-direction: column;
        border-right: 1px solid #e2e8f0;
        background: #fff;
      }

      .crm-pages-brand {
        display: flex;
        min-height: 4rem;
        align-items: center;
        justify-content: center;
        border-bottom: 1px solid #e2e8f0;
        padding: .7rem 1rem;
      }

      .crm-pages-brand img {
        max-height: 2.35rem;
        max-width: 8.5rem;
        object-fit: contain;
      }

      .crm-pages-nav {
        flex: 1;
        overflow: auto;
        padding: 1rem;
      }

      .crm-pages-nav-title {
        margin: 1rem 0 .45rem;
        color: #64748b;
        font-size: .72rem;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
      }

      .crm-pages-nav-link {
        display: flex;
        align-items: center;
        gap: .75rem;
        min-height: 2.65rem;
        border-radius: .55rem;
        color: #334155;
        padding: .55rem .75rem;
        font-size: .9rem;
        font-weight: 400;
        text-decoration: none;
      }

      .crm-pages-nav-link:hover,
      .crm-pages-nav-link.is-active {
        background: rgb(var(--theme-primary) / .1);
        color: rgb(var(--theme-primary));
      }

      .crm-pages-nav-icon {
        display: inline-flex;
        width: 1.55rem;
        height: 1.55rem;
        align-items: center;
        justify-content: center;
        border-radius: .45rem;
        background: #f1f5f9;
        color: #475569;
        font-size: .65rem;
        font-weight:600;
      }

      .crm-pages-nav-link.is-active .crm-pages-nav-icon {
        background: rgb(var(--theme-primary));
        color: #fff;
      }

      .crm-pages-logout {
        border-top: 1px solid #e2e8f0;
        padding: .8rem 1rem;
      }

      .crm-pages-logout button {
        display: flex;
        width: 100%;
        align-items: center;
        gap: .7rem;
        border: 0;
        background: transparent;
        color: #334155;
        padding: .55rem .25rem;
        font: inherit;
        font-size: .9rem;
        font-weight: 400;
        cursor: pointer;
      }

      .crm-pages-main {
        min-width: 0;
      }

      .layout-header.crm-pages-header {
        position: sticky;
        top: 0;
        z-index: 30;
        display: flex;
        min-height: 4rem;
        align-items: center;
        gap: 1rem;
        border-bottom: 1px solid #e2e8f0;
        background: rgb(255 255 255 / .94);
        padding: 0 1.4rem;
        backdrop-filter: blur(12px);
      }

      .crm-pages-menu-button {
        display: inline-flex;
        width: 2.35rem;
        height: 2.35rem;
        align-items: center;
        justify-content: center;
        border: 1px solid #e2e8f0;
        border-radius: .55rem;
        background: #fff;
        color: #334155;
        cursor: pointer;
      }

      .crm-pages-header-title {
        min-width: 0;
      }

      .crm-pages-header-title strong {
        display: block;
        color: #0f172a;
        font-size: .92rem;
        font-weight: 800;
      }

      .crm-pages-header-title span {
        display: block;
        margin-top: .12rem;
        color: #64748b;
        font-size: .76rem;
        font-weight: 600;
      }

      .layout-header .ms-auto {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: .65rem;
        min-width: 0;
      }

      .header-actions-divider {
        width: 1px;
        height: 1.6rem;
        background: #e2e8f0;
      }

      .crm-pages-icon-button {
        position: relative;
        display: inline-flex;
        width: 2.35rem;
        height: 2.35rem;
        align-items: center;
        justify-content: center;
        border: 1px solid #e2e8f0;
        border-radius: .55rem;
        background: #fff;
        color: #334155;
      }

      .crm-pages-icon-dot {
        position: absolute;
        top: .35rem;
        right: .35rem;
        width: .48rem;
        height: .48rem;
        border-radius: 999px;
        background: #ef4444;
      }

      .crm-pages-avatar {
        display: inline-flex;
        width: 2.45rem;
        height: 2.45rem;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: rgb(var(--theme-primary));
        color: #fff;
        font-size: .84rem;
        font-weight:600;
      }

      .crm-pages-content {
        padding: 1.5rem;
      }

      .crm-pages-backdrop {
        display: none;
      }

      @media (max-width: 1024px) {
        .crm-pages-shell {
          grid-template-columns: 1fr;
        }

        .crm-pages-sidebar {
          position: fixed;
          inset: 0 auto 0 0;
          width: min(17rem, 84vw);
          transform: translateX(-102%);
          transition: transform .18s ease;
        }

        .crm-pages-shell.is-sidebar-open .crm-pages-sidebar {
          transform: translateX(0);
        }

        .crm-pages-backdrop {
          position: fixed;
          inset: 0;
          z-index: 35;
          display: none;
          background: rgb(15 23 42 / .38);
        }

        .crm-pages-shell.is-sidebar-open .crm-pages-backdrop {
          display: block;
        }
      }

      @media (min-width: 1025px) {
        .crm-pages-menu-button {
          display: none;
        }
      }

      @media (max-width: 640px) {
        .layout-header.crm-pages-header {
          gap: .55rem;
          min-height: 4.25rem;
          padding: 0 .85rem;
        }

        .crm-pages-header-title {
          display: none;
        }

        .layout-header .ms-auto {
          flex: 1;
          justify-content: flex-end;
          gap: .45rem;
        }

        .header-actions-divider {
          display: none;
        }

        .crm-pages-content {
          padding: 1rem;
        }
      }
    </style>
  </head>
  <body class="{{ request()->boolean('mobile_embed') ? 'crm-mobile-embed' : '' }}">
    @php
      $user = auth()->user();
      $name = $user?->name ?: $user?->email ?: 'Jean-Philippe';
      $parts = preg_split('/\s+/', trim($name)) ?: [];
      $initials = collect($parts)
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: 'JP';
    @endphp

    <div class="crm-pages-shell" data-pages-shell>
      <div class="crm-pages-backdrop" data-sidebar-close></div>

      <aside class="crm-pages-sidebar" aria-label="Menu HUB">
        <a class="crm-pages-brand" href="{{ route('crm.home') }}">
          <img src="{{ asset('martin-sols-logo.png') }}" alt="Martin Sols" onerror="this.style.display='none'">
        </a>

        <nav class="crm-pages-nav" aria-label="Navigation HUB">
          <div class="crm-pages-nav-title">Applications HUB</div>
          <a class="crm-pages-nav-link" href="/reservations"><span class="crm-pages-nav-icon">RV</span><span>R&eacute;servations v&eacute;hicules</span></a>
          <a class="crm-pages-nav-link" href="/locations-materiel"><span class="crm-pages-nav-icon">LM</span><span>Location mat&eacute;riel</span></a>
          <a class="crm-pages-nav-link" href="/controle-caisse"><span class="crm-pages-nav-icon">CC</span><span>Contr&ocirc;le caisse</span></a>
          <a class="crm-pages-nav-link" href="/tapis-romus"><span class="crm-pages-nav-icon">TR</span><span>Tapis ROMUS</span></a>

          <div class="crm-pages-nav-title">Interne</div>
          <a class="crm-pages-nav-link is-active" href="/pages-crm"><span class="crm-pages-nav-icon">PC</span><span>Pages HUB</span></a>
          <a class="crm-pages-nav-link" href="/conges"><span class="crm-pages-nav-icon">CG</span><span>Cong&eacute;s</span></a>
          <a class="crm-pages-nav-link" href="/administration"><span class="crm-pages-nav-icon">AD</span><span>Administration</span></a>
        </nav>

        <form class="crm-pages-logout" method="POST" action="{{ route('logout') }}">
          @csrf
          <button type="submit"><span class="crm-pages-nav-icon">QS</span><span>Se d&eacute;connecter</span></button>
        </form>
      </aside>

      <section class="crm-pages-main">
        <header class="layout-header crm-pages-header">
          <button class="crm-pages-menu-button" type="button" data-sidebar-toggle aria-label="Ouvrir le menu">
            <span aria-hidden="true">&#9776;</span>
          </button>

          <div class="crm-pages-header-title">
            <strong>Pages HUB</strong>
            <span>{{ $name }}</span>
          </div>

          <div class="ms-auto">
            <span class="header-actions-divider" aria-hidden="true"></span>
            <span class="crm-pages-icon-button" aria-label="Notifications">
              <span aria-hidden="true">!</span>
              <span class="crm-pages-icon-dot"></span>
            </span>
            <span class="crm-pages-avatar" aria-label="{{ $name }}">{{ $initials }}</span>
          </div>
        </header>

        <main class="crm-pages-content">
          <div id="crm-pages-root">Chargement des pages HUB...</div>
        </main>
      </section>
    </div>

    <script src="{{ \App\Support\CrmAsset::url('modules/crm-core/crm-active-site.js') }}"></script>
    <script src="{{ \App\Support\CrmAsset::url('modules/crm-core/crm-text-fixes.js') }}"></script>
    <script src="{{ \App\Support\CrmAsset::url('modules/crm-pages/crm-pages.js') }}"></script>
    @unless(request()->boolean('mobile_embed'))
      @include('partials.pwa-scripts')
    @endunless
  </body>
</html>
