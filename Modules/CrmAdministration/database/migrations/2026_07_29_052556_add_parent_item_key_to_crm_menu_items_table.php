<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('crm_menu_items', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_menu_items', 'parent_item_key')) {
                $table->string('parent_item_key', 120)->nullable()->after('group_key');
                $table->index(['group_key', 'parent_item_key', 'sort_order'], 'crm_menu_items_group_parent_sort_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crm_menu_items', function (Blueprint $table) {
            if (Schema::hasColumn('crm_menu_items', 'parent_item_key')) {
                $table->dropIndex('crm_menu_items_group_parent_sort_idx');
                $table->dropColumn('parent_item_key');
            }
        });
    }
};
