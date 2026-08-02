<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_sites')) {
            return;
        }

        Schema::table('crm_sites', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_sites', 'sort_order')) {
                $table->integer('sort_order')->default(100)->after('active');
            }
        });

        $this->addIndex('crm_sites', 'crm_sites_active_sort_name_idx', ['active', 'sort_order', 'name']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_sites')) {
            return;
        }

        $this->dropIndex('crm_sites', 'crm_sites_active_sort_name_idx');

        Schema::table('crm_sites', function (Blueprint $table) {
            if (Schema::hasColumn('crm_sites', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (! $this->canUseColumns($table, $columns) || $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function canUseColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => strcasecmp((string) ($index['name'] ?? ''), $name) === 0);
    }
};
