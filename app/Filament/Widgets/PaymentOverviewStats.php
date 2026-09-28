<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\DTOs\DashboardFilterData;
use App\Models\User;
use App\Services\DashboardMetricsService;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

final class PaymentOverviewStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $filters = DashboardFilterData::fromPageFilters($this->pageFilters ?? [], $user, today(config('app.timezone'))->toImmutable());
        $metrics = app(DashboardMetricsService::class)->stats($filters, $user);

        return [
            Stat::make(
                __($filters->isDefaultMonth() ? 'dashboard.stats.paid_month' : 'dashboard.stats.paid_period'),
                $this->currency($metrics->paidAmount),
            )
                ->description(__('dashboard.stats.paid_description', [
                    'from' => $filters->periodStart->format('d/m'),
                    'until' => $filters->periodEnd->format('d/m'),
                    'count' => $metrics->paidCount,
                ]))
                ->color('success')
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make(__('dashboard.stats.pending'), $this->currency($metrics->openAmount))
                ->description(__('dashboard.stats.pending_description', [
                    'requested' => $metrics->requestedCount,
                    'launched' => $metrics->launchedCount,
                ]))
                ->color('warning')
                ->icon(Heroicon::OutlinedClock),
            Stat::make(__('dashboard.stats.upcoming'), $this->currency($metrics->upcomingAmount))
                ->description($filters->isPeriodClosed()
                    ? __('dashboard.stats.period_closed')
                    : __('dashboard.stats.upcoming_description', [
                        'count' => $metrics->upcomingCount,
                        'until' => $filters->periodEnd->format('d/m'),
                    ]))
                ->color('info')
                ->icon(Heroicon::OutlinedCalendarDays),
            Stat::make(__('dashboard.stats.overdue'), $this->currency($metrics->overdueAmount))
                ->description(__('dashboard.stats.overdue_description', ['count' => $metrics->overdueCount]))
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationTriangle),
        ];
    }

    private function currency(string $amount): string
    {
        return (string) Number::currency((float) $amount, 'BRL', 'pt_BR');
    }
}
