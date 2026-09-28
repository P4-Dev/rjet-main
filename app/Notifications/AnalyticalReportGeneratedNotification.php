<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\AnalyticalReports\AnalyticalReportResource;
use App\Models\AnalyticalReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AnalyticalReportGeneratedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AnalyticalReport $report,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.analytical_report_generated.title'))
            ->line($this->body())
            ->action(__('notifications.view_analytical_report'), $this->reportUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'analytical_report_id' => $this->report->getKey(),
            'title' => __('notifications.analytical_report_generated.title'),
            'body' => $this->body(),
            'url' => $this->reportUrl(),
        ];
    }

    private function body(): string
    {
        return __('notifications.analytical_report_generated.body', [
            'from' => $this->report->period_start->format('d/m/Y'),
            'until' => $this->report->period_end->format('d/m/Y'),
            'basis' => $this->report->date_basis->getLabel(),
            'count' => $this->report->rows_count,
        ]);
    }

    private function reportUrl(): string
    {
        return AnalyticalReportResource::getUrl('view', ['record' => $this->report]);
    }
}
