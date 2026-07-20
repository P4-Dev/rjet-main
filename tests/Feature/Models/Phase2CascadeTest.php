<?php

declare(strict_types=1);

use App\Models\Appropriation;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use App\Services\BranchService;
use Illuminate\Database\QueryException;

it('enforces partial unique cost center code per branch', function (): void {
    $branch = Branch::factory()->create();

    CostCenter::factory()->forBranch($branch)->create(['code' => 'ADM']);

    expect(fn () => CostCenter::factory()->forBranch($branch)->create(['code' => 'ADM']))
        ->toThrow(QueryException::class);
});

it('allows same cost center code on different branches', function (): void {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();

    CostCenter::factory()->forBranch($branchA)->create(['code' => 'ADM']);
    $other = CostCenter::factory()->forBranch($branchB)->create(['code' => 'ADM']);

    expect($other->code)->toBe('ADM');
});

it('enforces partial unique appropriation code per company', function (): void {
    $company = Company::factory()->create();

    Appropriation::factory()->forCompany($company)->create(['code' => 'DESP']);

    expect(fn () => Appropriation::factory()->forCompany($company)->create(['code' => 'DESP']))
        ->toThrow(QueryException::class);
});

it('cascades company soft delete to appropriations cost centers and overrides', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->forBranch($branch)->create();
    $appropriation = Appropriation::factory()->forCompany($company)->create();
    $supplier = Supplier::factory()->pj()->create();
    $override = SupplierCompanyPaymentMethod::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'company_id' => $company->getKey(),
    ]);
    BranchBankAccount::factory()->for($branch)->create();

    $company->delete();

    expect($appropriation->fresh()->trashed())->toBeTrue()
        ->and($costCenter->fresh()->trashed())->toBeTrue()
        ->and($override->fresh()->trashed())->toBeTrue()
        ->and($branch->fresh()->trashed())->toBeTrue();
});

it('restores company cascade children including cost centers and appropriations', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->forBranch($branch)->create();
    $appropriation = Appropriation::factory()->forCompany($company)->create();

    $company->delete();
    $company->restore();

    expect($branch->fresh()->trashed())->toBeFalse()
        ->and($costCenter->fresh()->trashed())->toBeFalse()
        ->and($appropriation->fresh()->trashed())->toBeFalse();
});

it('cascades cost centers when deleting a branch via BranchService', function (): void {
    $branch = Branch::factory()->create();
    $costCenter = CostCenter::factory()->forBranch($branch)->create();

    app(BranchService::class)->delete($branch);

    expect($branch->fresh()->trashed())->toBeTrue()
        ->and($costCenter->fresh()->trashed())->toBeTrue();
});
