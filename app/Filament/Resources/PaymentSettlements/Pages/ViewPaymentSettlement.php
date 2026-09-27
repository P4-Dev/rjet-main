<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Pages;

use App\Filament\Resources\PaymentSettlements\Actions\CancelPaymentSettlementAction;
use App\Filament\Resources\PaymentSettlements\Actions\ConfirmPaymentSettlementAction;
use App\Filament\Resources\PaymentSettlements\Actions\DownloadCnabFileAction;
use App\Filament\Resources\PaymentSettlements\Actions\GenerateCnabFileAction;
use App\Filament\Resources\PaymentSettlements\Actions\RetryCnabFileGenerationAction;
use App\Filament\Resources\PaymentSettlements\Actions\ValidateCnabRemittanceAction;
use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewPaymentSettlement extends ViewRecord
{
    protected static string $resource = PaymentSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ValidateCnabRemittanceAction::make(),
            GenerateCnabFileAction::make(),
            DownloadCnabFileAction::make(),
            RetryCnabFileGenerationAction::make(),
            ConfirmPaymentSettlementAction::make(),
            CancelPaymentSettlementAction::make(),
        ];
    }
}
