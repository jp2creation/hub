<?php

namespace Tests\Feature;

use App\Models\CrmDocument;
use App\Models\CrmFeatureFlag;
use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrmDocumentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_does_not_require_authentication(): void
    {
        $this->getJson('/api/documents?action=health&category=promo')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'mysql');
    }

    public function test_guest_cannot_read_documents_bootstrap(): void
    {
        $this->getJson('/api/documents?action=bootstrap&category=promo')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Utilisateur HUB requis');
    }

    public function test_authorized_user_can_read_documents_bootstrap(): void
    {
        [$account, , $site] = $this->createCrmUser(['documents.view']);

        $this->actingAs($account)
            ->getJson('/api/documents?action=bootstrap&category=promo&siteId='.$site->id)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('category.slug', 'promo')
            ->assertJsonPath('selectedSiteId', $site->id)
            ->assertJsonPath('canManage', false);
    }

    public function test_documents_category_page_uses_active_child_module_when_parent_documents_is_disabled(): void
    {
        [$account] = $this->createCrmUser(['documents.view']);

        CrmModule::query()->updateOrCreate(
            ['slug' => 'documents'],
            [
                'name' => 'Documents',
                'description' => 'Parent menu documents',
                'route_path' => '/documents',
                'active' => false,
                'sort_order' => 240,
            ],
        );

        CrmFeatureFlag::query()->updateOrCreate(
            ['flag_key' => 'module:documents'],
            [
                'scope' => 'module',
                'name' => 'Documents',
                'description' => 'Parent menu documents',
                'enabled' => false,
            ],
        );

        $this->actingAs($account)
            ->get('/documents/promo')
            ->assertOk()
            ->assertSee('crm-shell-config');
    }

    public function test_manage_permission_is_required_to_upload_document(): void
    {
        [$account, , $site] = $this->createCrmUser(['documents.view']);

        $this->actingAs($account)
            ->postJson('/api/documents?action=save_document', [
                'category' => 'promo',
                'siteId' => $site->id,
                'name' => 'Promo test',
                'fileDataUrl' => 'data:application/pdf;base64,'.base64_encode('%PDF fake'),
                'fileName' => 'promo.pdf',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Gestion documents non autorisee');
    }

    public function test_manager_can_create_directory_upload_download_and_delete_document(): void
    {
        Storage::fake('local');

        [$account, , $site] = $this->createCrmUser(['documents.view', 'documents.manage']);

        $directoryId = $this->actingAs($account)
            ->postJson('/api/documents?action=save_directory', [
                'category' => 'promo',
                'siteId' => $site->id,
                'name' => 'Promos ete',
                'visibility' => 'restricted',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('directory.name', 'Promos ete')
            ->json('directory.id');

        $documentId = $this->actingAs($account)
            ->postJson('/api/documents?action=save_document', [
                'category' => 'promo',
                'siteId' => $site->id,
                'directoryId' => $directoryId,
                'name' => 'Offre parquet',
                'description' => 'Document commercial',
                'visibility' => 'restricted',
                'fileDataUrl' => 'data:application/pdf;base64,'.base64_encode('%PDF document test'),
                'fileName' => 'offre-parquet.pdf',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('document.name', 'Offre parquet')
            ->assertJsonPath('document.directoryId', $directoryId)
            ->json('document.id');

        $document = CrmDocument::query()->findOrFail($documentId);
        Storage::disk('local')->assertExists($document->disk_path);

        $this->actingAs($account)
            ->get('/documents/file/'.$documentId)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($account)
            ->postJson('/api/documents?action=delete_document', [
                'id' => $documentId,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Storage::disk('local')->assertMissing($document->disk_path);
        $this->assertDatabaseMissing('crm_documents', ['id' => $documentId]);
    }

    public function test_private_document_is_hidden_from_other_viewer(): void
    {
        Storage::fake('local');

        [$ownerAccount, , $site] = $this->createCrmUser(['documents.view', 'documents.manage']);
        [$viewerAccount] = $this->createCrmUser(['documents.view'], $site);

        $documentId = $this->actingAs($ownerAccount)
            ->postJson('/api/documents?action=save_document', [
                'category' => 'promo',
                'siteId' => $site->id,
                'name' => 'Document prive',
                'visibility' => 'private',
                'fileDataUrl' => 'data:application/pdf;base64,'.base64_encode('%PDF private'),
                'fileName' => 'private.pdf',
            ])
            ->assertOk()
            ->json('document.id');

        $this->actingAs($viewerAccount)
            ->getJson('/api/documents?action=bootstrap&category=promo&siteId='.$site->id)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(0, 'documents');

        $this->actingAs($viewerAccount)
            ->get('/documents/file/'.$documentId)
            ->assertForbidden();
    }

    public function test_document_observer_deletes_physical_file_on_model_delete(): void
    {
        Storage::fake('local');

        $site = CrmSite::query()->create([
            'name' => 'Palissy Documents',
            'slug' => 'palissy-documents',
            'active' => true,
        ]);
        $path = 'crm-documents/promo/test-observer.pdf';
        Storage::disk('local')->put($path, '%PDF observer');

        $document = CrmDocument::query()->create([
            'site_id' => $site->id,
            'category' => 'promo',
            'name' => 'Observer document',
            'visibility' => 'restricted',
            'disk' => 'local',
            'disk_path' => $path,
            'original_name' => 'observer.pdf',
            'mime_type' => 'application/pdf',
            'size' => 13,
        ]);

        $document->delete();

        Storage::disk('local')->assertMissing($path);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: User, 1: CrmUser, 2: CrmSite}
     */
    private function createCrmUser(array $permissions, ?CrmSite $site = null): array
    {
        $account = User::factory()->create();
        $crmUser = CrmUser::query()->create([
            'user_id' => $account->id,
            'name' => 'HUB Documents User '.$account->id,
            'role' => in_array('documents.manage', $permissions, true) ? 'responsable' : 'user',
            'active' => true,
        ]);

        $site ??= CrmSite::query()->create([
            'name' => 'Palissy Test '.$account->id,
            'slug' => 'palissy-test-'.$account->id,
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);

        $module = CrmModule::query()->updateOrCreate(
            ['slug' => 'documents-promo'],
            [
                'name' => 'Promo',
                'description' => 'Documents promotionnels',
                'route_path' => '/documents/promo',
                'active' => true,
                'sort_order' => 241,
            ],
        );

        $permissionIds = [];
        foreach ($permissions as $index => $permission) {
            $permissionIds[] = CrmPermission::query()->updateOrCreate(
                ['name' => $permission],
                [
                    'label' => $permission,
                    'group_label' => 'Documents',
                    'sort_order' => 171 + $index,
                ],
            )->id;
        }

        $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
        $crmUser->modules()->syncWithoutDetaching([$module->id]);
        $crmUser->permissions()->syncWithoutDetaching($permissionIds);

        return [$account, $crmUser, $site];
    }
}
