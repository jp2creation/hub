<?php

namespace Modules\CrmCore\Services;

use App\Models\CrmMenuGroup;
use App\Models\CrmMenuItem;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Modules\CrmCore\Http\Requests\MobileTokenRequest;

class MobileAuthService
{
    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    public function issueToken(MobileTokenRequest $request): array
    {
        $data = $request->validated();
        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return $this->result([
                'ok' => false,
                'error' => __('crm.mobile.too_many_attempts'),
                'retryAfter' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return $this->result(['ok' => false, 'error' => __('crm.mobile.invalid_credentials')], 422);
        }

        $crmUser = $this->crmUserFor($user);

        if (! $crmUser || $crmUser->role === 'blocked') {
            RateLimiter::hit($throttleKey, 60);

            return $this->result(['ok' => false, 'error' => __('crm.mobile.account_unavailable')], 403);
        }

        RateLimiter::clear($throttleKey);

        return $this->issueMobileSessionFor($request, $user, $crmUser, (string) $data['device_name']);
    }

    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    public function issueTokenForWebSession(Request $request): array
    {
        $data = $request->validate([
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $crmUser = $user instanceof User ? $this->crmUserFor($user) : null;

        if (! $user instanceof User || ! $crmUser || $crmUser->role === 'blocked') {
            return $this->result(['ok' => false, 'error' => __('crm.mobile.account_unavailable')], 403);
        }

        $deviceName = trim((string) ($data['device_name'] ?? ''));

        return $this->issueMobileSessionFor(
            $request,
            $user,
            $crmUser,
            $deviceName !== '' ? $deviceName : 'Martin Sols Android'
        );
    }

    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    public function refreshToken(Request $request): array
    {
        $data = $request->validate([
            'refreshToken' => ['required_without:refresh_token', 'string', 'min:40', 'max:255'],
            'refresh_token' => ['required_without:refreshToken', 'string', 'min:40', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $plainRefreshToken = (string) ($data['refreshToken'] ?? $data['refresh_token']);

        return DB::transaction(function () use ($request, $data, $plainRefreshToken): array {
            $row = DB::table('crm_mobile_refresh_tokens')
                ->where('token_hash', hash('sha256', $plainRefreshToken))
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return $this->result(['ok' => false, 'error' => __('crm.mobile.invalid_refresh_token')], 401);
            }

            if ($row->revoked_at !== null) {
                $this->revokeRefreshTokenFamily($row, 'reuse_detected');

                return $this->result(['ok' => false, 'error' => __('crm.mobile.invalid_refresh_token')], 401);
            }

            if (Carbon::parse($row->expires_at)->isPast()) {
                $this->revokeRefreshTokenFamily($row, 'expired');

                return $this->result(['ok' => false, 'error' => __('crm.mobile.invalid_refresh_token')], 401);
            }

            $user = User::query()
                ->whereKey((int) $row->user_id)
                ->lockForUpdate()
                ->first();
            $crmUser = $user instanceof User ? $this->crmUserFor($user) : null;

            if (! $user instanceof User || ! $crmUser || $crmUser->role === 'blocked') {
                $this->revokeRefreshTokenFamily($row, 'account_unavailable');

                return $this->result(['ok' => false, 'error' => __('crm.mobile.account_unavailable')], 403);
            }

            $abilities = $this->mobileAbilities($crmUser);
            $expiresAt = $this->accessTokenExpiresAt();
            $deviceName = (string) ($data['device_name'] ?? $row->device_name);
            $accessToken = $user->createToken($deviceName, $abilities, $expiresAt);
            $refresh = $this->rotateRefreshToken($request, $row, $accessToken, $abilities, $deviceName);

            if ($row->personal_access_token_id) {
                $user->tokens()->whereKey((int) $row->personal_access_token_id)->delete();
            }

            return $this->result([
                'ok' => true,
                'token' => $accessToken->plainTextToken,
                'tokenType' => 'Bearer',
                'expiresAt' => $expiresAt?->toIso8601String(),
                'refreshToken' => $refresh['token'],
                'refreshExpiresAt' => $refresh['expiresAt']->toIso8601String(),
                'scopes' => $abilities,
                'user' => $this->userPayload($crmUser),
            ]);
        }, 3);
    }

    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    public function currentUser(Request $request): array
    {
        $user = $request->user();
        $crmUser = $user instanceof User ? $this->crmUserFor($user) : null;

        if (! $crmUser || $crmUser->role === 'blocked') {
            return $this->result(['ok' => false, 'error' => __('crm.mobile.account_unavailable')], 403);
        }

        return $this->result(['ok' => true, 'user' => $this->userPayload($crmUser)]);
    }

    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    public function createWebSession(Request $request): array
    {
        $user = $request->user();
        $crmUser = $user instanceof User ? $this->crmUserFor($user) : null;

        if (! $user instanceof User || ! $crmUser || $crmUser->role === 'blocked') {
            return $this->result(['ok' => false, 'error' => __('crm.mobile.account_unavailable')], 403);
        }

        $data = $request->validate([
            'redirectPath' => ['nullable', 'string', 'max:2048'],
            'siteId' => ['nullable', 'integer'],
            'embed' => ['nullable', 'boolean'],
            'plain' => ['nullable', 'boolean'],
        ]);

        $token = Str::random(56);
        $mobileToken = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        $embed = (bool) ($data['embed'] ?? true);
        $plain = (bool) ($data['plain'] ?? false);

        Cache::put($this->webSessionCacheKey($token), [
            'user_id' => (int) $user->id,
            'mobile_token_id' => $mobileToken && method_exists($mobileToken, 'getKey')
                ? (int) $mobileToken->getKey()
                : null,
            'mobile_app' => ! $plain && ! $embed,
            'redirect_path' => $this->safeMobileRedirectPath(
                (string) ($data['redirectPath'] ?? '/'),
                isset($data['siteId']) ? (int) $data['siteId'] : null,
                $embed,
                $plain,
            ),
        ], now()->addSeconds(90));

        return $this->result([
            'ok' => true,
            'url' => url('/mobile/session/'.$token),
            'expiresIn' => 90,
        ]);
    }

    public function consumeWebSession(Request $request, string $token): string
    {
        $payload = Cache::pull($this->webSessionCacheKey($token));

        abort_unless(is_array($payload), 404);

        $user = User::query()->find((int) ($payload['user_id'] ?? 0));

        abort_unless($user instanceof User, 403);

        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();

        $mobileTokenId = (int) ($payload['mobile_token_id'] ?? 0);

        if ($mobileTokenId > 0) {
            $request->session()->put('crm_mobile_token_id', $mobileTokenId);

            if (($payload['mobile_app'] ?? false) === true) {
                $request->session()->put('crm_mobile_app', true);
            } else {
                $request->session()->forget('crm_mobile_app');
            }
        }

        return (string) ($payload['redirect_path'] ?? '/');
    }

    public function revokeCurrentToken(Request $request): void
    {
        $bearerToken = $request->bearerToken();
        $token = $request->user()?->currentAccessToken();
        $tokenId = $token && method_exists($token, 'getKey') ? (int) $token->getKey() : null;

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        if ($tokenId) {
            DB::table('crm_mobile_refresh_tokens')
                ->where('personal_access_token_id', $tokenId)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'logout',
                    'updated_at' => now(),
                ]);
        }

        if ($bearerToken && str_contains($bearerToken, '|')) {
            $plainTextToken = Str::after($bearerToken, '|');

            $request->user()?->tokens()
                ->where('token', hash('sha256', $plainTextToken))
                ->delete();
        }

        Auth::guard('sanctum')->forgetUser();
        Auth::guard('web')->forgetUser();
    }

    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    private function result(array $data, int $status = 200): array
    {
        return ['data' => $data, 'status' => $status];
    }

    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    private function issueMobileSessionFor(Request $request, User $user, CrmUser $crmUser, string $deviceName): array
    {
        $abilities = $this->mobileAbilities($crmUser);
        $expiresAt = $this->accessTokenExpiresAt();
        $accessToken = $user->createToken($deviceName, $abilities, $expiresAt);
        $refresh = $this->storeRefreshToken($request, $user, $accessToken, $abilities, $deviceName);

        return $this->result([
            'ok' => true,
            'token' => $accessToken->plainTextToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt?->toIso8601String(),
            'refreshToken' => $refresh['token'],
            'refreshExpiresAt' => $refresh['expiresAt']->toIso8601String(),
            'scopes' => $abilities,
            'user' => $this->userPayload($crmUser),
        ]);
    }

    private function crmUserFor(User $user): ?CrmUser
    {
        return CrmUser::query()
            ->with(['account:id,email,name', 'modules:id,slug,name,active,route_path,sort_order', 'permissions:id,name', 'sites:id,name,slug'])
            ->where('user_id', $user->id)
            ->where('active', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(CrmUser $crmUser): array
    {
        $modules = $crmUser->role === 'blocked'
            ? collect()
            : $crmUser->modules
                ->where('active', true)
                ->sortBy('sort_order')
                ->map(fn ($module): array => [
                    'id' => (int) $module->id,
                    'name' => $module->name,
                    'slug' => $module->slug,
                    'routePath' => $module->route_path,
                ])
                ->values();

        $sites = $crmUser->sites
            ->sortByDesc(fn ($site): bool => (bool) $site->pivot?->is_default)
            ->map(fn ($site): array => [
                'id' => (int) $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'isDefault' => (bool) $site->pivot?->is_default,
            ])
            ->values();

        $defaultSite = $sites->firstWhere('isDefault', true) ?: $sites->first();

        return [
            'id' => $crmUser->id,
            'name' => $crmUser->name,
            'email' => $crmUser->email ?: $crmUser->account?->email,
            'role' => $crmUser->role,
            'photoUrl' => $crmUser->photo_url,
            'sites' => $sites->all(),
            'defaultSiteId' => $defaultSite['id'] ?? null,
            'siteIds' => $crmUser->sites->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'modules' => $modules->all(),
            'menu' => $crmUser->role === 'blocked' ? [] : $this->menuPayload($modules->all()),
            'permissions' => $crmUser->role === 'blocked'
                ? []
                : $crmUser->permissions->pluck('name')->sort()->values()->all(),
        ];
    }

    /**
     * @param  array<int, array{id:int,name:string,slug:string,routePath:?string}>  $modules
     * @return array<int, array{key:string,title:string,items:array<int, array<string, mixed>>}>
     */
    private function menuPayload(array $modules): array
    {
        $modulesBySlug = collect($modules)->keyBy('slug');
        $itemKeys = $modulesBySlug
            ->keys()
            ->map(fn (string $slug): string => 'module:'.$slug)
            ->all();

        if ($itemKeys === []) {
            return [];
        }

        $groups = CrmMenuGroup::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->keyBy('menu_key');

        $items = CrmMenuItem::query()
            ->where('active', true)
            ->whereIn('item_key', $itemKeys)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        return $items
            ->groupBy('group_key')
            ->map(function ($groupItems, string $groupKey) use ($groups, $modulesBySlug): array {
                $group = $groups->get($groupKey);

                return [
                    'key' => $groupKey,
                    'title' => $group?->title ?: Str::headline($groupKey),
                    'items' => $groupItems
                        ->map(function (CrmMenuItem $item) use ($modulesBySlug): ?array {
                            $slug = Str::after($item->item_key, 'module:');
                            $module = $modulesBySlug->get($slug);

                            if (! $module) {
                                return null;
                            }

                            return [
                                'key' => $item->item_key,
                                'groupKey' => $item->group_key,
                                'iconKey' => $item->icon_key,
                                'label' => $item->label,
                                'slug' => $slug,
                                'routePath' => $module['routePath'] ?: '/'.$slug,
                                'sortOrder' => (int) $item->sort_order,
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();
    }

    private function safeMobileRedirectPath(string $value, ?int $siteId, bool $embed = true, bool $plain = false): string
    {
        $path = trim($value);

        if ($path === '' || ! Str::startsWith($path, '/') || Str::startsWith($path, '//') || str_contains($path, "\n") || str_contains($path, "\r")) {
            $path = '/';
        }

        if (preg_match('#^/(?:api|assets|build|filament|livewire|storage|up)(?:/|$)#', $path)) {
            $path = '/';
        }

        $parts = parse_url($path);
        $targetPath = (string) ($parts['path'] ?? '/');

        if ($targetPath === '/dashboard' || $targetPath === '/dashboard/crm') {
            $targetPath = '/';
        }

        $query = [];

        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        if (! $plain) {
            if ($embed) {
                $query['mobile_embed'] = '1';
            } else {
                $query['mobile_app'] = '1';
                unset($query['mobile_embed']);
            }
        } else {
            unset($query['mobile_app'], $query['mobile_embed']);
        }

        if ($siteId && $siteId > 0) {
            $query['mobile_site_id'] = (string) $siteId;
        }

        $queryString = http_build_query($query);

        return $targetPath.($queryString !== '' ? '?'.$queryString : '');
    }

    private function webSessionCacheKey(string $token): string
    {
        return 'crm:mobile:web-session:'.$token;
    }

    /**
     * @return array{token: string, expiresAt: Carbon}
     */
    private function storeRefreshToken(Request $request, User $user, NewAccessToken $accessToken, array $abilities, string $deviceName): array
    {
        $refreshToken = Str::random(80);
        $expiresAt = $this->refreshTokenExpiresAt();
        $familyId = (string) Str::uuid();

        DB::table('crm_mobile_refresh_tokens')->insert([
            'user_id' => (int) $user->id,
            'personal_access_token_id' => (int) $accessToken->accessToken->getKey(),
            'token_hash' => hash('sha256', $refreshToken),
            'family_id' => $familyId,
            'device_name' => mb_substr($deviceName, 0, 120),
            'abilities' => json_encode($abilities, JSON_UNESCAPED_UNICODE),
            'expires_at' => $expiresAt,
            'ip_address' => $request->ip(),
            'user_agent' => $this->shortUserAgent($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['token' => $refreshToken, 'expiresAt' => $expiresAt];
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array{token: string, expiresAt: Carbon}
     */
    private function rotateRefreshToken(Request $request, object $row, NewAccessToken $accessToken, array $abilities, string $deviceName): array
    {
        $refreshToken = Str::random(80);
        $expiresAt = $this->refreshTokenExpiresAt();
        $familyId = $this->refreshTokenFamilyId($row);
        $now = now();

        DB::table('crm_mobile_refresh_tokens')
            ->where('id', $row->id)
            ->update([
                'family_id' => $familyId,
                'revoked_at' => $now,
                'revoked_reason' => 'rotated',
                'last_used_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('crm_mobile_refresh_tokens')->insert([
            'user_id' => (int) $row->user_id,
            'personal_access_token_id' => (int) $accessToken->accessToken->getKey(),
            'token_hash' => hash('sha256', $refreshToken),
            'family_id' => $familyId,
            'device_name' => mb_substr($deviceName, 0, 120),
            'abilities' => json_encode($abilities, JSON_UNESCAPED_UNICODE),
            'expires_at' => $expiresAt,
            'ip_address' => $request->ip(),
            'user_agent' => $this->shortUserAgent($request),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['token' => $refreshToken, 'expiresAt' => $expiresAt];
    }

    private function refreshTokenFamilyId(object $row): string
    {
        $familyId = (string) ($row->family_id ?? '');

        return $familyId !== '' ? $familyId : (string) Str::uuid();
    }

    private function revokeRefreshTokenFamily(object $row, string $reason): void
    {
        $familyId = (string) ($row->family_id ?? '');
        $tokensQuery = DB::table('crm_mobile_refresh_tokens');

        if ($familyId !== '') {
            $tokensQuery->where('family_id', $familyId);
        } else {
            $tokensQuery->where('id', $row->id);
        }

        $accessTokenIds = (clone $tokensQuery)
            ->whereNotNull('personal_access_token_id')
            ->pluck('personal_access_token_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        (clone $tokensQuery)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'updated_at' => now(),
            ]);

        if ($accessTokenIds !== []) {
            DB::table('personal_access_tokens')
                ->whereIn('id', $accessTokenIds)
                ->delete();
        }
    }

    /**
     * @return array<int, string>
     */
    private function mobileAbilities(CrmUser $crmUser): array
    {
        $crmUser->loadMissing('modules:id,slug,active');

        return $crmUser->modules
            ->where('active', true)
            ->pluck('slug')
            ->flatMap(fn (string $slug): array => $this->moduleAbilities($slug))
            ->prepend('crm:mobile')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function moduleAbilities(string $slug): array
    {
        $abilities = ['crm:module:'.$slug];

        if (str_starts_with($slug, 'documents-')) {
            $abilities[] = 'crm:module:documents';
        }

        return $abilities;
    }

    private function accessTokenExpiresAt(): ?Carbon
    {
        $minutes = (int) config('sanctum.mobile_access_token_expiration_minutes', 1440);

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }

    private function refreshTokenExpiresAt(): Carbon
    {
        $days = max(1, (int) config('sanctum.mobile_refresh_token_expiration_days', 30));

        return now()->addDays($days);
    }

    private function shortUserAgent(Request $request): ?string
    {
        $userAgent = (string) $request->userAgent();

        return $userAgent !== '' ? mb_substr($userAgent, 0, 512) : null;
    }

    private function throttleKey(Request $request): string
    {
        return Str::lower((string) $request->input('email')).'|'.$request->ip();
    }
}
