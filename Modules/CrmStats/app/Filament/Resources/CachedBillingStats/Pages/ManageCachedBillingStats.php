<?php

namespace Modules\CrmStats\Filament\Resources\CachedBillingStats\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Artisan;
use Modules\CrmStats\Filament\Resources\CachedBillingStats\CachedBillingStatResource;

class ManageCachedBillingStats extends ManageRecords
{
    protected static string $resource = CachedBillingStatResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Synchroniser')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->canSyncStats())
                ->action(fn (): int => $this->callSyncCommand()),
        ];
    }

    private function canSyncStats(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->canUsePlatformAdministration() || $user->can('sync_stats'));
    }

    private function callSyncCommand(): int
    {
        $exitCode = Artisan::call('hub:sync-billing-data');

        $notification = Notification::make()
            ->title($exitCode === 0 ? 'Synchronisation terminee' : 'Synchronisation en erreur')
            ->body(trim(Artisan::output()));

        if ($exitCode === 0) {
            $notification->success();
        } else {
            $notification->danger();
        }

        $notification->send();

        return $exitCode;
    }
}
