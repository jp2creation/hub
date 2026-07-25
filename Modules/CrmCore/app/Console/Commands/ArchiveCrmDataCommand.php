<?php

namespace Modules\CrmCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Modules\CrmCore\Services\CrmArchiveService;
use RuntimeException;

class ArchiveCrmDataCommand extends Command
{
    protected $signature = 'crm:archive
        {--years= : Nombre d annees a conserver dans les tables actives, ecrase la config}
        {--limit= : Taille de lot par table}
        {--dry-run : Compter sans archiver}
        {--model= : Archiver uniquement un modele configure, FQCN ou cle courte}';

    protected $description = 'Archive les anciennes donnees des modules metier du HUB.';

    public function handle(CrmArchiveService $archives): int
    {
        $yearsOption = $this->option('years');
        $limitOption = $this->option('limit');
        $modelFilter = $this->modelFilter();
        $years = $yearsOption === null || $yearsOption === '' ? null : max(1, (int) $yearsOption);
        $limit = $limitOption === null || $limitOption === '' ? null : max(1, (int) $limitOption);
        $effectiveLimit = $archives->batchSize($limit);

        try {
            $counts = $archives->countArchiveCandidates($years, $modelFilter);
        } catch (InvalidArgumentException|RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? 'Archivage HUB en dry-run.' : 'Archivage HUB en production.');
        $this->displayCounts($counts);

        if ($this->option('dry-run')) {
            $this->warn('Mode dry-run termine. Aucune modification.');

            return self::SUCCESS;
        }

        $totals = [
            'models' => [],
            'total' => 0,
        ];

        try {
            do {
                $batch = $archives->archiveOlderThan($years, $limit, $modelFilter);
                $largestBatch = 0;

                foreach ($batch['models'] as $key => $result) {
                    $totals['models'][$key] ??= [
                        'label' => $result['label'],
                        'archive_table' => $result['archive_table'],
                        'archived' => 0,
                    ];
                    $totals['models'][$key]['archived'] += $result['archived'];
                    $largestBatch = max($largestBatch, $result['archived']);
                }

                $totals['total'] += $batch['total'];
            } while ($batch['total'] > 0 && $largestBatch === $effectiveLimit);
        } catch (JsonException $error) {
            $this->error('Archivage interrompu : impossible de serialiser une ligne en JSON.');
            Log::channel('crm')->error('Commande crm:archive echouee', [
                'error' => $error->getMessage(),
            ]);

            return self::FAILURE;
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());
            Log::channel('crm')->error('Commande crm:archive echouee', [
                'error' => $error->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->info("Archivage termine. Total : {$totals['total']}.");
        foreach ($totals['models'] as $result) {
            $this->line("{$result['label']} archive(s) : {$result['archived']}");
        }

        Log::channel('crm')->info('Commande crm:archive executee', [
            'model' => $modelFilter,
            'archived' => $totals,
        ]);

        return self::SUCCESS;
    }

    private function modelFilter(): ?string
    {
        $model = $this->option('model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * @param  array{models: array<string, array{label: string, archive_table: string, cutoff: mixed, count: int}>, total: int}  $counts
     */
    private function displayCounts(array $counts): void
    {
        foreach ($counts['models'] as $result) {
            $this->line(sprintf(
                '%s : %d candidat(s) avant %s -> %s',
                $result['label'],
                $result['count'],
                $result['cutoff']->toDateString(),
                $result['archive_table'],
            ));
        }

        $this->line("Total candidat(s) : {$counts['total']}");
    }
}
