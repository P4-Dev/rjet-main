<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Pages;

use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentSettlements extends ListRecords
{
    protected static string $resource = PaymentSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('payment_settlements.actions.create'))
                ->url(PaymentSettlementResource::getUrl('create')),
        ];
    }
}
