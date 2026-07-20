<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BankException;
use App\Models\Bank;
use App\Models\BranchBankAccount;
use Illuminate\Support\Facades\DB;

final class BankService
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws BankException
     */
    public function create(array $attributes): Bank
    {
        $this->assertCodeIsUnique((string) $attributes['code']);

        return DB::transaction(fn (): Bank => Bank::query()->create($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws BankException
     */
    public function update(Bank $bank, array $attributes): Bank
    {
        if (isset($attributes['code'])) {
            $this->assertCodeIsUnique((string) $attributes['code'], (string) $bank->getKey());
        }

        return DB::transaction(function () use ($bank, $attributes): Bank {
            $bank->update($attributes);

            if ($bank->wasChanged(['code', 'name'])) {
                $this->syncBranchAccountSnapshots($bank);
            }

            return $bank->refresh();
        });
    }

    /**
     * @throws BankException
     */
    public function delete(Bank $bank): void
    {
        $this->ensureDeletable($bank);

        $bank->delete();
    }

    /**
     * @throws BankException
     */
    public function ensureDeletable(Bank $bank): void
    {
        if ($bank->branchBankAccounts()->exists()) {
            throw BankException::cannotDeleteWithAccounts((string) $bank->getKey());
        }
    }

    public function syncBranchAccountSnapshots(Bank $bank): void
    {
        BranchBankAccount::query()
            ->where('bank_id', $bank->getKey())
            ->update([
                'bank_code' => $bank->code,
                'bank_name' => $bank->name,
            ]);
    }

    /**
     * @throws BankException
     */
    private function assertCodeIsUnique(string $code, ?string $ignoreId = null): void
    {
        $exists = Bank::query()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw BankException::codeAlreadyExists($code);
        }
    }
}
