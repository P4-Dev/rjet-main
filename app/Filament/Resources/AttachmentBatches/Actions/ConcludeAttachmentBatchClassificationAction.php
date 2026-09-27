<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Actions;

use App\Actions\Attachment\ConcludeAttachmentBatchClassificationAction as ConcludeClassification;
use App\Exceptions\BusinessException;
use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use App\Models\AttachmentBatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class ConcludeAttachmentBatchClassificationAction
{
    public static function make(): Action
    {
        return Action::make('concludeClassification')
            ->label(__('attachment_batches.actions.conclude_classification'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('attachment_batches.actions.conclude_classification'))
            ->modalDescription(__('attachment_batches.hints.conclude'))
            ->visible(fn (?AttachmentBatch $record): bool => $record !== null
                && ! $record->trashed()
                && $record->isPendingClassification()
                && (Filament::auth()->user()?->can('classify', $record) ?? false))
            ->action(function (AttachmentBatch $record, Action $action): void {
                try {
                    app(ConcludeClassification::class)($record, Filament::auth()->user());
                } catch (BusinessException $e) {
                    Notification::make()
                        ->title($e->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('attachment_batches.messages.classification_concluded'))
                    ->success()
                    ->send();

                $action->redirect(AttachmentBatchResource::getUrl('view', ['record' => $record]));
            });
    }
}
