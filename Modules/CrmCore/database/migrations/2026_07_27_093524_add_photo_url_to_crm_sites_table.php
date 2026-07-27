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
            if (! Schema::hasColumn('crm_sites', 'photo_url')) {
                $table->string('photo_url', 255)->nullable()->after('color');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_sites')) {
            return;
        }

        Schema::table('crm_sites', function (Blueprint $table) {
            if (Schema::hasColumn('crm_sites', 'photo_url')) {
                $table->dropColumn('photo_url');
            }
        });
    }
};
