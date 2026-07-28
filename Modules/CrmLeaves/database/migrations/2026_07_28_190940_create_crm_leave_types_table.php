<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_leave_types')) {
            Schema::create('crm_leave_types', function (Blueprint $table): void {
                $table->id();
                $table->string('value', 40)->unique();
                $table->string('label', 80);
                $table->string('color', 20)->default('#38bdf8');
                $table->boolean('active')->default(true);
                $table->boolean('requires_balance')->default(false);
                $table->boolean('requires_approval')->default(true);
                $table->boolean('send_reminders')->default(true);
                $table->boolean('is_system')->default(false);
                $table->integer('sort_order')->default(100);
                $table->timestamps();

                $table->index(['active', 'sort_order']);
            });
        }

        $now = now();

        $types = [
            ['conge', 'Congé', '#facc15', true, true, true, true, 10],
            ['rtt', 'RTT', '#38bdf8', true, true, true, true, 20],
            ['absence', 'Absence', '#fb7185', false, true, true, true, 30],
            ['formation', 'Formation', '#a78bfa', false, false, false, true, 40],
            ['maladie', 'Arrêt maladie', '#94a3b8', false, true, true, true, 50],
        ];

        foreach ($types as [$value, $label, $color, $requiresBalance, $requiresApproval, $sendReminders, $isSystem, $sortOrder]) {
            DB::table('crm_leave_types')->updateOrInsert(
                ['value' => $value],
                [
                    'label' => $label,
                    'color' => $color,
                    'active' => true,
                    'requires_balance' => $requiresBalance,
                    'requires_approval' => $requiresApproval,
                    'send_reminders' => $sendReminders,
                    'is_system' => $isSystem,
                    'sort_order' => $sortOrder,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        if (! Schema::hasTable('crm_permissions')) {
            return;
        }

        DB::table('crm_permissions')->updateOrInsert(
            ['name' => 'conges.manage_types'],
            [
                'label' => "Gérer les types d'absence",
                'group_label' => 'Congés & Absences',
                'sort_order' => 147,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        if (! Schema::hasTable('crm_user_permissions')) {
            return;
        }

        $permissionId = (int) DB::table('crm_permissions')->where('name', 'conges.manage_types')->value('id');
        if ($permissionId <= 0) {
            return;
        }

        DB::table('crm_users')
            ->where('active', true)
            ->whereIn('role', ['admin', 'responsable'])
            ->pluck('id')
            ->each(function ($userId) use ($permissionId, $now): void {
                DB::table('crm_user_permissions')->updateOrInsert(
                    ['permission_id' => $permissionId, 'user_id' => (int) $userId],
                    ['created_at' => $now],
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leave_types');

        if (! Schema::hasTable('crm_permissions')) {
            return;
        }

        $permissionIds = DB::table('crm_permissions')
            ->where('name', 'conges.manage_types')
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            if (Schema::hasTable('crm_user_permissions')) {
                DB::table('crm_user_permissions')->whereIn('permission_id', $permissionIds)->delete();
            }

            if (Schema::hasTable('crm_user_site_module_permissions')) {
                DB::table('crm_user_site_module_permissions')->whereIn('permission_id', $permissionIds)->delete();
            }
        }

        DB::table('crm_permissions')->where('name', 'conges.manage_types')->delete();
    }
};
