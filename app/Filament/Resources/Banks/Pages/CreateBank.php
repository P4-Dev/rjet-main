<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Resources\Banks\BankResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBank extends CreateRecord
{
    protected static string $resource = BankResource::class;
}
