<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;
}
