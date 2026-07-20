<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appropriations\Pages;

use App\Filament\Resources\Appropriations\AppropriationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAppropriations extends ListRecords
{
    protected static string $resource = AppropriationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
