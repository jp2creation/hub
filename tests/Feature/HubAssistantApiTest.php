<?php

namespace Tests\Feature;

use App\Models\CrmCashRegisterDay;
use App\Models\CrmMenuGroup;
use App\Models\CrmModule;
use App\Models\CrmPage;
use App\Models\CrmPermission;
use App\Models\CrmReservation;
use App\Models\CrmSalesInvoice;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HubAssistantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_send_message(): void
    {
        $this->postJson('/api/hub-assistant/message', ['message' => 'ouvrir conges'])
            ->assertStatus(401);
    }

    public function test_message_is_required(): void
    {
        [$account] = $this->createHubUser('admin');

        $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', [])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Votre message est obligatoire.');
    }

    public function test_it_returns_matching_module_for_accessible_user(): void
    {
        [$account] = $this->createHubUser('admin');
        $this->createModule('dashboard', 'Tableau de bord', '/');
        $this->createModule('conges', 'Congés & Absences', '/conges');
        $this->createModule('remise-cheques', 'Remise de chèques', '/remise-cheques');

        $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'je cherche les conges'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', '/conges')
            ->assertJsonPath('label', 'Ouvrir Congés & Absences');
    }

    public function test_it_replies_politely_to_a_greeting(): void
    {
        [$account] = $this->createHubUser('admin');
        $this->createModule('dashboard', 'Tableau de bord', '/');
        $this->createModule('equipes', 'Équipe', '/equipes');

        $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'Bonjour'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', null)
            ->assertJsonFragment([
                'message' => 'Bonjour ! Comment puis-je vous aider ?',
            ]);
    }

    public function test_it_answers_basic_time_and_date_questions(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-02 10:15:00', 'Europe/Paris'));

        try {
            [$account] = $this->createHubUser('admin');
            $this->createModule('dashboard', 'Tableau de bord', '/');

            $this->actingAs($account)
                ->postJson('/api/hub-assistant/message', ['message' => 'Quelle heure est-il ?'])
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('message', 'Il est actuellement 10:15.');

            $this->actingAs($account)
                ->postJson('/api/hub-assistant/message', ['message' => 'Quel jour sommes-nous ?'])
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('message', 'Nous sommes dimanche.');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_it_guides_common_leave_questions_with_accessible_link(): void
    {
        [$account] = $this->createHubUser('admin');
        $this->createModule('dashboard', 'Tableau de bord', '/');
        $this->createModule('conges', 'Congés & Absences', '/conges');

        $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'comment poser des vacances ?'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', '/conges')
            ->assertJsonPath('label', 'Ouvrir Congés & Absences')
            ->assertJsonFragment([
                'message' => 'Pour les congés et absences, ouvrez Congés & Absences. Vous y trouverez votre calendrier, vos soldes et les demandes. Pour créer une demande, utilisez le bouton + Demander une absence.',
            ]);
    }

    public function test_it_does_not_suggest_inaccessible_guided_topics(): void
    {
        [$account, $crmUser] = $this->createHubUser();
        $dashboard = $this->createModule('dashboard', 'Tableau de bord', '/');
        $leaves = $this->createModule('conges', 'Congés & Absences', '/conges');

        $crmUser->modules()->sync([$dashboard->id]);

        $response = $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'comment poser des congés ?'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', null)
            ->assertJsonFragment([
                'message' => 'Je comprends la demande, mais je ne vois pas ce module dans vos accès actuels. Voici les raccourcis disponibles avec votre compte.',
            ])
            ->json();

        $urls = collect($response['suggestions'] ?? [])->pluck('url')->all();

        $this->assertNotContains($leaves->route_path, $urls);
    }

    public function test_it_explains_how_to_log_out(): void
    {
        [$account] = $this->createHubUser('admin');
        $this->createModule('dashboard', 'Tableau de bord', '/');

        $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'comment me déconnecter ?'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', null)
            ->assertJsonFragment([
                'message' => 'Pour vous déconnecter, ouvrez le menu utilisateur en haut à droite puis cliquez sur Se déconnecter.',
            ]);
    }

    public function test_regular_user_does_not_receive_inaccessible_module(): void
    {
        [$account, $crmUser] = $this->createHubUser();
        $leaves = $this->createModule('conges', 'Congés & Absences', '/conges');
        $this->createModule('administration', 'Administration', '/administration');

        $crmUser->modules()->sync([$leaves->id]);

        $response = $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'administration utilisateurs'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', null)
            ->json();

        $urls = collect($response['suggestions'] ?? [])->pluck('url')->all();

        $this->assertNotContains('/administration', $urls);
    }

    public function test_internal_pages_are_searchable_when_pages_module_is_accessible(): void
    {
        [$account, $crmUser] = $this->createHubUser();
        $pagesModule = $this->createModule('pages-crm', 'Pages HUB', '/pages-crm');
        $crmUser->modules()->sync([$pagesModule->id]);

        CrmPage::query()->updateOrCreate(
            ['slug' => 'procedure-depannage'],
            [
                'title' => 'Procedure depannage',
                'excerpt' => 'Consignes atelier',
                'content' => 'Diagnostic et prise en charge',
                'active' => true,
                'show_in_menu' => true,
                'sort_order' => 10,
            ],
        );

        $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'depannage atelier'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', '/pages-crm/procedure-depannage')
            ->assertJsonPath('label', 'Ouvrir Procedure depannage');
    }

    public function test_it_answers_sales_revenue_when_pilotage_is_accessible(): void
    {
        CarbonImmutable::setTestNow('2026-07-28 10:00:00');

        [$account, $crmUser] = $this->createHubUser();
        $site = $this->createSite('Palissy');
        $sales = $this->createModule('pilotage-commercial', 'Pilotage commercial', '/pilotage-commercial');
        $this->grantModuleForSite($crmUser, $sales, $site, ['sales.view']);

        CrmSalesInvoice::query()->create([
            'site_id' => $site->id,
            'representative_user_id' => $crmUser->id,
            'number' => 'FAC-001',
            'customer_name' => 'Client A',
            'issue_date' => '2026-07-27',
            'due_date' => '2026-08-27',
            'status' => CrmSalesInvoice::STATUS_PAID,
            'subtotal' => 1000,
            'total' => 1200,
            'margin' => 300,
            'commission_base' => 300,
        ]);
        CrmSalesInvoice::query()->create([
            'site_id' => $site->id,
            'representative_user_id' => $crmUser->id,
            'number' => 'FAC-002',
            'customer_name' => 'Client B',
            'issue_date' => '2026-07-27',
            'due_date' => '2026-08-27',
            'status' => CrmSalesInvoice::STATUS_PAID,
            'subtotal' => 200,
            'total' => 250,
            'margin' => 60,
            'commission_base' => 60,
        ]);
        CrmSalesInvoice::query()->create([
            'site_id' => $site->id,
            'representative_user_id' => $crmUser->id,
            'number' => 'FAC-003',
            'customer_name' => 'Client C',
            'issue_date' => '2026-07-27',
            'due_date' => '2026-08-27',
            'status' => CrmSalesInvoice::STATUS_PENDING,
            'subtotal' => 500,
            'total' => 600,
            'margin' => 120,
            'commission_base' => 120,
        ]);

        $response = $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', [
                'message' => 'quel est mon chiffre d hier ?',
                'siteId' => $site->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', '/pilotage-commercial')
            ->assertJsonPath('label', 'Ouvrir Pilotage commercial')
            ->json();

        $this->assertStringContainsString('Votre chiffre commercial hier sur Palissy est de', $response['message']);
        $this->assertStringContainsString('1 450,00', $response['message']);
        $this->assertStringContainsString('2 facture(s) payée(s)', $response['message']);

        CarbonImmutable::setTestNow();
    }

    public function test_it_uses_cash_control_revenue_when_sales_is_not_accessible(): void
    {
        CarbonImmutable::setTestNow('2026-07-28 10:00:00');

        [$account, $crmUser] = $this->createHubUser();
        $site = $this->createSite('Palissy');
        $cash = $this->createModule('controle-caisse', 'Contrôle caisse', '/controle-caisse');
        $this->grantModuleForSite($crmUser, $cash, $site, ['controle_caisse.view']);

        CrmCashRegisterDay::query()->create([
            'site_id' => $site->id,
            'cash_date' => '2026-07-27',
            'opening_balance' => 100,
            'invoice_total' => 682.5,
            'cash_sales' => 250,
            'card_sales' => 432.5,
            'check_sales' => 0,
            'transfer_sales' => 0,
            'counted_cash' => 350,
            'bank_counted' => 432.5,
            'invoice_errors_count' => 0,
            'status' => CrmCashRegisterDay::STATUS_OK,
            'created_by' => $crmUser->id,
            'updated_by' => $crmUser->id,
        ]);

        $response = $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', [
                'message' => 'quel est le chiffre d hier ?',
                'siteId' => $site->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', '/controle-caisse')
            ->assertJsonPath('label', 'Ouvrir Contrôle caisse')
            ->json();

        $this->assertStringContainsString('D’après le contrôle caisse, le chiffre hier sur Palissy est de', $response['message']);
        $this->assertStringContainsString('682,50', $response['message']);
        $this->assertStringContainsString('1 journée(s) de caisse', $response['message']);

        CarbonImmutable::setTestNow();
    }

    public function test_it_answers_vehicle_reservations_and_free_slots_for_today(): void
    {
        CarbonImmutable::setTestNow('2026-07-28 10:00:00');

        [$account, $crmUser] = $this->createHubUser();
        $site = $this->createSite('Palissy');
        $reservations = $this->createModule('reservations', 'Réservations véhicules', '/reservations');
        $this->grantModuleForSite($crmUser, $reservations, $site, ['reservations.view']);

        $vehicle = CrmVehicle::query()->create([
            'site_id' => $site->id,
            'name' => 'Sprinter',
            'description' => 'Véhicule principal Martin Sols',
            'color' => '#95002e',
            'day_start_time' => '07:30',
            'day_end_time' => '17:30',
            'active' => true,
        ]);

        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Livraison matin',
            'contact_phone' => '',
            'start_at' => '2026-07-28 08:00:00',
            'end_at' => '2026-07-28 10:00:00',
        ]);
        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Livraison après-midi',
            'contact_phone' => '',
            'start_at' => '2026-07-28 14:00:00',
            'end_at' => '2026-07-28 15:00:00',
        ]);

        $response = $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', [
                'message' => 'le sprinter palissy est il réservé aujourd hui ?',
                'siteId' => $site->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', '/reservations')
            ->assertJsonPath('label', 'Ouvrir Réservations véhicules')
            ->json();

        $this->assertStringContainsString('Sprinter (Palissy) est réservé aujourd’hui', $response['message']);
        $this->assertStringContainsString('08:00-10:00', $response['message']);
        $this->assertStringContainsString('14:00-15:00', $response['message']);
        $this->assertStringContainsString('07:30-08:00', $response['message']);
        $this->assertStringContainsString('10:00-14:00', $response['message']);
        $this->assertStringContainsString('15:00-17:30', $response['message']);

        CarbonImmutable::setTestNow();
    }

    public function test_it_answers_known_member_phone_directly(): void
    {
        [$account] = $this->createHubUser('admin');
        $this->createModule('equipes', 'Équipe', '/equipes');

        CrmUser::query()->create([
            'name' => 'Jean-Philippe DEGERT',
            'first_name' => 'Jean-Philippe',
            'last_name' => 'DEGERT',
            'email' => 'peinture.pau@martinsols.com',
            'phone' => '0678679958',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'quel est le numero de tel de degert ?'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Le téléphone de Jean-Philippe DEGERT est 0678679958.')
            ->assertJsonPath('url', null)
            ->assertJsonPath('label', null)
            ->assertJsonCount(0, 'suggestions');
    }

    public function test_it_answers_known_site_address_directly(): void
    {
        [$account] = $this->createHubUser('admin');
        $this->createModule('equipes', 'Équipe', '/equipes');

        CrmSite::query()->create([
            'name' => 'Palissy',
            'active' => true,
            'address' => '18 rue Palissy, 64000 Pau',
            'phone' => '05 59 11 22 33',
        ]);

        $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'adresse du site Palissy'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'L’adresse du site Palissy est 18 rue Palissy, 64000 Pau.')
            ->assertJsonPath('url', null)
            ->assertJsonCount(0, 'suggestions');
    }

    public function test_it_does_not_expose_member_contact_without_team_access(): void
    {
        [$account, $crmUser] = $this->createHubUser();
        $dashboard = $this->createModule('dashboard', 'Tableau de bord', '/');
        $this->createModule('equipes', 'Équipe', '/equipes');
        $crmUser->modules()->sync([$dashboard->id]);

        CrmUser::query()->create([
            'name' => 'Jean-Philippe DEGERT',
            'first_name' => 'Jean-Philippe',
            'last_name' => 'DEGERT',
            'phone' => '0678679958',
            'role' => 'user',
            'active' => true,
        ]);

        $response = $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'quel est le tel de degert ?'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', null)
            ->assertJsonFragment([
                'message' => 'Je comprends la demande, mais je ne vois pas le module Équipe dans vos accès actuels. Je ne peux donc pas afficher les coordonnées d’un membre ou d’un site.',
            ])
            ->json();

        $this->assertStringNotContainsString('0678679958', $response['message']);

        $urls = collect($response['suggestions'] ?? [])->pluck('url')->all();

        $this->assertNotContains('/equipes', $urls);
    }

    public function test_it_asks_for_precision_when_member_contact_question_is_ambiguous(): void
    {
        [$account] = $this->createHubUser('admin');
        $this->createModule('equipes', 'Équipe', '/equipes');

        CrmUser::query()->create([
            'name' => 'Jean Martin',
            'first_name' => 'Jean',
            'last_name' => 'Martin',
            'phone' => '0600000001',
            'role' => 'user',
            'active' => true,
        ]);
        CrmUser::query()->create([
            'name' => 'Paul Martin',
            'first_name' => 'Paul',
            'last_name' => 'Martin',
            'phone' => '0600000002',
            'role' => 'user',
            'active' => true,
        ]);

        $response = $this->actingAs($account)
            ->postJson('/api/hub-assistant/message', ['message' => 'telephone de martin'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', null)
            ->assertJsonCount(0, 'suggestions')
            ->json();

        $this->assertStringContainsString('J’ai trouvé plusieurs membres possibles', $response['message']);
        $this->assertStringNotContainsString('0600000001', $response['message']);
        $this->assertStringNotContainsString('0600000002', $response['message']);
    }

    /**
     * @return array{0: User, 1: CrmUser}
     */
    private function createHubUser(string $role = 'user'): array
    {
        $crmUser = CrmUser::query()->create([
            'name' => $role === 'admin' ? 'Admin HUB' : 'Jean-Philippe HUB',
            'role' => $role,
            'active' => true,
        ]);
        $account = User::query()->findOrFail($crmUser->id);

        return [$account, $crmUser];
    }

    private function createModule(string $slug, string $name, string $routePath): CrmModule
    {
        CrmMenuGroup::query()->firstOrCreate(
            ['menu_key' => CrmModule::defaultMenuGroup($slug)],
            ['title' => 'Applications HUB', 'active' => true, 'sort_order' => 10],
        );

        return CrmModule::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => '',
                'route_path' => $routePath,
                'active' => true,
                'sort_order' => 10,
            ],
        );
    }

    private function createSite(string $name): CrmSite
    {
        return CrmSite::query()->create([
            'name' => $name,
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function grantModuleForSite(CrmUser $crmUser, CrmModule $module, CrmSite $site, array $permissions): void
    {
        $permissionIds = collect($permissions)
            ->map(fn (string $permission, int $index): int => CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => $module->name,
                    'sort_order' => 100 + $index,
                ],
            )->id)
            ->all();

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);
        $crmUser->load(['modules:id,slug,active', 'permissions:id,name,label,sort_order', 'sites:id,active']);
    }
}
