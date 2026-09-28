<?php

declare(strict_types=1);

use App\Enums\AnalyticalReportStatus;
use App\Enums\ReportDateBasis;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AnalyticalReports\Pages\ListAnalyticalReports;
use App\Filament\Resources\AnalyticalReports\Pages\ViewAnalyticalReport;
use App\Jobs\Report\GenerateAnalyticalReportJob;
use App\Models\AnalyticalReport;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('renders list and view pages for staff', function (string $role): void {
    actingAs(User::factory()->{$role}()->create());
    $report = AnalyticalReport::factory()->generated()->create();

    Livewire::test(ListAnalyticalReports::class)->assertOk()->assertCanSeeTableRecords([$report]);
    Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()])->assertOk();
})->with(['operador', 'adm']);

it('forbids clients from the report pages', function (): void {
    actingAs(User::factory()->cliente()->withBranches(1)->create());
    $report = AnalyticalReport::factory()->generated()->create();

    Livewire::test(ListAnalyticalReports::class)->assertForbidden();
    Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()])->assertForbidden();
});

it('requests a report from the list action', function (): void {
    Queue::fake();
    $operador = User::factory()->operador()->create();
    actingAs($operador);
    $branch = Branch::factory()->create();
    PaymentRequest::factory()->forBranch($branch)->create(['due_date' => today()->startOfMonth()->addDays(3)]);

    Livewire::test(ListAnalyticalReports::class)
        ->callAction('requestAnalyticalReport', data: [
            'date_basis' => ReportDateBasis::DueDate->value,
            'period_start' => today()->startOfMonth()->toDateString(),
            'period_end' => today()->endOfMonth()->toDateString(),
            'branch_id' => $branch->getKey(),
        ])
        ->assertHasNoFormErrors()
        ->assertNotified(__('analytical_reports.messages.queued'));

    $report = AnalyticalReport::query()->sole();
    expect($report->status)->toBe(AnalyticalReportStatus::Queued)
        ->and($report->branch_id)->toBe($branch->getKey())
        ->and($report->created_by)->toBe($operador->getKey());
    Queue::assertPushed(GenerateAnalyticalReportJob::class);
});

it('keeps the modal open with a toast when the filters match nothing', function (): void {
    actingAs(User::factory()->operador()->create());

    Livewire::test(Dashboard::class)
        ->callAction('requestAnalyticalReport', data: [
            'period_start' => '2020-01-01',
            'period_end' => '2020-01-31',
        ])
        ->assertNotified(__('analytical_reports.errors.no_matching_requests'));

    expect(AnalyticalReport::query()->count())->toBe(0);
});

it('shows retry only for failed or stale reports', function (string $state, bool $visible): void {
    actingAs(User::factory()->operador()->create());
    $report = AnalyticalReport::factory()->{$state}()->create();

    $component = Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()]);

    $visible
        ? $component->assertActionVisible('retryAnalyticalReport')
        : $component->assertActionHidden('retryAnalyticalReport');
})->with([
    'failed' => ['failed', true],
    'stale' => ['stale', true],
    'recent generating' => ['generating', false],
]);

it('requeues a failed report from the view retry action', function (): void {
    Queue::fake();
    actingAs(User::factory()->operador()->create());
    $report = AnalyticalReport::factory()->failed()->create();

    Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()])
        ->callAction('retryAnalyticalReport')
        ->assertNotified(__('analytical_reports.messages.retry_queued'));

    expect($report->refresh()->status)->toBe(AnalyticalReportStatus::Queued)
        ->and($report->failure_reason)->toBeNull();
    Queue::assertPushed(GenerateAnalyticalReportJob::class, fn (GenerateAnalyticalReportJob $job): bool => $job->report->is($report));
});

it('polls the view only while the report is in progress', function (string $state, bool $polls): void {
    actingAs(User::factory()->operador()->create());
    $report = AnalyticalReport::factory()->{$state}()->create();

    $component = Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()]);

    $polls
        ? $component->assertSeeHtml('wire:poll.5s="refreshReport"')
        : $component->assertDontSeeHtml('wire:poll.5s');
})->with([
    'queued' => ['queued', true],
    'generating' => ['generating', true],
    'generated' => ['generated', false],
    'failed' => ['failed', false],
]);

it('shows the download action after a refresh once generation finishes', function (): void {
    actingAs(User::factory()->operador()->create());
    $report = AnalyticalReport::factory()->generating()->create();

    $component = Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()])
        ->assertActionHidden('downloadAnalyticalReport')
        ->assertSeeHtml('wire:poll.5s="refreshReport"');

    $report->update([
        'status' => AnalyticalReportStatus::Generated,
        'path' => 'reports/2026/09/'.$report->getKey().'.xlsx',
        'filename' => 'relatorio.xlsx',
    ]);

    $component->call('refreshReport')
        ->assertActionVisible('downloadAnalyticalReport')
        ->assertDontSeeHtml('wire:poll.5s');
});

it('forbids operators from opening a deleted report', function (): void {
    actingAs(User::factory()->operador()->create());
    $report = AnalyticalReport::factory()->generated()->create();
    $report->delete();

    Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()])->assertForbidden();
});

it('shows download only for generated reports', function (string $state, bool $visible): void {
    actingAs(User::factory()->operador()->create());
    $report = AnalyticalReport::factory()->{$state}()->create();

    $component = Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()]);

    $visible
        ? $component->assertActionVisible('downloadAnalyticalReport')
        : $component->assertActionHidden('downloadAnalyticalReport');
})->with([
    'generated' => ['generated', true],
    'queued' => ['queued', false],
    'failed' => ['failed', false],
]);

it('lets only admins delete a report', function (string $role, bool $visible): void {
    actingAs(User::factory()->{$role}()->create());
    $report = AnalyticalReport::factory()->generated()->create();

    $component = Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()]);

    $visible
        ? $component->assertActionVisible('delete')
        : $component->assertActionHidden('delete');
})->with([
    'operador' => ['operador', false],
    'adm' => ['adm', true],
]);

it('keeps the file on disk when an admin soft deletes and restores a report', function (): void {
    Storage::fake('local');
    config(['rjet.reports.disk' => 'local']);
    actingAs(User::factory()->adm()->create());
    $report = AnalyticalReport::factory()->generated()->create();
    Storage::disk('local')->put($report->path, 'xlsx-bytes');

    Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()])
        ->callAction('delete');

    expect($report->refresh()->trashed())->toBeTrue();
    Storage::disk('local')->assertExists($report->path);

    Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()])
        ->callAction('restore');

    expect($report->refresh()->trashed())->toBeFalse();
});

it('filters the list by status and by the current user', function (): void {
    $operador = User::factory()->operador()->create();
    actingAs($operador);
    $mineFailed = AnalyticalReport::factory()->failed()->create(['created_by' => $operador->getKey()]);
    $mineGenerated = AnalyticalReport::factory()->generated()->create(['created_by' => $operador->getKey()]);
    $othersFailed = AnalyticalReport::factory()->failed()->create();

    Livewire::test(ListAnalyticalReports::class)
        ->filterTable('status', AnalyticalReportStatus::Failed->value)
        ->assertCanSeeTableRecords([$mineFailed, $othersFailed])
        ->assertCanNotSeeTableRecords([$mineGenerated]);

    Livewire::test(ListAnalyticalReports::class)
        ->filterTable('mine')
        ->assertCanSeeTableRecords([$mineFailed, $mineGenerated])
        ->assertCanNotSeeTableRecords([$othersFailed]);
});

it('validates the request form', function (array $data, array $errors): void {
    actingAs(User::factory()->operador()->create());

    Livewire::test(ListAnalyticalReports::class)
        ->callAction('requestAnalyticalReport', data: $data)
        ->assertHasFormErrors($errors);

    expect(AnalyticalReport::query()->count())->toBe(0);
})->with([
    'end before start' => [['period_start' => '2026-09-30', 'period_end' => '2026-09-01'], ['period_end' => 'after_or_equal']],
    'missing period' => [['period_start' => null, 'period_end' => null], ['period_start' => 'required', 'period_end' => 'required']],
]);
