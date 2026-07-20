<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostCenter>
 */
final class CostCenterFactory extends Factory
{
    protected $model = CostCenter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => strtoupper(fake()->unique()->bothify('CC-###')),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => [
            'branch_id' => $branch->getKey(),
        ]);
    }
}
