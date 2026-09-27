<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttachmentBatchStatus;
use App\Models\AttachmentBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttachmentBatch>
 */
final class AttachmentBatchFactory extends Factory
{
    protected $model = AttachmentBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => AttachmentBatchStatus::PendingClassification,
            'items_count' => 0,
            'classified_count' => 0,
            'renamed_count' => 0,
            'failed_count' => 0,
            'failure_reason' => null,
            'classified_at' => null,
            'renaming_started_at' => null,
            'renamed_at' => null,
            'naming_generated_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function pendingClassification(): static
    {
        return $this->state(fn (): array => ['status' => AttachmentBatchStatus::PendingClassification]);
    }

    public function classified(): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchStatus::Classified,
            'classified_at' => now(),
        ]);
    }

    public function renaming(): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchStatus::Renaming,
            'classified_at' => now()->subMinute(),
            'renaming_started_at' => now(),
            'naming_generated_at' => now(),
        ]);
    }

    public function renamed(): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchStatus::Renamed,
            'classified_at' => now()->subMinutes(2),
            'renaming_started_at' => now()->subMinute(),
            'naming_generated_at' => now()->subMinute(),
            'renamed_at' => now(),
        ]);
    }

    public function partiallyFailed(): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchStatus::PartiallyFailed,
            'classified_at' => now()->subMinutes(2),
            'renaming_started_at' => now()->subMinute(),
            'naming_generated_at' => now()->subMinute(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchStatus::Failed,
            'failure_reason' => 'Structural failure',
        ]);
    }
}
