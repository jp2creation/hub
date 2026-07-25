<?php

namespace Tests\Feature;

use Modules\CrmCore\Support\CrmModuleStandard;
use Tests\TestCase;

class CrmModuleManifestTest extends TestCase
{
    public function test_crm_modules_have_explicit_provider_order(): void
    {
        $modules = collect(glob(base_path('Modules/*/module.json')) ?: [])
            ->map(function (string $path): array {
                $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $manifest['path'] = $path;

                return $manifest;
            });

        $this->assertNotEmpty($modules);
        $this->assertSame($modules->count(), $modules->pluck('priority')->unique()->count());

        $byName = $modules->keyBy('name');

        $this->assertLessThan(
            $byName['CrmAdministration']['priority'],
            $byName['CrmCore']['priority'],
        );
        $this->assertLessThan(
            $byName['CrmDocuments']['priority'],
            $byName['CrmAdministration']['priority'],
        );
        $this->assertLessThan(
            $byName['CrmCheckRemittances']['priority'],
            $byName['CrmCashControl']['priority'],
        );

        foreach ($modules as $module) {
            foreach (CrmModuleStandard::requiredManifestKeys() as $key) {
                $this->assertArrayHasKey($key, $module, $module['name'].' doit declarer '.$key.' dans module.json.');
            }

            $this->assertNotEmpty($module['providers'], $module['name'].' doit declarer au moins un provider.');
            $moduleRoot = dirname((string) $module['path']);

            foreach (CrmModuleStandard::requiredPathGroups(($module['name'] ?? '') === 'CrmCore') as $paths) {
                $exists = collect($paths)->contains(fn (string $path): bool => file_exists($moduleRoot.'/'.$path));
                $this->assertTrue($exists, $module['name'].' doit fournir '.implode(' ou ', $paths).'.');
            }

            foreach ($module['providers'] as $provider) {
                $this->assertTrue(class_exists($provider), $provider.' est introuvable.');
            }
        }

        if ($byName->has('Notification') && $byName->has('Invoice')) {
            $this->assertLessThan(
                $byName['Invoice']['priority'],
                $byName['Notification']['priority'],
            );
        }
    }

    public function test_crm_modules_keep_laravel_classes_under_app_directory(): void
    {
        $moduleRoots = collect(glob(base_path('Modules/*')) ?: [])
            ->filter(fn (string $path): bool => is_dir($path))
            ->values();

        $this->assertNotEmpty($moduleRoots);

        foreach ($moduleRoots as $moduleRoot) {
            $moduleName = basename($moduleRoot);

            foreach (['Http', 'Providers', 'Services'] as $legacyDirectory) {
                $this->assertDirectoryDoesNotExist(
                    $moduleRoot.'/'.$legacyDirectory,
                    "{$moduleName} doit placer {$legacyDirectory} sous app/{$legacyDirectory}.",
                );
            }
        }
    }

    public function test_crm_migrations_live_inside_module_database_directories(): void
    {
        $rootCrmMigrations = collect(glob(database_path('migrations/*.php')) ?: [])
            ->map(fn (string $path): string => basename($path))
            ->filter(fn (string $migration): bool => str_contains($migration, 'crm_')
                || str_contains($migration, 'dashboard_metrics')
                || str_contains($migration, 'notification_logs'))
            ->values()
            ->all();

        $this->assertSame([], $rootCrmMigrations, 'Les migrations HUB doivent vivre dans Modules/*/database/migrations.');

        $moduleMigrationDirs = collect(glob(base_path('Modules/*/database/migrations')) ?: [])
            ->map(fn (string $path): string => basename(dirname(dirname($path))))
            ->sort()
            ->values()
            ->all();

        $moduleNames = collect(glob(base_path('Modules/*/module.json')) ?: [])
            ->map(fn (string $path): string => basename(dirname($path)))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($moduleNames, $moduleMigrationDirs);
    }
}
