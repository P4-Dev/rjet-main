<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appropriations\Pages;

use App\Filament\Resources\Appropriations\AppropriationResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateAppropriation extends CreateRecord
{
    protected static string $resource = AppropriationResource::class;
}
