<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_users') && ! Schema::hasColumn('crm_users', 'phone')) {
            Schema::table('crm_users', function (Blueprint $table): void {
                $table->string('phone', 40)->nullable()->after('email');
            });
        }

        if (! Schema::hasTable('crm_modules') || ! Schema::hasTable('crm_permissions')) {
            return;
        }

        $now = now();

        DB::table('crm_permissions')->updateOrInsert(
            ['name' => 'teams.view'],
            [
                'label' => 'Voir les equipes',
                'group_label' => 'Equipe',
                'sort_order' => 155,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('crm_modules')->updateOrInsert(
            ['slug' => 'equipes'],
            [
                'name' => 'Équipe',
                'description' => 'Annuaire des membres par site',
                'route_path' => '/equipes',
                'active' => true,
                'sort_order' => 16,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        if (Schema::hasTable('crm_menu_groups')) {
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
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')->updateOrInsert(
                ['item_key' => 'module:equipes'],
                [
                    'group_key' => 'apps',
                    'icon_key' => 'users',
                    'label' => 'Équipe',
                    'active' => true,
                    'sort_order' => 16,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $this->grantExistingUsers($now);
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')->where('item_key', 'module:equipes')->delete();
        }

        $moduleId = Schema::hasTable('crm_modules')
            ? DB::table('crm_modules')->where('slug', 'equipes')->value('id')
            : null;
        $permissionId = Schema::hasTable('crm_permissions')
            ? DB::table('crm_permissions')->where('name', 'teams.view')->value('id')
            : null;

        if ($moduleId && Schema::hasTable('crm_user_modules')) {
            DB::table('crm_user_modules')->where('module_id', $moduleId)->delete();
        }

        if ($permissionId && Schema::hasTable('crm_user_permissions')) {
            DB::table('crm_user_permissions')->where('permission_id', $permissionId)->delete();
        }

        if ($moduleId && Schema::hasTable('crm_user_site_module_permissions')) {
            DB::table('crm_user_site_module_permissions')->where('module_id', $moduleId)->delete();
        }

        if ($permissionId && Schema::hasTable('crm_user_site_module_permissions')) {
            DB::table('crm_user_site_module_permissions')->where('permission_id', $permissionId)->delete();
        }

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')->where('slug', 'equipes')->delete();
        }

        if (Schema::hasTable('crm_permissions')) {
            DB::table('crm_permissions')->where('name', 'teams.view')->delete();
        }

        if (Schema::hasTable('crm_users') && Schema::hasColumn('crm_users', 'phone')) {
            Schema::table('crm_users', function (Blueprint $table): void {
                $table->dropColumn('phone');
            });
        }
    }

    private function grantExistingUsers(mixed $now): void
    {
        if (! Schema::hasTable('crm_users') || ! Schema::hasTable('crm_user_modules') || ! Schema::hasTable('crm_user_permissions')) {
            return;
        }

        $moduleId = (int) DB::table('crm_modules')->where('slug', 'equipes')->value('id');
        $permissionId = (int) DB::table('crm_permissions')->where('name', 'teams.view')->value('id');

        if ($moduleId <= 0 || $permissionId <= 0) {
            return;
        }

        $userIds = DB::table('crm_users')
            ->where('active', true)
            ->where('role', '<>', 'blocked')
            ->pluck('id');

        foreach ($userIds as $userId) {
            DB::table('crm_user_modules')->updateOrInsert(
                ['module_id' => $moduleId, 'user_id' => $userId],
                ['created_at' => $now],
            );

            DB::table('crm_user_permissions')->updateOrInsert(
                ['permission_id' => $permissionId, 'user_id' => $userId],
                ['created_at' => $now],
            );
        }
    }
};
