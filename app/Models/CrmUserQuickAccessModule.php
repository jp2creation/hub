<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $module_id
 * @property bool $enabled
 * @property int $sort_order
 * @property-read CrmUser $user
 * @property-read CrmModule $module
 */
class CrmUserQuickAccessModule extends Model
{
    protected $table = 'crm_user_quick_access_modules';

    protected $fillable = [
        'user_id',
        'module_id',
        'enabled',
        'sort_order',
    ];

    protected $attributes = [
        'enabled' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CrmUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(CrmUser::class, 'user_id');
    }

    /**
     * @return BelongsTo<CrmModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CrmModule::class, 'module_id');
    }
}
