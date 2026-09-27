<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Cnab\RequestCnabFileGenerationAction;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Models\PaymentSettlement;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class GenerateCnabFileAction
{
    public static function make(): Action
    {
        return Action::make('generateCnab')
            ->label(__('payment_settlements.actions.generate_cnab'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('primary')
            ->visible(fn (?PaymentSettlement $record): bool => $record !== null
                && $record->isDraft()
                && ($record->cnabConfig()?->is_active ?? false)
                && $record->currentCnabFile === null
                && ! $record->isSettlementDatePast()
                && (Filament::auth()->user()?->can('generateCnab', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('payment_settlements.actions.generate_cnab'))
            ->action(function (PaymentSettlement $record, Action $action): void {
                $user = Filament::auth()->user();

                abort_unless($user?->can('generateCnab', $record) ?? false, 403);

                try {
                    app(RequestCnabFileGenerationAction::class)($record, $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('cnab_files.messages.queued'))
                    ->success()
                    ->send();

                $action->redirect(PaymentSettlementResource::getUrl('view', ['record' => $record]));
            });
    }
}
