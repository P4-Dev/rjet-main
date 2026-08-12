<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\ApprovalRule;

final readonly class ApprovalRuleData
{
    public function __construct(
        public string $branchId,
        public string $minAmount,
        public ?string $maxAmount,
        public string $approverUserId,
        public bool $isActive = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $maxAmount = $data['max_amount'] ?? null;

        return new self(
            branchId: (string) $data['branch_id'],
            minAmount: number_format((float) $data['min_amount'], 2, '.', ''),
            maxAmount: $maxAmount === null || $maxAmount === ''
                ? null
                : number_format((float) $maxAmount, 2, '.', ''),
            approverUserId: (string) $data['approver_user_id'],
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    public static function fromModel(ApprovalRule $rule): self
    {
        return new self(
            branchId: (string) $rule->branch_id,
            minAmount: (string) $rule->min_amount,
            maxAmount: $rule->max_amount !== null ? (string) $rule->max_amount : null,
            approverUserId: (string) $rule->approver_user_id,
            isActive: (bool) $rule->is_active,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'branch_id' => $this->branchId,
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'approver_user_id' => $this->approverUserId,
            'is_active' => $this->isActive,
        ];
    }
}
