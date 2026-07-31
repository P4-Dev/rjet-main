<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Enums\AttachmentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Services\AttachmentService;
use App\Services\PaymentRequestService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

final class CreatePaymentRequest extends CreateRecord
{
    protected static string $resource = PaymentRequestResource::class;

    /**
     * Wrap create + relationship save + afterCreate so attachment failures
     * roll back the payment request and bank details.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * @var list<string>
     */
    protected array $uploadedAttachmentPaths = [];

    /**
     * @var array<string, string>
     */
    protected array $uploadedAttachmentNames = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->uploadedAttachmentPaths = array_values((array) ($data['attachment_files'] ?? []));
        $this->uploadedAttachmentNames = (array) ($data['attachment_file_names'] ?? []);

        unset($data['attachment_files'], $data['attachment_file_names']);

        $data['branch_id'] = app(PaymentRequestService::class)
            ->resolveBranchFor(Filament::auth()->user(), $data['branch_id'] ?? null);

        $data['status'] = PaymentRequestStatus::Requested->value;
        $data['net_amount'] = app(PaymentRequestService::class)->calculateNetAmount(
            (string) $data['gross_amount'],
            (string) ($data['discount_amount'] ?? '0'),
        );

        return $data;
    }

    protected function beforeCreate(): void
    {
        try {
            $service = app(PaymentRequestService::class);
            $state = $this->form->getRawState();
            $method = PaymentMethod::from((string) $state['payment_method']);

            $service->assertAmounts((string) $state['gross_amount'], (string) ($state['discount_amount'] ?? '0'));
            $service->assertAppropriation($state['branch_id'] ?? null, $state['appropriation_id'] ?? null);
            $service->assertBankDetails($method, (array) ($state['bankDetails'] ?? []));
            $service->assertBoletoHasAttachment($method, count((array) ($state['attachment_files'] ?? [])));
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

    protected function afterCreate(): void
    {
        try {
            app(AttachmentService::class)->storeManyFromPaths(
                $this->record,
                $this->uploadedAttachmentPaths,
                $this->uploadedAttachmentNames,
                $this->record->payment_method === PaymentMethod::Boleto
                    ? AttachmentType::Boleto
                    : AttachmentType::Other,
            );

            app(PaymentRequestService::class)->recordInitialStatus(
                $this->record,
                Filament::auth()->user(),
            );
        } catch (BusinessException $exception) {
            Notification::make()
                ->title($exception->getUserMessage())
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
