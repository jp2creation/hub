<?php

namespace Modules\CrmStats\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\CrmStats\Services\BillingStatsDashboardService;

class ClearStatsCacheCommand extends Command
{
    protected $signature = 'crm:stats-cache-clear';

    protected $description = 'Purge le cache des tableaux de bord statistiques HUB.';

    public function handle(BillingStatsDashboardService $dashboardService): int
    {
        $dashboardService->clearCache();
        Cache::forget('crm-stats:last-api-error');

        $this->info('Cache statistiques HUB purge.');

        return self::SUCCESS;
    }
}
