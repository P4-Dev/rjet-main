<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApprovalReassignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Approval $approval) {}

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
            ->subject(__('notifications.approval_reassigned.subject'))
            ->line(__('notifications.approval_reassigned.body'))
            ->action(
                __('notifications.view_payment_request'),
                $this->paymentRequestUrl(),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'approval_id' => $this->approval->getKey(),
            'payment_request_id' => $this->approval->payment_request_id,
            'title' => __('notifications.approval_reassigned.subject'),
            'body' => __('notifications.approval_reassigned.body'),
            'url' => $this->paymentRequestUrl(),
        ];
    }

    private function paymentRequestUrl(): string
    {
        return PaymentRequestResource::getUrl('view', [
            'record' => $this->approval->payment_request_id,
        ]);
    }
}
