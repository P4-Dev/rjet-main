<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Resources\Banks\BankResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewBank extends ViewRecord
{
    protected static string $resource = BankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
