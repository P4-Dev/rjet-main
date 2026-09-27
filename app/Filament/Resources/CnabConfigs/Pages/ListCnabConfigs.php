<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs\Pages;

use App\Filament\Resources\CnabConfigs\CnabConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCnabConfigs extends ListRecords
{
    protected static string $resource = CnabConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
