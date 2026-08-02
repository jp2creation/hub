package fr.martinsols.crm;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.app.KeyguardManager;
import android.content.ActivityNotFoundException;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.DialogInterface;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Movie;
import android.hardware.biometrics.BiometricPrompt;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.CancellationSignal;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.provider.Settings;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.text.InputType;
import android.util.Base64;
import android.view.Gravity;
import android.view.MotionEvent;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.JavascriptInterface;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.security.SecureRandom;
import java.util.concurrent.Executor;
import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.SecretKeyFactory;
import javax.crypto.spec.GCMParameterSpec;
import javax.crypto.spec.PBEKeySpec;
import org.json.JSONException;
import org.json.JSONObject;

public class MainActivity extends Activity {
    private static final String HUB_URL = "https://crm.jp2.fr/?mobile_app=1";
    private static final String UPDATE_MANIFEST_API_URL = "https://api.github.com/repos/jp2creation/hub/contents/mobile/releases/martin-sols-update.json?ref=main";
    private static final String UPDATE_MANIFEST_URL = "https://raw.githubusercontent.com/jp2creation/hub/main/mobile/releases/martin-sols-update.json";
    private static final String APK_MIME_TYPE = "application/vnd.android.package-archive";
    private static final String APP_SETTINGS_OVERRIDE_ASSET = "app-settings-override.js";
    private static final String MOBILE_AUTH_PREFS = "martin_sols_mobile_auth";
    private static final String MOBILE_AUTH_KEY_ALIAS = "martin_sols_mobile_session";
    private static final String MOBILE_AUTH_SESSION_CIPHER = "session_cipher";
    private static final String MOBILE_AUTH_SESSION_IV = "session_iv";
    private static final String MOBILE_AUTH_APP_CODE_HASH = "app_code_hash";
    private static final String MOBILE_AUTH_APP_CODE_SALT = "app_code_salt";
    private static final String MOBILE_AUTH_CIPHER_TRANSFORMATION = "AES/GCM/NoPadding";
    private static final int APP_CODE_HASH_ITERATIONS = 120000;
    private static final int APP_CODE_HASH_BITS = 256;
    private static final int APP_CODE_SALT_BYTES = 16;
    private static final long SPLASH_DURATION_MS = 12600L;
    private static final long NATIVE_LOCATION_TIMEOUT_MS = 15000L;
    private static final long UPDATE_CHECK_DELAY_MS = 1500L;
    private static final long UPDATE_PROGRESS_INTERVAL_MS = 450L;
    private static final int LOCATION_PERMISSION_REQUEST_CODE = 2101;
    private static final int DEVICE_CREDENTIAL_REQUEST_CODE = 2102;
    private static final int SPLASH_ANIMATION_RESOURCE = R.raw.intro;
    private static final int SPLASH_ANIMATION_WIDTH = 720;
    private static final int SPLASH_ANIMATION_HEIGHT = 1280;
    private static final int UPDATE_PROGRESS_MAX = 100;
    private static final float INTRO_ANIMATION_WIDTH_FRACTION = 0.62f;
    private static final float INTRO_ANIMATION_HEIGHT_FRACTION = 0.62f;
    private static final int INTRO_ANIMATION_MAX_WIDTH_DP = 260;
    private static final int MARTIN_SOLS_RED = Color.rgb(149, 0, 46);
    private static final int MARTIN_SOLS_BACKGROUND = Color.rgb(245, 247, 251);
    private static final int MARTIN_SOLS_NAVIGATION = Color.rgb(17, 24, 39);
    private static final int SPLASH_BACKGROUND = Color.rgb(255, 250, 247);

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Executor mainExecutor = new Executor() {
        @Override
        public void execute(Runnable command) {
            handler.post(command);
        }
    };
    private FrameLayout rootView;
    private WebView webView;
    private FrameLayout splashLayer;
    private IntroGifView splashView;
    private AppUpdate pendingInstallPermissionUpdate;
    private BroadcastReceiver updateDownloadReceiver;
    private AlertDialog updateProgressDialog;
    private ProgressBar updateProgressBar;
    private TextView updateProgressMessage;
    private TextView updateProgressPercent;
    private long updateDownloadId = -1L;
    private String pendingUpdateSha256 = "";
    private GeolocationPermissions.Callback pendingGeolocationCallback;
    private String pendingGeolocationOrigin = "";
    private String pendingDeviceCredentialRequestId = "";
    private String pendingNativeLocationRequestId = "";
    private boolean pendingNativeLocationHighAccuracy;
    private LocationListener nativeLocationListener;
    private Runnable nativeLocationTimeout;
    private CancellationSignal biometricCancellationSignal;
    private volatile boolean trustedCrmPageActive;
    private boolean updateCheckStarted;
    private boolean updateInstallStarted;

    private final Runnable hideSplash = new Runnable() {
        @Override
        public void run() {
            hideSplashView();
        }
    };

    private final Runnable updateDownloadProgress = new Runnable() {
        @Override
        public void run() {
            pollUpdateDownloadProgress();
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        requestWindowFeature(Window.FEATURE_NO_TITLE);
        configureSystemBars();
        super.onCreate(savedInstanceState);
        getWindow().setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE);

        rootView = new FrameLayout(this);

        webView = new WebView(this);
        configureCrmWebView(webView);
        rootView.addView(webView, matchParentLayoutParams());

        splashLayer = new FrameLayout(this);
        splashLayer.setBackgroundColor(SPLASH_BACKGROUND);
        splashView = new IntroGifView(this);
        configureSplashGifView(splashView);
        splashLayer.addView(splashView, centeredMatchParentLayoutParams());
        rootView.addView(splashLayer, matchParentLayoutParams());

        setContentView(rootView);
        webView.loadUrl(HUB_URL);
        handler.postDelayed(hideSplash, SPLASH_DURATION_MS);
    }

    @Override
    protected void onResume() {
        super.onResume();
        configureSystemBars();

        if (webView != null) {
            webView.onResume();
        }

        if (pendingInstallPermissionUpdate != null) {
            if (canRequestPackageInstalls()) {
                AppUpdate update = pendingInstallPermissionUpdate;
                pendingInstallPermissionUpdate = null;
                startUpdateDownload(update);
            } else if (!isFinishing()) {
                pendingInstallPermissionUpdate = null;
                showUpdateFailure("Autorisation non accordee : Android bloque l'installation de la mise a jour.");
            }
        }
    }

    @Override
    protected void onPause() {
        if (webView != null) {
            webView.onPause();
        }

        super.onPause();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);

        if (requestCode != DEVICE_CREDENTIAL_REQUEST_CODE) {
            return;
        }

        String requestId = pendingDeviceCredentialRequestId;
        pendingDeviceCredentialRequestId = "";

        if (resultCode == RESULT_OK) {
            deliverSavedMobileSession(requestId);
            return;
        }

        dispatchNativeAuthResult(requestId, false, null, "Authentification annulee.");
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);

        if (requestCode != LOCATION_PERMISSION_REQUEST_CODE) {
            return;
        }

        GeolocationPermissions.Callback callback = pendingGeolocationCallback;
        String origin = pendingGeolocationOrigin;
        pendingGeolocationCallback = null;
        pendingGeolocationOrigin = "";

        if (callback != null && origin.length() > 0) {
            callback.invoke(origin, hasLocationPermission(), false);
        }

        if (pendingNativeLocationRequestId.length() > 0) {
            String requestId = pendingNativeLocationRequestId;
            boolean highAccuracy = pendingNativeLocationHighAccuracy;

            if (hasLocationPermission()) {
                resolveNativeLocation(requestId, highAccuracy);
                return;
            }

            cancelNativeLocationRequest();
            dispatchNativeLocationResult(requestId, null, "Autorisation GPS refusee par Android.");
        }
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return;
        }

        super.onBackPressed();
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacks(hideSplash);
        handler.removeCallbacks(updateDownloadProgress);
        cancelBiometricPrompt();
        cancelNativeLocationRequest();
        unregisterUpdateDownloadReceiver();
        dismissUpdateProgressDialog();
        splashView = null;
        splashLayer = null;
        rootView = null;
        destroyWebView(webView);
        webView = null;
        super.onDestroy();
    }

    private FrameLayout.LayoutParams matchParentLayoutParams() {
        return new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT,
            FrameLayout.LayoutParams.MATCH_PARENT
        );
    }

    private FrameLayout.LayoutParams centeredMatchParentLayoutParams() {
        FrameLayout.LayoutParams params = matchParentLayoutParams();
        params.gravity = Gravity.CENTER;

        return params;
    }

    private void configureCrmWebView(WebView view) {
        view.setBackgroundColor(MARTIN_SOLS_BACKGROUND);
        view.setOverScrollMode(View.OVER_SCROLL_NEVER);
        view.setWebViewClient(new CrmWebViewClient());
        view.setWebChromeClient(new CrmWebChromeClient());
        view.addJavascriptInterface(new MartinSolsNativeAppBridge(), "MartinSolsNativeApp");
        view.setVerticalScrollBarEnabled(false);
        view.setHorizontalScrollBarEnabled(false);

        WebSettings settings = view.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setGeolocationEnabled(true);
        settings.setJavaScriptCanOpenWindowsAutomatically(true);
        settings.setMediaPlaybackRequiresUserGesture(false);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            settings.setSafeBrowsingEnabled(true);
        }

        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            cookieManager.setAcceptThirdPartyCookies(view, true);
        }
    }

    private void configureSplashGifView(IntroGifView view) {
        view.setOverScrollMode(View.OVER_SCROLL_NEVER);
        view.setOnTouchListener(new View.OnTouchListener() {
            @Override
            public boolean onTouch(View touchedView, MotionEvent event) {
                return true;
            }
        });
    }

    private void configureSystemBars() {
        Window window = getWindow();

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.setDecorFitsSystemWindows(true);
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            window.setStatusBarColor(MARTIN_SOLS_RED);
            window.setNavigationBarColor(MARTIN_SOLS_NAVIGATION);
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            window.getDecorView().setSystemUiVisibility(0);
        }
    }

    private void hideSplashView() {
        FrameLayout layer = splashLayer;

        if (layer == null) {
            return;
        }

        splashLayer = null;
        splashView = null;
        layer.removeAllViews();
        layer.setVisibility(View.GONE);

        if (rootView != null) {
            rootView.removeView(layer);
        }

        showCrmWebView();
    }

    private void showCrmWebView() {
        if (webView == null) {
            return;
        }

        webView.setVisibility(View.VISIBLE);
        webView.bringToFront();
        webView.resumeTimers();
        webView.invalidate();

        String currentUrl = webView.getUrl();

        if (currentUrl == null || currentUrl.length() == 0 || "about:blank".equalsIgnoreCase(currentUrl)) {
            webView.loadUrl(HUB_URL);
        }

        scheduleUpdateCheck();
        requestInitialLocationPermission();
    }

    private void scheduleUpdateCheck() {
        if (updateCheckStarted) {
            return;
        }

        updateCheckStarted = true;
        handler.postDelayed(new Runnable() {
            @Override
            public void run() {
                checkForAppUpdate(false);
            }
        }, UPDATE_CHECK_DELAY_MS);
    }

    private void checkForAppUpdate(boolean notifyWhenCurrent) {
        new Thread(new Runnable() {
            @Override
            public void run() {
                AppUpdate update = fetchAppUpdate();

                if (update == null || update.versionCode <= BuildConfig.VERSION_CODE) {
                    if (notifyWhenCurrent) {
                        handler.post(new Runnable() {
                            @Override
                            public void run() {
                                showNoUpdateDialog();
                            }
                        });
                    }

                    return;
                }

                handler.post(new Runnable() {
                    @Override
                    public void run() {
                        showUpdateDialog(update);
                    }
                });
            }
        }).start();
    }

    private void showNoUpdateDialog() {
        if (isFinishing()) {
            return;
        }

        new AlertDialog.Builder(this)
            .setTitle("Application a jour")
            .setMessage("Aucune nouvelle version de Martin Sols n'est disponible pour le moment.")
            .setPositiveButton("OK", null)
            .show();
    }

    private boolean isTrustedCrmPage() {
        return trustedCrmPageActive;
    }

    private static boolean isTrustedCrmOrigin(String origin) {
        if (origin == null || origin.length() == 0) {
            return false;
        }

        Uri uri = Uri.parse(origin);
        String host = uri.getHost();
        String scheme = uri.getScheme();

        return "https".equalsIgnoreCase(scheme) && "crm.jp2.fr".equalsIgnoreCase(host);
    }

    private void updateTrustedCrmPage(String url) {
        trustedCrmPageActive = isTrustedCrmOrigin(url);
    }

    private void requestInitialLocationPermission() {
        if (hasLocationPermission() || Build.VERSION.SDK_INT < Build.VERSION_CODES.M) {
            return;
        }

        requestPermissions(new String[] {
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION,
        }, LOCATION_PERMISSION_REQUEST_CODE);
    }

    private void injectAppSettingsOverride(WebView targetWebView) {
        if (targetWebView == null || targetWebView.getUrl() == null || !isTrustedCrmOrigin(targetWebView.getUrl())) {
            return;
        }

        try {
            targetWebView.evaluateJavascript(readText(getAssets().open(APP_SETTINGS_OVERRIDE_ASSET)), null);
        } catch (IOException exception) {
            return;
        }
    }

    private boolean hasLocationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) {
            return true;
        }

        return checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
            || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    private boolean hasFineLocationPermission() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.M
            || checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    private void requestNativeLocation(String requestId, boolean highAccuracy) {
        if (requestId == null || requestId.length() == 0) {
            dispatchNativeLocationResult("", null, "Demande GPS invalide.");

            return;
        }

        cancelNativeLocationRequest();

        pendingNativeLocationRequestId = requestId;
        pendingNativeLocationHighAccuracy = highAccuracy;

        if (!hasLocationPermission() && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            requestPermissions(new String[] {
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION,
            }, LOCATION_PERMISSION_REQUEST_CODE);

            return;
        }

        resolveNativeLocation(requestId, highAccuracy);
    }

    private void resolveNativeLocation(String requestId, boolean highAccuracy) {
        LocationManager locationManager = (LocationManager) getSystemService(Context.LOCATION_SERVICE);

        if (locationManager == null || !hasLocationPermission()) {
            cancelNativeLocationRequest();
            dispatchNativeLocationResult(requestId, null, "Localisation Android indisponible.");

            return;
        }

        Location fallbackLocation = bestLastKnownLocation(locationManager);
        String provider = nativeLocationProvider(locationManager, highAccuracy);

        if (provider.length() == 0) {
            cancelNativeLocationRequest();

            if (fallbackLocation != null) {
                dispatchNativeLocationResult(requestId, fallbackLocation, "");
                return;
            }

            dispatchNativeLocationResult(requestId, null, "GPS Android desactive sur ce telephone.");

            return;
        }

        nativeLocationListener = new LocationListener() {
            @Override
            public void onLocationChanged(Location location) {
                completeNativeLocationRequest(requestId, location, "");
            }

            @Override
            public void onProviderDisabled(String disabledProvider) {
            }

            @Override
            public void onProviderEnabled(String enabledProvider) {
            }

            @Override
            public void onStatusChanged(String changedProvider, int status, Bundle extras) {
            }
        };

        nativeLocationTimeout = new Runnable() {
            @Override
            public void run() {
                if (!requestId.equals(pendingNativeLocationRequestId)) {
                    return;
                }

                if (fallbackLocation != null) {
                    completeNativeLocationRequest(requestId, fallbackLocation, "");
                    return;
                }

                completeNativeLocationRequest(requestId, null, "Position GPS introuvable. Verifie que la localisation Android est activee.");
            }
        };

        try {
            locationManager.requestSingleUpdate(provider, nativeLocationListener, Looper.getMainLooper());
            handler.postDelayed(nativeLocationTimeout, NATIVE_LOCATION_TIMEOUT_MS);
        } catch (IllegalArgumentException | SecurityException exception) {
            cancelNativeLocationRequest();
            dispatchNativeLocationResult(requestId, fallbackLocation, fallbackLocation == null ? "Localisation Android refusee." : "");
        }
    }

    private String nativeLocationProvider(LocationManager locationManager, boolean highAccuracy) {
        if (highAccuracy && hasFineLocationPermission() && isProviderEnabled(locationManager, LocationManager.GPS_PROVIDER)) {
            return LocationManager.GPS_PROVIDER;
        }

        if (isProviderEnabled(locationManager, LocationManager.NETWORK_PROVIDER)) {
            return LocationManager.NETWORK_PROVIDER;
        }

        if (hasFineLocationPermission() && isProviderEnabled(locationManager, LocationManager.GPS_PROVIDER)) {
            return LocationManager.GPS_PROVIDER;
        }

        return "";
    }

    private boolean isProviderEnabled(LocationManager locationManager, String provider) {
        try {
            return locationManager.isProviderEnabled(provider);
        } catch (Exception exception) {
            return false;
        }
    }

    private Location bestLastKnownLocation(LocationManager locationManager) {
        Location bestLocation = null;

        try {
            for (String provider : locationManager.getProviders(true)) {
                Location location = locationManager.getLastKnownLocation(provider);

                if (isBetterLocation(location, bestLocation)) {
                    bestLocation = location;
                }
            }
        } catch (SecurityException exception) {
            return null;
        }

        return bestLocation;
    }

    private boolean isBetterLocation(Location location, Location currentBestLocation) {
        if (location == null) {
            return false;
        }

        if (currentBestLocation == null) {
            return true;
        }

        long timeDelta = location.getTime() - currentBestLocation.getTime();
        boolean isSignificantlyNewer = timeDelta > 120000L;
        boolean isSignificantlyOlder = timeDelta < -120000L;

        if (isSignificantlyNewer) {
            return true;
        }

        if (isSignificantlyOlder) {
            return false;
        }

        float accuracyDelta = location.getAccuracy() - currentBestLocation.getAccuracy();

        return accuracyDelta < 0 || (timeDelta > 0 && accuracyDelta <= 0);
    }

    private void completeNativeLocationRequest(String requestId, Location location, String error) {
        if (!requestId.equals(pendingNativeLocationRequestId)) {
            return;
        }

        cancelNativeLocationRequest();
        dispatchNativeLocationResult(requestId, location, error);
    }

    private void cancelNativeLocationRequest() {
        if (nativeLocationTimeout != null) {
            handler.removeCallbacks(nativeLocationTimeout);
            nativeLocationTimeout = null;
        }

        if (nativeLocationListener != null) {
            LocationManager locationManager = (LocationManager) getSystemService(Context.LOCATION_SERVICE);

            if (locationManager != null) {
                try {
                    locationManager.removeUpdates(nativeLocationListener);
                } catch (SecurityException exception) {
                }
            }

            nativeLocationListener = null;
        }

        pendingNativeLocationRequestId = "";
        pendingNativeLocationHighAccuracy = false;
    }

    private void dispatchNativeLocationResult(String requestId, Location location, String error) {
        if (webView == null) {
            return;
        }

        JSONObject detail = new JSONObject();

        try {
            detail.put("requestId", requestId == null ? "" : requestId);
            detail.put("ok", location != null);

            if (location != null) {
                JSONObject payload = new JSONObject();
                payload.put("accuracy", location.hasAccuracy() ? location.getAccuracy() : 0);
                payload.put("latitude", location.getLatitude());
                payload.put("longitude", location.getLongitude());
                payload.put("timestamp", location.getTime() > 0 ? location.getTime() : System.currentTimeMillis());
                detail.put("location", payload);
            } else {
                detail.put("error", error == null || error.length() == 0 ? "Localisation indisponible." : error);
            }
        } catch (JSONException exception) {
            return;
        }

        String script = "window.dispatchEvent(new CustomEvent('martin-sols:native-location-result',{detail:" + detail + "}));";

        handler.post(new Runnable() {
            @Override
            public void run() {
                if (webView != null) {
                    webView.evaluateJavascript(script, null);
                }
            }
        });
    }

    private void handleGeolocationPermissionPrompt(String origin, GeolocationPermissions.Callback callback) {
        if (!isTrustedCrmOrigin(origin)) {
            callback.invoke(origin, false, false);

            return;
        }

        if (hasLocationPermission()) {
            callback.invoke(origin, true, false);

            return;
        }

        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) {
            callback.invoke(origin, true, false);

            return;
        }

        pendingGeolocationCallback = callback;
        pendingGeolocationOrigin = origin;
        requestPermissions(new String[] {
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION,
        }, LOCATION_PERMISSION_REQUEST_CODE);
    }

    private SharedPreferences mobileAuthPreferences() {
        return getSharedPreferences(MOBILE_AUTH_PREFS, Context.MODE_PRIVATE);
    }

    private boolean hasSavedMobileSession() {
        SharedPreferences preferences = mobileAuthPreferences();

        return preferences.contains(MOBILE_AUTH_SESSION_CIPHER) && preferences.contains(MOBILE_AUTH_SESSION_IV);
    }

    private String mobileAuthStatusJson() {
        JSONObject status = new JSONObject();

        try {
            boolean deviceSecure = isDeviceSecure();
            boolean appCodeConfigured = isAppCodeConfigured();
            boolean protectedSessionAvailable = deviceSecure || appCodeConfigured;

            status.put("ok", true);
            status.put("available", protectedSessionAvailable);
            status.put("configured", protectedSessionAvailable);
            status.put("deviceSecure", deviceSecure);
            status.put("appCodeConfigured", appCodeConfigured);
            status.put("hasSession", hasSavedMobileSession());
            status.put("label", mobileAuthProtectionLabel(deviceSecure, appCodeConfigured));

            if (!protectedSessionAvailable) {
                status.put("message", "Configure un code app ou le verrouillage Android.");
            }
        } catch (JSONException exception) {
            return "{\"ok\":false}";
        }

        return status.toString();
    }

    private String mobileAuthProtectionLabel(boolean deviceSecure, boolean appCodeConfigured) {
        if (deviceSecure && appCodeConfigured) {
            return "Empreinte, visage, code appareil ou code app";
        }

        if (deviceSecure) {
            return Build.VERSION.SDK_INT >= Build.VERSION_CODES.P
                ? "Empreinte, visage ou code appareil"
                : "Code de l'appareil";
        }

        if (appCodeConfigured) {
            return "Code app";
        }

        return "Non configuree";
    }

    private boolean isDeviceSecure() {
        KeyguardManager keyguardManager = (KeyguardManager) getSystemService(Context.KEYGUARD_SERVICE);

        if (keyguardManager == null) {
            return false;
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            return keyguardManager.isDeviceSecure();
        }

        return keyguardManager.isKeyguardSecure();
    }

    private boolean isAppCodeConfigured() {
        SharedPreferences preferences = mobileAuthPreferences();

        return preferences.contains(MOBILE_AUTH_APP_CODE_HASH) && preferences.contains(MOBILE_AUTH_APP_CODE_SALT);
    }

    private boolean canProtectMobileSession() {
        return isDeviceSecure() || isAppCodeConfigured();
    }

    private String saveMobileSessionPayload(String payload) {
        if (!isTrustedCrmPage()) {
            return "{\"ok\":false,\"error\":\"Page HUB non autorisee.\"}";
        }

        if (!canProtectMobileSession()) {
            return "{\"ok\":false,\"error\":\"Configure un code app ou le verrouillage Android.\"}";
        }

        try {
            JSONObject session = new JSONObject(payload);

            if (session.optString("token", "").length() == 0 || session.optString("refreshToken", "").length() == 0) {
                return "{\"ok\":false,\"error\":\"Session mobile incomplete.\"}";
            }

            byte[] plainText = session.toString().getBytes(StandardCharsets.UTF_8);
            Cipher cipher = Cipher.getInstance(MOBILE_AUTH_CIPHER_TRANSFORMATION);
            cipher.init(Cipher.ENCRYPT_MODE, mobileSessionSecretKey());

            byte[] encrypted = cipher.doFinal(plainText);
            byte[] iv = cipher.getIV();

            mobileAuthPreferences()
                .edit()
                .putString(MOBILE_AUTH_SESSION_CIPHER, Base64.encodeToString(encrypted, Base64.NO_WRAP))
                .putString(MOBILE_AUTH_SESSION_IV, Base64.encodeToString(iv, Base64.NO_WRAP))
                .apply();

            return "{\"ok\":true}";
        } catch (Exception exception) {
            clearSavedMobileSession();

            return "{\"ok\":false,\"error\":\"Connexion rapide indisponible sur cet appareil.\"}";
        }
    }

    private SecretKey mobileSessionSecretKey() throws GeneralSecurityException, IOException {
        KeyStore keyStore = KeyStore.getInstance("AndroidKeyStore");
        keyStore.load(null);

        if (keyStore.containsAlias(MOBILE_AUTH_KEY_ALIAS)) {
            return (SecretKey) keyStore.getKey(MOBILE_AUTH_KEY_ALIAS, null);
        }

        KeyGenerator keyGenerator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore");
        KeyGenParameterSpec keySpec = new KeyGenParameterSpec.Builder(
            MOBILE_AUTH_KEY_ALIAS,
            KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setRandomizedEncryptionRequired(true)
            .build();

        keyGenerator.init(keySpec);

        return keyGenerator.generateKey();
    }

    private String decryptedMobileSessionPayload() throws GeneralSecurityException, IOException {
        SharedPreferences preferences = mobileAuthPreferences();
        String encryptedPayload = preferences.getString(MOBILE_AUTH_SESSION_CIPHER, "");
        String encodedIv = preferences.getString(MOBILE_AUTH_SESSION_IV, "");

        if (encryptedPayload == null || encryptedPayload.length() == 0 || encodedIv == null || encodedIv.length() == 0) {
            throw new GeneralSecurityException("Missing mobile session");
        }

        byte[] encrypted = Base64.decode(encryptedPayload, Base64.NO_WRAP);
        byte[] iv = Base64.decode(encodedIv, Base64.NO_WRAP);
        Cipher cipher = Cipher.getInstance(MOBILE_AUTH_CIPHER_TRANSFORMATION);
        cipher.init(Cipher.DECRYPT_MODE, mobileSessionSecretKey(), new GCMParameterSpec(128, iv));

        return new String(cipher.doFinal(encrypted), StandardCharsets.UTF_8);
    }

    private void clearSavedMobileSession() {
        mobileAuthPreferences()
            .edit()
            .remove(MOBILE_AUTH_SESSION_CIPHER)
            .remove(MOBILE_AUTH_SESSION_IV)
            .apply();
    }

    private boolean openDeviceSecuritySettings() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R && openSettingsActivity(Settings.ACTION_BIOMETRIC_ENROLL)) {
            return true;
        }

        if (openSettingsActivity(Settings.ACTION_SECURITY_SETTINGS)) {
            return true;
        }

        return openSettingsActivity(Settings.ACTION_SETTINGS);
    }

    private boolean openSettingsActivity(String action) {
        try {
            startActivity(new Intent(action));

            return true;
        } catch (Exception exception) {
            return false;
        }
    }

    private void showSetAppCodeDialog() {
        if (isFinishing()) {
            return;
        }

        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setPadding(dp(24), dp(12), dp(24), 0);

        TextView help = new TextView(this);
        help.setText("Choisis un code de 4 a 8 chiffres. Il servira a proteger la connexion rapide si le telephone n'a pas de verrouillage Android.");
        help.setTextColor(Color.rgb(100, 116, 139));
        help.setTextSize(14);
        layout.addView(help, new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        ));

        EditText codeInput = appCodeInput("Code app");
        LinearLayout.LayoutParams codeParams = new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        );
        codeParams.setMargins(0, dp(14), 0, 0);
        layout.addView(codeInput, codeParams);

        EditText confirmInput = appCodeInput("Confirmer le code");
        LinearLayout.LayoutParams confirmParams = new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        );
        confirmParams.setMargins(0, dp(10), 0, 0);
        layout.addView(confirmInput, confirmParams);

        final AlertDialog dialog = new AlertDialog.Builder(this)
            .setTitle("Code de l'app")
            .setView(layout)
            .setPositiveButton("Enregistrer", null)
            .setNegativeButton("Annuler", null)
            .create();

        dialog.setOnShowListener(new DialogInterface.OnShowListener() {
            @Override
            public void onShow(DialogInterface shownDialog) {
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View clickedView) {
                        String code = normalizeAppCode(codeInput.getText().toString());
                        String confirmation = normalizeAppCode(confirmInput.getText().toString());

                        if (!isValidAppCode(code)) {
                            codeInput.setError("4 a 8 chiffres");

                            return;
                        }

                        if (!code.equals(confirmation)) {
                            confirmInput.setError("Les codes ne correspondent pas");

                            return;
                        }

                        if (storeAppCode(code)) {
                            dialog.dismiss();
                            dispatchNativeAuthStatusChanged();
                            return;
                        }

                        codeInput.setError("Code impossible a enregistrer");
                    }
                });
            }
        });

        dialog.show();
    }

    private void showAppCodePrompt(String requestId) {
        if (isFinishing()) {
            dispatchNativeAuthResult(requestId, false, null, "Authentification impossible.");

            return;
        }

        EditText codeInput = appCodeInput("Code app");
        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setPadding(dp(24), dp(10), dp(24), 0);
        layout.addView(codeInput, new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        ));

        final AlertDialog dialog = new AlertDialog.Builder(this)
            .setTitle("Connexion Martin Sols")
            .setMessage("Entre le code de l'app pour ouvrir le HUB.")
            .setView(layout)
            .setPositiveButton("Valider", null)
            .setNegativeButton("Annuler", new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialogInterface, int which) {
                    dispatchNativeAuthResult(requestId, false, null, "Authentification annulee.");
                }
            })
            .create();

        dialog.setOnShowListener(new DialogInterface.OnShowListener() {
            @Override
            public void onShow(DialogInterface shownDialog) {
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View clickedView) {
                        String code = normalizeAppCode(codeInput.getText().toString());

                        if (verifyAppCode(code)) {
                            dialog.dismiss();
                            deliverSavedMobileSession(requestId);
                            return;
                        }

                        codeInput.setError("Code incorrect");
                    }
                });
            }
        });

        dialog.show();
    }

    private EditText appCodeInput(String hint) {
        EditText input = new EditText(this);
        input.setHint(hint);
        input.setSingleLine(true);
        input.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);

        return input;
    }

    private boolean storeAppCode(String code) {
        try {
            byte[] salt = new byte[APP_CODE_SALT_BYTES];
            new SecureRandom().nextBytes(salt);
            byte[] hash = deriveAppCodeHash(code, salt);

            mobileAuthPreferences()
                .edit()
                .putString(MOBILE_AUTH_APP_CODE_SALT, Base64.encodeToString(salt, Base64.NO_WRAP))
                .putString(MOBILE_AUTH_APP_CODE_HASH, Base64.encodeToString(hash, Base64.NO_WRAP))
                .apply();

            return true;
        } catch (GeneralSecurityException exception) {
            return false;
        }
    }

    private boolean verifyAppCode(String code) {
        if (!isValidAppCode(code)) {
            return false;
        }

        SharedPreferences preferences = mobileAuthPreferences();
        String encodedSalt = preferences.getString(MOBILE_AUTH_APP_CODE_SALT, "");
        String encodedHash = preferences.getString(MOBILE_AUTH_APP_CODE_HASH, "");

        if (encodedSalt == null || encodedSalt.length() == 0 || encodedHash == null || encodedHash.length() == 0) {
            return false;
        }

        try {
            byte[] salt = Base64.decode(encodedSalt, Base64.NO_WRAP);
            byte[] expectedHash = Base64.decode(encodedHash, Base64.NO_WRAP);
            byte[] actualHash = deriveAppCodeHash(code, salt);

            return MessageDigest.isEqual(expectedHash, actualHash);
        } catch (IllegalArgumentException | GeneralSecurityException exception) {
            return false;
        }
    }

    private byte[] deriveAppCodeHash(String code, byte[] salt) throws GeneralSecurityException {
        PBEKeySpec keySpec = new PBEKeySpec(code.toCharArray(), salt, APP_CODE_HASH_ITERATIONS, APP_CODE_HASH_BITS);

        try {
            return appCodeSecretKeyFactory().generateSecret(keySpec).getEncoded();
        } finally {
            keySpec.clearPassword();
        }
    }

    private SecretKeyFactory appCodeSecretKeyFactory() throws GeneralSecurityException {
        try {
            return SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256");
        } catch (NoSuchAlgorithmException exception) {
            return SecretKeyFactory.getInstance("PBKDF2WithHmacSHA1");
        }
    }

    private void clearAppCode() {
        mobileAuthPreferences()
            .edit()
            .remove(MOBILE_AUTH_APP_CODE_HASH)
            .remove(MOBILE_AUTH_APP_CODE_SALT)
            .apply();

        if (!isDeviceSecure()) {
            clearSavedMobileSession();
        }

        dispatchNativeAuthStatusChanged();
    }

    private static String normalizeAppCode(String code) {
        return code == null ? "" : code.trim();
    }

    private static boolean isValidAppCode(String code) {
        return code != null && code.matches("[0-9]{4,8}");
    }

    private void authenticateSavedMobileSession(String requestId) {
        if (!isTrustedCrmPage()) {
            dispatchNativeAuthResult(requestId, false, null, "Page HUB non autorisee.");

            return;
        }

        if (!hasSavedMobileSession()) {
            dispatchNativeAuthResult(requestId, false, null, "Aucune connexion rapide n'est enregistree.");

            return;
        }

        if (!canProtectMobileSession()) {
            dispatchNativeAuthResult(requestId, false, null, "Configure un code app ou le verrouillage Android.");

            return;
        }

        handler.post(new Runnable() {
            @Override
            public void run() {
                if (isDeviceSecure() && Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                    showBiometricPrompt(requestId);
                    return;
                }

                if (isAppCodeConfigured()) {
                    showAppCodePrompt(requestId);
                    return;
                }

                showDeviceCredentialPrompt(requestId);
            }
        });
    }

    private void showBiometricPrompt(String requestId) {
        cancelBiometricPrompt();

        BiometricPrompt.Builder builder = new BiometricPrompt.Builder(this)
            .setTitle("Connexion Martin Sols")
            .setSubtitle("Confirme ton identite")
            .setDescription("Utilise l'empreinte, le visage ou le code configure sur ce telephone.");

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            builder.setDeviceCredentialAllowed(true);
        } else {
            builder.setNegativeButton("Annuler", mainExecutor, new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    dispatchNativeAuthResult(requestId, false, null, "Authentification annulee.");
                }
            });
        }

        biometricCancellationSignal = new CancellationSignal();

        builder.build().authenticate(biometricCancellationSignal, mainExecutor, new BiometricPrompt.AuthenticationCallback() {
            @Override
            public void onAuthenticationSucceeded(BiometricPrompt.AuthenticationResult result) {
                biometricCancellationSignal = null;
                deliverSavedMobileSession(requestId);
            }

            @Override
            public void onAuthenticationError(int errorCode, CharSequence errorString) {
                biometricCancellationSignal = null;

                if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q && isDeviceSecure()) {
                    showDeviceCredentialPrompt(requestId);
                    return;
                }

                dispatchNativeAuthResult(requestId, false, null, errorString == null
                    ? "Authentification impossible."
                    : errorString.toString());
            }

            @Override
            public void onAuthenticationFailed() {
            }
        });
    }

    private void showDeviceCredentialPrompt(String requestId) {
        KeyguardManager keyguardManager = (KeyguardManager) getSystemService(Context.KEYGUARD_SERVICE);

        if (keyguardManager == null) {
            dispatchNativeAuthResult(requestId, false, null, "Authentification appareil indisponible.");

            return;
        }

        Intent intent = keyguardManager.createConfirmDeviceCredentialIntent(
            "Connexion Martin Sols",
            "Confirme ton identite pour ouvrir le HUB."
        );

        if (intent == null) {
            dispatchNativeAuthResult(requestId, false, null, "Configure un code sur ce telephone.");

            return;
        }

        pendingDeviceCredentialRequestId = requestId;
        startActivityForResult(intent, DEVICE_CREDENTIAL_REQUEST_CODE);
    }

    private void cancelBiometricPrompt() {
        if (biometricCancellationSignal == null || biometricCancellationSignal.isCanceled()) {
            return;
        }

        biometricCancellationSignal.cancel();
        biometricCancellationSignal = null;
    }

    private void deliverSavedMobileSession(String requestId) {
        try {
            String payload = decryptedMobileSessionPayload();
            new JSONObject(payload);
            dispatchNativeAuthResult(requestId, true, payload, "");
        } catch (Exception exception) {
            clearSavedMobileSession();
            dispatchNativeAuthResult(requestId, false, null, "Connexion rapide expiree. Reconnecte-toi une fois.");
        }
    }

    private void dispatchNativeAuthResult(String requestId, boolean ok, String sessionPayload, String error) {
        if (webView == null) {
            return;
        }

        JSONObject detail = new JSONObject();

        try {
            detail.put("requestId", requestId == null ? "" : requestId);
            detail.put("ok", ok);

            if (ok && sessionPayload != null) {
                detail.put("session", new JSONObject(sessionPayload));
            }

            if (!ok) {
                detail.put("error", error == null || error.length() == 0 ? "Authentification impossible." : error);
            }
        } catch (JSONException exception) {
            return;
        }

        String script = "window.dispatchEvent(new CustomEvent('martin-sols:native-auth-result',{detail:" + detail + "}));";

        handler.post(new Runnable() {
            @Override
            public void run() {
                if (webView != null) {
                    webView.evaluateJavascript(script, null);
                }
            }
        });
    }

    private void dispatchNativeAuthStatusChanged() {
        if (webView == null) {
            return;
        }

        String script = "window.dispatchEvent(new CustomEvent('martin-sols:native-auth-status-changed',{detail:" + mobileAuthStatusJson() + "}));";

        handler.post(new Runnable() {
            @Override
            public void run() {
                if (webView != null) {
                    webView.evaluateJavascript(script, null);
                }
            }
        });
    }

    private AppUpdate fetchAppUpdate() {
        AppUpdate apiUpdate = fetchAppUpdateFromUrl(UPDATE_MANIFEST_API_URL + "&t=" + System.currentTimeMillis(), true);

        if (apiUpdate != null) {
            return apiUpdate;
        }

        return fetchAppUpdateFromUrl(UPDATE_MANIFEST_URL + "?t=" + System.currentTimeMillis(), false);
    }

    private AppUpdate fetchAppUpdateFromUrl(String manifestUrl, boolean githubContentsResponse) {
        HttpURLConnection connection = null;

        try {
            URL url = new URL(manifestUrl);
            connection = (HttpURLConnection) url.openConnection();
            connection.setUseCaches(false);
            connection.setConnectTimeout(5000);
            connection.setReadTimeout(5000);
            connection.setRequestMethod("GET");
            connection.setRequestProperty("Accept", githubContentsResponse ? "application/vnd.github+json" : "application/json");
            connection.setRequestProperty("Cache-Control", "no-cache");
            connection.setRequestProperty("Pragma", "no-cache");
            connection.setRequestProperty("User-Agent", "Martin-Sols-Android/" + BuildConfig.VERSION_NAME);

            int responseCode = connection.getResponseCode();

            if (responseCode < 200 || responseCode >= 300) {
                return null;
            }

            JSONObject manifest = new JSONObject(readText(connection.getInputStream()));

            if (githubContentsResponse) {
                manifest = decodeGitHubContentsManifest(manifest);
            }

            return parseAppUpdateManifest(manifest);
        } catch (IOException | JSONException | IllegalArgumentException exception) {
            return null;
        } finally {
            if (connection != null) {
                connection.disconnect();
            }
        }
    }

    private JSONObject decodeGitHubContentsManifest(JSONObject response) throws JSONException {
        String encodedContent = response.optString("content", "");

        if (encodedContent.length() == 0) {
            throw new JSONException("Missing GitHub contents payload");
        }

        String decodedContent = new String(Base64.decode(encodedContent, Base64.DEFAULT), StandardCharsets.UTF_8);

        return new JSONObject(decodedContent);
    }

    private AppUpdate parseAppUpdateManifest(JSONObject manifest) {
        JSONObject android = manifest.optJSONObject("android");

        if (android == null) {
            return null;
        }

        int versionCode = android.optInt("versionCode", 0);
        String versionName = android.optString("versionName", "");
        String apkUrl = android.optString("apkUrl", "");
        String sha256 = android.optString("sha256", "");
        String releaseNotes = android.optString("releaseNotes", "");

        if (versionCode <= 0 || apkUrl.length() == 0) {
            return null;
        }

        return new AppUpdate(versionCode, versionName, apkUrl, sha256, releaseNotes);
    }

    private void showUpdateDialog(AppUpdate update) {
        if (isFinishing()) {
            return;
        }

        String versionLabel = update.versionName.length() > 0 ? update.versionName : String.valueOf(update.versionCode);
        String message = "Une nouvelle version de Martin Sols est disponible : " + versionLabel + ".";

        if (update.releaseNotes.length() > 0) {
            message = message + "\n\n" + update.releaseNotes;
        }

        new AlertDialog.Builder(this)
            .setTitle("Mise a jour disponible")
            .setMessage(message)
            .setPositiveButton("Installer", new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    startUpdateDownload(update);
                }
            })
            .setNegativeButton("Plus tard", new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    pendingInstallPermissionUpdate = null;
                }
            })
            .show();
    }

    private void startUpdateDownload(AppUpdate update) {
        if (!canRequestPackageInstalls()) {
            pendingInstallPermissionUpdate = update;
            showInstallPermissionDialog(update);

            return;
        }

        DownloadManager downloadManager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);

        if (downloadManager == null) {
            showUpdateFailure("Android ne peut pas lancer le telechargement.");

            return;
        }

        cancelActiveUpdateDownload(false);
        pendingUpdateSha256 = update.sha256;
        updateInstallStarted = false;
        String versionLabel = update.versionName.length() > 0 ? update.versionName : String.valueOf(update.versionCode);
        String fileName = "Martin_Sols_" + versionLabel + ".apk";
        DownloadManager.Request request = new DownloadManager.Request(Uri.parse(update.apkUrl));
        request.setTitle("Mise a jour Martin Sols");
        request.setDescription("Telechargement de la version " + versionLabel);
        request.setMimeType(APK_MIME_TYPE);
        request.setAllowedOverMetered(true);
        request.setAllowedOverRoaming(true);
        request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
        request.setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, fileName);

        updateDownloadId = downloadManager.enqueue(request);
        registerUpdateDownloadReceiver();
        showUpdateProgressDialog(update);
        handler.removeCallbacks(updateDownloadProgress);
        handler.post(updateDownloadProgress);
    }

    private boolean canRequestPackageInstalls() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.O || getPackageManager().canRequestPackageInstalls();
    }

    private void showInstallPermissionDialog(AppUpdate update) {
        if (isFinishing()) {
            return;
        }

        new AlertDialog.Builder(this)
            .setTitle("Autorisation Android requise")
            .setMessage("Pour installer la mise a jour, autorise Martin Sols a installer des apps inconnues. Reviens ensuite dans l'app : le telechargement demarrera automatiquement.")
            .setPositiveButton("Ouvrir les reglages", new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    openInstallPermissionSettings(update);
                }
            })
            .setNegativeButton("Plus tard", new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    pendingInstallPermissionUpdate = null;
                }
            })
            .show();
    }

    private void openInstallPermissionSettings(AppUpdate update) {
        pendingInstallPermissionUpdate = update;

        try {
            Intent intent = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES);
            intent.setData(Uri.parse("package:" + getPackageName()));
            startActivity(intent);
        } catch (ActivityNotFoundException exception) {
            Intent fallbackIntent = new Intent(Settings.ACTION_SECURITY_SETTINGS);
            startActivity(fallbackIntent);
        }
    }

    private void registerUpdateDownloadReceiver() {
        unregisterUpdateDownloadReceiver();
        updateDownloadReceiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context context, Intent intent) {
                long completedDownloadId = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L);

                if (completedDownloadId != updateDownloadId) {
                    return;
                }

                installDownloadedUpdate();
            }
        };

        IntentFilter filter = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(updateDownloadReceiver, filter, Context.RECEIVER_NOT_EXPORTED);
            return;
        }

        registerReceiver(updateDownloadReceiver, filter);
    }

    private void unregisterUpdateDownloadReceiver() {
        if (updateDownloadReceiver == null) {
            return;
        }

        unregisterReceiver(updateDownloadReceiver);
        updateDownloadReceiver = null;
    }

    private void showUpdateProgressDialog(AppUpdate update) {
        if (isFinishing()) {
            return;
        }

        dismissUpdateProgressDialog();

        String versionLabel = update.versionName.length() > 0 ? update.versionName : String.valueOf(update.versionCode);
        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setPadding(dp(24), dp(12), dp(24), 0);

        updateProgressMessage = new TextView(this);
        updateProgressMessage.setText("Preparation du telechargement de la version " + versionLabel + "...");
        updateProgressMessage.setTextColor(Color.rgb(31, 53, 79));
        updateProgressMessage.setTextSize(15);
        layout.addView(updateProgressMessage, new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        ));

        updateProgressBar = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        updateProgressBar.setMax(UPDATE_PROGRESS_MAX);
        updateProgressBar.setIndeterminate(true);
        LinearLayout.LayoutParams progressParams = new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        );
        progressParams.setMargins(0, dp(16), 0, dp(8));
        layout.addView(updateProgressBar, progressParams);

        updateProgressPercent = new TextView(this);
        updateProgressPercent.setText("En attente");
        updateProgressPercent.setTextColor(Color.rgb(100, 116, 139));
        updateProgressPercent.setTextSize(13);
        updateProgressPercent.setGravity(Gravity.RIGHT);
        layout.addView(updateProgressPercent, new LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        ));

        updateProgressDialog = new AlertDialog.Builder(this)
            .setTitle("Mise a jour Martin Sols")
            .setView(layout)
            .setNegativeButton("Annuler", new DialogInterface.OnClickListener() {
                @Override
                public void onClick(DialogInterface dialog, int which) {
                    cancelActiveUpdateDownload(true);
                }
            })
            .create();
        updateProgressDialog.setCanceledOnTouchOutside(false);
        updateProgressDialog.show();
    }

    private void pollUpdateDownloadProgress() {
        if (updateDownloadId < 0 || updateInstallStarted) {
            return;
        }

        DownloadManager downloadManager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);

        if (downloadManager == null) {
            showUpdateFailure("Android ne peut pas lire la progression du telechargement.");

            return;
        }

        DownloadManager.Query query = new DownloadManager.Query();
        query.setFilterById(updateDownloadId);

        try (Cursor cursor = downloadManager.query(query)) {
            if (cursor == null || !cursor.moveToFirst()) {
                showUpdateFailure("Telechargement introuvable.");

                return;
            }

            int status = intColumn(cursor, DownloadManager.COLUMN_STATUS);
            int reason = intColumn(cursor, DownloadManager.COLUMN_REASON);
            long downloadedBytes = longColumn(cursor, DownloadManager.COLUMN_BYTES_DOWNLOADED_SO_FAR);
            long totalBytes = longColumn(cursor, DownloadManager.COLUMN_TOTAL_SIZE_BYTES);
            int progress = progressPercent(downloadedBytes, totalBytes);

            if (status == DownloadManager.STATUS_SUCCESSFUL) {
                updateProgressUi("Telechargement termine. Verification du fichier...", UPDATE_PROGRESS_MAX, false);
                installDownloadedUpdate();

                return;
            }

            if (status == DownloadManager.STATUS_FAILED) {
                showUpdateFailure("Telechargement impossible. Code Android : " + reason + ".");

                return;
            }

            if (status == DownloadManager.STATUS_PAUSED) {
                updateProgressUi("Telechargement en pause. Android reprendra automatiquement. Code : " + reason + ".", progress, totalBytes <= 0);
            } else if (status == DownloadManager.STATUS_PENDING) {
                updateProgressUi("Demarrage du telechargement...", progress, true);
            } else {
                updateProgressUi("Telechargement en cours...", progress, totalBytes <= 0);
            }

            handler.postDelayed(updateDownloadProgress, UPDATE_PROGRESS_INTERVAL_MS);
        } catch (RuntimeException exception) {
            showUpdateFailure("Android ne peut pas suivre le telechargement.");
        }
    }

    private void updateProgressUi(String message, int progress, boolean indeterminate) {
        if (updateProgressMessage != null) {
            updateProgressMessage.setText(message);
        }

        if (updateProgressBar != null) {
            updateProgressBar.setIndeterminate(indeterminate);
            updateProgressBar.setProgress(Math.max(0, Math.min(UPDATE_PROGRESS_MAX, progress)));
        }

        if (updateProgressPercent != null) {
            updateProgressPercent.setText(indeterminate ? "En cours..." : progress + " %");
        }
    }

    private void showUpdateFailure(String message) {
        handler.removeCallbacks(updateDownloadProgress);
        unregisterUpdateDownloadReceiver();
        cancelActiveUpdateDownload(false);
        dismissUpdateProgressDialog();

        if (isFinishing()) {
            return;
        }

        new AlertDialog.Builder(this)
            .setTitle("Mise a jour interrompue")
            .setMessage(message)
            .setPositiveButton("OK", null)
            .show();
    }

    private void showNativeActionFailure(String message) {
        if (isFinishing()) {
            return;
        }

        new AlertDialog.Builder(this)
            .setTitle("Action impossible")
            .setMessage(message)
            .setPositiveButton("OK", null)
            .show();
    }

    private void cancelActiveUpdateDownload(boolean showMessage) {
        handler.removeCallbacks(updateDownloadProgress);

        if (updateDownloadId >= 0) {
            DownloadManager downloadManager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);

            if (downloadManager != null) {
                downloadManager.remove(updateDownloadId);
            }
        }

        updateDownloadId = -1L;
        pendingUpdateSha256 = "";
        updateInstallStarted = false;

        if (showMessage && !isFinishing()) {
            dismissUpdateProgressDialog();
            new AlertDialog.Builder(this)
                .setTitle("Mise a jour annulee")
                .setMessage("Le telechargement a ete annule.")
                .setPositiveButton("OK", null)
                .show();
        }
    }

    private void dismissUpdateProgressDialog() {
        if (updateProgressDialog != null) {
            updateProgressDialog.dismiss();
            updateProgressDialog = null;
        }

        updateProgressBar = null;
        updateProgressMessage = null;
        updateProgressPercent = null;
    }

    private void installDownloadedUpdate() {
        if (updateInstallStarted) {
            return;
        }

        DownloadManager downloadManager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);

        if (downloadManager == null || updateDownloadId < 0) {
            showUpdateFailure("Android ne trouve pas le fichier telecharge.");

            return;
        }

        Uri apkUri = downloadManager.getUriForDownloadedFile(updateDownloadId);

        if (apkUri == null || !isDownloadedUpdateValid(apkUri)) {
            showUpdateFailure("Controle de securite impossible : le fichier telecharge ne correspond pas a la version attendue.");

            return;
        }

        updateInstallStarted = true;
        handler.removeCallbacks(updateDownloadProgress);
        unregisterUpdateDownloadReceiver();
        updateProgressUi("Ouverture de l'installateur Android...", UPDATE_PROGRESS_MAX, false);

        Intent installIntent = new Intent(Intent.ACTION_VIEW);
        installIntent.setDataAndType(apkUri, APK_MIME_TYPE);
        installIntent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        installIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);

        try {
            startActivity(installIntent);
            handler.postDelayed(new Runnable() {
                @Override
                public void run() {
                    dismissUpdateProgressDialog();
                }
            }, 1200L);
        } catch (ActivityNotFoundException exception) {
            showUpdateFailure("Android n'a pas pu ouvrir l'installateur.");
        }
    }

    private boolean isDownloadedUpdateValid(Uri apkUri) {
        if (pendingUpdateSha256 == null || pendingUpdateSha256.length() == 0) {
            return true;
        }

        String downloadedSha256 = sha256(apkUri);

        return pendingUpdateSha256.equalsIgnoreCase(downloadedSha256);
    }

    private String sha256(Uri uri) {
        try (InputStream inputStream = getContentResolver().openInputStream(uri)) {
            if (inputStream == null) {
                return "";
            }

            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] buffer = new byte[8192];
            int read;

            while ((read = inputStream.read(buffer)) != -1) {
                digest.update(buffer, 0, read);
            }

            byte[] hash = digest.digest();
            StringBuilder builder = new StringBuilder(hash.length * 2);

            for (byte value : hash) {
                builder.append(String.format("%02x", value));
            }

            return builder.toString();
        } catch (IOException | NoSuchAlgorithmException exception) {
            return "";
        }
    }

    private static String readText(InputStream inputStream) throws IOException {
        try (InputStream stream = inputStream; ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096];
            int read;

            while ((read = stream.read(buffer)) != -1) {
                output.write(buffer, 0, read);
            }

            return output.toString("UTF-8");
        }
    }

    private static int progressPercent(long downloadedBytes, long totalBytes) {
        if (downloadedBytes <= 0 || totalBytes <= 0) {
            return 0;
        }

        return Math.min(99, Math.round((downloadedBytes * 100f) / totalBytes));
    }

    private static int intColumn(Cursor cursor, String columnName) {
        int columnIndex = cursor.getColumnIndex(columnName);

        if (columnIndex < 0) {
            return 0;
        }

        return cursor.getInt(columnIndex);
    }

    private static long longColumn(Cursor cursor, String columnName) {
        int columnIndex = cursor.getColumnIndex(columnName);

        if (columnIndex < 0) {
            return 0L;
        }

        return cursor.getLong(columnIndex);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private void destroyWebView(WebView view) {
        if (view == null) {
            return;
        }

        view.stopLoading();
        view.loadUrl("about:blank");
        view.destroy();
    }

    private final class CrmWebChromeClient extends WebChromeClient {
        @Override
        public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
            handleGeolocationPermissionPrompt(origin, callback);
        }
    }

    private final class CrmWebViewClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            if (isWebUrl(request.getUrl())) {
                return false;
            }

            return true;
        }

        @Override
        public boolean shouldOverrideUrlLoading(WebView view, String url) {
            if (isWebUrl(Uri.parse(url))) {
                return false;
            }

            return true;
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            updateTrustedCrmPage(url);
            injectAppSettingsOverride(view);
        }

        private boolean isWebUrl(Uri uri) {
            String scheme = uri.getScheme();

            return "http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme);
        }
    }

    @SuppressWarnings("deprecation")
    private static final class IntroGifView extends View {
        private final Movie animation;
        private long animationStartMs;

        public IntroGifView(Activity context) {
            super(context);

            Movie decodedAnimation = null;

            try (InputStream inputStream = context.getResources().openRawResource(SPLASH_ANIMATION_RESOURCE)) {
                decodedAnimation = Movie.decodeStream(inputStream);
            } catch (IOException | RuntimeException exception) {
                decodedAnimation = null;
            }

            animation = decodedAnimation;
        }

        @Override
        protected void onMeasure(int widthMeasureSpec, int heightMeasureSpec) {
            int viewWidth = MeasureSpec.getSize(widthMeasureSpec);
            int viewHeight = MeasureSpec.getSize(heightMeasureSpec);
            float animationAspectRatio = (float) SPLASH_ANIMATION_WIDTH / (float) SPLASH_ANIMATION_HEIGHT;
            float density = getResources().getDisplayMetrics().density;
            int maxWidth = Math.round(INTRO_ANIMATION_MAX_WIDTH_DP * density);
            int desiredWidth = Math.min(Math.round(viewWidth * INTRO_ANIMATION_WIDTH_FRACTION), maxWidth);
            int desiredHeight = Math.round(desiredWidth / animationAspectRatio);
            int maxHeight = Math.round(viewHeight * INTRO_ANIMATION_HEIGHT_FRACTION);

            if (desiredHeight > maxHeight) {
                desiredHeight = maxHeight;
                desiredWidth = Math.round(desiredHeight * animationAspectRatio);
            }

            setMeasuredDimension(desiredWidth, desiredHeight);
        }

        @Override
        protected void onDraw(Canvas canvas) {
            super.onDraw(canvas);

            if (animation == null) {
                return;
            }

            long now = SystemClock.uptimeMillis();

            if (animationStartMs == 0L) {
                animationStartMs = now;
            }

            int duration = animation.duration();

            if (duration <= 0) {
                duration = (int) SPLASH_DURATION_MS;
            }

            animation.setTime((int) ((now - animationStartMs) % duration));

            float animationWidth = Math.max(animation.width(), SPLASH_ANIMATION_WIDTH);
            float animationHeight = Math.max(animation.height(), SPLASH_ANIMATION_HEIGHT);
            float scale = Math.min(getWidth() / animationWidth, getHeight() / animationHeight);
            float left = (getWidth() - animationWidth * scale) / 2f;
            float top = (getHeight() - animationHeight * scale) / 2f;

            canvas.save();
            canvas.translate(left, top);
            canvas.scale(scale, scale);
            animation.draw(canvas, 0f, 0f);
            canvas.restore();
            postInvalidateOnAnimation();
        }
    }

    private final class MartinSolsNativeAppBridge {
        @JavascriptInterface
        public String getVersionName() {
            return BuildConfig.VERSION_NAME;
        }

        @JavascriptInterface
        public String getVersionCode() {
            return String.valueOf(BuildConfig.VERSION_CODE);
        }

        @JavascriptInterface
        public String checkForUpdates() {
            return runTrustedNativeAction(new Runnable() {
                @Override
                public void run() {
                    checkForAppUpdate(true);
                }
            }, "Recherche de mise a jour lancee.");
        }

        @JavascriptInterface
        public String getMobileAuthStatus() {
            if (!isTrustedCrmPage()) {
                return "{\"ok\":false,\"available\":false,\"configured\":false,\"hasSession\":false}";
            }

            return mobileAuthStatusJson();
        }

        @JavascriptInterface
        public String saveMobileSession(String payload) {
            return saveMobileSessionPayload(payload == null ? "" : payload);
        }

        @JavascriptInterface
        public void authenticateSavedMobileSession(String requestId) {
            MainActivity.this.authenticateSavedMobileSession(requestId == null ? "" : requestId);
        }

        @JavascriptInterface
        public String clearMobileSession() {
            return runTrustedNativeAction(new Runnable() {
                @Override
                public void run() {
                    clearSavedMobileSession();
                    dispatchNativeAuthStatusChanged();
                }
            }, "Connexion rapide supprimee.");
        }

        @JavascriptInterface
        public String requestLocation(String requestId, boolean highAccuracy) {
            return runTrustedNativeAction(new Runnable() {
                @Override
                public void run() {
                    MainActivity.this.requestNativeLocation(requestId == null ? "" : requestId, highAccuracy);
                }
            }, "Recherche de localisation lancee.");
        }

        @JavascriptInterface
        public String openDeviceSecuritySettings() {
            return runTrustedNativeAction(new Runnable() {
                @Override
                public void run() {
                    if (!MainActivity.this.openDeviceSecuritySettings()) {
                        showNativeActionFailure("Reglages Android indisponibles sur cet appareil.");
                    }
                }
            }, "Ouverture des reglages de securite Android.");
        }

        @JavascriptInterface
        public String setAppCode() {
            return runTrustedNativeAction(new Runnable() {
                @Override
                public void run() {
                    showSetAppCodeDialog();
                }
            }, "Ouverture du code app Martin Sols.");
        }

        @JavascriptInterface
        public String clearAppCode() {
            return runTrustedNativeAction(new Runnable() {
                @Override
                public void run() {
                    MainActivity.this.clearAppCode();
                }
            }, "Code app supprime.");
        }

        private String runTrustedNativeAction(Runnable action, String message) {
            if (!isTrustedCrmPage()) {
                return nativeActionResult(false, "Page HUB non autorisee.");
            }

            try {
                handler.post(new Runnable() {
                    @Override
                    public void run() {
                        try {
                            action.run();
                        } catch (Exception exception) {
                        }
                    }
                });

                return nativeActionResult(true, message);
            } catch (Exception exception) {
                return nativeActionResult(false, "Action Android indisponible.");
            }
        }

        private String nativeActionResult(boolean ok, String message) {
            JSONObject result = new JSONObject();

            try {
                result.put("ok", ok);
                result.put("message", message);
            } catch (JSONException exception) {
                return ok ? "{\"ok\":true}" : "{\"ok\":false}";
            }

            return result.toString();
        }
    }

    private static final class AppUpdate {
        private final int versionCode;
        private final String versionName;
        private final String apkUrl;
        private final String sha256;
        private final String releaseNotes;

        private AppUpdate(int versionCode, String versionName, String apkUrl, String sha256, String releaseNotes) {
            this.versionCode = versionCode;
            this.versionName = versionName;
            this.apkUrl = apkUrl;
            this.sha256 = sha256;
            this.releaseNotes = releaseNotes;
        }
    }
}
