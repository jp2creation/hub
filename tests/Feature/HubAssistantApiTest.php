<?php

namespace Tests\Feature;

use App\Models\CrmMenuGroup;
use App\Models\CrmModule;
use App\Models\CrmPage;
use App\Models\CrmUser;
use App\Models\User;
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

    /**
     * @return array{0: User, 1: CrmUser}
     */
    private function createHubUser(string $role = 'user'): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => $role === 'admin' ? 'Admin HUB' : 'Jean-Philippe HUB',
            'role' => $role,
            'active' => true,
        ]);

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
}
