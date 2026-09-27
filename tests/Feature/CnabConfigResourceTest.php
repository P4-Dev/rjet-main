<?php

declare(strict_types=1);

use App\Filament\Resources\CnabConfigs\Pages\CreateCnabConfig;
use App\Filament\Resources\CnabConfigs\Pages\EditCnabConfig;
use App\Filament\Resources\CnabConfigs\Pages\ListCnabConfigs;
use App\Filament\Resources\CnabConfigs\Pages\ViewCnabConfig;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\PaymentSettlement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('lets adm create a config for an account of the selected branch', function (): void {
    actingAs(User::factory()->adm()->create());
    $account = BranchBankAccount::factory()->itau()->create();

    Livewire::test(CreateCnabConfig::class)
        ->fillForm([
            'branch_id' => $account->branch_id,
            'branch_bank_account_id' => $account->getKey(),
            'payment_type_code' => '20',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CnabConfig::query()->sole()->branch_bank_account_id)->toBe($account->getKey());
});

it('only offers accounts of the selected branch', function (): void {
    actingAs(User::factory()->adm()->create());
    $account = BranchBankAccount::factory()->itau()->create();
    $otherBranchAccount = BranchBankAccount::factory()->itau()->create();

    Livewire::test(CreateCnabConfig::class)
        ->fillForm([
            'branch_id' => $account->branch_id,
            'branch_bank_account_id' => $otherBranchAccount->getKey(),
            'payment_type_code' => '20',
        ])
        ->call('create')
        ->assertHasFormErrors(['branch_bank_account_id']);

    expect(CnabConfig::query()->count())->toBe(0);
});

it('lets adm edit free fields', function (): void {
    actingAs(User::factory()->adm()->create());
    $config = CnabConfig::factory()->create();

    Livewire::test(EditCnabConfig::class, ['record' => $config->getKey()])
        ->fillForm(['company_name' => 'RAZAO NOVA'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($config->fresh()->company_name)->toBe('RAZAO NOVA');
});

it('shows operador the list and view without write actions', function (): void {
    actingAs(User::factory()->operador()->create());
    $config = CnabConfig::factory()->create();

    Livewire::test(ListCnabConfigs::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$config])
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($config));
    Livewire::test(ViewCnabConfig::class, ['record' => $config->getKey()])
        ->assertOk()
        ->assertActionHidden('edit');
    Livewire::test(EditCnabConfig::class, ['record' => $config->getKey()])->assertForbidden();
});

it('locks account, layout and sequence fields after the first issued file', function (): void {
    actingAs(User::factory()->adm()->create());
    $config = CnabConfig::factory()->create();
    $settlement = PaymentSettlement::factory()->forAccount($config->branchBankAccount)->create();
    CnabFile::factory()->for($settlement, 'settlement')->generated()->create(['cnab_config_id' => $config->getKey()]);

    Livewire::test(EditCnabConfig::class, ['record' => $config->getKey()])
        ->assertFormFieldDisabled('branch_bank_account_id')
        ->assertFormFieldDisabled('layout')
        ->assertFormFieldDisabled('last_file_sequence')
        ->assertFormFieldEnabled('company_name');
});
