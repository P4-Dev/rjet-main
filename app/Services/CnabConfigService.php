<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CnabValidationError;
use App\Enums\CnabFileStatus;
use App\Enums\CnabLayout;
use App\Exceptions\CnabException;
use App\Exceptions\PaymentSettlementException;
use App\Integrations\Cnab\CnabAdapterResolver;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class CnabConfigService
{
    private const LOCKED_AFTER_ISSUE = ['branch_bank_account_id', 'layout', 'last_file_sequence'];

    public function __construct(
        private readonly CnabAdapterResolver $adapterResolver,
    ) {}

    /**
     * A sequence of 0 (or omitted) inherits the highest NSA issued for the account (config replacement).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws CnabException
     * @throws PaymentSettlementException
     */
    public function create(array $data, User $actor): CnabConfig
    {
        $this->assertAdm($actor);

        try {
            return DB::transaction(function () use ($data, $actor): CnabConfig {
                $account = $this->lockAccount((string) $data['branch_bank_account_id']);

                if (CnabConfig::query()->forAccount($account)->exists()) {
                    throw CnabException::configAlreadyExists();
                }

                $config = new CnabConfig($data);
                $config->last_file_sequence = $this->resolveSequence(
                    (int) ($data['last_file_sequence'] ?? 0),
                    (string) $account->getKey(),
                    seedWhenZero: true,
                );

                $this->assertValidForAccount($config, $account);

                $config->forceFill(['created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()]);
                $config->save();

                return $config;
            });
        } catch (UniqueConstraintViolationException) {
            throw CnabException::configAlreadyExists();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CnabException
     * @throws PaymentSettlementException
     */
    public function update(CnabConfig $config, array $data, User $actor): CnabConfig
    {
        $this->assertAdm($actor);

        try {
            return DB::transaction(function () use ($config, $data, $actor): CnabConfig {
                /** @var CnabConfig $locked */
                $locked = CnabConfig::query()->whereKey($config->getKey())->lockForUpdate()->firstOrFail();
                $locked->fill($data);

                if ($locked->hasIssuedFiles() && $locked->isDirty(self::LOCKED_AFTER_ISSUE)) {
                    throw CnabException::configLockedAfterIssue();
                }

                if ($locked->isDirty('is_active') && ! $locked->is_active) {
                    $this->assertNoGenerationInProgress($locked);
                }

                $account = $this->lockAccount((string) $locked->branch_bank_account_id);

                if ($locked->isDirty('branch_bank_account_id')
                    && CnabConfig::query()->forAccount($account)->whereKeyNot($locked->getKey())->exists()) {
                    throw CnabException::configAlreadyExists();
                }

                if ($locked->isDirty(['last_file_sequence', 'branch_bank_account_id'])) {
                    $locked->last_file_sequence = $this->resolveSequence(
                        (int) $locked->last_file_sequence,
                        (string) $account->getKey(),
                        seedWhenZero: false,
                    );
                }

                $this->assertValidForAccount($locked, $account);

                $locked->forceFill(['updated_by' => $actor->getKey()])->save();
                $config->setRawAttributes($locked->getAttributes(), true);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw CnabException::configAlreadyExists();
        }
    }

    /**
     * Pauses generation only; the account stays bound (use delete to replace the config).
     *
     * @throws CnabException
     * @throws PaymentSettlementException
     */
    public function deactivate(CnabConfig $config, User $actor): void
    {
        $this->assertAdm($actor);
        $this->assertNoGenerationInProgress($config);

        $config->forceFill(['is_active' => false, 'updated_by' => $actor->getKey()])->save();
    }

    /**
     * @throws PaymentSettlementException
     */
    public function activate(CnabConfig $config, User $actor): void
    {
        $this->assertAdm($actor);

        $config->forceFill(['is_active' => true, 'updated_by' => $actor->getKey()])->save();
    }

    /**
     * @throws CnabException
     * @throws PaymentSettlementException
     */
    public function delete(CnabConfig $config, User $actor): void
    {
        $this->assertAdm($actor);
        $this->assertNoGenerationInProgress($config);

        $config->delete();
    }

    /**
     * @throws CnabException
     * @throws PaymentSettlementException
     */
    public function restore(CnabConfig $config, User $actor): void
    {
        $this->assertAdm($actor);

        if (CnabConfig::query()->forAccount((string) $config->branch_bank_account_id)->exists()) {
            throw CnabException::configAlreadyExists();
        }

        try {
            $config->restore();
        } catch (UniqueConstraintViolationException) {
            throw CnabException::configAlreadyExists();
        }
    }

    public function maxIssuedSequenceForAccount(string $branchBankAccountId): int
    {
        return (int) (CnabFile::withTrashed()
            ->whereIn(
                'cnab_config_id',
                CnabConfig::withTrashed()->where('branch_bank_account_id', $branchBankAccountId)->select('id'),
            )
            ->max('file_sequence') ?? 0);
    }

    /**
     * @throws CnabException
     */
    private function resolveSequence(int $informed, string $accountId, bool $seedWhenZero): int
    {
        $maxIssued = $this->maxIssuedSequenceForAccount($accountId);

        if ($seedWhenZero && $informed === 0) {
            return $maxIssued;
        }

        if ($informed < $maxIssued) {
            throw CnabException::fileSequenceBelowIssued($maxIssued);
        }

        if ($informed >= CnabConfig::MAX_FILE_SEQUENCE) {
            throw CnabException::fileSequenceExhausted();
        }

        return $informed;
    }

    /**
     * @throws CnabException
     * @throws PaymentSettlementException
     */
    private function assertValidForAccount(CnabConfig $config, BranchBankAccount $account): void
    {
        if (! $account->is_active || $account->bank === null) {
            throw PaymentSettlementException::bankAccountInactive();
        }

        $layout = $config->layout instanceof CnabLayout ? $config->layout : CnabLayout::from((string) $config->layout);
        $adapter = $this->adapterResolver->for($layout);

        if ($layout->bankCode() !== $account->bank->code) {
            throw CnabException::bankMismatch($layout->bankCode(), (string) $account->bank->code);
        }

        $errors = $adapter->validateConfig($config, $account, $account->branch);

        if ($errors !== []) {
            throw CnabException::configInvalid(array_map(
                fn (CnabValidationError $error): string => $error->message(),
                $errors,
            ));
        }
    }

    /**
     * @throws CnabException
     */
    private function assertNoGenerationInProgress(CnabConfig $config): void
    {
        if ($config->cnabFiles()->whereIn('status', CnabFileStatus::inProgressValues())->exists()) {
            throw CnabException::configHasActiveGeneration();
        }
    }

    /**
     * @throws PaymentSettlementException
     */
    private function assertAdm(User $actor): void
    {
        if (! $actor->isAdm()) {
            throw PaymentSettlementException::unauthorized();
        }
    }

    private function lockAccount(string $accountId): BranchBankAccount
    {
        /** @var BranchBankAccount $account */
        $account = BranchBankAccount::query()
            ->with(['bank', 'branch'])
            ->whereKey($accountId)
            ->lockForUpdate()
            ->firstOrFail();

        return $account;
    }
}
