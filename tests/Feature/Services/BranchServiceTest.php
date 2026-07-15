<?php

declare(strict_types=1);

use App\Exceptions\BranchException;
use App\Models\Branch;
use App\Models\Company;
use App\Services\BranchService;

beforeEach(function (): void {
    $this->service = app(BranchService::class);
});

it('blocks deleting a branch that still has bank accounts', function (): void {
    $branch = Branch::factory()->withBankAccounts(1)->create();

    expect(fn () => $this->service->delete($branch))
        ->toThrow(BranchException::class);

    expect(Branch::query()->whereKey($branch->getKey())->exists())->toBeTrue();
});

it('allows deleting a branch without bank accounts', function (): void {
    $branch = Branch::factory()->create();

    $this->service->delete($branch);

    expect($branch->fresh()->trashed())->toBeTrue();
});

it('does not block the company cascade even when branches have bank accounts', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->withBankAccounts(2)->create();

    $company->delete();

    expect($company->fresh()->trashed())->toBeTrue()
        ->and(Branch::withTrashed()->find($branch->getKey())->trashed())->toBeTrue();
});
