<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DTOs\PaymentRequestBankDetailsData;
use App\DTOs\PaymentRequestData;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PixKeyType;
use App\Models\Appropriation;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

final class PaymentRequestSeeder extends Seeder
{
    public function run(): void
    {
        $companyRequired = Company::query()->updateOrCreate(
            ['document' => '11222333000181'],
            [
                'name' => 'RJET Demo Apropriação',
                'legal_name' => 'RJET Demo Apropriação LTDA',
                'is_active' => true,
                'is_appropriation_required' => true,
            ],
        );

        $companyOptional = Company::query()->updateOrCreate(
            ['document' => '11444777000161'],
            [
                'name' => 'RJET Demo Livre',
                'legal_name' => 'RJET Demo Livre LTDA',
                'is_active' => true,
                'is_appropriation_required' => false,
            ],
        );

        $actor = User::query()->where('role', 'adm')->first()
            ?? User::factory()->create(['role' => 'adm']);

        $supplier = Supplier::query()->first()
            ?? Supplier::factory()->create(['default_payment_method' => PaymentMethod::Deposit]);

        $service = app(PaymentRequestService::class);

        foreach ([$companyRequired, $companyOptional] as $company) {
            $branch = Branch::query()->firstOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'document' => $company->document === '11222333000181' ? '11222333000262' : '11444777000242',
                ],
                [
                    'name' => $company->name.' - Matriz',
                    'legal_name' => $company->legal_name,
                    'is_active' => true,
                ],
            );

            if (PaymentRequest::query()->where('branch_id', $branch->getKey())->exists()) {
                continue;
            }

            $costCenter = CostCenter::query()->firstOrCreate(
                [
                    'branch_id' => $branch->getKey(),
                    'code' => 'CC-DEMO',
                ],
                [
                    'name' => 'Centro Demo',
                    'is_active' => true,
                    'sort_order' => 1,
                ],
            );

            $appropriationId = null;

            if ($company->is_appropriation_required) {
                $appropriationId = Appropriation::query()->firstOrCreate(
                    [
                        'company_id' => $company->getKey(),
                        'code' => 'AP-DEMO',
                    ],
                    [
                        'name' => 'Apropriação Demo',
                        'is_active' => true,
                        'sort_order' => 1,
                    ],
                )->getKey();
            }

            $service->create(
                new PaymentRequestData(
                    branchId: (string) $branch->getKey(),
                    supplierId: (string) $supplier->getKey(),
                    costCenterId: (string) $costCenter->getKey(),
                    appropriationId: $appropriationId !== null ? (string) $appropriationId : null,
                    paymentMethod: PaymentMethod::Deposit,
                    grossAmount: '1500.00',
                    discountAmount: '0.00',
                    dueDate: CarbonImmutable::now()->addDays(10),
                    notes: 'Seed Fase 3',
                    bankDetails: new PaymentRequestBankDetailsData(
                        depositType: DepositType::Pix,
                        pixKeyType: PixKeyType::Email,
                        pixKey: 'demo@rjet.test',
                    ),
                ),
                $actor,
            );
        }
    }
}
