<?php

namespace App\Policies;

use App\Models\CrmReservation;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCrmSiteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;

class CrmReservationPolicy
{
    use AuthorizesCrmSiteAccess;

    private const MODULE = 'reservations';

    public function viewAny(User $user): bool
    {
        return $this->canAnySite($user, self::MODULE, ['reservations.view']);
    }

    public function view(User $user, CrmReservation $reservation): bool
    {
        return $this->canOnSite($user, (int) $reservation->getAttribute('site_id'), self::MODULE, ['reservations.view']);
    }

    public function create(User $user): bool
    {
        return $this->canAnySite($user, self::MODULE, ['reservations.create']);
    }

    public function createForSite(User $user, int $siteId): Response
    {
        return $this->allowIf(
            $this->canOnSite($user, $siteId, self::MODULE, ['reservations.create']),
            'Droit insuffisant : reservations.create',
        );
    }

    public function update(User $user, CrmReservation $reservation): Response
    {
        $siteId = (int) $reservation->getAttribute('site_id');

        return $this->allowIf(
            $this->canActOnDatedReservation($user, $reservation)
            && (
                $this->canOnSite($user, $siteId, self::MODULE, ['reservations.update_any'])
                || (
                    $this->ownsCrmRecord($user, $reservation->getAttribute('user_id'))
                    && $this->canOnSite($user, $siteId, self::MODULE, ['reservations.update_own'])
                )
            ),
            'Modification non autorisee',
        );
    }

    public function updateForSite(User $user, CrmReservation $reservation, int $siteId): Response
    {
        return $this->allowIf(
            $this->canActOnDatedReservation($user, $reservation)
            && (
                $this->canOnSite($user, $siteId, self::MODULE, ['reservations.update_any'])
                || (
                    $this->ownsCrmRecord($user, $reservation->getAttribute('user_id'))
                    && $this->canOnSite($user, $siteId, self::MODULE, ['reservations.update_own'])
                )
            ),
            'Site non autorise',
        );
    }

    public function delete(User $user, CrmReservation $reservation): Response
    {
        $siteId = (int) $reservation->getAttribute('site_id');

        return $this->allowIf(
            $this->canActOnDatedReservation($user, $reservation)
            && (
                $this->canOnSite($user, $siteId, self::MODULE, ['reservations.delete_any'])
                || (
                    $this->ownsCrmRecord($user, $reservation->getAttribute('user_id'))
                    && $this->canOnSite($user, $siteId, self::MODULE, ['reservations.delete_own'])
                )
            ),
            'Suppression non autorisee',
        );
    }

    private function canActOnDatedReservation(User $user, CrmReservation $reservation): bool
    {
        if (! $this->reservationIsPast($reservation)) {
            return true;
        }

        return $this->crmUser($user)?->role === 'admin' || $this->canUseFilamentAdmin($user);
    }

    private function reservationIsPast(CrmReservation $reservation): bool
    {
        $startAt = $reservation->getAttribute('start_at');

        if (! $startAt) {
            return false;
        }

        return CarbonImmutable::parse($startAt)->startOfDay()->lt(CarbonImmutable::now()->startOfDay());
    }

    private function allowIf(bool $allowed, string $message): Response
    {
        return $allowed ? Response::allow() : Response::deny($message);
    }
}
