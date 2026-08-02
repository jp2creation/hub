<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_menu_items') || Schema::hasColumn('crm_menu_items', 'deleted_at')) {
            return;
        }

        Schema::table('crm_menu_items', function (Blueprint $table) {
            $table->softDeletes()->after('parent_item_key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_menu_items') || ! Schema::hasColumn('crm_menu_items', 'deleted_at')) {
            return;
        }

        Schema::table('crm_menu_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
