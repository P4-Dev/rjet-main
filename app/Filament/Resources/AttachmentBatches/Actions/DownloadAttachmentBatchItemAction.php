<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Actions;

use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Throwable;

final class DownloadAttachmentBatchItemAction
{
    public static function make(): Action
    {
        return Action::make('download')
            ->label(__('attachment_batches.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (?AttachmentBatchItem $record, Component $livewire): bool => $record !== null
                && self::canDownload($record, $livewire))
            ->action(function (AttachmentBatchItem $record, Action $action, Component $livewire): mixed {
                abort_unless(self::canDownload($record, $livewire), 403);

                $attachment = $record->attachment;

                try {
                    return Storage::disk($attachment->disk)->download($attachment->path, $attachment->displayName());
                } catch (Throwable) {
                    Notification::make()
                        ->title(__('attachments.errors.file_not_found'))
                        ->danger()
                        ->send();

                    $action->halt();

                    return null;
                }
            });
    }

    /**
     * Attachments still bound to the owner batch are authorized by the already-loaded batch (trashed included),
     * avoiding one batch lookup per row. Rebound attachments go through AttachmentPolicy.
     */
    private static function canDownload(AttachmentBatchItem $record, Component $livewire): bool
    {
        $user = Filament::auth()->user();
        $attachment = $record->attachment;

        if ($user === null || $attachment === null) {
            return false;
        }

        $ownerBatch = $livewire instanceof RelationManager ? $livewire->getOwnerRecord() : null;

        if (
            $ownerBatch instanceof AttachmentBatch
            && $record->attachment_batch_id === $ownerBatch->getKey()
            && $attachment->attachable_type === $ownerBatch->getMorphClass()
            && $attachment->attachable_id === $ownerBatch->getKey()
        ) {
            return $user->can('view', $ownerBatch);
        }

        return $user->can('view', $attachment);
    }
}
