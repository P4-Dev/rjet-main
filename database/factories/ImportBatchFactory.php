<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\ImportTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportBatch>
 */
final class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = sprintf('imports/%s/%s/%s/%s.csv', now()->format('Y'), now()->format('m'), Str::uuid(), Str::random(16));

        return [
            'import_template_version_id' => ImportTemplateVersion::factory(),
            'status' => ImportBatchStatus::Pending,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => 'import.csv',
            'mime_type' => 'text/csv',
            'size' => 128,
            'mappings_snapshot' => [],
            'total_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'failure_reason' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => ImportBatchStatus::Pending]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => ImportBatchStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ImportBatchStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'total_rows' => 1,
            'success_count' => 1,
            'error_count' => 0,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ImportBatchStatus::Failed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'failure_reason' => 'Structural failure',
        ]);
    }

    public function withFile(string $contents = "col\nvalue\n"): static
    {
        return $this->afterCreating(function (ImportBatch $batch) use ($contents): void {
            Storage::fake($batch->disk);
            Storage::disk($batch->disk)->put($batch->path, $contents);
        });
    }
}
