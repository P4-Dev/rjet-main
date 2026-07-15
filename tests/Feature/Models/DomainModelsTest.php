<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;

it('uses uuid primary keys on domain models', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $account = BranchBankAccount::factory()->for($branch)->create();
    $user = User::factory()->create();

    foreach ([$company, $branch, $account, $user] as $model) {
        expect($model->getKeyName())->toBe('id')
            ->and($model->getIncrementing())->toBeFalse()
            ->and(Str::isUuid($model->getKey()))->toBeTrue();
    }
});

it('casts attributes to the expected types', function (): void {
    $user = User::factory()->adm()->approver()->create();
    $account = BranchBankAccount::factory()->default()->create();

    expect($user->role)->toBeInstanceOf(UserRole::class)
        ->and($user->can_approve)->toBeBool()
        ->and($user->is_active)->toBeBool()
        ->and($account->account_type)->toBeInstanceOf(AccountType::class)
        ->and($account->is_default)->toBeTrue();
});

it('wires the domain relationships', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->withBankAccounts(2)->create();

    expect($branch->company->is($company))->toBeTrue()
        ->and($company->branches->contains($branch))->toBeTrue()
        ->and($branch->bankAccounts)->toHaveCount(2);
});

it('associates users to branches through the pivot with a default flag', function (): void {
    $user = User::factory()->cliente()->create();
    $branches = Branch::factory()->count(2)->create();

    app(App\Services\UserService::class)
        ->syncBranches($user, $branches->modelKeys(), $branches->first()->getKey());

    expect($user->branches()->count())->toBe(2)
        ->and($user->defaultBranch()->count())->toBe(1)
        ->and($user->defaultBranch()->first()->getKey())->toBe($branches->first()->getKey());
});
