<?php

namespace Tests\Feature;

use App\Http\Controllers\BlockedLegacyPhpApiController;
use App\Http\Middleware\BlockLegacyPhpApiPaths;
use App\Models\CrmModule;
use App\Models\CrmNotificationLog;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Modules\CrmCore\Services\CrmActivityLogger;
use Modules\CrmCore\Support\CrmReferenceCache;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class CrmSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_throttles_repeated_failed_attempts(): void
    {
        RateLimiter::clear('bruteforce@example.test|127.0.0.1');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postLoginWithCsrf([
                'email' => 'bruteforce@example.test',
                'password' => 'mauvais-mot-de-passe',
            ])
                ->assertRedirect('/login')
                ->assertSessionHasErrors('email');
        }

        $response = $this->postLoginWithCsrf([
            'email' => 'bruteforce@example.test',
            'password' => 'mauvais-mot-de-passe',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $errors = session('errors');
        $this->assertInstanceOf(ViewErrorBag::class, $errors);
        $this->assertStringContainsString(
            'Trop de tentatives.',
            $errors->first('email'),
        );
    }

    public function test_repeated_failed_logins_record_a_sanitized_monitoring_alert(): void
    {
        Cache::flush();
        RateLimiter::clear('watcher@example.test|127.0.0.1');

        config([
            'crm.monitoring.failed_login_threshold' => 2,
            'crm.monitoring.failed_login_window_minutes' => 15,
            'crm.monitoring.failed_login_cooldown_minutes' => 30,
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postLoginWithCsrf([
                'email' => 'watcher@example.test',
                'password' => 'mauvais-mot-de-passe',
            ])
                ->assertRedirect('/login')
                ->assertSessionHasErrors('email');
        }

        $logs = CrmNotificationLog::query()
            ->where('channel', 'monitoring')
            ->where('template_key', 'auth.login.failed')
            ->get();

        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertInstanceOf(CrmNotificationLog::class, $log);

        $payload = $log->getAttribute('payload');
        $this->assertIsArray($payload);

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['attempts']);
        $this->assertSame(2, $payload['threshold']);
        $this->assertSame('127.0.0.1', $payload['ip']);
        $this->assertSame(hash('sha256', 'watcher@example.test'), $payload['emailHash']);
        $this->assertStringNotContainsString('watcher@example.test', $payloadJson);
        $this->assertStringNotContainsString('mauvais-mot-de-passe', $payloadJson);
    }

    public function test_login_remember_me_sets_the_laravel_recaller_cookie(): void
    {
        RateLimiter::clear('remember@example.test|127.0.0.1');

        $user = User::factory()->create([
            'email' => 'remember@example.test',
        ]);
        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);

        $this->postLoginWithCsrf([
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])
            ->assertRedirect('/')
            ->assertCookie($guard->getRecallerName());
    }

    public function test_login_does_not_keep_mobile_embed_for_the_regular_pwa(): void
    {
        RateLimiter::clear('pwa@example.test|127.0.0.1');

        $user = User::factory()->create([
            'email' => 'pwa@example.test',
        ]);

        $this->postLoginWithCsrf([
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ], ['url.intended' => '/?mobile_embed=1&mobile_site_id=3&source=pwa'])
            ->assertRedirect('/?source=pwa');
    }

    public function test_crm_shell_intercepts_the_legacy_template_logout_link(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->getContent();

        $bridge = (string) file_get_contents(resource_path('frontend/crm/legacy/logout-bridge.ts'));

        $this->assertStringContainsString('id="crm-shell-config"', $html);
        $this->assertStringContainsString('legacyLogoutPath', $html);
        $this->assertTrue(str_contains($html, '/auth/login') || str_contains($html, '\\/auth\\/login'));
        $this->assertStringNotContainsString('data-crm-logout-bridge', $html);
        $this->assertStringNotContainsString('logoutToCrmLogin', $html);
        $this->assertStringContainsString('logoutToCrmLogin', $bridge);
        $this->assertStringContainsString('MartinSolsCrmLogout', $bridge);
    }

    public function test_legacy_php_api_paths_are_no_longer_registered(): void
    {
        $this->get('/api/administration.php?action=bootstrap&siteId=1')
            ->assertNotFound();

        $this->postJson('/api/administration.php?action=save_site', ['name' => 'Palissy'])
            ->assertNotFound();
    }

    public function test_legacy_php_api_attempts_are_recorded_for_production_audit(): void
    {
        $this->withHeader('User-Agent', 'Legacy Integrator/1.0')
            ->withHeader('Referer', 'https://integrateur.example.test/app')
            ->get('/api/conges.php?action=bootstrap&token=secret-token')
            ->assertNotFound();

        $log = DB::table('crm_logs')
            ->where('action', BlockLegacyPhpApiPaths::LOG_ACTION)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('GET /api/conges.php', $log->details);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('Legacy Integrator/1.0', $log->user_agent);

        $context = json_decode((string) $log->context, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/api/conges.php', $context['request']['path']);
        $this->assertSame('/api/conges.php', $context['request']['matchedPath']);
        $this->assertSame('bootstrap', $context['request']['query']['action']);
        $this->assertSame('[filtered]', $context['request']['query']['token']);
        $this->assertSame('request_path', $context['legacy']['reason']);
    }

    public function test_legacy_php_api_audit_command_is_green_without_hits(): void
    {
        $this->artisan('crm:audit-legacy-php-api', ['--days' => 7])
            ->expectsOutputToContain('Aucune tentative legacy /api/*.php')
            ->assertSuccessful();
    }

    public function test_legacy_php_api_audit_command_reports_hits_and_can_fail_ci(): void
    {
        DB::table('crm_logs')->insert([
            'user_id' => null,
            'user_name' => null,
            'action' => BlockLegacyPhpApiPaths::LOG_ACTION,
            'details' => 'GET /api/conges.php',
            'created_at' => now()->subHour(),
            'ip' => '203.0.113.42',
            'user_agent' => 'Legacy Integrator/1.0',
            'context' => '{}',
        ]);

        $this->artisan('crm:audit-legacy-php-api', [
            '--days' => 7,
            '--deactivation-date' => '2026-08-31',
        ])
            ->expectsOutputToContain('1 tentative(s) legacy /api/*.php')
            ->expectsOutputToContain('GET /api/conges.php')
            ->expectsOutputToContain('2026-08-31')
            ->assertSuccessful();

        $this->artisan('crm:audit-legacy-php-api', [
            '--days' => 7,
            '--fail-on-hits' => true,
        ])
            ->assertFailed();
    }

    public function test_legacy_php_api_blocker_checks_raw_server_uri(): void
    {
        $request = Request::create(
            '/api/administration',
            'GET',
            ['action' => 'bootstrap'],
        );
        $request->server->set('REQUEST_URI', '/api/administration.php?action=bootstrap');

        $this->expectException(NotFoundHttpException::class);

        app(BlockLegacyPhpApiPaths::class)->handle($request, fn (): Response => new Response('ok'));
    }

    public function test_legacy_php_api_blocker_checks_original_request_line(): void
    {
        $request = Request::create(
            '/api/administration',
            'GET',
            ['action' => 'bootstrap'],
        );
        $request->server->set('THE_REQUEST', 'GET /api/administration.php?action=bootstrap HTTP/2');

        $this->expectException(NotFoundHttpException::class);

        app(BlockLegacyPhpApiPaths::class)->handle($request, fn (): Response => new Response('ok'));
    }

    public function test_legacy_php_api_blocker_rejects_api_permanent_redirects(): void
    {
        $request = Request::create('/administration', 'GET');

        $this->expectException(NotFoundHttpException::class);

        app(BlockLegacyPhpApiPaths::class)->handle(
            $request,
            fn (): RedirectResponse => new RedirectResponse(
                'https://crm.jp2.fr/api/administration?action=bootstrap',
                308,
            ),
        );
    }

    public function test_legacy_php_api_blocker_allows_non_api_permanent_redirects(): void
    {
        $request = Request::create('/dashboard', 'GET');

        $response = app(BlockLegacyPhpApiPaths::class)->handle(
            $request,
            fn (): RedirectResponse => new RedirectResponse(
                'https://crm.jp2.fr/',
                308,
            ),
        );

        $this->assertSame(308, $response->getStatusCode());
    }

    public function test_laravel_router_does_not_register_legacy_php_api_routes(): void
    {
        $legacyApiRoutes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/')
                && str_ends_with($route->uri(), '.php'))
            ->values();

        $this->assertCount(1, $legacyApiRoutes, $legacyApiRoutes->pluck('uri')->implode(', '));

        $route = $legacyApiRoutes->first();

        $this->assertSame('api/{legacyPhpPath}.php', $route->uri());
        $this->assertSame('crm.api.legacy-php-blocked', $route->getName());
        $this->assertSame(BlockedLegacyPhpApiController::class, $route->getControllerClass());
        $this->assertArrayNotHasKey('crm_legacy_target', $route->defaults);
    }

    public function test_public_directory_does_not_expose_legacy_php_api_scripts(): void
    {
        $phpFiles = collect(File::allFiles(public_path()))
            ->map(fn ($file): string => str_replace(public_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
            ->filter(fn (string $path): bool => str_ends_with($path, '.php'))
            ->values()
            ->all();

        $this->assertSame(['index.php'], $phpFiles);
    }

    public function test_public_front_controller_blocks_legacy_php_api_before_bootstrap(): void
    {
        $frontController = (string) file_get_contents(public_path('index.php'));

        $this->assertStringContainsString('$legacyApiPathCandidates', $frontController);
        $this->assertStringContainsString('THE_REQUEST', $frontController);
        $this->assertStringContainsString('api/[^?\\s]+\\.php', $frontController);
        $this->assertStringContainsString('http_response_code(404)', $frontController);
        $this->assertStringContainsString('exit;', $frontController);
    }

    public function test_htaccess_blocks_legacy_php_api_paths_before_laravel(): void
    {
        foreach ([base_path('.htaccess'), public_path('.htaccess'), public_path('api/.htaccess')] as $file) {
            $this->assertStringContainsString(
                'Options -MultiViews -Indexes',
                (string) file_get_contents($file),
                $file,
            );
            $this->assertStringContainsString(
                'RedirectMatch 404 ^/api/.*\\.php$',
                (string) file_get_contents($file),
                $file,
            );
            $contents = (string) file_get_contents($file);

            $this->assertTrue(
                str_contains($contents, 'RewriteRule ^api/.*\\.php$ - [R=404,L]')
                || str_contains($contents, 'RewriteRule ^.*\\.php$ - [R=404,L]'),
                $file,
            );
            $this->assertStringContainsString(
                'RewriteCond %{THE_REQUEST} \\s/+api/[^?\\s]+\\.php(?:[?\\s]|$) [NC]',
                $contents,
                $file,
            );
        }
    }

    public function test_https_middleware_redirects_http_when_enabled(): void
    {
        config(['crm.security.force_https' => true]);

        $this->get('/login')
            ->assertStatus(301)
            ->assertRedirect('https://127.0.0.1:8000/login');
    }

    public function test_hsts_header_is_sent_on_secure_requests_when_enabled(): void
    {
        config([
            'crm.security.force_https' => false,
            'crm.security.hsts_enabled' => true,
            'crm.security.hsts_max_age' => 31536000,
            'crm.security.hsts_include_subdomains' => true,
            'crm.security.hsts_preload' => true,
        ]);

        $this->get('https://127.0.0.1:8000/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }

    public function test_security_headers_are_sent_when_csp_is_disabled(): void
    {
        config(['crm.security.csp_enabled' => false]);

        $response = $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-XSS-Protection', '1; mode=block')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_content_security_policy_can_be_enabled_in_report_only_mode(): void
    {
        config([
            'crm.security.csp_enabled' => true,
            'crm.security.csp_report_only' => true,
            'crm.security.csp' => "default-src 'self'; frame-ancestors 'none'",
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertHeader('Content-Security-Policy-Report-Only', "default-src 'self'; frame-ancestors 'none'");
    }

    public function test_cors_is_delegated_to_laravel_configuration(): void
    {
        config(['cors.allowed_origins' => ['https://crm.jp2.fr', 'capacitor://localhost']]);

        $this->withHeader('Origin', 'https://crm.jp2.fr')
            ->getJson('/api/reservations?action=health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://crm.jp2.fr');

        $blocked = $this->withHeader('Origin', 'https://evil.example')
            ->getJson('/api/reservations?action=health')
            ->assertOk();

        $this->assertFalse($blocked->headers->has('Access-Control-Allow-Origin'));

        foreach ([
            app_path('Http/Controllers/Controller.php'),
            base_path('Modules/CrmCore/app/Http/Requests/CrmApiRequest.php'),
        ] as $file) {
            $this->assertStringNotContainsString('Access-Control-Allow-Origin', (string) file_get_contents($file));
        }
    }

    public function test_https_proxy_and_host_trust_are_delegated_to_laravel(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $trustedHosts = (string) file_get_contents(app_path('Http/Middleware/TrustCrmHosts.php'));
        $trustedProxies = (string) file_get_contents(app_path('Http/Middleware/TrustCrmProxies.php'));
        $middleware = (string) file_get_contents(app_path('Http/Middleware/EnforceHttpsAndHsts.php'));

        $this->assertStringContainsString('trustHosts', $bootstrap);
        $this->assertStringContainsString('BlockLegacyPhpApiPaths::class', $bootstrap);
        $this->assertStringContainsString('replace(LaravelTrustHosts::class, TrustCrmHosts::class)', $bootstrap);
        $this->assertStringContainsString('replace(LaravelTrustProxies::class, TrustCrmProxies::class)', $bootstrap);
        $this->assertStringContainsString('TrustCrmHosts::class', $bootstrap);
        $this->assertStringContainsString('TrustCrmProxies::class', $bootstrap);
        $this->assertStringContainsString("config('crm.security.trusted_hosts'", $trustedHosts);
        $this->assertStringContainsString("config('crm.security.trusted_host_subdomains'", $trustedHosts);
        $this->assertStringContainsString("config('crm.security.trusted_proxies')", $trustedProxies);
        $this->assertStringContainsString("config('crm.security.trusted_proxy_headers')", $trustedProxies);
        $this->assertStringNotContainsString("env('CRM_TRUSTED_PROXIES'", $bootstrap);
        $this->assertStringNotContainsString("env('CRM_TRUSTED_HOSTS'", $bootstrap);
        $this->assertStringNotContainsString("env('CRM_TRUSTED_HOST_SUBDOMAINS'", $bootstrap);
        $this->assertStringContainsString('$request->secure()', $middleware);
        $this->assertStringNotContainsString('X-Forwarded-Proto', $middleware);
        $this->assertStringNotContainsString('X-Forwarded-Ssl', $middleware);
    }

    public function test_default_csp_policy_is_report_only_ready_without_unsafe_eval(): void
    {
        $crmConfig = (string) file_get_contents(config_path('crm.php'));
        $envExample = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString("'csp_report_only' => env('CRM_SECURITY_CSP_REPORT_ONLY', true)", $crmConfig);
        $this->assertStringContainsString('CRM_SECURITY_CSP_ENABLED=true', $envExample);
        $this->assertStringNotContainsString("'unsafe-eval'", $crmConfig);
        $this->assertStringNotContainsString("'unsafe-eval'", $envExample);
    }

    public function test_monitoring_tools_are_loaded_with_production_safe_defaults(): void
    {
        $providers = (string) file_get_contents(base_path('bootstrap/providers.php'));
        $appProvider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $telescopeConfig = (string) file_get_contents(config_path('telescope.php'));

        $this->assertFileExists(config_path('sentry.php'));
        $this->assertStringNotContainsString('TelescopeServiceProvider::class', $providers);
        $this->assertStringContainsString('class_exists(TelescopeApplicationServiceProvider::class)', $appProvider);
        $this->assertStringContainsString("env('TELESCOPE_ENABLED', env('APP_ENV') === 'local')", $telescopeConfig);
    }

    public function test_crm_api_throttle_limit_uses_authenticated_crm_role(): void
    {
        config([
            'crm.api.throttle_per_minute' => 120,
            'crm.api.role_throttle_per_minute.responsable' => 17,
        ]);

        $account = User::factory()->create();
        CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Manager API',
            'role' => 'responsable',
            'active' => true,
        ]);

        $request = Request::create('/api/mobile/me');
        $request->setUserResolver(fn (): User => $account);

        $limit = RateLimiter::limiter('crm-api')($request);

        $this->assertSame(17, $limit->maxAttempts);
        $this->assertSame((string) $account->id, $limit->key);
    }

    public function test_module_pages_require_matching_crm_permissions(): void
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Sans Reservation',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account)
            ->get('/reservations')
            ->assertForbidden();

        $site = CrmSite::query()->create([
            'name' => 'Palissy Securite',
            'slug' => 'palissy-securite',
            'active' => true,
        ]);
        $module = CrmModule::query()->create([
            'name' => 'Reservations',
            'slug' => 'reservations',
            'description' => 'Reservations vehicules',
            'route_path' => '/reservations',
            'active' => true,
            'sort_order' => 10,
        ]);
        $permission = CrmPermission::query()->create([
            'name' => 'reservations.view',
            'label' => 'Voir les reservations',
            'group_label' => 'Reservations',
            'sort_order' => 10,
        ]);

        $crmUser->sites()->sync([$site->id => ['is_default' => true]]);
        $crmUser->modules()->sync([$module->id]);
        $crmUser->permissions()->sync([$permission->id]);
        CrmReferenceCache::forgetSites();
        CrmReferenceCache::forgetModules();
        CrmReferenceCache::forgetPermissions();

        $this->actingAs($account)
            ->get('/reservations')
            ->assertOk();
    }

    public function test_spatie_platform_admin_role_bypasses_crm_module_middleware(): void
    {
        $account = User::factory()->create();

        Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
        $account->assignRole('admin');

        $this->actingAs($account)
            ->get('/reservations')
            ->assertOk();
    }

    public function test_filament_resources_explicitly_delegate_to_policy_authorization(): void
    {
        $resourceFiles = collect([
            ...File::allFiles(app_path('Filament/Resources')),
            ...File::allFiles(base_path('Modules')),
        ])
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), 'Resource.php')
                && str_contains(str_replace('\\', '/', $file->getPathname()), '/Filament/Resources/'))
            ->values();

        $this->assertNotEmpty($resourceFiles);

        foreach ($resourceFiles as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            $this->assertStringContainsString('AuthorizesResourceWithPolicy', $contents, $file->getPathname());
            $this->assertStringContainsString('use AuthorizesResourceWithPolicy;', $contents, $file->getPathname());
        }

        $crmUserResource = (string) file_get_contents(base_path('Modules/CrmAdministration/app/Filament/Resources/CrmUsers/CrmUserResource.php'));

        $this->assertStringContainsString("Action::make('createLaravelAccount')", $crmUserResource);
        $this->assertStringContainsString("->authorize('update')", $crmUserResource);
    }

    public function test_crm_api_controllers_delegate_business_authorization_to_services_or_policies(): void
    {
        $controllerFiles = collect(File::allFiles(base_path('Modules')))
            ->filter(fn ($file): bool => str_contains(str_replace('\\', '/', $file->getPathname()), '/app/Http/Controllers/')
                && str_ends_with($file->getFilename(), 'Controller.php'))
            ->values();

        $this->assertNotEmpty($controllerFiles);

        foreach ($controllerFiles as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            $this->assertStringNotContainsString('CrmAccessService', $contents, $file->getPathname());
            $this->assertStringNotContainsString('hasAnyRole(', $contents, $file->getPathname());
            $this->assertStringNotContainsString('->role ===', $contents, $file->getPathname());
        }
    }

    public function test_crm_activity_logs_include_sanitized_request_context(): void
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Audit Context',
            'role' => 'admin',
            'active' => true,
        ]);

        Route::post('/_tests/crm-activity-log', function (CrmActivityLogger $logger) use ($crmUser) {
            $logger->log($crmUser, 'test action sensible', 'details test', [
                'changes' => [
                    'name' => ['old' => 'Avant', 'new' => 'Apres'],
                    'api_token' => 'secret-token',
                ],
            ]);

            return response()->json(['ok' => true]);
        })->name('tests.crm-activity-log');

        $this->actingAs($account)
            ->withHeader('User-Agent', 'HUB Security Test Browser')
            ->postJson('/_tests/crm-activity-log', [
                'name' => 'Apres',
                'password' => 'super-secret',
                'notes' => str_repeat('x', 520),
            ])
            ->assertOk();

        $log = DB::table('crm_logs')->where('action', 'test action sensible')->first();

        $this->assertNotNull($log);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('HUB Security Test Browser', $log->user_agent);

        $context = json_decode((string) $log->context, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/_tests/crm-activity-log', $context['request']['path']);
        $this->assertSame('tests.crm-activity-log', $context['request']['route']);
        $this->assertSame('[filtered]', $context['payload']['password']);
        $this->assertSame('[filtered]', $context['changes']['api_token']);
        $this->assertSame('Apres', $context['changes']['name']['new']);
        $this->assertStringEndsWith('...', $context['payload']['notes']);
    }

    public function test_csrf_middleware_rejects_missing_token_when_enabled(): void
    {
        $middleware = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $request = Request::create('/logout', 'POST');
        $request->setLaravelSession($this->app['session']->driver());

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, fn (): Response => new Response('ok'));
    }

    public function test_session_api_mutation_requires_csrf_token(): void
    {
        [$account, , , $vehicle] = $this->createReservationCrmUser(['reservations.create']);
        $this->enforceCsrfDuringFeatureTest();

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-18T08:00',
                'endAt' => '2026-08-18T08:30',
                'title' => 'Sans CSRF',
            ])
            ->assertStatus(419);

        $this->assertDatabaseMissing('crm_reservations', [
            'title' => 'Sans CSRF',
        ]);
    }

    public function test_session_api_mutation_accepts_valid_csrf_token(): void
    {
        [$account, , , $vehicle] = $this->createReservationCrmUser(['reservations.create']);
        $this->enforceCsrfDuringFeatureTest();

        $this->actingAs($account)
            ->withSession(['_token' => 'crm-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'crm-csrf-token')
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-18T08:00',
                'endAt' => '2026-08-18T08:30',
                'title' => 'Avec CSRF',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.title', 'Avec CSRF');
    }

    public function test_mobile_bearer_api_mutation_does_not_require_csrf(): void
    {
        [$account, , , $vehicle] = $this->createReservationCrmUser(['reservations.create']);

        $token = $account->createToken('Mobile Security Test', [
            'crm:mobile',
            'crm:module:reservations',
        ])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-19T08:00',
                'endAt' => '2026-08-19T08:30',
                'title' => 'Mobile sans CSRF',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.title', 'Mobile sans CSRF');
    }

    public function test_mobile_api_rejects_session_without_bearer_token(): void
    {
        [$account, , , $vehicle] = $this->createReservationCrmUser(['reservations.create']);

        $this->actingAs($account)
            ->postJson('/api/mobile/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-20T08:00',
                'endAt' => '2026-08-20T08:30',
                'title' => 'Session sur mobile',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Bearer Token mobile requis.');
    }

    private function enforceCsrfDuringFeatureTest(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app): PreventRequestForgery => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $session
     */
    private function postLoginWithCsrf(array $payload, array $session = [], string $from = '/login'): TestResponse
    {
        $token = 'crm-login-csrf-token';

        return $this->from($from)
            ->withSession(['_token' => $token, ...$session])
            ->post('/login', ['_token' => $token, ...$payload]);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: User, 1: CrmUser, 2: CrmSite, 3: CrmVehicle}
     */
    private function createReservationCrmUser(array $permissions): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Security Reservation User '.$account->id,
            'role' => 'user',
            'active' => true,
        ]);

        $site = CrmSite::query()->create([
            'name' => 'Palissy Securite API',
            'slug' => 'palissy-securite-api',
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);

        $vehicle = CrmVehicle::query()->create([
            'site_id' => $site->id,
            'name' => 'Sprinter Securite',
            'description' => 'Vehicule test securite',
            'color' => '#95002e',
            'active' => true,
        ]);

        $module = CrmModule::query()->create([
            'name' => 'Reservations',
            'slug' => 'reservations',
            'description' => 'Reservations vehicules',
            'route_path' => '/reservations',
            'active' => true,
            'sort_order' => 10,
        ]);

        $permissionIds = [];

        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->create([
                'name' => $permission,
                'label' => $permission,
                'group_label' => 'Reservations',
                'sort_order' => 100 + $index,
            ])->id;
        }

        $crmUser->sites()->sync([$site->id => ['is_default' => true]]);
        $crmUser->modules()->sync([$module->id]);
        $crmUser->permissions()->sync($permissionIds);

        return [$account, $crmUser, $site, $vehicle];
    }
}
