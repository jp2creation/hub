<?php

namespace Tests\Feature;

use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSalesCommission;
use App\Models\CrmSalesInvoice;
use App\Models\CrmSalesObjective;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmSalesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/pilotage-commercial?action=health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'mysql');
    }

    public function test_guest_cannot_read_sales_bootstrap(): void
    {
        $this->getJson('/api/pilotage-commercial?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_authorized_user_can_sync_demo_read_dashboard_manage_objectives_and_pay_commissions(): void
    {
        [$account, $crmUser, $site] = $this->createCrmUser([
            'sales.view',
            'sales.sync',
            'sales.manage',
            'sales.commissions',
        ]);

        $this->actingAs($account)
            ->postJson('/api/pilotage-commercial?action=sync_demo', [
                'siteId' => $site->id,
                'month' => '2026-07',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('synced', 6);

        $this->assertSame(6, CrmSalesInvoice::query()->where('site_id', $site->id)->count());
        $this->assertSame(2, CrmSalesCommission::query()->where('site_id', $site->id)->count());

        $this->actingAs($account)
            ->getJson('/api/pilotage-commercial?action=bootstrap&month=2026-07&siteId='.$site->id)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'demo')
            ->assertJsonPath('selectedSiteId', $site->id)
            ->assertJsonPath('summary.invoiceCount', 6)
            ->assertJsonPath('summary.paidCount', 2)
            ->assertJsonPath('summary.pendingCount', 2)
            ->assertJsonPath('summary.overdueCount', 2)
            ->assertJsonPath('user.canSync', true)
            ->assertJsonPath('user.canManage', true)
            ->assertJsonPath('user.canManageCommissions', true);

        $this->actingAs($account)
            ->postJson('/api/pilotage-commercial?action=save_objective', [
                'siteId' => $site->id,
                'representativeId' => $crmUser->id,
                'month' => '2026-07',
                'targetRevenue' => 24000,
                'targetMargin' => 8000,
                'targetVisits' => 30,
                'notes' => 'Objectif commercial juillet.',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('objective.representativeUserId', $crmUser->id)
            ->assertJsonPath('objective.targetRevenue', 24000)
            ->assertJsonPath('objective.targetMargin', 8000)
            ->assertJsonPath('objective.targetVisits', 30);

        $this->assertDatabaseHas('crm_sales_objectives', [
            'site_id' => $site->id,
            'representative_user_id' => $crmUser->id,
            'period_start' => '2026-07-01 00:00:00',
        ]);

        $commission = CrmSalesCommission::query()->where('site_id', $site->id)->firstOrFail();

        $this->actingAs($account)
            ->postJson('/api/pilotage-commercial?action=mark_commission_paid', [
                'id' => $commission->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('commission.status', CrmSalesCommission::STATUS_PAID);

        $this->assertDatabaseHas('crm_sales_commissions', [
            'id' => $commission->id,
            'status' => CrmSalesCommission::STATUS_PAID,
        ]);
    }

    public function test_user_without_sync_permission_cannot_sync_demo_invoices(): void
    {
        [$account, , $site] = $this->createCrmUser(['sales.view']);

        $this->actingAs($account)
            ->postJson('/api/pilotage-commercial?action=sync_demo', [
                'siteId' => $site->id,
                'month' => '2026-07',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : sales.sync');

        $this->assertSame(0, CrmSalesInvoice::query()->count());
        $this->assertSame(0, CrmSalesObjective::query()->count());
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
            'name' => 'Commercial Test '.$account->id,
            'role' => 'user',
            'active' => true,
        ]);

        $site = CrmSite::query()->create([
            'name' => 'Palissy Test',
            'slug' => 'palissy-test-'.$account->id,
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'pilotage-commercial'],
            [
                'name' => 'Pilotage commercial',
                'description' => 'Objectifs, chiffre d affaires, factures et commissions commerciales',
                'route_path' => '/pilotage-commercial',
                'active' => true,
                'sort_order' => 19,
            ],
        );

        $permissionIds = [];
        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => 'Pilotage commercial',
                    'sort_order' => 163 + $index,
                ],
            )->id;
        }

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);

        return [$account, $crmUser, $site];
    }
}
