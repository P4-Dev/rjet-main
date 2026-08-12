<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Exceptions\BusinessException;
use App\Models\PaymentRequest;
use App\Services\ApprovalService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RejectPaymentRequestAction
{
    public static function make(): Action
    {
        return Action::make('rejectPaymentRequest')
            ->label(__('payment_requests.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('payment_requests.actions.reject'))
            ->visible(function (?PaymentRequest $record): bool {
                if ($record === null) {
                    return false;
                }

                return $record->currentPendingApproval() !== null
                    && (Filament::auth()->user()?->can('reject', $record) ?? false);
            })
            ->schema([
                Textarea::make('reason')
                    ->label(__('approvals.fields.reason'))
                    ->required()
                    ->minLength(5)
                    ->rows(3),
            ])
            ->action(function (PaymentRequest $record, array $data, Action $action): void {
                $user = Filament::auth()->user();
                $pending = $record->currentPendingApproval();

                abort_unless(
                    $user !== null && $pending !== null && $user->can('reject', $record),
                    403,
                );

                try {
                    app(ApprovalService::class)->reject($pending, $user, (string) $data['reason']);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(__('payment_requests.messages.rejected'))
                    ->success()
                    ->send();
            });
    }
}
