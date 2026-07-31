<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PaymentRequestBankDetailsData;
use App\DTOs\PaymentRequestData;
use App\Enums\AttachmentType;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Enums\PixKeyType;
use App\Events\PaymentRequest\PaymentRequestCreated;
use App\Events\PaymentRequest\PaymentRequestStatusChanged;
use App\Exceptions\AttachmentException;
use App\Exceptions\PaymentRequestException;
use App\Models\Appropriation;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestStatusHistory;
use App\Models\User;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PaymentRequestService
{
    public function __construct(private AttachmentService $attachmentService) {}

    public function calculateNetAmount(string $gross, string $discount): string
    {
        return bcsub($gross ?: '0', $discount ?: '0', 2);
    }

    /**
     * @param  list<string>  $attachmentPaths
     * @param  array<string, string>  $attachmentOriginalNames
     *
     * @throws PaymentRequestException
     * @throws ValidationException
     * @throws AttachmentException
     */
    public function create(
        PaymentRequestData $data,
        User $actor,
        int $attachmentCount = 0,
        array $attachmentPaths = [],
        array $attachmentOriginalNames = [],
        ?AttachmentType $attachmentType = null,
    ): PaymentRequest {
        $branchId = $this->resolveBranchFor($actor, $data->branchId);
        $resolvedAttachmentCount = $attachmentCount > 0 ? $attachmentCount : count($attachmentPaths);

        $this->assertAmounts($data->grossAmount, $data->discountAmount);
        $this->assertCostCenterForBranch($branchId, $data->costCenterId);
        $this->assertAppropriation($branchId, $data->appropriationId);
        $this->assertBankDetails($data->paymentMethod, $data->bankDetails?->toModelAttributes() ?? []);
        $this->assertBoletoHasAttachment($data->paymentMethod, $resolvedAttachmentCount);

        $netAmount = $this->calculateNetAmount($data->grossAmount, $data->discountAmount);

        $paymentRequest = DB::transaction(function () use ($data, $actor, $branchId, $netAmount, $attachmentPaths, $attachmentOriginalNames, $attachmentType): PaymentRequest {
            $attributes = $data->toModelAttributes();
            $attributes['branch_id'] = $branchId;
            $attributes['status'] = PaymentRequestStatus::Requested;
            $attributes['net_amount'] = $netAmount;

            /** @var PaymentRequest $paymentRequest */
            $paymentRequest = PaymentRequest::query()->create($attributes);

            if ($data->bankDetails !== null) {
                $paymentRequest->bankDetails()->create($data->bankDetails->toModelAttributes());
            }

            $this->recordInitialStatus($paymentRequest, $actor, dispatchEvent: false);

            if ($attachmentPaths !== []) {
                $type = $attachmentType ?? (
                    $data->paymentMethod === PaymentMethod::Boleto
                        ? AttachmentType::Boleto
                        : AttachmentType::Other
                );

                $this->attachmentService->storeManyFromPaths(
                    $paymentRequest,
                    $attachmentPaths,
                    $attachmentOriginalNames,
                    $type,
                    $actor,
                );
            }

            return $paymentRequest->fresh(['bankDetails', 'statusHistories', 'attachments']) ?? $paymentRequest;
        });

        Event::dispatch(new PaymentRequestCreated($paymentRequest));

        return $paymentRequest;
    }

    /**
     * @throws PaymentRequestException
     * @throws ValidationException
     */
    public function update(PaymentRequest $request, PaymentRequestData $data, User $actor): PaymentRequest
    {
        $this->assertEditable($request, $actor);
        $this->assertAmounts($data->grossAmount, $data->discountAmount);
        $this->assertCostCenterForBranch($request->branch_id, $data->costCenterId);
        $this->assertAppropriation($request->branch_id, $data->appropriationId);
        $this->assertBankDetails($data->paymentMethod, $data->bankDetails?->toModelAttributes() ?? []);

        $netAmount = $this->calculateNetAmount($data->grossAmount, $data->discountAmount);

        return DB::transaction(function () use ($request, $data, $netAmount): PaymentRequest {
            $attributes = $data->toModelAttributes();
            unset($attributes['branch_id']);
            $attributes['net_amount'] = $netAmount;

            $request->update($attributes);

            if ($data->bankDetails !== null) {
                $request->bankDetails()->updateOrCreate(
                    ['payment_request_id' => $request->getKey()],
                    $data->bankDetails->toModelAttributes(),
                );
            }

            return $request->fresh(['bankDetails']) ?? $request;
        });
    }

    /**
     * @throws PaymentRequestException
     */
    public function transitionStatus(
        PaymentRequest $request,
        PaymentRequestStatus $to,
        User $actor,
        ?string $notes = null,
    ): PaymentRequest {
        if (! ($actor->isOperador() || $actor->isAdm())) {
            throw PaymentRequestException::unauthorizedStatusTransition();
        }

        if (! $request->isVisibleTo($actor)) {
            throw PaymentRequestException::unauthorizedStatusTransition();
        }

        $from = $request->status;

        if (! $from->canTransitionTo($to)) {
            throw PaymentRequestException::invalidStatusTransition($from->value, $to->value);
        }

        $paymentRequest = DB::transaction(function () use ($request, $from, $to, $actor, $notes): PaymentRequest {
            $request->update(['status' => $to]);

            PaymentRequestStatusHistory::query()->create([
                'payment_request_id' => $request->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $actor->getKey(),
                'notes' => $notes,
                'created_at' => now(),
            ]);

            return $request->fresh(['statusHistories']) ?? $request;
        });

        Event::dispatch(new PaymentRequestStatusChanged($paymentRequest, $from, $to, $actor));

        return $paymentRequest;
    }

    public function recordInitialStatus(PaymentRequest $request, User $actor, bool $dispatchEvent = true): void
    {
        $exists = PaymentRequestStatusHistory::query()
            ->where('payment_request_id', $request->getKey())
            ->where('to_status', PaymentRequestStatus::Requested)
            ->whereNull('from_status')
            ->exists();

        if (! $exists) {
            PaymentRequestStatusHistory::query()->create([
                'payment_request_id' => $request->getKey(),
                'from_status' => null,
                'to_status' => PaymentRequestStatus::Requested,
                'changed_by' => $actor->getKey(),
                'notes' => null,
                'created_at' => now(),
            ]);
        }

        if ($dispatchEvent) {
            Event::dispatch(new PaymentRequestCreated($request));
        }
    }

    public function delete(PaymentRequest $request): void
    {
        $request->delete();
    }

    /**
     * @throws PaymentRequestException
     */
    public function resolveBranchFor(User $user, ?string $branchId): string
    {
        if ($user->role->seesAllBranches()) {
            if (blank($branchId)) {
                throw PaymentRequestException::branchNotAllowed('');
            }

            $exists = Branch::query()->active()->whereKey($branchId)->exists();

            if (! $exists) {
                throw PaymentRequestException::branchNotAllowed($branchId);
            }

            return $branchId;
        }

        $allowed = $user->branches()->pluck('branches.id')->map(fn ($id): string => (string) $id);

        if (filled($branchId)) {
            if (! $allowed->contains($branchId)) {
                throw PaymentRequestException::branchNotAllowed($branchId);
            }

            return $branchId;
        }

        $defaultId = $user->defaultBranch()->value('branches.id');

        if (filled($defaultId)) {
            return (string) $defaultId;
        }

        if ($allowed->count() === 1) {
            return (string) $allowed->first();
        }

        throw PaymentRequestException::branchNotAllowed('');
    }

    /**
     * @throws PaymentRequestException
     */
    public function assertCostCenterForBranch(?string $branchId, ?string $costCenterId): void
    {
        if (blank($branchId) || blank($costCenterId)) {
            throw PaymentRequestException::costCenterNotAllowed();
        }

        $belongs = CostCenter::query()
            ->whereKey($costCenterId)
            ->where('branch_id', $branchId)
            ->exists();

        if (! $belongs) {
            throw PaymentRequestException::costCenterNotAllowed();
        }
    }

    /**
     * @throws PaymentRequestException
     */
    public function assertAppropriation(?string $branchId, ?string $appropriationId): void
    {
        if (PaymentRequest::requiresAppropriationForBranch($branchId) && blank($appropriationId)) {
            throw PaymentRequestException::appropriationRequired();
        }

        if (blank($appropriationId)) {
            return;
        }

        if (blank($branchId)) {
            throw PaymentRequestException::appropriationNotAllowed();
        }

        $companyId = Branch::query()->whereKey($branchId)->value('company_id');

        if ($companyId === null) {
            throw PaymentRequestException::appropriationNotAllowed();
        }

        $belongs = Appropriation::query()
            ->whereKey($appropriationId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $belongs) {
            throw PaymentRequestException::appropriationNotAllowed();
        }
    }

    /**
     * @throws PaymentRequestException
     */
    public function assertAmounts(string $gross, string $discount): void
    {
        if (bccomp($gross, '0', 2) <= 0) {
            throw PaymentRequestException::invalidNetAmount();
        }

        if (bccomp($discount, '0', 2) < 0) {
            throw PaymentRequestException::invalidNetAmount();
        }

        if (bccomp($discount, $gross, 2) > 0) {
            throw PaymentRequestException::discountExceedsGross();
        }
    }

    /**
     * @param  array<string, mixed>  $bankDetails
     *
     * @throws PaymentRequestException
     * @throws ValidationException
     */
    public function assertBankDetails(PaymentMethod $method, array $bankDetails): void
    {
        if ($method === PaymentMethod::Boleto) {
            if (blank($bankDetails['digitable_line'] ?? null)) {
                throw PaymentRequestException::incompleteBankDetails('boleto');
            }

            return;
        }

        if ($method !== PaymentMethod::Deposit) {
            return;
        }

        $depositType = $bankDetails['deposit_type'] ?? null;
        $depositType = $depositType instanceof DepositType
            ? $depositType
            : (filled($depositType) ? DepositType::tryFrom((string) $depositType) : null);

        if ($depositType === null) {
            throw PaymentRequestException::incompleteBankDetails('deposit');
        }

        if ($depositType === DepositType::Pix) {
            $this->assertPixDetails($bankDetails);

            return;
        }

        if ($depositType === DepositType::Transfer) {
            $this->assertTransferDetails($bankDetails);
        }
    }

    /**
     * @throws PaymentRequestException
     */
    public function assertBoletoHasAttachment(PaymentMethod $method, int $attachmentCount): void
    {
        if ($method === PaymentMethod::Boleto && $attachmentCount < 1) {
            throw PaymentRequestException::boletoAttachmentRequired();
        }
    }

    /**
     * @throws PaymentRequestException
     */
    public function assertEditable(PaymentRequest $request, User $actor): void
    {
        if (! $request->isEditableBy($actor)) {
            throw PaymentRequestException::cannotEditInStatus($request->status->value);
        }
    }

    /**
     * Apply OCR result only onto blank fields.
     */
    public function applyOcrResult(PaymentRequest $request, \App\DTOs\BoletoOcrResult $result): PaymentRequest
    {
        if (! $result->wasSuccessful) {
            return $request;
        }

        return DB::transaction(function () use ($request, $result): PaymentRequest {
            $updates = [];

            if (blank($request->gross_amount) && filled($result->amount)) {
                $updates['gross_amount'] = $result->amount;
                $updates['net_amount'] = $this->calculateNetAmount(
                    $result->amount,
                    (string) ($request->discount_amount ?? '0'),
                );
            }

            if (blank($request->due_date) && $result->dueDate !== null) {
                $updates['due_date'] = $result->dueDate->toDateString();
            }

            if ($updates !== []) {
                $request->update($updates);
            }

            if (filled($result->digitableLine)) {
                $bankDetails = $request->bankDetails()->firstOrNew([]);

                if (blank($bankDetails->digitable_line)) {
                    $bankDetails->digitable_line = $result->digitableLine;
                    $bankDetails->payment_request_id = $request->getKey();
                    $bankDetails->save();
                }
            }

            return $request->fresh(['bankDetails']) ?? $request;
        });
    }

    /**
     * @param  array<string, mixed>  $bankDetails
     *
     * @throws PaymentRequestException
     * @throws ValidationException
     */
    private function assertPixDetails(array $bankDetails): void
    {
        $hasKey = filled($bankDetails['pix_key_type'] ?? null) && filled($bankDetails['pix_key'] ?? null);
        $hasQr = filled($bankDetails['pix_qr_code'] ?? null);

        if (! $hasKey && ! $hasQr) {
            throw PaymentRequestException::pixDetailsIncomplete();
        }

        if (! $hasKey) {
            return;
        }

        $pixKeyType = $bankDetails['pix_key_type'] instanceof PixKeyType
            ? $bankDetails['pix_key_type']
            : PixKeyType::from((string) $bankDetails['pix_key_type']);

        $validator = Validator::make(
            ['pix_key' => $bankDetails['pix_key']],
            ['pix_key' => $pixKeyType->validationRules()],
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * @param  array<string, mixed>  $bankDetails
     *
     * @throws PaymentRequestException
     */
    private function assertTransferDetails(array $bankDetails): void
    {
        $required = [
            'holder_document',
            'bank_id',
            'agency',
            'account_number',
            'account_digit',
            'account_type',
        ];

        foreach ($required as $field) {
            if (blank($bankDetails[$field] ?? null)) {
                throw PaymentRequestException::transferDetailsIncomplete();
            }
        }

        $digits = preg_replace('/\D/', '', (string) $bankDetails['holder_document']) ?? '';

        if (strlen($digits) === 11) {
            $validator = Validator::make(['document' => $digits], ['document' => [new ValidCpf]]);
            if ($validator->fails()) {
                throw PaymentRequestException::invalidHolderDocument();
            }

            return;
        }

        if (strlen($digits) === 14) {
            $validator = Validator::make(['document' => $digits], ['document' => [new ValidCnpj]]);
            if ($validator->fails()) {
                throw PaymentRequestException::invalidHolderDocument();
            }

            return;
        }

        throw PaymentRequestException::invalidHolderDocument();
    }
}
