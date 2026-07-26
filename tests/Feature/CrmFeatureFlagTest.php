<?php

namespace Tests\Feature;

use App\Models\CrmFeatureFlag;
use App\Models\CrmModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\CrmCore\Support\CrmReferenceCache;
use Tests\TestCase;

class CrmFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_module_flag_removes_module_from_active_lookup(): void
    {
        Cache::flush();

        CrmModule::query()->create([
            'name' => 'Reservations',
            'slug' => 'reservations',
            'description' => '',
            'route_path' => '/reservations',
            'active' => true,
            'sort_order' => 10,
        ]);

        $this->assertArrayHasKey('reservations', CrmReferenceCache::activeModuleLookup());

        CrmFeatureFlag::query()
            ->where('flag_key', 'module:reservations')
            ->firstOrFail()
            ->update(['enabled' => false]);

        $this->assertArrayNotHasKey('reservations', CrmReferenceCache::activeModuleLookup());
    }

    public function test_feature_flag_command_can_toggle_a_module(): void
    {
        CrmFeatureFlag::query()->create([
            'flag_key' => 'module:reservations',
            'scope' => 'module',
            'name' => 'Reservations',
            'enabled' => true,
        ]);

        $this->artisan('hub:feature', [
            'key' => 'module:reservations',
            '--disable' => true,
        ])->assertExitCode(0);

        $this->assertFalse((bool) CrmFeatureFlag::query()->where('flag_key', 'module:reservations')->value('enabled'));

        $this->artisan('hub:feature', [
            'key' => 'module:reservations',
            '--enable' => true,
        ])->assertExitCode(0);

        $this->assertTrue((bool) CrmFeatureFlag::query()->where('flag_key', 'module:reservations')->value('enabled'));
    }
}
