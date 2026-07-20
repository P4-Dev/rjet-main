<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appropriations\Pages;

use App\Filament\Resources\Appropriations\AppropriationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewAppropriation extends ViewRecord
{
    protected static string $resource = AppropriationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
