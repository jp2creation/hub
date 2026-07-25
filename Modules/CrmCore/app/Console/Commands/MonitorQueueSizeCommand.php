<?php

namespace Modules\CrmCore\Console\Commands;

use App\Models\CrmNotificationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MonitorQueueSizeCommand extends Command
{
    protected $signature = 'crm:monitor-queue-size
        {--threshold= : Nombre de jobs en attente avant alerte}';

    protected $description = 'Monitor HUB queue size and register an alert when it exceeds the configured threshold.';

    public function handle(): int
    {
        $threshold = max(1, (int) ($this->option('threshold') ?: config('crm.queue.alert_threshold', 1000)));
        $connection = (string) config('queue.default', 'sync');
        $queueConfig = config("queue.connections.{$connection}", []);
        $driver = (string) ($queueConfig['driver'] ?? $connection);

        if ($driver === 'redis') {
            return $this->monitorRedisQueue($queueConfig, $threshold);
        }

        if ($driver !== 'database') {
            $this->info("Connexion de queue non surveillée : {$connection} ({$driver}).");

            return self::SUCCESS;
        }

        $table = (string) ($queueConfig['table'] ?? config('queue.connections.database.table', 'jobs'));

        if (! Schema::hasTable($table)) {
            $this->info('Table de queue absente : '.$table);

            return self::SUCCESS;
        }

        $rows = DB::table($table)
            ->select('queue', DB::raw('COUNT(*) as total'))
            ->whereNull('reserved_at')
            ->groupBy('queue')
            ->get();

        $alerts = 0;

        foreach ($rows as $row) {
            $queue = (string) ($row->queue ?: 'default');
            $total = (int) $row->total;

            if ($total <= $threshold) {
                continue;
            }

            $alerts++;
            $this->warn("Queue {$queue} : {$total} jobs en attente (seuil {$threshold}).");
            $this->recordAlert($queue, $total, $threshold);
        }

        if ($alerts === 0) {
            $this->info('Queue HUB OK.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $queueConfig
     */
    private function monitorRedisQueue(array $queueConfig, int $threshold): int
    {
        $redisConnection = (string) ($queueConfig['connection'] ?? 'default');
        $queues = collect(explode(',', (string) ($queueConfig['queue'] ?? 'default')))
            ->map(fn (string $queue): string => trim($queue))
            ->filter()
            ->values();

        if ($queues->isEmpty()) {
            $queues = collect(['default']);
        }

        $alerts = 0;

        try {
            $redis = Redis::connection($redisConnection);

            foreach ($queues as $queue) {
                $total = (int) $redis->llen('queues:'.$queue);

                if ($total <= $threshold) {
                    continue;
                }

                $alerts++;
                $this->warn("Queue {$queue} : {$total} jobs en attente (seuil {$threshold}).");
                $this->recordAlert((string) $queue, $total, $threshold);
            }
        } catch (Throwable $exception) {
            $this->warn('Queue Redis non joignable : '.$exception->getMessage());

            return self::SUCCESS;
        }

        if ($alerts === 0) {
            $this->info('Queue HUB OK.');
        }

        return self::SUCCESS;
    }

    private function recordAlert(string $queue, int $total, int $threshold): void
    {
        $cooldown = max(1, (int) config('crm.queue.alert_cooldown_minutes', 30));
        $recipient = 'queue:'.$queue;

        $alreadyReported = CrmNotificationLog::query()
            ->where('channel', 'monitoring')
            ->where('template_key', 'queue.size.threshold')
            ->where('recipient', $recipient)
            ->where('created_at', '>=', now()->subMinutes($cooldown))
            ->exists();

        if ($alreadyReported) {
            return;
        }

        CrmNotificationLog::query()->create([
            'channel' => 'monitoring',
            'recipient' => $recipient,
            'subject' => 'Alerte queue HUB',
            'template_key' => 'queue.size.threshold',
            'locale' => (string) config('crm.notifications.locale', 'fr'),
            'status' => CrmNotificationLog::STATUS_SENT,
            'payload' => [
                'queue' => $queue,
                'jobs' => $total,
                'threshold' => $threshold,
            ],
            'sent_at' => now(),
        ]);
    }
}
