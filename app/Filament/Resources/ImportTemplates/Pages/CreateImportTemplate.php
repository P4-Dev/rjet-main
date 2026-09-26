<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\Pages;

use App\DTOs\ImportTemplateData;
use App\Exceptions\BusinessException;
use App\Filament\Resources\ImportTemplates\ImportTemplateResource;
use App\Services\ImportTemplateService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateImportTemplate extends CreateRecord
{
    protected static string $resource = ImportTemplateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ImportTemplateService::class)->create(
                ImportTemplateData::fromArray($data),
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

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('import_templates.messages.created');
    }
}
