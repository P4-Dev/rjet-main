<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
final class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contactable_type' => 'supplier',
            'contactable_id' => Supplier::factory(),
            'type' => ContactType::Main,
            'name' => fake()->name(),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->numerify('11########'),
            'mobile' => fake()->optional()->numerify('119########'),
            'position' => fake()->optional()->jobTitle(),
            'department' => fake()->optional()->word(),
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
