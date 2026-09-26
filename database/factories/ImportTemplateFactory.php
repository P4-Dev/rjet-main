<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportFileFormat;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportTemplate>
 */
final class ImportTemplateFactory extends Factory
{
    protected $model = ImportTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'company_id' => null,
            'branch_id' => null,
            'accepted_format' => ImportFileFormat::Csv,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function withCompany(?Company $company = null): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company?->getKey() ?? Company::factory(),
        ]);
    }

    public function withBranch(?Branch $branch = null): static
    {
        return $this->state(function () use ($branch): array {
            $resolved = $branch ?? Branch::factory()->create();

            return [
                'branch_id' => $resolved->getKey(),
                'company_id' => $resolved->company_id,
            ];
        });
    }

    public function xlsx(): static
    {
        return $this->state(fn (): array => ['accepted_format' => ImportFileFormat::Xlsx]);
    }
}
