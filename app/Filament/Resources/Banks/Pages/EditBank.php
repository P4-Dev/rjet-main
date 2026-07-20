<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Resources\Banks\Actions\DeleteBankAction;
use App\Filament\Resources\Banks\BankResource;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditBank extends EditRecord
{
    protected static string $resource = BankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteBankAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
