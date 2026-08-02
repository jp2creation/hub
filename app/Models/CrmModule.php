<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CrmCore\Support\CrmReferenceCache;

/**
 * @property int $id
 * @property bool $active
 * @property string|null $description
 * @property string|null $menu_badge
 * @property string $name
 * @property string|null $route_path
 * @property bool $show_menu_badge
 * @property string $slug
 * @property int $sort_order
 * @property-read Collection<int, CrmUser> $users
 * @property-read Collection<int, CrmUserSiteModulePermission> $siteModulePermissions
 */
class CrmModule extends Model
{
    protected $table = 'crm_modules';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'route_path',
        'menu_badge',
        'show_menu_badge',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'show_menu_badge' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CrmModule $module): void {
            if (blank($module->slug)) {
                $module->slug = static::uniqueSlug($module->name, $module->getKey());
            }

            if (blank($module->route_path)) {
                $module->route_path = '/'.$module->slug;
            }
        });

        static::saved(function (CrmModule $module): void {
            $oldSlug = $module->getOriginal('slug') ?: $module->slug;

            $menuItem = CrmMenuItem::withTrashed()
                ->where('item_key', 'module:'.$oldSlug)
                ->first();

            if (! $menuItem) {
                $menuItem = CrmMenuItem::withTrashed()->firstOrNew(['item_key' => 'module:'.$module->slug]);
            }

            if ($menuItem->exists && $menuItem->trashed()) {
                self::syncFeatureFlag($module, $oldSlug);
                CrmReferenceCache::forgetModules();

                return;
            }

            $menuItemExists = $menuItem->exists;

            $menuItem->fill([
                'item_key' => 'module:'.$module->slug,
                'group_key' => $menuItemExists ? $menuItem->group_key : static::defaultMenuGroup($module->slug),
                'icon_key' => $menuItem->icon_key ?: static::defaultIconKey($module->slug),
                'label' => $menuItemExists ? ($menuItem->label ?: $module->name) : $module->name,
                'active' => $menuItemExists ? ((bool) $menuItem->active && (bool) $module->active) : (bool) $module->active,
                'sort_order' => $menuItemExists ? $menuItem->sort_order : $module->sort_order,
            ]);

            $menuItem->saveQuietly();
            self::syncFeatureFlag($module, $oldSlug);
            CrmReferenceCache::forgetModules();
        });

        static::deleting(function (CrmModule $module): void {
            if (in_array($module->slug, ['reservations', 'administration'], true)) {
                throw ValidationException::withMessages([
                    'module' => 'Ce module est central. Desactive-le pour le masquer.',
                ]);
            }

            $module->users()->detach();
            $module->siteModulePermissions()->delete();
            CrmMenuItem::withTrashed()->where('item_key', 'module:'.$module->slug)->forceDelete();
        });

        static::deleted(function (): void {
            CrmReferenceCache::forgetModules();
        });
    }

    public static function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'module';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private static function syncFeatureFlag(CrmModule $module, string $oldSlug): void
    {
        if (! Schema::hasTable('crm_feature_flags')) {
            return;
        }

        $flag = CrmFeatureFlag::query()
            ->where('flag_key', 'module:'.$oldSlug)
            ->first();

        if (! $flag) {
            $flag = CrmFeatureFlag::firstOrNew(['flag_key' => 'module:'.$module->slug]);
            $flag->enabled = (bool) $module->active;
        }

        $flag->fill([
            'flag_key' => 'module:'.$module->slug,
            'scope' => 'module',
            'name' => $module->name,
            'description' => $module->description,
        ])->save();
    }

    public static function defaultIconKey(string $slug): string
    {
        return [
            'dashboard' => 'dashboard',
            'reservations' => 'truck',
            'administration' => 'settings',
            'planning' => 'calendar',
            'documents' => 'article',
            'documents-promo' => 'article',
            'documents-fiches-techniques' => 'article',
            'documents-procedures' => 'article',
            'demandes' => 'checklist',
            'equipes' => 'users',
            'pilotage-commercial' => 'dashboard',
            'tournees-representants' => 'calendar',
            'tapis-romus' => 'table',
            'locations-materiel' => 'package',
            'pages-crm' => 'article',
            'conges' => 'calendar',
            'controle-caisse' => 'creditCard',
            'demandes-acompte' => 'banknote',
            'remise-cheques' => 'creditCard',
            'addvance' => 'creditCard',
        ][$slug] ?? 'category';
    }

    public static function defaultMenuGroup(string $slug): string
    {
        if ($slug === 'dashboard') {
            return 'home';
        }

        if (in_array($slug, ['controle-caisse', 'demandes-acompte', 'remise-cheques', 'addvance'], true)) {
            return 'accounting';
        }

        if (in_array($slug, ['documents-promo', 'documents-fiches-techniques', 'documents-procedures'], true)) {
            return 'documents';
        }

        return in_array($slug, ['reservations', 'locations-materiel', 'equipes', 'pilotage-commercial', 'tournees-representants', 'tapis-romus'], true)
            ? 'apps'
            : 'internal';
    }

    /**
     * @return BelongsToMany<CrmUser, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(CrmUser::class, 'crm_user_modules', 'module_id', 'user_id');
    }

    /**
     * @return HasMany<CrmUserSiteModulePermission, $this>
     */
    public function siteModulePermissions(): HasMany
    {
        return $this->hasMany(CrmUserSiteModulePermission::class, 'module_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
