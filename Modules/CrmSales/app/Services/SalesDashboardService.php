<?php

namespace Modules\CrmSales\Services;

use App\Models\CrmSalesCommission;
use App\Models\CrmSalesInvoice;
use App\Models\CrmSalesObjective;
use App\Models\CrmSalesTour;
use App\Models\CrmSalesVisit;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Services\CrmActivityLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SalesDashboardService
{
    private const MODULE = 'pilotage-commercial';

    private const VIEW_PERMISSION = 'sales.view';

    private const SYNC_PERMISSION = 'sales.sync';

    private const MANAGE_PERMISSION = 'sales.manage';

    private const COMMISSION_PERMISSION = 'sales.commissions';

    public function __construct(
        private readonly CrmActivityLogger $activity,
        private readonly CrmAccessService $access,
    ) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label,sort_order', 'sites:id'])
            ->forAccount($user)
            ->where('active', true)
            ->first();

        if (! $actor) {
            $this->fail('Utilisateur HUB introuvable', 404);
        }

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function bootstrap(CrmUser $actor, ?int $siteId = null, array $filters = []): array
    {
        $this->requireModule($actor);

        $selectedSiteId = $this->resolveSiteId($actor, $siteId);
        $this->requireSitePermission($actor, $selectedSiteId, self::VIEW_PERMISSION);

        $month = $this->month($filters['month'] ?? null);
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $representativeId = $this->optionalPositiveInt($filters['representativeId'] ?? null);
        $status = $this->invoiceStatusFilter($filters['status'] ?? null);

        $invoices = $this->invoiceQuery($selectedSiteId, $start, $end, $representativeId, $status)
            ->with(['representative:id,name,role,photo_url'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        return [
            'ok' => true,
            'mode' => config('services.sales_api.url') ? 'api-ready' : 'demo',
            'user' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
                'permissions' => $this->access->permissionNames($actor),
                'siteIds' => $this->siteIds($actor),
                'selectedSiteId' => $selectedSiteId,
                'canSync' => $this->canSync($actor, $selectedSiteId),
                'canManage' => $this->canManage($actor, $selectedSiteId),
                'canManageCommissions' => $this->canManageCommissions($actor, $selectedSiteId),
            ],
            'sites' => $this->availableSites($actor)
                ->map(fn (CrmSite $site): array => $this->siteRow($site))
                ->values()
                ->all(),
            'representatives' => $this->representatives($selectedSiteId),
            'selectedSiteId' => $selectedSiteId,
            'filters' => [
                'month' => $month->format('Y-m'),
                'representativeId' => $representativeId,
                'status' => $status,
            ],
            'summary' => $this->summary($selectedSiteId, $start, $end, $representativeId),
            'objectives' => $this->objectiveRows($selectedSiteId, $start, $end, $representativeId),
            'commissions' => $this->commissionRows($selectedSiteId, $start, $end, $representativeId),
            'funnel' => $this->funnelRows($selectedSiteId, $start, $end, $representativeId),
            'leaderboard' => $this->leaderboard($selectedSiteId, $start, $end),
            'invoices' => $invoices->map(fn (CrmSalesInvoice $invoice): array => $this->invoiceRow($invoice))->values()->all(),
            'statusOptions' => $this->invoiceStatusRows(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncDemoInvoices(CrmUser $actor, ?int $siteId = null, ?string $monthValue = null): array
    {
        $this->requireModule($actor);

        $selectedSiteId = $this->resolveSiteId($actor, $siteId);
        $this->requireSitePermission($actor, $selectedSiteId, self::SYNC_PERMISSION);

        $month = $this->month($monthValue);
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        return DB::transaction(function () use ($actor, $selectedSiteId, $month, $start, $end): array {
            $representatives = CrmUser::query()
                ->where('active', true)
                ->whereHas('sites', fn (Builder $query) => $query->whereKey($selectedSiteId))
                ->orderBy('id')
                ->get(['id', 'name']);

            if ($representatives->isEmpty()) {
                $this->fail('Aucun commercial disponible sur ce site.', 422);
            }

            $customers = [
                ['Mairie de Lescar', 'MAI-LESCAR'],
                ['Hotel Bellevue', 'HOT-BEL'],
                ['SCI Palissy', 'SCI-PAL'],
                ['Atelier Dupont', 'AT-DUP'],
                ['Residence Horizon', 'RES-HOR'],
                ['SAS Batipro', 'BATI-PRO'],
            ];

            $createdOrUpdated = 0;

            foreach ($customers as $index => [$customer, $reference]) {
                $representative = $representatives[$index % $representatives->count()];
                $issueDate = $month->day(min($month->daysInMonth, 4 + ($index * 3)));
                $total = 840 + ($index * 335) + (($selectedSiteId % 5) * 45);
                $status = [self::invoiceStatusPaid(), CrmSalesInvoice::STATUS_PENDING, CrmSalesInvoice::STATUS_OVERDUE][$index % 3];

                $invoice = CrmSalesInvoice::query()->updateOrCreate(
                    ['external_id' => 'demo:'.$selectedSiteId.':'.$month->format('Ym').':'.($index + 1)],
                    [
                        'site_id' => $selectedSiteId,
                        'representative_user_id' => $representative->id,
                        'number' => 'DEMO-'.$month->format('Ym').'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                        'customer_name' => $customer,
                        'customer_reference' => $reference,
                        'issue_date' => $issueDate->toDateString(),
                        'due_date' => $issueDate->addDays(30)->toDateString(),
                        'status' => $status,
                        'subtotal' => round($total / 1.2, 2),
                        'total' => $total,
                        'margin' => round($total * 0.34, 2),
                        'commission_base' => round($total * 0.34, 2),
                        'raw_data' => [
                            'source' => 'demo',
                            'customer' => $customer,
                            'representative' => $representative->name,
                        ],
                        'synced_at' => now(),
                    ],
                );

                if ($invoice->status === CrmSalesInvoice::STATUS_PAID) {
                    CrmSalesCommission::query()->updateOrCreate(
                        ['invoice_id' => $invoice->id],
                        [
                            'site_id' => $selectedSiteId,
                            'representative_user_id' => $representative->id,
                            'period_start' => $start->toDateString(),
                            'period_end' => $end->toDateString(),
                            'amount' => round((float) $invoice->commission_base * 0.05, 2),
                            'status' => CrmSalesCommission::STATUS_ACQUIRED,
                            'notes' => 'Commission demo calculee a 5% de la marge.',
                            'created_by' => $actor->id,
                            'updated_by' => $actor->id,
                        ],
                    );
                }

                $createdOrUpdated++;
            }

            CrmSalesObjective::query()->updateOrCreate(
                [
                    'site_id' => $selectedSiteId,
                    'representative_user_id' => null,
                    'period_start' => $start->toDateString(),
                ],
                [
                    'period_end' => $end->toDateString(),
                    'target_revenue' => 18000,
                    'target_margin' => 6100,
                    'target_visits' => 24,
                    'notes' => 'Objectif demo mensuel pour tester le pilotage commercial.',
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            $this->activity->log($actor, 'synchronisation demo commercial', "{$createdOrUpdated} factures - ".$month->format('m/Y'));

            return [
                'ok' => true,
                'synced' => $createdOrUpdated,
                'message' => "{$createdOrUpdated} factures demo synchronisees.",
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveObjective(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $siteId = $this->resolveSiteId($actor, $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null));
            $this->requireSitePermission($actor, $siteId, self::MANAGE_PERMISSION);

            $month = $this->month($data['month'] ?? null);
            $representativeId = $this->optionalPositiveInt($data['representativeId'] ?? $data['representative_user_id'] ?? null);

            if ($representativeId && ! $this->representativeExistsOnSite($representativeId, $siteId)) {
                $this->fail('Commercial indisponible sur ce site.', 422);
            }

            $objective = CrmSalesObjective::query()->updateOrCreate(
                [
                    'site_id' => $siteId,
                    'representative_user_id' => $representativeId,
                    'period_start' => $month->startOfMonth()->toDateString(),
                ],
                [
                    'period_end' => $month->endOfMonth()->toDateString(),
                    'target_revenue' => $this->positiveDecimal($data['targetRevenue'] ?? $data['target_revenue'] ?? 0, 0),
                    'target_margin' => $this->positiveDecimal($data['targetMargin'] ?? $data['target_margin'] ?? 0, 0),
                    'target_visits' => max(0, min(999, (int) ($data['targetVisits'] ?? $data['target_visits'] ?? 0))),
                    'notes' => $this->optionalText($data['notes'] ?? null, 2000),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            $this->activity->log($actor, 'objectif commercial', 'Objectif #'.$objective->id.' - '.$month->format('m/Y'));

            return [
                'ok' => true,
                'objective' => $this->objectiveRow($objective, $siteId, $month->startOfMonth(), $month->endOfMonth()),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function markCommissionPaid(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = $this->positiveInt($data['id'] ?? null, 'Commission');
            $commission = CrmSalesCommission::query()->lockForUpdate()->find($id);

            if (! $commission) {
                $this->fail('Commission introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $commission->site_id, self::COMMISSION_PERMISSION);

            $commission->fill([
                'status' => CrmSalesCommission::STATUS_PAID,
                'paid_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->activity->log($actor, 'commission commerciale payee', 'Commission #'.$commission->id);

            return [
                'ok' => true,
                'commission' => $this->commissionRow($commission->fresh(['representative:id,name', 'invoice:id,number,customer_name'])),
            ];
        });
    }

    private static function invoiceStatusPaid(): string
    {
        return CrmSalesInvoice::STATUS_PAID;
    }

    private function requireModule(CrmUser $actor): void
    {
        if (! $this->access->hasModule($actor, self::MODULE)) {
            $this->fail('Module pilotage commercial non autorise', 403);
        }
    }

    private function requireSitePermission(CrmUser $actor, int $siteId, string $permission): void
    {
        if (! $this->canOnSite($actor, $siteId, $permission)) {
            $this->fail('Droit insuffisant : '.$permission, 403);
        }
    }

    private function canSync(CrmUser $actor, int $siteId): bool
    {
        return $this->canOnSite($actor, $siteId, self::SYNC_PERMISSION) || $this->canManage($actor, $siteId);
    }

    private function canManage(CrmUser $actor, int $siteId): bool
    {
        return $this->canOnSite($actor, $siteId, self::MANAGE_PERMISSION);
    }

    private function canManageCommissions(CrmUser $actor, int $siteId): bool
    {
        return $this->canOnSite($actor, $siteId, self::COMMISSION_PERMISSION);
    }

    private function canOnSite(CrmUser $actor, int $siteId, string $permission): bool
    {
        return $this->access->canOnSite($actor, $siteId, self::MODULE, $permission);
    }

    private function resolveSiteId(CrmUser $actor, ?int $requestedSiteId = null): int
    {
        $siteIds = $this->siteIds($actor);
        if ($siteIds === []) {
            $this->fail('Aucun site autorise pour le pilotage commercial', 403);
        }

        if ($requestedSiteId && in_array($requestedSiteId, $siteIds, true)) {
            return $requestedSiteId;
        }

        return $siteIds[0];
    }

    /**
     * @return array<int, int>
     */
    private function siteIds(CrmUser $actor): array
    {
        return $this->access->siteIdsForModule($actor, self::MODULE, [
            self::VIEW_PERMISSION,
            self::SYNC_PERMISSION,
            self::MANAGE_PERMISSION,
            self::COMMISSION_PERMISSION,
        ]);
    }

    private function availableSites(CrmUser $actor)
    {
        return CrmSite::query()
            ->active()
            ->whereIn('id', $this->siteIds($actor))
            ->orderedForHub()
            ->get(['id', 'name', 'slug']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function representatives(int $siteId): array
    {
        return CrmUser::query()
            ->where('active', true)
            ->whereHas('sites', fn (Builder $query) => $query->whereKey($siteId))
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'photo_url'])
            ->map(fn (CrmUser $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'photoUrl' => $user->photo_url,
            ])
            ->values()
            ->all();
    }

    private function representativeExistsOnSite(int $representativeId, int $siteId): bool
    {
        return CrmUser::query()
            ->whereKey($representativeId)
            ->where('active', true)
            ->whereHas('sites', fn (Builder $query) => $query->whereKey($siteId))
            ->exists();
    }

    /**
     * @return Builder<CrmSalesInvoice>
     */
    private function invoiceQuery(int $siteId, CarbonImmutable $start, CarbonImmutable $end, ?int $representativeId = null, ?string $status = null): Builder
    {
        return CrmSalesInvoice::query()
            ->forSite($siteId)
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId))
            ->when($status, fn (Builder $query) => $query->where('status', $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(int $siteId, CarbonImmutable $start, CarbonImmutable $end, ?int $representativeId = null): array
    {
        $invoiceQuery = $this->invoiceQuery($siteId, $start, $end, $representativeId);
        $visitQuery = CrmSalesVisit::query()
            ->forSite($siteId)
            ->whereBetween('planned_at', [$start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId));
        $commissionQuery = CrmSalesCommission::query()
            ->forSite($siteId)
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId));

        $revenue = (float) (clone $invoiceQuery)->where('status', CrmSalesInvoice::STATUS_PAID)->sum('total');
        $margin = (float) (clone $invoiceQuery)->where('status', CrmSalesInvoice::STATUS_PAID)->sum('margin');
        $target = (float) CrmSalesObjective::query()
            ->forSite($siteId)
            ->whereNull('representative_user_id')
            ->where('period_start', $start->toDateString())
            ->value('target_revenue');

        return [
            'invoiceCount' => (clone $invoiceQuery)->count(),
            'paidCount' => (clone $invoiceQuery)->where('status', CrmSalesInvoice::STATUS_PAID)->count(),
            'pendingCount' => (clone $invoiceQuery)->where('status', CrmSalesInvoice::STATUS_PENDING)->count(),
            'overdueCount' => (clone $invoiceQuery)->where('status', CrmSalesInvoice::STATUS_OVERDUE)->count(),
            'revenue' => round($revenue, 2),
            'pendingRevenue' => round((float) (clone $invoiceQuery)->where('status', CrmSalesInvoice::STATUS_PENDING)->sum('total'), 2),
            'margin' => round($margin, 2),
            'commissionAmount' => round((float) (clone $commissionQuery)->sum('amount'), 2),
            'commissionPaidAmount' => round((float) (clone $commissionQuery)->where('status', CrmSalesCommission::STATUS_PAID)->sum('amount'), 2),
            'visits' => (clone $visitQuery)->count(),
            'doneVisits' => (clone $visitQuery)->where('status', CrmSalesVisit::STATUS_DONE)->count(),
            'targetRevenue' => round($target, 2),
            'targetProgress' => $target > 0 ? min(999, round(($revenue / $target) * 100, 1)) : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function objectiveRows(int $siteId, CarbonImmutable $start, CarbonImmutable $end, ?int $representativeId = null): array
    {
        return CrmSalesObjective::query()
            ->with('representative:id,name')
            ->forSite($siteId)
            ->where('period_start', $start->toDateString())
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId))
            ->orderByRaw('representative_user_id is not null')
            ->orderBy('representative_user_id')
            ->get()
            ->map(fn (CrmSalesObjective $objective): array => $this->objectiveRow($objective, $siteId, $start, $end))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function objectiveRow(CrmSalesObjective $objective, int $siteId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $representativeId = $objective->representative_user_id ? (int) $objective->representative_user_id : null;
        $invoiceQuery = $this->invoiceQuery($siteId, $start, $end, $representativeId)
            ->where('status', CrmSalesInvoice::STATUS_PAID);
        $revenue = (float) (clone $invoiceQuery)->sum('total');
        $margin = (float) (clone $invoiceQuery)->sum('margin');
        $visits = CrmSalesVisit::query()
            ->forSite($siteId)
            ->whereBetween('planned_at', [$start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId))
            ->count();

        return [
            'id' => $objective->id,
            'representativeUserId' => $representativeId,
            'representativeName' => $objective->representative?->name ?: 'Site',
            'periodStart' => $objective->period_start?->toDateString(),
            'periodEnd' => $objective->period_end?->toDateString(),
            'targetRevenue' => (float) $objective->target_revenue,
            'targetMargin' => (float) $objective->target_margin,
            'targetVisits' => (int) $objective->target_visits,
            'actualRevenue' => round($revenue, 2),
            'actualMargin' => round($margin, 2),
            'actualVisits' => $visits,
            'revenueProgress' => $objective->target_revenue > 0 ? min(999, round(($revenue / (float) $objective->target_revenue) * 100, 1)) : null,
            'marginProgress' => $objective->target_margin > 0 ? min(999, round(($margin / (float) $objective->target_margin) * 100, 1)) : null,
            'visitProgress' => $objective->target_visits > 0 ? min(999, round(($visits / (int) $objective->target_visits) * 100, 1)) : null,
            'notes' => $objective->notes,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function commissionRows(int $siteId, CarbonImmutable $start, CarbonImmutable $end, ?int $representativeId = null): array
    {
        return CrmSalesCommission::query()
            ->with(['representative:id,name', 'invoice:id,number,customer_name'])
            ->forSite($siteId)
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId))
            ->orderByRaw("case when status = 'paid' then 1 else 0 end")
            ->orderByDesc('amount')
            ->limit(40)
            ->get()
            ->map(fn (CrmSalesCommission $commission): array => $this->commissionRow($commission))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function funnelRows(int $siteId, CarbonImmutable $start, CarbonImmutable $end, ?int $representativeId = null): array
    {
        $labels = [
            CrmSalesTour::STATUS_PLANNED => 'Tournées prévues',
            CrmSalesVisit::STATUS_DONE => 'Visites réalisées',
            CrmSalesInvoice::STATUS_PENDING => 'Factures en attente',
            CrmSalesInvoice::STATUS_PAID => 'Factures payées',
        ];

        $plannedTours = CrmSalesTour::query()
            ->forSite($siteId)
            ->whereBetween('tour_date', [$start->toDateString(), $end->toDateString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId))
            ->whereIn('status', [CrmSalesTour::STATUS_DRAFT, CrmSalesTour::STATUS_PLANNED, CrmSalesTour::STATUS_IN_PROGRESS])
            ->count();
        $doneVisits = CrmSalesVisit::query()
            ->forSite($siteId)
            ->whereBetween('planned_at', [$start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId))
            ->where('status', CrmSalesVisit::STATUS_DONE)
            ->count();
        $pendingInvoices = $this->invoiceQuery($siteId, $start, $end, $representativeId, CrmSalesInvoice::STATUS_PENDING)->count();
        $paidInvoices = $this->invoiceQuery($siteId, $start, $end, $representativeId, CrmSalesInvoice::STATUS_PAID)->count();

        return [
            ['key' => CrmSalesTour::STATUS_PLANNED, 'label' => $labels[CrmSalesTour::STATUS_PLANNED], 'count' => $plannedTours],
            ['key' => CrmSalesVisit::STATUS_DONE, 'label' => $labels[CrmSalesVisit::STATUS_DONE], 'count' => $doneVisits],
            ['key' => CrmSalesInvoice::STATUS_PENDING, 'label' => $labels[CrmSalesInvoice::STATUS_PENDING], 'count' => $pendingInvoices],
            ['key' => CrmSalesInvoice::STATUS_PAID, 'label' => $labels[CrmSalesInvoice::STATUS_PAID], 'count' => $paidInvoices],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function leaderboard(int $siteId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return CrmUser::query()
            ->where('active', true)
            ->whereHas('sites', fn (Builder $query) => $query->whereKey($siteId))
            ->orderBy('name')
            ->get(['id', 'name', 'photo_url'])
            ->map(function (CrmUser $representative) use ($siteId, $start, $end): array {
                $revenue = (float) $this->invoiceQuery($siteId, $start, $end, (int) $representative->id, CrmSalesInvoice::STATUS_PAID)->sum('total');
                $visits = CrmSalesVisit::query()
                    ->forSite($siteId)
                    ->where('representative_user_id', $representative->id)
                    ->whereBetween('planned_at', [$start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString()])
                    ->where('status', CrmSalesVisit::STATUS_DONE)
                    ->count();

                return [
                    'representativeUserId' => $representative->id,
                    'name' => $representative->name,
                    'photoUrl' => $representative->photo_url,
                    'revenue' => round($revenue, 2),
                    'visits' => $visits,
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->take(8)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(CrmSalesInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'representativeUserId' => $invoice->representative_user_id ? (int) $invoice->representative_user_id : null,
            'representativeName' => $invoice->representative?->name,
            'number' => $invoice->number,
            'customerName' => $invoice->customer_name,
            'customerReference' => $invoice->customer_reference,
            'issueDate' => $invoice->issue_date?->toDateString(),
            'dueDate' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status,
            'statusLabel' => $this->invoiceStatusLabel($invoice->status),
            'subtotal' => (float) $invoice->subtotal,
            'total' => (float) $invoice->total,
            'margin' => (float) $invoice->margin,
            'commissionBase' => (float) $invoice->commission_base,
            'syncedAt' => $invoice->synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commissionRow(CrmSalesCommission $commission): array
    {
        return [
            'id' => $commission->id,
            'representativeUserId' => $commission->representative_user_id ? (int) $commission->representative_user_id : null,
            'representativeName' => $commission->representative?->name,
            'invoiceId' => $commission->invoice_id ? (int) $commission->invoice_id : null,
            'invoiceNumber' => $commission->invoice?->number,
            'customerName' => $commission->invoice?->customer_name,
            'periodStart' => $commission->period_start?->toDateString(),
            'periodEnd' => $commission->period_end?->toDateString(),
            'amount' => (float) $commission->amount,
            'status' => $commission->status,
            'statusLabel' => $this->commissionStatusLabel($commission->status),
            'paidAt' => $commission->paid_at?->toIso8601String(),
            'notes' => $commission->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteRow(CrmSite $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
        ];
    }

    private function month(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return CarbonImmutable::now()->startOfMonth();
        }

        try {
            return CarbonImmutable::parse($value.'-01')->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $int = (int) $value;
        if ($int <= 0) {
            $this->fail($label.' requise', 422);
        }

        return $int;
    }

    private function positiveDecimal(mixed $value, float $min): float
    {
        $number = (float) str_replace(',', '.', (string) $value);

        return round(max($min, min(9999999, $number)), 2);
    }

    private function optionalText(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $limit) {
            $this->fail('Texte trop long', 422);
        }

        return $text;
    }

    private function invoiceStatusFilter(mixed $value): ?string
    {
        $value = (string) $value;

        return in_array($value, array_keys($this->invoiceStatusLabels()), true) ? $value : null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function invoiceStatusRows(): array
    {
        return collect($this->invoiceStatusLabels())
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function invoiceStatusLabels(): array
    {
        return [
            CrmSalesInvoice::STATUS_DRAFT => 'Brouillon',
            CrmSalesInvoice::STATUS_PENDING => 'En attente',
            CrmSalesInvoice::STATUS_PAID => 'Payee',
            CrmSalesInvoice::STATUS_OVERDUE => 'En retard',
            CrmSalesInvoice::STATUS_CANCELED => 'Annulee',
        ];
    }

    private function invoiceStatusLabel(string $status): string
    {
        return $this->invoiceStatusLabels()[$status] ?? $status;
    }

    private function commissionStatusLabel(string $status): string
    {
        return [
            CrmSalesCommission::STATUS_PENDING => 'En attente',
            CrmSalesCommission::STATUS_ACQUIRED => 'Acquise',
            CrmSalesCommission::STATUS_PAID => 'Payee',
        ][$status] ?? $status;
    }

    private function fail(string $message, int $status = 422): never
    {
        throw new HttpException($status, $message);
    }
}
