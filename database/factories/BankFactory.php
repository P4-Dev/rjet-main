<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bank>
 */
final class BankFactory extends Factory
{
    protected $model = Bank::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'name' => fake()->company().' Bank',
            'ispb' => fake()->optional()->numerify('########'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
