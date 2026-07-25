<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')
                ->where('slug', 'conges')
                ->update([
                    'sort_order' => 17,
                    'updated_at' => now(),
                ]);

            DB::table('crm_modules')
                ->where('slug', 'tournees-representants')
                ->update([
                    'sort_order' => 18,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('crm_menu_groups')) {
            DB::table('crm_menu_groups')->updateOrInsert(
                ['menu_key' => 'apps'],
                [
                    'title' => 'Applications HUB',
                    'active' => true,
                    'sort_order' => 10,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')->updateOrInsert(
                ['item_key' => 'module:conges'],
                [
                    'group_key' => 'apps',
                    'icon_key' => 'calendar',
                    'label' => 'Congés',
                    'active' => true,
                    'sort_order' => 17,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            DB::table('crm_menu_items')
                ->where('item_key', 'module:tournees-representants')
                ->update([
                    'sort_order' => 18,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')
                ->where('slug', 'conges')
                ->update([
                    'sort_order' => 24,
                    'updated_at' => now(),
                ]);

            DB::table('crm_modules')
                ->where('slug', 'tournees-representants')
                ->update([
                    'sort_order' => 17,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')
                ->where('item_key', 'module:conges')
                ->update([
                    'group_key' => 'internal',
                    'sort_order' => 24,
                    'updated_at' => now(),
                ]);

            DB::table('crm_menu_items')
                ->where('item_key', 'module:tournees-representants')
                ->update([
                    'sort_order' => 17,
                    'updated_at' => now(),
                ]);
        }
    }
};
