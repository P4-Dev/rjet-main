<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Models\CnabFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CnabFileGenerationFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly CnabFile $file,
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
            ->subject(__('notifications.cnab_file_generation_failed.title'))
            ->line($this->body())
            ->action(__('notifications.view_payment_settlement'), $this->settlementUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'cnab_file_id' => $this->file->getKey(),
            'payment_settlement_id' => $this->file->payment_settlement_id,
            'title' => __('notifications.cnab_file_generation_failed.title'),
            'body' => $this->body(),
            'url' => $this->settlementUrl(),
        ];
    }

    private function body(): string
    {
        return __('notifications.cnab_file_generation_failed.body', [
            'reason' => (string) $this->file->failure_reason,
        ]);
    }

    private function settlementUrl(): string
    {
        return PaymentSettlementResource::getUrl('view', [
            'record' => $this->file->payment_settlement_id,
        ]);
    }
}
