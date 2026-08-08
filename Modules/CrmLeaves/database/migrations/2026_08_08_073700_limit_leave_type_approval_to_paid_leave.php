<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_leave_types')) {
            return;
        }

        DB::table('crm_leave_types')
            ->where('value', 'conge')
            ->update(['requires_approval' => true]);

        DB::table('crm_leave_types')
            ->where('value', '<>', 'conge')
            ->update(['requires_approval' => false]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_leave_types')) {
            return;
        }

        DB::table('crm_leave_types')
            ->whereIn('value', ['conge', 'rtt', 'absence', 'maladie'])
            ->update(['requires_approval' => true]);

        DB::table('crm_leave_types')
            ->where('value', 'formation')
            ->update(['requires_approval' => false]);
    }
};
