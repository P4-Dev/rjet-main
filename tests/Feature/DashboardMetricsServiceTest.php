<?php

declare(strict_types=1);

use App\DTOs\DashboardFilterData;
use App\DTOs\DashboardMetrics;
use App\DTOs\DashboardSeries;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use App\Services\DashboardMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * @param  array<string, mixed>  $filters
 */
function dashboardFilters(User $user, array $filters = []): DashboardFilterData
{
    return DashboardFilterData::fromPageFilters($filters, $user, today(config('app.timezone'))->toImmutable());
}

function dashboardOpenRequest(Branch $branch, string $status, string $dueDate, string $amount, ?CostCenter $costCenter = null): PaymentRequest
{
    return PaymentRequest::factory()->forBranch($branch)->{$status}()->create([
        'due_date' => $dueDate,
        'gross_amount' => $amount,
        'net_amount' => $amount,
        ...($costCenter !== null ? ['cost_center_id' => $costCenter->getKey()] : []),
    ]);
}

function dashboardPaidRequest(Branch $branch, string $amount, string $settlementDate, string $settlementState = 'settled', bool $released = false): PaymentRequest
{
    $request = PaymentRequest::factory()->forBranch($branch)->{$settlementState === 'settled' && ! $released ? 'settled' : 'launched'}()->create([
        'gross_amount' => $amount,
        'net_amount' => $amount,
        'due_date' => $settlementDate,
    ]);
    $settlement = PaymentSettlement::factory()->forBranch($branch)->{$settlementState}()->dated($settlementDate)->create();
    $item = PaymentSettlementItem::factory()->for($settlement, 'settlement')->forPaymentRequest($request);

    ($released ? $item->released() : $item)->create();

    return $request;
}

/**
 * @return array{paid: string, upcoming: string, overdue: string}
 */
function dashboardSeriesTotals(DashboardSeries $series): array
{
    $sum = fn (array $values): string => array_reduce($values, fn (string $carry, string $value): string => bcadd($carry, $value, 2), '0.00');

    return ['paid' => $sum($series->paid), 'upcoming' => $sum($series->upcoming), 'overdue' => $sum($series->overdue)];
}

/**
 * @return array{paid: string, upcoming: string, overdue: string}
 */
function dashboardStatsTotals(DashboardMetrics $metrics): array
{
    return ['paid' => $metrics->paidAmount, 'upcoming' => $metrics->upcomingAmount, 'overdue' => $metrics->overdueAmount];
}

function dashboardService(): DashboardMetricsService
{
    return app(DashboardMetricsService::class);
}

beforeEach(function (): void {
    config(['rjet.dashboard.cache_ttl_seconds' => 0]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 10:00', 'America/Sao_Paulo'));

    $this->company = Company::factory()->create();
    $this->branch = Branch::factory()->for($this->company)->create();
    $this->operador = User::factory()->operador()->create();

    $this->overdue = dashboardOpenRequest($this->branch, 'requested', '2026-09-10', '100.00');
    dashboardOpenRequest($this->branch, 'launched', '2026-08-20', '200.00');
    dashboardOpenRequest($this->branch, 'requested', '2026-09-15', '300.00');
    dashboardOpenRequest($this->branch, 'launched', '2026-09-25', '400.00');
    dashboardOpenRequest($this->branch, 'requested', '2026-10-10', '500.00');
    $this->paid = dashboardPaidRequest($this->branch, '1000.00', '2026-09-05');
    dashboardPaidRequest($this->branch, '2000.00', '2026-08-28');
    dashboardPaidRequest($this->branch, '50.00', '2026-09-30', 'draft');
    dashboardPaidRequest($this->branch, '70.00', '2026-09-20', 'settled', released: true);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('computes the four indicators for the current month', function (): void {
    $metrics = dashboardService()->stats(dashboardFilters($this->operador), $this->operador);

    expect($metrics->paidAmount)->toBe('1000.00')
        ->and($metrics->paidCount)->toBe(1)
        ->and($metrics->openAmount)->toBe('1120.00')
        ->and($metrics->openCount)->toBe(6)
        ->and($metrics->requestedCount)->toBe(2)
        ->and($metrics->launchedCount)->toBe(4)
        ->and($metrics->upcomingAmount)->toBe('820.00')
        ->and($metrics->upcomingCount)->toBe(4)
        ->and($metrics->overdueAmount)->toBe('300.00')
        ->and($metrics->overdueCount)->toBe(2);
});

it('keeps pending equal to upcoming plus overdue', function (array $period, string $open, string $upcoming): void {
    $metrics = dashboardService()->stats(dashboardFilters($this->operador, $period), $this->operador);

    expect($metrics->openAmount)->toBe($open)
        ->and($metrics->upcomingAmount)->toBe($upcoming)
        ->and(bccomp($metrics->openAmount, bcadd($metrics->upcomingAmount, $metrics->overdueAmount, 2), 2))->toBe(0)
        ->and($metrics->openCount)->toBe($metrics->upcomingCount + $metrics->overdueCount);
})->with([
    'current month' => [['period_start' => '2026-09-01', 'period_end' => '2026-09-30'], '1120.00', '820.00'],
    'past period' => [['period_start' => '2026-07-01', 'period_end' => '2026-08-31'], '200.00', '0.00'],
    'future period' => [['period_start' => '2026-10-01', 'period_end' => '2026-10-31'], '1620.00', '1320.00'],
]);

it('ignores net amount edits on paid requests, draft settlements, released items and soft-deleted requests', function (): void {
    $this->paid->update(['net_amount' => '9999.00']);

    $metrics = dashboardService()->stats(dashboardFilters($this->operador), $this->operador);

    expect($metrics->paidAmount)->toBe('1000.00')
        ->and($metrics->paidCount)->toBe(1);

    $this->paid->delete();
    $this->overdue->delete();

    $metrics = dashboardService()->stats(dashboardFilters($this->operador), $this->operador);

    expect($metrics->paidAmount)->toBe('0.00')
        ->and($metrics->paidCount)->toBe(0)
        ->and($metrics->overdueAmount)->toBe('200.00')
        ->and($metrics->openAmount)->toBe('1020.00');
});

it('filters by company, branch and period without hiding earlier overdue requests', function (): void {
    $otherBranch = Branch::factory()->create();
    dashboardOpenRequest($otherBranch, 'requested', '2026-09-01', '900.00');
    dashboardPaidRequest($otherBranch, '800.00', '2026-09-02');

    $byCompany = dashboardService()->stats(dashboardFilters($this->operador, ['company_id' => $this->company->getKey()]), $this->operador);
    $byBranch = dashboardService()->stats(dashboardFilters($this->operador, ['branch_id' => $this->branch->getKey()]), $this->operador);
    $mismatch = dashboardService()->stats(dashboardFilters($this->operador, [
        'company_id' => $this->company->getKey(),
        'branch_id' => $otherBranch->getKey(),
    ]), $this->operador);
    $latePeriod = dashboardService()->stats(dashboardFilters($this->operador, [
        'period_start' => '2026-09-20',
        'period_end' => '2026-09-30',
    ]), $this->operador);

    expect($byCompany->overdueAmount)->toBe('300.00')
        ->and($byCompany->paidAmount)->toBe('1000.00')
        ->and($byBranch->paidAmount)->toBe('1000.00')
        ->and($byBranch->openAmount)->toBe('1120.00')
        ->and($mismatch->toArray())->toBe((new DashboardMetrics('0.00', 0, '0.00', 0, 0, 0, '0.00', 0, '0.00', 0))->toArray())
        ->and($latePeriod->overdueAmount)->toBe('1200.00')
        ->and($latePeriod->paidAmount)->toBe('0.00');
});

it('scopes clients to their linked branches', function (): void {
    $otherBranch = Branch::factory()->create();
    dashboardOpenRequest($otherBranch, 'requested', '2026-09-01', '900.00');
    $cliente = User::factory()->cliente()->withBranches([$this->branch])->create();
    $adm = User::factory()->adm()->create();

    $clientMetrics = dashboardService()->stats(dashboardFilters($cliente), $cliente);
    $forgedFilter = dashboardService()->stats(dashboardFilters($cliente, ['branch_id' => $otherBranch->getKey()]), $cliente);

    expect($clientMetrics->overdueAmount)->toBe('300.00')
        ->and($forgedFilter->openAmount)->toBe('0.00')
        ->and($forgedFilter->openCount)->toBe(0)
        ->and(dashboardService()->stats(dashboardFilters($this->operador), $this->operador)->overdueAmount)->toBe('1200.00')
        ->and(dashboardService()->stats(dashboardFilters($adm), $adm)->overdueAmount)->toBe('1200.00');
});

it('uses the São Paulo date as today', function (string $now, string $dueToday): void {
    Carbon::setTestNow(CarbonImmutable::parse($now, 'America/Sao_Paulo'));
    PaymentRequest::query()->delete();
    dashboardOpenRequest($this->branch, 'requested', $dueToday, '10.00');

    $metrics = dashboardService()->stats(dashboardFilters($this->operador), $this->operador);

    expect($metrics->upcomingAmount)->toBe('10.00')
        ->and($metrics->overdueAmount)->toBe('0.00');
})->with([
    'just after midnight' => ['2026-09-16 00:30', '2026-09-16'],
    'late evening (next day in UTC)' => ['2026-09-15 23:30', '2026-09-15'],
]);

it('keeps chart totals equal to the stats cards', function (array $filters): void {
    $otherBranch = Branch::factory()->for($this->company)->create();
    dashboardOpenRequest($otherBranch, 'launched', '2026-09-03', '150.00');
    dashboardOpenRequest($otherBranch, 'requested', '2026-09-28', '250.00');
    dashboardPaidRequest($otherBranch, '350.00', '2026-09-12');

    $resolved = collect($filters)->map(fn (string $value): string => match ($value) {
        'company' => $this->company->getKey(),
        'branch' => $this->branch->getKey(),
        default => $value,
    })->all();
    $dashboardFilters = dashboardFilters($this->operador, $resolved);
    $stats = dashboardStatsTotals(dashboardService()->stats($dashboardFilters, $this->operador));

    expect(dashboardSeriesTotals(dashboardService()->byBranch($dashboardFilters, $this->operador)))->toBe($stats)
        ->and(dashboardSeriesTotals(dashboardService()->byCostCenter($dashboardFilters, $this->operador)))->toBe($stats);
})->with([
    'no filters' => [[]],
    'company filter' => [['company_id' => 'company']],
    'branch filter' => [['branch_id' => 'branch']],
    'custom period' => [['period_start' => '2026-08-01', 'period_end' => '2026-09-20']],
]);

it('collapses extra categories into others and keeps deleted labels', function (): void {
    foreach (range(1, 14) as $index) {
        dashboardOpenRequest(Branch::factory()->create(['name' => sprintf('Filial %02d', $index)]), 'requested', '2026-09-20', sprintf('%d.00', $index * 10));
    }

    $filters = dashboardFilters($this->operador);
    $series = dashboardService()->byBranch($filters, $this->operador);

    expect($series->labels)->toHaveCount(12)
        ->and($series->labels[11])->toBe(__('dashboard.charts.others'))
        ->and(dashboardSeriesTotals($series))->toBe(dashboardStatsTotals(dashboardService()->stats($filters, $this->operador)));

    PaymentRequest::query()->delete();
    $costCenter = CostCenter::factory()->forBranch($this->branch)->create(['code' => 'CC-001', 'name' => 'Frota']);
    dashboardOpenRequest($this->branch, 'requested', '2026-09-20', '10.00', $costCenter);
    $costCenter->delete();
    $this->branch->update(['name' => 'Matriz']);
    $this->branch->delete();

    expect(dashboardService()->byCostCenter($filters, $this->operador)->labels)->toBe(['CC-001 — Frota (Matriz)'])
        ->and(dashboardService()->byCostCenter(dashboardFilters($this->operador, ['branch_id' => $this->branch->getKey()]), $this->operador)->labels)->toBe(['CC-001 — Frota'])
        ->and(dashboardService()->byBranch($filters, $this->operador)->labels)->toBe(['Matriz']);
});

it('leaves soft-deleted settlements and settled requests without settlement out of paid', function (): void {
    PaymentSettlement::query()->whereHas('items', fn ($items) => $items->where('payment_request_id', $this->paid->getKey()))->firstOrFail()->delete();
    PaymentRequest::factory()->forBranch($this->branch)->settled()->create(['net_amount' => '555.00', 'due_date' => '2026-09-08']);

    $metrics = dashboardService()->stats(dashboardFilters($this->operador), $this->operador);

    expect($metrics->paidAmount)->toBe('0.00')
        ->and($metrics->paidCount)->toBe(0)
        ->and(dashboardSeriesTotals(dashboardService()->byBranch(dashboardFilters($this->operador), $this->operador))['paid'])->toBe('0.00');
});

it('swaps an inverted period instead of returning nothing', function (): void {
    $inverted = dashboardService()->stats(dashboardFilters($this->operador, ['period_start' => '2026-09-30', 'period_end' => '2026-09-01']), $this->operador);
    $ordered = dashboardService()->stats(dashboardFilters($this->operador, ['period_start' => '2026-09-01', 'period_end' => '2026-09-30']), $this->operador);

    expect($inverted->toArray())->toBe($ordered->toArray())
        ->and($inverted->paidAmount)->toBe('1000.00');
});
