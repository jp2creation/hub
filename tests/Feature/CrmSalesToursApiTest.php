<?php

namespace Tests\Feature;

use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmSalesToursApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/tournees-representants?action=health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'mysql');
    }

    public function test_guest_cannot_read_sales_tours_bootstrap(): void
    {
        $this->getJson('/api/tournees-representants?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_authorized_user_can_manage_tour_visit_and_report(): void
    {
        [$account, $crmUser, $site] = $this->createCrmUser([
            'sales_tours.view',
            'sales_tours.create',
            'sales_tours.report',
        ]);

        $tour = $this->actingAs($account)
            ->postJson('/api/tournees-representants?action=save_tour', [
                'siteId' => $site->id,
                'representativeUserId' => $crmUser->id,
                'tourDate' => '2026-07-22',
                'title' => 'Rapport test Palissy',
                'objective' => 'Visiter les clients actifs du secteur.',
                'status' => 'planned',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tour.siteId', $site->id)
            ->assertJsonPath('tour.representativeUserId', $crmUser->id)
            ->assertJsonPath('tour.title', 'Rapport test Palissy')
            ->json('tour');

        $this->actingAs($account)
            ->postJson('/api/tournees-representants?action=save_visit', [
                'tourId' => $tour['id'],
                'customerName' => 'Client Test',
                'customerReference' => 'CLI-001',
                'city' => 'Pau',
                'plannedAt' => '2026-07-22 09:30:00',
                'durationMinutes' => 45,
                'visitType' => 'client',
                'priority' => 'high',
                'status' => 'done',
                'objective' => 'Presenter la gamme.',
                'result' => 'Client interesse.',
                'nextAction' => 'Envoyer devis.',
                'nextActionDate' => '2026-07-24',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('visit.customerName', 'Client Test')
            ->assertJsonPath('visit.status', 'done')
            ->assertJsonPath('tour.visits.0.customerName', 'Client Test');

        $this->actingAs($account)
            ->postJson('/api/tournees-representants?action=save_report', [
                'tourId' => $tour['id'],
                'status' => 'completed',
                'reportSummary' => 'Rapport termine, bon retour client.',
                'reportNextActions' => 'Relancer le devis vendredi.',
                'reportMood' => 'good',
                'kilometers' => 42.5,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tour.status', 'completed')
            ->assertJsonPath('tour.reportSummary', 'Rapport termine, bon retour client.')
            ->assertJsonPath('tour.kilometers', 42.5);

        $this->actingAs($account)
            ->getJson('/api/tournees-representants?action=bootstrap&month=2026-07&siteId='.$site->id)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('selectedSiteId', $site->id)
            ->assertJsonPath('summary.tours', 1)
            ->assertJsonPath('summary.visits', 1)
            ->assertJsonPath('summary.doneVisits', 1);
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
            ['slug' => 'tournees-representants'],
            [
                'name' => 'Rapport de visite',
                'description' => 'Planning, visites clients et rapports de visite',
                'route_path' => '/rapport-visite',
                'active' => true,
                'sort_order' => 17,
            ],
        );

        $permissionIds = [];
        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => 'Rapport de visite',
                    'sort_order' => 158 + $index,
                ],
            )->id;
        }

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);

        return [$account, $crmUser, $site];
    }
}
