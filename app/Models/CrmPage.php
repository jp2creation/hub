<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property bool $active
 * @property string|null $icon_key
 * @property bool $show_in_menu
 * @property string|null $slug
 * @property int $sort_order
 * @property string $title
 */
class CrmPage extends Model
{
    protected $table = 'crm_pages';

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'icon_key',
        'active',
        'show_in_menu',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'show_in_menu' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CrmPage $page): void {
            if (blank($page->slug)) {
                $page->slug = static::uniqueSlug($page->title, $page->getKey());
            }

            if (blank($page->icon_key)) {
                $page->icon_key = 'article';
            }
        });

        static::saved(function (CrmPage $page): void {
            static::syncMenuItem($page);
        });

        static::deleting(function (CrmPage $page): void {
            CrmMenuItem::withTrashed()
                ->whereIn('item_key', [
                    'cms-page:'.$page->slug,
                    'cms-page:'.($page->getOriginal('slug') ?: $page->slug),
                ])
                ->forceDelete();

            static::syncPagesMenuGroupVisibility();
        });
    }

    public static function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'page';
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

    public static function syncMenuItem(CrmPage $page): void
    {
        CrmMenuGroup::query()->updateOrCreate(
            ['menu_key' => 'pages'],
            ['title' => 'Pages internes', 'active' => true, 'sort_order' => 30],
        );

        $oldSlug = $page->getOriginal('slug') ?: $page->slug;
        if ($oldSlug !== $page->slug) {
            CrmMenuItem::withTrashed()
                ->where('item_key', 'cms-page:'.$oldSlug)
                ->forceDelete();
        }

        $menuItem = CrmMenuItem::withTrashed()->firstOrNew(['item_key' => 'cms-page:'.$page->slug]);
        if ($menuItem->exists && $menuItem->trashed()) {
            static::syncPagesMenuGroupVisibility();

            return;
        }

        $menuItem->fill([
            'group_key' => 'pages',
            'icon_key' => $page->icon_key ?: 'article',
            'label' => $page->title,
            'active' => $page->active && $page->show_in_menu,
            'sort_order' => $page->sort_order,
        ]);
        $menuItem->saveQuietly();

        static::syncPagesMenuGroupVisibility();
    }

    public function getRoutePathAttribute(): string
    {
        return '/pages-crm/'.$this->slug;
    }

    private static function syncPagesMenuGroupVisibility(): void
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
}
