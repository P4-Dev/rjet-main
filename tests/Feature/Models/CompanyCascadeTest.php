<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\Company;

it('cascades soft delete to branches and bank accounts (2 levels)', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->withBankAccounts(2)->create();

    $company->delete();

    expect($company->fresh()->trashed())->toBeTrue()
        ->and(Branch::withTrashed()->find($branch->getKey())->trashed())->toBeTrue()
        ->and(BranchBankAccount::query()->where('branch_id', $branch->getKey())->count())->toBe(0)
        ->and(BranchBankAccount::withTrashed()->where('branch_id', $branch->getKey())->count())->toBe(2);
});

it('restores branches and bank accounts deleted by the company cascade', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->withBankAccounts(2)->create();

    $company->delete();
    $company->restore();

    expect(Branch::query()->find($branch->getKey()))->not->toBeNull()
        ->and(BranchBankAccount::query()->where('branch_id', $branch->getKey())->count())->toBe(2);
});

it('does not restore children that were already deleted before the company cascade', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->withBankAccounts(2)->create();

    $preDeleted = $branch->bankAccounts()->first();
    $preDeleted->delete();

    // Garante um intervalo entre a exclusão prévia e a cascata da company.
    $this->travel(2)->minutes();

    $company->delete();
    $company->restore();

    expect(BranchBankAccount::query()->where('branch_id', $branch->getKey())->count())->toBe(1)
        ->and(BranchBankAccount::withTrashed()->find($preDeleted->getKey())->trashed())->toBeTrue();
});

it('force deletes the whole hierarchy', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->withBankAccounts(2)->create();

    $company->forceDelete();

    expect(Company::withTrashed()->find($company->getKey()))->toBeNull()
        ->and(Branch::withTrashed()->find($branch->getKey()))->toBeNull()
        ->and(BranchBankAccount::withTrashed()->where('branch_id', $branch->getKey())->count())->toBe(0);
});
