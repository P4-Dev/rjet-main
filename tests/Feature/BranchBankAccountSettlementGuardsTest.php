<?php

declare(strict_types=1);

use App\Exceptions\BranchException;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\RelationManagers\BankAccountsRelationManager;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\BranchBankAccountService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('refuses to delete a bank account bound to a live cnab config until the config is replaced', function (): void {
    $account = BranchBankAccount::factory()->itau()->create();
    $config = CnabConfig::factory()->inactive()->forAccount($account)->create();

    expect(fn () => app(BranchBankAccountService::class)->delete($account))
        ->toThrow(BranchException::class, BranchException::bankAccountHasCnabConfig((string) $account->getKey())->getMessage())
        ->and($account->fresh()->trashed())->toBeFalse();

    $config->delete();
    app(BranchBankAccountService::class)->delete($account->fresh());

    expect($account->fresh()->trashed())->toBeTrue();
});

it('guards the bank account soft delete even outside the service', function (): void {
    $account = BranchBankAccount::factory()->itau()->create();
    CnabConfig::factory()->forAccount($account)->create();

    expect(fn () => $account->delete())->toThrow(BranchException::class)
        ->and($account->fresh()->trashed())->toBeFalse();
});

it('locks the branch of a bank account referenced by a settlement or a trashed config', function (Closure $reference): void {
    $account = BranchBankAccount::factory()->itau()->create();
    $reference($account);
    $originalBranchId = $account->branch_id;

    expect(fn () => app(BranchBankAccountService::class)->update($account, ['branch_id' => Branch::factory()->create()->getKey()]))
        ->toThrow(BranchException::class, BranchException::bankAccountBranchLocked((string) $account->getKey())->getMessage())
        ->and($account->fresh()->branch_id)->toBe($originalBranchId);
})->with([
    'settlement' => [fn (BranchBankAccount $account) => PaymentSettlement::factory()->forAccount($account)->create()],
    'trashed cnab config' => [fn (BranchBankAccount $account) => CnabConfig::factory()->forAccount($account)->trashed()->create()],
]);

it('moves an unreferenced bank account to another branch', function (): void {
    $account = BranchBankAccount::factory()->itau()->create();
    $otherBranch = Branch::factory()->create();

    app(BranchBankAccountService::class)->update($account, ['branch_id' => $otherBranch->getKey()]);

    expect($account->fresh()->branch_id)->toBe($otherBranch->getKey());
});

it('notifies and deletes nothing when a bulk delete includes an account bound to a live config', function (): void {
    actingAs(User::factory()->adm()->create());
    $branch = Branch::factory()->create();
    $bound = BranchBankAccount::factory()->itau()->for($branch)->create();
    CnabConfig::factory()->forAccount($bound)->create();
    $free = BranchBankAccount::factory()->for($branch)->create();

    Livewire::test(BankAccountsRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => EditBranch::class])
        ->selectTableRecords([$bound->getKey(), $free->getKey()])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified(BranchException::bankAccountHasCnabConfig((string) $bound->getKey())->getUserMessage());

    expect($bound->fresh()->trashed())->toBeFalse()
        ->and($free->fresh()->trashed())->toBeFalse();
});

it('bulk deletes bank accounts without a live config', function (): void {
    actingAs(User::factory()->adm()->create());
    $branch = Branch::factory()->create();
    $accounts = BranchBankAccount::factory()->count(2)->for($branch)->create();

    Livewire::test(BankAccountsRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => EditBranch::class])
        ->selectTableRecords($accounts->modelKeys())
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertHasNoErrors();

    expect(BranchBankAccount::query()->whereKey($accounts->modelKeys())->count())->toBe(0);
});
