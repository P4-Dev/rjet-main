<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\DTOs\DashboardFilterData;
use App\Models\User;
use App\Services\DashboardMetricsService;
use Filament\Facades\Filament;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

final class PaymentsByCostCenterChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '320px';

    public function getHeading(): string|Htmlable|null
    {
        return __('dashboard.charts.by_cost_center');
    }

    public function getDescription(): string|Htmlable|null
    {
        $filters = $this->filters();

        return $filters === null ? null : sprintf(
            '%s – %s',
            $filters->periodStart->format('d/m/Y'),
            $filters->periodEnd->format('d/m/Y'),
        );
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return __('dashboard.charts.empty');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $filters = $this->filters();
        $user = Filament::auth()->user();

        if ($filters === null || ! $user instanceof User) {
            return [];
        }

        return PaymentsByBranchChart::chartData(app(DashboardMetricsService::class)->byCostCenter($filters, $user));
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                scales: {
                    x: { ticks: { callback: (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value) } },
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed.x)}`,
                        },
                    },
                },
            }
        JS);
    }

    private function filters(): ?DashboardFilterData
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? DashboardFilterData::fromPageFilters($this->pageFilters ?? [], $user, today(config('app.timezone'))->toImmutable())
            : null;
    }
}
