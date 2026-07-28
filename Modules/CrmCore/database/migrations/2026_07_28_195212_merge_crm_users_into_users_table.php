<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $this->addHubProfileColumns();

        if (! Schema::hasTable('crm_users')) {
            return;
        }

        $idMap = $this->mergeProfilesIntoUsers();

        if ($idMap === []) {
            return;
        }

        $this->dropForeignKeysReferencing('crm_users');
        $this->ensureLegacyRowsForRemappedUsers($idMap);
        $this->remapPivotTables($idMap);
        $this->remapReferenceColumns($idMap);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            foreach (['first_name', 'last_name', 'phone', 'bio', 'photo_url', 'role', 'active'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addHubProfileColumns(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name', 80)->nullable()->after('name');
            }

            if (! Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name', 80)->nullable()->after('first_name');
            }

            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 40)->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'bio')) {
                $table->string('bio', 255)->nullable()->after('phone');
            }

            if (! Schema::hasColumn('users', 'photo_url')) {
                $table->string('photo_url', 255)->nullable()->after('bio');
            }

            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 40)->default('user')->after('photo_url');
            }

            if (! Schema::hasColumn('users', 'active')) {
                $table->boolean('active')->default(true)->after('role');
            }
        });
    }

    /**
     * @return array<int, int>
     */
    private function mergeProfilesIntoUsers(): array
    {
        $idMap = [];
        $now = now();

        DB::table('crm_users')
            ->orderBy('id')
            ->get()
            ->each(function (object $crmUser) use (&$idMap, $now): void {
                $userId = $this->resolveTargetUserId($crmUser, $now);

                DB::table('users')
                    ->where('id', $userId)
                    ->update([
                        'name' => $this->nonEmpty($crmUser->name ?? null, 'Utilisateur HUB'),
                        'first_name' => $this->nullableString($crmUser->first_name ?? null),
                        'last_name' => $this->nullableString($crmUser->last_name ?? null),
                        'phone' => $this->nullableString($crmUser->phone ?? null),
                        'bio' => $this->nullableString($crmUser->bio ?? null),
                        'photo_url' => $this->nullableString($crmUser->photo_url ?? null),
                        'role' => $this->nonEmpty($crmUser->role ?? null, 'user'),
                        'active' => (bool) ($crmUser->active ?? true),
                        'updated_at' => $now,
                    ]);

                $idMap[(int) $crmUser->id] = $userId;
            });

        return $idMap;
    }

    private function resolveTargetUserId(object $crmUser, mixed $now): int
    {
        $linkedUserId = (int) ($crmUser->user_id ?? 0);

        if ($linkedUserId > 0 && DB::table('users')->where('id', $linkedUserId)->exists()) {
            return $linkedUserId;
        }

        $email = $this->nullableString($crmUser->email ?? null);
        if ($email !== null) {
            $existingId = DB::table('users')->where('email', $email)->value('id');

            if ($existingId) {
                return (int) $existingId;
            }
        }

        if ($email === null) {
            $generatedEmail = $this->baseGeneratedEmail((int) $crmUser->id, (string) ($crmUser->name ?? 'user'));
            $existingGeneratedId = DB::table('users')->where('email', $generatedEmail)->value('id');

            if ($existingGeneratedId) {
                return (int) $existingGeneratedId;
            }

            $email = $this->generatedEmail((int) $crmUser->id, (string) ($crmUser->name ?? 'user'));
        }

        return (int) DB::table('users')->insertGetId([
            'name' => $this->nonEmpty($crmUser->name ?? null, 'Utilisateur HUB'),
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            'created_at' => $crmUser->created_at ?? $now,
            'updated_at' => $crmUser->updated_at ?? $now,
        ]);
    }

    /**
     * @param  array<int, int>  $idMap
     */
    private function ensureLegacyRowsForRemappedUsers(array $idMap): void
    {
        if (DB::connection()->getDriverName() === 'mysql' || ! Schema::hasTable('crm_users')) {
            return;
        }

        $now = now();
        foreach (array_unique(array_values($idMap)) as $userId) {
            $userId = (int) $userId;

            if ($userId <= 0 || DB::table('crm_users')->where('id', $userId)->exists()) {
                continue;
            }

            $user = DB::table('users')->where('id', $userId)->first();
            if (! $user) {
                continue;
            }

            DB::table('crm_users')->insert([
                'id' => $userId,
                'name' => $this->legacyName($userId),
                'first_name' => $this->nullableString($user->first_name ?? null),
                'last_name' => $this->nullableString($user->last_name ?? null),
                'email' => null,
                'bio' => $this->nullableString($user->bio ?? null),
                'photo_url' => $this->nullableString($user->photo_url ?? null),
                'role' => $this->nonEmpty($user->role ?? null, 'user'),
                'active' => (bool) ($user->active ?? true),
                'user_id' => null,
                'phone' => $this->nullableString($user->phone ?? null),
                'created_at' => $user->created_at ?? $now,
                'updated_at' => $user->updated_at ?? $now,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $idMap
     */
    private function remapPivotTables(array $idMap): void
    {
        $this->remapPivotTable('crm_user_sites', $idMap, ['site_id', 'user_id'], ['site_id', 'user_id', 'is_default', 'created_at']);
        $this->remapPivotTable('crm_user_modules', $idMap, ['module_id', 'user_id'], ['module_id', 'user_id', 'created_at']);
        $this->remapPivotTable('crm_user_permissions', $idMap, ['permission_id', 'user_id'], ['permission_id', 'user_id', 'created_at']);
        $this->remapPivotTable('crm_user_site_module_permissions', $idMap, ['user_id', 'site_id', 'module_id', 'permission_id'], ['user_id', 'site_id', 'module_id', 'permission_id', 'created_at', 'updated_at']);
    }

    /**
     * @param  array<int, int>  $idMap
     * @param  array<int, string>  $uniqueColumns
     * @param  array<int, string>  $columns
     */
    private function remapPivotTable(string $table, array $idMap, array $uniqueColumns, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return;
        }

        $oldIds = array_keys($idMap);
        $rows = DB::table($table)
            ->whereIn('user_id', $oldIds)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        DB::table($table)->whereIn('user_id', $oldIds)->delete();

        $mappedRows = [];
        foreach ($rows as $row) {
            $mapped = [];
            foreach ($columns as $column) {
                $mapped[$column] = $column === 'user_id'
                    ? ($idMap[(int) $row->user_id] ?? (int) $row->user_id)
                    : ($row->{$column} ?? null);
            }

            $key = implode(':', array_map(fn (string $column): string => (string) ($mapped[$column] ?? ''), $uniqueColumns));
            $mappedRows[$key] = $mapped;
        }

        foreach (array_values($mappedRows) as $row) {
            DB::table($table)->insertOrIgnore($row);
        }
    }

    /**
     * @param  array<int, int>  $idMap
     */
    private function remapReferenceColumns(array $idMap): void
    {
        foreach ($this->crmUserReferenceColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach ($idMap as $oldId => $newId) {
                    if ($oldId === $newId) {
                        continue;
                    }

                    DB::table($table)
                        ->where($column, $oldId)
                        ->update([$column => $newId]);
                }
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function crmUserReferenceColumns(): array
    {
        return [
            'crm_reservations' => ['user_id'],
            'crm_equipment_rentals' => ['user_id'],
            'crm_leave_employees' => ['crm_user_id'],
            'crm_leave_entries' => ['created_by', 'updated_by'],
            'crm_leave_status_histories' => ['changed_by'],
            'crm_leave_transactions' => ['created_by'],
            'crm_deposit_requests' => ['requester_user_id', 'validated_by', 'created_by', 'updated_by'],
            'crm_check_remittances' => ['created_by', 'updated_by'],
            'crm_check_remittance_lines' => ['created_by', 'updated_by'],
            'crm_cash_register_days' => ['created_by', 'updated_by'],
            'crm_cash_movements' => ['uploaded_by'],
            'crm_cash_receipts' => ['created_by', 'updated_by'],
            'crm_cash_receipt_archives' => ['created_by', 'updated_by'],
            'crm_document_directories' => ['created_by', 'updated_by'],
            'crm_documents' => ['created_by', 'updated_by'],
            'crm_sales_tours' => ['representative_user_id', 'created_by', 'updated_by'],
            'crm_sales_visits' => ['representative_user_id', 'created_by', 'updated_by'],
            'crm_sales_invoices' => ['representative_user_id'],
            'crm_sales_objectives' => ['representative_user_id', 'created_by', 'updated_by'],
            'crm_sales_commissions' => ['representative_user_id', 'created_by', 'updated_by'],
            'crm_logs' => ['user_id'],
        ];
    }

    private function dropForeignKeysReferencing(string $referencedTable): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $constraints = DB::select(
            'select TABLE_NAME as table_name, CONSTRAINT_NAME as constraint_name
             from information_schema.KEY_COLUMN_USAGE
             where TABLE_SCHEMA = DATABASE()
               and REFERENCED_TABLE_NAME = ?',
            [$referencedTable],
        );

        foreach ($constraints as $constraint) {
            DB::statement(sprintf(
                'alter table `%s` drop foreign key `%s`',
                str_replace('`', '``', (string) $constraint->table_name),
                str_replace('`', '``', (string) $constraint->constraint_name),
            ));
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nonEmpty(mixed $value, string $fallback): string
    {
        return $this->nullableString($value) ?? $fallback;
    }

    private function generatedEmail(int $crmUserId, string $name): string
    {
        $email = $this->baseGeneratedEmail($crmUserId, $name);
        $suffix = 1;

        while (DB::table('users')->where('email', $email)->exists()) {
            $base = Str::before($this->baseGeneratedEmail($crmUserId, $name), '@hub.local');
            $email = "{$base}-{$suffix}@hub.local";
            $suffix++;
        }

        return $email;
    }

    private function baseGeneratedEmail(int $crmUserId, string $name): string
    {
        $base = Str::slug($name) ?: 'hub-user';

        return "{$base}-{$crmUserId}@hub.local";
    }

    private function legacyName(int $userId): string
    {
        $name = "legacy-hub-user-{$userId}";
        $suffix = 1;

        while (DB::table('crm_users')->where('name', $name)->exists()) {
            $name = "legacy-hub-user-{$userId}-{$suffix}";
            $suffix++;
        }

        return $name;
    }
};
