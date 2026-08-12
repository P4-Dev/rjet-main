<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\Approval;
use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaymentRequest $paymentRequest,
        public readonly Approval $approval,
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
            ->subject(__('notifications.payment_request_rejected.subject'))
            ->line(__('notifications.payment_request_rejected.body'))
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
            'payment_request_id' => $this->paymentRequest->getKey(),
            'title' => __('notifications.payment_request_rejected.subject'),
            'body' => __('notifications.payment_request_rejected.body'),
            'url' => $this->paymentRequestUrl(),
        ];
    }

    private function paymentRequestUrl(): string
    {
        return PaymentRequestResource::getUrl('view', [
            'record' => $this->paymentRequest->getKey(),
        ]);
    }
}
