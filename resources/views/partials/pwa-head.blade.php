<meta name="theme-color" content="{{ \App\Support\CrmTheme::primaryHex() }}" />
<meta name="application-name" content="JP2 Hub" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-title" content="JP2 Hub" />
<meta name="apple-mobile-web-app-status-bar-style" content="default" />
<link rel="manifest" href="{{ route('crm.pwa.manifest') }}" type="application/manifest+json" />
<link rel="apple-touch-icon" href="{{ asset('assets/pwa/apple-touch-icon.png') }}" />
@unless(request()->boolean('mobile_embed'))
  <script src="{{ \App\Support\CrmAsset::url('modules/crm-core/crm-pwa.js') }}" defer></script>
@endunless
