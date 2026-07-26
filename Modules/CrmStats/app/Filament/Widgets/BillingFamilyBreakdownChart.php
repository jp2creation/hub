<?php

namespace Modules\CrmStats\Filament\Widgets;

use App\Support\CrmTheme;
use Filament\Widgets\ChartWidget;
use Modules\CrmStats\Filament\Concerns\AuthorizesStatsAccess;
use Modules\CrmStats\Filament\Widgets\Concerns\UsesStatsPeriod;
use Modules\CrmStats\Services\BillingStatsDashboardService;

class BillingFamilyBreakdownChart extends ChartWidget
{
    use AuthorizesStatsAccess;
    use UsesStatsPeriod;

    protected ?string $heading = 'CA par famille de produits';

    protected ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $period = $this->statsPeriod();
        $rows = app(BillingStatsDashboardService::class)->familyBreakdown($period['start'], $period['end']);

        return [
            'datasets' => [
                [
                    'label' => 'CA',
                    'data' => $rows->pluck('total')->map(fn (mixed $value): float => (float) $value)->all(),
                    'backgroundColor' => [CrmTheme::primaryHex(), CrmTheme::accentHex(), '#0ea5a4', '#5267e8', '#64748b', '#16a34a', '#f97316', '#7c3aed'],
                ],
            ],
            'labels' => $rows->pluck('product_family')->all(),
        ];
    }
}
