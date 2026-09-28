<?php

declare(strict_types=1);

use App\Filament\Widgets\PaymentOverviewStats;
use App\Filament\Widgets\PaymentsByBranchChart;
use App\Filament\Widgets\PaymentsByCostCenterChart;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    config(['rjet.dashboard.cache_ttl_seconds' => 0]);
    actingAs(User::factory()->operador()->create());
});

it('shows the empty state when the filters match nothing', function (string $widget): void {
    $component = Livewire::test($widget, ['pageFilters' => ['period_start' => '2026-01-01', 'period_end' => '2026-01-31']]);

    expect($component->instance()->isEmpty())->toBeTrue();
    $component->assertOk()->assertSee(__('dashboard.charts.empty'));
})->with(['branch chart' => [PaymentsByBranchChart::class], 'cost center chart' => [PaymentsByCostCenterChart::class]]);

it('renders labels and the three series colors when there is data', function (string $widget): void {
    $branch = Branch::factory()->create(['name' => 'Filial Centro']);
    PaymentRequest::factory()->forBranch($branch)->requested()->create(['due_date' => today(), 'net_amount' => '100.00']);

    $component = Livewire::test($widget, ['pageFilters' => []]);

    expect($component->instance()->isEmpty())->toBeFalse();
    $component->assertOk()
        ->assertSee(__('dashboard.charts.paid'))
        ->assertSee('#10B981')
        ->assertSee('#F59E0B')
        ->assertSee('#EF4444');
})->with(['branch chart' => [PaymentsByBranchChart::class], 'cost center chart' => [PaymentsByCostCenterChart::class]]);

it('renders the four stats cards', function (): void {
    Livewire::test(PaymentOverviewStats::class, ['pageFilters' => []])
        ->assertOk()
        ->assertSee(__('dashboard.stats.paid_month'))
        ->assertSee(__('dashboard.stats.pending'))
        ->assertSee(__('dashboard.stats.upcoming'))
        ->assertSee(__('dashboard.stats.overdue'));
});

it('labels a custom past period as closed', function (): void {
    Livewire::test(PaymentOverviewStats::class, ['pageFilters' => [
        'period_start' => today()->subMonths(2)->startOfMonth()->toDateString(),
        'period_end' => today()->subMonths(2)->endOfMonth()->toDateString(),
    ]])
        ->assertOk()
        ->assertSee(__('dashboard.stats.paid_period'))
        ->assertDontSee(__('dashboard.stats.paid_month'))
        ->assertSee(__('dashboard.stats.period_closed'));
});

it('does not mark the current month as closed', function (): void {
    Livewire::test(PaymentOverviewStats::class, ['pageFilters' => []])
        ->assertOk()
        ->assertDontSee(__('dashboard.stats.period_closed'));
});
