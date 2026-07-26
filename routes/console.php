<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Console\PruneCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('backup:run --verify --quiet')
    ->dailyAt('02:30')
    ->withoutOverlapping();

Schedule::command('cash-control:archive-receipts --quiet')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('hub:archive --quiet')
    ->sundays()
    ->at('03:15')
    ->withoutOverlapping();

Schedule::command('hub:monitor-queue-size --quiet')
    ->everyFiveMinutes()
    ->withoutOverlapping();

if (class_exists(PruneCommand::class)) {
    Schedule::command('telescope:prune --hours=48')
        ->dailyAt('02:45')
        ->withoutOverlapping();
}

Schedule::command('hub:refresh-dashboard-metrics --quiet')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
