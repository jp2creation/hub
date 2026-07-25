<?php

namespace Modules\CrmReservations\Listeners;

use App\Models\CrmUser;
use Modules\CrmCore\Events\CrmDomainEvent;
use Modules\CrmCore\Services\CrmNotificationService;

class NotifyReservationDeleted
{
    public function __construct(
        private readonly CrmNotificationService $notifications,
    ) {}

    public function handle(CrmDomainEvent $event): void
    {
        if ($event->module !== 'reservations' || $event->entity !== 'reservation' || $event->name !== 'deleted') {
            return;
        }

        $ownerId = (int) ($event->payload['userId'] ?? 0);

        if ($ownerId <= 0 || $ownerId === (int) $event->actorId) {
            return;
        }

        $owner = CrmUser::query()
            ->with('account:id,name,email')
            ->find($ownerId);

        if (! $owner?->account) {
            return;
        }

        $this->notifications->notify($owner->account, 'reservation_deleted', [
            'reservation' => '#'.$event->entityId,
            'title' => (string) ($event->payload['title'] ?? 'Reservation'),
            'actor' => $event->actorName ?: 'HUB',
            'start' => (string) ($event->payload['startAt'] ?? ''),
            'end' => (string) ($event->payload['endAt'] ?? ''),
            'actionUrl' => '/reservations',
        ], ['database']);
    }
}
