<?php

namespace Tests\Feature;

use App\Models\CrmModule;
use App\Models\CrmPage;
use App\Models\CrmPermission;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmPageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_pages(): void
    {
        $this->getJson('/api/pages?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur CRM requis');
    }

    public function test_authorized_user_can_read_page_bootstrap(): void
    {
        [$account] = $this->createCrmUser(canManage: false);

        CrmPage::query()->create([
            'title' => 'Procedure chantier',
            'slug' => 'procedure-chantier',
            'excerpt' => 'Consignes internes',
            'content' => 'Texte complet',
            'icon_key' => 'article',
            'active' => true,
            'show_in_menu' => true,
            'sort_order' => 10,
        ]);

        $this->actingAs($account)
            ->getJson('/api/pages?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pages.0.slug', 'procedure-chantier')
            ->assertJsonMissing(['content' => 'Texte complet']);
    }

    public function test_authorized_user_can_read_single_page(): void
    {
        [$account] = $this->createCrmUser(canManage: false);

        CrmPage::query()->create([
            'title' => 'Procedure atelier',
            'slug' => 'procedure-atelier',
            'excerpt' => 'Atelier',
            'content' => 'Contenu atelier',
            'icon_key' => 'article',
            'active' => true,
            'show_in_menu' => true,
            'sort_order' => 20,
        ]);

        $this->actingAs($account)
            ->getJson('/api/pages?action=page&slug=procedure-atelier')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.content', 'Contenu atelier')
            ->assertJsonPath('page.routePath', '/pages-crm/procedure-atelier');
    }

    public function test_manage_permission_is_required_to_save_page(): void
    {
        [$account] = $this->createCrmUser(canManage: false);

        $this->actingAs($account)
            ->postJson('/api/pages?action=save_page', [
                'title' => 'Nouvelle page',
                'content' => 'Contenu',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Droit administration insuffisant');
    }

    public function test_authenticated_user_can_open_pages_crm_screen(): void
    {
        [$account] = $this->createCrmUser(canManage: false);

        $this->actingAs($account)
            ->get('/pages-crm')
            ->assertOk()
            ->assertSee('crm-shell-config', false);

        $register = (string) file_get_contents(resource_path('frontend/crm/modules/register.ts'));
        $fallbackMenu = (string) file_get_contents(resource_path('frontend/crm/router/menu.ts'));

        $this->assertStringContainsString('crm-pages.js', $register);
        $this->assertStringContainsString('CRM_NAV_FALLBACK', $fallbackMenu);
    }

    public function test_manager_can_create_page_and_menu_item(): void
    {
        [$account] = $this->createCrmUser(canManage: true);

        $this->actingAs($account)
            ->postJson('/api/pages?action=save_page', [
                'title' => 'Nouvelle procedure',
                'excerpt' => 'Resume',
                'content' => 'Contenu interne',
                'iconKey' => 'fileText',
                'active' => true,
                'showInMenu' => true,
                'sortOrder' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'nouvelle-procedure')
            ->assertJsonPath('page.routePath', '/pages-crm/nouvelle-procedure');

        $this->assertDatabaseHas('crm_menu_items', [
            'item_key' => 'cms-page:nouvelle-procedure',
            'group_key' => 'pages',
            'label' => 'Nouvelle procedure',
            'active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: CrmUser}
     */
    private function createCrmUser(bool $canManage): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'CRM Page User '.$account->id,
            'role' => $canManage ? 'admin' : 'user',
            'active' => true,
        ]);

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'pages-crm'],
            [
                'name' => 'Pages HUB',
                'description' => 'Pages internes',
                'route_path' => '/pages-crm',
                'active' => true,
                'sort_order' => 18,
            ],
        );

        $view = CrmPermission::query()->updateOrCreate(
            ['name' => 'pages.view'],
            ['label' => 'Voir les pages HUB', 'group_label' => 'Pages HUB', 'sort_order' => 190],
        );

        $manage = CrmPermission::query()->updateOrCreate(
            ['name' => 'pages.manage'],
            ['label' => 'Gerer les pages HUB', 'group_label' => 'Pages HUB', 'sort_order' => 200],
        );

        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching(
            $canManage ? [$view->id, $manage->id] : [$view->id],
        );

        return [$account, $crmUser];
    }
}
