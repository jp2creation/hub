<?php

namespace Tests\Feature;

use App\Models\CrmLeaveBalance;
use App\Models\CrmLeaveEmployee;
use App\Models\CrmLeaveEntry;
use App\Models\CrmLeaveTransaction;
use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmLeaveApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_leave_bootstrap(): void
    {
        $this->getJson('/api/conges?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_authorized_user_can_read_leave_bootstrap(): void
    {
        [$account, $crmUser, $site] = $this->createCrmUser(canManage: true);

        $this->actingAs($account)
            ->getJson('/api/conges?action=bootstrap&siteId='.$site->id)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.canManage', true)
            ->assertJsonPath('user.siteIds.0', $site->id)
            ->assertJsonPath('selectedSiteId', $site->id)
            ->assertJsonPath('employees.0.crmUserId', $crmUser->id)
            ->assertJsonPath('employees.0.name', $crmUser->name)
            ->assertJsonPath('types.0.label', 'Congé')
            ->assertJsonPath('periods.2.label', 'Après-midi');
    }

    public function test_leave_bootstrap_uses_users_linked_to_selected_site(): void
    {
        [$account, $crmUser, $site] = $this->createCrmUser(canManage: true);
        $otherSite = $this->createSite('Autre Site');
        $otherUser = CrmUser::query()->create([
            'name' => 'Autre utilisateur',
            'role' => 'user',
            'active' => true,
        ]);
        $blockedUser = CrmUser::query()->create([
            'name' => 'Utilisateur sans acces',
            'role' => 'blocked',
            'active' => true,
        ]);
        $otherUser->sites()->syncWithoutDetaching([$otherSite->id => ['is_default' => true]]);
        $blockedUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => false]]);

        $this->actingAs($account)
            ->getJson('/api/conges?action=bootstrap&siteId='.$site->id)
            ->assertOk()
            ->assertJsonFragment(['crmUserId' => $crmUser->id])
            ->assertJsonFragment(['crmUserId' => $blockedUser->id])
            ->assertJsonMissing(['crmUserId' => $otherUser->id]);
    }

    public function test_leave_employee_actions_are_not_available_from_module(): void
    {
        [$account] = $this->createCrmUser(canManage: true);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_employee', [
                'name' => 'Employe interdit',
            ])
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Action inconnue');
    }

    public function test_authorized_user_can_download_leave_pdf_for_selected_members(): void
    {
        [$account, , $site, $employee] = $this->createCrmUser(canManage: false);

        CrmLeaveEntry::query()->create([
            'employee_id' => $employee->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'type' => 'conge',
            'period' => 'full',
            'duration_days' => 3,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($account)
            ->postJson('/api/conges?action=export_pdf&siteId='.$site->id, [
                'fromDate' => '2026-08-01',
                'toDate' => '2026-08-31',
                'employeeIds' => [$employee->id],
            ])
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="conges-20260801-20260831.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_leave_pdf_export_rejects_unauthorized_employee_ids(): void
    {
        [$account, , $site] = $this->createCrmUser(canManage: false);
        $otherSite = $this->createSite('Autre Site');
        $otherCrmUser = CrmUser::query()->create([
            'name' => 'Autre export',
            'role' => 'user',
            'active' => true,
        ]);
        $otherCrmUser->sites()->syncWithoutDetaching([$otherSite->id => ['is_default' => true]]);
        $otherEmployee = CrmLeaveEmployee::query()->create([
            'crm_user_id' => $otherCrmUser->id,
            'name' => 'Autre export',
            'slug' => 'autre-export',
            'color' => '#16a34a',
            'active' => true,
            'sort_order' => 20,
        ]);

        $this->actingAs($account)
            ->postJson('/api/conges?action=export_pdf&siteId='.$site->id, [
                'fromDate' => '2026-08-01',
                'toDate' => '2026-08-31',
                'employeeIds' => [$otherEmployee->id],
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur non autorise pour cet export');
    }

    public function test_leave_export_options_can_include_other_sites_for_manager(): void
    {
        [$account, , $site] = $this->createCrmUser(canManage: true);
        $otherSite = $this->createSite('Libourne Export');
        $otherCrmUser = CrmUser::query()->create([
            'name' => 'Membre Libourne',
            'role' => 'user',
            'active' => true,
        ]);
        $otherCrmUser->sites()->syncWithoutDetaching([$otherSite->id => ['is_default' => true]]);

        $this->actingAs($account)
            ->postJson('/api/conges?action=export_options&siteId='.$site->id, [
                'includeOtherSites' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('canIncludeOtherSites', true)
            ->assertJsonFragment(['name' => 'Membre Libourne']);
    }

    public function test_manage_permission_is_required_to_create_leave(): void
    {
        [$account, , , $employee] = $this->createCrmUser(canManage: false);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                'employeeId' => $employee->id,
                'startDate' => '2026-08-01',
                'endDate' => '2026-08-02',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : conges.manage');
    }

    public function test_overlapping_leave_is_rejected(): void
    {
        [$account, , , $employee] = $this->createCrmUser(canManage: true);

        $payload = [
            'employeeId' => $employee->id,
            'startDate' => '2026-08-10',
            'endDate' => '2026-08-12',
            'type' => 'conge',
            'period' => 'full',
            'status' => 'approved',
        ];

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                ...$payload,
                'startDate' => '2026-08-11',
                'endDate' => '2026-08-13',
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Un conge existe deja sur cette periode')
            ->assertJsonPath('code', 'leave_overlap');
    }

    public function test_leave_creation_calculates_duration_and_records_balance_audit(): void
    {
        [$account, $crmUser, , $employee] = $this->createCrmUser(canManage: true);

        $response = $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                'employeeId' => $employee->id,
                'startDate' => '2026-08-10',
                'endDate' => '2026-08-12',
                'type' => 'conge',
                'period' => 'full',
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $leaveId = (int) $response->json('leave.id');
        $this->assertSame(3.0, (float) $response->json('leave.durationDays'));

        $balance = CrmLeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'conge')
            ->where('year', 2026)
            ->firstOrFail();

        $this->assertSame(3.0, $balance->used_days);
        $this->assertSame(22.0, $balance->remaining_days);

        $transaction = CrmLeaveTransaction::query()
            ->where('entry_id', $leaveId)
            ->firstOrFail();

        $this->assertSame(-3.0, $transaction->amount_days);
        $this->assertSame(22.0, $transaction->balance_after);
        $this->assertSame($crmUser->id, (int) $transaction->created_by);

        $this->assertDatabaseHas('crm_leave_status_histories', [
            'entry_id' => $leaveId,
            'employee_id' => $employee->id,
            'from_status' => null,
            'to_status' => 'approved',
            'changed_by' => $crmUser->id,
        ]);
    }

    public function test_half_day_leave_must_stay_on_one_day(): void
    {
        [$account, , , $employee] = $this->createCrmUser(canManage: true);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                'employeeId' => $employee->id,
                'startDate' => '2026-08-10',
                'endDate' => '2026-08-11',
                'type' => 'conge',
                'period' => 'morning',
                'status' => 'approved',
            ])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'invalid_half_day_period');
    }

    public function test_balance_enforcement_can_reject_insufficient_balance(): void
    {
        config(['crm.leaves.enforce_balances' => true]);

        [$account, , , $employee] = $this->createCrmUser(canManage: true);

        CrmLeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'type' => 'conge',
            'year' => 2026,
            'entitled_days' => 0,
            'carried_over_days' => 0,
            'used_days' => 0,
            'pending_days' => 0,
            'remaining_days' => 0,
        ]);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                'employeeId' => $employee->id,
                'startDate' => '2026-08-10',
                'endDate' => '2026-08-10',
                'type' => 'conge',
                'period' => 'morning',
                'status' => 'approved',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'insufficient_balance');
    }

    public function test_non_admin_cannot_modify_or_delete_approved_leave(): void
    {
        [$account, , , $employee] = $this->createCrmUser(canManage: true, role: 'responsable');

        $leave = CrmLeaveEntry::query()->create([
            'employee_id' => $employee->id,
            'start_date' => '2026-08-20',
            'end_date' => '2026-08-20',
            'type' => 'conge',
            'period' => 'full',
            'duration_days' => 1,
            'status' => 'approved',
        ]);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                'id' => $leave->id,
                'employeeId' => $employee->id,
                'startDate' => '2026-08-20',
                'endDate' => '2026-08-20',
                'type' => 'conge',
                'period' => 'full',
                'status' => 'refused',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'approved_leave_locked');

        $this->actingAs($account)
            ->postJson('/api/conges?action=delete_leave', ['id' => $leave->id])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'approved_leave_locked');
    }

    public function test_opposite_half_day_leaves_can_share_same_day(): void
    {
        [$account, , , $employee] = $this->createCrmUser(canManage: true);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                'employeeId' => $employee->id,
                'startDate' => '2026-08-14',
                'endDate' => '2026-08-14',
                'type' => 'conge',
                'period' => 'morning',
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                'employeeId' => $employee->id,
                'startDate' => '2026-08-14',
                'endDate' => '2026-08-14',
                'type' => 'conge',
                'period' => 'afternoon',
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_same_half_day_leave_is_rejected(): void
    {
        [$account, , , $employee] = $this->createCrmUser(canManage: true);

        $payload = [
            'employeeId' => $employee->id,
            'startDate' => '2026-08-15',
            'endDate' => '2026-08-15',
            'type' => 'conge',
            'period' => 'morning',
            'status' => 'approved',
        ];

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Un conge existe deja sur cette periode');
    }

    public function test_leave_creation_and_deletion_are_written_to_activity_log(): void
    {
        [$account, $crmUser, , $employee] = $this->createCrmUser(canManage: true);

        $leaveId = $this->actingAs($account)
            ->postJson('/api/conges?action=save_leave', [
                'employeeId' => $employee->id,
                'startDate' => '2026-08-18',
                'endDate' => '2026-08-18',
                'type' => 'conge',
                'period' => 'full',
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('leave.id');

        $this->assertDatabaseHas('crm_logs', [
            'user_id' => $crmUser->id,
            'action' => 'creation conge',
            'details' => "Conge #{$leaveId} - {$employee->name} - 2026-08-18 au 2026-08-18",
        ]);

        $this->actingAs($account)
            ->postJson('/api/conges?action=delete_leave', ['id' => $leaveId])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseHas('crm_logs', [
            'user_id' => $crmUser->id,
            'action' => 'suppression conge',
            'details' => "Conge #{$leaveId} - {$employee->name}",
        ]);

        $this->assertDatabaseHas('crm_leave_status_histories', [
            'entry_id' => $leaveId,
            'from_status' => 'approved',
            'to_status' => 'deleted',
            'changed_by' => $crmUser->id,
        ]);
    }

    /**
     * @return array{0: User, 1: CrmUser, 2: CrmSite, 3: CrmLeaveEmployee}
     */
    private function createCrmUser(bool $canManage, ?string $role = null): array
    {
        $account = User::factory()->create();
        $site = $this->createSite('Palissy Test');
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'HUB Test User '.$account->id,
            'role' => $role ?? ($canManage ? 'admin' : 'user'),
            'active' => true,
        ]);
        $employee = CrmLeaveEmployee::query()->create([
            'crm_user_id' => $crmUser->id,
            'name' => $crmUser->name,
            'slug' => 'crm-test-user-'.$account->id,
            'color' => '#2563eb',
            'active' => true,
            'sort_order' => 10,
        ]);

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'conges'],
            [
                'name' => 'Conges',
                'description' => 'Planning conges',
                'route_path' => '/conges',
                'active' => true,
                'sort_order' => 24,
            ],
        );

        $view = CrmPermission::query()->updateOrCreate(
            ['name' => 'conges.view'],
            ['label' => 'Voir les conges', 'group_label' => 'Conges', 'sort_order' => 185],
        );

        $manage = CrmPermission::query()->updateOrCreate(
            ['name' => 'conges.manage'],
            ['label' => 'Gerer les conges', 'group_label' => 'Conges', 'sort_order' => 186],
        );

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching(
            $canManage ? [$view->id, $manage->id] : [$view->id],
        );

        return [$account, $crmUser, $site, $employee];
    }

    private function createSite(string $name): CrmSite
    {
        return CrmSite::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);
    }
}
