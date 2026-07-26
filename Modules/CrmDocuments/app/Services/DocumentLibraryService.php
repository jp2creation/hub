<?php

namespace Modules\CrmDocuments\Services;

use App\Models\CrmDocument;
use App\Models\CrmDocumentDirectory;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use App\Support\CrmTheme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Services\CrmActivityLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentLibraryService
{
    private const VIEW_PERMISSION = 'documents.view';

    private const MANAGE_PERMISSION = 'documents.manage';

    private const STORAGE_DISK = 'local';

    private const STORAGE_PATH = 'crm-documents';

    private const MAX_UPLOAD_BYTES = 20_971_520;

    /**
     * @var array<string, array{slug: string, label: string, module: string, route: string, color: string}>
     */
    private const CATEGORIES = [
        'promo' => [
            'slug' => 'promo',
            'label' => 'Promo',
            'module' => 'documents-promo',
            'route' => '/documents/promo',
            'color' => '',
        ],
        'fiches-techniques' => [
            'slug' => 'fiches-techniques',
            'label' => 'Fiches techniques',
            'module' => 'documents-fiches-techniques',
            'route' => '/documents/fiches-techniques',
            'color' => '#2563eb',
        ],
        'procedures' => [
            'slug' => 'procedures',
            'label' => 'Procédures',
            'module' => 'documents-procedures',
            'route' => '/documents/procedures',
            'color' => '#16a34a',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const ALLOWED_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    public function __construct(
        private readonly CrmAccessService $access,
        private readonly CrmActivityLogger $activity,
    ) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label', 'sites:id'])
            ->where('user_id', $user->id)
            ->where('active', true)
            ->first();

        if (! $actor) {
            $this->fail('Utilisateur HUB introuvable', 404);
        }

        return $actor;
    }

    public function bootstrap(CrmUser $actor, string $categorySlug, ?int $requestedSiteId = null): array
    {
        $category = $this->category($categorySlug);
        $siteIds = $this->siteIds($actor, $category['module']);

        if ($siteIds === []) {
            $this->fail('Aucun site autorise pour ces documents', 403);
        }

        $selectedSiteId = $this->selectedSiteId($actor, $requestedSiteId, $siteIds);
        $canManage = $this->canManage($actor, $selectedSiteId, $category['module']);

        $directories = CrmDocumentDirectory::query()
            ->with(['creator:id,name'])
            ->forContext($selectedSiteId, $category['slug'])
            ->where(fn (Builder $query): Builder => $this->visibleDirectoryQuery($query, $actor, $canManage))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $documents = CrmDocument::query()
            ->with(['directory:id,name', 'creator:id,name'])
            ->forContext($selectedSiteId, $category['slug'])
            ->where(fn (Builder $query): Builder => $this->visibleDocumentQuery($query, $actor, $canManage))
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->orderBy('name')
            ->get();

        return [
            'ok' => true,
            'mode' => 'mysql',
            'category' => $category,
            'categories' => array_values($this->categories()),
            'selectedSiteId' => $selectedSiteId,
            'sites' => $this->siteRows($siteIds),
            'user' => [
                'id' => (int) $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
            ],
            'canManage' => $canManage,
            'directories' => $directories->map(fn (CrmDocumentDirectory $directory): array => $this->directoryRow($directory))->values()->all(),
            'documents' => $documents->map(fn (CrmDocument $document): array => $this->documentRow($document, $canManage))->values()->all(),
            'stats' => [
                'directories' => $directories->count(),
                'documents' => $documents->count(),
                'totalSize' => $documents->sum('size'),
                'privateDocuments' => $documents->where('visibility', 'private')->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDirectory(CrmUser $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $category = $this->category((string) ($data['category'] ?? ''));
            $siteId = (int) ($data['siteId'] ?? $data['site_id'] ?? 0);
            $this->requireManage($actor, $siteId, $category['module']);

            $id = max(0, (int) ($data['id'] ?? 0));
            $name = $this->shortText((string) ($data['name'] ?? ''), 160, 'Nom du dossier invalide');
            $description = $this->optionalText((string) ($data['description'] ?? ''), 1000, 'Description trop longue');
            $visibility = $this->visibility((string) ($data['visibility'] ?? 'restricted'));
            $parentId = $this->parentId($siteId, $category['slug'], (int) ($data['parentId'] ?? $data['parent_id'] ?? 0), $id ?: null);
            $sortOrder = max(0, min(9999, (int) ($data['sortOrder'] ?? $data['sort_order'] ?? 100)));

            $directory = $id > 0
                ? CrmDocumentDirectory::query()->lockForUpdate()->find($id)
                : new CrmDocumentDirectory;

            if ($id > 0 && ! $directory) {
                $this->fail('Dossier introuvable', 404);
            }

            if ($id > 0 && ((int) $directory->site_id !== $siteId || $directory->category !== $category['slug'])) {
                $this->fail('Dossier hors contexte', 403);
            }

            $directory->fill([
                'site_id' => $siteId,
                'category' => $category['slug'],
                'parent_id' => $parentId,
                'name' => $name,
                'description' => $description,
                'visibility' => $visibility,
                'sort_order' => $sortOrder,
                'created_by' => $directory->exists ? $directory->created_by : $actor->id,
                'updated_by' => $actor->id,
            ]);
            $directory->save();

            $this->log($actor, $id > 0 ? 'modification dossier documents' : 'creation dossier documents', $directory->name);

            return ['ok' => true, 'directory' => $this->directoryRow($directory->refresh())];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deleteDirectory(CrmUser $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);

            if ($id <= 0) {
                $this->fail('Dossier invalide', 400);
            }

            $directory = CrmDocumentDirectory::query()->lockForUpdate()->find($id);

            if (! $directory) {
                $this->fail('Dossier introuvable', 404);
            }

            $category = $this->category((string) $directory->category);
            $this->requireManage($actor, (int) $directory->site_id, $category['module']);

            if (! $directory->isEmpty()) {
                $this->fail('Le dossier doit etre vide avant suppression', 409);
            }

            $name = $directory->name;
            $directory->delete();
            $this->log($actor, 'suppression dossier documents', $name);

            return ['ok' => true, 'id' => $id];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDocument(CrmUser $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $category = $this->category((string) ($data['category'] ?? ''));
            $siteId = (int) ($data['siteId'] ?? $data['site_id'] ?? 0);
            $this->requireManage($actor, $siteId, $category['module']);

            $id = max(0, (int) ($data['id'] ?? 0));
            $directoryId = $this->directoryId($siteId, $category['slug'], (int) ($data['directoryId'] ?? $data['directory_id'] ?? 0));
            $name = trim((string) ($data['name'] ?? ''));
            $description = $this->optionalText((string) ($data['description'] ?? ''), 1000, 'Description trop longue');
            $visibility = $this->visibility((string) ($data['visibility'] ?? 'restricted'));
            $sortOrder = max(0, min(9999, (int) ($data['sortOrder'] ?? $data['sort_order'] ?? 100)));

            $document = $id > 0
                ? CrmDocument::query()->lockForUpdate()->find($id)
                : new CrmDocument;

            if ($id > 0 && ! $document) {
                $this->fail('Document introuvable', 404);
            }

            if ($id > 0 && ((int) $document->site_id !== $siteId || $document->category !== $category['slug'])) {
                $this->fail('Document hors contexte', 403);
            }

            $upload = $this->decodeUpload((string) ($data['fileDataUrl'] ?? $data['file_data_url'] ?? ''), (string) ($data['fileName'] ?? $data['file_name'] ?? ''));

            if (! $document->exists && ! $upload) {
                $this->fail('Fichier requis', 400);
            }

            if ($name === '') {
                $name = $upload['originalName'] ?? $document->name ?? '';
            }

            $name = $this->shortText($name, 180, 'Nom du document invalide');

            if ($upload) {
                $oldDisk = $document->disk;
                $oldPath = $document->disk_path;
                $newPath = $this->storeUpload($category['slug'], $siteId, $upload);

                if ($oldDisk && $oldPath) {
                    Storage::disk($oldDisk)->delete($oldPath);
                }

                $document->disk = self::STORAGE_DISK;
                $document->disk_path = $newPath;
                $document->original_name = $upload['originalName'];
                $document->mime_type = $upload['mime'];
                $document->size = strlen($upload['content']);
            }

            $document->fill([
                'site_id' => $siteId,
                'category' => $category['slug'],
                'directory_id' => $directoryId,
                'name' => $name,
                'description' => $description,
                'visibility' => $visibility,
                'sort_order' => $sortOrder,
                'created_by' => $document->exists ? $document->created_by : $actor->id,
                'updated_by' => $actor->id,
            ]);
            $document->save();

            $this->log($actor, $id > 0 ? 'modification document' : 'ajout document', $document->name);

            return ['ok' => true, 'document' => $this->documentRow($document->refresh(), true)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deleteDocument(CrmUser $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);

            if ($id <= 0) {
                $this->fail('Document invalide', 400);
            }

            $document = CrmDocument::query()->lockForUpdate()->find($id);

            if (! $document) {
                $this->fail('Document introuvable', 404);
            }

            $category = $this->category((string) $document->category);
            $this->requireManage($actor, (int) $document->site_id, $category['module']);

            $name = $document->name;
            $document->delete();
            $this->log($actor, 'suppression document', $name);

            return ['ok' => true, 'id' => $id];
        });
    }

    public function documentForDownload(CrmUser $actor, int $documentId): CrmDocument
    {
        $document = CrmDocument::query()->find($documentId);

        if (! $document) {
            $this->fail('Document introuvable', 404);
        }

        $category = $this->category((string) $document->category);
        $canManage = $this->canManage($actor, (int) $document->site_id, $category['module']);

        if (! $this->canView($actor, (int) $document->site_id, $category['module'])) {
            $this->fail('Acces non autorise', 403);
        }

        if (! $canManage && $document->visibility === 'private' && (int) $document->created_by !== (int) $actor->id) {
            $this->fail('Document prive', 403);
        }

        return $document;
    }

    /**
     * @return array{slug: string, label: string, module: string, route: string, color: string}
     */
    private function category(string $slug): array
    {
        $slug = Str::slug($slug);
        $categories = $this->categories();

        if (! isset($categories[$slug])) {
            $this->fail('Categorie documents inconnue', 404);
        }

        return $categories[$slug];
    }

    /**
     * @return array<string, array{slug: string, label: string, module: string, route: string, color: string}>
     */
    private function categories(): array
    {
        $categories = self::CATEGORIES;
        $categories['promo']['color'] = CrmTheme::primaryHex();

        return $categories;
    }

    /**
     * @return array<int, int>
     */
    private function siteIds(CrmUser $actor, string $moduleSlug): array
    {
        return $this->access->siteIdsForModule($actor, $moduleSlug, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function selectedSiteId(CrmUser $actor, ?int $requestedSiteId, array $siteIds): int
    {
        if ($requestedSiteId && in_array($requestedSiteId, $siteIds, true)) {
            return $requestedSiteId;
        }

        $defaultSiteId = (int) DB::table('crm_user_sites')
            ->where('user_id', $actor->id)
            ->where('is_default', true)
            ->value('site_id');

        if ($defaultSiteId > 0 && in_array($defaultSiteId, $siteIds, true)) {
            return $defaultSiteId;
        }

        return $siteIds[0] ?? 0;
    }

    private function canView(CrmUser $actor, int $siteId, string $moduleSlug): bool
    {
        return $this->access->canAnyOnSite($actor, $siteId, $moduleSlug, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
    }

    private function canManage(CrmUser $actor, int $siteId, string $moduleSlug): bool
    {
        return $this->access->canOnSite($actor, $siteId, $moduleSlug, self::MANAGE_PERMISSION);
    }

    private function requireManage(CrmUser $actor, int $siteId, string $moduleSlug): void
    {
        if ($siteId <= 0 || ! $this->canManage($actor, $siteId, $moduleSlug)) {
            $this->fail('Gestion documents non autorisee', 403);
        }
    }

    private function visibleDirectoryQuery(Builder $query, CrmUser $actor, bool $canManage): Builder
    {
        if ($canManage) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($actor): void {
            $inner
                ->whereIn('visibility', ['public', 'restricted'])
                ->orWhere('created_by', $actor->id);
        });
    }

    private function visibleDocumentQuery(Builder $query, CrmUser $actor, bool $canManage): Builder
    {
        if ($canManage) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($actor): void {
            $inner
                ->whereIn('visibility', ['public', 'restricted'])
                ->orWhere('created_by', $actor->id);
        });
    }

    private function parentId(int $siteId, string $category, int $parentId, ?int $currentId = null): ?int
    {
        if ($parentId <= 0) {
            return null;
        }

        if ($currentId && $parentId === $currentId) {
            $this->fail('Un dossier ne peut pas etre son propre parent', 400);
        }

        $parent = CrmDocumentDirectory::query()
            ->forContext($siteId, $category)
            ->find($parentId);

        if (! $parent) {
            $this->fail('Dossier parent introuvable', 404);
        }

        return (int) $parent->id;
    }

    private function directoryId(int $siteId, string $category, int $directoryId): ?int
    {
        if ($directoryId <= 0) {
            return null;
        }

        $exists = CrmDocumentDirectory::query()
            ->forContext($siteId, $category)
            ->whereKey($directoryId)
            ->exists();

        if (! $exists) {
            $this->fail('Dossier introuvable', 404);
        }

        return $directoryId;
    }

    private function shortText(string $value, int $max, string $message): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            $this->fail($message, 400);
        }

        return $value;
    }

    private function optionalText(string $value, int $max, string $message): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            $this->fail($message, 400);
        }

        return $value;
    }

    private function visibility(string $value): string
    {
        return in_array($value, ['public', 'restricted', 'private'], true) ? $value : 'restricted';
    }

    /**
     * @return array{content: string, mime: string, originalName: string}|null
     */
    private function decodeUpload(string $dataUrl, string $originalName): ?array
    {
        $dataUrl = trim($dataUrl);

        if ($dataUrl === '') {
            return null;
        }

        if (! preg_match('/^data:([^;]+);base64,(.+)$/', $dataUrl, $matches)) {
            $this->fail('Format du fichier invalide', 400);
        }

        $mime = strtolower(trim($matches[1]));

        if (! isset(self::ALLOWED_MIMES[$mime])) {
            $this->fail('Type de fichier non autorise', 400);
        }

        $content = base64_decode($matches[2], true);

        if ($content === false) {
            $this->fail('Fichier illisible', 400);
        }

        if (strlen($content) > self::MAX_UPLOAD_BYTES) {
            $this->fail('Fichier trop lourd (20 Mo maximum)', 400);
        }

        $originalName = trim($originalName) !== '' ? trim($originalName) : 'document.'.self::ALLOWED_MIMES[$mime];
        $originalName = Str::limit(str_replace(['/', '\\'], '-', $originalName), 180, '');

        return [
            'content' => $content,
            'mime' => $mime,
            'originalName' => $originalName,
        ];
    }

    /**
     * @param  array{content: string, mime: string, originalName: string}  $upload
     */
    private function storeUpload(string $category, int $siteId, array $upload): string
    {
        $extension = self::ALLOWED_MIMES[$upload['mime']] ?? 'bin';
        $path = sprintf(
            '%s/%s/site-%d/%s.%s',
            self::STORAGE_PATH,
            $category,
            $siteId,
            (string) Str::uuid(),
            $extension,
        );

        Storage::disk(self::STORAGE_DISK)->put($path, $upload['content']);

        return $path;
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array{id: int, name: string}>
     */
    private function siteRows(array $siteIds): array
    {
        return CrmSite::query()
            ->active()
            ->whereIn('id', $siteIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CrmSite $site): array => [
                'id' => (int) $site->id,
                'name' => $site->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function directoryRow(CrmDocumentDirectory $directory): array
    {
        return [
            'id' => (int) $directory->id,
            'siteId' => (int) $directory->site_id,
            'category' => $directory->category,
            'parentId' => $directory->parent_id ? (int) $directory->parent_id : null,
            'name' => $directory->name,
            'description' => $directory->description,
            'visibility' => $directory->visibility,
            'sortOrder' => (int) $directory->sort_order,
            'createdBy' => $directory->creator?->name,
            'updatedAt' => $directory->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRow(CrmDocument $document, bool $canManage): array
    {
        return [
            'id' => (int) $document->id,
            'siteId' => (int) $document->site_id,
            'category' => $document->category,
            'directoryId' => $document->directory_id ? (int) $document->directory_id : null,
            'directoryName' => $document->directory?->name,
            'name' => $document->name,
            'description' => $document->description,
            'visibility' => $document->visibility,
            'mimeType' => $document->mime_type,
            'size' => (int) $document->size,
            'readableSize' => $document->readableSize(),
            'originalName' => $document->original_name,
            'sortOrder' => (int) $document->sort_order,
            'createdBy' => $document->creator?->name,
            'updatedAt' => $document->updated_at?->toIso8601String(),
            'downloadUrl' => route('crm.documents.download', $document),
            'canManage' => $canManage,
        ];
    }

    private function log(CrmUser $actor, string $action, string $subject): void
    {
        $this->activity->log($actor, $action, $subject);
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
