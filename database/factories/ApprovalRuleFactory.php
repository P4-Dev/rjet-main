<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRule>
 */
final class ApprovalRuleFactory extends Factory
{
    protected $model = ApprovalRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'min_amount' => '0.00',
            'max_amount' => '5000.00',
            'approver_user_id' => User::factory()->operador()->approver(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function openEnded(): static
    {
        return $this->state(fn (): array => ['max_amount' => null]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (): array => ['branch_id' => $branch->getKey()]);
    }

    public function forApprover(User $user): static
    {
        return $this->state(fn (): array => ['approver_user_id' => $user->getKey()]);
    }

    public function range(float $min, ?float $max): static
    {
        return $this->state(fn (): array => [
            'min_amount' => number_format($min, 2, '.', ''),
            'max_amount' => $max === null ? null : number_format($max, 2, '.', ''),
        ]);
    }
}
