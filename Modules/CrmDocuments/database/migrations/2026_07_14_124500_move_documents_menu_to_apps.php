<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_menu_groups') || ! Schema::hasTable('crm_menu_items')) {
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

        foreach ([
            'module:documents-promo' => ['Promo', 22],
            'module:documents-fiches-techniques' => ['Fiches techniques', 23],
            'module:documents-procedures' => ['Procédures', 24],
        ] as $itemKey => [$label, $sortOrder]) {
            DB::table('crm_menu_items')
                ->where('item_key', $itemKey)
                ->update([
                    'group_key' => 'apps',
                    'label' => $label,
                    'icon_key' => 'article',
                    'active' => true,
                    'sort_order' => $sortOrder,
                    'updated_at' => $now,
                ]);
        }

        $documentsGroupHasItems = DB::table('crm_menu_items')->where('group_key', 'documents')->exists();

        if (! $documentsGroupHasItems) {
            DB::table('crm_menu_groups')->where('menu_key', 'documents')->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_menu_groups') || ! Schema::hasTable('crm_menu_items')) {
            return;
        }

        $now = now();

        DB::table('crm_menu_groups')->updateOrInsert(
            ['menu_key' => 'documents'],
            [
                'title' => 'DOCUMENTS',
                'active' => true,
                'sort_order' => 24,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        foreach ([
            'module:documents-promo' => 10,
            'module:documents-fiches-techniques' => 20,
            'module:documents-procedures' => 30,
        ] as $itemKey => $sortOrder) {
            DB::table('crm_menu_items')
                ->where('item_key', $itemKey)
                ->update([
                    'group_key' => 'documents',
                    'sort_order' => $sortOrder,
                    'updated_at' => $now,
                ]);
        }
    }
};
