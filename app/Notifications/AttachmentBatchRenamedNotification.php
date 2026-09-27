<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use App\Models\AttachmentBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AttachmentBatchRenamedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AttachmentBatch $batch,
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
            ->subject(__('notifications.attachment_batch_renamed.subject'))
            ->line(__('notifications.attachment_batch_renamed.body', [
                'count' => $this->batch->renamed_count,
            ]))
            ->action(
                __('notifications.view_attachment_batch'),
                $this->batchUrl(),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'attachment_batch_id' => $this->batch->getKey(),
            'renamed_count' => $this->batch->renamed_count,
            'title' => __('notifications.attachment_batch_renamed.subject'),
            'body' => __('notifications.attachment_batch_renamed.body', [
                'count' => $this->batch->renamed_count,
            ]),
            'url' => $this->batchUrl(),
        ];
    }

    private function batchUrl(): string
    {
        return AttachmentBatchResource::getUrl('view', [
            'record' => $this->batch->getKey(),
        ]);
    }
}
