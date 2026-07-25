<?php

namespace App\Filament\Widgets;

use App\Models\CrmReservation;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CrmStatsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Vue HUB';

    protected ?string $description = 'Etat rapide des sites, reservations et utilisateurs metier.';

    protected function getStats(): array
    {
        return [
            Stat::make('Sites actifs', CrmSite::query()->where('active', true)->count())
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->color('primary'),
            Stat::make('Vehicules actifs', CrmVehicle::query()->where('active', true)->count())
                ->icon(Heroicon::OutlinedTruck)
                ->color('warning'),
            Stat::make('Reservations a venir', CrmReservation::query()->where('end_at', '>=', now())->count())
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('success'),
            Stat::make('Utilisateurs HUB actifs', CrmUser::query()->where('active', true)->count())
                ->icon(Heroicon::OutlinedUsers)
                ->color('info'),
        ];
    }
}
