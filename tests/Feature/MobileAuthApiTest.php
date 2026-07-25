<?php

namespace Tests\Feature;

use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_token_can_authenticate_crm_api_requests(): void
    {
        [$account, $crmUser] = $this->createMobileCrmUser();

        $loginResponse = $this->postJson('/api/mobile/token', [
            'email' => $account->email,
            'password' => 'secret-mobile',
            'device_name' => 'Pixel Test',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonPath('user.modules.0.slug', 'reservations')
            ->assertJsonPath('scopes.0', 'crm:mobile')
            ->assertJsonPath('scopes.1', 'crm:module:reservations');

        $token = $loginResponse->json('token');

        $this->assertIsString($loginResponse->json('refreshToken'));
        $this->assertDatabaseCount('crm_mobile_refresh_tokens', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/reservations?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.name', $crmUser->name);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.name', $crmUser->name)
            ->assertJsonPath('user.sites.0.name', 'Palissy Mobile')
            ->assertJsonPath('user.menu.0.items.0.slug', 'reservations');

        $webSessionUrl = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/web-session', [
                'redirectPath' => '/reservations',
                'siteId' => $crmUser->sites()->first()->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('url');

        $webSessionPath = parse_url((string) $webSessionUrl, PHP_URL_PATH);

        $this->get($webSessionPath)
            ->assertRedirect('/reservations?mobile_embed=1&mobile_site_id='.$crmUser->sites()->first()->id);

        $this->assertAuthenticatedAs($account);

        $this->get($webSessionPath)
            ->assertNotFound();

        $this->flushSession();

        $fullWebSessionUrl = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/web-session', [
                'redirectPath' => '/',
                'siteId' => $crmUser->sites()->first()->id,
                'embed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('url');

        $fullWebSessionPath = parse_url((string) $fullWebSessionUrl, PHP_URL_PATH);

        $this->get($fullWebSessionPath)
            ->assertRedirect('/?mobile_app=1&mobile_site_id='.$crmUser->sites()->first()->id);

        $this->assertTrue(session()->has('crm_mobile_app'));

        $this->flushSession();

        $dashboardWebSessionUrl = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/web-session', [
                'redirectPath' => '/dashboard/crm?mobile_app=1',
                'siteId' => $crmUser->sites()->first()->id,
                'embed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('url');

        $dashboardWebSessionPath = parse_url((string) $dashboardWebSessionUrl, PHP_URL_PATH);

        $this->get($dashboardWebSessionPath)
            ->assertRedirect('/?mobile_app=1&mobile_site_id='.$crmUser->sites()->first()->id);

        $this->flushSession();

        $plainWebSessionUrl = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/web-session', [
                'redirectPath' => '/dashboard/crm?mobile_app=1&mobile_embed=1',
                'siteId' => $crmUser->sites()->first()->id,
                'embed' => false,
                'plain' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('url');

        $plainWebSessionPath = parse_url((string) $plainWebSessionUrl, PHP_URL_PATH);

        $this->withSession(['crm_mobile_app' => true])
            ->get($plainWebSessionPath)
            ->assertRedirect('/?mobile_site_id='.$crmUser->sites()->first()->id);

        $this->assertFalse(session()->has('crm_mobile_app'));

        $this->flushSession();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/logout')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/me')
            ->assertUnauthorized();
    }

    public function test_blocked_crm_user_cannot_receive_mobile_token(): void
    {
        [$account] = $this->createMobileCrmUser(role: 'blocked');

        $this->postJson('/api/mobile/token', [
            'email' => $account->email,
            'password' => 'secret-mobile',
            'device_name' => 'Pixel Test',
        ])
            ->assertForbidden()
            ->assertJsonPath('ok', false);
    }

    public function test_mobile_refresh_token_rotates_access_and_refresh_tokens(): void
    {
        [$account] = $this->createMobileCrmUser();

        $loginResponse = $this->postJson('/api/mobile/token', [
            'email' => $account->email,
            'password' => 'secret-mobile',
            'device_name' => 'Pixel Test',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $oldAccessToken = (string) $loginResponse->json('token');
        $oldRefreshToken = (string) $loginResponse->json('refreshToken');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('crm_mobile_refresh_tokens', 1);

        $initialRefreshRow = DB::table('crm_mobile_refresh_tokens')
            ->where('token_hash', hash('sha256', $oldRefreshToken))
            ->first();

        $this->assertNotNull($initialRefreshRow);
        $this->assertNotNull($initialRefreshRow->family_id);

        $refreshResponse = $this->postJson('/api/mobile/refresh', [
            'refreshToken' => $oldRefreshToken,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('scopes.1', 'crm:module:reservations');

        $newAccessToken = (string) $refreshResponse->json('token');
        $newRefreshToken = (string) $refreshResponse->json('refreshToken');

        $this->assertNotSame($oldAccessToken, $newAccessToken);
        $this->assertNotSame($oldRefreshToken, $newRefreshToken);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('crm_mobile_refresh_tokens', 2);

        $rotatedRefreshRow = DB::table('crm_mobile_refresh_tokens')
            ->where('token_hash', hash('sha256', $oldRefreshToken))
            ->first();
        $currentRefreshRow = DB::table('crm_mobile_refresh_tokens')
            ->where('token_hash', hash('sha256', $newRefreshToken))
            ->first();

        $this->assertNotNull($rotatedRefreshRow);
        $this->assertNotNull($currentRefreshRow);
        $this->assertSame($rotatedRefreshRow->family_id, $currentRefreshRow->family_id);
        $this->assertNotNull($rotatedRefreshRow->revoked_at);
        $this->assertSame('rotated', $rotatedRefreshRow->revoked_reason);
        $this->assertNull($currentRefreshRow->revoked_at);

        $this->withHeader('Authorization', 'Bearer '.$oldAccessToken)
            ->getJson('/api/mobile/me')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$newAccessToken)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->postJson('/api/mobile/refresh', [
            'refreshToken' => $oldRefreshToken,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(
            0,
            DB::table('crm_mobile_refresh_tokens')
                ->where('family_id', $currentRefreshRow->family_id)
                ->whereNull('revoked_at')
                ->count()
        );
        $this->assertDatabaseHas('crm_mobile_refresh_tokens', [
            'token_hash' => hash('sha256', $newRefreshToken),
            'revoked_reason' => 'reuse_detected',
        ]);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$newAccessToken)
            ->getJson('/api/mobile/me')
            ->assertUnauthorized();
    }

    public function test_mobile_refresh_token_rotation_uses_database_lock(): void
    {
        $serviceSource = (string) file_get_contents(base_path('Modules/CrmCore/app/Services/MobileAuthService.php'));

        $this->assertStringContainsString('DB::transaction(function ()', $serviceSource);
        $this->assertStringContainsString('->lockForUpdate()', $serviceSource);
        $this->assertStringContainsString('reuse_detected', $serviceSource);
    }

    public function test_authenticated_web_session_can_issue_native_mobile_session(): void
    {
        [$account, $crmUser] = $this->createMobileCrmUser();

        $response = $this->actingAs($account)
            ->postJson('/api/mobile/native-session', [
                'device_name' => 'Martin Sols Android 1.54',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonPath('user.name', $crmUser->name)
            ->assertJsonPath('scopes.0', 'crm:mobile')
            ->assertJsonPath('scopes.1', 'crm:module:reservations');

        $token = (string) $response->json('token');

        $this->assertNotSame('', $token);
        $this->assertIsString($response->json('refreshToken'));
        $this->assertDatabaseCount('crm_mobile_refresh_tokens', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.name', $crmUser->name);
    }

    public function test_blocked_web_session_cannot_issue_native_mobile_session(): void
    {
        [$account] = $this->createMobileCrmUser(role: 'blocked');

        $this->actingAs($account)
            ->postJson('/api/mobile/native-session', [
                'device_name' => 'Martin Sols Android',
            ])
            ->assertForbidden()
            ->assertJsonPath('ok', false);
    }

    public function test_mobile_module_scope_is_required_for_module_api_requests(): void
    {
        [$account, $crmUser] = $this->createMobileCrmUser();

        $crmUser->modules()->detach();

        $token = $this->postJson('/api/mobile/token', [
            'email' => $account->email,
            'password' => 'secret-mobile',
            'device_name' => 'Pixel Test',
        ])
            ->assertOk()
            ->assertJsonMissingPath('scopes.1')
            ->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/reservations?action=bootstrap')
            ->assertForbidden()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Scope mobile insuffisant.');
    }

    public function test_mobile_web_session_logout_revokes_the_launching_mobile_token(): void
    {
        [$account, $crmUser] = $this->createMobileCrmUser();

        $token = $this->postJson('/api/mobile/token', [
            'email' => $account->email,
            'password' => 'secret-mobile',
            'device_name' => 'Pixel Test',
        ])
            ->assertOk()
            ->json('token');

        $webSessionUrl = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/web-session', [
                'redirectPath' => '/',
                'siteId' => $crmUser->sites()->first()->id,
                'embed' => false,
            ])
            ->assertOk()
            ->json('url');

        $webSessionPath = parse_url((string) $webSessionUrl, PHP_URL_PATH);

        $this->get($webSessionPath)
            ->assertRedirect('/?mobile_app=1&mobile_site_id='.$crmUser->sites()->first()->id);

        $this->assertAuthenticatedAs($account);
        $this->assertTrue(session()->has('crm_mobile_token_id'));
        $this->assertTrue(session()->has('crm_mobile_app'));

        $this->post('/logout')
            ->assertRedirect('/login');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/me')
            ->assertUnauthorized();
    }

    public function test_mobile_app_session_shows_app_settings_entry_in_crm(): void
    {
        [$account] = $this->createMobileCrmUser();

        $this->actingAs($account)
            ->withSession(['crm_mobile_app' => true])
            ->get('/')
            ->assertOk()
            ->assertSee('crm-mobile-app', false)
            ->assertSee('"app":true', false);

        $this->flushSession();

        $this->actingAs($account)
            ->get('/?mobile_app=1')
            ->assertOk()
            ->assertSee('crm-mobile-app', false)
            ->assertSee('"app":true', false);

        $this->flushSession();

        $this->actingAs($account)
            ->get('/')
            ->assertOk()
            ->assertDontSee('crm-mobile-app', false)
            ->assertSee('"app":false', false);

        $this->flushSession();

        $this->actingAs($account)
            ->withSession(['crm_mobile_app' => true])
            ->get('/?source=pwa')
            ->assertOk()
            ->assertDontSee('crm-mobile-app', false)
            ->assertSee('"app":false', false)
            ->assertSessionMissing('crm_mobile_app');

        $fallbackNav = (string) file_get_contents(resource_path('frontend/crm/mobile/fallback-nav.ts'));
        $settings = (string) file_get_contents(resource_path('frontend/crm/mobile/settings.ts'));

        $this->assertStringContainsString('crm-mobile-fallback-nav', $fallbackNav);
        $this->assertStringContainsString('data-crm-mobile-settings-toggle', $settings);
    }

    public function test_mobile_tokens_are_revoked_after_password_reset_event(): void
    {
        [$account] = $this->createMobileCrmUser();

        $loginResponse = $this->postJson('/api/mobile/token', [
            'email' => $account->email,
            'password' => 'secret-mobile',
            'device_name' => 'Pixel Test',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('crm_mobile_refresh_tokens', 1);

        event(new PasswordReset($account));

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(
            0,
            DB::table('crm_mobile_refresh_tokens')->whereNull('revoked_at')->count()
        );
        $this->assertDatabaseHas('crm_mobile_refresh_tokens', [
            'revoked_reason' => 'password_reset',
        ]);

        $this->postJson('/api/mobile/refresh', [
            'refreshToken' => $loginResponse->json('refreshToken'),
        ])
            ->assertUnauthorized()
            ->assertJsonPath('ok', false);
    }

    /**
     * @return array{0: User, 1: CrmUser}
     */
    private function createMobileCrmUser(string $role = 'user'): array
    {
        $account = User::factory()->create([
            'email' => 'mobile-'.$role.'@example.test',
            'password' => 'secret-mobile',
        ]);

        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Mobile '.$role,
            'email' => $account->email,
            'role' => $role,
            'active' => true,
        ]);

        $site = CrmSite::query()->create([
            'name' => 'Palissy Mobile',
            'slug' => 'palissy-mobile',
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
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

        return [$account, $crmUser];
    }
}
