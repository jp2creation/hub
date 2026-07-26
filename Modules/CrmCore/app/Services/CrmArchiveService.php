<?php

namespace Modules\CrmCore\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class CrmArchiveService
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $archiveColumns = [];

    /**
     * @param  array<string, mixed>|null  $modelConfig
     */
    public function cutoffDate(?int $years = null, ?array $modelConfig = null): CarbonImmutable
    {
        $years = max(1, $years ?: (int) ($modelConfig['retention_years'] ?? config('archive.defaults.retention_years', 2)));

        return CarbonImmutable::now()->subYears($years)->startOfDay();
    }

    public function batchSize(?int $limit = null): int
    {
        return max(1, min($limit ?: (int) config('archive.defaults.batch_size', 500), 5000));
    }

    /**
     * @return array{models: array<string, array{model: string, label: string, archive_table: string, cutoff: CarbonImmutable, count: int}>, total: int}
     */
    public function countArchiveCandidates(?int $years = null, ?string $modelFilter = null): array
    {
        $counts = [
            'models' => [],
            'total' => 0,
        ];

        foreach ($this->configuredModels($modelFilter) as $modelClass => $modelConfig) {
            $cutoff = $this->cutoffDate($years, $modelConfig);
            $key = $this->modelKey($modelClass, $modelConfig);
            $count = $this->candidateQuery($modelClass, $modelConfig, $cutoff)->count();

            $counts['models'][$key] = [
                'model' => $modelClass,
                'label' => $this->modelLabel($modelClass, $modelConfig),
                'archive_table' => (string) $modelConfig['archive_table'],
                'cutoff' => $cutoff,
                'count' => $count,
            ];
            $counts['total'] += $count;
        }

        return $counts;
    }

    /**
     * @return array{models: array<string, array{model: string, label: string, archive_table: string, cutoff: CarbonImmutable, archived: int}>, total: int}
     *
     * @throws JsonException
     */
    public function archiveOlderThan(?int $years = null, ?int $limit = null, ?string $modelFilter = null): array
    {
        $limit = $this->batchSize($limit);
        $totals = [
            'models' => [],
            'total' => 0,
        ];

        foreach ($this->configuredModels($modelFilter) as $modelClass => $modelConfig) {
            $cutoff = $this->cutoffDate($years, $modelConfig);
            $key = $this->modelKey($modelClass, $modelConfig);
            $archiveTable = (string) $modelConfig['archive_table'];
            $archived = $this->archiveQuery(
                query: $this->candidateQuery($modelClass, $modelConfig, $cutoff),
                modelClass: $modelClass,
                modelConfig: $modelConfig,
                archiveTable: $archiveTable,
                limit: $limit,
            );

            $totals['models'][$key] = [
                'model' => $modelClass,
                'label' => $this->modelLabel($modelClass, $modelConfig),
                'archive_table' => $archiveTable,
                'cutoff' => $cutoff,
                'archived' => $archived,
            ];
            $totals['total'] += $archived;
        }

        return $totals;
    }

    /**
     * @return array<class-string<Model>, array<string, mixed>>
     */
    public function configuredModels(?string $modelFilter = null): array
    {
        $models = config('archive.models', []);

        if (! is_array($models)) {
            return [];
        }

        if ($modelFilter === null || $modelFilter === '') {
            /** @var array<class-string<Model>, array<string, mixed>> $models */
            return $models;
        }

        $matches = [];

        foreach ($models as $modelClass => $modelConfig) {
            if (! is_array($modelConfig)) {
                continue;
            }

            $aliases = array_filter([
                $modelClass,
                class_basename($modelClass),
                $this->modelKey($modelClass, $modelConfig),
                $modelConfig['archive_table'] ?? null,
            ]);

            if (in_array($modelFilter, $aliases, true)) {
                $matches[$modelClass] = $modelConfig;
            }
        }

        if ($matches === []) {
            throw new InvalidArgumentException("Le modele {$modelFilter} n'est pas configure pour l'archivage.");
        }

        /** @var array<class-string<Model>, array<string, mixed>> $matches */
        return $matches;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $modelConfig
     */
    private function candidateQuery(string $modelClass, array $modelConfig, CarbonInterface $cutoff): Builder
    {
        if (! class_exists($modelClass)) {
            throw new RuntimeException("Modele {$modelClass} introuvable.");
        }

        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Table {$table} introuvable.");
        }

        $dateColumn = (string) ($modelConfig['date_column'] ?? 'created_at');

        if (! Schema::hasColumn($table, $dateColumn)) {
            throw new RuntimeException("Colonne {$dateColumn} introuvable sur {$table}.");
        }

        /** @var Builder $query */
        $query = $modelClass::query();

        if (($modelConfig['with_trashed'] ?? false) && in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        $relations = $this->relations($modelConfig);
        if ($relations !== []) {
            $query->with($relations);
        }

        if (($modelConfig['date_mode'] ?? 'datetime') === 'date') {
            $query->whereDate($dateColumn, '<', $cutoff->toDateString());
        } else {
            $query->where($dateColumn, '<', $cutoff);
        }

        $this->applyConditions($query, $modelConfig['conditions'] ?? []);

        return $query->orderBy($model->getQualifiedKeyName());
    }

    /**
     * @throws JsonException
     */
    private function archiveQuery(Builder $query, string $modelClass, array $modelConfig, string $archiveTable, int $limit): int
    {
        if (! Schema::hasTable($archiveTable)) {
            throw new RuntimeException("Table d'archive {$archiveTable} introuvable.");
        }

        $records = $query
            ->limit($limit)
            ->get();

        if ($records->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($records, $modelClass, $modelConfig, $archiveTable): int {
            $archived = 0;

            foreach ($records as $record) {
                DB::table($archiveTable)->updateOrInsert(
                    ['original_id' => $record->getKey()],
                    $this->archivePayload($record, $modelClass, $modelConfig, $archiveTable),
                );

                $this->deleteChildren($record, $modelConfig);
                $this->deleteRecord($record, $modelConfig);
                $archived++;
            }

            return $archived;
        });
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $modelConfig
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function archivePayload(Model $record, string $modelClass, array $modelConfig, string $archiveTable): array
    {
        $dateColumn = (string) ($modelConfig['date_column'] ?? 'created_at');
        $startColumn = (string) ($modelConfig['start_column'] ?? '');
        $payload = [
            'original_model' => $modelClass,
            'original_table' => $record->getTable(),
            'original_id' => $record->getKey(),
            'data' => json_encode($record->getAttributes(), JSON_THROW_ON_ERROR),
            'archived_at' => now(),
            'archived_by' => 'hub:archive',
            'original_started_at' => $startColumn !== '' ? $record->getAttribute($startColumn) : null,
            'original_ended_at' => $record->getAttribute($dateColumn),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $data = $record->getAttributes();
        $relations = $this->relationPayload($record, $modelConfig);

        if ($relations !== []) {
            $data['_relations'] = $relations;
        }

        $data['_archive'] = [
            'model' => $modelClass,
            'table' => $record->getTable(),
            'archived_at' => now()->toIso8601String(),
        ];

        $payload['data'] = json_encode($data, JSON_THROW_ON_ERROR);

        return array_intersect_key($payload, array_flip($this->archiveColumns($archiveTable)));
    }

    /**
     * @param  array<string, mixed>  $modelConfig
     */
    private function applyConditions(Builder $query, mixed $conditions): void
    {
        if (! is_array($conditions)) {
            return;
        }

        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $query->whereNull((string) $column);
            } elseif (is_array($value)) {
                $query->whereIn((string) $column, $value);
            } else {
                $query->where((string) $column, $value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $modelConfig
     * @return array<int, string>
     */
    private function relations(array $modelConfig): array
    {
        $relations = $modelConfig['relations'] ?? [];

        if (! is_array($relations)) {
            return [];
        }

        return array_values(array_filter($relations, 'is_string'));
    }

    /**
     * @param  array<string, mixed>  $modelConfig
     * @return array<string, mixed>
     */
    private function relationPayload(Model $record, array $modelConfig): array
    {
        $payload = [];

        foreach ($this->relations($modelConfig) as $relationName) {
            if (! $record->relationLoaded($relationName)) {
                continue;
            }

            $relation = $record->getRelation($relationName);

            if ($relation instanceof EloquentCollection) {
                $payload[$relationName] = $relation
                    ->map(static fn (Model $child): array => $child->getAttributes())
                    ->values()
                    ->all();
            } elseif ($relation instanceof Model) {
                $payload[$relationName] = $relation->getAttributes();
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $modelConfig
     */
    private function deleteChildren(Model $record, array $modelConfig): void
    {
        $children = $modelConfig['delete_children'] ?? [];

        if (! is_array($children)) {
            return;
        }

        foreach ($children as $childConfig) {
            if (! is_array($childConfig)) {
                continue;
            }

            $table = (string) ($childConfig['table'] ?? '');
            $foreignKey = (string) ($childConfig['foreign_key'] ?? '');

            if ($table === '' || $foreignKey === '' || ! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->where($foreignKey, $record->getKey())->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $modelConfig
     */
    private function deleteRecord(Model $record, array $modelConfig): void
    {
        if (($modelConfig['force_delete'] ?? true) && in_array(SoftDeletes::class, class_uses_recursive($record::class), true)) {
            $record->forceDelete();

            return;
        }

        $record->delete();
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $modelConfig
     */
    private function modelKey(string $modelClass, array $modelConfig): string
    {
        return (string) ($modelConfig['key'] ?? Str::snake(class_basename($modelClass)));
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $modelConfig
     */
    private function modelLabel(string $modelClass, array $modelConfig): string
    {
        return (string) ($modelConfig['label'] ?? class_basename($modelClass));
    }

    /**
     * @return array<int, string>
     */
    private function archiveColumns(string $archiveTable): array
    {
        if (! array_key_exists($archiveTable, $this->archiveColumns)) {
            $this->archiveColumns[$archiveTable] = Schema::getColumnListing($archiveTable);
        }

        return $this->archiveColumns[$archiveTable];
    }
}
