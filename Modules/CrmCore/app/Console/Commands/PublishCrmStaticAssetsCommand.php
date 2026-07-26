<?php

namespace Modules\CrmCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PublishCrmStaticAssetsCommand extends Command
{
    protected $signature = 'hub:publish-static-assets
        {--force : Replace existing files}
        {--clean : Remove obsolete generated files from public/assets, except uploads}';

    protected $aliases = ['crm:publish-static-assets'];

    protected $description = 'Publish versioned HUB static assets into public/assets.';

    public function handle(): int
    {
        $sourceDirectory = resource_path('frontend/static/assets');
        $targetDirectory = public_path('assets');

        if (! is_dir($sourceDirectory)) {
            $this->warn("HUB static asset source not found: {$sourceDirectory}");

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($targetDirectory);

        $published = 0;
        $sourcePaths = [];

        foreach (File::allFiles($sourceDirectory) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $sourcePaths[$relativePath] = true;
            $target = $targetDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            File::ensureDirectoryExists(dirname($target));

            if (! $this->shouldPublish($file->getPathname(), $target)) {
                continue;
            }

            File::copy($file->getPathname(), $target);
            $published++;
        }

        $deleted = $this->option('clean') ? $this->deleteObsoleteAssets($targetDirectory, $sourcePaths) : 0;

        $this->info("HUB static assets published: {$published}; obsolete files removed: {$deleted}");

        return self::SUCCESS;
    }

    private function shouldPublish(string $source, string $target): bool
    {
        if ($this->option('force') || ! is_file($target)) {
            return true;
        }

        return filemtime($source) > filemtime($target);
    }

    /**
     * @param  array<string, true>  $sourcePaths
     */
    private function deleteObsoleteAssets(string $targetDirectory, array $sourcePaths): int
    {
        if (! is_dir($targetDirectory)) {
            return 0;
        }

        $deleted = 0;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($targetDirectory) + 1));

            if (str_starts_with($relativePath, 'uploads/')) {
                continue;
            }

            if (isset($sourcePaths[$relativePath])) {
                continue;
            }

            File::delete($file->getPathname());
            $deleted++;
        }

        return $deleted;
    }
}
