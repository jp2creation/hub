<?php

namespace Modules\CrmCore\Console\Commands;

use App\Models\CrmCashReceipt;
use App\Models\CrmEquipmentItem;
use App\Models\CrmEquipmentRental;
use App\Models\CrmLeaveEntry;
use App\Models\CrmReservation;
use App\Models\CrmSite;
use App\Models\DashboardMetric;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RefreshDashboardMetricsCommand extends Command
{
    protected $signature = 'hub:refresh-dashboard-metrics
        {--site= : Limiter le recalcul a un site}
        {--date= : Date de reference YYYY-MM-DD}';

    protected $aliases = ['crm:refresh-dashboard-metrics'];

    protected $description = 'Refresh pre-aggregated HUB dashboard metrics.';

    public function handle(): int
    {
        $timezone = config('crm.display_timezone', config('app.timezone', 'Europe/Paris'));
        $today = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), $timezone)->startOfDay()
            : CarbonImmutable::today($timezone);
        $clock = CarbonImmutable::now($timezone);
        $referenceNow = $today->setTime($clock->hour, $clock->minute, $clock->second);

        $siteId = (int) ($this->option('site') ?: 0);
        $sites = CrmSite::query()
            ->active()
            ->when($siteId > 0, fn ($query) => $query->whereKey($siteId))
            ->orderedForHub()
            ->get(['id', 'name']);

        if ($siteId > 0 && $sites->isEmpty()) {
            $this->error('Site introuvable ou inactif : '.$siteId);

            return self::FAILURE;
        }

        foreach ($sites as $site) {
            $metrics = $this->metricsForSite((int) $site->id, $today, $referenceNow);

            DashboardMetric::query()->updateOrCreate(
                [
                    'site_id' => (int) $site->id,
                    'metric_date' => $today->toDateString(),
                ],
                [
                    ...$metrics,
                    'generated_at' => now(),
                ],
            );
        }

        $this->info('Metriques dashboard recalculees : '.$sites->count());

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function metricsForSite(int $siteId, CarbonImmutable $today, CarbonImmutable $referenceNow): array
    {
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        [$equipmentAvailable, $equipmentTotal] = $this->equipmentAvailability($siteId, $referenceNow);

        return [
            'reservations_today' => $this->reservationsToday($siteId, $today),
            'monthly_revenue' => $this->monthlyRevenue($siteId, $monthStart, $monthEnd),
            'pending_leaves' => $this->pendingLeaves($siteId),
            'equipment_available' => $equipmentAvailable,
            'equipment_total' => $equipmentTotal,
            'reservation_trend' => $this->reservationTrend($siteId, $today),
        ];
    }

    private function reservationsToday(int $siteId, CarbonImmutable $today): int
    {
        return CrmReservation::query()
            ->where('site_id', $siteId)
            ->where('start_at', '<=', $today->endOfDay())
            ->where('end_at', '>=', $today->startOfDay())
            ->count();
    }

    private function monthlyRevenue(int $siteId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): float
    {
        return (float) CrmCashReceipt::query()
            ->join('crm_cash_register_days as days', 'days.id', '=', 'crm_cash_receipts.cash_register_day_id')
            ->where('days.site_id', $siteId)
            ->whereBetween('crm_cash_receipts.occurred_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('crm_cash_receipts.invoice_total');
    }

    private function pendingLeaves(int $siteId): int
    {
        return CrmLeaveEntry::query()
            ->join('crm_leave_employees as employees', 'employees.id', '=', 'crm_leave_entries.employee_id')
            ->join('crm_user_sites as user_sites', 'user_sites.user_id', '=', 'employees.crm_user_id')
            ->where('user_sites.site_id', $siteId)
            ->where('crm_leave_entries.status', 'pending')
            ->distinct()
            ->count('crm_leave_entries.id');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function equipmentAvailability(int $siteId, CarbonImmutable $now): array
    {
        $total = CrmEquipmentItem::query()
            ->active()
            ->where('site_id', $siteId)
            ->count();

        $busy = CrmEquipmentRental::query()
            ->where('site_id', $siteId)
            ->whereNotIn('status', [CrmEquipmentRental::STATUS_CANCELLED, CrmEquipmentRental::STATUS_RETURNED])
            ->where('start_at', '<=', $now)
            ->where('end_at', '>', $now)
            ->distinct('equipment_item_id')
            ->count('equipment_item_id');

        return [max(0, $total - $busy), $total];
    }

    /**
     * @return array<int, array{date: string, label: string, total: int}>
     */
    private function reservationTrend(int $siteId, CarbonImmutable $today): array
    {
        $start = $today->subDays(6)->startOfDay();
        $end = $today->endOfDay();
        $labels = collect(range(0, 6))
            ->map(fn (int $offset): CarbonImmutable => $start->addDays($offset));

        $rows = CrmReservation::query()
            ->selectRaw('DATE(start_at) as day, COUNT(*) as total')
            ->where('site_id', $siteId)
            ->whereBetween('start_at', [$start, $end])
            ->groupBy('day')
            ->pluck('total', 'day');

        return $labels
            ->map(fn (CarbonImmutable $date): array => [
                'date' => $date->toDateString(),
                'label' => $date->translatedFormat('d/m'),
                'total' => (int) ($rows[$date->toDateString()] ?? 0),
            ])
            ->all();
    }
}
