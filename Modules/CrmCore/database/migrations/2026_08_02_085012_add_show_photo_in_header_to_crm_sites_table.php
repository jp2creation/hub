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
            if (! Schema::hasColumn('crm_sites', 'show_photo_in_header')) {
                $table->boolean('show_photo_in_header')->default(true)->after('photo_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_sites')) {
            return;
        }

        Schema::table('crm_sites', function (Blueprint $table) {
            if (Schema::hasColumn('crm_sites', 'show_photo_in_header')) {
                $table->dropColumn('show_photo_in_header');
            }
        });
    }
};
