<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\PersonType;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use Illuminate\Database\Seeder;

final class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['name' => 'Fornecedor Demo'],
            [
                'person_type' => PersonType::Pj,
                'document' => fake()->unique()->cnpj(false),
                'legal_name' => 'Fornecedor Demo LTDA',
                'email' => 'contato@fornecedordemo.example',
                'phone' => '1133334444',
                'default_payment_method' => PaymentMethod::Boleto,
                'is_active' => true,
            ],
        );

        $altitude = Company::query()->where('name', 'Altitude')->first();
        $glow = Company::query()->where('name', 'Glow')->first();

        if ($altitude !== null) {
            SupplierCompanyPaymentMethod::query()->firstOrCreate(
                [
                    'supplier_id' => $supplier->getKey(),
                    'company_id' => $altitude->getKey(),
                ],
                ['payment_method' => PaymentMethod::Boleto],
            );
        }

        if ($glow !== null) {
            SupplierCompanyPaymentMethod::query()->firstOrCreate(
                [
                    'supplier_id' => $supplier->getKey(),
                    'company_id' => $glow->getKey(),
                ],
                ['payment_method' => PaymentMethod::Deposit],
            );
        }
    }
}
