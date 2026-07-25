<?php

namespace Modules\CrmCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PublishCrmModuleAssetsCommand extends Command
{
    protected $signature = 'crm:publish-module-assets {--force : Replace existing files}';

    protected $description = 'Publish HUB module assets into public/modules.';

    public function handle(): int
    {
        $modules = [
            'crm-core' => base_path('Modules/CrmCore/resources/assets'),
            'crm-leaves' => base_path('Modules/CrmLeaves/resources/assets'),
            'crm-cash-control' => base_path('Modules/CrmCashControl/resources/assets'),
            'crm-check-remittances' => base_path('Modules/CrmCheckRemittances/resources/assets'),
            'crm-deposit-requests' => base_path('Modules/CrmDepositRequests/resources/assets'),
            'crm-documents' => base_path('Modules/CrmDocuments/resources/assets'),
            'crm-pages' => base_path('Modules/CrmPages/resources/assets'),
            'crm-teams' => base_path('Modules/CrmTeams/resources/assets'),
            'crm-sales' => base_path('Modules/CrmSales/resources/assets'),
            'crm-sales-tours' => base_path('Modules/CrmSalesTours/resources/assets'),
        ];

        $published = 0;

        foreach ($modules as $module => $sourceDirectory) {
            if (! is_dir($sourceDirectory)) {
                continue;
            }

            $targetDirectory = public_path('modules/'.$module);

            File::ensureDirectoryExists($targetDirectory);

            foreach (File::files($sourceDirectory) as $file) {
                $target = $targetDirectory.DIRECTORY_SEPARATOR.$file->getFilename();

                if (! $this->shouldPublish($file->getPathname(), $target)) {
                    continue;
                }

                File::copy($file->getPathname(), $target);

                $published++;
            }
        }

        $this->info("HUB module assets published: {$published}");

        return self::SUCCESS;
    }

    private function shouldPublish(string $source, string $target): bool
    {
        if ($this->option('force') || ! is_file($target)) {
            return true;
        }

        return filemtime($source) > filemtime($target);
    }
}
