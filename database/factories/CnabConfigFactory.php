<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CnabLayout;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CnabConfig>
 */
final class CnabConfigFactory extends Factory
{
    protected $model = CnabConfig::class;

    /**
     * A new account per config: the live-config partial unique forbids reuse.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_bank_account_id' => BranchBankAccount::factory()->itau(),
            'layout' => CnabLayout::Itau240,
            'company_name' => null,
            'agreement_code' => null,
            'wallet_code' => null,
            'payment_type_code' => '20',
            'last_file_sequence' => 0,
            'is_active' => true,
        ];
    }

    public function itau240(): static
    {
        return $this->state(fn (): array => ['layout' => CnabLayout::Itau240]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function forAccount(BranchBankAccount $account): static
    {
        return $this->state(fn (): array => ['branch_bank_account_id' => $account->getKey()]);
    }

    public function withSequence(int $sequence): static
    {
        return $this->state(fn (): array => ['last_file_sequence' => $sequence]);
    }

    public function trashed(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }
}
