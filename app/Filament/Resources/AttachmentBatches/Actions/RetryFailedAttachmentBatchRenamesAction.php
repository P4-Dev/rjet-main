<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Actions;

use App\Actions\Attachment\RetryFailedAttachmentBatchRenamesAction as RetryFailedRenames;
use App\Exceptions\BusinessException;
use App\Models\AttachmentBatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RetryFailedAttachmentBatchRenamesAction
{
    public static function make(): Action
    {
        return Action::make('retryFailedRenames')
            ->label(__('attachment_batches.actions.retry_failed_renames'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (?AttachmentBatch $record): bool => $record !== null
                && ! $record->trashed()
                && $record->isRenameRetryable()
                && (Filament::auth()->user()?->can('classify', $record) ?? false))
            ->action(function (AttachmentBatch $record, Action $action): void {
                try {
                    app(RetryFailedRenames::class)($record, Filament::auth()->user());
                } catch (BusinessException $e) {
                    Notification::make()
                        ->title($e->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('attachment_batches.messages.retry_queued'))
                    ->success()
                    ->send();
            });
    }
}
