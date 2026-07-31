<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentRequests\Actions\DeletePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\TransitionStatusAction;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Services\PaymentRequestService;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

final class EditPaymentRequest extends EditRecord
{
    protected static string $resource = PaymentRequestResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            TransitionStatusAction::make(),
            DeletePaymentRequestAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['attachment_files'], $data['attachment_file_names'], $data['status']);

        $data['net_amount'] = app(PaymentRequestService::class)->calculateNetAmount(
            (string) $data['gross_amount'],
            (string) ($data['discount_amount'] ?? '0'),
        );

        return $data;
    }

    protected function beforeSave(): void
    {
        try {
            $service = app(PaymentRequestService::class);
            $state = $this->form->getRawState();
            $method = PaymentMethod::from((string) $state['payment_method']);

            $service->assertEditable($this->record, Filament::auth()->user());
            $service->assertAmounts((string) $state['gross_amount'], (string) ($state['discount_amount'] ?? '0'));
            $service->assertAppropriation($this->record->branch_id, $state['appropriation_id'] ?? null);
            $service->assertBankDetails($method, (array) ($state['bankDetails'] ?? []));
            $service->assertBoletoHasAttachment($method, $this->record->attachments()->count());
        } catch (BusinessException $exception) {
            Notification::make()
                ->title($exception->getUserMessage())
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }
}
