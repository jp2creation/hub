<?php

namespace Tests\Feature;

use App\Models\CrmCashReceipt;
use App\Models\CrmCashRegisterDay;
use App\Models\CrmCheckRemittance;
use App\Models\CrmEquipmentItem;
use App\Models\CrmEquipmentRental;
use App\Models\CrmLeaveEmployee;
use App\Models\CrmLeaveEntry;
use App\Models\CrmModule;
use App\Models\CrmNotificationLog;
use App\Models\CrmReservation;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Models\DashboardMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CrmDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_cannot_read_dashboard(): void
    {
        $this->getJson('/api/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_dashboard_returns_operational_widgets_for_authorized_user(): void
    {
        Carbon::setTestNow('2026-08-05 10:00:00');
        Cache::flush();

        $site = CrmSite::query()->create([
            'name' => 'Palissy',
            'slug' => 'palissy',
            'active' => true,
        ]);

        foreach ([
            ['Réservations véhicules', 'reservations', '/reservations'],
            ['Location matériel', 'locations-materiel', '/locations-materiel'],
            ['Congés', 'conges', '/conges'],
            ['Contrôle caisse', 'controle-caisse', '/controle-caisse'],
            ['Remise de chèques', 'remise-cheques', '/remise-cheques'],
        ] as [$name, $slug, $routePath]) {
            CrmModule::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $name,
                    'route_path' => $routePath,
                    'active' => true,
                    'sort_order' => 10,
                ],
            );
        }

        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Jean-Philippe',
            'email' => $account->email,
            'role' => 'admin',
            'active' => true,
        ]);
        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);

        $vehicle = CrmVehicle::query()->create([
            'site_id' => $site->id,
            'name' => 'Sprinter Test',
            'description' => '',
            'active' => true,
        ]);

        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Chantier du jour',
            'start_at' => '2026-08-05 09:00:00',
            'end_at' => '2026-08-05 11:00:00',
        ]);

        $busyItem = CrmEquipmentItem::query()->create([
            'site_id' => $site->id,
            'name' => 'Ponceuse occupée',
            'active' => true,
        ]);
        CrmEquipmentItem::query()->create([
            'site_id' => $site->id,
            'name' => 'Monobrosse libre',
            'active' => true,
        ]);
        CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $busyItem->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'day',
            'slot' => 'full_day',
            'status' => CrmEquipmentRental::STATUS_RESERVED,
            'title' => 'Location active',
            'start_at' => '2026-08-05 08:00:00',
            'end_at' => '2026-08-05 17:00:00',
        ]);

        $cashDay = CrmCashRegisterDay::query()->create([
            'site_id' => $site->id,
            'cash_date' => '2026-08-05',
            'status' => CrmCashRegisterDay::STATUS_OK,
        ]);
        CrmCashReceipt::query()->create([
            'cash_register_day_id' => $cashDay->id,
            'invoice_number' => 'F-001',
            'customer_name' => 'Client Test',
            'occurred_on' => '2026-08-05',
            'invoice_total' => 250.50,
            'cash_amount' => 250.50,
        ]);

        $employee = CrmLeaveEmployee::query()->firstOrCreate(
            ['crm_user_id' => $crmUser->id],
            [
                'name' => $crmUser->name,
                'slug' => 'jean-philippe-dashboard',
                'active' => true,
            ],
        );
        CrmLeaveEntry::query()->create([
            'employee_id' => $employee->id,
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'status' => 'pending',
            'type' => 'conge',
            'period' => 'full',
        ]);

        CrmCheckRemittance::query()->create([
            'site_id' => $site->id,
            'remittance_date' => '2026-08-05',
            'reference' => 'RC-TEST',
            'status' => CrmCheckRemittance::STATUS_DRAFT,
        ]);
        CrmNotificationLog::query()->create([
            'channel' => 'mail',
            'recipient' => 'test@example.com',
            'subject' => 'Test',
            'template_key' => 'test',
            'locale' => 'fr',
            'status' => CrmNotificationLog::STATUS_FAILED,
            'error_message' => 'Erreur test',
        ]);

        $this->actingAs($account)
            ->getJson('/api/dashboard?siteId='.$site->id)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('selectedSiteId', $site->id)
            ->assertJsonPath('stats.reservationsToday', 1)
            ->assertJsonPath('stats.monthlyRevenue', 250.5)
            ->assertJsonPath('stats.pendingLeaves', 1)
            ->assertJsonPath('stats.equipmentAvailable', 1)
            ->assertJsonPath('stats.equipmentTotal', 2)
            ->assertJsonPath('latestReservations.0.title', 'Chantier du jour')
            ->assertJsonPath('currentLeaves.0.name', 'Jean-Philippe')
            ->assertJsonPath('currentLeaves.0.type', 'Congé')
            ->assertJsonPath('currentLeaves.0.period', 'Journée')
            ->assertJsonPath('currentLeaves.0.status', 'En attente')
            ->assertJsonPath('notifications.draftCheckRemittances', 1)
            ->assertJsonPath('notifications.failedNotifications', 1);
    }

    public function test_dashboard_uses_pre_aggregated_metrics_when_available(): void
    {
        Carbon::setTestNow('2026-08-05 10:00:00');
        Cache::flush();

        $site = CrmSite::query()->create([
            'name' => 'Palissy',
            'slug' => 'palissy',
            'active' => true,
        ]);

        foreach ([
            ['Réservations véhicules', 'reservations', '/reservations'],
            ['Location matériel', 'locations-materiel', '/locations-materiel'],
            ['Congés', 'conges', '/conges'],
            ['Contrôle caisse', 'controle-caisse', '/controle-caisse'],
        ] as [$name, $slug, $routePath]) {
            CrmModule::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $name,
                    'route_path' => $routePath,
                    'active' => true,
                    'sort_order' => 10,
                ],
            );
        }

        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Jean-Philippe',
            'email' => $account->email,
            'role' => 'admin',
            'active' => true,
        ]);
        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);

        DashboardMetric::query()->create([
            'site_id' => $site->id,
            'metric_date' => '2026-08-05',
            'reservations_today' => 12,
            'monthly_revenue' => 9876.50,
            'pending_leaves' => 4,
            'equipment_available' => 7,
            'equipment_total' => 9,
            'reservation_trend' => [
                ['date' => '2026-07-30', 'label' => '30/07', 'total' => 1],
                ['date' => '2026-07-31', 'label' => '31/07', 'total' => 2],
                ['date' => '2026-08-01', 'label' => '01/08', 'total' => 3],
                ['date' => '2026-08-02', 'label' => '02/08', 'total' => 4],
                ['date' => '2026-08-03', 'label' => '03/08', 'total' => 5],
                ['date' => '2026-08-04', 'label' => '04/08', 'total' => 6],
                ['date' => '2026-08-05', 'label' => '05/08', 'total' => 7],
            ],
            'generated_at' => now(),
        ]);

        $this->actingAs($account)
            ->getJson('/api/dashboard?siteId='.$site->id)
            ->assertOk()
            ->assertJsonPath('stats.reservationsToday', 12)
            ->assertJsonPath('stats.monthlyRevenue', 9876.5)
            ->assertJsonPath('stats.pendingLeaves', 4)
            ->assertJsonPath('stats.equipmentAvailable', 7)
            ->assertJsonPath('stats.equipmentTotal', 9)
            ->assertJsonPath('reservationTrend.6.total', 7);
    }

    public function test_refresh_dashboard_metrics_command_populates_metric_table(): void
    {
        Carbon::setTestNow('2026-08-05 10:00:00');

        $site = CrmSite::query()->create([
            'name' => 'Palissy',
            'slug' => 'palissy',
            'active' => true,
            'morning_start' => '00:00:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '12:00:00',
            'afternoon_end' => '23:59:00',
        ]);
        $crmUser = CrmUser::query()->create([
            'name' => 'Collaborateur',
            'role' => 'user',
            'active' => true,
        ]);
        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $vehicle = CrmVehicle::query()->create([
            'site_id' => $site->id,
            'name' => 'Sprinter',
            'description' => '',
            'active' => true,
        ]);

        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Reservation test',
            'start_at' => '2026-08-05 09:00:00',
            'end_at' => '2026-08-05 10:00:00',
        ]);

        $cashDay = CrmCashRegisterDay::query()->create([
            'site_id' => $site->id,
            'cash_date' => '2026-08-05',
            'status' => CrmCashRegisterDay::STATUS_OK,
        ]);
        CrmCashReceipt::query()->create([
            'cash_register_day_id' => $cashDay->id,
            'invoice_number' => 'F-002',
            'customer_name' => 'Client test',
            'occurred_on' => '2026-08-05',
            'invoice_total' => 123.45,
        ]);

        $employee = CrmLeaveEmployee::query()->create([
            'crm_user_id' => $crmUser->id,
            'name' => 'Collaborateur',
            'slug' => 'collaborateur',
            'active' => true,
        ]);
        CrmLeaveEntry::query()->create([
            'employee_id' => $employee->id,
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'status' => 'pending',
            'type' => 'conge',
            'period' => 'full',
        ]);

        $busyItem = CrmEquipmentItem::query()->create([
            'site_id' => $site->id,
            'name' => 'Ponceuse',
            'active' => true,
        ]);
        CrmEquipmentItem::query()->create([
            'site_id' => $site->id,
            'name' => 'Monobrosse',
            'active' => true,
        ]);
        CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $busyItem->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'half_day',
            'slot' => 'morning',
            'status' => CrmEquipmentRental::STATUS_RESERVED,
            'title' => 'Location test',
            'start_at' => '2026-08-05 00:00:00',
            'end_at' => '2026-08-05 23:59:00',
        ]);

        $this->artisan('crm:refresh-dashboard-metrics', ['--date' => '2026-08-05'])
            ->assertSuccessful();

        $metric = DashboardMetric::currentForSite($site->id, '2026-08-05');

        $this->assertNotNull($metric);
        $this->assertSame(1, $metric->reservations_today);
        $this->assertSame(123.45, $metric->monthly_revenue);
        $this->assertSame(1, $metric->pending_leaves);
        $this->assertSame(1, $metric->equipment_available);
        $this->assertSame(2, $metric->equipment_total);
        $this->assertSame(7, count($metric->reservation_trend));
    }
}
