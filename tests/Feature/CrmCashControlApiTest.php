<?php

namespace Tests\Feature;

use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmCashControlApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/controle-caisse?action=health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'mysql');
    }

    public function test_guest_cannot_read_cash_control_bootstrap(): void
    {
        $this->getJson('/api/controle-caisse?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_manage_permission_is_required_to_create_cash_day(): void
    {
        [$account] = $this->createCrmUser(['controle_caisse.view']);

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=create_day', [
                'cashDate' => '2026-08-01',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : controle_caisse.manage');
    }

    public function test_cash_day_uses_previous_counted_cash_as_opening_balance(): void
    {
        [$account, , $site] = $this->createCrmUser(['controle_caisse.view', 'controle_caisse.manage']);

        $firstDayId = $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=create_day', [
                'siteId' => $site->id,
                'cashDate' => '2026-08-01',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('day.openingBalance', 0)
            ->json('day.id');

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_day', [
                'id' => $firstDayId,
                'cashDate' => '2026-08-01',
                'openingBalance' => 100,
                'invoiceTotal' => 300,
                'cashSales' => 120,
                'cardSales' => 130,
                'checkSales' => 50,
                'transferSales' => 0,
                'countedCash' => 220,
                'bankCounted' => 180,
                'invoiceErrorsCount' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('day.status', 'ok')
            ->assertJsonPath('day.expectedCash', 220)
            ->assertJsonPath('day.cashDifference', 0)
            ->assertJsonPath('day.entryDifference', 0);

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=create_day', [
                'siteId' => $site->id,
                'cashDate' => '2026-08-02',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('day.openingBalance', 220)
            ->assertJsonPath('day.status', 'review');
    }

    public function test_cash_movement_recalculates_expected_cash(): void
    {
        [$account, , $site] = $this->createCrmUser(['controle_caisse.view', 'controle_caisse.manage']);

        $dayId = $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=create_day', [
                'siteId' => $site->id,
                'cashDate' => '2026-08-03',
            ])
            ->assertOk()
            ->json('day.id');

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_day', [
                'id' => $dayId,
                'cashDate' => '2026-08-03',
                'openingBalance' => 50,
                'invoiceTotal' => 100,
                'cashSales' => 100,
                'countedCash' => 150,
                'bankCounted' => '',
            ])
            ->assertOk()
            ->assertJsonPath('day.status', 'ok')
            ->assertJsonPath('day.expectedCash', 150);

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_movement', [
                'dayId' => $dayId,
                'type' => 'cash_out',
                'label' => 'Achat fournitures',
                'amount' => '12,50',
                'occurredOn' => '2026-08-03',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('movement.label', 'Achat fournitures')
            ->assertJsonPath('day.cashOutTotal', 12.5)
            ->assertJsonPath('day.expectedCash', 137.5)
            ->assertJsonPath('day.cashDifference', 12.5)
            ->assertJsonPath('day.status', 'anomaly');
    }

    public function test_cash_receipt_updates_day_totals(): void
    {
        [$account, , $site] = $this->createCrmUser(['controle_caisse.view', 'controle_caisse.manage']);

        $dayId = $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=create_day', [
                'siteId' => $site->id,
                'cashDate' => '2026-08-04',
            ])
            ->assertOk()
            ->json('day.id');

        $receiptId = $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_receipt', [
                'dayId' => $dayId,
                'invoiceNumber' => 'F-2026-001',
                'customerName' => 'Client Dupont',
                'occurredOn' => '2026-08-04',
                'invoiceTotal' => 180,
                'cashAmount' => 80,
                'cardAmount' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('receipt.invoiceNumber', 'F-2026-001')
            ->assertJsonPath('day.receiptCount', 1)
            ->assertJsonPath('day.invoiceTotal', 180)
            ->assertJsonPath('day.cashSales', 80)
            ->assertJsonPath('day.cardSales', 100)
            ->assertJsonPath('day.paymentsTotal', 180)
            ->assertJsonPath('day.expectedCash', 80)
            ->assertJsonPath('day.bankExpected', 100)
            ->json('receipt.id');

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=delete_receipt', [
                'id' => $receiptId,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('day.receiptCount', 0)
            ->assertJsonPath('day.invoiceTotal', 0)
            ->assertJsonPath('day.cashSales', 0)
            ->assertJsonPath('day.paymentsTotal', 0);
    }

    public function test_cash_count_uses_denominations_and_bank_deposit(): void
    {
        [$account, , $site] = $this->createCrmUser(['controle_caisse.view', 'controle_caisse.manage']);

        $dayId = $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=create_day', [
                'siteId' => $site->id,
                'cashDate' => '2026-08-05',
            ])
            ->assertOk()
            ->json('day.id');

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_day', [
                'id' => $dayId,
                'cashDate' => '2026-08-05',
                'openingBalance' => 50,
                'invoiceErrorsCount' => 0,
            ])
            ->assertOk();

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_receipt', [
                'dayId' => $dayId,
                'invoiceNumber' => 'F-2026-002',
                'customerName' => 'Client comptoir',
                'occurredOn' => '2026-08-05',
                'invoiceTotal' => 100,
                'cashAmount' => 100,
            ])
            ->assertOk();

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_movement', [
                'dayId' => $dayId,
                'type' => 'cash_out',
                'label' => 'Achat fournitures',
                'amount' => 20,
                'occurredOn' => '2026-08-05',
            ])
            ->assertOk();

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_cash_count', [
                'dayId' => $dayId,
                'checkCounted' => 0,
                'transferCounted' => 0,
                'cardCounted' => 0,
                'lines' => [
                    [
                        'denomination' => 10,
                        'currentQuantity' => 13,
                        'depositQuantity' => 2,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('day.cashGrossTotal', 130)
            ->assertJsonPath('day.cashDepositTotal', 20)
            ->assertJsonPath('day.countedCash', 110)
            ->assertJsonPath('day.expectedCash', 110)
            ->assertJsonPath('day.cashDifference', 0)
            ->assertJsonPath('day.status', 'ok');
    }

    public function test_cash_day_can_be_deleted_with_related_lines(): void
    {
        [$account, , $site] = $this->createCrmUser(['controle_caisse.view', 'controle_caisse.manage']);

        $dayId = $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=create_day', [
                'siteId' => $site->id,
                'cashDate' => '2026-08-06',
            ])
            ->assertOk()
            ->json('day.id');

        $receiptId = $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_receipt', [
                'dayId' => $dayId,
                'invoiceNumber' => 'F-2026-DELETE',
                'customerName' => 'Client suppression',
                'occurredOn' => '2026-08-06',
                'invoiceTotal' => 80,
                'cashAmount' => 80,
            ])
            ->assertOk()
            ->json('receipt.id');

        $movementId = $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_movement', [
                'dayId' => $dayId,
                'type' => 'cash_out',
                'label' => 'Sortie suppression',
                'amount' => 12,
                'occurredOn' => '2026-08-06',
            ])
            ->assertOk()
            ->json('movement.id');

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=save_cash_count', [
                'dayId' => $dayId,
                'lines' => [
                    [
                        'denomination' => 20,
                        'currentQuantity' => 4,
                    ],
                ],
            ])
            ->assertOk();

        $this->actingAs($account)
            ->postJson('/api/controle-caisse?action=delete_day', [
                'id' => $dayId,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('crm_cash_register_days', ['id' => $dayId]);
        $this->assertDatabaseMissing('crm_cash_receipts', ['id' => $receiptId]);
        $this->assertDatabaseMissing('crm_cash_movements', ['id' => $movementId]);
        $this->assertDatabaseMissing('crm_cash_count_lines', ['cash_register_day_id' => $dayId]);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: User, 1: CrmUser, 2: CrmSite}
     */
    private function createCrmUser(array $permissions): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'HUB Cash User '.$account->id,
            'role' => in_array('controle_caisse.manage', $permissions, true) ? 'responsable' : 'user',
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

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'controle-caisse'],
            [
                'name' => 'Controle caisse',
                'description' => 'Controle journalier de caisse',
                'route_path' => '/controle-caisse',
                'active' => true,
                'sort_order' => 25,
            ],
        );

        $permissionIds = [];

        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => 'Controle caisse',
                    'sort_order' => 147 + $index,
                ],
            )->id;
        }

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);

        return [$account, $crmUser, $site];
    }
}
