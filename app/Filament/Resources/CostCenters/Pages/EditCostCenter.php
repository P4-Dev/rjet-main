<?php

declare(strict_types=1);

namespace App\Filament\Resources\CostCenters\Pages;

use App\Filament\Resources\CostCenters\CostCenterResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCostCenter extends EditRecord
{
    protected static string $resource = CostCenterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
