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

        $response = $this->actingAs($account)
            ->getJson('/api/equipes?action=bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('selectedSiteId', $palissy->id)
            ->assertJsonFragment(['name' => 'Palissy', 'membersCount' => 2])
            ->assertJsonFragment(['name' => 'Bordeaux', 'membersCount' => 2])
            ->assertJsonPath('members.0.firstName', 'Jean')
            ->assertJsonPath('members.1.firstName', 'Marie')
            ->assertJsonMissing(['firstName' => 'Paul']);

        $site = collect($response->json('sites'))->firstWhere('id', $palissy->id);

        $this->assertSame('18 rue Palissy, 64000 Pau', $site['address'] ?? null);
        $this->assertSame('05 59 11 22 33', $site['phone'] ?? null);
        $this->assertSame('palissy@example.test', $site['email'] ?? null);
        $this->assertSame('#2563eb', $site['color'] ?? null);
        $this->assertSame('/uploads/assets/uploads/sites/palissy.webp', $site['photoUrl'] ?? null);
        $this->assertTrue($site['showPhotoInHeader'] ?? false);
        $this->assertSame('08:00', $site['hours']['morningStart'] ?? null);
    }

    public function test_site_photo_background_can_be_hidden_for_team_header(): void
    {
        [$account, , $palissy] = $this->createCrmContext();

        $palissy->forceFill(['show_photo_in_header' => false])->save();

        $site = collect($this->actingAs($account)
            ->getJson('/api/equipes?action=bootstrap&siteId='.$palissy->id)
            ->assertOk()
            ->json('sites'))->firstWhere('id', $palissy->id);

        $this->assertSame('/uploads/assets/uploads/sites/palissy.webp', $site['photoUrl'] ?? null);
        $this->assertFalse($site['showPhotoInHeader'] ?? true);
    }

    public function test_team_member_exposes_only_primary_site_for_display(): void
    {
        [$account, , $palissy, $bordeaux] = $this->createCrmContext();

        $member = CrmUser::query()->create([
            'name' => 'Claire Multi',
            'first_name' => 'Claire',
            'last_name' => 'Multi',
            'email' => 'claire@example.test',
            'role' => 'user',
            'active' => true,
        ]);
        $member->sites()->sync([
            $palissy->id => ['is_default' => false],
            $bordeaux->id => ['is_default' => true],
        ]);

        $members = $this->actingAs($account)
            ->getJson('/api/equipes?action=bootstrap&siteId='.$palissy->id)
            ->assertOk()
            ->json('members');
        $claire = collect($members)->firstWhere('firstName', 'Claire');

        $this->assertSame($bordeaux->id, $claire['primarySiteId'] ?? null);
        $this->assertSame('Bordeaux', $claire['primarySiteName'] ?? null);
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

    public function test_user_can_read_members_from_all_authorized_sites(): void
    {
        [$account, , $palissy, $bordeaux] = $this->createCrmContext();
        $lyon = CrmSite::query()->create([
            'name' => 'Lyon',
            'slug' => 'lyon',
            'active' => true,
        ]);

        $this->createMember($palissy, [
            'name' => 'Marie Durand',
            'first_name' => 'Marie',
            'last_name' => 'Durand',
            'email' => 'marie@example.test',
        ]);
        $this->createMember($bordeaux, [
            'name' => 'Paul Bordeaux',
            'first_name' => 'Paul',
            'last_name' => 'Bordeaux',
            'email' => 'paul@example.test',
        ]);
        $this->createMember($lyon, [
            'name' => 'Luc Lyon',
            'first_name' => 'Luc',
            'last_name' => 'Lyon',
            'email' => 'luc@example.test',
        ]);

        $this->actingAs($account)
            ->getJson('/api/equipes?action=bootstrap&allSites=1')
            ->assertOk()
            ->assertJsonPath('allSites', true)
            ->assertJsonPath('selectedSiteId', $palissy->id)
            ->assertJsonFragment(['firstName' => 'Marie'])
            ->assertJsonFragment(['firstName' => 'Paul'])
            ->assertJsonMissing(['firstName' => 'Luc']);
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
            'address' => '18 rue Palissy, 64000 Pau',
            'phone' => '05 59 11 22 33',
            'email' => 'palissy@example.test',
            'color' => '#2563eb',
            'photo_url' => '/storage/assets/uploads/sites/palissy.webp',
            'morning_start' => '08:00:00',
        ]);
        $bordeaux = CrmSite::query()->create([
            'name' => 'Bordeaux',
            'slug' => 'bordeaux',
            'active' => true,
            'address' => '4 quai de Bordeaux, 33000 Bordeaux',
            'phone' => '05 56 44 55 66',
            'email' => 'bordeaux@example.test',
            'color' => '#16a34a',
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
