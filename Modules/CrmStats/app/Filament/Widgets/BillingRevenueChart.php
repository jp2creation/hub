<?php

namespace Modules\CrmStats\Filament\Widgets;

use App\Support\CrmTheme;
use Filament\Widgets\ChartWidget;
use Modules\CrmStats\Filament\Concerns\AuthorizesStatsAccess;
use Modules\CrmStats\Filament\Widgets\Concerns\UsesStatsPeriod;
use Modules\CrmStats\Services\BillingStatsDashboardService;

class BillingRevenueChart extends ChartWidget
{
    use AuthorizesStatsAccess;
    use UsesStatsPeriod;

    protected ?string $heading = 'Evolution du CA';

    protected ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $period = $this->statsPeriod();
        $series = app(BillingStatsDashboardService::class)->revenueSeries($period['start'], $period['end']);

        return [
            'datasets' => [
                [
                    'label' => 'CA',
                    'data' => $series->pluck('total')->map(fn (mixed $value): float => (float) $value)->all(),
                    'borderColor' => CrmTheme::primaryHex(),
                    'backgroundColor' => CrmTheme::primaryRgba(0.12),
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $series->pluck('period')->all(),
        ];
    }
}
