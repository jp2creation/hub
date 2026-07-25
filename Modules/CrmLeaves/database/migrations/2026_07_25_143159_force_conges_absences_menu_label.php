<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')
                ->where('slug', 'conges')
                ->update([
                    'name' => 'Congés & Absences',
                    'description' => 'Planning et gestion des congés, absences et arrêts',
                    'sort_order' => 17,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')
                ->where(function ($query): void {
                    $query
                        ->where('item_key', 'module:conges')
                        ->orWhere(function ($query): void {
                            $query
                                ->where('group_key', 'apps')
                                ->whereIn('label', ['Congés', 'Conges']);
                        });
                })
                ->update([
                    'group_key' => 'apps',
                    'icon_key' => 'calendar',
                    'label' => 'Congés & Absences',
                    'active' => true,
                    'sort_order' => 17,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_permissions')) {
            DB::table('crm_permissions')
                ->where('name', 'conges.view')
                ->update([
                    'label' => 'Voir les congés et absences',
                    'group_label' => 'Congés & Absences',
                    'updated_at' => $now,
                ]);

            DB::table('crm_permissions')
                ->where('name', 'conges.manage')
                ->update([
                    'label' => 'Gérer les congés et absences',
                    'group_label' => 'Congés & Absences',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        $now = now();

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')
                ->where('slug', 'conges')
                ->update([
                    'name' => 'Congés',
                    'description' => 'Planning et gestion des congés',
                    'sort_order' => 24,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')
                ->where('item_key', 'module:conges')
                ->update([
                    'group_key' => 'internal',
                    'label' => 'Congés',
                    'sort_order' => 24,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_permissions')) {
            DB::table('crm_permissions')
                ->where('name', 'conges.view')
                ->update([
                    'label' => 'Voir les conges',
                    'group_label' => 'Conges',
                    'updated_at' => $now,
                ]);

            DB::table('crm_permissions')
                ->where('name', 'conges.manage')
                ->update([
                    'label' => 'Gerer les conges',
                    'group_label' => 'Conges',
                    'updated_at' => $now,
                ]);
        }
    }
};
