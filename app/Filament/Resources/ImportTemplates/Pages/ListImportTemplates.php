<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\Pages;

use App\Filament\Resources\ImportTemplates\ImportTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListImportTemplates extends ListRecords
{
    protected static string $resource = ImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
