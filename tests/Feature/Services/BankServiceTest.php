<?php

declare(strict_types=1);

use App\Exceptions\BankException;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\Supplier;
use App\Services\BankService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;

it('enforces partial unique bank code', function (): void {
    Bank::factory()->create(['code' => '999']);

    expect(fn () => Bank::factory()->create(['code' => '999']))
        ->toThrow(QueryException::class);
});

it('blocks soft delete when non-trashed accounts exist', function (): void {
    $bank = Bank::factory()->create(['code' => '888', 'name' => 'Test Bank']);
    $branch = Branch::factory()->create();

    BranchBankAccount::factory()->for($branch)->create([
        'bank_id' => $bank->getKey(),
        'bank_code' => $bank->code,
        'bank_name' => $bank->name,
    ]);

    expect(fn () => app(BankService::class)->delete($bank))
        ->toThrow(BankException::class);
});

it('blocks soft delete when non-trashed supplier bank details exist', function (): void {
    $supplier = Supplier::factory()->withTransferDetails()->create();
    $bank = $supplier->load('bankDetails.bank')->bankDetails->bank;

    expect(fn () => app(BankService::class)->delete($bank))
        ->toThrow(BankException::class);
});

it('allows soft delete when only trashed accounts remain', function (): void {
    $bank = Bank::factory()->create(['code' => '887', 'name' => 'Orphan Bank']);
    $branch = Branch::factory()->create();

    $account = BranchBankAccount::factory()->for($branch)->create([
        'bank_id' => $bank->getKey(),
        'bank_code' => $bank->code,
        'bank_name' => $bank->name,
    ]);
    $account->delete();

    app(BankService::class)->delete($bank);

    expect($bank->fresh()->trashed())->toBeTrue();
});

it('syncs bank_code and bank_name when bank_id is set', function (): void {
    $bank = Bank::factory()->create(['code' => '341', 'name' => 'Itaú Sync']);
    $branch = Branch::factory()->create();

    $account = BranchBankAccount::factory()->for($branch)->create([
        'bank_id' => null,
        'bank_code' => '000',
        'bank_name' => 'Placeholder',
    ]);

    $account->bank_id = $bank->getKey();
    $account->save();

    expect($account->fresh()->bank_code)->toBe('341')
        ->and($account->fresh()->bank_name)->toBe('Itaú Sync');
});

it('propagates bank rename to linked non-trashed accounts', function (): void {
    $bank = Bank::factory()->create(['code' => '237', 'name' => 'Old Name']);
    $branch = Branch::factory()->create();

    $account = BranchBankAccount::factory()->for($branch)->create([
        'bank_id' => $bank->getKey(),
        'bank_code' => '237',
        'bank_name' => 'Old Name',
    ]);

    app(BankService::class)->update($bank, ['name' => 'New Name']);

    expect($account->fresh()->bank_name)->toBe('New Name')
        ->and($account->fresh()->bank_code)->toBe('237');
});

it('backfills bank_id from bank_code matching active banks', function (): void {
    $bank = Bank::factory()->create(['code' => '104', 'name' => 'Caixa']);
    $branch = Branch::factory()->create();

    $account = BranchBankAccount::factory()->for($branch)->create([
        'bank_id' => null,
        'bank_code' => '104',
        'bank_name' => 'Caixa Econômica',
    ]);

    $orphan = BranchBankAccount::factory()->for($branch)->create([
        'bank_id' => null,
        'bank_code' => '777',
        'bank_name' => 'Unknown',
    ]);

    Artisan::call('banks:backfill-branch-accounts');

    expect($account->fresh()->bank_id)->toBe($bank->getKey())
        ->and($orphan->fresh()->bank_id)->toBeNull();
});
