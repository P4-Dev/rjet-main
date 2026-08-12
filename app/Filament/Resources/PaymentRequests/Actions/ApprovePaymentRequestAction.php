<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Exceptions\BusinessException;
use App\Models\PaymentRequest;
use App\Services\ApprovalService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class ApprovePaymentRequestAction
{
    public static function make(): Action
    {
        return Action::make('approvePaymentRequest')
            ->label(__('payment_requests.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('payment_requests.actions.approve'))
            ->modalDescription(__('payment_requests.messages.confirm_approve'))
            ->visible(function (?PaymentRequest $record): bool {
                if ($record === null) {
                    return false;
                }

                return $record->currentPendingApproval() !== null
                    && (Filament::auth()->user()?->can('approve', $record) ?? false);
            })
            ->action(function (PaymentRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                $pending = $record->currentPendingApproval();

                abort_unless(
                    $user !== null && $pending !== null && $user->can('approve', $record),
                    403,
                );

                try {
                    app(ApprovalService::class)->approve($pending, $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(__('payment_requests.messages.approved'))
                    ->success()
                    ->send();
            });
    }
}
