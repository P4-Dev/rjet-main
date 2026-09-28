<?php

declare(strict_types=1);

use App\DTOs\AnalyticalReportData;
use App\Enums\AnalyticalReportStatus;
use App\Events\Report\AnalyticalReportRequested;
use App\Exceptions\AnalyticalReportException;
use App\Models\AnalyticalReport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\AnalyticalReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @param  array<string, mixed>  $overrides
 */
function reportRequestData(array $overrides = []): AnalyticalReportData
{
    return AnalyticalReportData::fromArray([
        'date_basis' => 'due_date',
        'period_start' => today()->startOfMonth()->toDateString(),
        'period_end' => today()->endOfMonth()->toDateString(),
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    Event::fake([AnalyticalReportRequested::class]);

    $this->operador = User::factory()->operador()->create();
    $this->branch = Branch::factory()->create();
    PaymentRequest::factory()->forBranch($this->branch)->requested()->count(3)->create(['due_date' => today()->startOfMonth()->addDays(5)]);
});

it('creates a queued report and dispatches the event only after commit', function (): void {
    $report = DB::transaction(function (): AnalyticalReport {
        $report = app(AnalyticalReportService::class)->request(reportRequestData(['statuses' => ['requested', 'requested']]), $this->operador);

        Event::assertNotDispatched(AnalyticalReportRequested::class);

        return $report;
    });

    expect($report->status)->toBe(AnalyticalReportStatus::Queued)
        ->and($report->statuses)->toBe(['requested'])
        ->and($report->created_by)->toBe($this->operador->getKey())
        ->and($report->disk)->toBe(config('rjet.reports.disk'));
    Event::assertDispatched(AnalyticalReportRequested::class, fn (AnalyticalReportRequested $event): bool => $event->report->is($report));
});

it('rejects invalid requests', function (string $case): void {
    $data = reportRequestData();
    $user = $this->operador;
    $expected = match ($case) {
        'cliente' => AnalyticalReportException::unauthorized(),
        'inverted period' => AnalyticalReportException::invalidPeriod(),
        'no rows' => AnalyticalReportException::noMatchingRequests(),
        'too many rows' => AnalyticalReportException::tooManyRows(3, 2),
        'too many in progress' => AnalyticalReportException::tooManyInProgress(3),
        'branch outside company' => AnalyticalReportException::noMatchingRequests(),
    };

    match ($case) {
        'cliente' => $user = User::factory()->cliente()->withBranches([$this->branch])->create(),
        'inverted period' => $data = reportRequestData(['period_start' => today()->endOfMonth()->toDateString(), 'period_end' => today()->startOfMonth()->toDateString()]),
        'no rows' => $data = reportRequestData(['period_start' => '2020-01-01', 'period_end' => '2020-01-31']),
        'too many rows' => config(['rjet.reports.max_rows' => 2]),
        'too many in progress' => AnalyticalReport::factory()->count(3)->queued()->create(['created_by' => $this->operador->getKey()]),
        'branch outside company' => $data = reportRequestData(['company_id' => Company::factory()->create()->getKey(), 'branch_id' => $this->branch->getKey()]),
    };

    expect(fn () => app(AnalyticalReportService::class)->request($data, $user))
        ->toThrow(AnalyticalReportException::class, $expected->getMessage());
    expect(AnalyticalReport::query()->where('created_by', $user->getKey())->count())->toBe($case === 'too many in progress' ? 3 : 0);
    Event::assertNotDispatched(AnalyticalReportRequested::class);
})->with(['cliente', 'inverted period', 'no rows', 'too many rows', 'too many in progress', 'branch outside company']);

it('stores an empty status selection as all statuses', function (): void {
    $report = app(AnalyticalReportService::class)->request(reportRequestData(['statuses' => []]), $this->operador);

    expect($report->refresh()->statuses)->toBeNull();
});

it('rejects a request without a period', function (): void {
    expect(fn () => app(AnalyticalReportService::class)->request(reportRequestData(['period_start' => null]), $this->operador))
        ->toThrow(AnalyticalReportException::class, AnalyticalReportException::invalidPeriod()->getMessage());
});

it('counts only requests in the selected statuses', function (): void {
    expect(fn () => app(AnalyticalReportService::class)->request(reportRequestData(['statuses' => ['launched']]), $this->operador))
        ->toThrow(AnalyticalReportException::class, AnalyticalReportException::noMatchingRequests()->getMessage());
});

describe('retry', function (): void {
    it('requeues failed and stale reports and dispatches the request event', function (string $state): void {
        $adm = User::factory()->adm()->create();
        $report = AnalyticalReport::factory()->{$state}()->create(['created_by' => $this->operador->getKey()]);

        $retried = app(AnalyticalReportService::class)->retry($report, $adm);

        expect($retried->status)->toBe(AnalyticalReportStatus::Queued)
            ->and($retried->failure_reason)->toBeNull()
            ->and($retried->updated_by)->toBe($adm->getKey())
            ->and($retried->created_by)->toBe($this->operador->getKey());
        Event::assertDispatched(AnalyticalReportRequested::class, fn (AnalyticalReportRequested $event): bool => $event->report->is($report));
    })->with(['failed', 'stale']);

    it('refuses reports that are not retryable', function (string $state): void {
        $report = AnalyticalReport::factory()->{$state}()->create();

        expect(fn () => app(AnalyticalReportService::class)->retry($report, $this->operador))
            ->toThrow(AnalyticalReportException::class, AnalyticalReportException::notRetryable()->getMessage());
        expect($report->refresh()->status->value)->toBe($state);
        Event::assertNotDispatched(AnalyticalReportRequested::class);
    })->with(['queued', 'generating', 'generated']);

    it('refuses clients', function (): void {
        $cliente = User::factory()->cliente()->withBranches([$this->branch])->create();
        $report = AnalyticalReport::factory()->failed()->create();

        expect(fn () => app(AnalyticalReportService::class)->retry($report, $cliente))
            ->toThrow(AnalyticalReportException::class, AnalyticalReportException::unauthorized()->getMessage());
        expect($report->refresh()->status)->toBe(AnalyticalReportStatus::Failed);
    });

    it('refuses a second retry made from an outdated copy', function (): void {
        $report = AnalyticalReport::factory()->failed()->create();
        $outdatedCopy = AnalyticalReport::query()->findOrFail($report->getKey());

        app(AnalyticalReportService::class)->retry($report, $this->operador);

        expect(fn () => app(AnalyticalReportService::class)->retry($outdatedCopy, $this->operador))
            ->toThrow(AnalyticalReportException::class, AnalyticalReportException::notRetryable()->getMessage());
        Event::assertDispatchedTimes(AnalyticalReportRequested::class, 1);
    });
});
