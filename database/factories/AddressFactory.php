<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AddressType;
use App\Models\Address;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
final class AddressFactory extends Factory
{
    protected $model = Address::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'addressable_type' => 'supplier',
            'addressable_id' => Supplier::factory(),
            'type' => AddressType::Main,
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'complement' => fake()->optional()->secondaryAddress(),
            'neighborhood' => fake()->citySuffix(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip_code' => fake()->numerify('########'),
            'country' => 'BR',
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }
}
