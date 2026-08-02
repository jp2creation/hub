@php
  $isRegularPwa = request()->query('source') === 'pwa';

  if ($isRegularPwa) {
      session()->forget('hub_mobile_app');
      session()->forget('crm_mobile_app');
  }

  $isCrmMobileApp = ! $isRegularPwa && (session('hub_mobile_app') || session('crm_mobile_app') || request()->boolean('mobile_app'));
  $crmMobileEmbed = request()->boolean('mobile_embed');
  $crmBodyClass = trim(($crmMobileEmbed ? 'crm-mobile-embed ' : '').($isCrmMobileApp ? 'crm-mobile-app' : ''));
  $crmTheme = \App\Support\CrmTheme::frontendConfig();
  $crmShellConfig = [
      'assets' => [
          'brandMorphLoaderStylesheet' => \App\Support\CrmAsset::url('modules/crm-core/brand-morph-loader.css'),
          'brandMorphLoaderVideo' => \App\Support\CrmAsset::url('modules/crm-core/brand-morph-loader.mp4'),
          'logoUrl' => asset('assets/logo/martin-sols-logo.png'),
      ],
      'csrfToken' => csrf_token(),
      'locale' => 'fr',
      'logout' => [
          'legacyLogoutPath' => '/auth/login',
          'logoutUrl' => route('logout', [], false),
          'loginUrl' => route('login', [], false),
      ],
      'mobile' => [
          'app' => (bool) $isCrmMobileApp,
          'embed' => (bool) $crmMobileEmbed,
          'siteId' => request()->integer('mobile_site_id') ?: null,
      ],
      'themeStorageKey' => 'martin-sols-hub-theme-v1',
      'theme' => $crmTheme,
  ];
@endphp
<!doctype html>
<html lang="fr" style="{{ \App\Support\CrmTheme::styleAttribute() }}">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    @include('partials.pwa-head')
    <title>Martin Sols - HUB</title>
    <script id="crm-shell-config" type="application/json">@json($crmShellConfig)</script>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap"
      rel="stylesheet"
    />
    @vite(config('crm_frontend.vite_entries'))
  </head>
  <body class="{{ $crmBodyClass }}">
    <div id="root"></div>
  </body>
</html>
