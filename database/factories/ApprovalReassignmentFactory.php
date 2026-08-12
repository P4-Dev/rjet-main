<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Approval;
use App\Models\ApprovalReassignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalReassignment>
 */
final class ApprovalReassignmentFactory extends Factory
{
    protected $model = ApprovalReassignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approval_id' => Approval::factory()->pending(),
            'from_approver_user_id' => User::factory()->operador()->approver(),
            'to_approver_user_id' => User::factory()->adm()->approver(),
            'reason' => 'approver_inactive',
            'created_by' => User::factory()->adm(),
            'created_at' => now(),
        ];
    }
}
