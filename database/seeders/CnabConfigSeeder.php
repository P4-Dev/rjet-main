<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CnabLayout;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use Illuminate\Database\Seeder;

/**
 * Development only: one Itaú 240 configuration per active Itaú account (idempotent).
 */
final class CnabConfigSeeder extends Seeder
{
    public function run(): void
    {
        BranchBankAccount::query()
            ->active()
            ->where('bank_code', CnabLayout::Itau240->bankCode())
            ->whereNotNull('bank_id')
            ->get()
            ->each(fn (BranchBankAccount $account): CnabConfig => CnabConfig::query()->firstOrCreate(
                ['branch_bank_account_id' => $account->getKey()],
                [
                    'layout' => CnabLayout::Itau240,
                    'payment_type_code' => '20',
                    'last_file_sequence' => 0,
                    'is_active' => true,
                ],
            ));
    }
}
