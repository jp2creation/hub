<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_modules', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_modules', 'menu_badge_color')) {
                $table->string('menu_badge_color', 7)->nullable()->after('menu_badge');
            }
        });

        if (Schema::hasColumn('crm_modules', 'menu_badge_color')) {
            DB::table('crm_modules')
                ->where('slug', 'reservations')
                ->whereNull('menu_badge_color')
                ->update(['menu_badge_color' => '#95002e']);
        }
    }

    public function down(): void
    {
        Schema::table('crm_modules', function (Blueprint $table) {
            if (Schema::hasColumn('crm_modules', 'menu_badge_color')) {
                $table->dropColumn('menu_badge_color');
            }
        });
    }
};
