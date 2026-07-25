<?php

namespace Modules\CrmSalesTours\Services;

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

class SalesTourService
{
    private const MODULE = 'tournees-representants';

    private const VIEW_PERMISSION = 'sales_tours.view';

    private const CREATE_PERMISSION = 'sales_tours.create';

    private const REPORT_PERMISSION = 'sales_tours.report';

    private const MANAGE_PERMISSION = 'sales_tours.manage';

    public function __construct(
        private readonly CrmActivityLogger $activity,
        private readonly CrmAccessService $access,
    ) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label,sort_order', 'sites:id'])
            ->where('user_id', $user->id)
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
        $status = $this->statusFilter($filters['status'] ?? null);
        $queryText = trim((string) ($filters['query'] ?? ''));

        $tours = CrmSalesTour::query()
            ->with([
                'site:id,name',
                'representative:id,name,role,photo_url',
                'visits' => fn ($query) => $query->orderByRaw('planned_at is null')->orderBy('planned_at')->orderBy('id'),
            ])
            ->forSite($selectedSiteId)
            ->whereBetween('tour_date', [$start->toDateString(), $end->toDateString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId))
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($queryText !== '', function (Builder $query) use ($queryText): void {
                $query->where(function (Builder $query) use ($queryText): void {
                    $query
                        ->where('title', 'like', '%'.$queryText.'%')
                        ->orWhere('objective', 'like', '%'.$queryText.'%')
                        ->orWhere('report_summary', 'like', '%'.$queryText.'%')
                        ->orWhereHas('visits', function (Builder $query) use ($queryText): void {
                            $query
                                ->where('customer_name', 'like', '%'.$queryText.'%')
                                ->orWhere('city', 'like', '%'.$queryText.'%')
                                ->orWhere('contact_name', 'like', '%'.$queryText.'%');
                        });
                });
            })
            ->orderBy('tour_date')
            ->orderBy('id')
            ->get();

        $selectedTourId = $this->optionalPositiveInt($filters['tourId'] ?? null);
        $selectedTour = $selectedTourId
            ? $tours->first(fn (CrmSalesTour $tour): bool => (int) $tour->id === $selectedTourId)
            : ($tours->first(fn (CrmSalesTour $tour): bool => $tour->tour_date?->isToday()) ?: $tours->first());

        return [
            'ok' => true,
            'user' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
                'permissions' => $this->access->permissionNames($actor),
                'siteIds' => $this->siteIds($actor),
                'selectedSiteId' => $selectedSiteId,
                'canCreate' => $this->canCreate($actor, $selectedSiteId),
                'canReport' => $this->canReport($actor, $selectedSiteId),
                'canManage' => $this->canManage($actor, $selectedSiteId),
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
                'query' => $queryText,
            ],
            'summary' => $this->summary($selectedSiteId, $start, $end, $representativeId),
            'tours' => $tours
                ->map(fn (CrmSalesTour $tour): array => $this->tourRow($tour, includeVisits: false))
                ->values()
                ->all(),
            'selectedTour' => $selectedTour ? $this->tourRow($selectedTour, includeVisits: true) : null,
            'statusOptions' => $this->statusRows(),
            'visitStatusOptions' => $this->visitStatusRows(),
            'visitTypeOptions' => $this->visitTypeRows(),
            'priorityOptions' => $this->priorityRows(),
            'moodOptions' => $this->moodRows(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveTour(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $tour = $id > 0
                ? CrmSalesTour::query()->lockForUpdate()->find($id)
                : new CrmSalesTour;

            if ($id > 0 && ! $tour) {
                $this->fail('Rapport de visite introuvable', 404);
            }

            $requestedSiteId = $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null);
            $siteId = $tour->exists ? (int) $tour->site_id : $this->resolveSiteId($actor, $requestedSiteId);

            if (! $tour->exists) {
                if (! $this->canCreate($actor, $siteId)) {
                    $this->fail('Droit insuffisant : '.self::CREATE_PERMISSION, 403);
                }
            } else {
                $this->requireEditableTour($actor, $tour);

                if ($requestedSiteId && $requestedSiteId !== $siteId) {
                    if (! $this->canManage($actor, $siteId)) {
                        $this->fail('Droit insuffisant : '.self::MANAGE_PERMISSION, 403);
                    }

                    $siteId = $this->resolveSiteId($actor, $requestedSiteId);
                    $this->requireSitePermission($actor, $siteId, self::MANAGE_PERMISSION);
                }
            }

            $tourDate = $this->date((string) ($data['tourDate'] ?? $data['tour_date'] ?? now()->format('Y-m-d')), 'date du rapport');
            $representativeId = $this->optionalPositiveInt($data['representativeUserId'] ?? $data['representative_user_id'] ?? null) ?: (int) $actor->id;

            if ($representativeId !== (int) $actor->id && ! $this->canManage($actor, $siteId)) {
                $this->fail('Seul un gestionnaire peut affecter un rapport de visite à un autre représentant.', 403);
            }

            if (! $this->representativeExistsOnSite($representativeId, $siteId)) {
                $this->fail('Représentant indisponible sur ce site.', 422);
            }

            $title = $this->limitedText($data['title'] ?? '', 190, 'Titre', false);
            if ($title === '') {
                $repName = CrmUser::query()->whereKey($representativeId)->value('name') ?: $actor->name;
                $title = 'Rapport de visite '.$repName.' - '.$tourDate->format('d/m/Y');
            }

            $status = $this->tourStatus($data['status'] ?? $tour->status ?? CrmSalesTour::STATUS_PLANNED);

            $tour->fill([
                'site_id' => $siteId,
                'representative_user_id' => $representativeId,
                'title' => $title,
                'tour_date' => $tourDate->toDateString(),
                'status' => $status,
                'objective' => $this->optionalText($data['objective'] ?? null, 4000),
                'updated_by' => $actor->id,
            ]);

            if (! $tour->exists) {
                $tour->created_by = $actor->id;
            }

            $this->applyCompletedAt($tour, $status);
            $tour->save();

            $this->activity->log($actor, $id > 0 ? 'modification rapport de visite' : 'création rapport de visite', $tour->title);

            return [
                'ok' => true,
                'tour' => $this->tourRow($tour->fresh(['site:id,name', 'representative:id,name,role,photo_url', 'visits']), true),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deleteTour(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = $this->positiveInt($data['id'] ?? null, 'Rapport de visite');
            $tour = CrmSalesTour::query()->lockForUpdate()->find($id);

            if (! $tour) {
                $this->fail('Rapport de visite introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $tour->site_id, self::MANAGE_PERMISSION);
            $title = $tour->title;
            $tour->delete();

            $this->activity->log($actor, 'suppression rapport de visite', $title);

            return ['ok' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveVisit(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $tourId = $this->positiveInt($data['tourId'] ?? $data['tour_id'] ?? null, 'Rapport de visite');
            $tour = CrmSalesTour::query()->lockForUpdate()->find($tourId);

            if (! $tour) {
                $this->fail('Rapport de visite introuvable', 404);
            }

            $this->requireEditableTour($actor, $tour);

            $visit = $id > 0
                ? CrmSalesVisit::query()->lockForUpdate()->where('tour_id', $tour->id)->find($id)
                : new CrmSalesVisit;

            if ($id > 0 && ! $visit) {
                $this->fail('Visite introuvable', 404);
            }

            $plannedAt = $this->optionalDateTime($data['plannedAt'] ?? $data['planned_at'] ?? null, 'heure prévue');

            $visit->fill([
                'tour_id' => $tour->id,
                'site_id' => $tour->site_id,
                'representative_user_id' => $tour->representative_user_id,
                'customer_name' => $this->limitedText($data['customerName'] ?? $data['customer_name'] ?? '', 190, 'Client'),
                'customer_reference' => $this->limitedText($data['customerReference'] ?? $data['customer_reference'] ?? '', 120, 'Référence client', false) ?: null,
                'address' => $this->limitedText($data['address'] ?? '', 255, 'Adresse', false) ?: null,
                'postal_code' => $this->limitedText($data['postalCode'] ?? $data['postal_code'] ?? '', 20, 'Code postal', false) ?: null,
                'city' => $this->limitedText($data['city'] ?? '', 120, 'Ville', false) ?: null,
                'contact_name' => $this->limitedText($data['contactName'] ?? $data['contact_name'] ?? '', 160, 'Contact', false) ?: null,
                'contact_phone' => $this->limitedText($data['contactPhone'] ?? $data['contact_phone'] ?? '', 80, 'Téléphone', false) ?: null,
                'contact_email' => $this->limitedText($data['contactEmail'] ?? $data['contact_email'] ?? '', 190, 'Email', false) ?: null,
                'planned_at' => $plannedAt?->toDateTimeString(),
                'duration_minutes' => $this->duration($data['durationMinutes'] ?? $data['duration_minutes'] ?? 45),
                'visit_type' => $this->visitType($data['visitType'] ?? $data['visit_type'] ?? 'client'),
                'priority' => $this->priority($data['priority'] ?? 'normal'),
                'status' => $this->visitStatus($data['status'] ?? $visit->status ?? CrmSalesVisit::STATUS_PLANNED),
                'objective' => $this->optionalText($data['objective'] ?? null, 4000),
                'result' => $this->optionalText($data['result'] ?? null, 4000),
                'next_action' => $this->optionalText($data['nextAction'] ?? $data['next_action'] ?? null, 4000),
                'next_action_date' => $this->optionalDate($data['nextActionDate'] ?? $data['next_action_date'] ?? null, 'date prochaine action')?->toDateString(),
                'updated_by' => $actor->id,
            ]);

            if (! $visit->exists) {
                $visit->created_by = $actor->id;
            }

            $visit->save();

            $this->activity->log($actor, $id > 0 ? 'modification visite rapport' : 'ajout visite rapport', $tour->title.' - '.$visit->customer_name);

            return [
                'ok' => true,
                'visit' => $this->visitRow($visit->fresh(['representative:id,name,role,photo_url'])),
                'tour' => $this->tourRow($tour->fresh(['site:id,name', 'representative:id,name,role,photo_url', 'visits']), true),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deleteVisit(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = $this->positiveInt($data['id'] ?? null, 'Visite');
            $visit = CrmSalesVisit::query()->with('tour')->lockForUpdate()->find($id);

            if (! $visit || ! $visit->tour) {
                $this->fail('Visite introuvable', 404);
            }

            $this->requireEditableTour($actor, $visit->tour);
            $details = $visit->tour->title.' - '.$visit->customer_name;
            $visit->delete();

            $this->activity->log($actor, 'suppression visite rapport', $details);

            return ['ok' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveReport(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = $this->positiveInt($data['id'] ?? $data['tourId'] ?? $data['tour_id'] ?? null, 'Rapport de visite');
            $tour = CrmSalesTour::query()->lockForUpdate()->find($id);

            if (! $tour) {
                $this->fail('Rapport de visite introuvable', 404);
            }

            $this->requireEditableTour($actor, $tour);
            $status = $this->tourStatus($data['status'] ?? $tour->status ?? CrmSalesTour::STATUS_COMPLETED);

            $tour->fill([
                'status' => $status,
                'report_summary' => $this->optionalText($data['reportSummary'] ?? $data['report_summary'] ?? null, 8000),
                'report_next_actions' => $this->optionalText($data['reportNextActions'] ?? $data['report_next_actions'] ?? null, 8000),
                'report_mood' => $this->mood($data['reportMood'] ?? $data['report_mood'] ?? null),
                'kilometers' => $this->decimal($data['kilometers'] ?? 0, 0, 99999),
                'updated_by' => $actor->id,
            ]);

            $this->applyCompletedAt($tour, $status);
            $tour->save();

            $this->activity->log($actor, 'rapport de visite', $tour->title);

            return [
                'ok' => true,
                'tour' => $this->tourRow($tour->fresh(['site:id,name', 'representative:id,name,role,photo_url', 'visits']), true),
            ];
        });
    }

    private function applyCompletedAt(CrmSalesTour $tour, string $status): void
    {
        if ($status === CrmSalesTour::STATUS_COMPLETED && ! $tour->completed_at) {
            $tour->completed_at = now();
        }

        if ($status !== CrmSalesTour::STATUS_COMPLETED) {
            $tour->completed_at = null;
        }
    }

    private function requireEditableTour(CrmUser $actor, CrmSalesTour $tour): void
    {
        $siteId = (int) $tour->site_id;
        if ($this->canManage($actor, $siteId)) {
            return;
        }

        $isOwnTour = (int) $tour->representative_user_id === (int) $actor->id
            || (int) $tour->created_by === (int) $actor->id;

        if ($isOwnTour && ($this->canCreate($actor, $siteId) || $this->canReport($actor, $siteId))) {
            return;
        }

        $this->fail('Droit insuffisant : '.self::REPORT_PERMISSION, 403);
    }

    private function requireModule(CrmUser $actor): void
    {
        if (! $this->access->hasModule($actor, self::MODULE)) {
            $this->fail('Module rapport de visite non autorisé', 403);
        }
    }

    private function requireSitePermission(CrmUser $actor, int $siteId, string $permission): void
    {
        if (! $this->canOnSite($actor, $siteId, $permission)) {
            $this->fail('Droit insuffisant : '.$permission, 403);
        }
    }

    private function canCreate(CrmUser $actor, int $siteId): bool
    {
        return $this->canOnSite($actor, $siteId, self::CREATE_PERMISSION) || $this->canManage($actor, $siteId);
    }

    private function canReport(CrmUser $actor, int $siteId): bool
    {
        return $this->canOnSite($actor, $siteId, self::REPORT_PERMISSION) || $this->canManage($actor, $siteId);
    }

    private function canManage(CrmUser $actor, int $siteId): bool
    {
        return $this->canOnSite($actor, $siteId, self::MANAGE_PERMISSION);
    }

    private function canOnSite(CrmUser $actor, int $siteId, string $permission): bool
    {
        return $this->access->canOnSite($actor, $siteId, self::MODULE, $permission);
    }

    private function resolveSiteId(CrmUser $actor, ?int $requestedSiteId = null): int
    {
        $siteIds = $this->siteIds($actor);
        if ($siteIds === []) {
            $this->fail('Aucun site autorisé pour les rapports de visite', 403);
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
            self::CREATE_PERMISSION,
            self::REPORT_PERMISSION,
            self::MANAGE_PERMISSION,
        ]);
    }

    private function availableSites(CrmUser $actor)
    {
        $siteIds = $this->siteIds($actor);

        return CrmSite::query()
            ->active()
            ->whereIn('id', $siteIds)
            ->orderBy('name')
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

    /**
     * @return array<string, mixed>
     */
    private function summary(int $siteId, CarbonImmutable $start, CarbonImmutable $end, ?int $representativeId = null): array
    {
        $tourQuery = CrmSalesTour::query()
            ->forSite($siteId)
            ->whereBetween('tour_date', [$start->toDateString(), $end->toDateString()])
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId));

        $visitQuery = CrmSalesVisit::query()
            ->forSite($siteId)
            ->whereHas('tour', function (Builder $query) use ($start, $end): void {
                $query->whereBetween('tour_date', [$start->toDateString(), $end->toDateString()]);
            })
            ->when($representativeId, fn (Builder $query) => $query->where('representative_user_id', $representativeId));

        return [
            'tours' => (clone $tourQuery)->count(),
            'completedTours' => (clone $tourQuery)->where('status', CrmSalesTour::STATUS_COMPLETED)->count(),
            'plannedTours' => (clone $tourQuery)->whereIn('status', [CrmSalesTour::STATUS_DRAFT, CrmSalesTour::STATUS_PLANNED, CrmSalesTour::STATUS_IN_PROGRESS])->count(),
            'visits' => (clone $visitQuery)->count(),
            'doneVisits' => (clone $visitQuery)->where('status', CrmSalesVisit::STATUS_DONE)->count(),
            'nextActions' => (clone $visitQuery)->whereNotNull('next_action')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tourRow(CrmSalesTour $tour, bool $includeVisits = false): array
    {
        $visits = $tour->relationLoaded('visits') ? $tour->visits : collect();
        $done = $visits->where('status', CrmSalesVisit::STATUS_DONE)->count();
        $total = $visits->count();

        return [
            'id' => $tour->id,
            'siteId' => (int) $tour->site_id,
            'siteName' => $tour->site?->name,
            'representativeUserId' => $tour->representative_user_id ? (int) $tour->representative_user_id : null,
            'representativeName' => $tour->representative?->name,
            'representativePhotoUrl' => $tour->representative?->photo_url,
            'title' => $tour->title,
            'tourDate' => $tour->tour_date?->toDateString(),
            'status' => $tour->status,
            'statusLabel' => $this->statusLabel($tour->status),
            'objective' => $tour->objective,
            'reportSummary' => $tour->report_summary,
            'reportNextActions' => $tour->report_next_actions,
            'reportMood' => $tour->report_mood,
            'kilometers' => (float) $tour->kilometers,
            'completedAt' => $tour->completed_at?->toIso8601String(),
            'visitsTotal' => $total,
            'visitsDone' => $done,
            'visitsPending' => max(0, $total - $done),
            'visits' => $includeVisits
                ? $visits->map(fn (CrmSalesVisit $visit): array => $this->visitRow($visit))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visitRow(CrmSalesVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'tourId' => (int) $visit->tour_id,
            'siteId' => (int) $visit->site_id,
            'representativeUserId' => $visit->representative_user_id ? (int) $visit->representative_user_id : null,
            'customerName' => $visit->customer_name,
            'customerReference' => $visit->customer_reference,
            'address' => $visit->address,
            'postalCode' => $visit->postal_code,
            'city' => $visit->city,
            'contactName' => $visit->contact_name,
            'contactPhone' => $visit->contact_phone,
            'contactEmail' => $visit->contact_email,
            'plannedAt' => $visit->planned_at?->toIso8601String(),
            'durationMinutes' => (int) $visit->duration_minutes,
            'visitType' => $visit->visit_type,
            'visitTypeLabel' => $this->visitTypeLabel($visit->visit_type),
            'priority' => $visit->priority,
            'priorityLabel' => $this->priorityLabel($visit->priority),
            'status' => $visit->status,
            'statusLabel' => $this->visitStatusLabel($visit->status),
            'objective' => $visit->objective,
            'result' => $visit->result,
            'nextAction' => $visit->next_action,
            'nextActionDate' => $visit->next_action_date?->toDateString(),
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

    private function date(string $value, string $label): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            $this->fail($label.' invalide', 422);
        }
    }

    private function optionalDate(mixed $value, string $label): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->date((string) $value, $label);
    }

    private function optionalDateTime(mixed $value, string $label): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            $this->fail($label.' invalide', 422);
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $int = (int) $value;
        if ($int <= 0) {
            $this->fail($label.' requis', 422);
        }

        return $int;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function duration(mixed $value): int
    {
        return max(15, min(480, (int) $value ?: 45));
    }

    private function decimal(mixed $value, float $min, float $max): float
    {
        $number = (float) str_replace(',', '.', (string) $value);

        return round(max($min, min($max, $number)), 1);
    }

    private function limitedText(mixed $value, int $limit, string $label, bool $required = true): string
    {
        $text = trim((string) $value);
        if ($required && $text === '') {
            $this->fail($label.' requis', 422);
        }

        if (mb_strlen($text) > $limit) {
            $this->fail($label.' trop long', 422);
        }

        return $text;
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

    private function tourStatus(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, array_keys($this->statusLabels()), true)
            ? $value
            : CrmSalesTour::STATUS_PLANNED;
    }

    private function statusFilter(mixed $value): ?string
    {
        $value = (string) $value;

        return in_array($value, array_keys($this->statusLabels()), true) ? $value : null;
    }

    private function visitStatus(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, array_keys($this->visitStatusLabels()), true)
            ? $value
            : CrmSalesVisit::STATUS_PLANNED;
    }

    private function visitType(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, array_keys($this->visitTypeLabels()), true) ? $value : 'client';
    }

    private function priority(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, array_keys($this->priorityLabels()), true) ? $value : 'normal';
    }

    private function mood(mixed $value): ?string
    {
        $value = (string) $value;

        return in_array($value, array_keys($this->moodLabels()), true) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            CrmSalesTour::STATUS_DRAFT => 'Brouillon',
            CrmSalesTour::STATUS_PLANNED => 'Planifiée',
            CrmSalesTour::STATUS_IN_PROGRESS => 'En cours',
            CrmSalesTour::STATUS_COMPLETED => 'Terminée',
            CrmSalesTour::STATUS_CANCELED => 'Annulée',
        ];
    }

    private function statusLabel(?string $status): string
    {
        return $this->statusLabels()[$status] ?? 'Planifiée';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusRows(): array
    {
        return collect($this->statusLabels())
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function visitStatusLabels(): array
    {
        return [
            CrmSalesVisit::STATUS_PLANNED => 'Prévue',
            CrmSalesVisit::STATUS_DONE => 'Réalisée',
            CrmSalesVisit::STATUS_MISSED => 'Non vue',
            CrmSalesVisit::STATUS_CANCELED => 'Annulée',
        ];
    }

    private function visitStatusLabel(?string $status): string
    {
        return $this->visitStatusLabels()[$status] ?? 'Prévue';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function visitStatusRows(): array
    {
        return collect($this->visitStatusLabels())
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function visitTypeLabels(): array
    {
        return [
            'client' => 'Client',
            'prospect' => 'Prospect',
            'chantier' => 'Chantier',
            'relance' => 'Relance',
            'sav' => 'SAV',
        ];
    }

    private function visitTypeLabel(?string $type): string
    {
        return $this->visitTypeLabels()[$type] ?? 'Client';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function visitTypeRows(): array
    {
        return collect($this->visitTypeLabels())
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function priorityLabels(): array
    {
        return [
            'low' => 'Basse',
            'normal' => 'Normale',
            'high' => 'Prioritaire',
            'urgent' => 'Urgente',
        ];
    }

    private function priorityLabel(?string $priority): string
    {
        return $this->priorityLabels()[$priority] ?? 'Normale';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function priorityRows(): array
    {
        return collect($this->priorityLabels())
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function moodLabels(): array
    {
        return [
            'positive' => 'Très positif',
            'neutral' => 'À suivre',
            'warning' => 'Point sensible',
            'blocked' => 'Bloqué',
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function moodRows(): array
    {
        return collect($this->moodLabels())
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
