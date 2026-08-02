<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HIDDEN_MODULES = [
        'addvance',
        'pages-crm',
        'planning',
        'stats',
    ];

    private const HIDDEN_MENU_ITEMS = [
        'admin:pages',
        'module:addvance',
        'module:pages-crm',
        'module:planning',
        'module:stats',
    ];

    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')
                ->whereIn('slug', self::HIDDEN_MODULES)
                ->update([
                    'active' => false,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')
                ->whereIn('item_key', self::HIDDEN_MENU_ITEMS)
                ->update([
                    'active' => false,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_feature_flags')) {
            DB::table('crm_feature_flags')
                ->whereIn(
                    'flag_key',
                    array_map(static fn (string $slug): string => 'module:'.$slug, self::HIDDEN_MODULES),
                )
                ->update([
                    'enabled' => false,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_user_quick_access_modules') && Schema::hasTable('crm_modules')) {
            DB::table('crm_user_quick_access_modules')
                ->whereIn('module_id', function (Builder $query): void {
                    $query
                        ->select('id')
                        ->from('crm_modules')
                        ->whereIn('slug', self::HIDDEN_MODULES);
                })
                ->delete();
        }
    }

    public function down(): void
    {
        $now = now();

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')
                ->whereIn('slug', self::HIDDEN_MODULES)
                ->update([
                    'active' => true,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')
                ->whereIn('item_key', self::HIDDEN_MENU_ITEMS)
                ->update([
                    'active' => true,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_feature_flags')) {
            DB::table('crm_feature_flags')
                ->whereIn(
                    'flag_key',
                    array_map(static fn (string $slug): string => 'module:'.$slug, self::HIDDEN_MODULES),
                )
                ->update([
                    'enabled' => true,
                    'updated_at' => $now,
                ]);
        }
    }
};
