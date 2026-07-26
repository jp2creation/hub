<?php

namespace Tests\Feature;

use App\Models\CachedBillingStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrmStatsSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_command_aggregates_external_billing_data_locally(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://billing.test/clients*' => Http::response([
                'data' => [
                    [
                        'id' => 'C1',
                        'name' => 'Entreprise Demo',
                        'status' => 'active',
                        'created_at' => '2026-07-01',
                    ],
                ],
            ]),
            'https://billing.test/products*' => Http::response([
                'data' => [
                    [
                        'id' => 'P1',
                        'name' => 'Peinture interieure',
                        'family' => 'Peinture',
                    ],
                ],
            ]),
            'https://billing.test/invoices*' => Http::response([
                'data' => [
                    [
                        'id' => 'F1',
                        'client_id' => 'C1',
                        'date' => '2026-07-05',
                        'total' => 300,
                        'lines' => [
                            [
                                'product_id' => 'P1',
                                'quantity' => 2,
                                'price' => 150,
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        config([
            'crm-stats.billing_api.url' => 'https://billing.test',
            'crm-stats.billing_api.key' => 'secret-key',
        ]);

        $this->artisan('hub:sync-billing-data', ['--from-date' => '2026-07-01'])
            ->assertExitCode(0);

        $stat = CachedBillingStat::query()->first();

        $this->assertInstanceOf(CachedBillingStat::class, $stat);
        $this->assertSame('Entreprise Demo', $stat->client_name);
        $this->assertSame('Peinture', $stat->product_family);
        $this->assertSame('300.00', $stat->total_amount);
        $this->assertSame(1, $stat->invoice_count);
    }
}
