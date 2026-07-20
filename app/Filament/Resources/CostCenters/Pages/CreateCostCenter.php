<?php

declare(strict_types=1);

namespace App\Filament\Resources\CostCenters\Pages;

use App\Filament\Resources\CostCenters\CostCenterResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCostCenter extends CreateRecord
{
    protected static string $resource = CostCenterResource::class;
}
