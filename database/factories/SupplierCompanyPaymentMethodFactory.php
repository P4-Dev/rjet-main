<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierCompanyPaymentMethod>
 */
final class SupplierCompanyPaymentMethodFactory extends Factory
{
    protected $model = SupplierCompanyPaymentMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'company_id' => Company::factory(),
            'payment_method' => fake()->randomElement(PaymentMethod::cases()),
        ];
    }
}
