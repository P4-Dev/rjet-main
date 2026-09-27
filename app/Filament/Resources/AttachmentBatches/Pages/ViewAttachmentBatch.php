<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Pages;

use App\Filament\Resources\AttachmentBatches\Actions\ClassifyAttachmentBatchAction;
use App\Filament\Resources\AttachmentBatches\Actions\ConcludeAttachmentBatchClassificationAction;
use App\Filament\Resources\AttachmentBatches\Actions\RetryFailedAttachmentBatchRenamesAction;
use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;

final class ViewAttachmentBatch extends ViewRecord
{
    protected static string $resource = AttachmentBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ClassifyAttachmentBatchAction::make(),
            ConcludeAttachmentBatchClassificationAction::make(),
            RetryFailedAttachmentBatchRenamesAction::make(),
            DeleteAction::make()
                ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
        ];
    }
}
