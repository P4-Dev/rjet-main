<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CnabFileStatus;
use App\Enums\CnabLayout;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\PaymentSettlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CnabFile>
 */
final class CnabFileFactory extends Factory
{
    protected $model = CnabFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_settlement_id' => PaymentSettlement::factory(),
            'cnab_config_id' => fn (array $attributes): string => $this->configIdForSettlement((string) $attributes['payment_settlement_id']),
            'layout' => CnabLayout::Itau240,
            'status' => CnabFileStatus::Queued,
            'file_sequence' => null,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => ['status' => CnabFileStatus::Queued]);
    }

    public function generating(): static
    {
        return $this->state(fn (): array => [
            'status' => CnabFileStatus::Generating,
            'started_at' => now(),
        ]);
    }

    public function generated(): static
    {
        return $this->state(function (): array {
            $fileId = fake()->uuid();

            return [
                'status' => CnabFileStatus::Generated,
                'file_sequence' => fake()->unique()->numberBetween(1, 999999),
                'disk' => 'local',
                'path' => "cnab/test/{$fileId}.rem",
                'filename' => "341_test_{$fileId}.rem",
                'checksum' => hash('sha256', ''),
                'size' => 0,
                'started_at' => now(),
                'generated_at' => now(),
            ];
        });
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => CnabFileStatus::Failed,
            'failure_reason' => fake()->sentence(),
        ]);
    }

    public function superseded(): static
    {
        return $this->generated()->state(fn (): array => [
            'status' => CnabFileStatus::Superseded,
            'superseded_at' => now(),
            'superseded_by' => User::factory()->adm(),
            'supersede_reason' => fake()->sentence(),
        ]);
    }

    private function configIdForSettlement(string $settlementId): string
    {
        $accountId = (string) PaymentSettlement::withTrashed()->whereKey($settlementId)->value('branch_bank_account_id');

        $configId = CnabConfig::withTrashed()->where('branch_bank_account_id', $accountId)->value('id');

        return (string) ($configId ?? CnabConfig::factory()->state(['branch_bank_account_id' => $accountId])->create()->getKey());
    }
}
