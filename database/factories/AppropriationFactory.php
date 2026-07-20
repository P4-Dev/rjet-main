<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appropriation;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appropriation>
 */
final class AppropriationFactory extends Factory
{
    protected $model = Appropriation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->bothify('AP-###')),
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

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes): array => [
            'company_id' => $company->getKey(),
        ]);
    }
}
