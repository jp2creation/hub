<?php

namespace Modules\CrmCore\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CrmCore\Http\Requests\LoginRequest;

class AuthController extends Controller
{
    public function show(Request $request): Response
    {
        return response()->view('auth.login', [
            'loginInstallLinks' => $this->loginInstallLinks(),
            'loginIsMobileApp' => $request->boolean('mobile_app') || $request->boolean('mobile_embed'),
        ], 200, [
            'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
            'Expires' => 'Fri, 01 Jan 1990 00:00:00 GMT',
            'Pragma' => 'no-cache',
        ]);
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Trop de tentatives. Reessayez dans {$seconds} secondes.",
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $this->stripMobileEmbedFromIntendedUrl($request);

        return redirect()->intended(route('crm.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();
        $mobileTokenId = (int) (
            $request->session()->pull('hub_mobile_token_id', 0)
            ?: $request->session()->pull('crm_mobile_token_id', 0)
        );

        if ($mobileTokenId > 0 && $user instanceof User) {
            $user->tokens()->whereKey($mobileTokenId)->delete();
        }

        Auth::guard('web')->logout();
        Auth::guard('sanctum')->forgetUser();
        Auth::guard('web')->forgetUser();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function throttleKey(Request $request): string
    {
        return Str::lower((string) $request->input('email')).'|'.$request->ip();
    }

    private function stripMobileEmbedFromIntendedUrl(Request $request): void
    {
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended) || ! str_contains($intended, 'mobile_embed')) {
            return;
        }

        $parts = parse_url($intended);

        if (! is_array($parts)) {
            return;
        }

        $query = [];

        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        unset($query['mobile_embed'], $query['mobile_site_id']);

        $path = (string) ($parts['path'] ?? '/');

        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/';
        }

        $queryString = http_build_query($query);
        $fragment = isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== ''
            ? '#'.$parts['fragment']
            : '';

        $request->session()->put('url.intended', $path.($queryString !== '' ? '?'.$queryString : '').$fragment);
    }

    /**
     * @return array<string, string>
     */
    private function loginInstallLinks(): array
    {
        $manifest = $this->nativeAppManifest();

        return [
            'androidApkUrl' => (string) data_get($manifest, 'android.apkUrl', ''),
            'androidRepoUrl' => (string) (data_get($manifest, 'android.repositoryUrl') ?: config('hub_apps.android.release_url', '')),
            'androidStoreUrl' => (string) (data_get($manifest, 'android.storeUrl') ?: data_get($manifest, 'android.googlePlayUrl') ?: config('hub_apps.android.store_url', '')),
            'appleRepoUrl' => (string) (data_get($manifest, 'ios.repositoryUrl') ?: data_get($manifest, 'macos.repositoryUrl') ?: config('hub_apps.apple.release_url', '')),
            'iosInstallUrl' => (string) data_get($manifest, 'ios.installUrl', ''),
            'iosStoreUrl' => (string) (data_get($manifest, 'ios.appStoreUrl') ?: data_get($manifest, 'ios.testFlightUrl') ?: config('hub_apps.apple.ios_store_url') ?: config('hub_apps.apple.store_url', '')),
            'macosPkgUrl' => (string) data_get($manifest, 'macos.pkgUrl', ''),
            'macosStoreUrl' => (string) (data_get($manifest, 'macos.macAppStoreUrl') ?: data_get($manifest, 'macos.appStoreUrl') ?: config('hub_apps.apple.macos_store_url') ?: config('hub_apps.apple.store_url', '')),
            'windowsDownloadUrl' => (string) (data_get($manifest, 'windows.installerUrl') ?: data_get($manifest, 'windows.portableUrl', '')),
            'windowsRepoUrl' => (string) (data_get($manifest, 'windows.repositoryUrl') ?: config('hub_apps.windows.release_url', '')),
            'windowsStoreUrl' => (string) (data_get($manifest, 'windows.storeUrl') ?: data_get($manifest, 'windows.microsoftStoreUrl') ?: config('hub_apps.windows.store_url', '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nativeAppManifest(): array
    {
        $manifestPath = base_path('mobile/releases/martin-sols-update.json');

        if (! is_file($manifestPath)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return [];
        }

        return $manifest;
    }
}
