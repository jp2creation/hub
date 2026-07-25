<?php

declare(strict_types=1);

use App\Models\CrmEquipmentCategory;
use App\Models\CrmEquipmentItem;
use App\Models\CrmEquipmentRental;
use App\Models\CrmLeaveEmployee;
use App\Models\CrmLeaveEntry;
use App\Models\CrmModule;
use App\Models\CrmPermission;
use App\Models\CrmReservation;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment('production') && getenv('CRM_E2E_ALLOW_PRODUCTION') !== '1') {
    fwrite(STDERR, "Refus de creer les fixtures E2E en production.\n");
    exit(1);
}

foreach (['users', 'crm_users', 'crm_sites', 'crm_modules', 'crm_permissions'] as $table) {
    if (! Schema::hasTable($table)) {
        fwrite(STDERR, "La table {$table} est absente. Lancez les migrations avant les tests E2E.\n");
        exit(1);
    }
}

$password = 'Playwright-E2E-Password-2026!';

$fixture = DB::transaction(function () use ($password): array {
    $account = User::query()->updateOrCreate(
        ['email' => 'crm-e2e@example.test'],
        [
            'name' => 'HUB E2E',
            'password' => $password,
        ],
    );

    $site = CrmSite::query()->updateOrCreate(
        ['name' => 'Palissy E2E'],
        [
            'slug' => 'palissy-e2e',
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
            'deleted_at' => null,
        ],
    );

    $modules = [
        ['dashboard', 'Tableau de bord', '/', 1],
        ['reservations', 'Reservations vehicules', '/reservations', 10],
        ['locations-materiel', 'Location materiel', '/locations-materiel', 15],
        ['equipes', 'Equipe', '/equipes', 18],
        ['conges', 'Conges', '/conges', 24],
        ['controle-caisse', 'Controle caisse', '/controle-caisse', 25],
        ['demandes-acompte', 'Demandes acompte', '/demandes-acompte', 26],
        ['remise-cheques', 'Remise cheques', '/remise-cheques', 27],
        ['pages-crm', 'Pages HUB', '/pages-crm', 28],
        ['documents-promo', 'Documents promo', '/documents/promo', 29],
        ['pilotage-commercial', 'Pilotage commercial', '/pilotage-commercial', 30],
    ];

    $moduleIds = [];

    foreach ($modules as [$slug, $name, $routePath, $sortOrder]) {
        $moduleIds[$slug] = CrmModule::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => 'Fixture E2E',
                'route_path' => $routePath,
                'active' => true,
                'sort_order' => $sortOrder,
            ],
        )->id;
    }

    $permissions = [
        ['reservations.view', 'Reservations', 100],
        ['reservations.create', 'Reservations', 110],
        ['reservations.update_own', 'Reservations', 120],
        ['reservations.delete_own', 'Reservations', 130],
        ['reservations.manage_vehicles', 'Reservations', 140],
        ['equipment_rentals.view', 'Location materiel', 150],
        ['equipment_rentals.create', 'Location materiel', 160],
        ['equipment_rentals.update_own', 'Location materiel', 170],
        ['equipment_rentals.delete_own', 'Location materiel', 180],
        ['equipment_rentals.manage_items', 'Location materiel', 190],
        ['conges.view', 'Conges', 200],
        ['conges.manage', 'Conges', 210],
        ['sales.view', 'Pilotage commercial', 220],
        ['teams.view', 'Equipe', 230],
        ['controle_caisse.view', 'Controle caisse', 240],
        ['deposit_requests.view', 'Demandes acompte', 250],
        ['check_remittances.view', 'Remise cheques', 260],
        ['pages.view', 'Pages HUB', 270],
        ['documents.view', 'Documents', 280],
    ];

    $permissionIds = [];

    foreach ($permissions as [$name, $group, $sortOrder]) {
        $permissionIds[] = CrmPermission::query()->updateOrCreate(
            ['name' => $name],
            [
                'label' => $name,
                'group_label' => $group,
                'sort_order' => $sortOrder,
            ],
        )->id;
    }

    $crmUser = CrmUser::query()->updateOrCreate(
        ['user_id' => $account->id],
        [
            'name' => 'HUB E2E Admin',
            'first_name' => 'HUB',
            'last_name' => 'E2E',
            'email' => $account->email,
            'phone' => '0102030405',
            'role' => 'admin',
            'active' => true,
        ],
    );

    $crmUser->sites()->syncWithoutDetaching([$site->id => ['is_default' => true]]);
    $crmUser->modules()->sync(array_values($moduleIds));
    $crmUser->permissions()->sync($permissionIds);

    $vehicle = CrmVehicle::query()->updateOrCreate(
        ['name' => 'Sprinter E2E'],
        [
            'site_id' => $site->id,
            'description' => 'Vehicule Playwright',
            'color' => '#95002e',
            'day_start_time' => '07:30',
            'day_end_time' => '17:30',
            'active' => true,
        ],
    );

    $category = CrmEquipmentCategory::query()->updateOrCreate(
        ['slug' => 'poncage-e2e'],
        [
            'name' => 'Poncage E2E',
            'active' => true,
            'sort_order' => 10,
        ],
    );

    $equipmentItem = CrmEquipmentItem::query()->updateOrCreate(
        ['inventory_code' => 'E2E-PONCEUSE'],
        [
            'site_id' => $site->id,
            'category_id' => $category->id,
            'name' => 'Ponceuse E2E',
            'description' => 'Materiel Playwright',
            'color' => '#95002e',
            'half_day_price' => 45,
            'day_price' => 80,
            'show_day_price' => true,
            'rental_mode' => 'half_day_and_day',
            'deposit_amount' => 300,
            'active' => true,
            'sort_order' => 10,
        ],
    );

    $employee = CrmLeaveEmployee::query()->updateOrCreate(
        ['slug' => 'crm-e2e-admin'],
        [
            'crm_user_id' => $crmUser->id,
            'name' => $crmUser->name,
            'color' => '#95002e',
            'active' => true,
            'sort_order' => 10,
        ],
    );

    CrmReservation::query()
        ->where('vehicle_id', $vehicle->id)
        ->where('title', 'like', 'E2E Reservation%')
        ->delete();

    CrmEquipmentRental::query()
        ->where('equipment_item_id', $equipmentItem->id)
        ->where('title', 'like', 'E2E Location%')
        ->delete();

    CrmLeaveEntry::query()
        ->where('employee_id', $employee->id)
        ->where('notes', 'like', 'E2E%')
        ->delete();

    return [
        'email' => $account->email,
        'password' => $password,
        'siteId' => (int) $site->id,
        'crmUserId' => (int) $crmUser->id,
        'vehicleId' => (int) $vehicle->id,
        'equipmentItemId' => (int) $equipmentItem->id,
        'employeeId' => (int) $employee->id,
    ];
});

fwrite(STDOUT, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
