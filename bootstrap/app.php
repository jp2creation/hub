<?php

use App\Http\Middleware\BlockLegacyPhpApiPaths;
use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\EnforceHttpsAndHsts;
use App\Http\Middleware\EnsureCrmMobileTokenScope;
use App\Http\Middleware\EnsureCrmModuleAccess;
use App\Http\Middleware\MirrorAuthenticatedSessionMetadata;
use App\Http\Middleware\TrustCrmHosts;
use App\Http\Middleware\TrustCrmProxies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustHosts as LaravelTrustHosts;
use Illuminate\Http\Middleware\TrustProxies as LaravelTrustProxies;
use Modules\CrmAdministration\Console\Commands\EnsureCrmAdminCommand;
use Modules\CrmCashControl\Console\Commands\ArchiveCashReceiptsCommand;
use Modules\CrmCore\Console\Commands\ArchiveCrmDataCommand;
use Modules\CrmCore\Console\Commands\AuditLegacyPhpApiCommand;
use Modules\CrmCore\Console\Commands\BackupDatabaseCommand;
use Modules\CrmCore\Console\Commands\ManageCrmFeatureFlagCommand;
use Modules\CrmCore\Console\Commands\MonitorQueueSizeCommand;
use Modules\CrmCore\Console\Commands\PublishCrmModuleAssetsCommand;
use Modules\CrmCore\Console\Commands\PublishCrmStaticAssetsCommand;
use Modules\CrmCore\Console\Commands\RefreshDashboardMetricsCommand;
use Modules\CrmStats\Console\Commands\ClearStatsCacheCommand;
use Modules\CrmStats\Console\Commands\SyncBillingDataCommand;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ArchiveCashReceiptsCommand::class,
        ArchiveCrmDataCommand::class,
        AuditLegacyPhpApiCommand::class,
        BackupDatabaseCommand::class,
        EnsureCrmAdminCommand::class,
        ManageCrmFeatureFlagCommand::class,
        MonitorQueueSizeCommand::class,
        PublishCrmModuleAssetsCommand::class,
        PublishCrmStaticAssetsCommand::class,
        RefreshDashboardMetricsCommand::class,
        ClearStatsCacheCommand::class,
        SyncBillingDataCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('hub:sync-billing-data')->hourly()->withoutOverlapping();
        $schedule->command('kpis:snapshot --interval=day')->dailyAt('02:10')->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts();
        $middleware->replace(LaravelTrustHosts::class, TrustCrmHosts::class);
        $middleware->replace(LaravelTrustProxies::class, TrustCrmProxies::class);
        $middleware->prepend(BlockLegacyPhpApiPaths::class);
        $middleware->append(EnforceHttpsAndHsts::class);
        $middleware->append(MirrorAuthenticatedSessionMetadata::class);

        $middleware->alias([
            'hub.compress' => CompressResponse::class,
            'hub.mobile_scope' => EnsureCrmMobileTokenScope::class,
            'hub.module' => EnsureCrmModuleAccess::class,
            'crm.compress' => CompressResponse::class,
            'crm.mobile_scope' => EnsureCrmMobileTokenScope::class,
            'crm.module' => EnsureCrmModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        if (class_exists(Integration::class)) {
            Integration::handles($exceptions);
        }
    })->create();
