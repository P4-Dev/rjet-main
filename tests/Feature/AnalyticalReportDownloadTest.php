<?php

declare(strict_types=1);

use App\Events\Report\AnalyticalReportDownloaded;
use App\Exceptions\AnalyticalReportException;
use App\Filament\Resources\AnalyticalReports\Pages\ViewAnalyticalReport;
use App\Models\AnalyticalReport;
use App\Models\User;
use App\Services\AnalyticalReportService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.reports.disk' => 'local']);
    $this->report = AnalyticalReport::factory()->generated()->create();
    Storage::disk('local')->put($this->report->path, 'xlsx-bytes');
});

it('downloads the file for staff and records the event', function (string $role): void {
    Event::fake([AnalyticalReportDownloaded::class]);
    $user = User::factory()->{$role}()->create();
    actingAs($user);

    Livewire::test(ViewAnalyticalReport::class, ['record' => $this->report->getKey()])
        ->callAction(TestAction::make('downloadAnalyticalReport'))
        ->assertFileDownloaded($this->report->filename);

    Event::assertDispatched(AnalyticalReportDownloaded::class, fn (AnalyticalReportDownloaded $event): bool => $event->report->is($this->report) && $event->user->is($user));
})->with(['operador', 'adm']);

it('refuses clients and reports that are not generated', function (): void {
    $cliente = User::factory()->cliente()->withBranches(1)->create();
    $operador = User::factory()->operador()->create();
    $service = app(AnalyticalReportService::class);

    expect(fn () => $service->download($this->report, $cliente))
        ->toThrow(AnalyticalReportException::class, AnalyticalReportException::unauthorized()->getMessage())
        ->and(fn () => $service->download(AnalyticalReport::factory()->failed()->create(), $operador))
        ->toThrow(AnalyticalReportException::class, AnalyticalReportException::notDownloadable()->getMessage())
        ->and(fn () => $service->download(AnalyticalReport::factory()->queued()->create(), $operador))
        ->toThrow(AnalyticalReportException::class, AnalyticalReportException::notDownloadable()->getMessage());
});

it('reports a missing file', function (): void {
    Event::fake([AnalyticalReportDownloaded::class]);
    Storage::disk('local')->delete($this->report->path);

    expect(fn () => app(AnalyticalReportService::class)->download($this->report, User::factory()->operador()->create()))
        ->toThrow(AnalyticalReportException::class, AnalyticalReportException::fileMissing()->getMessage());
    Event::assertNotDispatched(AnalyticalReportDownloaded::class);
});

it('tells the user when the file is gone instead of downloading', function (): void {
    actingAs(User::factory()->operador()->create());
    Storage::disk('local')->delete($this->report->path);

    Livewire::test(ViewAnalyticalReport::class, ['record' => $this->report->getKey()])
        ->callAction(TestAction::make('downloadAnalyticalReport'))
        ->assertNotified(__('analytical_reports.errors.file_missing'))
        ->assertNoFileDownloaded();
});
