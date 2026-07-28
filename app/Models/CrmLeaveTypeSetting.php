<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CrmLeaveTypeSetting extends Model
{
    protected $table = 'crm_leave_types';

    protected $fillable = [
        'value',
        'label',
        'color',
        'active',
        'requires_balance',
        'requires_approval',
        'send_reminders',
        'is_system',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requires_balance' => 'boolean',
            'requires_approval' => 'boolean',
            'send_reminders' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('label');
    }
}
