<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Actions;

use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use App\Models\AttachmentBatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;

final class ClassifyAttachmentBatchAction
{
    public static function make(): Action
    {
        return Action::make('classify')
            ->label(__('attachment_batches.actions.classify'))
            ->icon(Heroicon::OutlinedTag)
            ->color('primary')
            ->visible(fn (?AttachmentBatch $record): bool => $record !== null
                && ! $record->trashed()
                && $record->isPendingClassification()
                && (Filament::auth()->user()?->can('classify', $record) ?? false))
            ->url(fn (AttachmentBatch $record): string => AttachmentBatchResource::getUrl('classify', ['record' => $record]));
    }
}
