<?php

namespace Modules\CrmLeaves\Services;

use App\Enums\CrmLeavePeriod;
use App\Enums\CrmLeaveStatus;
use App\Models\CrmLeaveBalance;
use App\Models\CrmLeaveEntry;
use App\Models\CrmLeaveStatusHistory;
use App\Models\CrmLeaveTransaction;
use App\Models\CrmUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LeaveBalanceService
{
    public function durationDays(string $startDate, string $endDate, string $period): float
    {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->startOfDay();

        if ($end->lessThan($start)) {
            return 0.0;
        }

        $periodEnum = CrmLeavePeriod::tryFrom($period) ?? CrmLeavePeriod::Full;

        if ($periodEnum->isHalfDay()) {
            return $start->isSameDay($end) ? 0.5 : 0.0;
        }

        if ((bool) config('crm.leaves.exclude_weekends', false)) {
            return $this->businessDays($start, $end);
        }

        return (float) ($start->diffInDays($end) + 1);
    }

    public function syncBalance(int $employeeId, string $type, int $year): CrmLeaveBalance
    {
        $existing = CrmLeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->where('type', $type)
            ->where('year', $year)
            ->first();

        $entitled = (float) ($existing?->entitled_days ?? config('crm.leaves.default_entitlement_days', 25));
        $carriedOver = (float) ($existing?->carried_over_days ?? 0);
        $baseQuery = CrmLeaveEntry::query()
            ->where('employee_id', $employeeId)
            ->where('type', $type)
            ->whereYear('start_date', $year);
        $used = (float) (clone $baseQuery)
            ->where('status', CrmLeaveStatus::Approved->value)
            ->sum('duration_days');
        $pending = (float) (clone $baseQuery)
            ->where('status', CrmLeaveStatus::Pending->value)
            ->sum('duration_days');
        $remaining = max(0.0, $entitled + $carriedOver - $used);

        return CrmLeaveBalance::query()->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'type' => $type,
                'year' => $year,
            ],
            [
                'entitled_days' => $entitled,
                'carried_over_days' => $carriedOver,
                'used_days' => $used,
                'pending_days' => $pending,
                'remaining_days' => $remaining,
                'recalculated_at' => now(),
            ],
        );
    }

    public function canRequest(int $employeeId, string $type, int $year, float $duration, ?int $ignoreEntryId = null): bool
    {
        $balance = $this->syncBalance($employeeId, $type, $year);

        if ($ignoreEntryId) {
            $existing = CrmLeaveEntry::query()
                ->whereKey($ignoreEntryId)
                ->where('employee_id', $employeeId)
                ->where('type', $type)
                ->where('status', CrmLeaveStatus::Approved->value)
                ->whereYear('start_date', $year)
                ->first();

            if ($existing) {
                return ($balance->remaining_days + (float) $existing->duration_days) >= $duration;
            }
        }

        return $balance->remaining_days >= $duration;
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function rowsForEmployees(array $employeeIds, int $year): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return CrmLeaveBalance::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->orderBy('type')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $balances): array => $balances
                ->map(fn (CrmLeaveBalance $balance): array => $this->balanceRow($balance))
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $old
     */
    public function recordSavedEntry(CrmLeaveEntry $entry, CrmUser $actor, array $old): void
    {
        $newStatus = (string) $entry->status;
        $oldStatus = (string) ($old['status'] ?? '');

        if (! $entry->wasRecentlyCreated && $oldStatus !== '' && $oldStatus !== $newStatus) {
            $this->recordStatusHistory($entry, $actor, $oldStatus, $newStatus, 'Modification HUB');
        }

        if ($entry->wasRecentlyCreated) {
            $this->recordStatusHistory($entry, $actor, null, $newStatus, 'Creation HUB');
        }

        $this->recordBalanceMovement($entry, $actor, $old);
        $this->syncBalance((int) $entry->employee_id, (string) $entry->type, $this->yearForEntry($entry));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function recordDeletedEntry(array $snapshot, CrmUser $actor): void
    {
        CrmLeaveStatusHistory::query()->create([
            'entry_id' => (int) $snapshot['id'],
            'employee_id' => (int) $snapshot['employee_id'],
            'from_status' => (string) $snapshot['status'],
            'to_status' => 'deleted',
            'changed_by' => $actor->id,
            'reason' => 'Suppression HUB',
            'changed_at' => now(),
        ]);

        if ((string) $snapshot['status'] === CrmLeaveStatus::Approved->value) {
            $year = CarbonImmutable::parse((string) $snapshot['start_date'])->year;
            $balance = $this->syncBalance((int) $snapshot['employee_id'], (string) $snapshot['type'], $year);

            CrmLeaveTransaction::query()->create([
                'entry_id' => (int) $snapshot['id'],
                'employee_id' => (int) $snapshot['employee_id'],
                'type' => (string) $snapshot['type'],
                'year' => $year,
                'amount_days' => (float) $snapshot['duration_days'],
                'balance_after' => $balance->remaining_days,
                'reason' => 'Suppression conge approuve',
                'created_by' => $actor->id,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceRow(CrmLeaveBalance $balance): array
    {
        return [
            'employeeId' => (int) $balance->employee_id,
            'type' => $balance->type,
            'year' => (int) $balance->year,
            'entitledDays' => (float) $balance->entitled_days,
            'carriedOverDays' => (float) $balance->carried_over_days,
            'usedDays' => (float) $balance->used_days,
            'pendingDays' => (float) $balance->pending_days,
            'remainingDays' => (float) $balance->remaining_days,
            'recalculatedAt' => $balance->recalculated_at?->toIso8601String(),
        ];
    }

    private function recordStatusHistory(CrmLeaveEntry $entry, CrmUser $actor, ?string $from, string $to, string $reason): void
    {
        CrmLeaveStatusHistory::query()->create([
            'entry_id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor->id,
            'reason' => $reason,
            'changed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $old
     */
    private function recordBalanceMovement(CrmLeaveEntry $entry, CrmUser $actor, array $old): void
    {
        $newApproved = $entry->status === CrmLeaveStatus::Approved->value;
        $oldApproved = ($old['status'] ?? null) === CrmLeaveStatus::Approved->value;
        $newDuration = (float) $entry->duration_days;
        $oldDuration = (float) ($old['duration_days'] ?? 0);

        if (! $oldApproved && ! $newApproved) {
            return;
        }

        if ($oldApproved) {
            $oldEmployeeId = (int) ($old['employee_id'] ?? $entry->employee_id);
            $oldType = (string) ($old['type'] ?? $entry->type);
            $oldYear = CarbonImmutable::parse((string) ($old['start_date'] ?? $entry->start_date))->year;
            $newSameContext = $newApproved
                && $oldEmployeeId === (int) $entry->employee_id
                && $oldType === (string) $entry->type
                && $oldYear === $this->yearForEntry($entry);

            if (! $newSameContext || abs($oldDuration - $newDuration) > 0.001) {
                $amount = $newSameContext ? $oldDuration - $newDuration : $oldDuration;
                $this->recordTransaction(
                    entry: $entry,
                    actor: $actor,
                    employeeId: $oldEmployeeId,
                    type: $oldType,
                    year: $oldYear,
                    amount: $amount,
                    reason: $newSameContext ? 'Ajustement conge approuve' : 'Annulation ancien solde conge',
                );
            }
        }

        if ($newApproved) {
            $oldSameContext = $oldApproved
                && (int) ($old['employee_id'] ?? 0) === (int) $entry->employee_id
                && (string) ($old['type'] ?? '') === (string) $entry->type
                && CarbonImmutable::parse((string) ($old['start_date'] ?? $entry->start_date))->year === $this->yearForEntry($entry);

            if (! $oldSameContext) {
                $this->recordTransaction(
                    entry: $entry,
                    actor: $actor,
                    employeeId: (int) $entry->employee_id,
                    type: (string) $entry->type,
                    year: $this->yearForEntry($entry),
                    amount: -$newDuration,
                    reason: 'Approbation conge',
                );
            }
        }
    }

    private function recordTransaction(
        CrmLeaveEntry $entry,
        CrmUser $actor,
        int $employeeId,
        string $type,
        int $year,
        float $amount,
        string $reason
    ): void {
        if (abs($amount) < 0.001) {
            return;
        }

        $balance = $this->syncBalance($employeeId, $type, $year);

        CrmLeaveTransaction::query()->create([
            'entry_id' => $entry->id,
            'employee_id' => $employeeId,
            'type' => $type,
            'year' => $year,
            'amount_days' => $amount,
            'balance_after' => $balance->remaining_days,
            'reason' => $reason,
            'created_by' => $actor->id,
        ]);
    }

    private function yearForEntry(CrmLeaveEntry $entry): int
    {
        return CarbonImmutable::parse((string) $entry->start_date)->year;
    }

    private function businessDays(CarbonImmutable $start, CarbonImmutable $end): float
    {
        $days = 0;

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            if ($date->isWeekday()) {
                $days++;
            }
        }

        return (float) $days;
    }
}
