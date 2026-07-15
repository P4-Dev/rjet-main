<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->city(),
            'legal_name' => fake()->company().' LTDA',
            'document' => fake()->unique()->cnpj(false),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Cria a filial com contas bancárias associadas (a primeira como padrão).
     */
    public function withBankAccounts(int $count = 1): static
    {
        return $this->afterCreating(function (Branch $branch) use ($count): void {
            BranchBankAccountFactory::new()
                ->count(max(0, $count - 1))
                ->for($branch)
                ->create();

            if ($count > 0) {
                BranchBankAccountFactory::new()
                    ->default()
                    ->for($branch)
                    ->create();
            }
        });
    }
}
