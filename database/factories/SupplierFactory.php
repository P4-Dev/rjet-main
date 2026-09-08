<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PersonType;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
final class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_type' => PersonType::Pj,
            'document' => fake()->unique()->cnpj(false),
            'name' => fake()->company(),
            'legal_name' => fake()->company().' LTDA',
            'email' => fake()->optional()->companyEmail(),
            'phone' => fake()->optional()->numerify('11########'),
            'default_payment_method' => PaymentMethod::Boleto,
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function pf(): static
    {
        return $this->state(fn (array $attributes): array => [
            'person_type' => PersonType::Pf,
            'document' => fake()->unique()->cpf(false),
            'legal_name' => null,
            'name' => fake()->name(),
        ]);
    }

    public function pj(): static
    {
        return $this->state(fn (array $attributes): array => [
            'person_type' => PersonType::Pj,
            'document' => fake()->unique()->cnpj(false),
            'legal_name' => fake()->company().' LTDA',
            'name' => fake()->company(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function withPaymentOverride(Company $company, PaymentMethod $method): static
    {
        return $this->afterCreating(function (Supplier $supplier) use ($company, $method): void {
            SupplierCompanyPaymentMethod::factory()->create([
                'supplier_id' => $supplier->getKey(),
                'company_id' => $company->getKey(),
                'payment_method' => $method,
            ]);
        });
    }

    public function withPixDetails(): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_payment_method' => PaymentMethod::Deposit,
        ])->has(SupplierBankDetailsFactory::new()->pix(), 'bankDetails');
    }

    public function withTransferDetails(): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_payment_method' => PaymentMethod::Deposit,
        ])->has(SupplierBankDetailsFactory::new()->transfer(), 'bankDetails');
    }
}
