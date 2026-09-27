<?php

declare(strict_types=1);

use App\Enums\CnabLayout;
use App\Exceptions\CnabException;
use App\Models\Bank;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\CnabConfigService;

function cnabConfigService(): CnabConfigService
{
    return app(CnabConfigService::class);
}

/**
 * @return array<string, mixed>
 */
function cnabConfigInput(BranchBankAccount $account, array $overrides = []): array
{
    return [
        'branch_bank_account_id' => $account->getKey(),
        'layout' => CnabLayout::Itau240->value,
        'payment_type_code' => '20',
        'is_active' => true,
        ...$overrides,
    ];
}

function issueCnabFile(CnabConfig $config, int $fileSequence): CnabFile
{
    $settlement = PaymentSettlement::factory()->forAccount($config->branchBankAccount)->create();

    return CnabFile::factory()->for($settlement, 'settlement')->generated()->create([
        'cnab_config_id' => $config->getKey(),
        'file_sequence' => $fileSequence,
    ]);
}

beforeEach(function (): void {
    $this->adm = User::factory()->adm()->create();
    $this->account = BranchBankAccount::factory()->itau()->create();
});

it('creates one config per account with blameable columns', function (): void {
    $config = cnabConfigService()->create(cnabConfigInput($this->account), $this->adm);

    expect($config->created_by)->toBe($this->adm->getKey())
        ->and($config->updated_by)->toBe($this->adm->getKey())
        ->and($config->last_file_sequence)->toBe(0);
    expect(fn () => cnabConfigService()->create(cnabConfigInput($this->account), $this->adm))
        ->toThrow(CnabException::class, CnabException::configAlreadyExists()->getMessage());
});

it('rejects an itau layout on an account from another bank', function (): void {
    $account = BranchBankAccount::factory()->create(['bank_id' => Bank::factory()->create(['code' => '237'])->getKey(), 'bank_code' => '237']);

    expect(fn () => cnabConfigService()->create(cnabConfigInput($account), $this->adm))
        ->toThrow(CnabException::class, CnabException::bankMismatch('341', '237')->getMessage());
});

it('locks account, layout and sequence after the first issued file', function (Closure $changes): void {
    $config = cnabConfigService()->create(cnabConfigInput($this->account), $this->adm);
    issueCnabFile($config, 1);

    expect(fn () => cnabConfigService()->update($config, $changes(), $this->adm))
        ->toThrow(CnabException::class, CnabException::configLockedAfterIssue()->getMessage());
})->with([
    'account' => [fn (): array => ['branch_bank_account_id' => BranchBankAccount::factory()->itau()->create()->getKey()]],
    'sequence' => [fn (): array => ['last_file_sequence' => 50]],
]);

it('still accepts free fields after issue', function (): void {
    $config = cnabConfigService()->create(cnabConfigInput($this->account), $this->adm);
    issueCnabFile($config, 1);

    cnabConfigService()->update($config, ['company_name' => 'NOVA RAZAO'], $this->adm);

    expect($config->fresh()->company_name)->toBe('NOVA RAZAO');
});

it('seeds the replacement config sequence from the highest issued nsa', function (): void {
    $first = cnabConfigService()->create(cnabConfigInput($this->account), $this->adm);
    issueCnabFile($first, 7);
    cnabConfigService()->delete($first, $this->adm);

    $replacement = cnabConfigService()->create(cnabConfigInput($this->account), $this->adm);

    expect($replacement->last_file_sequence)->toBe(7);
    expect(fn () => cnabConfigService()->update($replacement, ['last_file_sequence' => 5], $this->adm))
        ->toThrow(CnabException::class, CnabException::fileSequenceBelowIssued(7)->getMessage());
});

it('refuses to delete or deactivate a config while a file is being generated', function (string $operation, string $fileState): void {
    $config = cnabConfigService()->create(cnabConfigInput($this->account), $this->adm);
    CnabFile::factory()->for(PaymentSettlement::factory()->forAccount($this->account)->create(), 'settlement')
        ->{$fileState}()
        ->create(['cnab_config_id' => $config->getKey()]);

    expect(fn () => cnabConfigService()->{$operation}($config, $this->adm))
        ->toThrow(CnabException::class, CnabException::configHasActiveGeneration()->getMessage())
        ->and($config->fresh())
        ->trashed()->toBeFalse()
        ->is_active->toBeTrue();
})->with(['delete', 'deactivate'])->with(['queued', 'generating']);

it('refuses to deactivate through update while a file is being generated', function (string $fileState): void {
    $config = cnabConfigService()->create(cnabConfigInput($this->account), $this->adm);
    CnabFile::factory()->for(PaymentSettlement::factory()->forAccount($this->account)->create(), 'settlement')
        ->{$fileState}()
        ->create(['cnab_config_id' => $config->getKey()]);

    expect(fn () => cnabConfigService()->update($config, ['is_active' => false], $this->adm))
        ->toThrow(CnabException::class, CnabException::configHasActiveGeneration()->getMessage())
        ->and($config->fresh()->is_active)->toBeTrue();
})->with(['queued', 'generating']);

it('still activates or edits other fields through update while a file is being generated', function (): void {
    $config = cnabConfigService()->create(cnabConfigInput($this->account, ['is_active' => false]), $this->adm);
    CnabFile::factory()->for(PaymentSettlement::factory()->forAccount($this->account)->create(), 'settlement')
        ->generating()
        ->create(['cnab_config_id' => $config->getKey()]);

    cnabConfigService()->update($config, ['is_active' => true], $this->adm);
    cnabConfigService()->update($config, ['company_name' => 'NOVA RAZAO', 'is_active' => true], $this->adm);

    expect($config->fresh())
        ->is_active->toBeTrue()
        ->company_name->toBe('NOVA RAZAO');
});

it('keeps the account bound when the config is only deactivated', function (): void {
    $config = cnabConfigService()->create(cnabConfigInput($this->account), $this->adm);
    cnabConfigService()->deactivate($config, $this->adm);

    expect(fn () => cnabConfigService()->create(cnabConfigInput($this->account), $this->adm))
        ->toThrow(CnabException::class, CnabException::configAlreadyExists()->getMessage());
});
