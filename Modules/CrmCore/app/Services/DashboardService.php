<?php

namespace Modules\CrmCore\Services;

use App\Models\CrmCashReceipt;
use App\Models\CrmCashRegisterDay;
use App\Models\CrmCheckRemittance;
use App\Models\CrmEquipmentItem;
use App\Models\CrmEquipmentRental;
use App\Models\CrmLeaveEntry;
use App\Models\CrmNotificationLog;
use App\Models\CrmReservation;
use App\Models\CrmUser;
use App\Models\DashboardMetric;
use App\Models\User;
use App\Support\CrmTheme;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\CrmCore\Support\CrmReferenceCache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DashboardService
{
    public function __construct(
        private readonly CrmAccessService $access,
    ) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label', 'sites:id'])
            ->forAccount($user)
            ->where('active', true)
            ->first();

        if (! $actor) {
            $this->fail('Utilisateur HUB introuvable', 404);
        }

        return $actor;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(CrmUser $actor, ?int $requestedSiteId = null): array
    {
        $generalSiteIds = $this->generalSiteIds($actor);
        $selectedSiteId = $this->selectedSiteId($actor, $requestedSiteId, $generalSiteIds);
        $cacheKey = sprintf('crm:dashboard:v1:user:%d:site:%s', $actor->id, $selectedSiteId ?: 'all');
        $ttl = max(0, (int) config('crm.dashboard.cache_seconds', 300));

        $payload = fn (): array => $this->buildOverview($actor, $selectedSiteId, $generalSiteIds);

        return $ttl > 0 ? Cache::remember($cacheKey, $ttl, $payload) : $payload();
    }

    /**
     * @param  array<int, int>  $generalSiteIds
     * @return array<string, mixed>
     */
    private function buildOverview(CrmUser $actor, ?int $selectedSiteId, array $generalSiteIds): array
    {
        $today = CarbonImmutable::today(config('crm.display_timezone', config('app.timezone', 'Europe/Paris')));
        $now = CarbonImmutable::now(config('crm.display_timezone', config('app.timezone', 'Europe/Paris')));
        $weekStart = $today->startOfWeek();
        $weekEnd = $today->endOfWeek();
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();

        $reservationSiteIds = $this->moduleSiteIds($actor, 'reservations', ['reservations.view'], $selectedSiteId);
        $equipmentSiteIds = $this->moduleSiteIds($actor, 'locations-materiel', ['equipment_rentals.view'], $selectedSiteId);
        $leaveSiteIds = $this->moduleSiteIds($actor, 'conges', ['conges.view'], $selectedSiteId);
        $cashSiteIds = $this->moduleSiteIds($actor, 'controle-caisse', ['controle_caisse.view'], $selectedSiteId);
        $checkSiteIds = $this->moduleSiteIds($actor, 'remise-cheques', ['check_remittances.view'], $selectedSiteId);

        $moduleAccess = [
            'reservations' => $reservationSiteIds !== [],
            'equipmentRentals' => $equipmentSiteIds !== [],
            'leaves' => $leaveSiteIds !== [],
            'cashControl' => $cashSiteIds !== [],
            'checkRemittances' => $checkSiteIds !== [],
        ];

        $metrics = $this->preAggregatedMetrics($selectedSiteId, $today);
        $reservationChart = $reservationSiteIds === []
            ? $this->reservationChart([], $today)
            : ($metrics['reservationTrend'] ?? $this->reservationChart($reservationSiteIds, $today));
        [$equipmentAvailable, $equipmentTotal] = $equipmentSiteIds === []
            ? [0, 0]
            : ($metrics['equipmentAvailability'] ?? $this->equipmentAvailability($equipmentSiteIds, $now));

        return [
            'ok' => true,
            'generatedAt' => $now->toIso8601String(),
            'cacheSeconds' => (int) config('crm.dashboard.cache_seconds', 300),
            'user' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
                'siteIds' => $generalSiteIds,
            ],
            'selectedSiteId' => $selectedSiteId,
            'sites' => $this->siteRows($generalSiteIds),
            'modules' => $this->moduleRows($actor),
            'access' => $moduleAccess,
            'stats' => [
                'reservationsToday' => $reservationSiteIds === []
                    ? 0
                    : (int) ($metrics['reservationsToday'] ?? $this->reservationsToday($reservationSiteIds, $today)),
                'monthlyRevenue' => $cashSiteIds === []
                    ? 0.0
                    : (float) ($metrics['monthlyRevenue'] ?? $this->monthlyRevenue($cashSiteIds, $monthStart, $monthEnd)),
                'pendingLeaves' => $leaveSiteIds === []
                    ? 0
                    : (int) ($metrics['pendingLeaves'] ?? $this->pendingLeaves($leaveSiteIds)),
                'equipmentAvailable' => $equipmentAvailable,
                'equipmentTotal' => $equipmentTotal,
            ],
            'reservationTrend' => $reservationChart,
            'latestReservations' => $this->latestReservations($reservationSiteIds),
            'currentLeaves' => $this->currentLeaves($leaveSiteIds, $weekStart, $weekEnd),
            'alerts' => $this->alerts($equipmentSiteIds, $cashSiteIds, $checkSiteIds, $now, $monthStart, $monthEnd),
            'notifications' => $this->notifications($leaveSiteIds, $checkSiteIds),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function preAggregatedMetrics(?int $selectedSiteId, CarbonImmutable $today): ?array
    {
        if (! $selectedSiteId || ! config('crm.dashboard.metrics_enabled', true)) {
            return null;
        }

        $metric = DashboardMetric::currentForSite($selectedSiteId, $today);

        if (! $metric) {
            return null;
        }

        return [
            'reservationsToday' => (int) $metric->reservations_today,
            'monthlyRevenue' => (float) $metric->monthly_revenue,
            'pendingLeaves' => (int) $metric->pending_leaves,
            'equipmentAvailability' => [
                (int) $metric->equipment_available,
                (int) $metric->equipment_total,
            ],
            'reservationTrend' => $this->normalizedReservationTrend($metric->reservation_trend, $today),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function generalSiteIds(CrmUser $actor): array
    {
        return $this->access->siteIds($actor);
    }

    /**
     * @param  array<int, int>  $generalSiteIds
     */
    private function selectedSiteId(CrmUser $actor, ?int $requestedSiteId, array $generalSiteIds): ?int
    {
        if ($requestedSiteId && in_array($requestedSiteId, $generalSiteIds, true)) {
            return $requestedSiteId;
        }

        $defaultSiteId = (int) DB::table('crm_user_sites')
            ->where('user_id', $actor->id)
            ->where('is_default', true)
            ->value('site_id');

        if ($defaultSiteId > 0 && in_array($defaultSiteId, $generalSiteIds, true)) {
            return $defaultSiteId;
        }

        return $generalSiteIds[0] ?? null;
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<int, int>
     */
    private function moduleSiteIds(CrmUser $actor, string $moduleSlug, array $permissions, ?int $selectedSiteId): array
    {
        $siteIds = $this->access->siteIdsForModule($actor, $moduleSlug, $permissions);

        if ($selectedSiteId) {
            return in_array($selectedSiteId, $siteIds, true) ? [$selectedSiteId] : [];
        }

        return $siteIds;
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function reservationsToday(array $siteIds, CarbonImmutable $today): int
    {
        if ($siteIds === []) {
            return 0;
        }

        return CrmReservation::query()
            ->whereIn('site_id', $siteIds)
            ->where('start_at', '<=', $today->endOfDay())
            ->where('end_at', '>=', $today->startOfDay())
            ->count();
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function monthlyRevenue(array $siteIds, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): float
    {
        if ($siteIds === []) {
            return 0.0;
        }

        return (float) CrmCashReceipt::query()
            ->join('crm_cash_register_days as days', 'days.id', '=', 'crm_cash_receipts.cash_register_day_id')
            ->whereIn('days.site_id', $siteIds)
            ->whereBetween('crm_cash_receipts.occurred_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('crm_cash_receipts.invoice_total');
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function pendingLeaves(array $siteIds): int
    {
        if ($siteIds === []) {
            return 0;
        }

        return CrmLeaveEntry::query()
            ->join('crm_leave_employees as employees', 'employees.id', '=', 'crm_leave_entries.employee_id')
            ->join('crm_user_sites as user_sites', 'user_sites.user_id', '=', 'employees.crm_user_id')
            ->whereIn('user_sites.site_id', $siteIds)
            ->where('crm_leave_entries.status', 'pending')
            ->distinct()
            ->count('crm_leave_entries.id');
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array{0: int, 1: int}
     */
    private function equipmentAvailability(array $siteIds, CarbonImmutable $now): array
    {
        if ($siteIds === []) {
            return [0, 0];
        }

        $total = CrmEquipmentItem::query()
            ->active()
            ->whereIn('site_id', $siteIds)
            ->count();

        $busy = CrmEquipmentRental::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotIn('status', [CrmEquipmentRental::STATUS_CANCELLED, CrmEquipmentRental::STATUS_RETURNED])
            ->where('start_at', '<=', $now)
            ->where('end_at', '>', $now)
            ->distinct('equipment_item_id')
            ->count('equipment_item_id');

        return [max(0, $total - $busy), $total];
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array{date: string, label: string, total: int}>
     */
    private function reservationChart(array $siteIds, CarbonImmutable $today): array
    {
        $start = $today->subDays(6)->startOfDay();
        $end = $today->endOfDay();
        $labels = collect(range(0, 6))
            ->map(fn (int $offset): CarbonImmutable => $start->addDays($offset));

        if ($siteIds === []) {
            return $labels
                ->map(fn (CarbonImmutable $date): array => [
                    'date' => $date->toDateString(),
                    'label' => $date->translatedFormat('d/m'),
                    'total' => 0,
                ])
                ->all();
        }

        $rows = CrmReservation::query()
            ->selectRaw('DATE(start_at) as day, COUNT(*) as total')
            ->whereIn('site_id', $siteIds)
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

    /**
     * @param  array<int, array<string, mixed>>|null  $trend
     * @return array<int, array{date: string, label: string, total: int}>|null
     */
    private function normalizedReservationTrend(?array $trend, CarbonImmutable $today): ?array
    {
        if ($trend === null) {
            return null;
        }

        $start = $today->subDays(6)->startOfDay();
        $rowsByDate = collect($trend)
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['date']))
            ->keyBy(fn (array $row): string => (string) $row['date']);

        return collect(range(0, 6))
            ->map(function (int $offset) use ($start, $rowsByDate): array {
                $date = $start->addDays($offset);
                $row = $rowsByDate->get($date->toDateString(), []);

                return [
                    'date' => $date->toDateString(),
                    'label' => (string) ($row['label'] ?? $date->translatedFormat('d/m')),
                    'total' => (int) ($row['total'] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array<string, mixed>>
     */
    private function latestReservations(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return CrmReservation::query()
            ->with(['site:id,name', 'vehicle:id,name', 'user:id,name'])
            ->whereIn('site_id', $siteIds)
            ->latest('start_at')
            ->limit(5)
            ->get()
            ->map(fn (CrmReservation $reservation): array => [
                'id' => $reservation->id,
                'title' => $reservation->title ?: 'Réservation véhicule',
                'site' => $reservation->site?->name ?? '',
                'vehicle' => $reservation->vehicle?->name ?? '',
                'user' => $reservation->user?->name ?? $reservation->user_name,
                'startAt' => $reservation->start_at?->format('Y-m-d\TH:i'),
                'endAt' => $reservation->end_at?->format('Y-m-d\TH:i'),
                'status' => $reservation->end_at && $reservation->end_at->isPast() ? 'passée' : 'prévue',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array<string, mixed>>
     */
    private function currentLeaves(array $siteIds, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        if ($siteIds === []) {
            return [];
        }

        return CrmLeaveEntry::query()
            ->select('crm_leave_entries.*')
            ->with(['employee:id,name,color'])
            ->join('crm_leave_employees as employees', 'employees.id', '=', 'crm_leave_entries.employee_id')
            ->join('crm_user_sites as user_sites', 'user_sites.user_id', '=', 'employees.crm_user_id')
            ->whereIn('user_sites.site_id', $siteIds)
            ->where('crm_leave_entries.status', '<>', 'refused')
            ->whereDate('crm_leave_entries.start_date', '<=', $weekEnd->toDateString())
            ->whereDate('crm_leave_entries.end_date', '>=', $weekStart->toDateString())
            ->distinct()
            ->orderBy('start_date')
            ->limit(8)
            ->get()
            ->map(fn (CrmLeaveEntry $leave): array => [
                'id' => $leave->id,
                'name' => $leave->employee?->name ?? 'Utilisateur',
                'color' => $leave->employee?->color ?? CrmTheme::primaryHex(),
                'type' => $this->leaveTypeLabel((string) $leave->type),
                'period' => $this->leavePeriodLabel((string) $leave->period),
                'status' => $this->leaveStatusLabel((string) $leave->status),
                'startDate' => $leave->start_date?->toDateString(),
                'endDate' => $leave->end_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $equipmentSiteIds
     * @param  array<int, int>  $cashSiteIds
     * @param  array<int, int>  $checkSiteIds
     * @return array<int, array<string, mixed>>
     */
    private function alerts(array $equipmentSiteIds, array $cashSiteIds, array $checkSiteIds, CarbonImmutable $now, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $alerts = [];

        if ($equipmentSiteIds !== []) {
            $lateReturns = CrmEquipmentRental::query()
                ->whereIn('site_id', $equipmentSiteIds)
                ->whereIn('status', [CrmEquipmentRental::STATUS_RESERVED, CrmEquipmentRental::STATUS_PICKED_UP])
                ->where('end_at', '<', $now)
                ->count();

            if ($lateReturns > 0) {
                $alerts[] = [
                    'type' => 'danger',
                    'label' => 'Retours matériel',
                    'value' => $lateReturns,
                    'detail' => 'Location(s) à clôturer',
                    'href' => '/locations-materiel',
                ];
            }
        }

        if ($cashSiteIds !== []) {
            $cashReviews = CrmCashRegisterDay::query()
                ->whereIn('site_id', $cashSiteIds)
                ->whereBetween('cash_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where(fn (Builder $query): Builder => $query
                    ->whereIn('status', [CrmCashRegisterDay::STATUS_ANOMALY, CrmCashRegisterDay::STATUS_REVIEW])
                    ->orWhere('invoice_errors_count', '>', 0))
                ->count();

            if ($cashReviews > 0) {
                $alerts[] = [
                    'type' => 'warning',
                    'label' => 'Caisses à vérifier',
                    'value' => $cashReviews,
                    'detail' => 'Jour(s) du mois en anomalie',
                    'href' => '/controle-caisse',
                ];
            }

            $invoiceDiffs = CrmCashReceipt::query()
                ->join('crm_cash_register_days as days', 'days.id', '=', 'crm_cash_receipts.cash_register_day_id')
                ->whereIn('days.site_id', $cashSiteIds)
                ->whereBetween('crm_cash_receipts.occurred_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->whereRaw('ABS(crm_cash_receipts.invoice_total - (crm_cash_receipts.cash_amount + crm_cash_receipts.card_amount + crm_cash_receipts.check_amount + crm_cash_receipts.transfer_amount + crm_cash_receipts.control_amount)) > ?', [0.01])
                ->count();

            if ($invoiceDiffs > 0) {
                $alerts[] = [
                    'type' => 'danger',
                    'label' => 'Factures à vérifier',
                    'value' => $invoiceDiffs,
                    'detail' => 'Écart entre facture et paiement',
                    'href' => '/controle-caisse?view=list',
                ];
            }
        }

        if ($checkSiteIds !== []) {
            $draftRemittances = CrmCheckRemittance::query()
                ->whereIn('site_id', $checkSiteIds)
                ->where('status', CrmCheckRemittance::STATUS_DRAFT)
                ->count();

            if ($draftRemittances > 0) {
                $alerts[] = [
                    'type' => 'info',
                    'label' => 'Remises en brouillon',
                    'value' => $draftRemittances,
                    'detail' => 'Remise(s) de chèques à finaliser',
                    'href' => '/remise-cheques',
                ];
            }
        }

        $monitoringWindow = max(1, (int) config('crm.monitoring.dashboard_alert_minutes', 60));

        $queueAlert = CrmNotificationLog::query()
            ->where('channel', 'monitoring')
            ->where('template_key', 'queue.size.threshold')
            ->where('created_at', '>=', $now->subMinutes($monitoringWindow))
            ->latest('created_at')
            ->first();

        if ($queueAlert) {
            $payload = $this->notificationPayload($queueAlert);

            $alerts[] = [
                'type' => 'danger',
                'label' => 'Queue HUB',
                'value' => (int) ($payload['jobs'] ?? 0),
                'detail' => 'Jobs en attente sur '.(string) ($payload['queue'] ?? 'default'),
                'href' => '/administration',
            ];
        }

        $backupAlert = CrmNotificationLog::query()
            ->where('channel', 'monitoring')
            ->where('template_key', 'backup.database.failed')
            ->where('created_at', '>=', $now->subMinutes($monitoringWindow))
            ->latest('created_at')
            ->first();

        if ($backupAlert) {
            $alerts[] = [
                'type' => 'danger',
                'label' => 'Sauvegarde HUB',
                'value' => 1,
                'detail' => 'Derniere sauvegarde echouee',
                'href' => '/administration',
            ];
        }

        $loginAlert = CrmNotificationLog::query()
            ->where('channel', 'monitoring')
            ->where('template_key', 'auth.login.failed')
            ->where('created_at', '>=', $now->subMinutes($monitoringWindow))
            ->latest('created_at')
            ->first();

        if ($loginAlert) {
            $payload = $this->notificationPayload($loginAlert);

            $alerts[] = [
                'type' => 'warning',
                'label' => 'Connexions echouees',
                'value' => (int) ($payload['attempts'] ?? 0),
                'detail' => 'Tentatives suspectes detectees',
                'href' => '/administration',
            ];
        }

        return $alerts;
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationPayload(CrmNotificationLog $log): array
    {
        $payload = $log->getAttribute('payload');

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<int, int>  $leaveSiteIds
     * @param  array<int, int>  $checkSiteIds
     * @return array<string, mixed>
     */
    private function notifications(array $leaveSiteIds, array $checkSiteIds): array
    {
        $pendingLeaves = $this->pendingLeaves($leaveSiteIds);
        $draftChecks = $checkSiteIds === []
            ? 0
            : CrmCheckRemittance::query()->whereIn('site_id', $checkSiteIds)->where('status', CrmCheckRemittance::STATUS_DRAFT)->count();
        $failedNotifications = CrmNotificationLog::query()->failed()->count();

        return [
            'pendingLeaves' => $pendingLeaves,
            'draftCheckRemittances' => $draftChecks,
            'failedNotifications' => $failedNotifications,
            'total' => $pendingLeaves + $draftChecks + $failedNotifications,
        ];
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array{id: int, name: string}>
     */
    private function siteRows(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return collect(CrmReferenceCache::activeSiteRows())
            ->whereIn('id', $siteIds)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (array $site): array => ['id' => (int) $site['id'], 'name' => $site['name']])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function moduleRows(CrmUser $actor): array
    {
        $moduleIds = $this->access->moduleIds($actor);

        if ($moduleIds === []) {
            return [];
        }

        $moduleIds = array_flip($moduleIds);

        return collect(CrmReferenceCache::activeModuleLookup())
            ->filter(fn (array $module): bool => isset($moduleIds[(int) $module['id']]))
            ->sortBy([
                ['sortOrder', 'asc'],
                ['name', 'asc'],
            ])
            ->map(fn (array $module): array => [
                'id' => (int) $module['id'],
                'name' => $module['name'],
                'slug' => $module['slug'],
                'routePath' => $module['routePath'],
            ])
            ->values()
            ->all();
    }

    private function leaveTypeLabel(string $type): string
    {
        $key = 'crm-leaves.types.'.$type;
        $label = trans($key, [], 'fr');

        return $label !== $key ? $label : ucfirst($type);
    }

    private function leavePeriodLabel(string $period): string
    {
        $key = 'crm-leaves.periods.'.$period;
        $label = trans($key, [], 'fr');

        return $label !== $key ? $label : $period;
    }

    private function leaveStatusLabel(string $status): string
    {
        $key = 'crm-leaves.statuses.'.$status;
        $label = trans($key, [], 'fr');

        return $label !== $key ? $label : $status;
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
