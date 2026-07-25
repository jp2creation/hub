<?php

namespace Tests\Feature;

use App\Models\CrmEquipmentCategory;
use App\Models\CrmEquipmentItem;
use App\Models\CrmEquipmentRental;
use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrmEquipmentRentalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/equipment-rentals?action=health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'mysql');
    }

    public function test_guest_cannot_read_equipment_bootstrap(): void
    {
        $this->getJson('/api/equipment-rentals?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_admin_can_access_equipment_without_explicit_module_permissions(): void
    {
        $account = User::factory()->create();
        CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Admin Equipment',
            'role' => 'admin',
            'active' => true,
        ]);

        $site = CrmSite::query()->create([
            'name' => 'Palissy Admin',
            'slug' => 'palissy-admin',
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);

        $category = CrmEquipmentCategory::query()->create([
            'name' => 'Poncage Admin',
            'slug' => 'poncage-admin',
            'active' => true,
            'sort_order' => 10,
        ]);

        $item = CrmEquipmentItem::query()->create([
            'site_id' => $site->id,
            'category_id' => $category->id,
            'name' => 'Ponceuse Admin',
            'inventory_code' => 'PON-ADMIN',
            'description' => 'Materiel test admin',
            'color' => '#95002e',
            'half_day_price' => 45,
            'day_price' => 80,
            'deposit_amount' => 300,
            'active' => true,
            'sort_order' => 10,
        ]);

        CrmModule::query()->updateOrCreate(
            ['slug' => 'locations-materiel'],
            [
                'name' => 'Location materiel',
                'description' => 'Planning et locations du materiel interne',
                'route_path' => '/locations-materiel',
                'active' => true,
                'sort_order' => 15,
            ],
        );

        foreach (['equipment_rentals.view', 'equipment_rentals.create'] as $index => $permission) {
            CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => 'Location materiel',
                    'sort_order' => 120 + $index,
                ],
            );
        }

        $this->actingAs($account)
            ->getJson('/api/equipment-rentals?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('equipmentItems.0.name', 'Ponceuse Admin')
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.siteIds.0', $site->id);

        $date = now()->addMonth()->format('Y-m-d');

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'date' => $date,
                'slot' => 'morning',
                'title' => 'Location admin',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('equipmentRental.title', 'Location admin');
    }

    public function test_authorized_user_can_read_equipment_bootstrap(): void
    {
        [$account, $crmUser, $site, $item] = $this->createCrmUser(['equipment_rentals.view']);

        CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $item->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'half_day',
            'slot' => 'morning',
            'status' => 'reserved',
            'title' => 'Location test',
            'contact_phone' => '',
            'start_at' => '2026-08-03 08:00:00',
            'end_at' => '2026-08-03 09:00:00',
            'notes' => '',
        ]);

        $this->actingAs($account)
            ->getJson('/api/equipment-rentals?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('equipmentItems.0.name', 'Ponceuse Test')
            ->assertJsonPath('equipmentItems.0.showDayPrice', true)
            ->assertJsonPath('equipmentItems.0.rentalMode', 'half_day_and_day')
            ->assertJsonPath('equipmentRentals.0.title', 'Location test')
            ->assertJsonPath('user.siteIds.0', $site->id);
    }

    public function test_equipment_bootstrap_returns_minimal_users_for_allowed_sites(): void
    {
        [$account, , $site] = $this->createCrmUser(['equipment_rentals.view']);

        $module = CrmModule::query()->where('slug', 'locations-materiel')->firstOrFail();
        $permission = CrmPermission::query()->where('name', 'equipment_rentals.view')->firstOrFail();

        $sameSiteUser = CrmUser::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Aline Meme Site',
            'role' => 'responsable',
            'active' => true,
        ]);
        $sameSiteUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $sameSiteUser->modules()->syncWithoutDetaching([$module->id]);
        $sameSiteUser->permissions()->syncWithoutDetaching([$permission->id]);

        $otherSite = CrmSite::query()->create([
            'name' => 'Autre Site Materiel',
            'slug' => 'autre-site-materiel',
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);
        $otherSiteUser = CrmUser::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Bernard Hors Site',
            'role' => 'responsable',
            'active' => true,
        ]);
        $otherSiteUser->sites()->syncWithoutDetaching([$otherSite->id => ['is_default' => true]]);

        $inactiveUser = CrmUser::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Claude Inactif',
            'role' => 'responsable',
            'active' => false,
        ]);
        $inactiveUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => false]]);

        $users = collect($this->actingAs($account)
            ->getJson('/api/equipment-rentals?action=bootstrap')
            ->assertOk()
            ->json('users'));

        $sameSiteRow = $users->firstWhere('id', $sameSiteUser->id);

        $this->assertNotNull($sameSiteRow);
        $this->assertIsArray($sameSiteRow);
        $this->assertSame(['id', 'name'], array_keys($sameSiteRow));
        $this->assertSame('Aline Meme Site', $sameSiteRow['name']);
        $this->assertFalse($users->contains('id', $otherSiteUser->id));
        $this->assertFalse($users->contains('id', $inactiveUser->id));
    }

    public function test_split_equipment_read_endpoints_are_windowed(): void
    {
        [$account, $crmUser, $site, $item] = $this->createCrmUser(['equipment_rentals.view']);

        CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $item->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'half_day',
            'slot' => 'morning',
            'status' => 'reserved',
            'title' => 'Location juillet',
            'contact_phone' => '',
            'start_at' => '2026-07-15 08:00:00',
            'end_at' => '2026-07-15 12:00:00',
            'notes' => '',
        ]);

        CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $item->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'half_day',
            'slot' => 'morning',
            'status' => 'reserved',
            'title' => 'Location hors fenetre',
            'contact_phone' => '',
            'start_at' => '2027-02-15 08:00:00',
            'end_at' => '2027-02-15 12:00:00',
            'notes' => '',
        ]);

        $this->actingAs($account)
            ->getJson('/api/equipment-rentals/bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('equipmentItems.0.name', 'Ponceuse Test')
            ->assertJsonMissingPath('equipmentRentals');

        $rentals = $this->actingAs($account)
            ->getJson('/api/equipment-rentals?from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('window.from', '2026-07-01T00:00')
            ->json('equipmentRentals');

        $this->assertCount(1, $rentals);
        $this->assertSame('Location juillet', $rentals[0]['title']);

        $this->actingAs($account)
            ->getJson('/api/equipment-rentals?from=2026-01-01&to=2026-12-31')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Fenetre de dates trop large');
    }

    public function test_equipment_users_and_items_endpoints_support_cursor_pagination(): void
    {
        [$account, , $site, $item] = $this->createCrmUser(['equipment_rentals.view']);

        CrmEquipmentItem::query()->create([
            'site_id' => $site->id,
            'category_id' => $item->category_id,
            'name' => 'Bordureuse Test',
            'inventory_code' => 'BOR-TEST',
            'description' => 'Second materiel',
            'color' => '#95002e',
            'half_day_price' => 35,
            'day_price' => 60,
            'deposit_amount' => 250,
            'active' => true,
            'sort_order' => 20,
        ]);

        $itemsPage = $this->actingAs($account)
            ->getJson('/api/equipment-rentals/items?limit=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.hasMore', true)
            ->json();

        $this->assertSame($item->id, $itemsPage['equipmentItems'][0]['id']);
        $this->assertSame($item->id, $itemsPage['pagination']['nextCursor']);

        $this->actingAs($account)
            ->getJson('/api/equipment-rentals/items?limit=1&cursor='.$itemsPage['pagination']['nextCursor'])
            ->assertOk()
            ->assertJsonPath('equipmentItems.0.name', 'Bordureuse Test')
            ->assertJsonPath('pagination.hasMore', false);

        $this->actingAs($account)
            ->getJson('/api/equipment-rentals/users?limit=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('users.0.name', 'HUB Equipment User '.$account->id);

        $this->actingAs($account)
            ->getJson('/api/equipment-rentals/categories')
            ->assertOk()
            ->assertJsonPath('equipmentCategories.0.slug', 'poncage-test');
    }

    public function test_create_permission_is_required_to_create_rental(): void
    {
        [$account, , , $item] = $this->createCrmUser(['equipment_rentals.view']);

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'date' => '2026-08-04',
                'slot' => 'morning',
                'title' => 'Sans droit',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : equipment_rentals.create');
    }

    public function test_overlapping_rental_is_rejected(): void
    {
        [$account, , , $item] = $this->createCrmUser(['equipment_rentals.create']);

        $payload = [
            'equipmentItemId' => $item->id,
            'startAt' => '2026-08-05T08:00',
            'endAt' => '2026-08-05T10:00',
            'title' => 'Premiere location',
        ];

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('equipmentRental.title', 'Premiere location');

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                ...$payload,
                'startAt' => '2026-08-05T09:30',
                'endAt' => '2026-08-05T11:00',
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Ce materiel est deja loue sur ce creneau');
    }

    public function test_past_rental_is_rejected(): void
    {
        [$account, , , $item] = $this->createCrmUser(['equipment_rentals.create']);
        $yesterday = now()->subDay()->format('Y-m-d');

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'startAt' => $yesterday.'T08:00',
                'endAt' => $yesterday.'T09:00',
                'title' => 'Location passee',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Impossible de reserver dans le passe');
    }

    public function test_user_can_update_own_rental(): void
    {
        [$account, , , $item] = $this->createCrmUser(['equipment_rentals.create', 'equipment_rentals.update_own']);

        $rentalId = $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'startAt' => '2026-08-06T08:00',
                'endAt' => '2026-08-06T09:00',
                'title' => 'A modifier',
            ])
            ->assertOk()
            ->json('equipmentRental.id');

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=update_rental', [
                'id' => $rentalId,
                'equipmentItemId' => $item->id,
                'startAt' => '2026-08-06T09:00',
                'endAt' => '2026-08-06T10:00',
                'title' => 'Modifie',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('equipmentRental.title', 'Modifie')
            ->assertJsonPath('equipmentRental.startAt', '2026-08-06T09:00');
    }

    public function test_creator_without_delete_own_cannot_delete_rental(): void
    {
        [$account, , , $item] = $this->createCrmUser(['equipment_rentals.create']);

        $rentalId = $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'startAt' => '2026-08-06T10:30',
                'endAt' => '2026-08-06T11:30',
                'title' => 'Sans droit suppression',
            ])
            ->assertOk()
            ->json('equipmentRental.id');

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=delete_rental', ['id' => $rentalId])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Suppression non autorisee');

        $this->assertDatabaseHas('crm_equipment_rentals', [
            'id' => $rentalId,
        ]);
    }

    public function test_creator_with_delete_own_can_delete_rental(): void
    {
        [$account, , , $item] = $this->createCrmUser(['equipment_rentals.create', 'equipment_rentals.delete_own']);

        $rentalId = $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'startAt' => '2026-08-06T13:30',
                'endAt' => '2026-08-06T14:30',
                'title' => 'Avec droit suppression',
            ])
            ->assertOk()
            ->json('equipmentRental.id');

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=delete_rental', ['id' => $rentalId])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('crm_equipment_rentals', [
            'id' => $rentalId,
        ]);
    }

    public function test_manager_can_save_and_hide_equipment_item(): void
    {
        [$account, , $site] = $this->createCrmUser(['equipment_rentals.manage_items']);

        $item = $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=save_equipment_item', [
                'siteId' => $site->id,
                'categoryName' => 'Outillage',
                'name' => 'Scie test',
                'inventoryCode' => 'SCIE-TEST',
                'description' => 'Materiel atelier',
                'color' => '#123abc',
                'photoDataUrl' => $this->crmPngDataUrl(),
                'halfDayPrice' => '12,5',
                'dayPrice' => '20',
                'showDayPrice' => false,
                'rentalMode' => 'day_only',
                'depositAmount' => '100',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('equipmentItem.name', 'Scie test')
            ->assertJsonPath('equipmentItem.showDayPrice', false)
            ->assertJsonPath('equipmentItem.rentalMode', 'day_only')
            ->assertJsonPath('equipmentCategory.slug', 'outillage')
            ->json('equipmentItem');

        $itemId = (int) $item['id'];
        $photoPath = preg_replace('#^/(?:storage|uploads)/#', '', (string) $item['photoUrl']) ?? '';

        $this->assertMatchesRegularExpression('#^/(storage|uploads)/assets/uploads/equipment/#', $item['photoUrl']);
        Storage::disk('public')->assertExists($photoPath);
        Storage::disk('public')->assertExists(str_replace('.webp', '-thumb.webp', $photoPath));

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=delete_equipment_item', ['id' => $itemId])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('id', $itemId);

        Storage::disk('public')->assertMissing($photoPath);
        Storage::disk('public')->assertMissing(str_replace('.webp', '-thumb.webp', $photoPath));

        $this->assertDatabaseHas('crm_equipment_items', [
            'id' => $itemId,
            'show_day_price' => false,
            'rental_mode' => 'day_only',
            'active' => false,
        ]);
    }

    public function test_day_only_equipment_rejects_half_day_rental(): void
    {
        [$account, , , $item] = $this->createCrmUser(['equipment_rentals.create']);
        $item->forceFill(['rental_mode' => 'day_only'])->save();

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'date' => '2026-08-07',
                'slot' => 'morning',
                'title' => 'Demi journee refusee',
            ])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Ce materiel se loue uniquement a la journee');

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'date' => '2026-08-07',
                'slot' => 'full_day',
                'periodType' => 'day',
                'title' => 'Journee acceptee',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('equipmentRental.slot', 'full_day');
    }

    public function test_day_only_equipment_rejects_custom_partial_day_times(): void
    {
        [$account, , , $item] = $this->createCrmUser(['equipment_rentals.create']);
        $item->forceFill(['rental_mode' => 'day_only'])->save();

        $this->actingAs($account)
            ->postJson('/api/equipment-rentals?action=create_rental', [
                'equipmentItemId' => $item->id,
                'periodType' => 'day',
                'slot' => 'full_day',
                'startAt' => '2026-08-08T07:30',
                'endAt' => '2026-08-08T12:00',
                'title' => 'Fausse journee',
            ])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Ce materiel se loue uniquement a la journee');
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: User, 1: CrmUser, 2: CrmSite, 3: CrmEquipmentItem}
     */
    private function createCrmUser(array $permissions): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'HUB Equipment User '.$account->id,
            'role' => 'user',
            'active' => true,
        ]);

        $site = CrmSite::query()->create([
            'name' => 'Palissy Test',
            'slug' => 'palissy-test',
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);

        $category = CrmEquipmentCategory::query()->create([
            'name' => 'Poncage Test',
            'slug' => 'poncage-test',
            'active' => true,
            'sort_order' => 10,
        ]);

        $item = CrmEquipmentItem::query()->create([
            'site_id' => $site->id,
            'category_id' => $category->id,
            'name' => 'Ponceuse Test',
            'inventory_code' => 'PON-TEST',
            'description' => 'Materiel test',
            'color' => '#95002e',
            'half_day_price' => 45,
            'day_price' => 80,
            'deposit_amount' => 300,
            'active' => true,
            'sort_order' => 10,
        ]);

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'locations-materiel'],
            [
                'name' => 'Location materiel',
                'description' => 'Planning et locations du materiel interne',
                'route_path' => '/locations-materiel',
                'active' => true,
                'sort_order' => 15,
            ],
        );

        $permissionIds = [];

        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => 'Location materiel',
                    'sort_order' => 120 + $index,
                ],
            )->id;
        }

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);

        return [$account, $crmUser, $site, $item];
    }
}
