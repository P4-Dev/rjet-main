<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Actions;

use App\Actions\Cnab\RetryCnabFileGenerationAction as RetryCnabFileGeneration;
use App\Enums\CnabFileStatus;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Models\CnabFile;
use App\Models\PaymentSettlement;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * On the settlement view it targets the latest file; on the files relation manager, the row.
 */
final class RetryCnabFileGenerationAction
{
    public static function make(): Action
    {
        return Action::make('retryCnab')
            ->label(__('cnab_files.actions.retry'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(function (?Model $record): bool {
                $file = self::resolveFile($record);

                return $file !== null
                    && $file->status === CnabFileStatus::Failed
                    && ! CnabFile::query()
                        ->where('payment_settlement_id', $file->payment_settlement_id)
                        ->whereIn('status', CnabFileStatus::activeValues())
                        ->exists()
                    && (Filament::auth()->user()?->can('retry', $file) ?? false);
            })
            ->requiresConfirmation()
            ->modalHeading(__('cnab_files.actions.retry'))
            ->action(function (?Model $record, Action $action): void {
                $user = Filament::auth()->user();
                $file = self::resolveFile($record);

                abort_unless($file !== null && ($user?->can('retry', $file) ?? false), 403);

                try {
                    app(RetryCnabFileGeneration::class)($file, $user);
                } catch (BusinessException $exception) {
                    Notification::make()
                        ->title($exception->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('cnab_files.messages.retry_queued'))
                    ->success()
                    ->send();

                $action->redirect(PaymentSettlementResource::getUrl('view', ['record' => $file->payment_settlement_id]));
            });
    }

    private static function resolveFile(?Model $record): ?CnabFile
    {
        return match (true) {
            $record instanceof CnabFile => $record,
            $record instanceof PaymentSettlement => $record->latestCnabFile,
            default => null,
        };
    }
}
