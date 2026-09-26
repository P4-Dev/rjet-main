<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\ImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentRequestBatchImportedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ImportBatch $batch,
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
            ->subject(__('notifications.payment_request_batch_imported.subject'))
            ->line(__('notifications.payment_request_batch_imported.body', [
                'success' => $this->batch->success_count,
                'errors' => $this->batch->error_count,
            ]))
            ->action(
                __('notifications.view_import_batch'),
                $this->batchUrl(),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'import_batch_id' => $this->batch->getKey(),
            'success_count' => $this->batch->success_count,
            'error_count' => $this->batch->error_count,
            'title' => __('notifications.payment_request_batch_imported.subject'),
            'body' => __('notifications.payment_request_batch_imported.body', [
                'success' => $this->batch->success_count,
                'errors' => $this->batch->error_count,
            ]),
            'url' => $this->batchUrl(),
        ];
    }

    private function batchUrl(): string
    {
        return ImportBatchResource::getUrl('view', [
            'record' => $this->batch->getKey(),
        ]);
    }
}
