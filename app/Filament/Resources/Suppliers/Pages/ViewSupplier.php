<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

final class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    public function getRecord(): Model
    {
        return tap(parent::getRecord(), function (Model $record): void {
            // Livewire rehydrates the model by key only; the Infolist stays mounted
            // alongside relation manager tabs and needs nested company on overrides.
            $record->loadMissing(['companyPaymentMethods.company', 'bankDetails.bank']);
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
