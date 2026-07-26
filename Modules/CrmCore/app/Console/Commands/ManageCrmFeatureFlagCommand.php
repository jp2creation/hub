<?php

namespace Modules\CrmCore\Console\Commands;

use App\Models\CrmFeatureFlag;
use Illuminate\Console\Command;
use Modules\CrmCore\Services\CrmFeatureFlagService;

class ManageCrmFeatureFlagCommand extends Command
{
    protected $signature = 'hub:feature
        {key? : Cle du flag, ex: module:reservations}
        {--enable : Active le flag}
        {--disable : Desactive le flag}
        {--list : Liste les flags}';

    protected $aliases = ['crm:feature'];

    protected $description = 'List, enable or disable HUB feature flags without redeploying.';

    public function handle(CrmFeatureFlagService $features): int
    {
        if ($this->option('list') || ! $this->argument('key')) {
            $this->listFlags();

            return self::SUCCESS;
        }

        if ($this->option('enable') === $this->option('disable')) {
            $this->error('Utilisez --enable ou --disable.');

            return self::FAILURE;
        }

        $key = trim((string) $this->argument('key'));
        if ($key === '') {
            $this->error('Cle de flag invalide.');

            return self::FAILURE;
        }

        $enabled = (bool) $this->option('enable');
        $flag = $features->set($key, $enabled);
        $features->forget();

        $state = $flag->enabled ? 'active' : 'desactive';
        $this->info("Flag {$flag->flag_key} {$state}.");

        return self::SUCCESS;
    }

    private function listFlags(): void
    {
        $rows = CrmFeatureFlag::query()
            ->orderBy('scope')
            ->orderBy('flag_key')
            ->get(['flag_key', 'scope', 'name', 'enabled'])
            ->map(fn (CrmFeatureFlag $flag): array => [
                $flag->flag_key,
                $flag->scope,
                $flag->name ?? '',
                $flag->enabled ? 'active' : 'desactive',
            ])
            ->all();

        if ($rows === []) {
            $this->info('Aucun feature flag configure.');

            return;
        }

        $this->table(['Cle', 'Scope', 'Nom', 'Etat'], $rows);
    }
}
