<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Settlement\ConfirmPaymentSettlementAction as ConfirmPaymentSettlement;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Models\PaymentSettlement;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class ConfirmPaymentSettlementAction
{
    public static function make(): Action
    {
        return Action::make('confirmSettlement')
            ->label(__('payment_settlements.actions.confirm'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (?PaymentSettlement $record): bool => $record !== null
                && $record->isDraft()
                && ! $record->isSettlementDateFuture()
                && ! $record->hasActiveCnabGeneration()
                && (Filament::auth()->user()?->can('confirm', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('payment_settlements.actions.confirm'))
            ->modalDescription(fn (PaymentSettlement $record): string => $record->hasGeneratedCnabFile()
                ? __('payment_settlements.messages.confirm_description')
                : __('payment_settlements.messages.confirm_without_cnab'))
            ->action(function (PaymentSettlement $record, Action $action): void {
                $user = Filament::auth()->user();

                abort_unless($user?->can('confirm', $record) ?? false, 403);

                try {
                    app(ConfirmPaymentSettlement::class)($record, $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('payment_settlements.messages.confirmed'))
                    ->success()
                    ->send();

                $action->redirect(PaymentSettlementResource::getUrl('view', ['record' => $record]));
            });
    }
}
