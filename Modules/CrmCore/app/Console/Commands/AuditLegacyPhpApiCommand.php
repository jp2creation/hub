<?php

namespace Modules\CrmCore\Console\Commands;

use App\Http\Middleware\BlockLegacyPhpApiPaths;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditLegacyPhpApiCommand extends Command
{
    protected $signature = 'hub:audit-legacy-php-api
        {--days=30 : Fenetre d audit en jours}
        {--limit=20 : Nombre maximum de lignes affichees par tableau}
        {--deactivation-date= : Date de desactivation definitive a communiquer aux integrateurs}
        {--fail-on-hits : Retourne un code erreur si des tentatives legacy existent}';

    protected $aliases = ['crm:audit-legacy-php-api'];

    protected $description = 'Audit blocked legacy /api/*.php calls recorded in crm_logs.';

    public function handle(): int
    {
        if (! Schema::hasTable('crm_logs')) {
            $this->warn('Table crm_logs absente : impossible de verifier les appels legacy.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $limit = max(1, min(100, (int) $this->option('limit')));
        $since = now()->subDays($days);
        $query = DB::table('crm_logs')
            ->where('action', BlockLegacyPhpApiPaths::LOG_ACTION)
            ->where('created_at', '>=', $since);
        $total = (int) (clone $query)->count();

        if ($total === 0) {
            $this->info("Aucune tentative legacy /api/*.php dans crm_logs sur les {$days} dernier(s) jour(s).");

            return self::SUCCESS;
        }

        $this->warn("{$total} tentative(s) legacy /api/*.php detectee(s) dans crm_logs sur les {$days} dernier(s) jour(s).");
        $this->line('Premiere tentative : '.((string) (clone $query)->min('created_at') ?: 'n/a'));
        $this->line('Derniere tentative : '.((string) (clone $query)->max('created_at') ?: 'n/a'));
        $this->newLine();
        $this->displayEndpointSummary($query, $limit);
        $this->displayClientSummary($query, $limit);
        $this->newLine();
        $this->warn($this->communicationMessage());

        return $this->option('fail-on-hits') ? self::FAILURE : self::SUCCESS;
    }

    private function displayEndpointSummary(Builder $query, int $limit): void
    {
        $rows = (clone $query)
            ->select([
                'details',
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT ip) as unique_ips'),
                DB::raw('MAX(created_at) as last_seen'),
            ])
            ->groupBy('details')
            ->orderByDesc('total')
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                (string) $row->details,
                (int) $row->total,
                (int) $row->unique_ips,
                (string) $row->last_seen,
            ])
            ->all();

        $this->table(['Endpoint legacy', 'Hits', 'IPs', 'Derniere tentative'], $rows);
    }

    private function displayClientSummary(Builder $query, int $limit): void
    {
        $hasUserAgent = Schema::hasColumn('crm_logs', 'user_agent');
        $select = [
            'ip',
            DB::raw('COUNT(*) as total'),
            DB::raw('MAX(details) as last_endpoint'),
            DB::raw('MAX(created_at) as last_seen'),
        ];

        if ($hasUserAgent) {
            $select[] = 'user_agent';
        }

        $rows = (clone $query)
            ->select($select)
            ->groupBy($hasUserAgent ? ['ip', 'user_agent'] : ['ip'])
            ->orderByDesc('total')
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                (string) ($row->ip ?: 'n/a'),
                $hasUserAgent ? mb_substr((string) ($row->user_agent ?: 'n/a'), 0, 90) : 'n/a',
                (int) $row->total,
                (string) $row->last_endpoint,
                (string) $row->last_seen,
            ])
            ->all();

        $this->table(['IP', 'User-Agent', 'Hits', 'Dernier endpoint', 'Derniere tentative'], $rows);
    }

    private function communicationMessage(): string
    {
        $date = trim((string) $this->option('deactivation-date'));

        if ($date !== '') {
            return "Action : contacter les integrateurs detectes et communiquer la date de desactivation definitive {$date}.";
        }

        return 'Action : contacter les integrateurs detectes et communiquer une date de desactivation definitive.';
    }
}
