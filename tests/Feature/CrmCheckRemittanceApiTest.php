<?php

namespace Tests\Feature;

use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmCheckRemittanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/remise-cheques?action=health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'mysql');
    }

    public function test_guest_cannot_read_check_remittance_bootstrap(): void
    {
        $this->getJson('/api/remise-cheques?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_manage_permission_is_required_to_create_remittance(): void
    {
        [$account, , $site] = $this->createCrmUser(['check_remittances.view']);

        $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_remittance', [
                'siteId' => $site->id,
                'remittanceDate' => '2026-08-10',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : check_remittances.manage');
    }

    public function test_manager_can_create_remittance_and_add_checks(): void
    {
        [$account, , $site] = $this->createCrmUser([
            'check_remittances.view',
            'check_remittances.manage',
        ]);

        $remittanceId = $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_remittance', [
                'siteId' => $site->id,
                'remittanceDate' => '2026-08-10',
                'bankName' => 'Banque test',
                'status' => 'ready',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('remittance.siteId', $site->id)
            ->assertJsonPath('remittance.checkCount', 0)
            ->assertJsonPath('remittance.totalAmount', 0)
            ->json('remittance.id');

        $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_check', [
                'remittanceId' => $remittanceId,
                'payerName' => 'Client Martin',
                'invoiceNumber' => 'F-2026-001',
                'amount' => '123,45',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('check.payerName', 'Client Martin')
            ->assertJsonPath('check.invoiceNumber', 'F-2026-001')
            ->assertJsonPath('remittance.checkCount', 1)
            ->assertJsonPath('remittance.totalAmount', 123.45);

        $this->assertDatabaseHas('crm_check_remittances', [
            'id' => $remittanceId,
            'site_id' => $site->id,
            'check_count' => 1,
            'total_amount' => 123.45,
        ]);

        $this->actingAs($account)
            ->getJson('/api/remise-cheques?action=bootstrap&limit=10&year=2026&month=8&siteId='.$site->id)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('summary.remittanceCount', 1)
            ->assertJsonPath('summary.checkCount', 1)
            ->assertJsonPath('summary.totalAmount', 123.45)
            ->assertJsonPath('remittances.0.id', $remittanceId)
            ->assertJsonPath('remittances.0.checks.0.invoiceNumber', 'F-2026-001');
    }

    public function test_manager_can_show_one_remittance_for_detail_page(): void
    {
        [$account, , $site] = $this->createCrmUser([
            'check_remittances.view',
            'check_remittances.manage',
        ]);

        $remittanceId = $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_remittance', [
                'siteId' => $site->id,
                'remittanceDate' => '2026-08-13',
                'reference' => 'RC-DETAIL',
            ])
            ->assertOk()
            ->json('remittance.id');

        $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_check', [
                'remittanceId' => $remittanceId,
                'payerName' => 'Client detail',
                'invoiceNumber' => 'F-DETAIL',
                'amount' => '77,10',
            ])
            ->assertOk();

        $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=show_remittance', [
                'id' => $remittanceId,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('canManage', true)
            ->assertJsonPath('remittance.id', $remittanceId)
            ->assertJsonPath('remittance.reference', 'RC-DETAIL')
            ->assertJsonPath('remittance.checks.0.invoiceNumber', 'F-DETAIL');
    }

    public function test_check_requires_name_and_accepts_photo_payload(): void
    {
        [$account, , $site] = $this->createCrmUser([
            'check_remittances.view',
            'check_remittances.manage',
        ]);

        $remittanceId = $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_remittance', [
                'siteId' => $site->id,
                'remittanceDate' => '2026-08-12',
            ])
            ->assertOk()
            ->json('remittance.id');

        $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_check', [
                'remittanceId' => $remittanceId,
                'invoiceNumber' => 'F-MISSING-NAME',
                'amount' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Nom requis');

        $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_check', [
                'remittanceId' => $remittanceId,
                'payerName' => 'Client Photo',
                'invoiceNumber' => 'F-PHOTO',
                'amount' => '88,20',
                'photoDataUrl' => $this->crmPngDataUrl(),
                'photoName' => 'cheque.png',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('check.payerName', 'Client Photo')
            ->assertJsonPath('check.invoiceNumber', 'F-PHOTO')
            ->assertJsonPath('check.amount', 88.20);
    }

    public function test_server_ocr_action_falls_back_when_engine_is_missing(): void
    {
        config(['crm.check_ocr.python' => 'missing-python-for-test']);

        [$account, , $site] = $this->createCrmUser([
            'check_remittances.view',
            'check_remittances.manage',
        ]);

        $remittanceId = $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=save_remittance', [
                'siteId' => $site->id,
                'remittanceDate' => '2026-08-12',
            ])
            ->assertOk()
            ->json('remittance.id');

        $this->actingAs($account)
            ->postJson('/api/remise-cheques?action=detect_check_ocr', [
                'siteId' => $site->id,
                'remittanceId' => $remittanceId,
                'photoDataUrl' => $this->crmPngDataUrl(),
                'knownPayerNames' => ['Client Photo'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('engineAvailable', false);
    }

    public function test_view_only_user_cannot_add_check_to_existing_remittance(): void
    {
        [$managerAccount, , $site] = $this->createCrmUser([
            'check_remittances.view',
            'check_remittances.manage',
        ]);
        [$viewerAccount] = $this->createCrmUser(['check_remittances.view'], $site);

        $remittanceId = $this->actingAs($managerAccount)
            ->postJson('/api/remise-cheques?action=save_remittance', [
                'siteId' => $site->id,
                'remittanceDate' => '2026-08-11',
            ])
            ->assertOk()
            ->json('remittance.id');

        $this->actingAs($viewerAccount)
            ->postJson('/api/remise-cheques?action=save_check', [
                'remittanceId' => $remittanceId,
                'payerName' => 'Client bloque',
                'invoiceNumber' => 'F-LOCK',
                'amount' => 50,
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : check_remittances.manage');
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: User, 1: CrmUser, 2: CrmSite}
     */
    private function createCrmUser(array $permissions, ?CrmSite $site = null): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'HUB Check User '.$account->id,
            'role' => in_array('check_remittances.manage', $permissions, true) ? 'responsable' : 'user',
            'active' => true,
        ]);

        $site ??= CrmSite::query()->create([
            'name' => 'Palissy Test',
            'slug' => 'palissy-test-'.$account->id,
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'remise-cheques'],
            [
                'name' => 'Remise de chèques',
                'description' => 'Remises de chèques',
                'route_path' => '/remise-cheques',
                'active' => true,
                'sort_order' => 27,
            ],
        );

        $permissionIds = [];
        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => 'Remise de chèques',
                    'sort_order' => 149 + $index,
                ],
            )->id;
        }

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);

        return [$account, $crmUser, $site];
    }
}
