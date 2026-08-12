<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
final class ApprovalFactory extends Factory
{
    protected $model = Approval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $paymentRequest = PaymentRequest::factory()->boleto();
        $assignedAt = now();

        return [
            'payment_request_id' => $paymentRequest,
            'approval_rule_id' => ApprovalRule::factory(),
            'approver_user_id' => User::factory()->operador()->approver(),
            'status' => ApprovalStatus::Pending,
            'reason' => null,
            'amount_snapshot' => '1000.00',
            'branch_id_snapshot' => fake()->uuid(),
            'supplier_id_snapshot' => fake()->uuid(),
            'material_fingerprint' => hash('sha256', 'factory-fingerprint'),
            'assigned_at' => $assignedAt,
            'due_at' => $assignedAt->copy()->addWeekdays(2),
            'decided_at' => null,
            'decided_by' => null,
            'escalated_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Pending,
            'decided_at' => null,
            'decided_by' => null,
            'reason' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Approved,
            'decided_at' => now(),
            'decided_by' => User::factory()->adm()->approver(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Rejected,
            'reason' => 'Valor fora da política.',
            'decided_at' => now(),
            'decided_by' => User::factory()->adm()->approver(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Pending,
            'due_at' => now()->subDay(),
            'escalated_at' => null,
        ]);
    }

    public function escalated(): static
    {
        return $this->overdue()->state(fn (): array => [
            'escalated_at' => now()->subHour(),
        ]);
    }

    public function forPaymentRequest(PaymentRequest $paymentRequest): static
    {
        return $this->state(fn (): array => [
            'payment_request_id' => $paymentRequest->getKey(),
            'amount_snapshot' => $paymentRequest->net_amount,
            'branch_id_snapshot' => $paymentRequest->branch_id,
            'supplier_id_snapshot' => $paymentRequest->supplier_id,
            'material_fingerprint' => $paymentRequest->currentMaterialFingerprint(),
        ]);
    }

    public function forApprover(User $user): static
    {
        return $this->state(fn (): array => ['approver_user_id' => $user->getKey()]);
    }
}
