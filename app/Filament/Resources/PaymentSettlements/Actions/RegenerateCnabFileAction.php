<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Cnab\RegenerateCnabFileAction as RegenerateCnabFile;
use App\Enums\CnabFileStatus;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Models\CnabFile;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RegenerateCnabFileAction
{
    public static function make(): Action
    {
        return Action::make('regenerateCnab')
            ->label(__('cnab_files.actions.regenerate'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('danger')
            ->visible(fn (CnabFile $record): bool => $record->status === CnabFileStatus::Generated
                && (Filament::auth()->user()?->can('regenerate', $record) ?? false))
            ->modalHeading(__('cnab_files.actions.regenerate'))
            ->modalDescription(__('cnab_files.messages.regenerate_warning'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('cnab_files.fields.supersede_reason'))
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (CnabFile $record, array $data, Action $action): void {
                $user = Filament::auth()->user();

                abort_unless($user?->can('regenerate', $record) ?? false, 403);

                try {
                    app(RegenerateCnabFile::class)($record, $user, (string) $data['reason']);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('cnab_files.messages.regenerate_queued'))
                    ->success()
                    ->send();

                $action->redirect(PaymentSettlementResource::getUrl('view', ['record' => $record->payment_settlement_id]));
            });
    }
}
