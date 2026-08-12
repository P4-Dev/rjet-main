<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' LTDA',
            'document' => fake()->unique()->cnpj(false),
            'is_active' => true,
            'is_appropriation_required' => false,
            'approval_sla_business_days' => 2,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function requiresAppropriation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_appropriation_required' => true,
        ]);
    }

    public function withApprovalSla(int $days = 2): static
    {
        return $this->state(fn (): array => [
            'approval_sla_business_days' => $days,
        ]);
    }

    /**
     * Cria a empresa com filiais associadas.
     */
    public function withBranches(int $count = 2): static
    {
        return $this->has(BranchFactory::new()->count($count), 'branches');
    }
}
