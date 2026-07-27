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
            if (! Schema::hasColumn('crm_sites', 'address')) {
                $table->string('address')->nullable()->after('afternoon_end');
            }

            if (! Schema::hasColumn('crm_sites', 'phone')) {
                $table->string('phone', 40)->nullable()->after('address');
            }

            if (! Schema::hasColumn('crm_sites', 'email')) {
                $table->string('email', 190)->nullable()->after('phone');
            }

            if (! Schema::hasColumn('crm_sites', 'color')) {
                $table->string('color', 20)->default('#95002e')->after('email');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_sites')) {
            return;
        }

        Schema::table('crm_sites', function (Blueprint $table) {
            foreach (['color', 'email', 'phone', 'address'] as $column) {
                if (Schema::hasColumn('crm_sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
