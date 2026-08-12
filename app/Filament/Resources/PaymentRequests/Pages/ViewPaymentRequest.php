<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Filament\Resources\PaymentRequests\Actions\ApprovePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\DeletePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\RejectPaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\ResubmitForApprovalAction;
use App\Filament\Resources\PaymentRequests\Actions\SendForApprovalAction;
use App\Filament\Resources\PaymentRequests\Actions\TransitionStatusAction;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewPaymentRequest extends ViewRecord
{
    protected static string $resource = PaymentRequestResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load([
            'attachments',
            'statusHistories.changedBy',
            'approvals.approver',
            'approvals.decidedBy',
            'creator',
            'editor',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ApprovePaymentRequestAction::make(),
            RejectPaymentRequestAction::make(),
            ResubmitForApprovalAction::make(),
            SendForApprovalAction::make(),
            TransitionStatusAction::make(),
            DeletePaymentRequestAction::make(),
        ];
    }
}
