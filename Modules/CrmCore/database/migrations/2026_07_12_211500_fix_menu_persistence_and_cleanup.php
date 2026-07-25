<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HIDDEN_MODULES = [
        'planning',
        'documents',
        'demandes',
    ];

    public function up(): void
    {
        if (Schema::hasTable('crm_modules')) {
            foreach ($this->moduleLabels() as $slug => $values) {
                DB::table('crm_modules')
                    ->where('slug', $slug)
                    ->update([
                        ...$values,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('crm_modules')
                ->whereIn('slug', self::HIDDEN_MODULES)
                ->update([
                    'active' => false,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('crm_menu_groups')) {
            DB::table('crm_menu_groups')
                ->where('menu_key', 'accounting')
                ->update([
                    'title' => 'Comptabilité',
                    'active' => true,
                    'updated_at' => now(),
                ]);

            DB::table('crm_menu_groups')
                ->whereIn('menu_key', ['dashboards', 'authentication', 'forms', 'tables', 'charts'])
                ->delete();
        }

        if (Schema::hasTable('crm_menu_items')) {
            foreach ($this->menuLabels() as $itemKey => $label) {
                DB::table('crm_menu_items')
                    ->where('item_key', $itemKey)
                    ->update([
                        'label' => $label,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('crm_menu_items')
                ->whereIn('item_key', array_map(
                    static fn (string $slug): string => 'module:'.$slug,
                    self::HIDDEN_MODULES,
                ))
                ->update([
                    'active' => false,
                    'updated_at' => now(),
                ]);

            $prefixes = ['dashboard:', 'app:', 'feature:', 'auth:', 'page:', 'form:', 'table:', 'chart:'];

            DB::table('crm_menu_items')
                ->where(function ($query) use ($prefixes): void {
                    foreach ($prefixes as $prefix) {
                        $query->orWhere('item_key', 'like', $prefix.'%');
                    }
                })
                ->delete();
        }
    }

    public function down(): void
    {
        // This migration is a production cleanup; reverting would restore broken links.
    }

    /**
     * @return array<string, array{name: string, description: string}>
     */
    private function moduleLabels(): array
    {
        return [
            'reservations' => [
                'name' => 'Réservations véhicules',
                'description' => 'Planning et réservations des véhicules',
            ],
            'locations-materiel' => [
                'name' => 'Location matériel',
                'description' => 'Planning et locations du matériel interne',
            ],
            'administration' => [
                'name' => 'Administration',
                'description' => 'Gestion des sites, modules, utilisateurs et rôles',
            ],
            'conges' => [
                'name' => 'Congés',
                'description' => 'Planning et gestion des congés',
            ],
            'controle-caisse' => [
                'name' => 'Contrôle caisse',
                'description' => 'Contrôle journalier de caisse, reports, écarts et justificatifs',
            ],
            'documents' => [
                'name' => 'Documents internes',
                'description' => 'Procédures et documents partagés',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function menuLabels(): array
    {
        return [
            'module:reservations' => 'Réservations véhicules',
            'module:locations-materiel' => 'Location matériel',
            'module:pages-crm' => 'Pages HUB',
            'module:administration' => 'Administration',
            'module:conges' => 'Congés',
            'module:controle-caisse' => 'Contrôle caisse',
            'module:planning' => 'Planning',
            'module:documents' => 'Documents internes',
            'module:demandes' => 'Demandes internes',
            'module:tapis-romus' => 'Tapis ROMUS',
        ];
    }
};
