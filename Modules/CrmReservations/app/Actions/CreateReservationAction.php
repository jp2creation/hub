<?php

namespace Modules\CrmReservations\Actions;

use App\Models\CrmReservation;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\CrmCore\Events\CrmDomainEvent;
use Modules\CrmCore\Queries\ReservationConflictQuery;
use Modules\CrmReservations\Data\ReservationPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreateReservationAction
{
    public function __construct(
        private readonly ReservationConflictQuery $conflicts,
    ) {}

    public function execute(CrmUser $actor, ReservationPayload $payload): CrmReservation
    {
        return DB::transaction(function () use ($actor, $payload): CrmReservation {
            $this->requireNotPastStartDate($actor, $payload->startAt);

            $vehicle = CrmVehicle::query()
                ->active()
                ->lockForUpdate()
                ->find($payload->vehicleId);

            if (! $vehicle) {
                $this->fail('Vehicule introuvable', 404);
            }

            $site = CrmSite::query()->find((int) $vehicle->site_id);

            if (! $site) {
                $this->fail('Site introuvable', 404);
            }

            $this->requireWithinVehicleHours($vehicle, $site, $payload->startAt, $payload->endAt);
            $this->requireNoReservationConflict($payload->vehicleId, $payload->startAt, $payload->endAt);

            $reservation = CrmReservation::query()->create([
                'site_id' => $site->id,
                'vehicle_id' => $payload->vehicleId,
                'user_id' => $actor->id,
                'user_name' => (string) $actor->getAttribute('name'),
                'title' => $payload->title,
                'contact_phone' => $payload->contactPhone,
                'start_at' => $payload->startAt,
                'end_at' => $payload->endAt,
                'notes' => $payload->notes,
            ]);

            $this->dispatchReservationEvent($actor, 'created', $reservation->refresh());

            return $reservation;
        });
    }

    private function requireNotPastStartDate(CrmUser $actor, string $startAt): void
    {
        if ($actor->role === 'admin') {
            return;
        }

        if (CarbonImmutable::parse($startAt)->startOfDay()->lt(CarbonImmutable::now()->startOfDay())) {
            $this->fail('Impossible de reserver dans le passe', 422);
        }
    }

    private function requireWithinVehicleHours(CrmVehicle $vehicle, CrmSite $site, string $startAt, string $endAt): void
    {
        if (! $vehicle->containsReservationPeriod($startAt, $endAt, $site)) {
            $this->fail('Creneau hors horaires du vehicule', 400);
        }
    }

    private function requireNoReservationConflict(int $vehicleId, string $startAt, string $endAt, ?int $ignoreId = null): void
    {
        if ($this->conflicts->vehicleOverlaps($vehicleId, $startAt, $endAt, $ignoreId)) {
            $this->fail('Le vehicule est deja reserve sur ce creneau', 409);
        }
    }

    private function dispatchReservationEvent(CrmUser $actor, string $name, CrmReservation $reservation): void
    {
        CrmDomainEvent::dispatch(
            module: 'reservations',
            name: $name,
            entity: 'reservation',
            entityId: (int) $reservation->id,
            siteId: (int) $reservation->getAttribute('site_id'),
            actorId: (int) $actor->id,
            actorName: (string) $actor->getAttribute('name'),
            payload: [
                'title' => (string) ($reservation->getAttribute('title') ?? ''),
                'vehicleId' => (int) $reservation->getAttribute('vehicle_id'),
                'userId' => (int) $reservation->getAttribute('user_id'),
                'userName' => (string) ($reservation->getAttribute('user_name') ?? ''),
                'startAt' => $this->dateTimePayload($reservation->getAttribute('start_at')),
                'endAt' => $this->dateTimePayload($reservation->getAttribute('end_at')),
                'actionUrl' => '/reservations',
            ],
        );
    }

    private function dateTimePayload(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
