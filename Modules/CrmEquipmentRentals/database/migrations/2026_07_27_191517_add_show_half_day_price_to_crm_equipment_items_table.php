<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_equipment_items') || Schema::hasColumn('crm_equipment_items', 'show_half_day_price')) {
            return;
        }

        Schema::table('crm_equipment_items', function (Blueprint $table): void {
            $column = $table->boolean('show_half_day_price')->default(true);

            if (Schema::hasColumn('crm_equipment_items', 'show_day_price')) {
                $column->after('show_day_price');
            } else {
                $column->after('day_price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_equipment_items') || ! Schema::hasColumn('crm_equipment_items', 'show_half_day_price')) {
            return;
        }

        Schema::table('crm_equipment_items', function (Blueprint $table): void {
            $table->dropColumn('show_half_day_price');
        });
    }
};
