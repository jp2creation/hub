<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmFrontendSourceTest extends TestCase
{
    public function test_crm_shell_is_loaded_from_versioned_vite_source(): void
    {
        $blade = (string) file_get_contents(resource_path('views/crm.blade.php'));
        $viteConfig = (string) file_get_contents(base_path('vite.config.js'));
        $shell = (string) file_get_contents(resource_path('frontend/crm/shell.ts'));
        $nativeShell = (string) file_get_contents(resource_path('frontend/crm/layout/native-shell.ts'));
        $legacyTemplate = (string) file_get_contents(resource_path('frontend/crm/legacy/template-compat.ts'));
        $hosts = (string) file_get_contents(resource_path('frontend/crm/modules/hosts.ts'));
        $modules = (string) file_get_contents(resource_path('frontend/crm/modules/register.ts'));
        $menu = (string) file_get_contents(resource_path('frontend/crm/router/menu.ts'));
        $loginBlade = (string) file_get_contents(resource_path('views/auth/login.blade.php'));
        $mainActivity = (string) file_get_contents(base_path('mobile/android/app/src/main/java/fr/martinsols/crm/MainActivity.java'));
        $iosBridge = (string) file_get_contents(base_path('mobile/ios/App/App/MartinSolsBridgeViewController.swift'));
        $iosInfoPlist = (string) file_get_contents(base_path('mobile/ios/App/App/Info.plist'));
        $macApp = (string) file_get_contents(base_path('mobile/ios/App/MacApp/MacAppDelegate.swift'));
        $macInfoPlist = (string) file_get_contents(base_path('mobile/ios/App/MacApp/Info.plist'));
        $androidManifest = (string) file_get_contents(base_path('mobile/android/app/src/main/AndroidManifest.xml'));
        $androidBuild = (string) file_get_contents(base_path('mobile/android/app/build.gradle'));
        $androidSettings = (string) file_get_contents(base_path('mobile/android/settings.gradle'));
        $androidSettingsOverride = (string) file_get_contents(base_path('mobile/android/app/src/main/assets/app-settings-override.js'));
        $androidReleaseWorkflow = (string) file_get_contents(base_path('.github/workflows/martin-sols-android-release.yml'));
        $androidUpdateManifest = json_decode((string) file_get_contents(base_path('mobile/releases/martin-sols-update.json')), true, 512, JSON_THROW_ON_ERROR);
        $androidColors = (string) file_get_contents(base_path('mobile/android/app/src/main/res/values/colors.xml'));
        $androidStyles = (string) file_get_contents(base_path('mobile/android/app/src/main/res/values/styles.xml'));
        $mobilePackage = (string) file_get_contents(base_path('mobile/package.json'));
        $mobilePackageLock = (string) file_get_contents(base_path('mobile/package-lock.json'));
        $mobileApp = (string) file_get_contents(base_path('mobile/src/main.ts'));
        $mobileStyles = (string) file_get_contents(base_path('mobile/src/styles.css'));
        $mobileSettings = (string) file_get_contents(resource_path('frontend/crm/mobile/settings.ts'));
        $capacitorConfig = (string) file_get_contents(base_path('mobile/capacitor.config.json'));
        $openingAnimation = base_path('mobile/src/assets/opening-animation.gif');
        $androidIntroAnimation = base_path('mobile/android/app/src/main/res/raw/intro.mp4');

        $this->assertStringContainsString("@vite(config('crm_frontend.vite_entries'))", $blade);
        $this->assertStringContainsString('id="crm-shell-config"', $blade);
        $this->assertStringContainsString('<div id="root"></div>', $blade);
        $this->assertStringContainsString('meta name="csrf-token"', $blade);
        $this->assertStringNotContainsString('legacyAdminexScript', $blade);
        $this->assertStringNotContainsString('legacyAdminexStylesheet', $blade);
        $this->assertStringNotContainsString('resources/frontend/adminex/src/main.tsx', $viteConfig);
        $this->assertStringNotContainsString('resources/frontend/adminex/src', $viteConfig);
        $this->assertStringNotContainsString('adminex-ui', $viteConfig);
        $this->assertStringNotContainsString('assets/legacy-adminex.css', $blade);

        $this->assertFileExists(resource_path('frontend/crm/shell.ts'));
        $this->assertFileExists(resource_path('frontend/crm/api/client.ts'));
        $this->assertFileExists(resource_path('frontend/crm/config.ts'));
        $this->assertFileExists(resource_path('frontend/crm/legacy/logout-bridge.ts'));
        $this->assertFileExists(resource_path('frontend/crm/layout/native-shell.ts'));
        $this->assertFileExists(resource_path('frontend/crm/loader.ts'));
        $this->assertFileExists(resource_path('frontend/crm/mobile/embed-bridge.ts'));
        $this->assertFileExists(resource_path('frontend/crm/mobile/fallback-nav.ts'));
        $this->assertFileExists(resource_path('frontend/crm/mobile/settings.ts'));
        $this->assertFileExists(resource_path('frontend/crm/modules/hosts.ts'));
        $this->assertFileExists(resource_path('frontend/crm/router/menu.ts'));
        $this->assertFileExists(resource_path('frontend/crm/styles/shell.css'));
        $this->assertFileExists(resource_path('frontend/crm/styles/native-ui.css'));
        $this->assertFileExists(resource_path('frontend/crm/styles/template-compat.css'));
        $this->assertFileExists(resource_path('frontend/crm/styles/template-compat/variables.css'));
        $this->assertFileExists(resource_path('frontend/crm/styles/template-compat/components.css'));
        $this->assertFileExists(resource_path('frontend/crm/ui/native-ui.ts'));

        $this->assertStringContainsString("import './styles/template-compat.css';", $shell);
        $this->assertStringContainsString("import './styles/shell.css';", $shell);
        $this->assertStringContainsString("import './styles/native-ui.css';", $shell);
        $this->assertStringContainsString('installMartinSolsUi', $shell);
        $this->assertStringContainsString('installCrmModuleHostGuard', $shell);
        $this->assertStringContainsString('installNativeCrmShell', $shell);
        $this->assertStringContainsString('installCurrentCrmModuleRouteLoader', $shell);
        $this->assertStringContainsString('loadCurrentCrmModuleOverlay', $shell);
        $this->assertStringContainsString('preloadRemainingCrmModuleOverlays', $shell);
        $this->assertStringNotContainsString('loadLegacyAdminex()', $shell);
        $this->assertStringContainsString('data-crm-native-shell', $nativeShell);
        $this->assertStringContainsString('layout-sidebar crm-native-sidebar', $nativeShell);
        $this->assertStringContainsString('layout-header crm-native-header', $nativeShell);
        $this->assertStringContainsString('<img class="crm-native-mobile-brand-mark" src="${esc(logoMarkUrl())}" alt="Martin Sols">', $nativeShell);
        $this->assertStringContainsString('<img class="crm-native-mobile-brand-full" src="${esc(logoUrl())}" alt="Martin Sols">', $nativeShell);
        $this->assertStringContainsString('martin-sols-hub-sidebar-collapsed', $nativeShell);
        $this->assertStringContainsString('crm-native-sidebar-collapsed', $nativeShell);
        $this->assertStringContainsString('Déployer le menu', $nativeShell);
        $this->assertStringContainsString('Rabattre le menu', $nativeShell);
        $this->assertStringContainsString('isDesktopSidebarMode()', $nativeShell);
        $this->assertStringContainsString('setSidebarCollapsed(readStoredSidebarCollapsed(), false)', $nativeShell);
        $this->assertStringContainsString("fetch('/api/administration?action=profile'", $nativeShell);
        $this->assertStringContainsString('profile.navigation', $nativeShell);
        $this->assertStringContainsString('window.CRM_NAV_FALLBACK = profile.navigation', $nativeShell);
        $this->assertStringContainsString('data-crm-native-submenu-toggle', $nativeShell);
        $this->assertStringContainsString('iconForKey', $nativeShell);
        $this->assertStringContainsString("iconForKey('logout')", $nativeShell);
        $this->assertStringContainsString('crm-native-nav-label">Se déconnecter', $nativeShell);
        $this->assertStringContainsString('data-crm-mobile-settings-toggle', $nativeShell);
        $this->assertStringContainsString('Paramètres de l’app', $nativeShell);
        $this->assertStringContainsString('window.MartinSolsCrmConfig?.mobile.app === true', $nativeShell);
        $this->assertStringContainsString('Boolean(window.MartinSolsNativeApp)', $nativeShell);
        $this->assertStringContainsString('href="/">Tableau de bord</a>', $nativeShell);
        $this->assertStringContainsString('crm-native-brand" href="/"', $nativeShell);
        $this->assertStringContainsString("new Set(['home', 'apps', 'accounting', 'internal'])", $nativeShell);
        $this->assertStringContainsString("commercial: 'dashboard'", $nativeShell);
        $this->assertStringContainsString('isLegacyTemplateRoute()', $legacyTemplate);
        $this->assertStringContainsString('return false;', $legacyTemplate);
        $this->assertStringNotContainsString('appendModuleScript', $legacyTemplate);
        $this->assertStringNotContainsString('legacyAdminexStylesheet', $legacyTemplate);

        $this->assertStringContainsString("id: 'crm-sales-tours-module'", $hosts);
        $this->assertStringContainsString("paths: ['/rapport-visite', '/tournees-representants']", $hosts);
        $this->assertStringContainsString("id: 'crm-reservations-module'", $hosts);
        $this->assertStringContainsString("id: 'crm-equipment-rentals-module'", $hosts);
        $this->assertStringContainsString("id: 'crm-administration-module'", $hosts);
        $this->assertStringContainsString("id: 'crm-tapis-romus-module'", $hosts);
        $this->assertStringNotContainsString('adminexOnly', $hosts);
        $this->assertStringContainsString("prefix: '/documents/'", $hosts);
        $this->assertStringContainsString('refreshStaleRouteOnce', $hosts);
        $this->assertStringContainsString('refreshMissingHostOnce', $hosts);
        $this->assertStringContainsString('scheduleMissingHostRefresh', $hosts);
        $this->assertStringContainsString('clearCrmRuntimeCaches', $hosts);

        $this->assertStringContainsString("administration: () => import('../../../../Modules/CrmAdministration/resources/assets/crm-administration.js')", $modules);
        $this->assertStringContainsString("equipmentRentals: () => import('../../../../Modules/CrmEquipmentRentals/resources/assets/crm-equipment-rentals.js')", $modules);
        $this->assertStringContainsString("reservations: () => import('../../../../Modules/CrmReservations/resources/assets/crm-reservations.js')", $modules);
        $this->assertStringContainsString("{ name: 'Tableau de bord', slug: 'dashboard', routePath: '/', active: true, sortOrder: 0 }", $menu);
        $this->assertStringContainsString("tapisRomus: () => import('../../../../Modules/CrmTapisRomus/resources/assets/crm-tapis-romus.js')", $modules);
        $this->assertStringContainsString("cashControl: () => import('../../../../Modules/CrmCashControl/resources/assets/crm-controle-caisse.js')", $modules);
        $this->assertStringNotContainsString('mountLegacyReactComponent', $modules);
        $this->assertStringNotContainsString('loadLegacyAsset', $modules);
        $this->assertStringNotContainsString('transitionalReactModules', $modules);
        $this->assertStringNotContainsString('import(/* @vite-ignore */ `/assets/', $modules);

        $this->assertFileDoesNotExist(resource_path('frontend/crm/legacy/react-components.ts'));
        $this->assertDirectoryDoesNotExist(resource_path('frontend/adminex'));
        $this->assertFileDoesNotExist(resource_path('frontend/static/assets/index-CqSzWeas.js'));
        $this->assertFileDoesNotExist(resource_path('frontend/static/assets/legacy-adminex.css'));

        $mobileFallback = (string) file_get_contents(resource_path('frontend/crm/mobile/fallback-nav.ts'));
        $shellCss = (string) file_get_contents(resource_path('frontend/crm/styles/shell.css'));
        $templateCompatCss = (string) file_get_contents(resource_path('frontend/crm/styles/template-compat.css'));
        $templateVariablesCss = (string) file_get_contents(resource_path('frontend/crm/styles/template-compat/variables.css'));
        $templateComponentsCss = (string) file_get_contents(resource_path('frontend/crm/styles/template-compat/components.css'));
        $nativeUi = (string) file_get_contents(resource_path('frontend/crm/ui/native-ui.ts'));
        $nativeUiCss = (string) file_get_contents(resource_path('frontend/crm/styles/native-ui.css'));
        $filamentCss = (string) file_get_contents(public_path('css/filament/crm-filament.css'));

        $this->assertStringContainsString("window.matchMedia('(max-width: 767.98px)')", $mobileFallback);
        $this->assertStringContainsString('--color-secondary-50: #fafafa;', $templateVariablesCss);
        $this->assertStringContainsString('background: var(--color-secondary-50, #fafafa);', $shellCss);
        $this->assertStringContainsString('--ms-ui-soft: var(--color-secondary-50, #fafafa);', $nativeUiCss);
        $this->assertStringContainsString('--crm-bg: #fafafa;', $filamentCss);
        $this->assertStringNotContainsString('#eff2f5', $templateVariablesCss.$shellCss.$nativeUiCss.$filamentCss);
        $this->assertStringContainsString('shouldUseFallbackNavigation', $mobileFallback);
        $this->assertStringContainsString("document.body.classList.contains('crm-mobile-app')", $mobileFallback);
        $this->assertStringContainsString("document.body.classList.contains('crm-mobile-embed')", $mobileFallback);
        $this->assertStringContainsString('new MutationObserver', $mobileFallback);
        $this->assertStringNotContainsString("if (!document.body.classList.contains('crm-mobile-app'))", $mobileFallback);
        $this->assertStringContainsString("if (document.body.classList.contains('crm-mobile-app'))", $mobileFallback);
        $this->assertStringContainsString('return false;', $mobileFallback);
        $this->assertStringNotContainsString("window.matchMedia('(display-mode: standalone)')", $mobileFallback);
        $this->assertStringContainsString("'<a class=\"crm-mobile-fallback-brand\" href=\"/\">'", $mobileFallback);
        $this->assertStringContainsString('body.crm-mobile-fallback-nav-browser', $shellCss);
        $this->assertStringNotContainsString('html:has(body.crm-mobile-app)', $shellCss);
        $this->assertStringNotContainsString('overscroll-behavior-y: none;', $shellCss);
        $this->assertStringNotContainsString('--crm-mobile-app-header-height: var(--crm-header-height);', $shellCss);
        $this->assertStringNotContainsString('--crm-mobile-app-safe-top:', $shellCss);
        $this->assertStringNotContainsString('--crm-mobile-app-status-background:', $shellCss);
        $this->assertStringContainsString('body.crm-mobile-app .crm-native-main', $shellCss);
        $this->assertStringContainsString('body.crm-mobile-app .crm-native-header', $shellCss);
        $this->assertStringContainsString('--sidebar-collapsed-width: 80px;', $shellCss);
        $this->assertStringContainsString('body.crm-native-sidebar-collapsed .crm-native-sidebar', $shellCss);
        $this->assertStringContainsString('body.crm-native-sidebar-collapsed .crm-native-body', $shellCss);
        $this->assertStringContainsString('body.crm-native-sidebar-collapsed .crm-native-header', $shellCss);
        $this->assertStringContainsString('body.crm-native-sidebar-collapsed .crm-native-nav-submenu:hover .crm-native-nav-subitems', $shellCss);
        $this->assertStringContainsString('body.crm-native-sidebar-collapsed .crm-native-brand-mark', $shellCss);
        $this->assertStringContainsString('width: 2.45rem;', $shellCss);
        $this->assertStringContainsString('height: 2.45rem;', $shellCss);
        $this->assertStringContainsString('position: sticky;', $shellCss);
        $this->assertStringContainsString('padding-top: var(--crm-header-height);', $shellCss);
        $this->assertStringContainsString('height: var(--crm-header-height);', $shellCss);
        $this->assertStringContainsString('padding: 0 0.75rem;', $shellCss);
        $this->assertStringNotContainsString('padding: 0 0.75rem !important;', $shellCss);
        $this->assertStringContainsString('flex: 0 1 clamp(7rem, 22vw, 9.25rem);', $shellCss);
        $this->assertStringContainsString('body.crm-native-sidebar-open .crm-native-mobile-brand-full', $shellCss);
        $this->assertStringContainsString('@media (max-width: 767px)', $shellCss);
        $this->assertStringContainsString('flex: 0 0 2.4rem;', $shellCss);
        $this->assertStringContainsString('max-width: clamp(10rem, 44vw, 12rem) !important;', $shellCss);
        $this->assertStringContainsString('flex-basis: clamp(9.6rem, 44vw, 10.8rem) !important;', $shellCss);
        $this->assertStringNotContainsString('width: min(118px, 30vw);', $shellCss);
        $this->assertStringNotContainsString('max-width: 6.9rem !important;', $shellCss);
        $this->assertStringNotContainsString('max-width: 6.1rem !important;', $shellCss);
        $this->assertStringContainsString('body.crm-mobile-app .crm-mobile-fallback-header', $shellCss);
        $this->assertStringContainsString('display: none !important;', $shellCss);
        $this->assertStringNotContainsString('z-index: 9988;', $shellCss);
        $this->assertStringNotContainsString('injectMobileHeaderPatch', $mainActivity);
        $this->assertStringNotContainsString('injectMobileHeaderReset', $mainActivity);
        $this->assertStringNotContainsString('stripCrmAppShellMode', $mainActivity);
        $this->assertStringNotContainsString('scheduleCrmAppShellCleanup', $mainActivity);
        $this->assertStringNotContainsString('martin-sols-webview-clean-slate', $mainActivity);
        $this->assertStringNotContainsString('view.evaluateJavascript', $mainActivity);
        $this->assertStringNotContainsString('BridgeActivity', $mainActivity);
        $this->assertStringNotContainsString('com.getcapacitor', $mainActivity);
        $this->assertStringNotContainsString('WindowCompat', $mainActivity);
        $this->assertStringNotContainsString('SPLASH_URL', $mainActivity);
        $this->assertStringNotContainsString('configureSplashWebView', $mainActivity);
        $this->assertStringNotContainsString('setMediaController', $mainActivity);
        $this->assertStringNotContainsString('VideoView', $mainActivity);
        $this->assertStringContainsString('public class MainActivity extends Activity', $mainActivity);
        $this->assertStringContainsString('private static final String HUB_URL = "https://crm.jp2.fr/?mobile_app=1";', $mainActivity);
        $this->assertStringContainsString('private static final String UPDATE_MANIFEST_URL = "https://raw.githubusercontent.com/jp2creation/hub/main/mobile/releases/martin-sols-update.json";', $mainActivity);
        $this->assertStringContainsString('private static final String UPDATE_MANIFEST_API_URL = "https://api.github.com/repos/jp2creation/hub/contents/mobile/releases/martin-sols-update.json?ref=main";', $mainActivity);
        $this->assertStringContainsString('private static final String APK_MIME_TYPE = "application/vnd.android.package-archive";', $mainActivity);
        $this->assertStringContainsString('private static final long SPLASH_DURATION_MS = 5500L;', $mainActivity);
        $this->assertStringContainsString('private static final long UPDATE_CHECK_DELAY_MS = 1500L;', $mainActivity);
        $this->assertStringContainsString('private static final long UPDATE_PROGRESS_INTERVAL_MS = 450L;', $mainActivity);
        $this->assertStringContainsString('private static final int SPLASH_VIDEO_RESOURCE = R.raw.intro;', $mainActivity);
        $this->assertStringContainsString('private static final float INTRO_VIDEO_WIDTH_FRACTION = 0.62f;', $mainActivity);
        $this->assertStringContainsString('private static final float INTRO_VIDEO_HEIGHT_FRACTION = 0.62f;', $mainActivity);
        $this->assertStringContainsString('private static final int INTRO_VIDEO_MAX_WIDTH_DP = 260;', $mainActivity);
        $this->assertStringContainsString('private static final int UPDATE_PROGRESS_MAX = 100;', $mainActivity);
        $this->assertStringContainsString('private static final long NATIVE_LOCATION_TIMEOUT_MS = 15000L;', $mainActivity);
        $this->assertStringContainsString('requestWindowFeature(Window.FEATURE_NO_TITLE);', $mainActivity);
        $this->assertStringContainsString('rootView = new FrameLayout(this);', $mainActivity);
        $this->assertStringContainsString('webView = new WebView(this);', $mainActivity);
        $this->assertStringContainsString('splashLayer = new FrameLayout(this);', $mainActivity);
        $this->assertStringContainsString('splashLayer.setBackgroundColor(SPLASH_BACKGROUND);', $mainActivity);
        $this->assertStringContainsString('splashView = new IntroTextureView(this);', $mainActivity);
        $this->assertStringContainsString('configureSplashTextureView(splashView);', $mainActivity);
        $this->assertStringNotContainsString('view.setBackgroundColor(SPLASH_BACKGROUND);', $mainActivity);
        $this->assertStringContainsString('rootView.addView(splashLayer, matchParentLayoutParams());', $mainActivity);
        $this->assertStringContainsString('webView.loadUrl(HUB_URL);', $mainActivity);
        $this->assertStringContainsString('handler.postDelayed(hideSplash, SPLASH_DURATION_MS);', $mainActivity);
        $this->assertStringContainsString('window.setDecorFitsSystemWindows(true);', $mainActivity);
        $this->assertStringContainsString('private static final int MARTIN_SOLS_RED = Color.rgb(149, 0, 46);', $mainActivity);
        $this->assertStringContainsString('private static final int MARTIN_SOLS_BACKGROUND = Color.rgb(245, 247, 251);', $mainActivity);
        $this->assertStringContainsString('window.setStatusBarColor(MARTIN_SOLS_RED);', $mainActivity);
        $this->assertStringContainsString('protected void onResume()', $mainActivity);
        $this->assertStringContainsString('view.setOverScrollMode(View.OVER_SCROLL_NEVER);', $mainActivity);
        $this->assertStringNotContainsString('SYSTEM_UI_FLAG_LIGHT_STATUS_BAR', $mainActivity);
        $this->assertStringContainsString('if (isWebUrl(request.getUrl()))', $mainActivity);
        $this->assertStringContainsString('public boolean shouldOverrideUrlLoading(WebView view, String url)', $mainActivity);
        $this->assertStringContainsString('private boolean isWebUrl(Uri uri)', $mainActivity);
        $this->assertStringContainsString('settings.setJavaScriptEnabled(true);', $mainActivity);
        $this->assertStringContainsString('settings.setGeolocationEnabled(true);', $mainActivity);
        $this->assertStringNotContainsString('settings.setGeolocationEnabled(false);', $mainActivity);
        $this->assertStringContainsString('onGeolocationPermissionsShowPrompt', $mainActivity);
        $this->assertStringContainsString('GeolocationPermissions.Callback', $mainActivity);
        $this->assertStringContainsString('LocationManager', $mainActivity);
        $this->assertStringContainsString('private void requestNativeLocation(String requestId, boolean highAccuracy)', $mainActivity);
        $this->assertStringContainsString('private void dispatchNativeLocationResult(String requestId, Location location, String error)', $mainActivity);
        $this->assertStringContainsString('martin-sols:native-location-result', $mainActivity);
        $this->assertStringContainsString('requestInitialLocationPermission()', $mainActivity);
        $this->assertStringContainsString('BiometricPrompt.Builder', $mainActivity);
        $this->assertStringContainsString('KeyguardManager', $mainActivity);
        $this->assertStringContainsString('MOBILE_AUTH_KEY_ALIAS', $mainActivity);
        $this->assertStringContainsString('private volatile boolean trustedCrmPageActive;', $mainActivity);
        $this->assertStringContainsString('private void updateTrustedCrmPage(String url)', $mainActivity);
        $this->assertStringContainsString('trustedCrmPageActive = isTrustedCrmOrigin(url);', $mainActivity);
        $this->assertStringContainsString('return trustedCrmPageActive;', $mainActivity);
        $this->assertStringContainsString('saveMobileSessionPayload', $mainActivity);
        $this->assertStringContainsString('authenticateSavedMobileSession', $mainActivity);
        $this->assertStringContainsString('getMobileAuthStatus', $mainActivity);
        $this->assertStringContainsString('clearMobileSession', $mainActivity);
        $this->assertStringContainsString('view.setOnTouchListener(new View.OnTouchListener()', $mainActivity);
        $this->assertStringContainsString('view.setSurfaceTextureListener(new TextureView.SurfaceTextureListener()', $mainActivity);
        $this->assertStringContainsString('private void prepareSplashPlayer(SurfaceTexture surfaceTexture)', $mainActivity);
        $this->assertStringContainsString('splashSurface = new Surface(surfaceTexture);', $mainActivity);
        $this->assertStringContainsString('splashPlayer = new MediaPlayer();', $mainActivity);
        $this->assertStringContainsString('splashPlayer.setDataSource(descriptor.getFileDescriptor(), descriptor.getStartOffset(), descriptor.getLength());', $mainActivity);
        $this->assertStringContainsString('splashPlayer.setSurface(splashSurface);', $mainActivity);
        $this->assertStringContainsString('splashPlayer.setVolume(0.0f, 0.0f);', $mainActivity);
        $this->assertStringContainsString('splashPlayer.prepareAsync();', $mainActivity);
        $this->assertStringContainsString('private void releaseSplashPlayer()', $mainActivity);
        $this->assertStringContainsString('private void releaseSplashSurface()', $mainActivity);
        $this->assertStringContainsString('rootView.removeView(layer);', $mainActivity);
        $this->assertStringContainsString('private void showCrmWebView()', $mainActivity);
        $this->assertStringContainsString('webView.bringToFront();', $mainActivity);
        $this->assertStringContainsString('webView.invalidate();', $mainActivity);
        $this->assertStringContainsString('webView.loadUrl(HUB_URL);', $mainActivity);
        $this->assertStringContainsString('int desiredWidth = Math.min(Math.round(viewWidth * INTRO_VIDEO_WIDTH_FRACTION), maxWidth);', $mainActivity);
        $this->assertStringContainsString('int desiredHeight = Math.round(desiredWidth / videoAspectRatio);', $mainActivity);
        $this->assertStringContainsString('private void scheduleUpdateCheck()', $mainActivity);
        $this->assertStringContainsString('view.addJavascriptInterface(new MartinSolsNativeAppBridge(), "MartinSolsNativeApp")', $mainActivity);
        $this->assertStringContainsString('import android.webkit.JavascriptInterface;', $mainActivity);
        $this->assertStringContainsString('private void checkForAppUpdate(boolean notifyWhenCurrent)', $mainActivity);
        $this->assertStringContainsString('private AppUpdate fetchAppUpdate()', $mainActivity);
        $this->assertStringContainsString('JSONObject manifest = new JSONObject(readText(connection.getInputStream()));', $mainActivity);
        $this->assertStringContainsString('update.versionCode <= BuildConfig.VERSION_CODE', $mainActivity);
        $this->assertStringContainsString('private void showNoUpdateDialog()', $mainActivity);
        $this->assertStringContainsString('private final class MartinSolsNativeAppBridge', $mainActivity);
        $this->assertStringContainsString('checkForAppUpdate(true)', $mainActivity);
        $this->assertStringContainsString('private void showUpdateDialog(AppUpdate update)', $mainActivity);
        $this->assertStringContainsString('private void startUpdateDownload(AppUpdate update)', $mainActivity);
        $this->assertStringContainsString('private void showUpdateProgressDialog(AppUpdate update)', $mainActivity);
        $this->assertStringContainsString('private void pollUpdateDownloadProgress()', $mainActivity);
        $this->assertStringContainsString('private void updateProgressUi(String message, int progress, boolean indeterminate)', $mainActivity);
        $this->assertStringContainsString('private void showUpdateFailure(String message)', $mainActivity);
        $this->assertStringContainsString('private void cancelActiveUpdateDownload(boolean showMessage)', $mainActivity);
        $this->assertStringContainsString('DownloadManager.Query query = new DownloadManager.Query();', $mainActivity);
        $this->assertStringContainsString('query.setFilterById(updateDownloadId);', $mainActivity);
        $this->assertStringContainsString('DownloadManager.COLUMN_BYTES_DOWNLOADED_SO_FAR', $mainActivity);
        $this->assertStringContainsString('DownloadManager.COLUMN_TOTAL_SIZE_BYTES', $mainActivity);
        $this->assertStringContainsString('DownloadManager.STATUS_SUCCESSFUL', $mainActivity);
        $this->assertStringContainsString('DownloadManager.STATUS_FAILED', $mainActivity);
        $this->assertStringContainsString('DownloadManager.STATUS_PAUSED', $mainActivity);
        $this->assertStringContainsString('updateProgressBar.setIndeterminate(true);', $mainActivity);
        $this->assertStringContainsString('progress + " %"', $mainActivity);
        $this->assertStringContainsString('Mise a jour interrompue', $mainActivity);
        $this->assertStringContainsString('Autorisation Android requise', $mainActivity);
        $this->assertStringContainsString('Autorisation non accordee', $mainActivity);
        $this->assertStringContainsString('Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES', $mainActivity);
        $this->assertStringContainsString('Settings.ACTION_BIOMETRIC_ENROLL', $mainActivity);
        $this->assertStringContainsString('Settings.ACTION_SECURITY_SETTINGS', $mainActivity);
        $this->assertStringContainsString('Settings.ACTION_SETTINGS', $mainActivity);
        $this->assertStringContainsString('private AppUpdate fetchAppUpdateFromUrl(String manifestUrl, boolean githubContentsResponse)', $mainActivity);
        $this->assertStringContainsString('connection.setUseCaches(false);', $mainActivity);
        $this->assertStringContainsString('connection.setRequestProperty("Cache-Control", "no-cache");', $mainActivity);
        $this->assertStringContainsString('connection.setRequestProperty("User-Agent", "Martin-Sols-Android/" + BuildConfig.VERSION_NAME);', $mainActivity);
        $this->assertStringContainsString('private JSONObject decodeGitHubContentsManifest(JSONObject response)', $mainActivity);
        $this->assertStringContainsString('Base64.decode(encodedContent, Base64.DEFAULT)', $mainActivity);
        $this->assertStringContainsString('DownloadManager.Request request = new DownloadManager.Request(Uri.parse(update.apkUrl));', $mainActivity);
        $this->assertStringContainsString('DownloadManager.ACTION_DOWNLOAD_COMPLETE', $mainActivity);
        $this->assertStringContainsString('downloadManager.getUriForDownloadedFile(updateDownloadId)', $mainActivity);
        $this->assertStringContainsString('pendingUpdateSha256.equalsIgnoreCase(downloadedSha256)', $mainActivity);
        $this->assertStringContainsString('MessageDigest.getInstance("SHA-256")', $mainActivity);
        $this->assertStringContainsString('MOBILE_AUTH_APP_CODE_HASH', $mainActivity);
        $this->assertStringContainsString('PBKDF2WithHmacSHA256', $mainActivity);
        $this->assertStringContainsString('private void showSetAppCodeDialog()', $mainActivity);
        $this->assertStringContainsString('private void showAppCodePrompt(String requestId)', $mainActivity);
        $this->assertStringContainsString('private boolean openDeviceSecuritySettings()', $mainActivity);
        $this->assertStringContainsString('private boolean openSettingsActivity(String action)', $mainActivity);
        $this->assertStringContainsString('public String setAppCode()', $mainActivity);
        $this->assertStringContainsString('public String clearAppCode()', $mainActivity);
        $this->assertStringContainsString('public String openDeviceSecuritySettings()', $mainActivity);
        $this->assertStringContainsString('public String requestLocation(String requestId, boolean highAccuracy)', $mainActivity);
        $this->assertStringContainsString('private String runTrustedNativeAction(Runnable action, String message)', $mainActivity);
        $this->assertStringContainsString('if (!MainActivity.this.openDeviceSecuritySettings())', $mainActivity);
        $this->assertStringContainsString('private void showNativeActionFailure(String message)', $mainActivity);
        $this->assertStringContainsString('setTitle("Action impossible")', $mainActivity);
        $this->assertStringContainsString('private String nativeActionResult(boolean ok, String message)', $mainActivity);
        $this->assertStringContainsString('APP_SETTINGS_OVERRIDE_ASSET = "app-settings-override.js"', $mainActivity);
        $this->assertStringContainsString('private void injectAppSettingsOverride(WebView targetWebView)', $mainActivity);
        $this->assertStringContainsString('targetWebView.evaluateJavascript(readText(getAssets().open(APP_SETTINGS_OVERRIDE_ASSET)), null)', $mainActivity);
        $this->assertStringContainsString('public void onPageFinished(WebView view, String url)', $mainActivity);
        $this->assertStringContainsString('updateTrustedCrmPage(url);', $mainActivity);
        $this->assertStringContainsString('injectAppSettingsOverride(view);', $mainActivity);
        $this->assertStringContainsString('window.MartinSolsAndroidSettingsOverride', $androidSettingsOverride);
        $this->assertStringContainsString('data-crm-mobile-settings-toggle', $androidSettingsOverride);
        $this->assertStringContainsString('stopImmediatePropagation', $androidSettingsOverride);
        $this->assertStringContainsString('data-martin-sols-native-settings', $androidSettingsOverride);
        $this->assertStringContainsString('ms-native-settings-group', $androidSettingsOverride);
        $this->assertStringNotContainsString('ms-native-settings-methods', $androidSettingsOverride);
        $this->assertStringContainsString('Paramétrage de l’application', $androidSettingsOverride);
        $this->assertStringContainsString('Sécurité de l’appareil', $androidSettingsOverride);
        $this->assertStringContainsString('Code app Martin Sols', $androidSettingsOverride);
        $this->assertStringContainsString('Activer la connexion rapide', $androidSettingsOverride);
        $this->assertStringContainsString('/api/mobile/native-session', $androidSettingsOverride);
        $this->assertStringContainsString('function requestNativeLocation()', $androidSettingsOverride);
        $this->assertStringContainsString('nativeBridge.requestLocation(requestId, Boolean(settings.highAccuracyLocation))', $androidSettingsOverride);
        $this->assertStringContainsString('Localisation terrain', $androidSettingsOverride);
        $this->assertStringContainsString('Rechercher une mise à jour', $androidSettingsOverride);
        $this->assertStringContainsString("callNative('checkForUpdates'", $androidSettingsOverride);
        $this->assertStringContainsString("callNative('setAppCode'", $androidSettingsOverride);
        $this->assertStringContainsString("callNative('openDeviceSecuritySettings'", $androidSettingsOverride);
        $this->assertStringContainsString("callNative('clearAppCode'", $androidSettingsOverride);
        $this->assertStringContainsString("callNative('clearMobileSession'", $androidSettingsOverride);
        $this->assertStringContainsString('function nativeResult(value)', $androidSettingsOverride);
        $this->assertStringContainsString('result.ok === false', $androidSettingsOverride);
        $this->assertStringContainsString('private static final class AppUpdate', $mainActivity);
        $this->assertStringContainsString('ACCESS_FINE_LOCATION', $androidManifest);
        $this->assertStringContainsString('ACCESS_COARSE_LOCATION', $androidManifest);
        $this->assertStringContainsString('USE_BIOMETRIC', $androidManifest);
        $this->assertStringContainsString('hardware.location.gps', $androidManifest);
        $this->assertStringContainsString('REQUEST_INSTALL_PACKAGES', $androidManifest);
        $this->assertStringNotContainsString('@capacitor/geolocation', $mobilePackage);
        $this->assertStringNotContainsString('@capacitor/geolocation', $mobilePackageLock);
        $this->assertStringNotContainsString('@capacitor/device', $mobilePackage);
        $this->assertStringNotContainsString('@capacitor/device', $mobilePackageLock);
        $this->assertStringNotContainsString('@capacitor/network', $mobilePackage);
        $this->assertStringNotContainsString('@capacitor/network', $mobilePackageLock);
        $this->assertStringNotContainsString('@capacitor/preferences', $mobilePackage);
        $this->assertStringNotContainsString('@capacitor/preferences', $mobilePackageLock);
        $this->assertFileExists($openingAnimation);
        $this->assertFileDoesNotExist(base_path('mobile/src/assets/opening-animation.mp4'));
        $this->assertSame('d965677ac8cab41cdf18f7009171f4d44ab8bc9db23c28ee4aec5b3042f5298b', hash_file('sha256', $openingAnimation));
        $this->assertFileExists($androidIntroAnimation);
        $this->assertFileDoesNotExist(base_path('mobile/android/app/src/main/assets/intro.mp4'));
        $this->assertFileDoesNotExist(base_path('mobile/android/app/src/main/assets/intro.gif'));
        $this->assertFileExists(base_path('mobile/android/app/src/main/assets/app-settings-override.js'));
        $this->assertSame('f75ea6261193493c901fd8fb101c54eed36ab961d776056b7389b56d46c3f6d8', hash_file('sha256', $androidIntroAnimation));
        $this->assertFileDoesNotExist(base_path('mobile/android/app/src/main/assets/splash.html'));
        $this->assertFileDoesNotExist(base_path('mobile/android/app/src/main/res/drawable/splash.png'));
        $this->assertFileDoesNotExist(base_path('mobile/android/app/src/main/res/layout/activity_main.xml'));
        $this->assertFileDoesNotExist(base_path('mobile/android/app/src/main/res/xml/config.xml'));
        $this->assertFileDoesNotExist(base_path('mobile/android/app/src/main/res/xml/file_paths.xml'));
        $this->assertStringContainsString("const openingAnimationUrl = new URL('./assets/opening-animation.gif', import.meta.url).href", $mobileApp);
        $this->assertStringContainsString("const defaultCrmUrl = 'https://crm.jp2.fr/?mobile_app=1&source=ios_app'", $mobileApp);
        $this->assertStringContainsString('function normalizeCrmUrl(value: string): string', $mobileApp);
        $this->assertStringContainsString("url.searchParams.set('mobile_app', '1')", $mobileApp);
        $this->assertStringContainsString('const openingAnimationDurationMs = 5500', $mobileApp);
        $this->assertStringContainsString('const startupIntro = renderStartup()', $mobileApp);
        $this->assertStringContainsString('class="startup-intro-media"', $mobileApp);
        $this->assertStringContainsString('const animationTimer = window.setTimeout(finish, openingAnimationDurationMs)', $mobileApp);
        $this->assertStringContainsString("introImage.addEventListener('error', complete, { once: true })", $mobileApp);
        $this->assertStringNotContainsString("video.addEventListener('ended'", $mobileApp);
        $this->assertStringContainsString('function openCrmWebView(): void', $mobileApp);
        $this->assertStringContainsString('window.location.replace(crmUrl)', $mobileApp);
        $this->assertStringNotContainsString('/api/mobile', $mobileApp);
        $this->assertStringNotContainsString('redirectPath', $mobileApp);
        $this->assertStringNotContainsString('plain: true', $mobileApp);
        $this->assertStringNotContainsString('renderLogin', $mobileApp);
        $this->assertStringNotContainsString('data-open-settings', $mobileApp);
        $this->assertStringNotContainsString('Geolocation', $mobileApp);
        $this->assertStringNotContainsString('CapacitorHttp', $mobileApp);
        $this->assertStringContainsString("document.documentElement.classList.add('crm-native-handoff')", $mobileApp);
        $this->assertStringContainsString("app.innerHTML = ''", $mobileApp);
        $this->assertStringContainsString('versionCode 56', $androidBuild);
        $this->assertStringContainsString('versionName "1.54"', $androidBuild);
        $this->assertStringContainsString('buildConfig = true', $androidBuild);
        $this->assertStringContainsString('MARTIN_SOLS_ANDROID_KEYSTORE_PATH', $androidBuild);
        $this->assertStringContainsString('MARTIN_SOLS_ANDROID_KEYSTORE_PASSWORD', $androidBuild);
        $this->assertStringContainsString('MARTIN_SOLS_ANDROID_KEY_ALIAS', $androidBuild);
        $this->assertStringContainsString('MARTIN_SOLS_ANDROID_KEY_PASSWORD', $androidBuild);
        $this->assertStringNotContainsString('capacitor', $androidSettings);
        $this->assertStringNotContainsString('capacitor', $androidBuild);
        $this->assertStringNotContainsString('appcompat', $androidBuild);
        $this->assertStringNotContainsString('core-splashscreen', $androidBuild);
        $this->assertSame(1, $androidUpdateManifest['schemaVersion']);
        $this->assertSame('Martin Sols', $androidUpdateManifest['appName']);
        $this->assertIsInt($androidUpdateManifest['android']['versionCode']);
        $this->assertGreaterThanOrEqual(38, $androidUpdateManifest['android']['versionCode']);
        $this->assertMatchesRegularExpression('/^1\\.\\d+$/', $androidUpdateManifest['android']['versionName']);
        $this->assertStringStartsWith('https://github.com/jp2creation/hub/releases/download/martin-sols-android-v', $androidUpdateManifest['android']['apkUrl']);
        $this->assertStringContainsString('/Martin_Sols_', $androidUpdateManifest['android']['apkUrl']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $androidUpdateManifest['android']['sha256']);
        $this->assertSame('app-store-or-testflight', $androidUpdateManifest['ios']['distribution']);
        $this->assertStringContainsString('name: Martin Sols Android release', $androidReleaseWorkflow);
        $this->assertStringContainsString('workflow_dispatch:', $androidReleaseWorkflow);
        $this->assertStringContainsString('MARTIN_SOLS_ANDROID_KEYSTORE_BASE64', $androidReleaseWorkflow);
        $this->assertStringContainsString('mobile/releases/martin-sols-update.json', $androidReleaseWorkflow);
        $this->assertStringContainsString('gh release create "$TAG_NAME" "$APK_NAME"', $androidReleaseWorkflow);
        $this->assertStringContainsString('<color name="martin_sols_red">#95002E</color>', $androidColors);
        $this->assertStringContainsString('<color name="martin_sols_background">#F5F7FB</color>', $androidColors);
        $this->assertStringContainsString('parent="@android:style/Theme.Material.Light.NoActionBar"', $androidStyles);
        $this->assertStringNotContainsString('Theme.AppCompat', $androidStyles);
        $this->assertStringNotContainsString('Theme.SplashScreen', $androidStyles);
        $this->assertStringContainsString('<item name="android:windowNoTitle">true</item>', $androidStyles);
        $this->assertStringContainsString('<item name="android:windowActionBar">false</item>', $androidStyles);
        $this->assertStringNotContainsString('<item name="windowNoTitle">true</item>', $androidStyles);
        $this->assertStringNotContainsString('<item name="windowActionBar">false</item>', $androidStyles);
        $this->assertStringNotContainsString('postSplashScreenTheme', $androidStyles);
        $this->assertStringNotContainsString('<item name="android:background">@drawable/splash</item>', $androidStyles);
        $this->assertStringContainsString('<item name="android:statusBarColor">@color/martin_sols_red</item>', $androidStyles);
        $this->assertStringContainsString('<item name="android:windowTranslucentStatus">false</item>', $androidStyles);
        $this->assertStringContainsString('<item name="android:windowLightStatusBar">false</item>', $androidStyles);
        $this->assertStringContainsString('StatusBar.setStyle({ style: Style.Dark })', $mobileApp);
        $this->assertStringContainsString("StatusBar.setBackgroundColor({ color: '#95002e' })", $mobileApp);
        $this->assertStringNotContainsString('StatusBar.setOverlaysWebView', $mobileApp);
        $this->assertStringNotContainsString('startup-copy', $mobileApp);
        $this->assertStringContainsString('data-crm-mobile-check-update', $mobileSettings);
        $this->assertStringContainsString('crm-mobile-app-settings-content', $mobileSettings);
        $this->assertStringContainsString('crm-mobile-app-settings-group', $mobileSettings);
        $this->assertStringContainsString('crm-mobile-app-settings-group-title', $mobileSettings);
        $this->assertStringContainsString('crm-mobile-app-settings-row', $mobileSettings);
        $this->assertStringContainsString('Paramétrage de l’application', $mobileSettings);
        $this->assertStringContainsString('Plateforme', $mobileSettings);
        $this->assertStringContainsString('Tester la localisation', $mobileSettings);
        $this->assertStringContainsString('data-crm-mobile-clear-auth', $mobileSettings);
        $this->assertStringContainsString('data-crm-mobile-device-security', $mobileSettings);
        $this->assertStringContainsString('data-crm-mobile-auth-summary', $mobileSettings);
        $this->assertStringContainsString('data-crm-mobile-set-app-code', $mobileSettings);
        $this->assertStringContainsString('data-crm-mobile-set-app-code-label', $mobileSettings);
        $this->assertStringContainsString('data-crm-mobile-clear-app-code', $mobileSettings);
        $this->assertStringContainsString('Sécurité de l’appareil', $mobileSettings);
        $this->assertStringContainsString('Empreinte, visage ou code appareil', $mobileSettings);
        $this->assertStringContainsString('Ouverture des réglages iOS.', $mobileSettings);
        $this->assertStringContainsString('Ouverture des réglages de sécurité macOS.', $mobileSettings);
        $this->assertStringContainsString('Ouverture des réglages de sécurité Android.', $mobileSettings);
        $this->assertStringContainsString('Code app Martin Sols', $mobileSettings);
        $this->assertStringContainsString('Localisation terrain', $mobileSettings);
        $this->assertStringContainsString('Informations', $mobileSettings);
        $this->assertStringContainsString('Code app', $mobileSettings);
        $this->assertStringContainsString('platformLabel()', $mobileSettings);
        $this->assertStringContainsString('settingsIcon(', $mobileSettings);
        $this->assertStringContainsString('getMobileAuthStatus', $mobileSettings);
        $this->assertStringContainsString('/api/mobile/native-session', $mobileSettings);
        $this->assertStringContainsString('requestLocation?: (requestId: string, highAccuracy: boolean) => NativeBridgeResponse;', $mobileSettings);
        $this->assertStringContainsString('getPlatformName?: () => string;', $mobileSettings);
        $this->assertStringContainsString('martin-sols:native-location-result', $mobileSettings);
        $this->assertStringContainsString('clearMobileSession', $mobileSettings);
        $this->assertStringContainsString('setAppCode', $mobileSettings);
        $this->assertStringContainsString('clearAppCode', $mobileSettings);
        $this->assertStringContainsString('openDeviceSecuritySettings', $mobileSettings);
        $this->assertStringContainsString('window.MartinSolsNativeApp', $mobileSettings);
        $this->assertStringContainsString('Boolean(window.MartinSolsNativeApp)', $mobileSettings);
        $this->assertStringContainsString('checkForUpdates: requestUpdateCheck', $mobileSettings);
        $this->assertStringContainsString('data-native-login', $loginBlade);
        $this->assertStringContainsString('Connexion rapide', $loginBlade);
        $this->assertStringContainsString('/api/mobile/token', $loginBlade);
        $this->assertStringContainsString('/api/mobile/web-session', $loginBlade);
        $this->assertStringContainsString('/api/mobile/refresh', $loginBlade);
        $this->assertStringContainsString('authenticateSavedMobileSession', $loginBlade);
        $this->assertStringContainsString('saveMobileSession', $loginBlade);
        $this->assertStringContainsString('await saveNativeSession(session)', $loginBlade);
        $this->assertStringContainsString('async function nativeResult(value)', $loginBlade);
        $this->assertStringContainsString('nativeApp.getPlatformName?.()', $loginBlade);
        $this->assertStringContainsString('clearMobileSession', $loginBlade);
        $this->assertStringNotContainsString('crm-mobile-app-settings-trigger', $mobileSettings);
        $this->assertStringNotContainsString('crm-mobile-app-settings-backdrop', $mobileSettings);
        $this->assertStringNotContainsString('.startup-copy', $mobileStyles);
        $this->assertStringNotContainsString('auth-settings-button', $mobileStyles);
        $this->assertStringNotContainsString('crm-app-settings-button', $mobileStyles);
        $this->assertStringNotContainsString('startup-crm-loader', $mobileStyles);
        $this->assertStringContainsString('.startup-intro-media', $mobileStyles);
        $this->assertStringContainsString('html.crm-native-handoff #app', $mobileStyles);
        $this->assertStringContainsString('body.crm-mobile-app-settings-open', $shellCss);
        $this->assertStringContainsString('.crm-mobile-app-settings-content', $shellCss);
        $this->assertStringContainsString('.crm-mobile-app-settings-group', $shellCss);
        $this->assertStringContainsString('.crm-mobile-app-settings-group-title', $shellCss);
        $this->assertStringContainsString('.crm-mobile-app-settings-row-icon', $shellCss);
        $this->assertStringContainsString('.crm-mobile-app-settings-pill', $shellCss);
        $this->assertStringContainsString('.crm-mobile-app-settings-pill.is-warn', $shellCss);
        $this->assertStringContainsString('.crm-mobile-app-settings-mini-action', $shellCss);
        $this->assertStringContainsString('width: min(100%, 560px);', $shellCss);
        $this->assertStringContainsString('min-height: 100dvh;', $shellCss);
        $this->assertStringNotContainsString('.crm-mobile-app-settings-trigger', $shellCss);
        $this->assertStringNotContainsString('.crm-mobile-app-settings-backdrop', $shellCss);
        $this->assertStringNotContainsString('place-items: end center;', $shellCss);
        $this->assertStringNotContainsString('width: min(100%, 430px);', $shellCss);
        $this->assertStringNotContainsString('max-height: min(86dvh, 620px);', $shellCss);
        $this->assertStringContainsString('"overlaysWebView": false', $capacitorConfig);
        $this->assertStringContainsString('"style": "DARK"', $capacitorConfig);
        $this->assertStringContainsString('"backgroundColor": "#95002e"', $capacitorConfig);
        $this->assertStringNotContainsString('CapacitorHttp', $capacitorConfig);
        $this->assertStringNotContainsString('SplashScreen', $capacitorConfig);
        $this->assertStringContainsString('WKScriptMessageHandlerWithReply', $iosBridge);
        $this->assertStringContainsString('import LocalAuthentication', $iosBridge);
        $this->assertStringContainsString('import CoreLocation', $iosBridge);
        $this->assertStringContainsString('import Security', $iosBridge);
        $this->assertStringContainsString('private func saveMobileSessionPayload(_ payload: String) -> [String: Any]', $iosBridge);
        $this->assertStringContainsString('private func showSetAppCodeDialog(reply:', $iosBridge);
        $this->assertStringContainsString('private func showAppCodePrompt(requestId: String)', $iosBridge);
        $this->assertStringContainsString('private func requestNativeLocation(requestId: String, highAccuracy: Bool) -> [String: Any]', $iosBridge);
        $this->assertStringContainsString('private func dispatchNativeLocationResult(requestId: String, location: CLLocation?, error: String)', $iosBridge);
        $this->assertStringContainsString('getPlatformName() {', $iosBridge);
        $this->assertStringContainsString('NSFaceIDUsageDescription', $iosInfoPlist);
        $this->assertStringContainsString('WKScriptMessageHandlerWithReply', $macApp);
        $this->assertStringContainsString('import LocalAuthentication', $macApp);
        $this->assertStringContainsString('import CoreLocation', $macApp);
        $this->assertStringContainsString('import Security', $macApp);
        $this->assertStringContainsString('private func saveMobileSessionPayload(_ payload: String) -> [String: Any]', $macApp);
        $this->assertStringContainsString('private func showSetAppCodeDialog() -> [String: Any]', $macApp);
        $this->assertStringContainsString('private func showAppCodePrompt(requestId: String)', $macApp);
        $this->assertStringContainsString('private func requestNativeLocation(requestId: String, highAccuracy: Bool) -> [String: Any]', $macApp);
        $this->assertStringContainsString('private func dispatchNativeLocationResult(requestId: String, location: CLLocation?, error: String)', $macApp);
        $this->assertStringContainsString('document.documentElement.classList.add(\'crm-mac-app\')', $macApp);
        $this->assertStringContainsString('getPlatformName() {', $macApp);
        $this->assertStringContainsString('NSLocationUsageDescription', $macInfoPlist);
        $this->assertStringContainsString('@import "./template-compat/variables.css"', $templateCompatCss);
        $this->assertStringContainsString('@import "./template-compat/components.css"', $templateCompatCss);
        $this->assertStringContainsString('--theme-primary: 149 0 46;', $templateVariablesCss);
        $this->assertStringContainsString('--theme-accent: 245 178 18;', $templateVariablesCss);
        $this->assertStringContainsString('--color-secondary-900: #1d354f;', $templateVariablesCss);
        $this->assertStringContainsString('--shadow-card:', $templateVariablesCss);
        $this->assertStringContainsString('.card', $templateComponentsCss);
        $this->assertStringContainsString('.btn-primary', $templateComponentsCss);
        $this->assertStringContainsString('window.MartinSolsUi', $nativeUi);
        $this->assertStringContainsString('renderSegmentControl', $nativeUi);
        $this->assertStringContainsString('renderProductGrid', $nativeUi);
        $this->assertStringContainsString('bindNavigation', $nativeUi);
        $this->assertStringContainsString('setTemplateDefaults', $nativeUi);
        $this->assertStringContainsString('crm-ui-route-transitioning', $nativeUi);
        $this->assertStringContainsString("html.dataset.cardStyle = html.dataset.cardStyle || 'shadow'", $nativeUi);
        $this->assertStringContainsString('--crm-header-height: 64px;', $shellCss);
        $this->assertStringContainsString('margin-left: auto;', $shellCss);
        $this->assertStringContainsString('html.crm-ui-route-transitioning .crm-native-content', $shellCss);
        $this->assertStringContainsString('crm-template-fade-in', $shellCss);
        $this->assertStringContainsString('body.ms-ui-modal-open', $nativeUiCss);
        $this->assertStringContainsString('--ms-ui-shadow-card', $nativeUiCss);
        $this->assertStringContainsString('.dash-card', $nativeUiCss);
        $this->assertStringContainsString('.ms-ui-product-card', $nativeUiCss);
        $this->assertStringContainsString('.resa-product-card', $nativeUiCss);
        $this->assertStringContainsString('.rent-product-card', $nativeUiCss);
        $this->assertStringContainsString('.ms-ui-segment', $nativeUiCss);
        $this->assertStringContainsString('.resa-dialog', $nativeUiCss);
        $this->assertStringContainsString('.rent-dialog', $nativeUiCss);
    }

    public function test_static_assets_keep_only_brand_and_pwa_files(): void
    {
        $assetsDir = resource_path('frontend/static/assets');

        $this->assertFileExists($assetsDir.'/logo/martin-sols-logo.png');
        $this->assertFileExists($assetsDir.'/logo/logomark.png');
        $this->assertFileExists($assetsDir.'/pwa/icon-192.png');
        $this->assertFileExists($assetsDir.'/pwa/icon-512.png');
        $this->assertFileDoesNotExist($assetsDir.'/logo/logo.svg');
        $this->assertFileDoesNotExist($assetsDir.'/logo/logo-dark.svg');
        $this->assertSame([], glob($assetsDir.'/*.js') ?: []);
        $this->assertSame([], glob($assetsDir.'/*.css') ?: []);
        $this->assertDirectoryDoesNotExist($assetsDir.'/products');
        $this->assertDirectoryDoesNotExist($assetsDir.'/gallery');
        $this->assertDirectoryDoesNotExist($assetsDir.'/avatars');

        $androidIconDir = base_path('mobile/android/app/src/main/res/mipmap-xxxhdpi');
        $this->assertFileExists($androidIconDir.'/ic_launcher.png');
        $this->assertFileExists($androidIconDir.'/ic_launcher_round.png');
        $this->assertFileExists($androidIconDir.'/ic_launcher_foreground.png');
        $this->assertSame([192, 192], array_slice(getimagesize($androidIconDir.'/ic_launcher.png') ?: [], 0, 2));
        $this->assertSame([432, 432], array_slice(getimagesize($androidIconDir.'/ic_launcher_foreground.png') ?: [], 0, 2));
        $this->assertSame('4a6c9c82f0c23541c06922add7ae96b41f6e01bc849a44c54ebddc7b5369dbd1', hash_file('sha256', $androidIconDir.'/ic_launcher.png'));
        $this->assertSame('7e7a85e18e6f16800e42e4e5c89bb58708a2c86f3d49ee832a4ae33b63e1abb7', hash_file('sha256', $androidIconDir.'/ic_launcher_foreground.png'));
    }

    public function test_conges_cards_use_global_background_theme(): void
    {
        $asset = (string) file_get_contents(base_path('Modules/CrmLeaves/resources/assets/crm-conges.js'));

        $this->assertStringContainsString('--leave-panel:#fff;', $asset);
        $this->assertStringContainsString('--leave-panel-soft:var(--color-secondary-50,#fafafa);', $asset);
        $this->assertStringContainsString('--leave-card-border:var(--color-surface-200,#e4e4e7);', $asset);
        $this->assertStringContainsString('--leave-card-radius:var(--radius,.625rem);', $asset);
        $this->assertStringContainsString('--leave-card-shadow:var(--shadow-card,0 1px 2px rgb(0 0 0 / 0.04),0 8px 24px -4px rgb(0 0 0 / 0.06));', $asset);
        $this->assertStringContainsString('background:var(--color-secondary-50,#fafafa);', $asset);
        $this->assertStringContainsString('background:var(--leave-panel);', $asset);
        $this->assertStringContainsString('background:var(--leave-panel-soft);', $asset);
        $this->assertStringContainsString('border:1px solid var(--leave-card-border);', $asset);
        $this->assertStringContainsString('border-radius:var(--leave-card-radius);', $asset);
        $this->assertStringContainsString('box-shadow:var(--leave-card-shadow);', $asset);
        $this->assertStringContainsString('<div class="leave-header-top">', $asset);
        $this->assertStringContainsString('.layout-container.layout-page:has(#crm-leaves-module),', $asset);
        $this->assertStringContainsString('.layout-page:has(#crm-leaves-module){width:100%;max-width:100%;min-width:0;overflow-x:hidden}', $asset);
        $this->assertStringContainsString('main:has(#crm-leaves-module){min-width:0;overflow-x:hidden}', $asset);
        $this->assertStringContainsString('<em>${esc(card.detail)}</em>', $asset);
        $this->assertStringContainsString('<p class="leave-header-subtitle">${esc(activeSiteName())}</p>', $asset);
        $this->assertStringContainsString('#crm-leaves-module .leave-header-subtitle', $asset);
        $this->assertStringContainsString('grid-template-columns:minmax(0,1fr);', $asset);
        $this->assertStringContainsString('grid-column:1/-1;', $asset);
        $this->assertStringContainsString('inline-size:100%;', $asset);
        $this->assertStringContainsString('max-inline-size:none;', $asset);
        $this->assertStringContainsString('grid-template-columns:repeat(4,minmax(0,1fr));', $asset);
        $this->assertStringContainsString('justify-items:stretch;', $asset);
        $this->assertStringContainsString('justify-self:stretch;', $asset);
        $this->assertStringContainsString('background:transparent;', $asset);
        $this->assertStringContainsString('box-shadow:none;', $asset);
        $this->assertStringContainsString('grid-template-columns:2.6rem minmax(0,1fr);', $asset);
        $this->assertStringContainsString('box-shadow:0 12px 28px rgba(15,23,42,.05);', $asset);
        $this->assertStringContainsString('min-height:2.35rem;', $asset);
        $this->assertStringContainsString('border-radius:.5rem;', $asset);
        $this->assertStringContainsString('box-shadow:0 10px 24px rgba(15,23,42,.04);', $asset);
        $this->assertStringContainsString('content:none;', $asset);
        $this->assertStringContainsString('font-size:1.45rem;', $asset);

        $headerStart = strpos($asset, 'function renderHeader()');
        $headerEnd = strpos($asset, 'function icon(name)', $headerStart ?: 0);
        $calendarStart = strpos($asset, 'function renderCalendar()');
        $calendarEnd = strpos($asset, 'function renderWorkflowPanel()', $calendarStart ?: 0);
        $balancesStart = strpos($asset, 'function renderBalancesPanel()');
        $balancesEnd = strpos($asset, 'function renderRequestsTable()', $balancesStart ?: 0);
        $balancePanelStart = strpos($asset, 'function renderBalancePanel()');
        $balancePanelEnd = strpos($asset, 'async function request(', $balancePanelStart ?: 0);

        if (
            $headerStart === false ||
            $headerEnd === false ||
            $calendarStart === false ||
            $calendarEnd === false ||
            $balancesStart === false ||
            $balancesEnd === false ||
            $balancePanelStart === false ||
            $balancePanelEnd === false
        ) {
            $this->fail('Le module Congés ne contient plus les fonctions de rendu attendues.');
        }

        $headerSource = substr($asset, $headerStart, $headerEnd - $headerStart);
        $calendarSource = substr($asset, $calendarStart, $calendarEnd - $calendarStart);
        $balancesSource = substr($asset, $balancesStart, $balancesEnd - $balancesStart);
        $balancePanelSource = substr($asset, $balancePanelStart, $balancePanelEnd - $balancePanelStart);

        $this->assertStringContainsString('${renderTopMetrics()}', $headerSource);
        $this->assertStringContainsString('leave-header-subtitle', $headerSource);
        $this->assertStringNotContainsString('leave-header-icons', $headerSource);
        $this->assertStringNotContainsString('leave-round-icon', $headerSource);
        $this->assertStringNotContainsString('data-filter-focus', $asset);
        $this->assertStringNotContainsString('${renderTopMetrics()}', $calendarSource);
        $this->assertStringNotContainsString('${renderTopMetrics()}', $balancesSource);
        $this->assertStringNotContainsString('leave-profile-card', $balancePanelSource);
        $this->assertStringNotContainsString('leave-balance-grid', $balancePanelSource);
        $this->assertStringContainsString('leave-type-card leave-sidebar-card', $balancePanelSource);
        $this->assertStringContainsString('Absences par type', $balancePanelSource);

        foreach ([
            '#fffdfa',
            '#fbfaf7',
            '#f4f2ee',
            '#f8f7f4',
            '#fbfaf6',
            '#f3f6fb',
            '#ddd9cf',
            '#dedbd3',
            '#f6edf1',
            '#f8e9ef',
            '#f0efeb',
        ] as $legacyBackground) {
            $this->assertStringNotContainsString($legacyBackground, $asset);
        }
    }

    public function test_deployment_archive_includes_vite_build_output(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy-planethoster.sh'));
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        $this->assertStringContainsString('tar -rf "$LOCAL_ARCHIVE_TAR" public/build', $script);
        $this->assertStringContainsString('if [ -d public/build ]; then', $script);
        $this->assertStringNotContainsString('if [ "$CRM_DEPLOY_BUILD" != "0" ] && [ -d public/build ]; then', $script);
        $this->assertStringContainsString('Manifest Vite absent: ${RELEASE_DIR}/public/build/manifest.json', $script);
        $this->assertStringContainsString('php artisan hub:publish-static-assets --force --clean', $script);
        $this->assertStringContainsString('php artisan hub:publish-module-assets --force', $script);
        $this->assertStringContainsString('gzip -c "$LOCAL_ARCHIVE_TAR" > "$LOCAL_ARCHIVE"', $script);
        $this->assertStringContainsString('RELEASES_DIR="${CRM_DEPLOY_ROOT}/releases"', $script);
        $this->assertStringContainsString('SHARED_DIR="${CRM_DEPLOY_ROOT}/shared"', $script);
        $this->assertStringContainsString('CURRENT_LINK="${CRM_DEPLOY_ROOT}/current"', $script);
        $this->assertStringContainsString('mv -Tf "$NEXT_LINK" "$CURRENT_LINK"', $script);
        $this->assertStringContainsString('rollback_current', $script);
        $this->assertStringContainsString('curl -fsS --max-time 10 "$health_url"', $script);
        $this->assertStringContainsString('php artisan horizon:terminate || true', $script);
        $this->assertStringContainsString('cleanup_old_releases', $script);
        $this->assertStringContainsString("--exclude='storage/redis'", $script);
        $this->assertStringContainsString("--exclude='storage/framework/cache'", $script);
        $this->assertStringContainsString('/public/assets', $gitignore);
        $this->assertStringContainsString('/public/modules', $gitignore);
    }

    public function test_site_contact_and_color_are_exposed_in_frontend_modules(): void
    {
        $teams = (string) file_get_contents(base_path('Modules/CrmTeams/resources/assets/crm-equipes.js'));
        $administration = (string) file_get_contents(base_path('Modules/CrmAdministration/resources/assets/crm-administration.js'));
        $activeSite = (string) file_get_contents(base_path('Modules/CrmCore/resources/assets/crm-active-site.js'));

        $this->assertStringContainsString('function renderSiteInfo(site)', $teams);
        $this->assertStringContainsString('Informations du site sélectionné', $teams);
        $this->assertStringContainsString('${siteInfoItem("Adresse", site?.address || "", "mapPin")}', $teams);
        $this->assertStringContainsString('${siteInfoItem("Téléphone", site?.phone || "", "phone", site?.phone ? `tel:${phoneHref(site.phone)}` : "")}', $teams);
        $this->assertStringContainsString('style="--site-color:${esc(color)}"', $teams);

        $this->assertStringContainsString('<label>Couleur <input name="color" type="color"', $administration);
        $this->assertStringContainsString('<label>Téléphone <input name="phone" type="tel"', $administration);
        $this->assertStringContainsString('<label>Adresse <input name="address"', $administration);
        $this->assertStringContainsString('window.CRM_ACTIVE_SITE?.reload?.();', $administration);

        $this->assertStringContainsString('.crm-active-site-trigger.has-site-dot .crm-active-site-dot', $activeSite);
        $this->assertStringContainsString('.crm-active-site-control{position:relative;min-width:0;flex:1 1 auto;width:100%}', $activeSite);
        $this->assertStringContainsString('button.style.setProperty(\'--active-site-color\', selectedColor);', $activeSite);
        $this->assertStringContainsString('clamp(10rem,44vw,12rem)', $activeSite);
        $this->assertStringContainsString('crm-active-site-option-dot', $activeSite);
        $this->assertStringContainsString("'.crm-active-site-option.is-active{background:rgb(var(--theme-primary) / .11);color:rgb(var(--theme-primary))}'", $activeSite);
        $this->assertStringNotContainsString('.crm-active-site-trigger.has-site-color', $activeSite);
        $this->assertStringNotContainsString('function contrastColor(color)', $activeSite);
    }

    public function test_dom_ready_sensitive_crm_modules_boot_even_when_loaded_late(): void
    {
        foreach ([
            base_path('Modules/CrmAdministration/resources/assets/crm-administration.js'),
            base_path('Modules/CrmCashControl/resources/assets/crm-controle-caisse.js'),
            base_path('Modules/CrmCore/resources/assets/crm-account-settings.js'),
            base_path('Modules/CrmCore/resources/assets/crm-active-site.js'),
            base_path('Modules/CrmCore/resources/assets/crm-dashboard.js'),
            base_path('Modules/CrmCore/resources/assets/crm-text-fixes.js'),
            base_path('Modules/CrmEquipmentRentals/resources/assets/crm-equipment-rentals.js'),
            base_path('Modules/CrmReservations/resources/assets/crm-reservations.js'),
            base_path('Modules/CrmTapisRomus/resources/assets/crm-tapis-romus.js'),
        ] as $assetPath) {
            $asset = (string) file_get_contents($assetPath);

            $this->assertStringContainsString('document.readyState ===', $asset, $assetPath);
        }
    }
}
