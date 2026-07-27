<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Modules\CrmCore\Services\UploadedCrmFileCleaner;
use Modules\CrmCore\Support\CrmReferenceCache;

class CrmEquipmentItem extends Model
{
    protected $table = 'crm_equipment_items';

    protected $fillable = [
        'site_id',
        'category_id',
        'name',
        'inventory_code',
        'description',
        'color',
        'photo_url',
        'half_day_price',
        'day_price',
        'show_half_day_price',
        'show_day_price',
        'rental_mode',
        'deposit_amount',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'half_day_price' => 'decimal:2',
            'day_price' => 'decimal:2',
            'show_half_day_price' => 'boolean',
            'show_day_price' => 'boolean',
            'deposit_amount' => 'decimal:2',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            CrmReferenceCache::forgetEquipmentItems();
        });

        static::updated(function (CrmEquipmentItem $item): void {
            if ($item->wasChanged('photo_url')) {
                app(UploadedCrmFileCleaner::class)->deletePublicUpload($item->getOriginal('photo_url'));
            }

            if ($item->wasChanged('active') && ! $item->active) {
                app(UploadedCrmFileCleaner::class)->deletePublicUpload($item->getAttribute('photo_url'));
            }
        });

        static::deleting(function (CrmEquipmentItem $item): void {
            if ($item->rentals()->exists()) {
                throw ValidationException::withMessages([
                    'equipment' => 'Ce materiel a des locations. Desactive-le pour le masquer.',
                ]);
            }
        });

        static::deleted(function (CrmEquipmentItem $item): void {
            app(UploadedCrmFileCleaner::class)->deletePublicUpload($item->getAttribute('photo_url'));
            CrmReferenceCache::forgetEquipmentItems();
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CrmSite::class, 'site_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CrmEquipmentCategory::class, 'category_id');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(CrmEquipmentRental::class, 'equipment_item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
