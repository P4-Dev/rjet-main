<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Pages;

use App\Actions\Attachment\CreateAttachmentBatchAction;
use App\Exceptions\BusinessException;
use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateAttachmentBatch extends CreateRecord
{
    protected static string $resource = AttachmentBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $files = $data['files'] ?? [];

        try {
            $batch = app(CreateAttachmentBatchAction::class)(
                is_array($files) ? $files : [$files],
                Filament::auth()->user(),
            );
        } catch (BusinessException $e) {
            Notification::make()
                ->title($e->getUserMessage())
                ->danger()
                ->send();

            $this->halt();

            throw $e;
        }

        Notification::make()
            ->title(__('attachment_batches.messages.created'))
            ->success()
            ->send();

        return $batch;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
