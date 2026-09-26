<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportTargetField;
use App\Models\ImportBatch;
use App\Models\ImportBatchError;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatchError>
 */
final class ImportBatchErrorFactory extends Factory
{
    protected $model = ImportBatchError::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'row_number' => fake()->numberBetween(2, 100),
            'target_field' => ImportTargetField::SupplierDocument,
            'message' => fake()->sentence(),
            'raw_values' => ['col' => 'value'],
            'created_at' => now(),
        ];
    }

    public function forRow(int $rowNumber): static
    {
        return $this->state(fn (): array => ['row_number' => $rowNumber]);
    }
}
