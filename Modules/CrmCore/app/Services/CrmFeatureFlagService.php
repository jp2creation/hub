<?php

namespace Modules\CrmCore\Services;

use App\Models\CrmFeatureFlag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CrmFeatureFlagService
{
    public const CACHE_KEY = 'hub:feature-flags:v1';

    public function enabled(string $key, bool $default = true): bool
    {
        $flags = $this->flags();

        if (! array_key_exists($key, $flags)) {
            return $default;
        }

        $flag = $flags[$key];

        if (! (bool) ($flag['enabled'] ?? false)) {
            return false;
        }

        $now = now();
        $startsAt = $flag['starts_at'] ?? null;
        $endsAt = $flag['ends_at'] ?? null;

        if ($startsAt && $now->lt($startsAt)) {
            return false;
        }

        return ! ($endsAt && $now->gt($endsAt));
    }

    public function enabledModule(string $slug): bool
    {
        return $this->enabled('module:'.$slug, true);
    }

    public function set(string $key, bool $enabled): CrmFeatureFlag
    {
        $scope = str_starts_with($key, 'module:') ? 'module' : 'global';

        return CrmFeatureFlag::query()->updateOrCreate(
            ['flag_key' => $key],
            [
                'scope' => $scope,
                'enabled' => $enabled,
            ],
        );
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array{enabled: bool, starts_at: mixed, ends_at: mixed}>
     */
    private function flags(): array
    {
        if (! $this->featureTableExists()) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return CrmFeatureFlag::query()
                ->get(['flag_key', 'enabled', 'starts_at', 'ends_at'])
                ->mapWithKeys(fn (CrmFeatureFlag $flag): array => [
                    $flag->flag_key => [
                        'enabled' => (bool) $flag->enabled,
                        'starts_at' => $flag->starts_at,
                        'ends_at' => $flag->ends_at,
                    ],
                ])
                ->all();
        });
    }

    private function featureTableExists(): bool
    {
        try {
            return Schema::hasTable('crm_feature_flags');
        } catch (Throwable) {
            return false;
        }
    }
}
