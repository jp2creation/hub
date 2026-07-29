<?php

namespace Modules\CrmAdministration\Services;

use App\Models\CrmMenuGroup;
use App\Models\CrmMenuItem;
use App\Models\CrmModule;
use App\Models\CrmPage;
use App\Models\CrmPermission;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmUserSiteModulePermission;
use App\Models\User;
use App\Support\CrmTheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Services\CrmActivityLogger;
use Modules\CrmCore\Services\CrmImageStorage;
use Modules\CrmCore\Support\CrmReferenceCache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdministrationService
{
    private const DEFAULT_PROFILE_PHOTO = '/assets/logo/logomark.png';

    private const ADMIN_NAVIGATION_PERMISSIONS = [
        'admin:users' => ['platform.manage_users', 'platform.manage_roles'],
        'admin:sites' => ['platform.manage_sites'],
        'admin:modules' => ['platform.manage_modules'],
        'admin:menu' => ['platform.manage_modules'],
        'admin:pages' => ['pages.manage'],
    ];

    public function __construct(
        private readonly CrmActivityLogger $activity,
        private readonly CrmAccessService $access,
        private readonly CrmImageStorage $images,
        private readonly AdminAccountService $accounts,
    ) {}

    public function ensureDefaults(): void
    {
        DB::transaction(function (): void {
            foreach ($this->permissionSeed() as [$name, $label, $group, $sortOrder]) {
                CrmPermission::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'label' => $label,
                        'group_label' => $group,
                        'sort_order' => $sortOrder,
                    ],
                );
            }

            $legacyPermissionId = CrmPermission::query()
                ->where('name', 'reservations.manage_sites')
                ->value('id');

            if ($legacyPermissionId) {
                DB::table('crm_user_permissions')->where('permission_id', $legacyPermissionId)->delete();
                CrmPermission::query()->whereKey($legacyPermissionId)->delete();
            }

            foreach ($this->moduleSeed() as [$name, $slug, $description, $routePath, $sortOrder, $active]) {
                $module = CrmModule::query()->firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'description' => $description,
                        'route_path' => $routePath,
                        'active' => $active,
                        'sort_order' => $sortOrder,
                    ],
                );

                if ($this->isDocumentCategoryModule($slug)) {
                    $module->forceFill([
                        'name' => $name,
                        'description' => $description,
                        'route_path' => $routePath,
                        'active' => $active,
                        'sort_order' => $sortOrder,
                    ])->save();
                }
            }

            CrmModule::query()
                ->where('slug', 'reservations')
                ->whereNull('menu_badge')
                ->update(['menu_badge' => 'Martin', 'show_menu_badge' => true]);

            foreach ($this->menuGroupSeed() as [$menuKey, $title, $sortOrder, $active]) {
                CrmMenuGroup::query()->updateOrCreate(
                    ['menu_key' => $menuKey],
                    ['title' => $title, 'active' => $active, 'sort_order' => $sortOrder],
                );
            }

            $this->deleteObsoleteMenuEntries();

            foreach ($this->staticMenuItemSeed() as $item) {
                $this->ensureMenuItem($item);
            }

            $this->normalizeStaticAdminMenuLabels();

            CrmModule::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['name', 'slug', 'active', 'sort_order'])
                ->each(function (CrmModule $module): void {
                    $this->ensureMenuItem([
                        'module:'.$module->slug,
                        $this->defaultModuleMenuGroup($module->slug),
                        $module->name,
                        CrmModule::defaultIconKey($module->slug),
                        (int) $module->sort_order,
                    ]);

                    if ($this->isDocumentCategoryModule($module->slug)) {
                        CrmMenuItem::query()
                            ->where('item_key', 'module:'.$module->slug)
                            ->update([
                                'group_key' => $this->defaultModuleMenuGroup($module->slug),
                                'label' => $module->name,
                                'active' => (bool) $module->active,
                                'sort_order' => (int) $module->sort_order,
                            ]);
                    }

                    if ($module->slug === 'conges') {
                        CrmMenuItem::query()
                            ->where('item_key', 'module:conges')
                            ->update([
                                'group_key' => 'apps',
                                'sort_order' => (int) $module->sort_order,
                            ]);
                    }

                    if (! $module->active) {
                        CrmMenuItem::query()
                            ->where('item_key', 'module:'.$module->slug)
                            ->update(['active' => false]);
                    }
                });

            $this->syncPagesMenuGroupVisibility();

            $siteIds = $this->ensureDefaultSites();
            $this->ensureDefaultUsers($siteIds);
            $this->ensureDefaultProfilePhotos();

            CrmReferenceCache::forgetSites();
            CrmReferenceCache::forgetModules();
            CrmReferenceCache::forgetPermissions();
            CrmReferenceCache::forgetUsers();
        });
    }

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label,sort_order', 'sites:id'])
            ->forAccount($user)
            ->where('active', true)
            ->first();

        if (! $actor) {
            $this->fail('Utilisateur HUB introuvable', 404);
        }

        return $actor;
    }

    public function profile(CrmUser $actor): array
    {
        return ['ok' => true, 'profile' => $this->profilePayload($actor)];
    }

    public function saveProfile(CrmUser $actor, array $data): array
    {
        $profile = $this->profilePayload($actor);
        $canEditIdentity = (bool) $profile['canEditIdentity'];

        $firstName = $canEditIdentity
            ? trim((string) ($data['firstName'] ?? $data['first_name'] ?? $profile['firstName']))
            : $profile['firstName'];
        $lastName = $canEditIdentity
            ? trim((string) ($data['lastName'] ?? $data['last_name'] ?? $profile['lastName']))
            : $profile['lastName'];
        $email = trim((string) ($data['email'] ?? $profile['email']));
        $phone = trim((string) ($data['phone'] ?? $profile['phone'] ?? ''));
        $bio = trim((string) ($data['bio'] ?? $profile['bio']));
        $photoUrl = (string) $profile['photoUrl'];
        $photoDataUrl = (string) ($data['photoDataUrl'] ?? $data['photo_data_url'] ?? '');

        if ($firstName === '') {
            $this->fail('Prenom obligatoire', 400);
        }

        if (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
            $this->fail('Prenom ou nom trop long', 400);
        }

        if ($email === '') {
            $this->fail('Adresse e-mail obligatoire', 400);
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $this->fail('Adresse e-mail invalide', 400);
        }

        if (mb_strlen($phone) > 40) {
            $this->fail('Telephone trop long', 400);
        }

        if ($email !== '') {
            $emailQuery = User::query()->where('email', $email);

            $emailQuery->whereKeyNot($actor->id);

            if ($emailQuery->exists()) {
                $this->fail('Adresse e-mail deja utilisee', 400);
            }
        }

        if (mb_strlen($bio) > 255) {
            $this->fail('Bio trop longue', 400);
        }

        if ($photoDataUrl !== '') {
            $photoUrl = $this->images->storeDataUrl($photoDataUrl, 'profiles', $photoUrl)['url'];
        }

        $updates = [
            'email' => $email,
            'phone' => $phone,
            'bio' => $bio,
            'photo_url' => $photoUrl,
        ];

        $displayName = $profile['displayName'];
        if ($canEditIdentity) {
            $displayName = trim($firstName.' '.$lastName) ?: $firstName;
            $updates['name'] = $displayName;
            $updates['first_name'] = $firstName;
            $updates['last_name'] = $lastName;
        }

        $actor->forceFill($updates)->save();

        $this->log($actor, 'modification profil', $email);

        return ['ok' => true, 'profile' => $this->profilePayload($actor->refresh())];
    }

    public function deleteSession(CrmUser $actor, array $data): array
    {
        $sessionId = trim((string) ($data['sessionId'] ?? $data['session_id'] ?? ''));

        if ($sessionId === '') {
            $this->fail('Session invalide', 400);
        }

        $accountId = (int) $actor->id;
        if ($accountId <= 0) {
            $this->fail('Utilisateur introuvable', 404);
        }

        $table = $this->sessionTable();
        if (! Schema::hasTable($table)) {
            $this->fail('Sessions indisponibles', 404);
        }

        $currentSessionId = $this->currentSessionId();
        $rawSessionId = DB::table($table)
            ->where('user_id', $accountId)
            ->pluck('id')
            ->first(fn (string $rawId): bool => $this->publicSessionId($rawId) === $sessionId);

        if (! $rawSessionId) {
            $this->fail('Session introuvable', 404);
        }

        if ($rawSessionId === $currentSessionId) {
            $this->fail('Impossible de supprimer la session actuelle', 400);
        }

        DB::table($table)
            ->where('user_id', $accountId)
            ->where('id', $rawSessionId)
            ->delete();

        $this->deleteStoredSession($rawSessionId);
        $this->log($actor, 'deconnexion appareil', $sessionId);

        return ['ok' => true, 'profile' => $this->profilePayload($actor->refresh())];
    }

    public function bootstrap(CrmUser $actor): array
    {
        $this->requireAny($actor, [
            'platform.manage_users',
            'platform.manage_modules',
            'platform.manage_sites',
            'platform.manage_roles',
        ]);

        $sites = CrmSite::query()
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();
        $modules = CrmModule::query()
            ->orderByDesc('active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $menuGroups = CrmMenuGroup::query()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
        $menuItems = CrmMenuItem::query()
            ->orderBy('group_key')
            ->orderByRaw('parent_item_key is not null')
            ->orderBy('parent_item_key')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
        $permissions = CrmPermission::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $pages = CrmPage::query()
            ->orderByDesc('active')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
        $users = CrmUser::query()
            ->with(['sites:id', 'modules:id', 'permissions:id,name', 'siteModulePermissions:id,user_id,site_id,module_id,permission_id'])
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        return [
            'ok' => true,
            'actor' => $this->actorRow($actor),
            'roles' => $this->roleProfiles(),
            'sites' => $sites->map(fn (CrmSite $site): array => $this->siteRow($site))->values()->all(),
            'modules' => $modules->map(fn (CrmModule $module): array => $this->moduleRow($module))->values()->all(),
            'menuGroups' => $menuGroups->map(fn (CrmMenuGroup $group): array => $this->menuGroupRow($group))->values()->all(),
            'menuItems' => $menuItems->map(fn (CrmMenuItem $item): array => $this->menuItemRow($item))->values()->all(),
            'permissions' => $permissions->map(fn (CrmPermission $permission): array => $this->permissionRow($permission))->values()->all(),
            'pages' => $pages->map(fn (CrmPage $page): array => $this->pageRow($page))->values()->all(),
            'users' => $users->map(fn (CrmUser $user): array => $this->userRow($user))->values()->all(),
        ];
    }

    public function saveMenuSettings(CrmUser $actor, array $data): array
    {
        $this->requireAny($actor, ['platform.manage_modules']);

        $groups = is_array($data['groups'] ?? null) ? $data['groups'] : [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        DB::transaction(function () use ($groups, $items): void {
            $groupKeys = CrmMenuGroup::query()->pluck('menu_key')->mapWithKeys(fn (string $key): array => [$key => true])->all();
            $itemKeys = CrmMenuItem::query()->pluck('item_key')->mapWithKeys(fn (string $key): array => [$key => true])->all();

            foreach ($groups as $groupData) {
                $menuKey = trim((string) ($groupData['menuKey'] ?? $groupData['menu_key'] ?? ''));
                if (! isset($groupKeys[$menuKey])) {
                    continue;
                }

                $title = trim((string) ($groupData['title'] ?? ''));
                if ($title === '') {
                    $this->fail('Titre de section obligatoire', 400);
                }
                if (mb_strlen($title) > 120) {
                    $this->fail('Titre de section trop long', 400);
                }

                CrmMenuGroup::query()
                    ->where('menu_key', $menuKey)
                    ->update([
                        'title' => $title,
                        'active' => $this->boolean($groupData['active'] ?? null, true),
                        'sort_order' => (int) ($groupData['sortOrder'] ?? $groupData['sort_order'] ?? 100),
                        'updated_at' => now(),
                    ]);
            }

            foreach ($items as $itemData) {
                $itemKey = trim((string) ($itemData['itemKey'] ?? $itemData['item_key'] ?? ''));
                if (! isset($itemKeys[$itemKey])) {
                    continue;
                }

                $groupKey = trim((string) ($itemData['groupKey'] ?? $itemData['group_key'] ?? ''));
                if (! isset($groupKeys[$groupKey])) {
                    $this->fail('Section de navigation invalide', 400);
                }

                $parentItemKey = trim((string) ($itemData['parentItemKey'] ?? $itemData['parent_item_key'] ?? ''));
                if ($parentItemKey !== '') {
                    if ($parentItemKey === $itemKey || ! isset($itemKeys[$parentItemKey])) {
                        $this->fail('Page parente invalide', 400);
                    }

                    if (CrmMenuItem::query()->where('parent_item_key', $itemKey)->exists()) {
                        $this->fail('Une page avec sous-pages ne peut pas devenir sous-page', 400);
                    }

                    $parent = CrmMenuItem::query()
                        ->where('item_key', $parentItemKey)
                        ->first(['item_key', 'group_key', 'parent_item_key']);

                    if (! $parent || filled($parent->parent_item_key)) {
                        $this->fail('Page parente invalide', 400);
                    }

                    $groupKey = $parent->group_key;
                }

                $iconKey = trim((string) ($itemData['iconKey'] ?? $itemData['icon_key'] ?? ''));
                if ($iconKey !== '' && ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $iconKey)) {
                    $this->fail('Icone de menu invalide', 400);
                }

                $label = trim((string) ($itemData['label'] ?? ''));
                if ($label === '') {
                    $this->fail('Titre de page obligatoire', 400);
                }
                if (mb_strlen($label) > 160) {
                    $this->fail('Titre de page trop long', 400);
                }

                CrmMenuItem::query()
                    ->where('item_key', $itemKey)
                    ->update([
                        'group_key' => $groupKey,
                        'parent_item_key' => $parentItemKey !== '' ? $parentItemKey : null,
                        'icon_key' => $iconKey,
                        'label' => $label,
                        'active' => $this->boolean($itemData['active'] ?? null, true),
                        'sort_order' => (int) ($itemData['sortOrder'] ?? $itemData['sort_order'] ?? 100),
                        'updated_at' => now(),
                    ]);
            }
        });

        CrmReferenceCache::forgetModules();
        $this->log($actor, 'modification navigation', 'configuration navigation laterale');

        return ['ok' => true];
    }

    public function pagesBootstrap(CrmUser $actor): array
    {
        $this->requireAny($actor, ['pages.manage', 'platform.manage_modules']);

        $pages = CrmPage::query()
            ->orderByDesc('active')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return ['ok' => true, 'pages' => $pages->map(fn (CrmPage $page): array => $this->pageRow($page))->values()->all()];
    }

    public function savePage(CrmUser $actor, array $data): array
    {
        $this->requireAny($actor, ['pages.manage', 'platform.manage_modules']);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $title = trim((string) ($data['title'] ?? ''));
            $excerpt = trim((string) ($data['excerpt'] ?? ''));
            $content = trim((string) ($data['content'] ?? ''));
            $iconKey = $this->iconKey((string) ($data['iconKey'] ?? $data['icon_key'] ?? 'article'));

            if ($title === '') {
                $this->fail('Titre de page obligatoire', 400);
            }
            if (mb_strlen($title) > 160) {
                $this->fail('Titre de page trop long', 400);
            }
            if (mb_strlen($excerpt) > 255) {
                $this->fail('Resume trop long', 400);
            }
            if ($content === '') {
                $this->fail('Contenu obligatoire', 400);
            }

            $page = $id > 0 ? CrmPage::query()->lockForUpdate()->find($id) : new CrmPage;
            if ($id > 0 && ! $page) {
                $this->fail('Page introuvable', 404);
            }

            $page->fill([
                'title' => $title,
                'slug' => CrmPage::uniqueSlug((string) ($data['slug'] ?? $title), $id ?: null),
                'excerpt' => $excerpt,
                'content' => $content,
                'icon_key' => $iconKey,
                'active' => $this->boolean($data['active'] ?? null, true),
                'show_in_menu' => $this->boolean($data['showInMenu'] ?? $data['show_in_menu'] ?? null, true),
                'sort_order' => (int) ($data['sortOrder'] ?? $data['sort_order'] ?? 100),
            ])->save();

            $this->log($actor, $id > 0 ? 'modification page HUB' : 'creation page HUB', $title);

            return ['ok' => true, 'page' => $this->pageRow($page->refresh())];
        });
    }

    public function deletePage(CrmUser $actor, array $data): array
    {
        $this->requireAny($actor, ['pages.manage', 'platform.manage_modules']);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                $this->fail('Page invalide', 400);
            }

            $page = CrmPage::query()->lockForUpdate()->find($id);
            if (! $page) {
                $this->fail('Page introuvable', 404);
            }

            $title = $page->title;
            $page->delete();
            $this->log($actor, 'suppression page HUB', $title);

            return ['ok' => true, 'id' => $id];
        });
    }

    public function saveSite(CrmUser $actor, array $data): array
    {
        $this->requireAny($actor, ['platform.manage_sites', 'platform.manage_modules']);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $name = trim((string) ($data['name'] ?? ''));
            $address = trim((string) ($data['address'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));
            $email = trim((string) ($data['email'] ?? ''));
            $photoDataUrl = (string) ($data['photoDataUrl'] ?? $data['photo_data_url'] ?? '');
            $removePhoto = $this->boolean($data['removePhoto'] ?? $data['remove_photo'] ?? null, false);

            if ($name === '') {
                $this->fail('Nom du site obligatoire', 400);
            }
            if (mb_strlen($name) > 120) {
                $this->fail('Nom du site trop long', 400);
            }
            if (mb_strlen($address) > 255) {
                $this->fail('Adresse trop longue', 400);
            }
            if (mb_strlen($phone) > 40) {
                $this->fail('Telephone trop long', 400);
            }
            if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
                $this->fail('Email invalide', 400);
            }

            $site = $id > 0 ? CrmSite::query()->lockForUpdate()->find($id) : new CrmSite;
            if ($id > 0 && ! $site) {
                $this->fail('Site introuvable', 404);
            }

            $color = $this->siteColor((string) ($data['color'] ?? $site?->color ?? ''));
            $morningStart = $this->normalizeTime($data, 'morningStart', 'morning_start', $site?->morning_start ?: '07:30');
            $morningEnd = $this->normalizeTime($data, 'morningEnd', 'morning_end', $site?->morning_end ?: '12:00');
            $afternoonStart = $this->normalizeTime($data, 'afternoonStart', 'afternoon_start', $site?->afternoon_start ?: '13:30');
            $afternoonEnd = $this->normalizeTime($data, 'afternoonEnd', 'afternoon_end', $site?->afternoon_end ?: '17:30');
            $photoUrl = trim((string) ($site?->photo_url ?? ''));

            if ($removePhoto) {
                $photoUrl = '';
            }

            if ($photoDataUrl !== '') {
                $photoUrl = $this->images->storeDataUrl($photoDataUrl, 'sites', $photoUrl, [
                    'label' => 'Photo du site',
                    'imageMaxWidth' => 1600,
                    'imageMaxHeight' => 1200,
                    'thumbnailSize' => 480,
                ])['url'];
            }

            if (
                $this->minutes($morningStart) >= $this->minutes($morningEnd)
                || $this->minutes($afternoonStart) >= $this->minutes($afternoonEnd)
                || $this->minutes($morningEnd) > $this->minutes($afternoonStart)
            ) {
                $this->fail('Plages horaires incoherentes', 400);
            }

            $site->fill([
                'name' => $name,
                'active' => $this->boolean($data['active'] ?? null, true),
                'address' => $address !== '' ? $address : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'color' => $color,
                'photo_url' => $photoUrl !== '' ? $photoUrl : null,
                'morning_start' => $morningStart,
                'morning_end' => $morningEnd,
                'afternoon_start' => $afternoonStart,
                'afternoon_end' => $afternoonEnd,
            ])->save();

            $this->log($actor, $id > 0 ? 'modification site' : 'creation site', $name);

            return ['ok' => true, 'id' => $site->id];
        });
    }

    public function deleteSite(CrmUser $actor, array $data): array
    {
        $this->requireAny($actor, ['platform.manage_sites']);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                $this->fail('Site invalide', 400);
            }

            $site = CrmSite::query()->with(['vehicles', 'reservations', 'equipmentItems', 'equipmentRentals'])->find($id);
            if (! $site) {
                $this->fail('Site introuvable', 404);
            }

            $used = $site->vehicles->isNotEmpty()
                || $site->reservations->isNotEmpty()
                || $site->equipmentItems->isNotEmpty()
                || $site->equipmentRentals->isNotEmpty();
            $name = $site->name;

            if ($used) {
                $site->delete();
            } else {
                $site->forceDelete();
            }

            $this->log($actor, $used ? 'archivage site' : 'suppression site', $name);

            return ['ok' => true, 'id' => $id, 'archived' => $used];
        });
    }

    public function saveModule(CrmUser $actor, array $data): array
    {
        $this->requireAny($actor, ['platform.manage_modules']);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $name = trim((string) ($data['name'] ?? ''));
            $slugSource = trim((string) ($data['slug'] ?? '')) ?: $name;
            $routePath = trim((string) ($data['routePath'] ?? $data['route_path'] ?? ''));
            $menuBadge = trim((string) ($data['menuBadge'] ?? $data['menu_badge'] ?? ''));

            if ($name === '') {
                $this->fail('Nom du module obligatoire', 400);
            }
            if (mb_strlen($menuBadge) > 40) {
                $this->fail('Badge menu trop long', 400);
            }

            $module = $id > 0 ? CrmModule::query()->lockForUpdate()->find($id) : new CrmModule;
            if ($id > 0 && ! $module) {
                $this->fail('Module introuvable', 404);
            }

            $slug = CrmModule::uniqueSlug($slugSource, $id ?: null);
            if ($routePath === '') {
                $routePath = '/'.$slug;
            }

            $module->fill([
                'name' => $name,
                'slug' => $slug,
                'description' => trim((string) ($data['description'] ?? '')),
                'route_path' => $routePath,
                'menu_badge' => $menuBadge !== '' ? $menuBadge : null,
                'show_menu_badge' => $this->boolean($data['showMenuBadge'] ?? $data['show_menu_badge'] ?? null, false),
                'active' => $this->boolean($data['active'] ?? null, true),
                'sort_order' => (int) ($data['sortOrder'] ?? $data['sort_order'] ?? 100),
            ])->save();

            $this->log($actor, $id > 0 ? 'modification module' : 'creation module', $name);

            return ['ok' => true, 'id' => $module->id];
        });
    }

    public function saveUser(CrmUser $actor, array $data): array
    {
        $this->requireAny($actor, ['platform.manage_users', 'platform.manage_roles']);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $name = trim((string) ($data['name'] ?? ''));
            $role = trim((string) ($data['role'] ?? 'user'));
            $hasFirstName = array_key_exists('firstName', $data) || array_key_exists('first_name', $data);
            $hasLastName = array_key_exists('lastName', $data) || array_key_exists('last_name', $data);
            $hasEmail = array_key_exists('email', $data);
            $hasPhone = array_key_exists('phone', $data);
            $firstName = $hasFirstName ? trim((string) ($data['firstName'] ?? $data['first_name'] ?? '')) : '';
            $lastName = $hasLastName ? trim((string) ($data['lastName'] ?? $data['last_name'] ?? '')) : '';
            $email = $hasEmail ? trim((string) $data['email']) : '';
            $phone = $hasPhone ? trim((string) $data['phone']) : '';

            if ($name === '') {
                $this->fail('Nom utilisateur obligatoire', 400);
            }

            if (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
                $this->fail('Prenom ou nom trop long', 400);
            }

            if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
                $this->fail('Adresse e-mail invalide', 400);
            }

            if (mb_strlen($phone) > 40) {
                $this->fail('Telephone trop long', 400);
            }

            if (! in_array($role, ['admin', 'responsable', 'user', 'blocked'], true)) {
                $role = 'user';
            }

            $primarySiteId = max(0, (int) ($data['primarySiteId'] ?? $data['primary_site_id'] ?? $data['siteId'] ?? $data['site_id'] ?? 0));
            $siteIds = $this->requestedSiteIds($data, $primarySiteId);
            $moduleIds = $this->validIds($data['moduleIds'] ?? [], CrmModule::class);
            $permissionIds = $this->validIds($data['permissionIds'] ?? [], CrmPermission::class);
            $accessRules = $this->accessRulesFromPayload($data['accessRules'] ?? []);

            if ($role === 'blocked') {
                $moduleIds = [];
                $permissionIds = [];
                $accessRules = [];
            }

            if ($siteIds === []) {
                $firstSiteId = CrmSite::query()
                    ->where('active', true)
                    ->orderBy('id')
                    ->value('id');

                $siteIds = $firstSiteId ? [(int) $firstSiteId] : [];
            }

            $user = $id > 0 ? CrmUser::query()->lockForUpdate()->find($id) : new CrmUser;
            if ($id > 0 && ! $user) {
                $this->fail('Utilisateur introuvable', 404);
            }

            if ($email !== '') {
                $emailQuery = User::query()->where('email', $email);

                if ($user->exists) {
                    $emailQuery->whereKeyNot($user->id);
                }

                if ($emailQuery->exists()) {
                    $this->fail('Adresse e-mail deja utilisee', 400);
                }
            }

            $password = $this->requestedPassword($data);
            if ($password !== null && ! $this->hasPermission($actor, 'platform.manage_users')) {
                $this->fail('Droit administration insuffisant', 403);
            }

            $updates = [
                'name' => $name,
                'role' => $role,
                'active' => $this->boolean($data['active'] ?? null, true),
                'photo_url' => trim((string) $user->photo_url) ?: self::DEFAULT_PROFILE_PHOTO,
            ];

            if ($hasFirstName) {
                $updates['first_name'] = $firstName !== '' ? $firstName : null;
            }

            if ($hasLastName) {
                $updates['last_name'] = $lastName !== '' ? $lastName : null;
            }

            if ($hasEmail && $email !== '') {
                $updates['email'] = $email;
            }

            if ($hasPhone) {
                $updates['phone'] = $phone !== '' ? $phone : null;
            }

            $user->fill($updates)->save();

            $this->syncSites($user, $siteIds);
            $user->modules()->sync($moduleIds);
            $user->permissions()->sync($permissionIds);
            $this->syncAccessRules($user, $accessRules);
            $passwordUpdated = $this->updateUserPassword($user, $data, $password);
            CrmReferenceCache::forgetUsers();

            $this->log($actor, $id > 0 ? 'modification utilisateur' : 'creation utilisateur', $name);
            if ($passwordUpdated) {
                $this->log($actor, 'modification mot de passe utilisateur', $name);
            }

            return ['ok' => true, 'id' => $user->id];
        });
    }

    private function requestedPassword(array $data): ?string
    {
        if (! array_key_exists('password', $data)) {
            return null;
        }

        $password = (string) $data['password'];

        return $password !== '' ? $password : null;
    }

    private function updateUserPassword(CrmUser $user, array $data, ?string $password): bool
    {
        if ($password === null) {
            return false;
        }

        $confirmation = (string) ($data['passwordConfirmation'] ?? $data['password_confirmation'] ?? '');
        if ($password !== $confirmation) {
            $this->fail('Confirmation du mot de passe invalide', 400);
        }

        try {
            $this->accounts->assertStrongPassword($password);
        } catch (ValidationException) {
            $this->fail('Mot de passe trop faible', 400);
        }

        $user->forceFill([
            'password' => $password,
        ])->save();

        return true;
    }

    private function ensureDefaultSites(): array
    {
        $existing = CrmSite::query()
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($existing->isNotEmpty()) {
            return $existing->pluck('id', 'name')->map(fn ($id): int => (int) $id)->all();
        }

        $siteIds = [];
        foreach (['Palissy', 'Bordeaux', 'Pessac', 'Glotin', 'Pastel'] as $name) {
            $site = CrmSite::query()->create([
                'name' => $name,
                'active' => true,
                'morning_start' => '07:30:00',
                'morning_end' => '12:00:00',
                'afternoon_start' => '13:30:00',
                'afternoon_end' => '17:30:00',
            ]);
            $siteIds[$name] = $site->id;
        }

        return $siteIds;
    }

    private function ensureDefaultUsers(array $siteIds): void
    {
        if ($siteIds === []) {
            return;
        }

        $defaultSiteId = (int) ($siteIds['Palissy'] ?? reset($siteIds));
        $profiles = collect($this->roleProfiles())->keyBy('key');
        $users = [
            ['J-Philippe', 'admin', array_values($siteIds)],
            ['Christophe L', 'user', [$defaultSiteId]],
            ['Remi G', 'user', [$defaultSiteId]],
            ['Samy I', 'user', [$defaultSiteId]],
            ['Philippe P', 'responsable', [$defaultSiteId]],
            ['Jeremy L', 'blocked', [$defaultSiteId]],
        ];

        foreach ($users as [$name, $role, $sites]) {
            $user = CrmUser::query()->firstOrCreate(
                ['name' => $name],
                ['role' => $role, 'active' => true, 'photo_url' => self::DEFAULT_PROFILE_PHOTO],
            );

            if (! trim((string) $user->photo_url)) {
                $user->forceFill(['photo_url' => self::DEFAULT_PROFILE_PHOTO])->save();
            }

            $profile = $profiles->get($role, $profiles->get('user'));

            if ($role === 'blocked') {
                $user->modules()->sync([]);
                $user->permissions()->sync([]);

                continue;
            }

            if ($user->sites()->count() === 0) {
                $this->syncSites($user, array_map('intval', $sites));
            }

            $moduleIds = CrmModule::query()
                ->whereIn('slug', $profile['moduleSlugs'])
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $permissionIds = CrmPermission::query()
                ->whereIn('name', $profile['permissions'])
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($user->modules()->count() === 0) {
                $user->modules()->sync($moduleIds);
            } elseif ($role === 'admin') {
                $user->modules()->syncWithoutDetaching($moduleIds);
            }

            if ($user->permissions()->count() === 0) {
                $user->permissions()->sync($permissionIds);
            } elseif ($role === 'admin') {
                $user->permissions()->syncWithoutDetaching($permissionIds);
            }
        }

        CrmReferenceCache::forgetUsers();
    }

    private function ensureDefaultProfilePhotos(): void
    {
        $updated = CrmUser::query()
            ->whereNull('photo_url')
            ->orWhere('photo_url', '')
            ->update(['photo_url' => self::DEFAULT_PROFILE_PHOTO]);

        if ($updated > 0) {
            CrmReferenceCache::forgetUsers();
        }
    }

    private function ensureMenuItem(array $item): void
    {
        [$itemKey, $groupKey, $label, $iconKey, $sortOrder, $parentItemKey] = array_pad($item, 6, null);

        $menuItem = CrmMenuItem::query()->firstOrNew(['item_key' => $itemKey]);
        $menuItem->fill([
            'group_key' => $menuItem->exists ? $menuItem->group_key : $groupKey,
            'parent_item_key' => $menuItem->exists ? $menuItem->parent_item_key : $parentItemKey,
            'icon_key' => $menuItem->icon_key ?: $iconKey,
            'label' => $menuItem->exists ? $menuItem->label : $label,
            'active' => $menuItem->exists ? $menuItem->active : true,
            'sort_order' => $menuItem->exists ? $menuItem->sort_order : $sortOrder,
        ]);
        $menuItem->saveQuietly();
    }

    private function normalizeStaticAdminMenuLabels(): void
    {
        CrmMenuItem::query()
            ->where('item_key', 'admin:menu')
            ->where('label', 'Menu gauche')
            ->update(['label' => 'Navigation']);
    }

    private function deleteObsoleteMenuEntries(): void
    {
        $prefixes = ['dashboard:', 'app:', 'feature:', 'auth:', 'page:', 'form:', 'table:', 'chart:'];

        CrmMenuItem::query()
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('item_key', 'like', $prefix.'%');
                }
            })
            ->delete();

        CrmMenuGroup::query()
            ->whereIn('menu_key', ['dashboards', 'authentication', 'forms', 'tables', 'charts'])
            ->delete();
    }

    private function defaultModuleMenuGroup(string $slug): string
    {
        if ($slug === 'dashboard') {
            return 'home';
        }

        if (in_array($slug, ['controle-caisse', 'demandes-acompte', 'remise-cheques', 'addvance'], true)) {
            return 'accounting';
        }

        if ($this->isDocumentCategoryModule($slug)) {
            return 'documents';
        }

        return in_array($slug, ['reservations', 'locations-materiel', 'equipes', 'conges', 'pilotage-commercial', 'tournees-representants', 'tapis-romus'], true)
            ? 'apps'
            : 'internal';
    }

    private function isDocumentCategoryModule(string $slug): bool
    {
        return in_array($slug, ['documents-promo', 'documents-fiches-techniques', 'documents-procedures'], true);
    }

    private function syncPagesMenuGroupVisibility(): void
    {
        CrmMenuGroup::query()
            ->where('menu_key', 'pages')
            ->update([
                'active' => CrmMenuItem::query()
                    ->where('group_key', 'pages')
                    ->where('active', true)
                    ->exists(),
            ]);
    }

    private function permissionSeed(): array
    {
        return [
            ['reservations.view', 'Voir les reservations', 'Reservations', 10],
            ['reservations.create', 'Creer une reservation', 'Reservations', 20],
            ['reservations.update_own', 'Modifier ses reservations', 'Reservations', 30],
            ['reservations.update_any', 'Modifier toutes les reservations', 'Reservations', 40],
            ['reservations.delete_own', 'Supprimer ses reservations', 'Reservations', 50],
            ['reservations.delete_any', 'Supprimer toutes les reservations', 'Reservations', 60],
            ['reservations.manage_vehicles', 'Gerer les vehicules du site', 'Reservations', 70],
            ['equipment_rentals.view', 'Voir les locations materiel', 'Location materiel', 80],
            ['equipment_rentals.create', 'Creer une location materiel', 'Location materiel', 90],
            ['equipment_rentals.update_own', 'Modifier ses locations materiel', 'Location materiel', 100],
            ['equipment_rentals.update_any', 'Modifier toutes les locations materiel', 'Location materiel', 110],
            ['equipment_rentals.delete_own', 'Supprimer ses locations materiel', 'Location materiel', 120],
            ['equipment_rentals.delete_any', 'Supprimer toutes les locations materiel', 'Location materiel', 130],
            ['equipment_rentals.manage_items', 'Gerer le materiel de location', 'Location materiel', 140],
            ['conges.view', 'Voir les congés et absences', 'Congés & Absences', 145],
            ['conges.manage', 'Gérer les congés et absences', 'Congés & Absences', 146],
            ['conges.manage_types', "Gérer les types d'absence", 'Congés & Absences', 147],
            ['controle_caisse.view', 'Voir le controle caisse', 'Controle caisse', 148],
            ['controle_caisse.manage', 'Gerer le controle caisse', 'Controle caisse', 149],
            ['check_remittances.view', 'Voir les remises de chèques', 'Remise de chèques', 150],
            ['check_remittances.manage', 'Gérer les remises de chèques', 'Remise de chèques', 151],
            ['deposit_requests.view', "Voir les demandes d'acompte", "Demande d'acompte", 152],
            ['deposit_requests.create', "Créer une demande d'acompte", "Demande d'acompte", 153],
            ['deposit_requests.manage', "Gérer les demandes d'acompte", "Demande d'acompte", 154],
            ['deposit_requests.validate', "Valider les demandes d'acompte", "Demande d'acompte", 155],
            ['teams.view', 'Voir les equipes', 'Equipe', 156],
            ['documents.view', 'Voir les documents', 'Documents', 157],
            ['documents.manage', 'Gerer les documents', 'Documents', 158],
            ['sales_tours.view', 'Voir les rapports de visite', 'Rapport de visite', 159],
            ['sales_tours.create', 'Creer un rapport de visite', 'Rapport de visite', 160],
            ['sales_tours.report', 'Renseigner les rapports de visite', 'Rapport de visite', 161],
            ['sales_tours.manage', 'Gerer tous les rapports de visite', 'Rapport de visite', 162],
            ['sales.view', 'Voir le pilotage commercial', 'Pilotage commercial', 163],
            ['sales.sync', 'Synchroniser les donnees commerciales', 'Pilotage commercial', 164],
            ['sales.manage', 'Gerer les objectifs commerciaux', 'Pilotage commercial', 165],
            ['sales.commissions', 'Gerer les commissions commerciales', 'Pilotage commercial', 166],
            ['platform.manage_sites', 'Gerer les sites', 'Administration', 160],
            ['platform.manage_users', 'Gerer les utilisateurs', 'Administration', 170],
            ['platform.manage_roles', 'Gerer les roles', 'Administration', 180],
            ['platform.manage_modules', 'Gerer les modules', 'Administration', 190],
            ['pages.view', 'Voir les pages HUB', 'Pages HUB', 200],
            ['pages.manage', 'Gerer les pages HUB', 'Pages HUB', 210],
        ];
    }

    private function roleProfiles(): array
    {
        return [
            [
                'key' => 'user',
                'label' => 'Employe',
                'description' => 'Reservation et location sur les sites rattaches, suppression de ses propres demandes.',
                'permissions' => ['reservations.view', 'reservations.create', 'reservations.update_own', 'reservations.delete_own', 'equipment_rentals.view', 'equipment_rentals.create', 'equipment_rentals.update_own', 'equipment_rentals.delete_own', 'conges.view', 'teams.view', 'sales_tours.view', 'sales_tours.create', 'sales_tours.report', 'sales.view', 'controle_caisse.view', 'deposit_requests.view', 'deposit_requests.create'],
                'moduleSlugs' => ['dashboard', 'reservations', 'locations-materiel', 'equipes', 'tournees-representants', 'pilotage-commercial', 'conges', 'controle-caisse', 'demandes-acompte', 'addvance'],
            ],
            [
                'key' => 'responsable',
                'label' => 'Responsable site',
                'description' => 'Gestion des reservations, vehicules et locations materiel des sites rattaches.',
                'permissions' => ['reservations.view', 'reservations.create', 'reservations.update_own', 'reservations.update_any', 'reservations.delete_own', 'reservations.delete_any', 'reservations.manage_vehicles', 'equipment_rentals.view', 'equipment_rentals.create', 'equipment_rentals.update_own', 'equipment_rentals.update_any', 'equipment_rentals.delete_own', 'equipment_rentals.delete_any', 'equipment_rentals.manage_items', 'conges.view', 'conges.manage', 'conges.manage_types', 'teams.view', 'sales_tours.view', 'sales_tours.create', 'sales_tours.report', 'sales_tours.manage', 'sales.view', 'sales.sync', 'sales.manage', 'sales.commissions', 'controle_caisse.view', 'controle_caisse.manage', 'deposit_requests.view', 'deposit_requests.create', 'deposit_requests.manage', 'check_remittances.view', 'check_remittances.manage'],
                'moduleSlugs' => ['dashboard', 'reservations', 'locations-materiel', 'equipes', 'tournees-representants', 'pilotage-commercial', 'conges', 'controle-caisse', 'demandes-acompte', 'remise-cheques', 'addvance'],
            ],
            [
                'key' => 'admin',
                'label' => 'Administrateur',
                'description' => 'Acces global aux sites, modules, utilisateurs, roles et permissions.',
                'permissions' => array_map(fn (array $permission): string => $permission[0], $this->permissionSeed()),
                'moduleSlugs' => ['dashboard', 'reservations', 'locations-materiel', 'equipes', 'tournees-representants', 'pilotage-commercial', 'pages-crm', 'administration', 'conges', 'controle-caisse', 'demandes-acompte', 'remise-cheques', 'addvance', 'documents-promo', 'documents-fiches-techniques', 'documents-procedures', 'tapis-romus'],
            ],
            [
                'key' => 'blocked',
                'label' => 'Sans acces',
                'description' => 'Aucun module ni action disponible.',
                'permissions' => [],
                'moduleSlugs' => [],
            ],
        ];
    }

    private function moduleSeed(): array
    {
        return [
            ['Tableau de bord', 'dashboard', 'Synthese et acces rapides du HUB', '/', 0, true],
            ['Réservations véhicules', 'reservations', 'Planning et réservations des véhicules', '/reservations', 10, true],
            ['Location matériel', 'locations-materiel', 'Planning et locations du matériel interne', '/locations-materiel', 15, true],
            ['Équipe', 'equipes', 'Annuaire des membres par site', '/equipes', 16, true],
            ['Congés & Absences', 'conges', 'Planning et gestion des congés, absences et arrêts', '/conges', 17, true],
            ['Rapport de visite', 'tournees-representants', 'Planning, visites clients et rapports de visite', '/rapport-visite', 18, true],
            ['Pilotage commercial', 'pilotage-commercial', 'Objectifs, chiffre d affaires, factures et commissions commerciales', '/pilotage-commercial', 19, true],
            ['Pages HUB', 'pages-crm', 'Pages internes modifiables depuis le HUB', '/pages-crm', 18, true],
            ['Administration', 'administration', 'Gestion des sites, modules, utilisateurs et rôles', '/administration', 20, true],
            ['Contrôle caisse', 'controle-caisse', 'Contrôle journalier de caisse, reports, écarts et justificatifs', '/controle-caisse', 25, true],
            ['Demande d\'acompte', 'demandes-acompte', 'Demandes d\'acompte et validation par la comptabilité', '/demandes-acompte', 26, true],
            ['Remise de chèques', 'remise-cheques', 'Remises de chèques, photos, contrôle des montants et impression PDF', '/remise-cheques', 27, true],
            ['Addvance', 'addvance', 'Accès externe Addvance Solutions', 'https://martinsols.addvancesolutions.fr', 28, true],
            ['Promo', 'documents-promo', 'Documents commerciaux et promotions.', '/documents/promo', 241, true],
            ['Fiches techniques', 'documents-fiches-techniques', 'Fiches techniques produits et materiel.', '/documents/fiches-techniques', 242, true],
            ['Procédures', 'documents-procedures', 'Procédures internes du HUB.', '/documents/procedures', 243, true],
            ['Planning', 'planning', 'Planning interne par site', '/planning', 30, false],
            ['Documents internes', 'documents', 'Procédures et documents partagés', '/documents', 40, false],
            ['Demandes internes', 'demandes', 'Demandes et validations internes', '/demandes', 50, false],
            ['Tapis ROMUS', 'tapis-romus', 'Bon de commande et mesures tapis ROMUS', '/tapis-romus', 60, true],
        ];
    }

    private function menuGroupSeed(): array
    {
        return [
            ['home', 'Accueil', 0, true],
            ['apps', 'Applications HUB', 10, true],
            ['accounting', 'Comptabilité', 18, true],
            ['documents', 'Documents', 19, true],
            ['internal', 'Administration', 20, true],
            ['pages', 'Pages internes', 30, false],
        ];
    }

    private function staticMenuItemSeed(): array
    {
        return [
            ['admin:users', 'internal', 'Utilisateurs', 'users', 10, 'module:administration'],
            ['admin:sites', 'internal', 'Sites', 'category', 20, 'module:administration'],
            ['admin:modules', 'internal', 'Modules', 'package', 30, 'module:administration'],
            ['admin:menu', 'internal', 'Navigation', 'settings', 40, 'module:administration'],
            ['admin:pages', 'internal', 'Pages HUB', 'article', 50, 'module:administration'],
        ];
    }

    private function actorRow(CrmUser $actor): array
    {
        $actor->loadMissing(['permissions:id,name,sort_order', 'modules:id,slug,active', 'sites:id']);

        return [
            'id' => $actor->id,
            'name' => $actor->name,
            'firstName' => $actor->first_name,
            'lastName' => $actor->last_name,
            'email' => $actor->email,
            'phone' => $actor->phone,
            'bio' => $actor->bio,
            'photoUrl' => trim((string) $actor->photo_url) ?: self::DEFAULT_PROFILE_PHOTO,
            'role' => $actor->role,
            'active' => (bool) $actor->active,
            'permissions' => $this->permissionNames($actor),
        ];
    }

    private function siteRow(CrmSite $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'active' => (bool) $site->active,
            'address' => trim((string) $site->address),
            'phone' => trim((string) $site->phone),
            'email' => trim((string) $site->email),
            'color' => $this->siteColor((string) $site->color),
            'photoUrl' => $this->images->normalizePublicUrl($site->photo_url),
            'hours' => [
                'morningStart' => $this->time5($site->morning_start, '07:30'),
                'morningEnd' => $this->time5($site->morning_end, '12:00'),
                'afternoonStart' => $this->time5($site->afternoon_start, '13:30'),
                'afternoonEnd' => $this->time5($site->afternoon_end, '17:30'),
            ],
        ];
    }

    private function moduleRow(CrmModule $module): array
    {
        return [
            'id' => $module->id,
            'name' => $module->name,
            'slug' => $module->slug,
            'description' => $module->description ?? '',
            'routePath' => $module->route_path ?? '',
            'menuBadge' => $module->menu_badge ?? '',
            'showMenuBadge' => (bool) $module->show_menu_badge,
            'active' => (bool) $module->active,
            'sortOrder' => (int) $module->sort_order,
        ];
    }

    private function menuGroupRow(CrmMenuGroup $group): array
    {
        return [
            'id' => $group->id,
            'menuKey' => $group->menu_key,
            'title' => $group->title,
            'active' => (bool) $group->active,
            'sortOrder' => (int) $group->sort_order,
        ];
    }

    private function menuItemRow(CrmMenuItem $item): array
    {
        return [
            'id' => $item->id,
            'itemKey' => $item->item_key,
            'groupKey' => $item->group_key,
            'parentItemKey' => $item->parent_item_key,
            'iconKey' => $item->icon_key ?? '',
            'label' => $item->label,
            'active' => (bool) $item->active,
            'sortOrder' => (int) $item->sort_order,
        ];
    }

    private function permissionRow(CrmPermission $permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'label' => $permission->label,
            'group' => $permission->group_label,
            'sortOrder' => (int) $permission->sort_order,
        ];
    }

    private function pageRow(CrmPage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'excerpt' => $page->excerpt ?? '',
            'content' => $page->content ?? '',
            'iconKey' => $page->icon_key ?: 'article',
            'active' => (bool) $page->active,
            'showInMenu' => (bool) $page->show_in_menu,
            'sortOrder' => (int) $page->sort_order,
            'routePath' => $page->route_path,
        ];
    }

    private function userRow(CrmUser $user): array
    {
        $user->loadMissing(['sites:id', 'modules:id', 'permissions:id,name,sort_order', 'siteModulePermissions:id,user_id,site_id,module_id,permission_id']);
        $siteIds = $user->sites->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'active' => (bool) $user->active,
            'hasAccount' => true,
            'primarySiteId' => $this->primarySiteId($user, $siteIds),
            'siteIds' => $siteIds,
            'moduleIds' => $user->modules->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'permissionIds' => $user->permissions->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'permissions' => $this->permissionNames($user),
            'effectiveSiteIds' => $this->access->siteIds($user),
            'effectiveModuleIds' => $this->access->moduleIds($user),
            'effectivePermissions' => $this->access->permissionNames($user),
            'accessRules' => $this->access->accessRules($user),
        ];
    }

    private function profilePayload(CrmUser $user): array
    {
        $user->loadMissing(['permissions:id,name,sort_order', 'account:id,name,email']);

        $firstName = trim((string) $user->first_name);
        $lastName = trim((string) $user->last_name);
        $accountName = trim((string) ($user->account?->name ?? ''));
        $rawName = trim((string) $user->name) ?: $accountName;

        if ($firstName === '' && $rawName !== '') {
            if ($rawName === 'J-Philippe') {
                $firstName = 'Jean-Philippe';
            } else {
                $parts = preg_split('/\s+/', $rawName, 2);
                $firstName = trim((string) ($parts[0] ?? ''));
                if ($lastName === '') {
                    $lastName = trim((string) ($parts[1] ?? ''));
                }
            }
        }

        $displayName = trim($firstName.' '.$lastName);
        if ($displayName === '') {
            $displayName = $rawName !== '' ? $rawName : 'Jean-Philippe';
        }

        return [
            'id' => $user->id,
            'name' => $rawName,
            'displayName' => $displayName,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => trim((string) $user->email) ?: (trim((string) ($user->account?->email ?? '')) ?: 'contact@jp2creation.fr'),
            'phone' => trim((string) $user->phone),
            'bio' => trim((string) $user->bio) ?: ($user->role === 'admin' ? 'Administrateur HUB Martin Sols' : ''),
            'photoUrl' => $this->images->normalizePublicUrl($user->photo_url) ?: self::DEFAULT_PROFILE_PHOTO,
            'role' => $user->role,
            'canEditIdentity' => $user->role === 'admin' || $this->hasPermission($user, 'platform.manage_users'),
            'connectedDevices' => $this->connectedDevices($user),
            'navigation' => $this->profileNavigationPayload($user),
        ];
    }

    /**
     * @return array{modules: array<int, array<string, mixed>>, menuGroups: array<int, array<string, mixed>>, menuItems: array<int, array<string, mixed>>}
     */
    private function profileNavigationPayload(CrmUser $user): array
    {
        $moduleIds = $this->access->moduleIds($user);
        $adminItemKeys = $this->adminNavigationItemKeys($user);

        if ($moduleIds === [] && $adminItemKeys === []) {
            return [
                'modules' => [],
                'menuGroups' => [],
                'menuItems' => [],
            ];
        }

        $modules = $moduleIds === []
            ? collect()
            : CrmModule::query()
                ->active()
                ->whereIn('id', $moduleIds)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

        $directItemKeys = $modules
            ->map(fn (CrmModule $module): string => 'module:'.$module->slug)
            ->merge($adminItemKeys)
            ->unique()
            ->values()
            ->all();

        $activeMenuItems = CrmMenuItem::query()
            ->where('active', true)
            ->orderBy('group_key')
            ->orderByRaw('parent_item_key is not null')
            ->orderBy('parent_item_key')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        $parentItemKeys = $activeMenuItems
            ->filter(fn (CrmMenuItem $item): bool => in_array($item->item_key, $directItemKeys, true))
            ->pluck('parent_item_key')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $visibleItemKeys = collect([...$directItemKeys, ...$parentItemKeys])
            ->unique()
            ->values()
            ->all();

        $menuItems = $activeMenuItems
            ->filter(fn (CrmMenuItem $item): bool => in_array($item->item_key, $visibleItemKeys, true))
            ->values();

        $menuGroups = CrmMenuGroup::query()
            ->where('active', true)
            ->whereIn('menu_key', $menuItems->pluck('group_key')->unique()->values()->all())
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return [
            'modules' => $modules->map(fn (CrmModule $module): array => $this->moduleRow($module))->values()->all(),
            'menuGroups' => $menuGroups->map(fn (CrmMenuGroup $group): array => $this->menuGroupRow($group))->values()->all(),
            'menuItems' => $menuItems->map(fn (CrmMenuItem $item): array => $this->menuItemRow($item))->values()->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function adminNavigationItemKeys(CrmUser $user): array
    {
        $permissionNames = $this->access->permissionNames($user);

        return collect(self::ADMIN_NAVIGATION_PERMISSIONS)
            ->filter(fn (array $requiredPermissions): bool => $user->role === 'admin'
                || count(array_intersect($requiredPermissions, $permissionNames)) > 0)
            ->keys()
            ->values()
            ->all();
    }

    private function connectedDevices(CrmUser $user): array
    {
        $accountId = (int) $user->id;
        if ($accountId <= 0) {
            return [];
        }

        $table = $this->sessionTable();
        if (! Schema::hasTable($table)) {
            return [];
        }

        $currentSessionId = $this->currentSessionId();
        $minimumActivity = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;

        return DB::table($table)
            ->where('user_id', $accountId)
            ->where('last_activity', '>=', $minimumActivity)
            ->orderByDesc('last_activity')
            ->limit(12)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->sortByDesc(fn (object $session): int => $session->id === $currentSessionId ? 1 : 0)
            ->values()
            ->map(function (object $session) use ($currentSessionId): array {
                $agent = $this->parseUserAgent((string) ($session->user_agent ?? ''));
                $lastActivity = (int) $session->last_activity;

                return [
                    'id' => $this->publicSessionId((string) $session->id),
                    'name' => $agent['name'],
                    'browser' => $agent['browser'],
                    'platform' => $agent['platform'],
                    'deviceType' => $agent['deviceType'],
                    'ipAddress' => (string) ($session->ip_address ?: 'IP inconnue'),
                    'userAgent' => mb_substr((string) ($session->user_agent ?? ''), 0, 180),
                    'lastActivity' => date('c', $lastActivity),
                    'lastActivityLabel' => $this->humanLastActivity($lastActivity),
                    'isCurrent' => $session->id === $currentSessionId,
                ];
            })
            ->all();
    }

    private function sessionTable(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function currentSessionId(): string
    {
        $request = request();

        return $request->hasSession()
            ? (string) $request->session()->getId()
            : '';
    }

    private function deleteStoredSession(string $sessionId): void
    {
        Session::getHandler()->destroy($sessionId);
    }

    private function publicSessionId(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 32);
    }

    /**
     * @return array{name: string, browser: string, platform: string, deviceType: string}
     */
    private function parseUserAgent(string $userAgent): array
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'CriOS') || str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'FxiOS') || str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Navigateur inconnu',
        };

        $platform = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Système inconnu',
        };

        $deviceType = match (true) {
            str_contains($userAgent, 'iPad') || (str_contains($userAgent, 'Android') && ! str_contains($userAgent, 'Mobile')) => 'Tablette',
            str_contains($userAgent, 'Mobile') || str_contains($userAgent, 'iPhone') => 'Mobile',
            default => 'Ordinateur',
        };

        return [
            'name' => $browser !== 'Navigateur inconnu' && $platform !== 'Système inconnu'
                ? $browser.' sur '.$platform
                : $browser,
            'browser' => $browser,
            'platform' => $platform,
            'deviceType' => $deviceType,
        ];
    }

    private function humanLastActivity(int $timestamp): string
    {
        $seconds = max(0, now()->timestamp - $timestamp);

        if ($seconds < 60) {
            return 'à l’instant';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return 'il y a '.$minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return 'il y a '.$hours.' h';
        }

        $days = intdiv($hours, 24);
        if ($days === 1) {
            return 'hier';
        }

        return 'il y a '.$days.' j';
    }

    private function hasPermission(CrmUser $actor, string $permission): bool
    {
        return in_array($permission, $this->permissionNames($actor), true);
    }

    private function requireAny(CrmUser $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($actor, $permission)) {
                return;
            }
        }

        $this->fail('Droit administration insuffisant', 403);
    }

    private function permissionNames(CrmUser $user): array
    {
        $user->loadMissing('permissions:id,name,sort_order');

        return $user->permissions
            ->sortBy([
                ['sort_order', 'asc'],
                ['name', 'asc'],
            ])
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function primarySiteId(CrmUser $user, array $siteIds): ?int
    {
        $defaultSite = $user->sites->first(
            fn (CrmSite $site): bool => (bool) ($site->pivot?->is_default ?? false),
        );

        return $defaultSite ? (int) $defaultSite->id : ($siteIds[0] ?? null);
    }

    private function syncSites(CrmUser $user, array $siteIds): void
    {
        $sync = [];
        foreach (array_values($siteIds) as $index => $siteId) {
            $sync[(int) $siteId] = ['is_default' => $index === 0];
        }

        $user->sites()->sync($sync);
    }

    /**
     * @return array<int, int>
     */
    private function requestedSiteIds(array $data, int $primarySiteId): array
    {
        $requestedSiteIds = is_array($data['siteIds'] ?? null)
            ? array_map('intval', $data['siteIds'])
            : [];

        if ($primarySiteId > 0) {
            array_unshift($requestedSiteIds, $primarySiteId);
        }

        $requestedSiteIds = array_values(array_unique(array_filter(
            $requestedSiteIds,
            fn (int $siteId): bool => $siteId > 0,
        )));

        if ($requestedSiteIds === []) {
            return [];
        }

        $validSiteIds = array_fill_keys($this->validIds($requestedSiteIds, CrmSite::class), true);

        return array_values(array_filter(
            $requestedSiteIds,
            fn (int $siteId): bool => isset($validSiteIds[$siteId]),
        ));
    }

    /**
     * @param  class-string  $model
     */
    private function validIds(mixed $ids, string $model): array
    {
        $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
        if ($ids === []) {
            return [];
        }

        return $model::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function normalizeTime(array $data, string $camelKey, string $snakeKey, string $default): string
    {
        $value = trim((string) ($data[$camelKey] ?? $data[$snakeKey] ?? ($data['hours'][$camelKey] ?? $default)));
        $value = substr($value, 0, 5);

        if (! preg_match('/^([0-2][0-9]):([0-5][0-9])$/', $value, $matches)) {
            $this->fail('Horaire invalide', 400);
        }

        $hour = (int) $matches[1];
        if ($hour > 23) {
            $this->fail('Horaire invalide', 400);
        }

        return sprintf('%02d:%02d:00', $hour, (int) $matches[2]);
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }

    private function time5(mixed $value, string $default): string
    {
        $value = trim((string) $value);

        return preg_match('/^([0-2][0-9]:[0-5][0-9])/', $value, $matches) ? $matches[1] : $default;
    }

    private function siteColor(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return CrmTheme::primaryHex();
        }

        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            $this->fail('Couleur invalide', 400);
        }

        return strtolower($value);
    }

    private function iconKey(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $value) ? $value : 'article';
    }

    private function boolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<int, array{site_id: int, module_id: int, permission_id: int}>
     */
    private function accessRulesFromPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $validSiteIds = CrmSite::query()->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
        $validModuleIds = CrmModule::query()->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
        $validPermissionIds = CrmPermission::query()->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();

        $rules = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $siteId = (int) ($entry['siteId'] ?? $entry['site_id'] ?? 0);
            $moduleId = (int) ($entry['moduleId'] ?? $entry['module_id'] ?? 0);

            if (! isset($validSiteIds[$siteId], $validModuleIds[$moduleId])) {
                continue;
            }

            $permissionIds = [];
            if (isset($entry['permissionIds']) && is_array($entry['permissionIds'])) {
                $permissionIds = $entry['permissionIds'];
            } elseif (isset($entry['permission_ids']) && is_array($entry['permission_ids'])) {
                $permissionIds = $entry['permission_ids'];
            } else {
                $permissionIds = [$entry['permissionId'] ?? $entry['permission_id'] ?? 0];
            }

            foreach ($permissionIds as $permissionId) {
                $permissionId = (int) $permissionId;
                if (! isset($validPermissionIds[$permissionId])) {
                    continue;
                }

                $rules["{$siteId}:{$moduleId}:{$permissionId}"] = [
                    'site_id' => $siteId,
                    'module_id' => $moduleId,
                    'permission_id' => $permissionId,
                ];
            }
        }

        return array_values($rules);
    }

    /**
     * @param  array<int, array{site_id: int, module_id: int, permission_id: int}>  $rules
     */
    private function syncAccessRules(CrmUser $user, array $rules): void
    {
        CrmUserSiteModulePermission::query()
            ->where('user_id', $user->id)
            ->delete();

        if ($rules === []) {
            return;
        }

        $now = now();
        CrmUserSiteModulePermission::query()->insert(array_map(
            fn (array $rule): array => [
                'user_id' => (int) $user->id,
                'site_id' => $rule['site_id'],
                'module_id' => $rule['module_id'],
                'permission_id' => $rule['permission_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rules,
        ));
    }

    private function log(CrmUser $actor, string $action, string $details = ''): void
    {
        $this->activity->log($actor, $action, $details);
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
