<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Cliente,
            'can_approve' => false,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function adm(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Adm,
        ]);
    }

    public function operador(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Operador,
        ]);
    }

    public function cliente(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Cliente,
        ]);
    }

    public function approver(): static
    {
        return $this->state(fn (array $attributes): array => [
            'can_approve' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Vincula o usuário a filiais, marcando a primeira como padrão.
     *
     * @param  int|array<int, Branch>  $branches
     */
    public function withBranches(int|array $branches = 1): static
    {
        return $this->afterCreating(function (User $user) use ($branches): void {
            $models = is_int($branches)
                ? Branch::factory()->count($branches)->create()
                : collect($branches);

            $models->values()->each(function (Branch $branch, int $index) use ($user): void {
                $user->branches()->attach($branch->getKey(), [
                    'id' => (string) Str::orderedUuid(),
                    'is_default' => $index === 0,
                ]);
            });
        });
    }
}
