<?php

namespace Modules\CrmReservations\Services;

use App\Models\CrmReservation;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Models\User;
use App\Support\CrmTheme;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Services\CrmActivityLogger;
use Modules\CrmCore\Services\CrmImageStorage;
use Modules\CrmCore\Support\CrmReferenceCache;
use Modules\CrmReservations\Actions\CreateReservationAction;
use Modules\CrmReservations\Actions\DeleteReservationAction;
use Modules\CrmReservations\Actions\UpdateReservationAction;
use Modules\CrmReservations\Data\ReservationPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationService
{
    public function __construct(
        private readonly CrmActivityLogger $activity,
        private readonly CrmAccessService $access,
        private readonly CrmImageStorage $images,
        private readonly CreateReservationAction $createReservationAction,
        private readonly UpdateReservationAction $updateReservationAction,
        private readonly DeleteReservationAction $deleteReservationAction,
    ) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label', 'sites:id'])
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
     */
    public function bootstrap(CrmUser $actor, bool $includeReservations = true, array $filters = []): array
    {
        $this->requireModule($actor, 'reservations');

        $allowedSiteIds = $this->siteIds($actor);
        if ($allowedSiteIds === []) {
            $this->fail('Aucun site autorise pour les reservations', 403);
        }

        $payload = [
            'ok' => true,
            'mode' => 'mysql',
            'user' => $this->actorRow($actor),
            'sites' => collect($this->activeSiteRows())->whereIn('id', $allowedSiteIds)->values()->all(),
            'modules' => $this->activeModuleRows(),
            'vehicles' => collect($this->activeVehicleRows())->whereIn('siteId', $allowedSiteIds)->values()->all(),
            'permissions' => $this->permissionRows(),
            'users' => $this->userRowsForSites($allowedSiteIds),
        ];

        if ($includeReservations) {
            $calendar = $this->reservations($actor, $filters);
            $payload['reservations'] = $calendar['reservations'];
            $payload['window'] = $calendar['window'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function reservations(CrmUser $actor, array $filters = []): array
    {
        $this->requireModule($actor, 'reservations');

        [$from, $to] = $this->dateWindow($filters);
        $siteIds = $this->filteredSiteIds($actor, $filters);
        $vehicleId = (int) ($filters['vehicleId'] ?? $filters['vehicle_id'] ?? 0);

        $reservations = CrmReservation::query()
            ->with(['site:id,name', 'user:id,name', 'vehicle:id,name'])
            ->whereIn('site_id', $siteIds)
            ->when($vehicleId > 0, fn ($query) => $query->where('vehicle_id', $vehicleId))
            ->where('end_at', '>=', $from)
            ->where('start_at', '<=', $to)
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();

        return [
            'ok' => true,
            'mode' => 'mysql',
            'window' => [
                'from' => $from->format('Y-m-d\TH:i'),
                'to' => $to->format('Y-m-d\TH:i'),
            ],
            'reservations' => $reservations
                ->map(fn (CrmReservation $reservation): array => $this->reservationRow($reservation))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function users(CrmUser $actor, array $filters = []): array
    {
        $this->requireModule($actor, 'reservations');

        $siteIds = $this->filteredSiteIds($actor, $filters);
        $limit = $this->limit($filters, 250, 500);
        $cursor = max(0, (int) ($filters['cursor'] ?? 0));

        $users = CrmUser::query()
            ->select(['id', 'name'])
            ->where('active', true)
            ->where('id', '>', $cursor)
            ->whereHas('sites', fn ($query) => $query->whereIn('crm_sites.id', $siteIds))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $users->count() > $limit;
        $rows = $users->take($limit)->values();

        return [
            'ok' => true,
            'mode' => 'mysql',
            'users' => $rows->map(fn (CrmUser $user): array => $this->userRow($user))->all(),
            'pagination' => [
                'hasMore' => $hasMore,
                'nextCursor' => $hasMore ? $rows->last()?->id : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function vehicles(CrmUser $actor, array $filters = []): array
    {
        $this->requireModule($actor, 'reservations');

        $siteIds = $this->filteredSiteIds($actor, $filters);
        $limit = $this->limit($filters, 250, 500);
        $cursor = max(0, (int) ($filters['cursor'] ?? 0));

        $vehicles = CrmVehicle::query()
            ->active()
            ->whereIn('site_id', $siteIds)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $vehicles->count() > $limit;
        $rows = $vehicles->take($limit)->values();

        return [
            'ok' => true,
            'mode' => 'mysql',
            'vehicles' => $rows->map(fn (CrmVehicle $vehicle): array => $this->vehicleRow($vehicle))->all(),
            'pagination' => [
                'hasMore' => $hasMore,
                'nextCursor' => $hasMore ? $rows->last()?->id : null,
            ],
        ];
    }

    public function createReservation(User $account, CrmUser $actor, array $data): array
    {
        $this->requireModule($actor, 'reservations');

        $payload = ReservationPayload::fromArray($data);
        $site = $this->siteForVehicle($payload->vehicleId);

        Gate::forUser($account)->authorize('createForSite', [CrmReservation::class, (int) $site->id]);

        $reservation = $this->createReservationAction->execute($actor, $payload);

        return ['ok' => true, 'reservation' => $this->reservationRow($reservation)];
    }

    public function updateReservation(User $account, CrmUser $actor, array $data): array
    {
        $this->requireModule($actor, 'reservations');

        $payload = ReservationPayload::fromArray($data, true);
        $reservation = $this->reservationForPolicy((int) $payload->id);
        $site = $this->siteForVehicle($payload->vehicleId);

        Gate::forUser($account)->authorize('update', $reservation);
        Gate::forUser($account)->authorize('updateForSite', [$reservation, (int) $site->id]);

        $reservation = $this->updateReservationAction->execute($actor, $payload);

        return ['ok' => true, 'reservation' => $this->reservationRow($reservation)];
    }

    public function saveVehicle(User $account, CrmUser $actor, array $data): array
    {
        $this->requireModule($actor, 'reservations');

        return DB::transaction(function () use ($account, $actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $siteId = (int) ($data['siteId'] ?? $data['site_id'] ?? 0);
            $name = trim((string) ($data['name'] ?? ''));
            $description = trim((string) ($data['description'] ?? ''));
            $color = $this->color((string) ($data['color'] ?? CrmTheme::primaryHex()));
            $photoDataUrl = (string) ($data['photoDataUrl'] ?? $data['photo_data_url'] ?? '');
            $dayStartTime = $this->nullableTime5($data['dayStartTime'] ?? $data['day_start_time'] ?? null);
            $dayEndTime = $this->nullableTime5($data['dayEndTime'] ?? $data['day_end_time'] ?? null);

            if ($name === '' || mb_strlen($name) > 160) {
                $this->fail('Nom de vehicule invalide', 400);
            }

            if (mb_strlen($description) > 255) {
                $this->fail('Description trop longue', 400);
            }

            if ($dayStartTime && $dayEndTime && $this->minutes5($dayEndTime) <= $this->minutes5($dayStartTime)) {
                $this->fail('Horaires du vehicule invalides', 400);
            }

            $vehicle = $id > 0
                ? CrmVehicle::query()->lockForUpdate()->find($id)
                : new CrmVehicle;

            if ($id > 0 && ! $vehicle) {
                $this->fail('Vehicule introuvable', 404);
            }

            if ($id > 0) {
                Gate::forUser($account)->authorize('update', $vehicle);
                Gate::forUser($account)->authorize('updateForSite', [$vehicle, $siteId]);
            } else {
                Gate::forUser($account)->authorize('createForSite', [CrmVehicle::class, $siteId]);
            }

            $duplicateExists = CrmVehicle::query()
                ->where('name', $name)
                ->when($id > 0, fn ($query) => $query->whereKeyNot($id))
                ->lockForUpdate()
                ->exists();

            if ($duplicateExists) {
                $this->fail('Un vehicule porte deja ce nom', 409);
            }

            $photoUrl = $vehicle->getAttribute('photo_url') ?: null;

            if (trim($photoDataUrl) !== '') {
                $photoUrl = $this->images->storeDataUrl(
                    $photoDataUrl,
                    'vehicles',
                    $photoUrl,
                    ['maxBytes' => 2 * 1024 * 1024],
                )['url'];
            }

            $vehicle->fill([
                'site_id' => $siteId,
                'name' => $name,
                'description' => $description,
                'color' => $color,
                'photo_url' => $photoUrl,
                'day_start_time' => $dayStartTime,
                'day_end_time' => $dayEndTime,
                'active' => true,
            ]);
            $vehicle->save();

            $this->log($actor, $id > 0 ? 'modification vehicule' : 'creation vehicule', "Vehicule #{$vehicle->id}");

            return ['ok' => true, 'vehicle' => $this->vehicleRow($vehicle->refresh())];
        });
    }

    public function deleteVehicle(User $account, CrmUser $actor, array $data): array
    {
        $this->requireModule($actor, 'reservations');

        return DB::transaction(function () use ($account, $actor, $data): array {
            $id = (int) ($data['id'] ?? 0);

            if ($id <= 0) {
                $this->fail('Vehicule invalide', 400);
            }

            $vehicle = CrmVehicle::query()
                ->lockForUpdate()
                ->find($id);

            if (! $vehicle) {
                $this->fail('Vehicule introuvable', 404);
            }

            Gate::forUser($account)->authorize('delete', $vehicle);

            $vehicle->forceFill(['active' => false])->save();

            $this->log($actor, 'masquage vehicule', "Vehicule #{$id}");

            return ['ok' => true, 'id' => $id];
        });
    }

    public function deleteReservation(User $account, CrmUser $actor, array $data): array
    {
        $this->requireModule($actor, 'reservations');

        $id = (int) ($data['id'] ?? 0);

        if ($id <= 0) {
            $this->fail('Reservation invalide', 400);
        }

        $reservation = $this->reservationForPolicy($id);

        Gate::forUser($account)->authorize('delete', $reservation);

        $this->deleteReservationAction->execute($actor, $id);

        return ['ok' => true];
    }

    private function requireModule(CrmUser $actor, string $slug): void
    {
        if (! $this->hasModule($actor, $slug)) {
            $this->fail('Module non autorise : '.$slug, 403);
        }
    }

    private function hasModule(CrmUser $actor, string $slug): bool
    {
        return $this->access->hasModule($actor, $slug);
    }

    private function reservationForPolicy(int $id): CrmReservation
    {
        $reservation = CrmReservation::query()->find($id);

        if (! $reservation) {
            $this->fail('Reservation introuvable', 404);
        }

        return $reservation;
    }

    private function siteForVehicle(int $vehicleId): CrmSite
    {
        $vehicle = CrmVehicle::query()
            ->active()
            ->find($vehicleId);

        if (! $vehicle) {
            $this->fail('Vehicule introuvable', 404);
        }

        $site = CrmSite::query()->find((int) $vehicle->site_id);

        if (! $site) {
            $this->fail('Site introuvable', 404);
        }

        return $site;
    }

    private function actorRow(CrmUser $actor): array
    {
        return [
            'id' => $actor->id,
            'name' => $actor->name,
            'role' => $actor->role,
            'active' => (bool) $actor->active,
            'siteIds' => $this->siteIds($actor),
            'moduleIds' => $actor->role === 'blocked' ? [] : $this->access->moduleIds($actor),
            'permissions' => $actor->role === 'blocked' ? [] : $this->access->permissionNames($actor),
        ];
    }

    private function userRow(CrmUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function siteIds(CrmUser $user): array
    {
        return $this->access->siteIdsForModule($user, 'reservations', $this->reservationPermissionNames());
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array{id: int, name: string}>
     */
    private function userRowsForSites(array $siteIds): array
    {
        $siteLookup = array_fill_keys(array_map('intval', $siteIds), true);
        $rows = [];

        foreach (CrmReferenceCache::activeUserRows() as $user) {
            foreach ($user['siteIds'] as $siteId) {
                if (isset($siteLookup[$siteId])) {
                    $rows[] = [
                        'id' => $user['id'],
                        'name' => $user['name'],
                    ];

                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    private function filteredSiteIds(CrmUser $actor, array $filters): array
    {
        $allowedSiteIds = $this->siteIds($actor);

        if ($allowedSiteIds === []) {
            $this->fail('Aucun site autorise pour les reservations', 403);
        }

        $requestedSiteId = (int) ($filters['siteId'] ?? $filters['site_id'] ?? 0);

        if ($requestedSiteId <= 0) {
            return $allowedSiteIds;
        }

        if (! in_array($requestedSiteId, $allowedSiteIds, true)) {
            $this->fail('Site non autorise', 403);
        }

        return [$requestedSiteId];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dateWindow(array $filters): array
    {
        $from = $this->optionalDateBoundary($filters['from'] ?? null, true)
            ?? CarbonImmutable::now()->startOfMonth()->subDays(7);
        $to = $this->optionalDateBoundary($filters['to'] ?? null, false)
            ?? CarbonImmutable::now()->endOfMonth()->addDays(7);

        if ($from->gt($to)) {
            $this->fail('Fenetre de dates invalide', 422);
        }

        if ($from->diffInDays($to, true) > 120) {
            $this->fail('Fenetre de dates trop large', 422);
        }

        return [$from, $to];
    }

    private function optionalDateBoundary(mixed $value, bool $startOfDay): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            $this->fail('Date de planning invalide', 422);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $startOfDay ? $date->startOfDay() : $date->endOfDay();
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function limit(array $filters, int $default, int $max): int
    {
        $limit = (int) ($filters['limit'] ?? $default);

        return max(1, min($limit, $max));
    }

    /**
     * @return array<int, string>
     */
    private function reservationPermissionNames(): array
    {
        return [
            'reservations.view',
            'reservations.create',
            'reservations.update_own',
            'reservations.update_any',
            'reservations.delete_own',
            'reservations.delete_any',
            'reservations.manage_vehicles',
        ];
    }

    private function vehicleRow(CrmVehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'siteId' => (int) $vehicle->site_id,
            'name' => $vehicle->name,
            'description' => $vehicle->description ?? '',
            'color' => $vehicle->color ?: CrmTheme::primaryHex(),
            'photoUrl' => $vehicle->getAttribute('photo_url') ?? '',
            'dayStartTime' => $this->time5($vehicle->day_start_time, ''),
            'dayEndTime' => $this->time5($vehicle->day_end_time, ''),
            'active' => (bool) $vehicle->active,
        ];
    }

    private function reservationRow(CrmReservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'siteId' => (int) $reservation->site_id,
            'vehicleId' => (int) $reservation->vehicle_id,
            'userId' => (int) $reservation->user_id,
            'userName' => $reservation->user_name,
            'title' => $reservation->title ?? '',
            'contactPhone' => $reservation->contact_phone ?? '',
            'startAt' => $reservation->start_at?->format('Y-m-d\TH:i') ?? '',
            'endAt' => $reservation->end_at?->format('Y-m-d\TH:i') ?? '',
            'notes' => $reservation->notes ?? '',
        ];
    }

    private function activeSiteRows(): array
    {
        return CrmReferenceCache::activeSiteRows();
    }

    private function activeModuleRows(): array
    {
        return CrmReferenceCache::activeMenuModuleRows();
    }

    private function activeVehicleRows(): array
    {
        return CrmReferenceCache::activeVehicleRows();
    }

    private function permissionRows(): array
    {
        return CrmReferenceCache::permissionRows();
    }

    private function color(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : CrmTheme::primaryHex();
    }

    private function time5(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return preg_match('/^([0-2][0-9]:[0-5][0-9])/', $value, $match) && (int) substr($match[1], 0, 2) <= 23
            ? $match[1]
            : $default;
    }

    private function nullableTime5(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^([0-2][0-9]:[0-5][0-9])/', $value, $match) || (int) substr($match[1], 0, 2) > 23) {
            $this->fail('Horaire du vehicule invalide', 400);
        }

        return $match[1];
    }

    private function minutes5(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }

    private function log(CrmUser $actor, string $action, string $details = ''): void
    {
        $this->activity->log($actor, $action, $details);
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
