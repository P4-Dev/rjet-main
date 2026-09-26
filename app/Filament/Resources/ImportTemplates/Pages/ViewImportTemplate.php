<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\Pages;

use App\Filament\Resources\ImportTemplates\Actions\PublishImportTemplateVersionAction;
use App\Filament\Resources\ImportTemplates\ImportTemplateResource;
use App\Services\ImportTemplateService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewImportTemplate extends ViewRecord
{
    protected static string $resource = ImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            PublishImportTemplateVersionAction::make(),
            DeleteAction::make()
                ->using(function ($record): void {
                    app(ImportTemplateService::class)->delete($record);
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('import_templates.messages.deleted')),
                ),
        ];
    }
}
