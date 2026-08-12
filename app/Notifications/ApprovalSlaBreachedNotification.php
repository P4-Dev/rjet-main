<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class ApprovalSlaBreachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Approval $approval) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'approval_id' => $this->approval->getKey(),
            'payment_request_id' => $this->approval->payment_request_id,
            'title' => __('notifications.approval_sla_breached.title'),
            'body' => __('notifications.approval_sla_breached.body'),
            'url' => PaymentRequestResource::getUrl('view', [
                'record' => $this->approval->payment_request_id,
            ]),
        ];
    }
}
