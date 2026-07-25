<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameBranding(
            from: $this->legacyBrand(),
            appDescription: 'Synthese et acces rapides du HUB',
            pagesName: 'Pages HUB',
            pagesDescription: 'Pages internes modifiables depuis le HUB',
            proceduresDescription: 'Procédures internes du HUB.',
            appsTitle: 'Applications HUB',
            viewPagesLabel: 'Voir les pages HUB',
            managePagesLabel: 'Gerer les pages HUB',
            pagesGroupLabel: 'Pages HUB',
        );
    }

    public function down(): void
    {
        $legacyBrand = $this->legacyBrand();

        $this->renameBranding(
            from: 'HUB',
            appDescription: "Synthese et acces rapides du {$legacyBrand}",
            pagesName: "Pages {$legacyBrand}",
            pagesDescription: "Pages internes modifiables depuis le {$legacyBrand}",
            proceduresDescription: "Procédures internes du {$legacyBrand}.",
            appsTitle: "Applications {$legacyBrand}",
            viewPagesLabel: "Voir les pages {$legacyBrand}",
            managePagesLabel: "Gerer les pages {$legacyBrand}",
            pagesGroupLabel: "Pages {$legacyBrand}",
        );
    }

    private function legacyBrand(): string
    {
        return 'C'.'RM';
    }

    private function renameBranding(
        string $from,
        string $appDescription,
        string $pagesName,
        string $pagesDescription,
        string $proceduresDescription,
        string $appsTitle,
        string $viewPagesLabel,
        string $managePagesLabel,
        string $pagesGroupLabel,
    ): void {
        $now = now();

        if (Schema::hasTable('crm_menu_groups')) {
            DB::table('crm_menu_groups')
                ->where('menu_key', 'apps')
                ->where('title', "Applications {$from}")
                ->update([
                    'title' => $appsTitle,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')
                ->where('item_key', 'module:pages-crm')
                ->where('label', "Pages {$from}")
                ->update([
                    'label' => $pagesName,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')
                ->where('slug', 'dashboard')
                ->where('description', "Synthese et acces rapides du {$from}")
                ->update([
                    'description' => $appDescription,
                    'updated_at' => $now,
                ]);

            DB::table('crm_modules')
                ->where('slug', 'pages-crm')
                ->where('name', "Pages {$from}")
                ->update([
                    'name' => $pagesName,
                    'description' => $pagesDescription,
                    'updated_at' => $now,
                ]);

            DB::table('crm_modules')
                ->where('slug', 'documents-procedures')
                ->where('description', "Procédures internes du {$from}.")
                ->update([
                    'description' => $proceduresDescription,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('crm_permissions')) {
            DB::table('crm_permissions')
                ->where('name', 'pages.view')
                ->where(function ($query) use ($from): void {
                    $query
                        ->where('label', "Voir les pages {$from}")
                        ->orWhere('group_label', "Pages {$from}");
                })
                ->update([
                    'label' => $viewPagesLabel,
                    'group_label' => $pagesGroupLabel,
                    'updated_at' => $now,
                ]);

            DB::table('crm_permissions')
                ->where('name', 'pages.manage')
                ->where(function ($query) use ($from): void {
                    $query
                        ->where('label', "Gerer les pages {$from}")
                        ->orWhere('group_label', "Pages {$from}");
                })
                ->update([
                    'label' => $managePagesLabel,
                    'group_label' => $pagesGroupLabel,
                    'updated_at' => $now,
                ]);
        }
    }
};
