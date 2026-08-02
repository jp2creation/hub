<?php

namespace Modules\CrmDepositRequests\Services;

use App\Models\CrmDepositRequest;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use App\Support\CrmTheme;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Services\CrmActivityLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DepositRequestService
{
    private const MODULE = 'demandes-acompte';

    private const VIEW_PERMISSION = 'deposit_requests.view';

    private const CREATE_PERMISSION = 'deposit_requests.create';

    private const MANAGE_PERMISSION = 'deposit_requests.manage';

    private const VALIDATE_PERMISSION = 'deposit_requests.validate';

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

        $limit = $this->limit($filters['limit'] ?? 20);
        $status = $this->statusFilter($filters['status'] ?? null);
        $queryText = trim((string) ($filters['query'] ?? ''));

        $query = CrmDepositRequest::query()
            ->with(['site:id,name', 'requester:id,name', 'validator:id,name'])
            ->forSite($selectedSiteId)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($queryText !== '', function ($query) use ($queryText): void {
                $query->where(function ($query) use ($queryText): void {
                    $query
                        ->where('requester_name', 'like', '%'.$queryText.'%')
                        ->orWhere('document_number', 'like', '%'.$queryText.'%')
                        ->orWhere('client_name', 'like', '%'.$queryText.'%');
                });
            })
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->limit($limit);

        $requests = $query->get();

        return [
            'ok' => true,
            'user' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
                'permissions' => $this->access->permissionNames($actor),
                'siteIds' => $this->siteIds($actor),
                'selectedSiteId' => $selectedSiteId,
                'canCreate' => $this->canOnSite($actor, $selectedSiteId, self::CREATE_PERMISSION),
                'canManage' => $this->canOnSite($actor, $selectedSiteId, self::MANAGE_PERMISSION),
                'canValidate' => $this->canOnSite($actor, $selectedSiteId, self::VALIDATE_PERMISSION),
            ],
            'sites' => $this->availableSites($actor)
                ->map(fn (CrmSite $site): array => $this->siteRow($site))
                ->values()
                ->all(),
            'selectedSiteId' => $selectedSiteId,
            'filters' => [
                'limit' => $limit,
                'status' => $status,
                'query' => $queryText,
            ],
            'summary' => $this->summary($selectedSiteId),
            'requests' => $requests
                ->map(fn (CrmDepositRequest $request): array => $this->requestRow($request, $actor))
                ->values()
                ->all(),
            'statusOptions' => $this->statusRows(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveRequest(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));

            $request = $id > 0
                ? CrmDepositRequest::query()->lockForUpdate()->find($id)
                : new CrmDepositRequest;

            if ($id > 0 && ! $request) {
                $this->fail("Demande d'acompte introuvable", 404);
            }

            $requestedSiteId = $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null);
            $siteId = $request->exists
                ? (int) $request->site_id
                : $this->resolveSiteId($actor, $requestedSiteId);

            if (! $request->exists) {
                $this->requireSitePermission($actor, $siteId, self::CREATE_PERMISSION);
            } else {
                $canManage = $this->canOnSite($actor, $siteId, self::MANAGE_PERMISSION);
                $canEditOwnPending = (int) $request->created_by === (int) $actor->id
                    && $request->status !== CrmDepositRequest::STATUS_VALIDATED
                    && $this->canOnSite($actor, $siteId, self::CREATE_PERMISSION);

                if (! $canManage && ! $canEditOwnPending) {
                    $this->fail('Droit insuffisant : '.self::MANAGE_PERMISSION, 403);
                }

                if ($requestedSiteId && $requestedSiteId !== $siteId) {
                    if (! $canManage) {
                        $this->fail('Droit insuffisant : '.self::MANAGE_PERMISSION, 403);
                    }

                    $siteId = $this->resolveSiteId($actor, $requestedSiteId);
                    $this->requireSitePermission($actor, $siteId, self::MANAGE_PERMISSION);
                }
            }

            $date = $this->date((string) ($data['requestDate'] ?? $data['request_date'] ?? now()->format('Y-m-d')), 'date');
            $requesterName = $this->limitedText($data['requesterName'] ?? $data['requester_name'] ?? $actor->name, 190, 'Demandeur');
            $documentNumber = $this->limitedText($data['documentNumber'] ?? $data['document_number'] ?? '', 120, 'Numero facture ou commande');
            $clientName = $this->limitedText($data['clientName'] ?? $data['client_name'] ?? $request->client_name ?? '', 190, 'Nom du client');
            $amount = $this->positiveDecimal($data['amount'] ?? 0, 'montant');

            if ($requesterName === '') {
                $this->fail('Demandeur requis', 422);
            }

            if ($documentNumber === '') {
                $this->fail('Numero facture ou commande requis', 422);
            }

            $request->fill([
                'site_id' => $siteId,
                'request_date' => $date,
                'requester_user_id' => $request->requester_user_id ?: $actor->id,
                'requester_name' => $requesterName,
                'document_number' => $documentNumber,
                'client_name' => $clientName !== '' ? $clientName : null,
                'amount' => $amount,
                'notes' => $this->limitedText($data['notes'] ?? $request->notes ?? '', 4000, 'Notes'),
                'updated_by' => $actor->id,
            ]);

            if (! $request->exists) {
                $request->status = CrmDepositRequest::STATUS_PENDING;
                $request->created_by = $actor->id;
            }

            $request->save();
            $this->activity->log($actor, $id > 0 ? 'modification demande acompte' : 'creation demande acompte', $request->document_number);

            return ['ok' => true, 'request' => $this->requestRow($request->refresh()->load(['site:id,name', 'requester:id,name', 'validator:id,name']), $actor)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validateRequest(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                $this->fail("Demande d'acompte requise", 400);
            }

            $request = CrmDepositRequest::query()->lockForUpdate()->find($id);
            if (! $request) {
                $this->fail("Demande d'acompte introuvable", 404);
            }

            $this->requireSitePermission($actor, (int) $request->site_id, self::VALIDATE_PERMISSION);

            $status = (string) ($data['status'] ?? CrmDepositRequest::STATUS_VALIDATED);
            if (! in_array($status, [CrmDepositRequest::STATUS_PENDING, CrmDepositRequest::STATUS_VALIDATED], true)) {
                $this->fail('Statut invalide', 422);
            }

            $validatedAt = null;
            $validatedBy = null;

            if ($status === CrmDepositRequest::STATUS_VALIDATED) {
                $validatedAt = $this->datetime((string) ($data['validatedAt'] ?? $data['validated_at'] ?? now()->format('Y-m-d H:i:s')), 'date de validation');
                $validatedBy = $actor->id;
            }

            $request->forceFill([
                'status' => $status,
                'validated_at' => $validatedAt,
                'validated_by' => $validatedBy,
                'updated_by' => $actor->id,
            ])->save();

            $this->activity->log($actor, $status === CrmDepositRequest::STATUS_VALIDATED ? 'validation demande acompte' : 'remise en attente demande acompte', $request->document_number);

            return ['ok' => true, 'request' => $this->requestRow($request->refresh()->load(['site:id,name', 'requester:id,name', 'validator:id,name']), $actor)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function deleteRequest(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                $this->fail("Demande d'acompte requise", 400);
            }

            $request = CrmDepositRequest::query()->lockForUpdate()->find($id);
            if (! $request) {
                $this->fail("Demande d'acompte introuvable", 404);
            }

            $this->requireSitePermission($actor, (int) $request->site_id, self::MANAGE_PERMISSION);
            $request->delete();
            $this->activity->log($actor, 'suppression demande acompte', $request->document_number);

            return ['ok' => true, 'deleted' => true, 'id' => $id];
        });
    }

    private function summary(int $siteId): array
    {
        $query = CrmDepositRequest::query()->forSite($siteId);
        $pendingQuery = (clone $query)->where('status', CrmDepositRequest::STATUS_PENDING);
        $validatedQuery = (clone $query)->where('status', CrmDepositRequest::STATUS_VALIDATED);

        return [
            'totalCount' => (clone $query)->count(),
            'pendingCount' => (clone $pendingQuery)->count(),
            'validatedCount' => (clone $validatedQuery)->count(),
            'pendingAmount' => $this->money((float) (clone $pendingQuery)->sum('amount')),
            'validatedAmount' => $this->money((float) (clone $validatedQuery)->sum('amount')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestRow(CrmDepositRequest $request, CrmUser $actor): array
    {
        $request->loadMissing(['site:id,name', 'requester:id,name', 'validator:id,name']);
        $canManage = $this->canOnSite($actor, (int) $request->site_id, self::MANAGE_PERMISSION);
        $canValidate = $this->canOnSite($actor, (int) $request->site_id, self::VALIDATE_PERMISSION);
        $canEditOwnPending = (int) $request->created_by === (int) $actor->id
            && $request->status !== CrmDepositRequest::STATUS_VALIDATED
            && $this->canOnSite($actor, (int) $request->site_id, self::CREATE_PERMISSION);

        return [
            'id' => $request->id,
            'siteId' => (int) $request->site_id,
            'siteName' => $request->site?->name ?? '',
            'requestDate' => $request->request_date?->format('Y-m-d'),
            'requesterUserId' => $request->requester_user_id ? (int) $request->requester_user_id : null,
            'requesterName' => $request->requester_name,
            'documentNumber' => $request->document_number,
            'clientName' => $request->client_name ?? '',
            'amount' => $this->money($request->amount),
            'status' => $request->status ?: CrmDepositRequest::STATUS_PENDING,
            'statusLabel' => $this->statusLabels()[$request->status] ?? 'En attente',
            'validatedAt' => $request->validated_at?->format('Y-m-d H:i:s'),
            'validatedDate' => $request->validated_at?->format('Y-m-d'),
            'validatedByName' => $request->validator?->name ?? '',
            'notes' => $request->notes ?? '',
            'canEdit' => $canManage || $canEditOwnPending,
            'canDelete' => $canManage,
            'canValidate' => $canValidate,
        ];
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
    private function statusLabels(): array
    {
        return [
            CrmDepositRequest::STATUS_PENDING => 'En attente',
            CrmDepositRequest::STATUS_VALIDATED => 'Validé',
        ];
    }

    private function resolveSiteId(CrmUser $actor, ?int $siteId): int
    {
        $siteIds = $this->siteIds($actor);
        if ($siteIds === []) {
            $this->fail('Aucun site autorise', 403);
        }

        $selectedSiteId = $siteId && $siteId > 0 ? $siteId : $siteIds[0];
        if (! in_array($selectedSiteId, $siteIds, true)) {
            $this->fail('Site non autorise', 403);
        }

        if (! CrmSite::query()->active()->whereKey($selectedSiteId)->exists()) {
            $this->fail('Site introuvable', 404);
        }

        return $selectedSiteId;
    }

    /**
     * @return array<int, int>
     */
    private function siteIds(CrmUser $actor): array
    {
        return $this->access->siteIdsForModule($actor, self::MODULE, [
            self::VIEW_PERMISSION,
            self::CREATE_PERMISSION,
            self::MANAGE_PERMISSION,
            self::VALIDATE_PERMISSION,
        ]);
    }

    private function availableSites(CrmUser $actor)
    {
        return CrmSite::query()
            ->active()
            ->whereIn('id', $this->siteIds($actor))
            ->orderedForHub()
            ->get();
    }

    private function requireModule(CrmUser $actor): void
    {
        if (! $this->access->hasModule($actor, self::MODULE)) {
            $this->fail("Module demande d'acompte non autorise", 403);
        }
    }

    private function requireSitePermission(CrmUser $actor, int $siteId, string $permission): void
    {
        if (! $this->canOnSite($actor, $siteId, $permission)) {
            $this->fail('Droit insuffisant : '.$permission, 403);
        }
    }

    private function canOnSite(CrmUser $actor, int $siteId, string $permission): bool
    {
        return $this->access->canOnSite($actor, $siteId, self::MODULE, $permission);
    }

    private function siteRow(CrmSite $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'active' => (bool) $site->active,
            'address' => trim((string) $site->address),
            'phone' => trim((string) $site->phone),
            'email' => trim((string) $site->email),
            'color' => $this->siteColor((string) $site->color),
        ];
    }

    private function siteColor(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : CrmTheme::primaryHex();
    }

    private function limit(mixed $value): int
    {
        $limit = (int) $value;

        return in_array($limit, [10, 20, 50, 100], true) ? $limit : 20;
    }

    private function statusFilter(mixed $value): ?string
    {
        $value = trim((string) $value);

        return in_array($value, [CrmDepositRequest::STATUS_PENDING, CrmDepositRequest::STATUS_VALIDATED], true) ? $value : null;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function date(string $value, string $label): string
    {
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            $this->fail($label.' invalide', 422);
        }
    }

    private function datetime(string $value, string $label): string
    {
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $this->fail($label.' invalide', 422);
        }
    }

    private function positiveDecimal(mixed $value, string $label): float
    {
        $number = $this->decimal($value);
        if ($number <= 0) {
            $this->fail(ucfirst($label).' requis', 422);
        }

        return $number;
    }

    private function decimal(mixed $value): float
    {
        $normalized = str_replace(["\xc2\xa0", ' '], '', trim((string) $value));
        $normalized = str_replace(',', '.', $normalized);
        $number = is_numeric($normalized) ? (float) $normalized : 0.0;

        return $this->money($number);
    }

    private function money(float $value): float
    {
        return round($value + 0.00001, 2);
    }

    private function limitedText(mixed $value, int $max, string $label): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) > $max) {
            $this->fail($label.' trop long', 400);
        }

        return $text;
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
