<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Actions\PaymentRequest\ExtractBoletoDataAction;
use App\Enums\PaymentMethod;
use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class ExtractBoletoOcrAction
{
    public static function make(): Action
    {
        return Action::make('extractBoletoOcr')
            ->label(__('payment_requests.actions.extract_ocr'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(function (?Attachment $record): bool {
                if ($record === null || ! $record->isPdf()) {
                    return false;
                }

                $user = Filament::auth()->user();
                $attachable = $record->attachable;

                return $user !== null
                    && $attachable instanceof PaymentRequest
                    && $attachable->payment_method === PaymentMethod::Boleto
                    && (bool) config('rjet.ocr.enabled')
                    && $user->can('update', $attachable);
            })
            ->action(function (Attachment $record): void {
                $attachable = $record->attachable;

                abort_unless(
                    $attachable instanceof PaymentRequest
                    && (Filament::auth()->user()?->can('update', $attachable) ?? false),
                    403,
                );

                $result = app(ExtractBoletoDataAction::class)->fromAttachment($record);

                if (! $result->wasSuccessful) {
                    Notification::make()
                        ->title(__('payment_requests.messages.ocr_failed'))
                        ->body($result->message)
                        ->warning()
                        ->send();

                    return;
                }

                /** @var PaymentRequest $paymentRequest */
                $paymentRequest = $record->attachable;

                app(PaymentRequestService::class)->applyOcrResult($paymentRequest, $result);

                Notification::make()
                    ->title(__('payment_requests.messages.ocr_succeeded'))
                    ->success()
                    ->send();
            });
    }
}
