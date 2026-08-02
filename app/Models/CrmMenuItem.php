<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CrmCore\Support\CrmReferenceCache;

/**
 * @property int $id
 * @property bool $active
 * @property string $group_key
 * @property string $icon_key
 * @property string $item_key
 * @property string $label
 * @property string|null $parent_item_key
 * @property int $sort_order
 * @property-read CrmMenuItem|null $parent
 * @property-read Collection<int, CrmMenuItem> $children
 * @property-read CrmMenuGroup|null $group
 */
class CrmMenuItem extends Model
{
    use SoftDeletes;

    protected $table = 'crm_menu_items';

    protected $fillable = [
        'item_key',
        'group_key',
        'parent_item_key',
        'icon_key',
        'label',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            CrmReferenceCache::forgetModules();
        });

        static::deleted(function (): void {
            CrmReferenceCache::forgetModules();
        });
    }

    /**
     * @return BelongsTo<CrmMenuGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CrmMenuGroup::class, 'group_key', 'menu_key');
    }

    /**
     * @return BelongsTo<CrmMenuItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_key', 'item_key');
    }

    /**
     * @return HasMany<CrmMenuItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_key', 'item_key');
    }
}
