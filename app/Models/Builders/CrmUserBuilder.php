<?php

namespace App\Models\Builders;

use App\Models\CrmUser;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<CrmUser>
 */
class CrmUserBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return CrmUser
     */
    public function create(array $attributes = [])
    {
        if ($this->legacyUserId($attributes) !== null && ($record = $this->legacyRecordFromAttributes($attributes)) instanceof CrmUser) {
            unset($attributes['user_id']);

            $record->fill($attributes)->save();

            return $record;
        }

        unset($attributes['user_id']);

        /** @var CrmUser $record */
        $record = parent::create($attributes);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(): array<string, mixed>)|array<string, mixed>  $values
     * @return CrmUser
     */
    public function firstOrCreate(array $attributes = [], Closure|array $values = [])
    {
        if ($this->legacyUserId($attributes) !== null) {
            $record = $this->legacyRecordFromAttributes($attributes);
            unset($attributes['user_id']);
            $resolvedValues = value($values);

            if ($record instanceof CrmUser) {
                if ($resolvedValues !== []) {
                    $record->fill($resolvedValues)->save();
                }

                return $record;
            }

            /** @var CrmUser $record */
            $record = parent::create([...$attributes, ...$resolvedValues]);

            return $record;
        }

        /** @var CrmUser $record */
        $record = parent::firstOrCreate($attributes, $values);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(): array<string, mixed>)|array<string, mixed>  $values
     * @return CrmUser
     */
    public function updateOrCreate(array $attributes, Closure|array $values = [])
    {
        if ($this->legacyUserId($attributes) !== null) {
            $record = $this->legacyRecordFromAttributes($attributes);
            unset($attributes['user_id']);
            $resolvedValues = value($values);

            if ($record instanceof CrmUser) {
                $record->fill([...$attributes, ...$resolvedValues])->save();

                return $record;
            }

            /** @var CrmUser $record */
            $record = parent::create([...$attributes, ...$resolvedValues]);

            return $record;
        }

        /** @var CrmUser $record */
        $record = parent::updateOrCreate($attributes, $values);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function legacyRecordFromAttributes(array $attributes): ?CrmUser
    {
        $legacyUserId = $this->legacyUserId($attributes);

        if ($legacyUserId === null) {
            return null;
        }

        /** @var CrmUser|null $record */
        $record = (clone $this)->whereKey($legacyUserId)->first();

        return $record;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function legacyUserId(array $attributes): ?int
    {
        $legacyUserId = (int) ($attributes['user_id'] ?? 0);

        return $legacyUserId > 0 ? $legacyUserId : null;
    }
}
