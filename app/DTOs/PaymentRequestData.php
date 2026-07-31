<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

final readonly class PaymentRequestData
{
    public function __construct(
        public ?string $branchId,
        public string $supplierId,
        public string $costCenterId,
        public ?string $appropriationId,
        public PaymentMethod $paymentMethod,
        public string $grossAmount,
        public string $discountAmount,
        public CarbonImmutable $dueDate,
        public ?string $notes = null,
        public ?PaymentRequestBankDetailsData $bankDetails = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $paymentMethod = $data['payment_method'] instanceof PaymentMethod
            ? $data['payment_method']
            : PaymentMethod::from((string) $data['payment_method']);

        $bankDetails = null;

        if (isset($data['bank_details']) || isset($data['bankDetails'])) {
            $raw = $data['bank_details'] ?? $data['bankDetails'];
            $bankDetails = $raw instanceof PaymentRequestBankDetailsData
                ? $raw
                : PaymentRequestBankDetailsData::fromArray((array) $raw);
        }

        $dueDate = $data['due_date'] instanceof CarbonImmutable
            ? $data['due_date']
            : CarbonImmutable::parse((string) $data['due_date']);

        return new self(
            branchId: isset($data['branch_id']) ? ($data['branch_id'] !== null ? (string) $data['branch_id'] : null) : null,
            supplierId: (string) $data['supplier_id'],
            costCenterId: (string) $data['cost_center_id'],
            appropriationId: isset($data['appropriation_id']) && $data['appropriation_id'] !== null
                ? (string) $data['appropriation_id']
                : null,
            paymentMethod: $paymentMethod,
            grossAmount: (string) $data['gross_amount'],
            discountAmount: (string) ($data['discount_amount'] ?? '0'),
            dueDate: $dueDate,
            notes: isset($data['notes']) ? ($data['notes'] !== null ? (string) $data['notes'] : null) : null,
            bankDetails: $bankDetails,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'branch_id' => $this->branchId,
            'supplier_id' => $this->supplierId,
            'cost_center_id' => $this->costCenterId,
            'appropriation_id' => $this->appropriationId,
            'payment_method' => $this->paymentMethod,
            'gross_amount' => $this->grossAmount,
            'discount_amount' => $this->discountAmount,
            'due_date' => $this->dueDate->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
