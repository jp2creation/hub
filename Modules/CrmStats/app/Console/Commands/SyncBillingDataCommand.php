<?php

namespace Modules\CrmStats\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\CrmStats\Services\BillingStatsSyncService;

class SyncBillingDataCommand extends Command
{
    protected $signature = 'crm:sync-billing-data
        {--from-date= : Date de debut de synchronisation, format YYYY-MM-DD}
        {--site-id= : Site HUB cible si l API ne fournit pas de site_id}
        {--full : Synchronisation complete sans filtre de date}';

    protected $description = 'Synchronise les donnees de facturation externes dans la table locale d agregats HUB.';

    public function handle(BillingStatsSyncService $syncService): int
    {
        $fromDate = null;

        if (! $this->option('full')) {
            $fromDate = $this->option('from-date')
                ? CarbonImmutable::parse((string) $this->option('from-date'))
                : CarbonImmutable::today()->subDays(30);
        }

        $siteId = $this->option('site-id') ? (int) $this->option('site-id') : null;
        $result = $syncService->sync($fromDate, $siteId);

        $this->info("{$result['synced']} agregat(s) synchronise(s) depuis {$result['invoices']} facture(s).");

        if ($result['warning']) {
            $this->warn('API indisponible ou partielle : '.$result['warning']);
        }

        return self::SUCCESS;
    }
}
