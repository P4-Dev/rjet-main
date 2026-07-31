<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentRequests\Actions\DeletePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\ForceDeletePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\RestorePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\TransitionStatusAction;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Services\PaymentRequestService;
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
            RestorePaymentRequestAction::make(),
            ForceDeletePaymentRequestAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['attachment_files'], $data['attachment_file_names'], $data['status']);

        $user = Filament::auth()->user();

        // Disabled branch fields remain dehydrated; never trust client-supplied
        // branch_id for users scoped to linked branches.
        if ($user === null || ! $user->role->seesAllBranches()) {
            unset($data['branch_id']);
        } else {
            $data['branch_id'] = app(PaymentRequestService::class)
                ->resolveBranchFor($user, isset($data['branch_id']) ? (string) $data['branch_id'] : null);
        }

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

            $branchId = filled($state['branch_id'] ?? null)
                ? (string) $state['branch_id']
                : $this->record->branch_id;

            $service->assertCostCenterForBranch($branchId, $state['cost_center_id'] ?? null);
            $service->assertAppropriation($branchId, $state['appropriation_id'] ?? null);
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
