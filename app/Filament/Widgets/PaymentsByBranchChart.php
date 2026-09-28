<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\DTOs\DashboardFilterData;
use App\DTOs\DashboardSeries;
use App\Models\User;
use App\Services\DashboardMetricsService;
use Filament\Facades\Filament;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

final class PaymentsByBranchChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '320px';

    public function getHeading(): string|Htmlable|null
    {
        return __('dashboard.charts.by_branch');
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

        return self::chartData(app(DashboardMetricsService::class)->byBranch($filters, $user));
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                scales: {
                    y: { ticks: { callback: (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value) } },
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed.y)}`,
                        },
                    },
                },
            }
        JS);
    }

    /**
     * @return array<string, mixed>
     */
    public static function chartData(DashboardSeries $series): array
    {
        if ($series->isEmpty()) {
            return [];
        }

        $floats = fn (array $values): array => array_map(fn (string $value): float => (float) $value, $values);

        return [
            'labels' => $series->labels,
            'datasets' => [
                ['label' => __('dashboard.charts.paid'), 'data' => $floats($series->paid), 'backgroundColor' => '#10B981'],
                ['label' => __('dashboard.charts.upcoming'), 'data' => $floats($series->upcoming), 'backgroundColor' => '#F59E0B'],
                ['label' => __('dashboard.charts.overdue'), 'data' => $floats($series->overdue), 'backgroundColor' => '#EF4444'],
            ],
        ];
    }

    private function filters(): ?DashboardFilterData
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? DashboardFilterData::fromPageFilters($this->pageFilters ?? [], $user, today(config('app.timezone'))->toImmutable())
            : null;
    }
}
