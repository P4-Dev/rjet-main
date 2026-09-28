<?php

declare(strict_types=1);

use App\Enums\ReportDateBasis;
use App\Events\Report\AnalyticalReportGenerated;
use App\Events\Report\AnalyticalReportGenerationFailed;
use App\Filament\Resources\AnalyticalReports\AnalyticalReportResource;
use App\Listeners\Report\NotifyAnalyticalReportGenerated;
use App\Listeners\Report\NotifyAnalyticalReportGenerationFailed;
use App\Models\AnalyticalReport;
use App\Models\User;
use App\Notifications\AnalyticalReportGeneratedNotification;
use App\Notifications\AnalyticalReportGenerationFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->creator = User::factory()->operador()->create();
});

it('notifies the creator by mail and database with the report view url and no attachment', function (): void {
    $report = AnalyticalReport::factory()->generated()->create(['created_by' => $this->creator->getKey()]);
    $url = AnalyticalReportResource::getUrl('view', ['record' => $report]);

    event(new AnalyticalReportGenerated($report));

    Notification::assertSentTo($this->creator, AnalyticalReportGeneratedNotification::class, function (AnalyticalReportGeneratedNotification $notification) use ($url): bool {
        $mail = $notification->toMail($this->creator);

        return $notification->via($this->creator) === ['mail', 'database']
            && $notification->toArray($this->creator)['url'] === $url
            && $mail->actionUrl === $url
            && $mail->attachments === []
            && $mail->rawAttachments === [];
    });
    expect(new NotifyAnalyticalReportGenerated)->toBeInstanceOf(ShouldQueue::class)
        ->and(new NotifyAnalyticalReportGenerationFailed)->toBeInstanceOf(ShouldQueue::class);
});

it('notifies the creator about failures with the reason', function (): void {
    $report = AnalyticalReport::factory()->failed('Motivo X.')->create(['created_by' => $this->creator->getKey()]);

    event(new AnalyticalReportGenerationFailed($report));

    Notification::assertSentTo(
        $this->creator,
        AnalyticalReportGenerationFailedNotification::class,
        fn (AnalyticalReportGenerationFailedNotification $notification): bool => str_contains($notification->toArray($this->creator)['body'], 'Motivo X.'),
    );
});

it('skips inactive or missing creators', function (): void {
    $inactive = User::factory()->operador()->inactive()->create();

    event(new AnalyticalReportGenerated(AnalyticalReport::factory()->generated()->create(['created_by' => $inactive->getKey()])));
    event(new AnalyticalReportGenerationFailed(AnalyticalReport::factory()->failed()->create(['created_by' => null, 'updated_by' => null])));

    Notification::assertNothingSent();
});

it('describes the period, date basis and row count in the generated notification', function (): void {
    $report = AnalyticalReport::factory()->generated()->basis(ReportDateBasis::SettlementDate)->create([
        'created_by' => $this->creator->getKey(),
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'rows_count' => 42,
    ]);

    $payload = (new AnalyticalReportGeneratedNotification($report))->toArray($this->creator);

    expect($payload['analytical_report_id'])->toBe($report->getKey())
        ->and($payload['title'])->toBe(__('notifications.analytical_report_generated.title'))
        ->and($payload['body'])->toContain('01/08/2026')
        ->and($payload['body'])->toContain('31/08/2026')
        ->and($payload['body'])->toContain(ReportDateBasis::SettlementDate->getLabel())
        ->and($payload['body'])->toContain('42');
});

it('sends the failure notification by mail and database pointing to the report view', function (): void {
    $report = AnalyticalReport::factory()->failed('Motivo Y.')->create(['created_by' => $this->creator->getKey()]);
    $url = AnalyticalReportResource::getUrl('view', ['record' => $report]);
    $notification = new AnalyticalReportGenerationFailedNotification($report);

    expect($notification->via($this->creator))->toBe(['mail', 'database'])
        ->and($notification->toArray($this->creator)['url'])->toBe($url)
        ->and($notification->toMail($this->creator)->actionUrl)->toBe($url)
        ->and($notification->toMail($this->creator)->rawAttachments)->toBe([]);
});
