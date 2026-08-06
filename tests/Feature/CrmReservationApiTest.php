<?php

namespace Tests\Feature;

use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmReservation;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\CrmCore\Events\CrmDomainEvent;
use Tests\TestCase;

class CrmReservationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-01 08:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 08:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/reservations?action=health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'mysql');
    }

    public function test_guest_cannot_read_reservation_bootstrap(): void
    {
        $this->getJson('/api/reservations?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_invalid_reservation_query_parameters_return_validation_error(): void
    {
        $this->getJson('/api/reservations?action=bootstrap&siteId=invalide')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Site invalide.');
    }

    public function test_authorized_user_can_read_reservation_bootstrap(): void
    {
        [$account, $crmUser, $site, $vehicle] = $this->createCrmUser(['reservations.view']);

        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Tournee test',
            'contact_phone' => '',
            'start_at' => '2026-08-03 08:00:00',
            'end_at' => '2026-08-03 09:00:00',
            'notes' => '',
        ]);

        $this->actingAs($account)
            ->getJson('/api/reservations?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('vehicles.0.name', 'Sprinter Test')
            ->assertJsonPath('reservations.0.title', 'Tournee test')
            ->assertJsonPath('user.siteIds.0', $site->id);
    }

    public function test_reservation_bootstrap_returns_minimal_users_for_allowed_sites(): void
    {
        [$account, , $site] = $this->createCrmUser(['reservations.view']);

        $module = CrmModule::query()->where('slug', 'reservations')->firstOrFail();
        $permission = CrmPermission::query()->where('name', 'reservations.view')->firstOrFail();

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
            'name' => 'Autre Site Reservations',
            'slug' => 'autre-site-reservations',
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
            ->getJson('/api/reservations?action=bootstrap')
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

    public function test_split_reservation_read_endpoints_are_windowed(): void
    {
        [$account, $crmUser, $site, $vehicle] = $this->createCrmUser(['reservations.view']);

        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Dans juillet',
            'contact_phone' => '',
            'start_at' => '2026-07-15 08:00:00',
            'end_at' => '2026-07-15 09:00:00',
            'notes' => '',
        ]);

        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Hors fenetre',
            'contact_phone' => '',
            'start_at' => '2027-02-15 08:00:00',
            'end_at' => '2027-02-15 09:00:00',
            'notes' => '',
        ]);

        $this->actingAs($account)
            ->getJson('/api/reservations/bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('vehicles.0.name', 'Sprinter Test')
            ->assertJsonMissingPath('reservations');

        $reservations = $this->actingAs($account)
            ->getJson('/api/reservations?from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('window.from', '2026-07-01T00:00')
            ->json('reservations');

        $this->assertCount(1, $reservations);
        $this->assertSame('Dans juillet', $reservations[0]['title']);

        $this->actingAs($account)
            ->getJson('/api/reservations?from=2026-01-01&to=2026-12-31')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Fenetre de dates trop large');
    }

    public function test_reservation_users_and_vehicles_endpoints_support_cursor_pagination(): void
    {
        [$account, , $site, $vehicle] = $this->createCrmUser(['reservations.view']);

        CrmVehicle::query()->create([
            'site_id' => $site->id,
            'name' => 'Boxer Test',
            'description' => 'Second vehicule',
            'color' => '#95002e',
            'active' => true,
        ]);

        $vehiclesPage = $this->actingAs($account)
            ->getJson('/api/reservations/vehicles?limit=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.hasMore', true)
            ->json();

        $this->assertSame($vehicle->id, $vehiclesPage['vehicles'][0]['id']);
        $this->assertSame($vehicle->id, $vehiclesPage['pagination']['nextCursor']);

        $this->actingAs($account)
            ->getJson('/api/reservations/vehicles?limit=1&cursor='.$vehiclesPage['pagination']['nextCursor'])
            ->assertOk()
            ->assertJsonPath('vehicles.0.name', 'Boxer Test')
            ->assertJsonPath('pagination.hasMore', false);

        $this->actingAs($account)
            ->getJson('/api/reservations/users?limit=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('users.0.name', 'HUB Reservation User '.$account->id);
    }

    public function test_vehicle_day_hours_are_exposed_and_enforced(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.view', 'reservations.create']);

        $vehicle->forceFill([
            'day_start_time' => '08:00',
            'day_end_time' => '16:30',
        ])->save();

        $this->actingAs($account)
            ->getJson('/api/reservations?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('vehicles.0.dayStartTime', '08:00')
            ->assertJsonPath('vehicles.0.dayEndTime', '16:30');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-04T07:30',
                'endAt' => '2026-08-04T08:30',
                'title' => 'Trop tot',
            ])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Creneau hors horaires du vehicule');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-04T08:00',
                'endAt' => '2026-08-04T09:00',
                'title' => 'Dans les horaires',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.title', 'Dans les horaires');
    }

    public function test_vehicle_default_day_hours_allow_extended_day_view_slots(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.create']);

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-07T06:00',
                'endAt' => '2026-08-07T12:30',
                'title' => 'Matin par defaut',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.startAt', '2026-08-07T06:00')
            ->assertJsonPath('reservation.endAt', '2026-08-07T12:30');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-08T13:00',
                'endAt' => '2026-08-08T19:30',
                'title' => 'Apres-midi par defaut',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.startAt', '2026-08-08T13:00')
            ->assertJsonPath('reservation.endAt', '2026-08-08T19:30');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-09T05:30',
                'endAt' => '2026-08-09T06:30',
                'title' => 'Trop tot',
            ])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Creneau hors horaires du vehicule');
    }

    public function test_create_permission_is_required_to_create_reservation(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.view']);

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-04T08:00',
                'endAt' => '2026-08-04T09:00',
                'title' => 'Sans droit',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit insuffisant : reservations.create');
    }

    public function test_reservation_payload_accepts_snake_case_and_dispatches_domain_event(): void
    {
        Event::fake([CrmDomainEvent::class]);

        [$account, , , $vehicle] = $this->createCrmUser(['reservations.create']);

        $reservationId = $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicle_id' => $vehicle->id,
                'start_at' => '2026-08-10 08:00',
                'end_at' => '2026-08-10T09:00',
                'title' => 'DTO snake case',
                'contact_phone' => '06 00 00 00 00',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.title', 'DTO snake case')
            ->assertJsonPath('reservation.startAt', '2026-08-10T08:00')
            ->json('reservation.id');

        Event::assertDispatched(CrmDomainEvent::class, function (CrmDomainEvent $event) use ($reservationId): bool {
            return $event->module === 'reservations'
                && $event->entity === 'reservation'
                && $event->name === 'created'
                && (int) $event->entityId === (int) $reservationId
                && ($event->payload['title'] ?? null) === 'DTO snake case';
        });
    }

    public function test_overlapping_reservation_is_rejected(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.create']);

        $payload = [
            'vehicleId' => $vehicle->id,
            'startAt' => '2026-08-05T08:00',
            'endAt' => '2026-08-05T10:00',
            'title' => 'Premiere reservation',
        ];

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.title', 'Premiere reservation');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                ...$payload,
                'startAt' => '2026-08-05T09:30',
                'endAt' => '2026-08-05T11:00',
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Le vehicule est deja reserve sur ce creneau');
    }

    public function test_past_reservation_is_rejected(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.create']);
        $yesterday = now()->subDay()->format('Y-m-d');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => $yesterday.'T08:00',
                'endAt' => $yesterday.'T09:00',
                'title' => 'Reservation passee',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Impossible de reserver dans le passe');
    }

    public function test_user_can_update_own_reservation(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.create', 'reservations.update_own']);

        $reservationId = $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-06T08:00',
                'endAt' => '2026-08-06T09:00',
                'title' => 'A modifier',
            ])
            ->assertOk()
            ->json('reservation.id');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=update_reservation', [
                'id' => $reservationId,
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-06T09:00',
                'endAt' => '2026-08-06T10:00',
                'title' => 'Modifie',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.title', 'Modifie')
            ->assertJsonPath('reservation.startAt', '2026-08-06T09:00');
    }

    public function test_user_with_update_own_cannot_update_past_reservation(): void
    {
        [$account, $crmUser, $site, $vehicle] = $this->createCrmUser(['reservations.update_own']);
        $yesterday = now()->subDay()->format('Y-m-d');

        $reservation = CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Ancienne reservation',
            'contact_phone' => '',
            'start_at' => $yesterday.' 08:00:00',
            'end_at' => $yesterday.' 09:00:00',
            'notes' => '',
        ]);

        $this->actingAs($account)
            ->postJson('/api/reservations?action=update_reservation', [
                'id' => $reservation->id,
                'vehicleId' => $vehicle->id,
                'startAt' => $yesterday.'T09:00',
                'endAt' => $yesterday.'T10:00',
                'title' => 'Ancienne modifiee',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Modification non autorisee');

        $this->assertDatabaseHas('crm_reservations', [
            'id' => $reservation->id,
            'title' => 'Ancienne reservation',
        ]);
    }

    public function test_crm_admin_can_update_past_reservation(): void
    {
        [$account, $crmUser, $site, $vehicle] = $this->createCrmUser(['reservations.update_any'], 'admin');
        $yesterday = now()->subDay()->format('Y-m-d');

        $reservation = CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Historique admin',
            'contact_phone' => '',
            'start_at' => $yesterday.' 08:00:00',
            'end_at' => $yesterday.' 09:00:00',
            'notes' => '',
        ]);

        $this->actingAs($account)
            ->postJson('/api/reservations?action=update_reservation', [
                'id' => $reservation->id,
                'vehicleId' => $vehicle->id,
                'startAt' => $yesterday.'T09:00',
                'endAt' => $yesterday.'T10:00',
                'title' => 'Historique corrige',
                'notes' => 'Correction admin',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reservation.title', 'Historique corrige')
            ->assertJsonPath('reservation.notes', 'Correction admin');
    }

    public function test_reservation_domain_events_are_logged_centrally(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.create']);

        $reservationId = $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-11T08:00',
                'endAt' => '2026-08-11T09:00',
                'title' => 'Journalisee',
            ])
            ->assertOk()
            ->json('reservation.id');

        $this->assertDatabaseHas('crm_logs', [
            'action' => 'creation reservation',
            'details' => 'Reservation #'.$reservationId.' - Journalisee',
        ]);
    }

    public function test_creator_without_delete_own_cannot_delete_reservation(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.create']);

        $reservationId = $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-07T08:00',
                'endAt' => '2026-08-07T09:00',
                'title' => 'A supprimer',
            ])
            ->assertOk()
            ->json('reservation.id');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=delete_reservation', [
                'id' => $reservationId,
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Suppression non autorisee');

        $this->assertDatabaseHas('crm_reservations', [
            'id' => $reservationId,
        ]);
    }

    public function test_creator_with_delete_own_can_delete_reservation(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.create', 'reservations.delete_own']);

        $reservationId = $this->actingAs($account)
            ->postJson('/api/reservations?action=create_reservation', [
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-07T10:00',
                'endAt' => '2026-08-07T11:00',
                'title' => 'A supprimer avec droit',
            ])
            ->assertOk()
            ->json('reservation.id');

        $this->actingAs($account)
            ->postJson('/api/reservations?action=delete_reservation', [
                'id' => $reservationId,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('crm_reservations', [
            'id' => $reservationId,
        ]);
    }

    public function test_creator_with_delete_own_cannot_delete_past_reservation(): void
    {
        [$account, $crmUser, $site, $vehicle] = $this->createCrmUser(['reservations.delete_own']);
        $yesterday = now()->subDay()->format('Y-m-d');

        $reservation = CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Reservation passee',
            'contact_phone' => '',
            'start_at' => $yesterday.' 08:00:00',
            'end_at' => $yesterday.' 09:00:00',
            'notes' => '',
        ]);

        $this->actingAs($account)
            ->postJson('/api/reservations?action=delete_reservation', [
                'id' => $reservation->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Suppression non autorisee');

        $this->assertDatabaseHas('crm_reservations', [
            'id' => $reservation->id,
        ]);
    }

    public function test_crm_admin_can_delete_past_reservation(): void
    {
        [$account, $crmUser, $site, $vehicle] = $this->createCrmUser(['reservations.delete_any'], 'admin');
        $yesterday = now()->subDay()->format('Y-m-d');

        $reservation = CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Historique admin',
            'contact_phone' => '',
            'start_at' => $yesterday.' 08:00:00',
            'end_at' => $yesterday.' 09:00:00',
            'notes' => '',
        ]);

        $this->actingAs($account)
            ->postJson('/api/reservations?action=delete_reservation', [
                'id' => $reservation->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('crm_reservations', [
            'id' => $reservation->id,
        ]);
    }

    public function test_deleting_another_user_reservation_notifies_the_owner(): void
    {
        [$ownerAccount, $owner, $site, $vehicle] = $this->createCrmUser(['reservations.create']);

        $reservation = CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $owner->id,
            'user_name' => $owner->name,
            'title' => 'Suppression responsable',
            'contact_phone' => '',
            'start_at' => '2026-08-12 08:00:00',
            'end_at' => '2026-08-12 09:00:00',
            'notes' => '',
        ]);

        $managerAccount = User::factory()->create();
        $manager = CrmUser::query()->create([
            'user_id' => $managerAccount->id,
            'name' => 'Manager Reservations',
            'role' => 'responsable',
            'active' => true,
        ]);
        $module = CrmModule::query()->where('slug', 'reservations')->firstOrFail();
        $permission = CrmPermission::query()->updateOrCreate(
            ['name' => 'reservations.delete_any'],
            ['label' => 'Supprimer toutes les reservations', 'group_label' => 'Reservations', 'sort_order' => 160],
        );

        $manager->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $manager->modules()->syncWithoutDetaching([$module->id]);
        $manager->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($managerAccount)
            ->postJson('/api/reservations?action=delete_reservation', ['id' => $reservation->id])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('notification_logs', [
            'notifiable_type' => User::class,
            'notifiable_id' => $ownerAccount->id,
            'template_key' => 'reservation_deleted',
            'status' => 'sent',
        ]);
    }

    public function test_updating_unknown_reservation_returns_not_found(): void
    {
        [$account, , , $vehicle] = $this->createCrmUser(['reservations.update']);

        $this->actingAs($account)
            ->postJson('/api/reservations?action=update_reservation', [
                'id' => 999999,
                'vehicleId' => $vehicle->id,
                'startAt' => '2026-08-06T09:00',
                'endAt' => '2026-08-06T10:00',
                'title' => 'Introuvable',
            ])
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Reservation introuvable');
    }

    public function test_manager_can_save_and_hide_vehicle(): void
    {
        [$account, , $site] = $this->createCrmUser(['reservations.manage_vehicles']);

        $vehicle = $this->actingAs($account)
            ->postJson('/api/reservations?action=save_vehicle', [
                'siteId' => $site->id,
                'name' => 'Camion test',
                'description' => 'Vehicule atelier',
                'color' => '#123abc',
                'photoDataUrl' => $this->crmPngDataUrl(),
                'dayStartTime' => '08:15',
                'dayEndTime' => '16:45',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('vehicle.name', 'Camion test')
            ->assertJsonPath('vehicle.dayStartTime', '08:15')
            ->assertJsonPath('vehicle.dayEndTime', '16:45')
            ->json('vehicle');

        $vehicleId = (int) $vehicle['id'];
        $photoPath = preg_replace('#^/(?:storage|uploads)/#', '', (string) $vehicle['photoUrl']) ?? '';

        $this->assertMatchesRegularExpression('#^/(storage|uploads)/assets/uploads/vehicles/#', $vehicle['photoUrl']);
        Storage::disk('public')->assertExists($photoPath);
        Storage::disk('public')->assertExists(str_replace('.webp', '-thumb.webp', $photoPath));

        $this->actingAs($account)
            ->postJson('/api/reservations?action=delete_vehicle', ['id' => $vehicleId])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('id', $vehicleId);

        Storage::disk('public')->assertMissing($photoPath);
        Storage::disk('public')->assertMissing(str_replace('.webp', '-thumb.webp', $photoPath));

        $this->assertDatabaseHas('crm_vehicles', [
            'id' => $vehicleId,
            'active' => false,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: User, 1: CrmUser, 2: CrmSite, 3: CrmVehicle}
     */
    private function createCrmUser(array $permissions, string $role = 'user'): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'HUB Reservation User '.$account->id,
            'role' => $role,
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

        $vehicle = CrmVehicle::query()->create([
            'site_id' => $site->id,
            'name' => 'Sprinter Test',
            'description' => 'Vehicule test',
            'color' => '#95002e',
            'active' => true,
        ]);

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'reservations'],
            [
                'name' => 'Reservations',
                'description' => 'Reservations vehicules',
                'route_path' => '/reservations',
                'active' => true,
                'sort_order' => 10,
            ],
        );

        $permissionIds = [];

        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => 'Reservations',
                    'sort_order' => 100 + $index,
                ],
            )->id;
        }

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);

        return [$account, $crmUser, $site, $vehicle];
    }
}
