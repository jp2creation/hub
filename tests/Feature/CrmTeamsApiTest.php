<?php

namespace Tests\Feature;

use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmTeamsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_teams(): void
    {
        $this->getJson('/api/equipes?action=bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_user_reads_members_from_default_site(): void
    {
        [$account, , $palissy, $bordeaux] = $this->createCrmContext();

        $this->createMember($palissy, [
            'name' => 'Marie Durand',
            'first_name' => 'Marie',
            'last_name' => 'Durand',
            'email' => 'marie@example.test',
            'phone' => '06 11 22 33 44',
        ]);
        $this->createMember($bordeaux, [
            'name' => 'Paul Bordeaux',
            'first_name' => 'Paul',
            'last_name' => 'Bordeaux',
            'email' => 'paul@example.test',
            'phone' => '05 56 00 00 00',
        ]);

        $this->actingAs($account)
            ->getJson('/api/equipes?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('selectedSiteId', $palissy->id)
            ->assertJsonFragment(['name' => 'Palissy', 'membersCount' => 2])
            ->assertJsonFragment(['name' => 'Bordeaux', 'membersCount' => 2])
            ->assertJsonPath('members.0.firstName', 'Jean')
            ->assertJsonPath('members.1.firstName', 'Marie')
            ->assertJsonMissing(['firstName' => 'Paul']);
    }

    public function test_user_can_request_an_authorized_site(): void
    {
        [$account, , , $bordeaux] = $this->createCrmContext();

        $this->createMember($bordeaux, [
            'name' => 'Paul Bordeaux',
            'first_name' => 'Paul',
            'last_name' => 'Bordeaux',
            'email' => 'paul@example.test',
            'phone' => '05 56 00 00 00',
        ]);

        $this->actingAs($account)
            ->getJson('/api/equipes?action=bootstrap&siteId='.$bordeaux->id)
            ->assertOk()
            ->assertJsonPath('selectedSiteId', $bordeaux->id)
            ->assertJsonPath('members.0.firstName', 'Jean')
            ->assertJsonFragment([
                'firstName' => 'Paul',
                'lastName' => 'Bordeaux',
                'phone' => '05 56 00 00 00',
                'email' => 'paul@example.test',
            ]);
    }

    public function test_team_members_return_normalized_profile_photo_urls(): void
    {
        [$account, $crmUser, $palissy] = $this->createCrmContext();

        $crmUser->forceFill([
            'photo_url' => '/storage/assets/uploads/profiles/avatar.webp',
        ])->save();

        $this->actingAs($account)
            ->getJson('/api/equipes?action=bootstrap&siteId='.$palissy->id)
            ->assertOk()
            ->assertJsonPath('members.0.photoUrl', '/uploads/assets/uploads/profiles/avatar.webp');
    }

    public function test_user_without_team_access_is_rejected(): void
    {
        $account = User::factory()->create();
        CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'No Team',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($account)
            ->getJson('/api/equipes?action=bootstrap')
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Aucun site autorise pour le module equipe');
    }

    private function createCrmContext(): array
    {
        $palissy = CrmSite::query()->create([
            'name' => 'Palissy',
            'slug' => 'palissy',
            'active' => true,
        ]);
        $bordeaux = CrmSite::query()->create([
            'name' => 'Bordeaux',
            'slug' => 'bordeaux',
            'active' => true,
        ]);

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'equipes'],
            [
                'name' => 'Équipe',
                'description' => 'Annuaire des membres',
                'route_path' => '/equipes',
                'active' => true,
                'sort_order' => 16,
            ],
        );
        $permission = CrmPermission::query()->updateOrCreate(
            ['name' => 'teams.view'],
            [
                'label' => 'Voir les equipes',
                'group_label' => 'Equipe',
                'sort_order' => 155,
            ],
        );

        $account = User::factory()->create([
            'name' => 'Jean Martin',
            'email' => 'jean@example.test',
        ]);
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'Jean Martin',
            'first_name' => 'Jean',
            'last_name' => 'Martin',
            'email' => 'jean@example.test',
            'phone' => '06 00 00 00 00',
            'role' => 'user',
            'active' => true,
        ]);
        $crmUser->sites()->sync([
            $palissy->id => ['is_default' => true],
            $bordeaux->id => ['is_default' => false],
        ]);
        $crmUser->modules()->sync([$module->id]);
        $crmUser->permissions()->sync([$permission->id]);

        return [$account, $crmUser, $palissy, $bordeaux];
    }

    private function createMember(CrmSite $site, array $attributes): CrmUser
    {
        $member = CrmUser::query()->create(array_merge([
            'role' => 'user',
            'active' => true,
        ], $attributes));
        $member->sites()->sync([$site->id => ['is_default' => true]]);

        return $member;
    }
}
