<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs\Pages;

use App\Filament\Resources\CnabConfigs\CnabConfigResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCnabConfig extends ViewRecord
{
    protected static string $resource = CnabConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
