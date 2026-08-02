<?php

namespace Tests\Feature;

use App\Models\CrmEquipmentItem;
use App\Models\CrmMenuGroup;
use App\Models\CrmMenuItem;
use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\CrmAdministration\Filament\Resources\CrmUsers\Pages\ManageCrmUsers;
use Modules\CrmCore\Support\CrmReferenceCache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CrmAdministrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/administration?action=health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'laravel');
    }

    public function test_guest_cannot_read_administration_bootstrap(): void
    {
        $this->getJson('/api/administration?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_admin_can_read_and_save_profile(): void
    {
        [$account, $crmUser] = $this->createAdminUser();

        $readProfile = $this->actingAs($account)
            ->getJson('/api/administration?action=profile')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('profile.displayName', 'Jean-Philippe')
            ->assertJsonPath('profile.firstName', 'Jean-Philippe')
            ->assertJsonPath('profile.email', $account->email)
            ->assertJsonPath('profile.photoUrl', '/assets/logo/logomark.png')
            ->assertJsonPath('profile.canEditIdentity', true)
            ->json('profile');

        $reservationItem = collect($readProfile['navigation']['menuItems'])
            ->firstWhere('itemKey', 'module:reservations');
        $reservationModule = collect($readProfile['navigation']['modules'])
            ->firstWhere('slug', 'reservations');
        $leavesItem = collect($readProfile['navigation']['menuItems'])
            ->firstWhere('itemKey', 'module:conges');
        $adminUsersItem = collect($readProfile['navigation']['menuItems'])
            ->firstWhere('itemKey', 'admin:users');
        $adminReservationsItem = collect($readProfile['navigation']['menuItems'])
            ->firstWhere('itemKey', 'admin:reservations');
        $adminEquipmentItem = collect($readProfile['navigation']['menuItems'])
            ->firstWhere('itemKey', 'admin:equipment');
        $documentsGroup = collect($readProfile['navigation']['menuGroups'])
            ->firstWhere('menuKey', 'documents');

        $this->assertSame('truck', $reservationItem['iconKey'] ?? null);
        $this->assertSame('Martin', $reservationModule['menuBadge'] ?? null);
        $this->assertSame('#95002e', $reservationModule['menuBadgeColor'] ?? null);
        $this->assertTrue($reservationModule['showMenuBadge'] ?? false);
        $this->assertSame('Congés & Absences', $leavesItem['label'] ?? null);
        $this->assertSame('module:administration', $adminUsersItem['parentItemKey'] ?? null);
        $this->assertSame('module:administration', $adminReservationsItem['parentItemKey'] ?? null);
        $this->assertSame('module:administration', $adminEquipmentItem['parentItemKey'] ?? null);
        $this->assertSame('Documents', $documentsGroup['title'] ?? null);

        $profile = $this->actingAs($account)
            ->postJson('/api/administration?action=save_profile', [
                'firstName' => 'Jean-Philippe',
                'lastName' => 'Martin',
                'email' => 'jp.martin@example.test',
                'bio' => 'Direction HUB',
                'photoDataUrl' => $this->crmPngDataUrl(),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('profile.displayName', 'Jean-Philippe Martin')
            ->assertJsonPath('profile.email', 'jp.martin@example.test')
            ->assertJsonPath('profile.bio', 'Direction HUB')
            ->json('profile');

        $this->assertStringStartsWith('/uploads/assets/uploads/profiles/', $profile['photoUrl']);
        $this->assertStringEndsWith('.webp', $profile['photoUrl']);

        $this->assertDatabaseHas('users', [
            'id' => $crmUser->id,
            'name' => 'Jean-Philippe Martin',
            'first_name' => 'Jean-Philippe',
            'last_name' => 'Martin',
            'email' => 'jp.martin@example.test',
            'bio' => 'Direction HUB',
            'photo_url' => $profile['photoUrl'],
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $account->id,
            'name' => 'Jean-Philippe Martin',
            'email' => 'jp.martin@example.test',
        ]);

        $storedPath = substr((string) $profile['photoUrl'], strlen('/storage/'));
        Storage::disk('public')->delete($storedPath);
        Storage::disk('public')->delete(str_replace('.webp', '-thumb.webp', $storedPath));
    }

    public function test_non_admin_profile_cannot_change_identity(): void
    {
        $account = User::factory()->create([
            'name' => 'Marie Durand',
            'email' => 'marie.old@example.test',
        ]);
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Marie Durand',
            'first_name' => 'Marie',
            'last_name' => 'Durand',
            'email' => 'marie.old@example.test',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_profile', [
                'firstName' => 'Pirate',
                'lastName' => 'Invisible',
                'email' => 'marie.new@example.test',
                'bio' => 'Equipe Palissy',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('profile.displayName', 'Marie Durand')
            ->assertJsonPath('profile.firstName', 'Marie')
            ->assertJsonPath('profile.lastName', 'Durand')
            ->assertJsonPath('profile.email', 'marie.new@example.test')
            ->assertJsonPath('profile.bio', 'Equipe Palissy')
            ->assertJsonPath('profile.canEditIdentity', false);

        $this->assertDatabaseHas('users', [
            'id' => $crmUser->id,
            'name' => 'Marie Durand',
            'first_name' => 'Marie',
            'last_name' => 'Durand',
            'email' => 'marie.new@example.test',
            'bio' => 'Equipe Palissy',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $account->id,
            'name' => 'Marie Durand',
            'email' => 'marie.new@example.test',
        ]);
    }

    public function test_profile_rejects_duplicate_account_email(): void
    {
        [$account] = $this->createAdminUser();
        User::factory()->create(['email' => 'already-used@example.test']);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_profile', [
                'firstName' => 'Jean-Philippe',
                'lastName' => 'Martin',
                'email' => 'already-used@example.test',
            ])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Adresse e-mail deja utilisee');
    }

    public function test_profile_returns_real_connected_devices_from_sessions(): void
    {
        [$account] = $this->createAdminUser();

        DB::table('sessions')->insert([
            'id' => 'desktop-session',
            'user_id' => $account->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('sessions')->insert([
            'id' => 'other-user-session',
            'user_id' => User::factory()->create()->id,
            'ip_address' => '198.51.100.50',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $profile = $this->actingAs($account)
            ->getJson('/api/administration?action=profile')
            ->assertOk()
            ->assertJsonMissing(['ipAddress' => '198.51.100.50'])
            ->json('profile');

        $device = collect($profile['connectedDevices'])->firstWhere('id', substr(hash('sha256', 'desktop-session'), 0, 32));

        $this->assertNotNull($device);
        $this->assertSame('Chrome', $device['browser']);
        $this->assertSame('macOS', $device['platform']);
        $this->assertSame('Ordinateur', $device['deviceType']);
        $this->assertSame('192.0.2.10', $device['ipAddress']);
    }

    public function test_user_can_disconnect_another_session(): void
    {
        [$account] = $this->createAdminUser();

        DB::table('sessions')->insert([
            'id' => 'old-session',
            'user_id' => $account->id,
            'ip_address' => '192.0.2.11',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $profile = $this->actingAs($account)
            ->postJson('/api/administration?action=delete_session', [
                'sessionId' => substr(hash('sha256', 'old-session'), 0, 32),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('profile');

        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertNull(collect($profile['connectedDevices'])->firstWhere('id', substr(hash('sha256', 'old-session'), 0, 32)));
    }

    public function test_administration_bootstrap_cleans_template_menu_entries(): void
    {
        [$account] = $this->createAdminUser();

        CrmMenuGroup::query()->create([
            'menu_key' => 'dashboards',
            'title' => 'Dashboards',
            'active' => true,
            'sort_order' => 10,
        ]);
        CrmMenuGroup::query()->create([
            'menu_key' => 'charts',
            'title' => 'Charts',
            'active' => true,
            'sort_order' => 80,
        ]);
        CrmMenuItem::query()->create([
            'item_key' => 'dashboard:analytics',
            'group_key' => 'dashboards',
            'icon_key' => 'chartLine',
            'label' => 'Analytics',
            'active' => true,
            'sort_order' => 20,
        ]);
        CrmMenuItem::query()->create([
            'item_key' => 'chart:line',
            'group_key' => 'charts',
            'icon_key' => 'chartLine',
            'label' => 'Line',
            'active' => true,
            'sort_order' => 10,
        ]);
        CrmMenuItem::query()
            ->updateOrCreate(
                ['item_key' => 'module:documents-promo'],
                [
                    'group_key' => 'apps',
                    'icon_key' => 'article',
                    'label' => 'Documents',
                    'active' => true,
                    'sort_order' => 22,
                ],
            );

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->assertJsonMissing(['itemKey' => 'dashboard:analytics'])
            ->assertJsonMissing(['itemKey' => 'chart:line']);

        $this->assertDatabaseMissing('crm_menu_items', ['item_key' => 'dashboard:analytics']);
        $this->assertDatabaseMissing('crm_menu_items', ['item_key' => 'chart:line']);
        $this->assertDatabaseMissing('crm_menu_groups', ['menu_key' => 'dashboards']);
        $this->assertDatabaseHas('crm_menu_groups', [
            'menu_key' => 'home',
            'title' => 'Accueil',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_modules', [
            'slug' => 'dashboard',
            'route_path' => '/',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:dashboard',
            'group_key' => 'home',
            'label' => 'Tableau de bord',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_groups', [
            'menu_key' => 'apps',
            'title' => 'Applications HUB',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:reservations',
            'group_key' => 'apps',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:conges',
            'group_key' => 'apps',
            'label' => 'Congés & Absences',
            'sort_order' => 17,
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:planning',
            'active' => false,
        ]);
        $this->assertDatabaseHas('crm_modules', [
            'slug' => 'planning',
            'active' => false,
        ]);
        $this->assertDatabaseHas('crm_modules', [
            'slug' => 'pages-crm',
            'active' => false,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:pages-crm',
            'active' => false,
        ]);
        $this->assertDatabaseMissing('crm_menu_items', [
            'item_key' => 'admin:pages',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_modules', [
            'slug' => 'stats',
            'active' => false,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:stats',
            'active' => false,
        ]);
        $this->assertDatabaseMissing('crm_menu_groups', ['menu_key' => 'check_remittances']);
        $this->assertDatabaseHas('crm_menu_groups', [
            'menu_key' => 'accounting',
            'title' => 'Comptabilité',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:remise-cheques',
            'group_key' => 'accounting',
            'label' => 'Remise de chèques',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_modules', [
            'slug' => 'addvance',
            'route_path' => 'https://martinsols.addvancesolutions.fr',
            'active' => false,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:addvance',
            'group_key' => 'accounting',
            'label' => 'Addvance',
            'active' => false,
        ]);
        $this->assertDatabaseHas('crm_menu_groups', [
            'menu_key' => 'documents',
            'title' => 'Documents',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_modules', [
            'slug' => 'documents-promo',
            'route_path' => '/documents/promo',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_modules', [
            'slug' => 'documents-fiches-techniques',
            'route_path' => '/documents/fiches-techniques',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_modules', [
            'slug' => 'documents-procedures',
            'route_path' => '/documents/procedures',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:documents-promo',
            'group_key' => 'documents',
            'label' => 'Promo',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:documents-fiches-techniques',
            'group_key' => 'documents',
            'label' => 'Fiches techniques',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:documents-procedures',
            'group_key' => 'documents',
            'label' => 'Procédures',
            'active' => true,
        ]);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:documents',
            'active' => false,
        ]);
    }

    public function test_menu_settings_keep_custom_label_and_visibility_after_bootstrap(): void
    {
        [$account] = $this->createAdminUser();

        $bootstrap = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json();

        $congesItem = collect($bootstrap['menuItems'])
            ->firstWhere('itemKey', 'module:conges');

        $this->assertNotNull($congesItem);
        $this->assertSame('apps', $congesItem['groupKey']);
        $this->assertSame('Congés & Absences', $congesItem['label']);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_menu_settings', [
                'items' => [[
                    'itemKey' => 'module:conges',
                    'groupKey' => $congesItem['groupKey'],
                    'iconKey' => $congesItem['iconKey'],
                    'label' => 'Absences équipe',
                    'active' => false,
                    'sortOrder' => $congesItem['sortOrder'],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:conges',
            'label' => 'Absences équipe',
            'active' => false,
        ]);

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->assertJsonFragment([
                'itemKey' => 'module:conges',
                'label' => 'Absences équipe',
                'active' => false,
            ]);
    }

    public function test_menu_settings_keep_conges_in_home_group_after_bootstrap(): void
    {
        [$account] = $this->createAdminUser();

        $bootstrap = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json();

        $congesItem = collect($bootstrap['menuItems'])
            ->firstWhere('itemKey', 'module:conges');

        $this->assertNotNull($congesItem);
        $this->assertSame('apps', $congesItem['groupKey']);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_menu_settings', [
                'items' => [[
                    'itemKey' => 'module:conges',
                    'groupKey' => 'home',
                    'parentItemKey' => '',
                    'iconKey' => $congesItem['iconKey'],
                    'label' => $congesItem['label'],
                    'active' => true,
                    'sortOrder' => 20,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:conges',
            'group_key' => 'home',
            'parent_item_key' => null,
            'sort_order' => 20,
        ]);

        $reloaded = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json();

        $reloadedCongesItem = collect($reloaded['menuItems'])
            ->firstWhere('itemKey', 'module:conges');

        $this->assertSame('home', $reloadedCongesItem['groupKey'] ?? null);
        $this->assertNull($reloadedCongesItem['parentItemKey'] ?? null);
    }

    public function test_admin_can_assign_menu_item_as_sub_link(): void
    {
        [$account] = $this->createAdminUser();

        $bootstrap = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json();

        $adminUsersItem = collect($bootstrap['menuItems'])
            ->firstWhere('itemKey', 'admin:users');

        $this->assertNotNull($adminUsersItem);
        $this->assertSame('module:administration', $adminUsersItem['parentItemKey'] ?? null);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_menu_settings', [
                'items' => [[
                    'itemKey' => 'admin:users',
                    'groupKey' => 'internal',
                    'parentItemKey' => 'module:administration',
                    'iconKey' => 'users',
                    'label' => 'Utilisateurs HUB',
                    'active' => true,
                    'sortOrder' => 15,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'admin:users',
            'group_key' => 'internal',
            'parent_item_key' => 'module:administration',
            'label' => 'Utilisateurs HUB',
            'sort_order' => 15,
        ]);
    }

    public function test_bootstrap_normalizes_legacy_administration_menu_label(): void
    {
        [$account] = $this->createAdminUser();

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk();

        CrmMenuItem::query()
            ->where('item_key', 'module:administration')
            ->update(['label' => 'Tableau de bord']);

        $bootstrap = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json();

        $administrationItem = collect($bootstrap['menuItems'])
            ->firstWhere('itemKey', 'module:administration');

        $this->assertSame('Administration', $administrationItem['label'] ?? null);
        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'module:administration',
            'label' => 'Administration',
        ]);
    }

    public function test_admin_can_delete_menu_item_and_sub_links(): void
    {
        [$account] = $this->createAdminUser();

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk();

        $deletedItemKeys = $this->actingAs($account)
            ->postJson('/api/administration?action=delete_menu_item', [
                'itemKey' => 'module:administration',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('deletedItemKeys');

        $this->assertContains('module:administration', $deletedItemKeys);
        $this->assertContains('admin:users', $deletedItemKeys);
        $this->assertSoftDeleted('crm_menu_items', ['item_key' => 'module:administration']);
        $this->assertSoftDeleted('crm_menu_items', ['item_key' => 'admin:users']);

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->assertJsonMissing(['itemKey' => 'module:administration'])
            ->assertJsonMissing(['itemKey' => 'admin:users']);
    }

    public function test_profile_navigation_keeps_admin_page_visible_when_moved_to_top_level(): void
    {
        [$account] = $this->createAdminUser();

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk();

        CrmMenuItem::query()
            ->where('item_key', 'admin:users')
            ->update([
                'group_key' => 'internal',
                'parent_item_key' => null,
                'active' => true,
                'sort_order' => 10,
            ]);

        $profile = $this->actingAs($account)
            ->getJson('/api/administration?action=profile')
            ->assertOk()
            ->json('profile');

        $adminUsersItem = collect($profile['navigation']['menuItems'])
            ->firstWhere('itemKey', 'admin:users');
        $administrationGroup = collect($profile['navigation']['menuGroups'])
            ->firstWhere('menuKey', 'internal');

        $this->assertNotNull($adminUsersItem);
        $this->assertSame('internal', $adminUsersItem['groupKey'] ?? null);
        $this->assertNull($adminUsersItem['parentItemKey'] ?? null);
        $this->assertSame('Administration', $administrationGroup['title'] ?? null);
    }

    public function test_user_without_platform_permission_cannot_read_bootstrap(): void
    {
        $account = User::factory()->create();
        CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Simple User',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit administration insuffisant');
    }

    public function test_admin_can_save_site_with_hours(): void
    {
        [$account] = $this->createAdminUser();

        $siteId = $this->actingAs($account)
            ->postJson('/api/administration?action=save_site', [
                'name' => 'Atelier Nord',
                'active' => true,
                'address' => '12 rue des Artisans, 64000 Pau',
                'phone' => '05 59 00 00 00',
                'email' => 'atelier-nord@example.test',
                'color' => '#2563eb',
                'photoDataUrl' => $this->crmPngDataUrl(16, 10),
                'showPhotoInHeader' => false,
                'hours' => [
                    'morningStart' => '08:00',
                    'morningEnd' => '12:15',
                    'afternoonStart' => '13:45',
                    'afternoonEnd' => '18:00',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('id');

        $photoUrl = (string) CrmSite::query()->whereKey($siteId)->value('photo_url');

        $this->assertStringStartsWith('/uploads/assets/uploads/sites/', $photoUrl);
        $this->assertStringEndsWith('.webp', $photoUrl);
        $photoPath = substr($photoUrl, strlen('/uploads/'));
        Storage::disk('public')->assertExists($photoPath);
        Storage::disk('public')->assertExists(str_replace('.webp', '-thumb.webp', $photoPath));

        $this->assertDatabaseHas('crm_sites', [
            'id' => $siteId,
            'name' => 'Atelier Nord',
            'slug' => 'atelier-nord',
            'morning_start' => '08:00:00',
            'afternoon_end' => '18:00:00',
            'address' => '12 rue des Artisans, 64000 Pau',
            'phone' => '05 59 00 00 00',
            'email' => 'atelier-nord@example.test',
            'color' => '#2563eb',
            'photo_url' => $photoUrl,
            'show_photo_in_header' => false,
        ]);

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $siteId,
                'address' => '12 rue des Artisans, 64000 Pau',
                'phone' => '05 59 00 00 00',
                'email' => 'atelier-nord@example.test',
                'color' => '#2563eb',
                'photoUrl' => $photoUrl,
                'showPhotoInHeader' => false,
            ]);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_site', [
                'id' => $siteId,
                'name' => 'Atelier Nord',
                'active' => true,
                'address' => '12 rue des Artisans, 64000 Pau',
                'phone' => '05 59 00 00 00',
                'email' => 'atelier-nord@example.test',
                'color' => '#2563eb',
                'removePhoto' => true,
                'hours' => [
                    'morningStart' => '08:00',
                    'morningEnd' => '12:15',
                    'afternoonStart' => '13:45',
                    'afternoonEnd' => '18:00',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('crm_sites', [
            'id' => $siteId,
            'photo_url' => null,
            'show_photo_in_header' => false,
        ]);
        Storage::disk('public')->assertMissing($photoPath);
        Storage::disk('public')->assertMissing(str_replace('.webp', '-thumb.webp', $photoPath));
    }

    public function test_admin_site_color_must_be_hex(): void
    {
        [$account] = $this->createAdminUser();

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_site', [
                'name' => 'Atelier Sud',
                'active' => true,
                'color' => 'rouge',
            ])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Couleur invalide');
    }

    public function test_admin_can_manage_module_menu_badge_text_and_color(): void
    {
        [$account] = $this->createAdminUser();

        $moduleId = (int) collect($this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json('modules'))
            ->firstWhere('slug', 'reservations')['id'];

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_module', [
                'id' => $moduleId,
                'name' => 'Réservations véhicules',
                'slug' => 'reservations',
                'routePath' => '/reservations',
                'menuBadge' => 'Essai',
                'menuBadgeColor' => '#f5b212',
                'showMenuBadge' => true,
                'active' => true,
                'sortOrder' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('crm_modules', [
            'id' => $moduleId,
            'menu_badge' => 'Essai',
            'menu_badge_color' => '#f5b212',
            'show_menu_badge' => true,
        ]);

        $module = collect($this->actingAs($account)
            ->getJson('/api/administration?action=profile')
            ->assertOk()
            ->json('profile.navigation.modules'))
            ->firstWhere('slug', 'reservations');

        $this->assertSame('Essai', $module['menuBadge'] ?? null);
        $this->assertSame('#f5b212', $module['menuBadgeColor'] ?? null);
        $this->assertTrue($module['showMenuBadge'] ?? false);
    }

    public function test_admin_can_reorder_sites_for_hub_display(): void
    {
        [$account] = $this->createAdminUser();

        $sites = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json('sites');

        $orderedIds = array_reverse(collect($sites)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        $this->assertGreaterThan(1, count($orderedIds));

        $this->actingAs($account)
            ->postJson('/api/administration?action=reorder_sites', [
                'sites' => collect($orderedIds)
                    ->map(fn (int $id, int $index): array => [
                        'id' => $id,
                        'sortOrder' => ($index + 1) * 10,
                    ])
                    ->values()
                    ->all(),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame($orderedIds, CrmSite::query()
            ->orderedForHub()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        $this->assertSame($orderedIds, collect(CrmReferenceCache::activeSiteRows())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('sites.0.id', $orderedIds[0]);
    }

    public function test_admin_can_manage_reservation_vehicles_from_hub_administration(): void
    {
        [$account] = $this->createAdminUser();

        $sites = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json('sites');
        $siteId = (int) collect($sites)->first()['id'];

        $vehicleId = $this->actingAs($account)
            ->postJson('/api/administration?action=save_vehicle', [
                'siteId' => $siteId,
                'name' => 'Camion atelier',
                'description' => 'Vehicule principal du site',
                'color' => '#2563eb',
                'dayStartTime' => '07:00',
                'dayEndTime' => '18:30',
                'photoDataUrl' => $this->crmPngDataUrl(16, 10),
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('id');

        $vehicle = CrmVehicle::query()->findOrFail($vehicleId);
        $photoUrl = (string) $vehicle->photo_url;
        $photoPath = substr($photoUrl, strlen('/uploads/'));

        $this->assertStringStartsWith('/uploads/assets/uploads/vehicles/', $photoUrl);
        Storage::disk('public')->assertExists($photoPath);
        $this->assertDatabaseHas('crm_vehicles', [
            'id' => $vehicleId,
            'site_id' => $siteId,
            'name' => 'Camion atelier',
            'description' => 'Vehicule principal du site',
            'color' => '#2563eb',
            'photo_url' => $photoUrl,
            'active' => true,
        ]);

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $vehicleId,
                'siteId' => $siteId,
                'name' => 'Camion atelier',
                'description' => 'Vehicule principal du site',
                'dayStartTime' => '07:00',
                'dayEndTime' => '18:30',
                'photoUrl' => $photoUrl,
                'reservationsCount' => 0,
            ]);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_vehicle', [
                'id' => $vehicleId,
                'siteId' => $siteId,
                'name' => 'Camion atelier',
                'description' => 'Vehicule principal du site',
                'color' => '#2563eb',
                'dayStartTime' => '07:00',
                'dayEndTime' => '18:30',
                'removePhoto' => true,
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('crm_vehicles', [
            'id' => $vehicleId,
            'active' => false,
            'photo_url' => null,
        ]);
        Storage::disk('public')->assertMissing($photoPath);

        $this->actingAs($account)
            ->postJson('/api/administration?action=delete_vehicle', ['id' => $vehicleId])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('archived', false);

        $this->assertDatabaseMissing('crm_vehicles', ['id' => $vehicleId]);
    }

    public function test_admin_can_manage_equipment_categories_and_items_from_hub_administration(): void
    {
        [$account] = $this->createAdminUser();

        $sites = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json('sites');
        $siteId = (int) collect($sites)->first()['id'];

        $categoryId = $this->actingAs($account)
            ->postJson('/api/administration?action=save_equipment_category', [
                'name' => 'Ponceuses',
                'active' => true,
                'sortOrder' => 35,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('id');

        $itemId = $this->actingAs($account)
            ->postJson('/api/administration?action=save_equipment_item', [
                'siteId' => $siteId,
                'categoryId' => $categoryId,
                'name' => 'Ponceuse test HUB',
                'inventoryCode' => 'PONCEUSE-HUB-01',
                'description' => 'Materiel administrable depuis le HUB',
                'color' => '#95002e',
                'halfDayPrice' => 42.5,
                'dayPrice' => 80,
                'depositAmount' => 150,
                'rentalMode' => 'day_only',
                'showHalfDayPrice' => false,
                'showDayPrice' => true,
                'photoDataUrl' => $this->crmPngDataUrl(16, 10),
                'active' => true,
                'sortOrder' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('id');

        $item = CrmEquipmentItem::query()->findOrFail($itemId);
        $photoUrl = (string) $item->photo_url;
        $photoPath = substr($photoUrl, strlen('/uploads/'));

        $this->assertStringStartsWith('/uploads/assets/uploads/equipment/', $photoUrl);
        Storage::disk('public')->assertExists($photoPath);
        $this->assertDatabaseHas('crm_equipment_categories', [
            'id' => $categoryId,
            'name' => 'Ponceuses',
            'slug' => 'ponceuses',
            'active' => true,
            'sort_order' => 35,
        ]);
        $this->assertDatabaseHas('crm_equipment_items', [
            'id' => $itemId,
            'site_id' => $siteId,
            'category_id' => $categoryId,
            'name' => 'Ponceuse test HUB',
            'inventory_code' => 'PONCEUSE-HUB-01',
            'description' => 'Materiel administrable depuis le HUB',
            'color' => '#95002e',
            'show_half_day_price' => false,
            'show_day_price' => true,
            'rental_mode' => 'day_only',
            'photo_url' => $photoUrl,
            'active' => true,
            'sort_order' => 20,
        ]);

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $categoryId,
                'name' => 'Ponceuses',
                'slug' => 'ponceuses',
                'itemsCount' => 1,
            ])
            ->assertJsonFragment([
                'id' => $itemId,
                'siteId' => $siteId,
                'categoryId' => $categoryId,
                'categoryName' => 'Ponceuses',
                'name' => 'Ponceuse test HUB',
                'inventoryCode' => 'PONCEUSE-HUB-01',
                'photoUrl' => $photoUrl,
                'showHalfDayPrice' => false,
                'showDayPrice' => true,
                'rentalMode' => 'day_only',
                'rentalsCount' => 0,
            ]);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_equipment_item', [
                'id' => $itemId,
                'siteId' => $siteId,
                'categoryId' => $categoryId,
                'name' => 'Ponceuse test HUB',
                'inventoryCode' => 'PONCEUSE-HUB-01',
                'description' => 'Materiel administrable depuis le HUB',
                'color' => '#95002e',
                'halfDayPrice' => 42.5,
                'dayPrice' => 80,
                'depositAmount' => 150,
                'rentalMode' => 'day_only',
                'showHalfDayPrice' => false,
                'showDayPrice' => true,
                'removePhoto' => true,
                'active' => false,
                'sortOrder' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('crm_equipment_items', [
            'id' => $itemId,
            'active' => false,
            'photo_url' => null,
        ]);
        Storage::disk('public')->assertMissing($photoPath);

        $this->actingAs($account)
            ->postJson('/api/administration?action=delete_equipment_item', ['id' => $itemId])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('archived', false);
        $this->actingAs($account)
            ->postJson('/api/administration?action=delete_equipment_category', ['id' => $categoryId])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('archived', false);

        $this->assertDatabaseMissing('crm_equipment_items', ['id' => $itemId]);
        $this->assertDatabaseMissing('crm_equipment_categories', ['id' => $categoryId]);
    }

    public function test_admin_can_create_blocked_user_without_rights(): void
    {
        [$account] = $this->createAdminUser();

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk();

        $siteId = CrmSite::query()->value('id');
        $moduleId = CrmModule::query()->value('id');
        $permissionId = CrmPermission::query()->value('id');

        $userId = $this->actingAs($account)
            ->postJson('/api/administration?action=save_user', [
                'name' => 'Compte bloque',
                'role' => 'blocked',
                'active' => true,
                'siteIds' => [$siteId],
                'moduleIds' => [$moduleId],
                'permissionIds' => [$permissionId],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('id');

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'name' => 'Compte bloque',
            'role' => 'blocked',
        ]);
        $this->assertDatabaseMissing('crm_user_modules', ['user_id' => $userId]);
        $this->assertDatabaseMissing('crm_user_permissions', ['user_id' => $userId]);
        $this->assertDatabaseHas('crm_user_sites', ['user_id' => $userId, 'site_id' => $siteId]);
    }

    public function test_admin_can_keep_multiple_user_sites_with_selected_primary_site(): void
    {
        [$account] = $this->createAdminUser();

        $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk();

        $siteIds = CrmSite::query()
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $target = CrmUser::query()->create([
            'name' => 'Membre multisite',
            'email' => 'membre.multisite@example.test',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_user', [
                'id' => $target->id,
                'name' => 'Membre multisite',
                'email' => 'membre.multisite@example.test',
                'role' => 'responsable',
                'active' => true,
                'primarySiteId' => $siteIds[1],
                'siteIds' => $siteIds,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('crm_user_sites', [
            'user_id' => $target->id,
            'site_id' => $siteIds[0],
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('crm_user_sites', [
            'user_id' => $target->id,
            'site_id' => $siteIds[1],
            'is_default' => true,
        ]);
    }

    public function test_admin_can_change_linked_member_password_from_hub_administration(): void
    {
        [$account] = $this->createAdminUser();
        $targetAccount = User::factory()->create([
            'name' => 'Membre Password',
            'email' => 'membre.password@example.test',
            'password' => 'Ancien-Membre-2026!',
        ]);
        $target = CrmUser::query()->create([
            'user_id' => $targetAccount->id,
            'name' => 'Membre Password',
            'email' => $targetAccount->email,
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_user', [
                'id' => $target->id,
                'name' => 'Membre Password',
                'email' => $targetAccount->email,
                'role' => 'user',
                'active' => true,
                'password' => 'Nouveau-Membre-2026!',
                'passwordConfirmation' => 'Nouveau-Membre-2026!',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('id', $target->id);

        $this->assertTrue(Hash::check('Nouveau-Membre-2026!', $targetAccount->refresh()->password));

        $users = $this->actingAs($account)
            ->getJson('/api/administration?action=bootstrap')
            ->assertOk()
            ->json('users');
        $targetRow = collect($users)->firstWhere('id', $target->id);

        $this->assertTrue($targetRow['hasAccount']);
        $this->assertArrayNotHasKey('password', $targetRow);
    }

    public function test_member_password_confirmation_is_required_when_password_changes(): void
    {
        [$account] = $this->createAdminUser();
        $targetAccount = User::factory()->create([
            'password' => 'Ancien-Membre-2026!',
        ]);
        $target = CrmUser::query()->create([
            'user_id' => $targetAccount->id,
            'name' => 'Membre Confirmation',
            'email' => $targetAccount->email,
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account)
            ->postJson('/api/administration?action=save_user', [
                'id' => $target->id,
                'name' => 'Membre Confirmation',
                'email' => $targetAccount->email,
                'role' => 'user',
                'active' => true,
                'password' => 'Nouveau-Membre-2026!',
                'passwordConfirmation' => 'Autre-Membre-2026!',
            ])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Confirmation du mot de passe invalide');

        $this->assertTrue(Hash::check('Ancien-Membre-2026!', $targetAccount->refresh()->password));
    }

    public function test_role_manager_without_user_permission_cannot_change_member_password(): void
    {
        $actorAccount = User::factory()->create();
        $actor = CrmUser::query()->create([
            'user_id' => $actorAccount->id,
            'name' => 'Gestionnaire Roles',
            'role' => 'user',
            'active' => true,
        ]);
        $permission = CrmPermission::query()->create([
            'name' => 'platform.manage_roles',
            'label' => 'Gerer les roles',
            'group_label' => 'Administration',
            'sort_order' => 180,
        ]);
        $actor->permissions()->sync([$permission->id]);
        $targetAccount = User::factory()->create([
            'password' => 'Ancien-Membre-2026!',
        ]);
        $target = CrmUser::query()->create([
            'user_id' => $targetAccount->id,
            'name' => 'Membre Refuse',
            'email' => $targetAccount->email,
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($actorAccount)
            ->postJson('/api/administration?action=save_user', [
                'id' => $target->id,
                'name' => 'Membre Refuse',
                'email' => $targetAccount->email,
                'role' => 'user',
                'active' => true,
                'password' => 'Nouveau-Membre-2026!',
                'passwordConfirmation' => 'Nouveau-Membre-2026!',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit administration insuffisant');

        $this->assertTrue(Hash::check('Ancien-Membre-2026!', $targetAccount->refresh()->password));
    }

    public function test_filament_user_edit_action_can_change_linked_member_password(): void
    {
        [$account] = $this->createAdminUser();
        Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $account->assignRole('admin');

        $targetAccount = User::factory()->create([
            'name' => 'Membre Filament',
            'email' => 'membre.filament@example.test',
            'password' => 'Ancien-Filament-2026!',
        ]);
        $target = CrmUser::query()->create([
            'user_id' => $targetAccount->id,
            'name' => 'Membre Filament',
            'email' => $targetAccount->email,
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account);

        Livewire::test(ManageCrmUsers::class)
            ->callTableAction('edit', $target, [
                'name' => 'Membre Filament',
                'first_name' => null,
                'last_name' => null,
                'email' => $targetAccount->email,
                'phone' => null,
                'user_id' => $targetAccount->id,
                'role' => 'user',
                'active' => true,
                'password' => 'Nouveau-Filament-2026!',
                'password_confirmation' => 'Nouveau-Filament-2026!',
                'sites' => [],
                'modules' => [],
                'permissions' => [],
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue(Hash::check('Nouveau-Filament-2026!', $targetAccount->refresh()->password));
    }

    /**
     * @return array{0: User, 1: CrmUser}
     */
    private function createAdminUser(): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'J-Philippe',
            'role' => 'admin',
            'active' => true,
        ]);

        return [$account, $crmUser];
    }
}
