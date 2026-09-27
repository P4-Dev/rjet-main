<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Settlement\ReleasePaymentSettlementItemAction as ReleasePaymentSettlementItem;
use App\Enums\CnabFileStatus;
use App\Exceptions\BusinessException;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;

final class ReleasePaymentSettlementItemAction
{
    public static function make(): Action
    {
        return Action::make('releaseItem')
            ->label(__('payment_settlements.actions.release_item'))
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('danger')
            ->visible(function (PaymentSettlementItem $record, RelationManager $livewire): bool {
                /** @var PaymentSettlement $settlement */
                $settlement = $livewire->getOwnerRecord();

                return $record->isActive()
                    && $settlement->isDraft()
                    && ! $settlement->cnabFiles()->whereIn('status', CnabFileStatus::activeValues())->exists()
                    && (Filament::auth()->user()?->can('update', $settlement) ?? false);
            })
            ->requiresConfirmation()
            ->modalHeading(__('payment_settlements.actions.release_item'))
            ->action(function (PaymentSettlementItem $record, RelationManager $livewire, Action $action): void {
                $user = Filament::auth()->user();
                /** @var PaymentSettlement $settlement */
                $settlement = $livewire->getOwnerRecord();

                abort_unless($user?->can('update', $settlement) ?? false, 403);

                try {
                    app(ReleasePaymentSettlementItem::class)($record->setRelation('settlement', $settlement), $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('payment_settlements.messages.item_released'))
                    ->success()
                    ->send();
            });
    }
}
