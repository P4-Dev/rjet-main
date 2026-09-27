<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CnabRemittanceData;
use App\DTOs\CnabRemittanceResult;
use App\DTOs\CnabValidationError;
use App\DTOs\CnabValidationReport;
use App\Enums\CnabFileStatus;
use App\Enums\CnabPaymentType;
use App\Events\Cnab\CnabFileDownloaded;
use App\Events\Cnab\CnabFileGenerated;
use App\Events\Cnab\CnabFileGenerationFailed;
use App\Events\Cnab\CnabFileGenerationRequested;
use App\Exceptions\CnabException;
use App\Integrations\Cnab\CnabAdapterResolver;
use App\Integrations\Cnab\CnabRemittanceValidator;
use App\Jobs\Cnab\GenerateCnabFileJob;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\CnabFileItem;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class CnabFileService
{
    public function __construct(
        private readonly CnabRemittanceService $remittanceService,
        private readonly CnabAdapterResolver $adapterResolver,
        private readonly CnabRemittanceValidator $validator,
    ) {}

    /**
     * @throws CnabException
     */
    public function request(PaymentSettlement $settlement, User $actor): CnabFile
    {
        try {
            $file = DB::transaction(fn (): CnabFile => $this->createQueuedFile($settlement, $actor));
        } catch (UniqueConstraintViolationException) {
            throw CnabException::generationAlreadyActive();
        }

        Event::dispatch(new CnabFileGenerationRequested($file));

        return $file;
    }

    /**
     * Called by GenerateCnabFileJob. Deterministic failures mark the file failed without throwing;
     * infrastructure failures throw so the job retries.
     *
     * @throws CnabException
     */
    public function generate(CnabFile $file): void
    {
        $file = $this->startGenerating($file);

        if ($file === null) {
            return;
        }

        /** @var PaymentSettlement $settlement */
        $settlement = $file->settlement()->firstOrFail();

        if (! $settlement->isDraft()) {
            $this->markFailed($file, __('cnab_files.errors.settlement_not_draft'));

            return;
        }

        try {
            /** @var CnabConfig $config */
            $config = $file->config()->firstOrFail();
            $report = $this->remittanceService->validate($settlement, $config);

            if (! $report->isValid()) {
                $this->markFailed($file, __('cnab_files.errors.remittance_invalid', ['count' => $report->errorCount()]), $report);

                return;
            }

            $fileSequence = $this->assignFileSequence($file);
            $data = $this->remittanceService->buildData($settlement, $config->refresh(), $fileSequence);
            $adapter = $this->adapterResolver->for($file->layout);
            $result = $adapter->build($data);
        } catch (CnabException $exception) {
            $this->markFailed($file, $exception->getUserMessage());

            return;
        }

        $structuralErrors = $this->validator->validate($result->content, $data, $result);

        if ($structuralErrors !== []) {
            $this->markFailed(
                $file,
                __('cnab_files.errors.remittance_invalid', ['count' => count($structuralErrors)]),
                new CnabValidationReport(structuralErrors: $structuralErrors),
            );

            return;
        }

        $disk = (string) config('rjet.cnab.disk');
        $path = sprintf(
            '%s/%s/%s/%s.rem',
            config('rjet.cnab.directory'),
            $settlement->branch_id,
            $data->generatedAt->format('Y/m'),
            $file->getKey(),
        );

        $this->store($disk, $path, $result->content);

        try {
            $completed = DB::transaction(function () use ($file, $config, $settlement, $data, $result, $disk, $path, $adapter): bool {
                /** @var CnabFile|null $locked */
                $locked = CnabFile::query()->whereKey($file->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->status !== CnabFileStatus::Generating) {
                    return false;
                }

                $this->persistPlacements($file, $data, $result);

                $file->forceFill([
                    'status' => CnabFileStatus::Generated,
                    'disk' => $disk,
                    'path' => $path,
                    'filename' => $adapter->fileName($data),
                    'size' => strlen($result->content),
                    'checksum' => hash('sha256', $result->content),
                    'records_count' => $result->recordsCount,
                    'items_count' => count($data->items),
                    'total_amount' => $result->totalAmount,
                    'config_snapshot' => $this->configSnapshot($config, $settlement),
                    'generated_at' => now(),
                    'failure_reason' => null,
                ])->save();

                return true;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        if (! $completed) {
            Storage::disk($disk)->delete($path);

            return;
        }

        Event::dispatch(new CnabFileGenerated($file->fresh() ?? $file));
    }

    public function markFailed(CnabFile $file, string $userReason, ?CnabValidationReport $report = null): void
    {
        $failed = DB::transaction(function () use ($file, $userReason, $report): ?CnabFile {
            /** @var CnabFile|null $locked */
            $locked = CnabFile::query()->whereKey($file->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->status->canTransitionTo(CnabFileStatus::Failed)) {
                return null;
            }

            $locked->forceFill([
                'status' => CnabFileStatus::Failed,
                'failure_reason' => $userReason,
            ])->save();

            if ($report !== null) {
                $this->persistItemErrors($locked, $report);
            }

            return $locked;
        });

        if ($failed !== null) {
            $file->setRawAttributes($failed->getAttributes(), true);
            Event::dispatch(new CnabFileGenerationFailed($failed));
        }
    }

    /**
     * Safety net for files left in progress by a dead worker: requeues the same file (same NSA)
     * or fails it once it is older than the recovery ceiling. Never creates a new file.
     *
     * @return array{requeued: int, failed: int}
     */
    public function recoverStuck(bool $dryRun = false): array
    {
        $connection = (string) config('rjet.cnab.queue.connection');
        $retryAfter = (int) (config("queue.connections.{$connection}.retry_after") ?? 240);
        $staleBefore = now()->subSeconds($retryAfter + (int) config('rjet.cnab.recovery.margin_seconds'));
        $maxAgeBefore = now()->subMinutes((int) config('rjet.cnab.recovery.max_age_minutes'));
        $counts = ['requeued' => 0, 'failed' => 0];

        CnabFile::query()
            ->whereIn('status', CnabFileStatus::inProgressValues())
            ->where('updated_at', '<=', $staleBefore)
            ->chunkById(100, function ($files) use ($dryRun, $staleBefore, $maxAgeBefore, &$counts): void {
                foreach ($files as $file) {
                    $outcome = $dryRun
                        ? ($file->created_at <= $maxAgeBefore ? 'failed' : 'requeued')
                        : $this->recoverStuckFile($file, $staleBefore, $maxAgeBefore);

                    if ($outcome !== null) {
                        $counts[$outcome]++;
                    }
                }
            });

        return $counts;
    }

    /**
     * @return 'requeued'|'failed'|null
     */
    private function recoverStuckFile(CnabFile $file, CarbonInterface $staleBefore, CarbonInterface $maxAgeBefore): ?string
    {
        return DB::transaction(function () use ($file, $staleBefore, $maxAgeBefore): ?string {
            /** @var CnabFile|null $locked */
            $locked = CnabFile::query()->whereKey($file->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->status->isInProgress() || $locked->updated_at > $staleBefore) {
                return null;
            }

            if ($locked->created_at <= $maxAgeBefore) {
                $this->markFailed($locked, __('cnab_files.messages.generation_interrupted'));
                Log::warning('Stuck CNAB file marked as failed.', ['cnab_file_id' => $locked->getKey()]);

                return 'failed';
            }

            CnabFile::query()->whereKey($locked->getKey())->toBase()->update(['updated_at' => now()]);
            DB::afterCommit(fn () => GenerateCnabFileJob::dispatch($locked));
            Log::info('Stuck CNAB file requeued.', ['cnab_file_id' => $locked->getKey()]);

            return 'requeued';
        });
    }

    /**
     * @throws CnabException
     */
    public function retry(CnabFile $file, User $actor): CnabFile
    {
        if (! $file->isRetryable()) {
            throw CnabException::fileNotRetryable();
        }

        /** @var PaymentSettlement $settlement */
        $settlement = $file->settlement()->firstOrFail();

        return $this->request($settlement, $actor);
    }

    /**
     * Supersedes a generated file and queues a new one (new NSA) in a single transaction.
     *
     * @throws CnabException
     */
    public function regenerate(CnabFile $file, User $actor, string $reason): CnabFile
    {
        /** @var PaymentSettlement $settlement */
        $settlement = $file->settlement()->firstOrFail();

        if (! $settlement->isDraft()) {
            throw CnabException::settlementNotDraft();
        }

        if ($file->status !== CnabFileStatus::Generated) {
            throw CnabException::fileNotRetryable();
        }

        try {
            $newFile = DB::transaction(function () use ($file, $settlement, $actor, $reason): CnabFile {
                $this->supersede($file, $actor, $reason);

                return $this->createQueuedFile($settlement, $actor);
            });
        } catch (UniqueConstraintViolationException) {
            throw CnabException::generationAlreadyActive();
        }

        Event::dispatch(new CnabFileGenerationRequested($newFile));

        return $newFile;
    }

    public function supersedeForCancellation(PaymentSettlement $settlement, User $actor): void
    {
        $settlement->cnabFiles()
            ->where('status', CnabFileStatus::Generated)
            ->get()
            ->each(fn (CnabFile $file) => $this->supersede($file, $actor, __('cnab_files.messages.cancellation')));
    }

    /**
     * @throws CnabException
     */
    public function download(CnabFile $file, User $actor, ?string $ipAddress = null): StreamedResponse
    {
        if (! $file->isDownloadable()) {
            throw CnabException::fileNotDownloadable();
        }

        $storage = Storage::disk((string) $file->disk);

        if (! $storage->exists((string) $file->path)) {
            throw CnabException::fileMissing();
        }

        if (! hash_equals((string) $file->checksum, hash('sha256', (string) $storage->get((string) $file->path)))) {
            Log::critical('CNAB file checksum mismatch.', [
                'cnab_file_id' => $file->getKey(),
                'file_sequence' => $file->file_sequence,
            ]);

            throw CnabException::fileIntegrityCheckFailed();
        }

        if ($file->downloaded_at === null) {
            $file->forceFill([
                'downloaded_at' => now(),
                'downloaded_by' => $actor->getKey(),
            ])->save();
        }

        Event::dispatch(new CnabFileDownloaded($file, $actor, $ipAddress));

        return $storage->download((string) $file->path, (string) $file->filename);
    }

    /**
     * @throws CnabException
     */
    private function createQueuedFile(PaymentSettlement $settlement, User $actor): CnabFile
    {
        /** @var PaymentSettlement $locked */
        $locked = PaymentSettlement::query()->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isDraft()) {
            throw CnabException::settlementNotDraft();
        }

        if ($locked->isSettlementDatePast()) {
            throw CnabException::paymentDateInPast();
        }

        $config = $this->remittanceService->liveConfigFor($locked);

        if ($locked->cnabFiles()->whereIn('status', CnabFileStatus::activeValues())->exists()) {
            throw CnabException::generationAlreadyActive();
        }

        /** @var CnabFile $file */
        $file = CnabFile::query()->create([
            'payment_settlement_id' => $locked->getKey(),
            'cnab_config_id' => $config->getKey(),
            'layout' => $config->layout,
            'status' => CnabFileStatus::Queued,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);

        return $file;
    }

    private function startGenerating(CnabFile $file): ?CnabFile
    {
        return DB::transaction(function () use ($file): ?CnabFile {
            /** @var CnabFile|null $locked */
            $locked = CnabFile::query()->whereKey($file->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->status->isInProgress()) {
                return null;
            }

            $locked->forceFill([
                'status' => CnabFileStatus::Generating,
                'started_at' => $locked->started_at ?? now(),
            ]);
            $locked->updateTimestamps();
            $locked->save();

            return $locked;
        });
    }

    /**
     * A sequence assigned by a previous attempt (crash/retry) is reused, never re-issued.
     *
     * @throws CnabException
     */
    private function assignFileSequence(CnabFile $file): int
    {
        return DB::transaction(function () use ($file): int {
            /** @var CnabConfig $config */
            $config = CnabConfig::withTrashed()->whereKey($file->cnab_config_id)->lockForUpdate()->firstOrFail();
            /** @var CnabFile $locked */
            $locked = CnabFile::query()->whereKey($file->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->file_sequence !== null) {
                $file->file_sequence = $locked->file_sequence;

                return $locked->file_sequence;
            }

            $next = $config->last_file_sequence + 1;

            if ($next > CnabConfig::MAX_FILE_SEQUENCE) {
                throw CnabException::fileSequenceExhausted();
            }

            $config->forceFill(['last_file_sequence' => $next])->save();
            $locked->forceFill(['file_sequence' => $next])->save();
            $file->file_sequence = $next;

            return $next;
        });
    }

    /**
     * @throws CnabException
     */
    private function store(string $disk, string $path, string $content): void
    {
        try {
            $stored = Storage::disk($disk)->put($path, $content, 'private');
        } catch (Throwable) {
            $stored = false;
        }

        if (! $stored) {
            throw CnabException::storageWriteFailed();
        }
    }

    private function persistPlacements(CnabFile $file, CnabRemittanceData $data, CnabRemittanceResult $result): void
    {
        foreach ($data->items as $item) {
            $placement = $result->placements[$item->settlementItemId];

            CnabFileItem::query()->create([
                'cnab_file_id' => $file->getKey(),
                'payment_settlement_item_id' => $item->settlementItemId,
                'payment_type' => $item->paymentType,
                'payment_form_code' => $placement['payment_form_code'],
                'batch_number' => $placement['batch_number'],
                'record_sequence' => $placement['record_sequence'],
                'reference' => $item->reference,
                'amount' => $item->amount,
                'is_valid' => true,
            ]);
        }
    }

    private function persistItemErrors(CnabFile $file, CnabValidationReport $report): void
    {
        if ($report->itemErrors === []) {
            return;
        }

        $items = PaymentSettlementItem::query()
            ->with('paymentRequest.bankDetails')
            ->whereKey(array_keys($report->itemErrors))
            ->get();

        foreach ($items as $item) {
            $paymentRequest = $item->paymentRequest;

            CnabFileItem::query()->create([
                'cnab_file_id' => $file->getKey(),
                'payment_settlement_item_id' => $item->getKey(),
                'payment_type' => CnabPaymentType::fromPaymentRequest($paymentRequest) ?? CnabPaymentType::intendedFor($paymentRequest),
                'reference' => CnabFileItem::referenceFor((string) $paymentRequest->getKey()),
                'amount' => (string) $item->amount,
                'is_valid' => false,
                'validation_errors' => array_map(
                    fn (CnabValidationError $error): array => $error->toArray(),
                    $report->itemErrors[(string) $item->getKey()],
                ),
            ]);
        }
    }

    private function supersede(CnabFile $file, User $actor, string $reason): void
    {
        $file->forceFill([
            'status' => CnabFileStatus::Superseded,
            'superseded_at' => now(),
            'superseded_by' => $actor->getKey(),
            'supersede_reason' => $reason,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function configSnapshot(CnabConfig $config, PaymentSettlement $settlement): array
    {
        $account = $settlement->branchBankAccount;
        $branch = $settlement->branch;

        return [
            'cnab_config_id' => $config->getKey(),
            'layout' => $config->layout->value,
            'company_name' => $config->company_name,
            'agreement_code' => $config->agreement_code,
            'wallet_code' => $config->wallet_code,
            'payment_type_code' => $config->payment_type_code,
            'account' => [
                'id' => $account->getKey(),
                'bank_code' => $account->bank_code,
                'agency' => $account->agency,
                'agency_digit' => $account->agency_digit,
                'account_number' => $account->account_number,
                'account_digit' => $account->account_digit,
            ],
            'branch' => [
                'id' => $branch->getKey(),
                'document' => $branch->document,
                'legal_name' => $branch->legal_name,
            ],
        ];
    }
}
