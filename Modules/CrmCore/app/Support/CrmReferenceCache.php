<?php

namespace Modules\CrmCore\Support;

use App\Models\CrmEquipmentCategory;
use App\Models\CrmEquipmentItem;
use App\Models\CrmMenuItem;
use App\Models\CrmModule;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Support\CrmTheme;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\CrmCore\Services\CrmFeatureFlagService;
use Modules\CrmCore\Services\CrmImageStorage;

final class CrmReferenceCache
{
    public const ACTIVE_SITE_ROWS = 'hub:sites:active:rows:v3';

    public const ACTIVE_SITE_IDS = 'hub:sites:active:ids:v1';

    public const ACTIVE_MODULE_ROWS = 'hub:modules:active:rows:v1';

    public const ACTIVE_MODULE_LOOKUP = 'hub:modules:active:lookup:v1';

    public const PERMISSION_ROWS = 'hub:permissions:rows:v1';

    public const PERMISSION_ID_LOOKUP = 'hub:permissions:id-lookup:v1';

    public const ACTIVE_USER_ROWS = 'hub:users:active:rows:v1';

    public const ACTIVE_VEHICLE_ROWS = 'hub:vehicles:active:rows:v1';

    public const ACTIVE_EQUIPMENT_CATEGORY_ROWS = 'hub:equipment-categories:active:rows:v1';

    public const ACTIVE_EQUIPMENT_ITEM_ROWS = 'hub:equipment-items:active:rows:v1';

    /**
     * @return array<int, array{id: int, name: string, slug: string, address: string, phone: string, email: string, color: string, photoUrl: string, hours: array{morningStart: string, morningEnd: string, afternoonStart: string, afternoonEnd: string}}>
     */
    public static function activeSiteRows(): array
    {
        return Cache::rememberForever(self::ACTIVE_SITE_ROWS, function (): array {
            return CrmSite::query()
                ->active()
                ->orderBy('id')
                ->get()
                ->map(fn (CrmSite $site): array => [
                    'id' => (int) $site->id,
                    'name' => $site->name,
                    'slug' => $site->slug,
                    'address' => trim((string) $site->address),
                    'phone' => trim((string) $site->phone),
                    'email' => trim((string) $site->email),
                    'color' => self::siteColor($site),
                    'photoUrl' => app(CrmImageStorage::class)->normalizePublicUrl($site->photo_url),
                    'hours' => self::siteHours($site),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, int>
     */
    public static function activeSiteIds(): array
    {
        return Cache::rememberForever(self::ACTIVE_SITE_IDS, function (): array {
            return collect(self::activeSiteRows())
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        });
    }

    public static function activeSiteExists(int $siteId): bool
    {
        return in_array($siteId, self::activeSiteIds(), true);
    }

    private static function siteColor(CrmSite $site): string
    {
        $color = trim((string) $site->color);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : CrmTheme::primaryHex();
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, description: string, active: bool, sortOrder: int}>
     */
    public static function activeMenuModuleRows(): array
    {
        return Cache::rememberForever(self::ACTIVE_MODULE_ROWS, function (): array {
            $features = app(CrmFeatureFlagService::class);
            $visibleModuleSlugs = CrmMenuItem::query()
                ->where('active', true)
                ->where('item_key', 'like', 'module:%')
                ->pluck('item_key')
                ->map(fn (string $key): string => substr($key, 7))
                ->filter()
                ->values()
                ->all();

            return CrmModule::query()
                ->active()
                ->whereIn('slug', $visibleModuleSlugs)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->filter(fn (CrmModule $module): bool => $features->enabledModule($module->slug))
                ->map(fn (CrmModule $module): array => [
                    'id' => (int) $module->id,
                    'name' => $module->name,
                    'slug' => $module->slug,
                    'description' => $module->description ?? '',
                    'active' => (bool) $module->active,
                    'sortOrder' => (int) $module->sort_order,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, array{id: int, name: string, slug: string, routePath: string, sortOrder: int}>
     */
    public static function activeModuleLookup(): array
    {
        return Cache::rememberForever(self::ACTIVE_MODULE_LOOKUP, function (): array {
            $features = app(CrmFeatureFlagService::class);

            return CrmModule::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'route_path', 'sort_order'])
                ->filter(fn (CrmModule $module): bool => $features->enabledModule($module->slug))
                ->mapWithKeys(fn (CrmModule $module): array => [
                    $module->slug => [
                        'id' => (int) $module->id,
                        'name' => $module->name,
                        'slug' => $module->slug,
                        'routePath' => $module->route_path,
                        'sortOrder' => (int) $module->sort_order,
                    ],
                ])
                ->all();
        });
    }

    /**
     * @return array{id: int, name: string, slug: string, routePath: string, sortOrder: int}|null
     */
    public static function activeModule(string $slug): ?array
    {
        return self::activeModuleLookup()[$slug] ?? null;
    }

    /**
     * @return array<int, array{name: string, label: string, group: string}>
     */
    public static function permissionRows(): array
    {
        return Cache::rememberForever(self::PERMISSION_ROWS, function (): array {
            return DB::table('crm_permissions')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (object $permission): array => [
                    'name' => $permission->name,
                    'label' => $permission->label,
                    'group' => $permission->group_label,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, int>
     */
    public static function permissionIdLookup(): array
    {
        return Cache::rememberForever(self::PERMISSION_ID_LOOKUP, function (): array {
            return DB::table('crm_permissions')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('id', 'name')
                ->map(fn ($id): int => (int) $id)
                ->all();
        });
    }

    public static function permissionId(string $name): ?int
    {
        return self::permissionIdLookup()[$name] ?? null;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    public static function permissionIds(array $names): array
    {
        $lookup = self::permissionIdLookup();

        return collect($names)
            ->map(fn (string $name): ?int => $lookup[$name] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, firstName: string, lastName: string, email: string, phone: string, role: string, photoUrl: string, siteIds: array<int, int>, siteNames: array<int, string>, defaultSiteId: int|null}>
     */
    public static function activeUserRows(): array
    {
        return Cache::rememberForever(self::ACTIVE_USER_ROWS, function (): array {
            return CrmUser::query()
                ->with(['account:id,email', 'sites:id,name'])
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (CrmUser $user): array => self::userRow($user))
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array{id: int, siteId: int, name: string, description: string, color: string, photoUrl: string, dayStartTime: string, dayEndTime: string, active: bool}>
     */
    public static function activeVehicleRows(): array
    {
        return Cache::rememberForever(self::ACTIVE_VEHICLE_ROWS, function (): array {
            return CrmVehicle::query()
                ->active()
                ->orderBy('site_id')
                ->orderBy('name')
                ->get()
                ->map(fn (CrmVehicle $vehicle): array => [
                    'id' => (int) $vehicle->id,
                    'siteId' => (int) $vehicle->site_id,
                    'name' => $vehicle->name,
                    'description' => $vehicle->description ?? '',
                    'color' => $vehicle->color ?: CrmTheme::primaryHex(),
                    'photoUrl' => $vehicle->getAttribute('photo_url') ?? '',
                    'dayStartTime' => self::time5($vehicle->day_start_time, ''),
                    'dayEndTime' => self::time5($vehicle->day_end_time, ''),
                    'active' => (bool) $vehicle->active,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, active: bool, sortOrder: int}>
     */
    public static function activeEquipmentCategoryRows(): array
    {
        return Cache::rememberForever(self::ACTIVE_EQUIPMENT_CATEGORY_ROWS, function (): array {
            return CrmEquipmentCategory::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (CrmEquipmentCategory $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'active' => (bool) $category->active,
                    'sortOrder' => (int) $category->sort_order,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array{id: int, siteId: int, categoryId: int|null, name: string, inventoryCode: string, description: string, color: string, photoUrl: string, halfDayPrice: float, dayPrice: float, showDayPrice: bool, rentalMode: string, depositAmount: float, active: bool, sortOrder: int}>
     */
    public static function activeEquipmentItemRows(): array
    {
        return Cache::rememberForever(self::ACTIVE_EQUIPMENT_ITEM_ROWS, function (): array {
            return CrmEquipmentItem::query()
                ->active()
                ->orderBy('site_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (CrmEquipmentItem $item): array => [
                    'id' => (int) $item->id,
                    'siteId' => (int) $item->site_id,
                    'categoryId' => $item->category_id ? (int) $item->category_id : null,
                    'name' => $item->name,
                    'inventoryCode' => $item->inventory_code ?? '',
                    'description' => $item->description ?? '',
                    'color' => $item->color ?: CrmTheme::primaryHex(),
                    'photoUrl' => $item->getAttribute('photo_url') ?? '',
                    'halfDayPrice' => (float) $item->half_day_price,
                    'dayPrice' => (float) $item->day_price,
                    'showDayPrice' => (bool) ($item->show_day_price ?? true),
                    'rentalMode' => in_array($item->getAttribute('rental_mode'), ['half_day_and_day', 'day_only'], true)
                        ? (string) $item->getAttribute('rental_mode')
                        : 'half_day_and_day',
                    'depositAmount' => (float) $item->deposit_amount,
                    'active' => (bool) $item->active,
                    'sortOrder' => (int) $item->sort_order,
                ])
                ->values()
                ->all();
        });
    }

    public static function forgetSites(): void
    {
        Cache::forget(self::ACTIVE_SITE_ROWS);
        Cache::forget(self::ACTIVE_SITE_IDS);
    }

    public static function forgetModules(): void
    {
        Cache::forget(self::ACTIVE_MODULE_ROWS);
        Cache::forget(self::ACTIVE_MODULE_LOOKUP);
    }

    public static function forgetPermissions(): void
    {
        Cache::forget(self::PERMISSION_ROWS);
        Cache::forget(self::PERMISSION_ID_LOOKUP);
    }

    public static function forgetUsers(): void
    {
        Cache::forget(self::ACTIVE_USER_ROWS);
    }

    public static function forgetVehicles(): void
    {
        Cache::forget(self::ACTIVE_VEHICLE_ROWS);
    }

    public static function forgetEquipmentCategories(): void
    {
        Cache::forget(self::ACTIVE_EQUIPMENT_CATEGORY_ROWS);
    }

    public static function forgetEquipmentItems(): void
    {
        Cache::forget(self::ACTIVE_EQUIPMENT_ITEM_ROWS);
    }

    private static function siteHours(CrmSite $site): array
    {
        return [
            'morningStart' => self::time5($site->morning_start, '07:30'),
            'morningEnd' => self::time5($site->morning_end, '12:00'),
            'afternoonStart' => self::time5($site->afternoon_start, '13:30'),
            'afternoonEnd' => self::time5($site->afternoon_end, '17:30'),
        ];
    }

    /**
     * @return array{id: int, name: string, firstName: string, lastName: string, email: string, phone: string, role: string, photoUrl: string, siteIds: array<int, int>, siteNames: array<int, string>, defaultSiteId: int|null}
     */
    private static function userRow(CrmUser $user): array
    {
        $siteIds = $user->sites
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $defaultSite = $user->sites->first(
            fn (CrmSite $site): bool => (bool) ($site->pivot->is_default ?? false),
        );

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'firstName' => trim((string) $user->first_name),
            'lastName' => trim((string) $user->last_name),
            'email' => trim((string) $user->email) ?: trim((string) ($user->account->email ?? '')),
            'phone' => trim((string) $user->phone),
            'role' => $user->role,
            'photoUrl' => self::profilePhotoUrl($user->photo_url),
            'siteIds' => $siteIds,
            'siteNames' => $user->sites->pluck('name')->map(fn ($name): string => (string) $name)->values()->all(),
            'defaultSiteId' => $defaultSite ? (int) $defaultSite->id : ($siteIds[0] ?? null),
        ];
    }

    private static function profilePhotoUrl(?string $photoUrl): string
    {
        return app(CrmImageStorage::class)->normalizePublicUrl($photoUrl) ?: '/assets/logo/logomark.png';
    }

    private static function time5(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return preg_match('/^([0-2][0-9]:[0-5][0-9])/', $value, $match) && (int) substr($match[1], 0, 2) <= 23
            ? $match[1]
            : $default;
    }
}
