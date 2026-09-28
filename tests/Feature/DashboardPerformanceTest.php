<?php

declare(strict_types=1);

use App\Filament\Widgets\PaymentOverviewStats;
use App\Filament\Widgets\PaymentsByBranchChart;
use App\Filament\Widgets\PaymentsByCostCenterChart;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * @param  class-string  $widget
 */
function dashboardWidgetQueryCount(string $widget): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test($widget, ['pageFilters' => []])->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('renders the dashboard widgets with a fixed number of queries', function (): void {
    config(['rjet.dashboard.cache_ttl_seconds' => 0]);
    actingAs(User::factory()->operador()->create());

    $branches = Branch::factory()->count(5)->create();
    $costCenters = $branches->flatMap(fn (Branch $branch) => CostCenter::factory()->count(2)->forBranch($branch)->create());
    $supplier = Supplier::factory()->create();

    PaymentRequest::factory()
        ->count(2000)
        ->state(new Sequence(fn (Sequence $sequence): array => [
            'branch_id' => $costCenters[$sequence->index % 10]->branch_id,
            'cost_center_id' => $costCenters[$sequence->index % 10]->getKey(),
            'supplier_id' => $supplier->getKey(),
            'status' => $sequence->index % 2 === 0 ? 'requested' : 'launched',
            'due_date' => today()->addDays(($sequence->index % 40) - 20)->toDateString(),
        ]))
        ->create();

    expect(dashboardWidgetQueryCount(PaymentOverviewStats::class))->toBe(2)
        ->and(dashboardWidgetQueryCount(PaymentsByBranchChart::class))->toBe(3)
        ->and(dashboardWidgetQueryCount(PaymentsByCostCenterChart::class))->toBe(4);
});
