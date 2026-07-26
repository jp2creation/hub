<?php

namespace Tests\Feature;

use App\Models\CrmNotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class CrmQueueMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_monitor_records_alert_when_waiting_jobs_exceed_threshold(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
        ]);

        $this->artisan('hub:monitor-queue-size', ['--threshold' => 1])
            ->assertSuccessful();

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'monitoring',
            'recipient' => 'queue:default',
            'template_key' => 'queue.size.threshold',
            'status' => CrmNotificationLog::STATUS_SENT,
        ]);

        $alert = CrmNotificationLog::query()
            ->where('template_key', 'queue.size.threshold')
            ->firstOrFail();

        $this->assertSame('default', $alert->payload['queue']);
        $this->assertSame(2, $alert->payload['jobs']);
        $this->assertSame(1, $alert->payload['threshold']);
    }

    public function test_queue_monitor_records_alert_for_redis_waiting_jobs(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
            'queue.connections.redis.queue' => 'default',
        ]);

        $redis = \Mockery::mock();
        $redis->shouldReceive('llen')
            ->once()
            ->with('queues:default')
            ->andReturn(2);

        Redis::shouldReceive('connection')
            ->once()
            ->with('queue')
            ->andReturn($redis);

        $this->artisan('hub:monitor-queue-size', ['--threshold' => 1])
            ->assertSuccessful();

        $alert = CrmNotificationLog::query()
            ->where('template_key', 'queue.size.threshold')
            ->firstOrFail();

        $this->assertSame('default', $alert->payload['queue']);
        $this->assertSame(2, $alert->payload['jobs']);
        $this->assertSame(1, $alert->payload['threshold']);
    }
}
