<?php

namespace Tests\Feature;

use App\Models\CrmDepositRequest;
use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmDepositRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/demandes-acompte?action=health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'mysql');
    }

    public function test_guest_cannot_read_deposit_requests_bootstrap(): void
    {
        $this->getJson('/api/demandes-acompte?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_create_permission_is_required_to_create_deposit_request(): void
    {
        [$account, , $site] = $this->createCrmUser(['deposit_requests.view']);

        $this->actingAs($account)
            ->postJson('/api/demandes-acompte?action=save_request', [
                'siteId' => $site->id,
                'requestDate' => '2026-09-01',
                'requesterName' => 'Jean Test',
                'documentNumber' => 'CMD-100',
                'clientName' => 'Client Sans Droit',
                'amount' => '120,50',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : deposit_requests.create');
    }

    public function test_creator_can_create_and_edit_own_pending_request_without_setting_validation(): void
    {
        [$account, , $site] = $this->createCrmUser([
            'deposit_requests.view',
            'deposit_requests.create',
        ]);

        $requestId = $this->actingAs($account)
            ->postJson('/api/demandes-acompte?action=save_request', [
                'siteId' => $site->id,
                'requestDate' => '2026-09-01',
                'requesterName' => 'Jean Test',
                'documentNumber' => 'CMD-100',
                'clientName' => 'Client Départ',
                'amount' => '120,50',
                'status' => 'validated',
                'validatedAt' => '2026-09-02 15:30:00',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request.status', CrmDepositRequest::STATUS_PENDING)
            ->assertJsonPath('request.validatedAt', null)
            ->assertJsonPath('request.canEdit', true)
            ->assertJsonPath('request.canValidate', false)
            ->json('request.id');

        $this->actingAs($account)
            ->postJson('/api/demandes-acompte?action=save_request', [
                'id' => $requestId,
                'siteId' => $site->id,
                'requestDate' => '2026-09-03',
                'requesterName' => 'Jean Test',
                'documentNumber' => 'FAC-200',
                'clientName' => 'Client Final',
                'amount' => '140,75',
            ])
            ->assertOk()
            ->assertJsonPath('request.requestDate', '2026-09-03')
            ->assertJsonPath('request.documentNumber', 'FAC-200')
            ->assertJsonPath('request.clientName', 'Client Final')
            ->assertJsonPath('request.amount', 140.75)
            ->assertJsonPath('request.status', CrmDepositRequest::STATUS_PENDING);

        $this->actingAs($account)
            ->getJson('/api/demandes-acompte?action=bootstrap&query=Client%20Final')
            ->assertOk()
            ->assertJsonPath('requests.0.id', $requestId)
            ->assertJsonPath('requests.0.clientName', 'Client Final');

        $this->actingAs($account)
            ->postJson('/api/demandes-acompte?action=validate_request', [
                'id' => $requestId,
                'status' => 'validated',
                'validatedAt' => '2026-09-02 15:30:00',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : deposit_requests.validate');

        $this->assertDatabaseHas('crm_deposit_requests', [
            'id' => $requestId,
            'site_id' => $site->id,
            'document_number' => 'FAC-200',
            'client_name' => 'Client Final',
            'amount' => 140.75,
            'status' => CrmDepositRequest::STATUS_PENDING,
            'validated_at' => null,
        ]);
    }

    public function test_accounting_permission_can_validate_deposit_request(): void
    {
        [$creatorAccount, , $site] = $this->createCrmUser([
            'deposit_requests.view',
            'deposit_requests.create',
        ]);
        [$accountingAccount, $accountingUser] = $this->createCrmUser([
            'deposit_requests.view',
            'deposit_requests.validate',
        ], $site);

        $requestId = $this->actingAs($creatorAccount)
            ->postJson('/api/demandes-acompte?action=save_request', [
                'siteId' => $site->id,
                'requestDate' => '2026-09-01',
                'requesterName' => 'Jean Test',
                'documentNumber' => 'CMD-VALID',
                'clientName' => 'Client Validé',
                'amount' => 220,
            ])
            ->assertOk()
            ->json('request.id');

        $this->actingAs($accountingAccount)
            ->postJson('/api/demandes-acompte?action=validate_request', [
                'id' => $requestId,
                'status' => 'validated',
                'validatedAt' => '2026-09-02 15:30:00',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request.status', CrmDepositRequest::STATUS_VALIDATED)
            ->assertJsonPath('request.validatedAt', '2026-09-02 15:30:00')
            ->assertJsonPath('request.validatedByName', $accountingUser->name);

        $this->assertDatabaseHas('crm_deposit_requests', [
            'id' => $requestId,
            'status' => CrmDepositRequest::STATUS_VALIDATED,
            'validated_by' => $accountingUser->id,
        ]);
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
            'name' => 'HUB Deposit User '.$account->id,
            'role' => array_intersect(['deposit_requests.manage', 'deposit_requests.validate'], $permissions) ? 'responsable' : 'user',
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
            ['slug' => 'demandes-acompte'],
            [
                'name' => "Demande d'acompte",
                'description' => "Demandes d'acompte",
                'route_path' => '/demandes-acompte',
                'active' => true,
                'sort_order' => 26,
            ],
        );

        $permissionIds = [];
        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => "Demande d'acompte",
                    'sort_order' => 151 + $index,
                ],
            )->id;
        }

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);

        return [$account, $crmUser, $site];
    }
}
