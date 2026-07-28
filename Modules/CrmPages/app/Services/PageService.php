<?php

namespace Modules\CrmPages\Services;

use App\Models\CrmModule;
use App\Models\CrmPage;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CrmCore\Services\CrmActivityLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PageService
{
    public function __construct(private readonly CrmActivityLogger $activity) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label'])
            ->forAccount($user)
            ->where('active', true)
            ->first();

        if (! $actor) {
            $this->fail('Utilisateur HUB introuvable', 404);
        }

        return $actor;
    }

    public function bootstrap(CrmUser $actor): array
    {
        $this->requireViewAccess($actor);

        $pages = CrmPage::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return [
            'ok' => true,
            'user' => $this->actorRow($actor),
            'pages' => $pages->map(fn (CrmPage $page): array => $this->pageRow($page, false))->values()->all(),
        ];
    }

    public function page(CrmUser $actor, string $slug): array
    {
        $this->requireViewAccess($actor);

        $slug = trim($slug);

        if ($slug === '') {
            $this->fail('Page invalide', 400);
        }

        $page = CrmPage::query()
            ->where('slug', $slug)
            ->where('active', true)
            ->first();

        if (! $page) {
            $this->fail('Page introuvable', 404);
        }

        return ['ok' => true, 'page' => $this->pageRow($page)];
    }

    public function manageBootstrap(CrmUser $actor): array
    {
        $this->requireManageAccess($actor);

        $pages = CrmPage::query()
            ->orderByDesc('active')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return [
            'ok' => true,
            'pages' => $pages->map(fn (CrmPage $page): array => $this->pageRow($page))->values()->all(),
        ];
    }

    public function savePage(CrmUser $actor, array $data): array
    {
        $this->requireManageAccess($actor);

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

            $page = $id > 0
                ? CrmPage::query()->lockForUpdate()->find($id)
                : new CrmPage;

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
                'sort_order' => max(0, min(999, (int) ($data['sortOrder'] ?? $data['sort_order'] ?? 100))),
            ]);
            $page->save();

            $this->log($actor, $id > 0 ? 'modification page HUB' : 'creation page HUB', $title);

            return ['ok' => true, 'page' => $this->pageRow($page->refresh())];
        });
    }

    public function deletePage(CrmUser $actor, array $data): array
    {
        $this->requireManageAccess($actor);

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

    private function requireViewAccess(CrmUser $actor): void
    {
        if ($this->isAdmin($actor)) {
            return;
        }

        if ($this->hasModule($actor, 'pages-crm') && $this->hasAnyPermission($actor, ['pages.view', 'pages.manage'])) {
            return;
        }

        $this->fail('Module pages non autorise', 403);
    }

    private function requireManageAccess(CrmUser $actor): void
    {
        if ($this->hasAnyPermission($actor, ['pages.manage', 'platform.manage_modules'])) {
            return;
        }

        $this->fail('Droit administration insuffisant', 403);
    }

    private function isAdmin(CrmUser $actor): bool
    {
        return $actor->role === 'admin';
    }

    private function hasModule(CrmUser $actor, string $moduleSlug): bool
    {
        $actor->loadMissing('modules:id,slug,active');

        $moduleActive = CrmModule::query()
            ->where('slug', $moduleSlug)
            ->where('active', true)
            ->exists();

        return $moduleActive
            && $actor->modules->contains(fn (CrmModule $module): bool => $module->slug === $moduleSlug && (bool) $module->active);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function hasAnyPermission(CrmUser $actor, array $permissions): bool
    {
        $actor->loadMissing('permissions:id,name,label');

        return $actor->permissions->contains(fn ($permission): bool => in_array($permission->name, $permissions, true));
    }

    private function actorRow(CrmUser $actor): array
    {
        $actor->loadMissing(['modules:id,slug,active', 'permissions:id,name,label']);

        return [
            'id' => $actor->id,
            'name' => $actor->name,
            'role' => $actor->role,
            'active' => (bool) $actor->active,
            'permissions' => $actor->permissions->pluck('name')->values()->all(),
            'moduleSlugs' => $actor->modules
                ->filter(fn (CrmModule $module): bool => (bool) $module->active)
                ->pluck('slug')
                ->values()
                ->all(),
        ];
    }

    private function pageRow(CrmPage $page, bool $withContent = true): array
    {
        $row = [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'excerpt' => $page->excerpt ?? '',
            'iconKey' => $page->icon_key ?: 'article',
            'active' => (bool) $page->active,
            'showInMenu' => (bool) $page->show_in_menu,
            'sortOrder' => (int) $page->sort_order,
            'routePath' => $page->route_path,
        ];

        if ($withContent) {
            $row['content'] = $page->content ?? '';
        }

        return $row;
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

    private function log(CrmUser $actor, string $action, string $details = ''): void
    {
        $this->activity->log($actor, $action, $details);
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
