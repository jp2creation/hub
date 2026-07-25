<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\CrmCore\Support\CrmReferenceCache;

return new class extends Migration
{
    private const MODULE_SLUG = 'dashboard';

    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('crm_menu_groups')) {
            DB::table('crm_menu_groups')->updateOrInsert(
                ['menu_key' => 'home'],
                [
                    'title' => 'Accueil',
                    'active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')->updateOrInsert(
                ['slug' => self::MODULE_SLUG],
                [
                    'name' => 'Tableau de bord',
                    'description' => 'Synthese et acces rapides du HUB',
                    'route_path' => '/dashboard/crm',
                    'active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')->updateOrInsert(
                ['item_key' => 'module:'.self::MODULE_SLUG],
                [
                    'group_key' => 'home',
                    'icon_key' => 'dashboard',
                    'label' => 'Tableau de bord',
                    'active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $this->grantModuleToExistingUsers($now);

        CrmReferenceCache::forgetModules();
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_modules')) {
            $moduleId = DB::table('crm_modules')->where('slug', self::MODULE_SLUG)->value('id');

            if ($moduleId && Schema::hasTable('crm_user_modules')) {
                DB::table('crm_user_modules')->where('module_id', $moduleId)->delete();
            }
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')->where('item_key', 'module:'.self::MODULE_SLUG)->delete();
        }

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')->where('slug', self::MODULE_SLUG)->delete();
        }

        if (Schema::hasTable('crm_menu_groups') && Schema::hasTable('crm_menu_items')) {
            $homeHasItems = DB::table('crm_menu_items')->where('group_key', 'home')->exists();

            if (! $homeHasItems) {
                DB::table('crm_menu_groups')->where('menu_key', 'home')->delete();
            }
        }

        CrmReferenceCache::forgetModules();
    }

    private function grantModuleToExistingUsers($now): void
    {
        if (! Schema::hasTable('crm_modules') || ! Schema::hasTable('crm_users') || ! Schema::hasTable('crm_user_modules')) {
            return;
        }

        $moduleId = DB::table('crm_modules')->where('slug', self::MODULE_SLUG)->value('id');

        if (! $moduleId) {
            return;
        }

        DB::table('crm_users')
            ->whereIn('role', ['admin', 'responsable', 'user'])
            ->where('active', true)
            ->pluck('id')
            ->each(function ($userId) use ($moduleId, $now): void {
                DB::table('crm_user_modules')->updateOrInsert(
                    [
                        'module_id' => $moduleId,
                        'user_id' => $userId,
                    ],
                    [
                        'created_at' => $now,
                    ],
                );
            });
    }
};
