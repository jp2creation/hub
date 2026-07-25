<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_menu_items') || ! Schema::hasTable('crm_menu_groups')) {
            return;
        }

        $now = now();

        DB::table('crm_menu_groups')->updateOrInsert(
            ['menu_key' => 'apps'],
            [
                'title' => 'Applications HUB',
                'active' => true,
                'sort_order' => 10,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('crm_menu_groups')->updateOrInsert(
            ['menu_key' => 'internal'],
            [
                'title' => 'Interne',
                'active' => true,
                'sort_order' => 20,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('crm_menu_items')
            ->where('item_key', 'module:tapis-romus')
            ->update([
                'group_key' => 'apps',
                'sort_order' => 20,
                'updated_at' => $now,
            ]);

        DB::table('crm_menu_items')
            ->where('item_key', 'module:administration')
            ->update([
                'group_key' => 'internal',
                'sort_order' => 90,
                'updated_at' => $now,
            ]);

        DB::table('crm_menu_groups')
            ->whereIn('menu_key', [
                'authentication',
                'charts',
                'dashboards',
                'forms',
                'tables',
            ])
            ->update([
                'active' => false,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_menu_items') || ! Schema::hasTable('crm_menu_groups')) {
            return;
        }

        $now = now();

        DB::table('crm_menu_items')
            ->where('item_key', 'module:tapis-romus')
            ->update([
                'group_key' => 'forms',
                'sort_order' => 40,
                'updated_at' => $now,
            ]);

        DB::table('crm_menu_items')
            ->where('item_key', 'module:administration')
            ->update([
                'group_key' => 'authentication',
                'sort_order' => 10,
                'updated_at' => $now,
            ]);
    }
};
