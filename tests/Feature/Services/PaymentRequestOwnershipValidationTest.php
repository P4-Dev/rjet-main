<?php

declare(strict_types=1);

use App\DTOs\PaymentRequestBankDetailsData;
use App\DTOs\PaymentRequestData;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PixKeyType;
use App\Enums\UserRole;
use App\Exceptions\PaymentRequestException;
use App\Models\Appropriation;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentRequestService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->service = app(PaymentRequestService::class);
    $this->actor = User::factory()->create(['role' => UserRole::Adm]);
});

it('rejects a cost center that does not belong to the branch', function (): void {
    $branch = Branch::factory()->create();
    $foreign = Branch::factory()->create();
    $foreignCostCenter = CostCenter::factory()->for($foreign)->create();
    $supplier = Supplier::factory()->create();

    $data = new PaymentRequestData(
        branchId: (string) $branch->getKey(),
        supplierId: (string) $supplier->getKey(),
        costCenterId: (string) $foreignCostCenter->getKey(),
        appropriationId: null,
        paymentMethod: PaymentMethod::Deposit,
        grossAmount: '100.00',
        discountAmount: '0.00',
        dueDate: CarbonImmutable::now()->addDay(),
        bankDetails: new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'ok@exemplo.com',
        ),
    );

    expect(fn () => $this->service->create($data, $this->actor))
        ->toThrow(PaymentRequestException::class);
});

it('rejects an appropriation that does not belong to the branch company', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->for($branch)->create();
    $foreignAppropriation = Appropriation::factory()->create();
    $supplier = Supplier::factory()->create();

    $data = new PaymentRequestData(
        branchId: (string) $branch->getKey(),
        supplierId: (string) $supplier->getKey(),
        costCenterId: (string) $costCenter->getKey(),
        appropriationId: (string) $foreignAppropriation->getKey(),
        paymentMethod: PaymentMethod::Deposit,
        grossAmount: '100.00',
        discountAmount: '0.00',
        dueDate: CarbonImmutable::now()->addDay(),
        bankDetails: new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'ok@exemplo.com',
        ),
    );

    expect(fn () => $this->service->create($data, $this->actor))
        ->toThrow(PaymentRequestException::class);
});
