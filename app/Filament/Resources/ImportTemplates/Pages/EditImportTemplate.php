<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\Pages;

use App\DTOs\ImportTemplateData;
use App\Exceptions\BusinessException;
use App\Filament\Resources\ImportTemplates\ImportTemplateResource;
use App\Services\ImportTemplateService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditImportTemplate extends EditRecord
{
    protected static string $resource = ImportTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['mappings']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(ImportTemplateService::class)->updateMetadata(
                $record,
                ImportTemplateData::fromArray([
                    ...$data,
                    'mappings' => [],
                ]),
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
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('import_templates.messages.updated');
    }
}
