<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Settlement\CancelPaymentSettlementAction as CancelPaymentSettlement;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Models\PaymentSettlement;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class CancelPaymentSettlementAction
{
    public static function make(): Action
    {
        return Action::make('cancelSettlement')
            ->label(__('payment_settlements.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (?PaymentSettlement $record): bool => $record !== null
                && $record->isDraft()
                && ! $record->hasActiveCnabGeneration()
                && (Filament::auth()->user()?->can('cancel', $record) ?? false))
            ->modalHeading(__('payment_settlements.actions.cancel'))
            ->modalDescription(fn (PaymentSettlement $record): ?string => $record->hasGeneratedCnabFile()
                ? __('payment_settlements.messages.cancel_with_generated_file')
                : null)
            ->schema([
                Textarea::make('reason')
                    ->label(__('payment_settlements.fields.cancellation_reason'))
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (PaymentSettlement $record, array $data, Action $action): void {
                $user = Filament::auth()->user();

                abort_unless($user?->can('cancel', $record) ?? false, 403);

                try {
                    app(CancelPaymentSettlement::class)($record, $user, (string) $data['reason']);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('payment_settlements.messages.cancelled'))
                    ->success()
                    ->send();

                $action->redirect(PaymentSettlementResource::getUrl('view', ['record' => $record]));
            });
    }
}
