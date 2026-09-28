<?php

declare(strict_types=1);

use App\DTOs\DashboardFilterData;
use App\Enums\PaymentRequestStatus;
use App\Events\PaymentRequest\PaymentRequestBatchImported;
use App\Events\PaymentRequest\PaymentRequestCreated;
use App\Events\PaymentRequest\PaymentRequestStatusChanged;
use App\Events\Settlement\PaymentSettlementCancelled;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\ImportBatch;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\DashboardMetricsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function dashboardCacheVersion(): int
{
    return (int) Cache::get(DashboardMetricsService::VERSION_KEY, 1);
}

function dashboardCacheFilters(User $user): DashboardFilterData
{
    return DashboardFilterData::fromPageFilters([], $user, today(config('app.timezone'))->toImmutable());
}

beforeEach(function (): void {
    config(['rjet.dashboard.cache_ttl_seconds' => 300]);
    Notification::fake();

    $this->operador = User::factory()->operador()->create();
    $this->branch = Branch::factory()->create();
    $this->request = PaymentRequest::factory()->forBranch($this->branch)->requested()->create(['due_date' => today()->addDay()]);
});

it('serves repeated calls with the same filters from cache', function (): void {
    $service = app(DashboardMetricsService::class);
    $filters = dashboardCacheFilters($this->operador);
    $first = $service->stats($filters, $this->operador);

    DB::enableQueryLog();
    $second = $service->stats($filters, $this->operador);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($second)->toEqual($first);
});

it('bumps the cache version on payment request events', function (string $eventName): void {
    $event = match ($eventName) {
        'created' => new PaymentRequestCreated($this->request),
        'status_changed' => new PaymentRequestStatusChanged($this->request, PaymentRequestStatus::Requested, PaymentRequestStatus::Launched, $this->operador),
        'batch_imported' => new PaymentRequestBatchImported(ImportBatch::factory()->completed()->create()),
    };
    $before = dashboardCacheVersion();

    event($event);

    expect(dashboardCacheVersion())->toBe($before + 1);
})->with(['created', 'status_changed', 'batch_imported']);

it('bumps the cache version when metric columns change on the observer', function (string $column): void {
    $value = match ($column) {
        'due_date' => today()->addDays(10)->toDateString(),
        'net_amount' => '12345.67',
        'cost_center_id' => CostCenter::factory()->forBranch($this->branch)->create()->getKey(),
        'branch_id' => Branch::factory()->create()->getKey(),
    };
    $before = dashboardCacheVersion();

    $this->request->update([$column => $value]);

    expect(dashboardCacheVersion())->toBe($before + 1);
})->with(['due_date', 'net_amount', 'cost_center_id', 'branch_id']);

it('keeps the cache version on unrelated changes and settlement cancellation', function (): void {
    $before = dashboardCacheVersion();

    $this->request->update(['notes' => 'irrelevant']);
    event(new PaymentSettlementCancelled(PaymentSettlement::factory()->cancelled()->create()));

    expect(dashboardCacheVersion())->toBe($before);
});

it('isolates client cache keys from staff keys', function (): void {
    $service = app(DashboardMetricsService::class);
    $cliente = User::factory()->cliente()->withBranches([$this->branch])->create();
    $otherCliente = User::factory()->cliente()->withBranches(1)->create();

    $staffKey = $service->cacheKey('stats', dashboardCacheFilters($this->operador), $this->operador);
    $clientKey = $service->cacheKey('stats', dashboardCacheFilters($cliente), $cliente);
    $otherClientKey = $service->cacheKey('stats', dashboardCacheFilters($otherCliente), $otherCliente);

    expect($staffKey)->toContain(':all:')
        ->and($clientKey)->not->toBe($staffKey)
        ->and($clientKey)->not->toBe($otherClientKey);
});

it('only bumps the version after the surrounding transaction commits', function (): void {
    $before = dashboardCacheVersion();

    DB::transaction(function () use ($before): void {
        app(DashboardMetricsService::class)->flush();

        expect(dashboardCacheVersion())->toBe($before);
    });

    expect(dashboardCacheVersion())->toBe($before + 1);
});

it('rebuilds chart labels when the locale changes', function (): void {
    config(['rjet.dashboard.chart_max_categories' => 2]);

    foreach (range(1, 3) as $index) {
        PaymentRequest::factory()->forBranch(Branch::factory()->create(['name' => "Filial {$index}"]))->requested()->create([
            'due_date' => today()->addDay(),
            'net_amount' => sprintf('%d.00', $index * 10),
        ]);
    }

    $service = app(DashboardMetricsService::class);
    $filters = dashboardCacheFilters($this->operador);
    $locale = app()->getLocale();

    app()->setLocale('pt_BR');
    $service->byBranch($filters, $this->operador);

    app()->setLocale('en');

    expect($service->byBranch($filters, $this->operador)->labels)
        ->toContain('Others')
        ->not->toContain('Outros');

    app()->setLocale($locale);
});

it('bumps the cache version when a payment request is soft deleted or restored', function (): void {
    $before = dashboardCacheVersion();

    $this->request->delete();

    expect(dashboardCacheVersion())->toBe($before + 1);

    $this->request->restore();

    expect(dashboardCacheVersion())->toBe($before + 2);
});

it('bumps the cache version when a payment request is force deleted', function (): void {
    $before = dashboardCacheVersion();

    $this->request->forceDelete();

    expect(dashboardCacheVersion())->toBeGreaterThan($before);
});

it('queries again on every call when the ttl is zero', function (): void {
    config(['rjet.dashboard.cache_ttl_seconds' => 0]);
    $service = app(DashboardMetricsService::class);
    $filters = dashboardCacheFilters($this->operador);
    $service->stats($filters, $this->operador);

    DB::enableQueryLog();
    $service->stats($filters, $this->operador);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeGreaterThan(0);
});
