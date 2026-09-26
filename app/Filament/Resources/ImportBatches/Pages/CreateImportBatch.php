<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Actions\Import\StartImportBatchAction;
use App\Exceptions\BusinessException;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\ImportTemplateVersion;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class CreateImportBatch extends CreateRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $version = ImportTemplateVersion::query()->findOrFail($data['import_template_version_id']);
        $file = $data['spreadsheet'] ?? null;

        if (is_array($file)) {
            $file = reset($file) ?: null;
        }

        if (! $file instanceof UploadedFile && ! $file instanceof TemporaryUploadedFile) {
            Notification::make()
                ->title(__('import_batches.errors.unreadable'))
                ->danger()
                ->send();

            $this->halt();

            throw new \RuntimeException('Missing spreadsheet upload.');
        }

        try {
            $batch = app(StartImportBatchAction::class)(
                $file,
                $version,
                Filament::auth()->user(),
            );

            Notification::make()
                ->title(__('import_batches.messages.started'))
                ->success()
                ->send();

            return $batch;
        } catch (BusinessException $e) {
            Notification::make()
                ->title($e->getUserMessage())
                ->danger()
                ->send();

            $this->halt();

            throw $e;
        }
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
