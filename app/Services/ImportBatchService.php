<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PaymentRequestBankDetailsData;
use App\DTOs\PaymentRequestData;
use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportFileFormat;
use App\Enums\ImportTargetField;
use App\Enums\PaymentMethod;
use App\Enums\PixKeyType;
use App\Events\PaymentRequest\PaymentRequestBatchImported;
use App\Exceptions\BusinessException;
use App\Exceptions\ImportException;
use App\Exceptions\PaymentRequestException;
use App\Integrations\Spreadsheet\SpreadsheetReader;
use App\Jobs\PaymentRequest\ProcessImportBatchJob;
use App\Models\Appropriation;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\ImportBatch;
use App\Models\ImportBatchError;
use App\Models\ImportTemplate;
use App\Models\ImportTemplateVersion;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ImportBatchService
{
    public function __construct(
        private readonly SpreadsheetReader $spreadsheetReader,
        private readonly PaymentRequestService $paymentRequestService,
    ) {}

    /**
     * @throws ImportException
     */
    public function start(UploadedFile $file, ImportTemplateVersion $version, User $actor): ImportBatch
    {
        if (! ($actor->isOperador() || $actor->isAdm())) {
            throw ImportException::unauthorizedImport();
        }

        $version->loadMissing(['template', 'mappings']);

        /** @var ImportTemplate|null $template */
        $template = $version->template;

        if ($template === null || $template->trashed() || ! $template->is_active) {
            throw ImportException::templateInactive();
        }

        if (! $version->is_current || $version->trashed()) {
            throw ImportException::templateInactive();
        }

        $this->assertFileMatchesFormat($file, $template->accepted_format);

        $batchId = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'csv');
        $hash = hash_file('sha256', $file->getRealPath() ?: $file->getPathname()) ?: Str::random(40);
        $disk = (string) config('rjet.imports.disk');
        $directory = trim((string) config('rjet.imports.directory', 'imports'), '/');
        $path = sprintf(
            '%s/%s/%s/%s/%s.%s',
            $directory,
            now()->format('Y'),
            now()->format('m'),
            $batchId,
            $hash,
            $extension,
        );

        Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        $mappingsSnapshot = $version->mappings->map(static fn ($m): array => [
            'source_column' => $m->source_column,
            'target_field' => $m->target_field instanceof ImportTargetField
                ? $m->target_field->value
                : (string) $m->target_field,
            'default_value' => $m->default_value,
            'sort_order' => $m->sort_order,
        ])->values()->all();

        /** @var ImportBatch $batch */
        $batch = ImportBatch::query()->create([
            'id' => $batchId,
            'import_template_version_id' => $version->getKey(),
            'status' => ImportBatchStatus::Pending,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'mappings_snapshot' => $mappingsSnapshot,
            'total_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
        ]);

        if ($batch->created_by === null) {
            $batch->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->saveQuietly();
        }

        ProcessImportBatchJob::dispatch($batch);

        return $batch;
    }

    public function process(ImportBatch $batch): void
    {
        $batch->refresh();

        if ($batch->status->isTerminal()) {
            return;
        }

        if ($batch->status === ImportBatchStatus::Processing && $batch->paymentRequests()->exists()) {
            $this->markFailed($batch, ImportException::unsafeReprocess()->getUserMessage());

            return;
        }

        if ($batch->status === ImportBatchStatus::Pending) {
            if (! $batch->status->canTransitionTo(ImportBatchStatus::Processing)) {
                $this->markFailed($batch, ImportException::batchNotPending()->getUserMessage());

                return;
            }

            $batch->update([
                'status' => ImportBatchStatus::Processing,
                'started_at' => now(),
            ]);
        }

        $batch->loadMissing(['templateVersion.template', 'templateVersion.mappings', 'creator']);

        $actor = $batch->creator;
        if ($actor === null) {
            $this->markFailed($batch, ImportException::missingCreator()->getUserMessage());

            return;
        }

        // Counters must be visible to catch blocks if a structural failure occurs mid-loop.
        $totalRows = 0;
        $successCount = 0;
        $errorCount = 0;
        $tempPath = null;

        try {
            [$absolutePath, $tempPath] = $this->resolveReadableSpreadsheetPath($batch);

            $maxRows = (int) config('rjet.imports.max_rows', 500);
            $seenKeys = [];

            $version = $batch->templateVersion;
            $template = $version?->template;
            $mappings = $batch->mappings_snapshot ?: ($version?->mappings?->map(static fn ($m): array => [
                'source_column' => $m->source_column,
                'target_field' => $m->target_field instanceof ImportTargetField
                    ? $m->target_field->value
                    : (string) $m->target_field,
                'default_value' => $m->default_value,
                'sort_order' => $m->sort_order,
            ])->values()->all() ?? []);

            if ($template === null || $version === null) {
                throw ImportException::templateInactive();
            }

            $hadAnyRow = false;

            foreach ($this->spreadsheetReader->rows($absolutePath) as $row) {
                $hadAnyRow = true;
                $totalRows++;

                // total_rows counts every row seen, including the one that trips max_rows
                // (that row does not create a PaymentRequest — throw is before resolve).
                if ($totalRows > $maxRows) {
                    throw ImportException::rowLimitExceeded($maxRows);
                }

                $rowNumber = (int) $row['row'];
                /** @var array<string, mixed> $cells */
                $cells = $row['values'];

                try {
                    $paymentRequestData = $this->resolveRow(
                        $cells,
                        $mappings,
                        $template,
                        $actor,
                        $batch,
                        $seenKeys,
                        $rowNumber,
                    );

                    $this->paymentRequestService->create($paymentRequestData, $actor);
                    $successCount++;
                } catch (ImportException|PaymentRequestException|ValidationException|BusinessException $e) {
                    $message = $e instanceof BusinessException
                        ? $e->getUserMessage()
                        : ($e instanceof ValidationException
                            ? collect($e->errors())->flatten()->first() ?? $e->getMessage()
                            : $e->getMessage());

                    $this->recordError(
                        $batch,
                        $rowNumber,
                        null,
                        (string) $message,
                        $cells,
                    );
                    $errorCount++;
                }
            }

            if (! $hadAnyRow) {
                throw ImportException::emptySpreadsheet();
            }

            $batch->refresh();

            if (! $batch->status->canTransitionTo(ImportBatchStatus::Completed)) {
                logger()->warning('Import batch cannot transition to Completed.', [
                    'import_batch_id' => $batch->getKey(),
                    'status' => $batch->status->value,
                ]);

                return;
            }

            $batch->update([
                'status' => ImportBatchStatus::Completed,
                'total_rows' => $totalRows,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'finished_at' => now(),
            ]);

            Event::dispatch(new PaymentRequestBatchImported(
                $batch->fresh(['creator']) ?? $batch,
            ));
        } catch (ImportException $e) {
            $this->markFailed(
                $batch,
                $e->getUserMessage(),
                $totalRows,
                $successCount,
                $errorCount,
            );
        } catch (Throwable $e) {
            logger()->error('Import batch processing failed.', [
                'import_batch_id' => $batch->getKey(),
                'exception' => $e->getMessage(),
            ]);
            $this->markFailed(
                $batch,
                ImportException::processingFailed($e)->getUserMessage(),
                $totalRows,
                $successCount,
                $errorCount,
            );
        } finally {
            if (is_string($tempPath) && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function markFailed(
        ImportBatch $batch,
        string $reason,
        ?int $totalRows = null,
        ?int $successCount = null,
        ?int $errorCount = null,
    ): void {
        $batch->refresh();

        if ($batch->status === ImportBatchStatus::Completed) {
            return;
        }

        // Failed→Failed is blocked by canTransitionTo; allow updating reason/counters
        // so job failed() can still persist after process() already marked Failed.
        if (
            $batch->status !== ImportBatchStatus::Failed
            && ! $batch->status->canTransitionTo(ImportBatchStatus::Failed)
        ) {
            logger()->warning('Import batch cannot transition to Failed.', [
                'import_batch_id' => $batch->getKey(),
                'status' => $batch->status->value,
            ]);

            return;
        }

        $payload = [
            'status' => ImportBatchStatus::Failed,
            'failure_reason' => Str::limit($reason, 2000),
            'finished_at' => now(),
        ];

        if ($totalRows !== null) {
            $payload['total_rows'] = $totalRows;
        }

        if ($successCount !== null) {
            $payload['success_count'] = $successCount;
        }

        if ($errorCount !== null) {
            $payload['error_count'] = $errorCount;
        }

        $batch->update($payload);
    }

    /**
     * @return array{0: string, 1: ?string} Absolute path and optional temp path to delete
     *
     * @throws ImportException
     */
    private function resolveReadableSpreadsheetPath(ImportBatch $batch): array
    {
        $filesystem = Storage::disk($batch->disk);

        if ($this->isLocalDisk($batch->disk)) {
            $absolutePath = $filesystem->path($batch->path);

            if (! is_file($absolutePath)) {
                throw ImportException::unreadableSpreadsheet();
            }

            return [$absolutePath, null];
        }

        if (! $filesystem->exists($batch->path)) {
            throw ImportException::unreadableSpreadsheet();
        }

        $extension = pathinfo($batch->path, PATHINFO_EXTENSION) ?: 'bin';
        $tempBase = tempnam(sys_get_temp_dir(), 'rjet_import_');

        if ($tempBase === false) {
            throw ImportException::unreadableSpreadsheet();
        }

        $tempPath = $tempBase.'.'.$extension;

        if (! @rename($tempBase, $tempPath)) {
            @unlink($tempBase);
            throw ImportException::unreadableSpreadsheet();
        }

        try {
            $contents = $filesystem->get($batch->path);

            if ($contents === null || $contents === '') {
                throw ImportException::unreadableSpreadsheet();
            }

            if (file_put_contents($tempPath, $contents) === false) {
                throw ImportException::unreadableSpreadsheet();
            }
        } catch (ImportException $e) {
            @unlink($tempPath);

            throw $e;
        } catch (Throwable $e) {
            @unlink($tempPath);

            throw ImportException::unreadableSpreadsheet($e);
        }

        return [$tempPath, $tempPath];
    }

    private function isLocalDisk(string $disk): bool
    {
        return (config("filesystems.disks.{$disk}.driver") ?? 'local') === 'local';
    }

    /**
     * @param  array<string, mixed>  $cells
     * @param  list<array<string, mixed>>  $mappings
     * @param  array<string, true>  $seenKeys
     *
     * @throws ImportException
     * @throws PaymentRequestException
     * @throws ValidationException
     */
    public function resolveRow(
        array $cells,
        array $mappings,
        ImportTemplate $template,
        User $actor,
        ImportBatch $batch,
        array &$seenKeys,
        int $rowNumber,
    ): PaymentRequestData {
        $resolved = $this->applyMappings($cells, $mappings);

        $supplierDocument = $this->normalizeDocument((string) ($resolved[ImportTargetField::SupplierDocument->value] ?? ''));
        if ($supplierDocument === '') {
            throw ImportException::invalidDocument('');
        }
        $this->assertValidDocument($supplierDocument);

        $supplier = Supplier::query()->active()->where('document', $supplierDocument)->first();
        if ($supplier === null) {
            throw ImportException::supplierNotFound($supplierDocument);
        }

        $branchId = $this->resolveBranchId($resolved, $template, $actor);

        $costCenterCode = trim((string) ($resolved[ImportTargetField::CostCenterCode->value] ?? ''));
        $costCenter = CostCenter::query()
            ->active()
            ->where('branch_id', $branchId)
            ->where('code', $costCenterCode)
            ->first();

        if ($costCenter === null) {
            throw ImportException::costCenterNotFound($costCenterCode);
        }

        $appropriationId = null;
        $appropriationCode = trim((string) ($resolved[ImportTargetField::AppropriationCode->value] ?? ''));
        $requiresAppropriation = PaymentRequest::requiresAppropriationForBranch($branchId);

        if ($requiresAppropriation && $appropriationCode === '') {
            throw ImportException::appropriationRequired();
        }

        if ($appropriationCode !== '') {
            $companyId = Branch::query()->whereKey($branchId)->value('company_id');
            $appropriation = Appropriation::query()
                ->active()
                ->where('company_id', $companyId)
                ->where('code', $appropriationCode)
                ->first();

            if ($appropriation === null) {
                throw ImportException::appropriationNotFound($appropriationCode);
            }

            $appropriationId = (string) $appropriation->getKey();
        }

        $paymentMethod = $this->resolvePaymentMethod($resolved, $supplier, $branchId);
        if ($paymentMethod === PaymentMethod::Boleto) {
            throw ImportException::boletoNotSupportedInBatch();
        }

        $grossAmount = $this->parseMoney((string) ($resolved[ImportTargetField::GrossAmount->value] ?? ''));
        $discountAmount = $this->parseMoney((string) ($resolved[ImportTargetField::DiscountAmount->value] ?? '0'));
        $dueDate = $this->parseDate((string) ($resolved[ImportTargetField::DueDate->value] ?? ''));

        $duplicateKey = implode('|', [
            $supplierDocument,
            $grossAmount,
            $dueDate->toDateString(),
            $branchId,
        ]);

        if (isset($seenKeys[$duplicateKey])) {
            throw ImportException::duplicateRowInBatch($rowNumber);
        }
        $seenKeys[$duplicateKey] = true;

        $bankDetails = $this->resolveBankDetails($resolved, $paymentMethod);

        return new PaymentRequestData(
            branchId: $branchId,
            supplierId: (string) $supplier->getKey(),
            costCenterId: (string) $costCenter->getKey(),
            appropriationId: $appropriationId,
            paymentMethod: $paymentMethod,
            grossAmount: $grossAmount,
            discountAmount: $discountAmount,
            dueDate: $dueDate,
            notes: filled($resolved[ImportTargetField::Notes->value] ?? null)
                ? (string) $resolved[ImportTargetField::Notes->value]
                : null,
            bankDetails: $bankDetails,
            importBatchId: (string) $batch->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public function recordError(
        ImportBatch $batch,
        int $rowNumber,
        ?ImportTargetField $targetField,
        string $message,
        ?array $raw = null,
    ): void {
        ImportBatchError::query()->create([
            'import_batch_id' => $batch->getKey(),
            'row_number' => $rowNumber,
            'target_field' => $targetField,
            'message' => Str::limit($message, 500),
            'raw_values' => $raw,
            'created_at' => now(),
        ]);
    }

    public function parseMoney(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '0.00';
        }

        $value = preg_replace('/[^\d,.\-]/', '', $value) ?? $value;

        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            throw ImportException::invalidAmount($value);
        }

        return number_format((float) $value, 2, '.', '');
    }

    public function parseDate(string $value): CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            throw ValidationException::withMessages([
                'due_date' => [__('import_batches.errors.empty')],
            ]);
        }

        try {
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) === 1) {
                return CarbonImmutable::createFromFormat('d/m/Y', $value)->startOfDay();
            }

            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'due_date' => [__('validation.date', ['attribute' => 'due_date'])],
            ]);
        }
    }

    /**
     * @throws ImportException
     */
    private function assertFileMatchesFormat(UploadedFile $file, ImportFileFormat $format): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $mime = (string) ($file->getMimeType() ?? '');

        if ($extension !== $format->value && ! in_array($mime, $format->mimeTypes(), true)) {
            throw ImportException::incompatibleFormat();
        }

        if ($extension !== '' && $extension !== $format->value) {
            throw ImportException::incompatibleFormat();
        }
    }

    /**
     * @param  array<string, mixed>  $cells
     * @param  list<array<string, mixed>>  $mappings
     * @return array<string, mixed>
     */
    private function applyMappings(array $cells, array $mappings): array
    {
        $resolved = [];

        foreach ($mappings as $mapping) {
            $target = (string) ($mapping['target_field'] ?? '');
            $source = $mapping['source_column'] ?? null;
            $default = $mapping['default_value'] ?? null;

            $value = null;
            if (filled($source) && array_key_exists((string) $source, $cells)) {
                $value = $cells[(string) $source];
            }

            if (($value === null || trim((string) $value) === '') && filled($default)) {
                $value = $default;
            }

            $resolved[$target] = $value;
        }

        return $resolved;
    }

    private function normalizeDocument(string $document): string
    {
        return preg_replace('/\D/', '', $document) ?? '';
    }

    /**
     * @throws ImportException
     */
    private function assertValidDocument(string $digits): void
    {
        if (strlen($digits) === 11) {
            $validator = Validator::make(['document' => $digits], ['document' => [new ValidCpf]]);
            if ($validator->fails()) {
                throw ImportException::invalidDocument($digits);
            }

            return;
        }

        if (strlen($digits) === 14) {
            $validator = Validator::make(['document' => $digits], ['document' => [new ValidCnpj]]);
            if ($validator->fails()) {
                throw ImportException::invalidDocument($digits);
            }

            return;
        }

        throw ImportException::invalidDocument($digits);
    }

    /**
     * @param  array<string, mixed>  $resolved
     *
     * @throws ImportException
     * @throws PaymentRequestException
     */
    private function resolveBranchId(array $resolved, ImportTemplate $template, User $actor): string
    {
        if (filled($template->branch_id)) {
            $rowBranchDocument = trim((string) ($resolved[ImportTargetField::BranchDocument->value] ?? ''));
            if ($rowBranchDocument !== '') {
                $digits = $this->normalizeDocument($rowBranchDocument);
                $templateBranch = Branch::query()->whereKey($template->branch_id)->first();
                if ($templateBranch !== null && $digits !== '' && $templateBranch->document !== $digits) {
                    throw PaymentRequestException::branchNotAllowed((string) $template->branch_id);
                }
            }

            return $this->paymentRequestService->resolveBranchFor($actor, (string) $template->branch_id);
        }

        $branchDocument = $this->normalizeDocument((string) ($resolved[ImportTargetField::BranchDocument->value] ?? ''));
        if ($branchDocument === '') {
            throw PaymentRequestException::branchNotAllowed('');
        }

        $branch = Branch::query()->active()->where('document', $branchDocument)->first();
        if ($branch === null) {
            throw PaymentRequestException::branchNotAllowed($branchDocument);
        }

        return $this->paymentRequestService->resolveBranchFor($actor, (string) $branch->getKey());
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function resolvePaymentMethod(array $resolved, Supplier $supplier, string $branchId): PaymentMethod
    {
        $raw = $resolved[ImportTargetField::PaymentMethod->value] ?? null;
        if (filled($raw)) {
            $normalized = strtolower(trim((string) $raw));

            return match ($normalized) {
                'boleto' => PaymentMethod::Boleto,
                'deposit', 'deposito', 'depósito' => PaymentMethod::Deposit,
                default => PaymentMethod::tryFrom($normalized) ?? $supplier->default_payment_method,
            };
        }

        $company = Branch::query()->whereKey($branchId)->first()?->company;
        if ($company !== null) {
            return $supplier->paymentMethodFor($company);
        }

        return $supplier->default_payment_method;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function resolveBankDetails(array $resolved, PaymentMethod $paymentMethod): ?PaymentRequestBankDetailsData
    {
        if ($paymentMethod === PaymentMethod::Boleto) {
            return PaymentRequestBankDetailsData::fromArray([
                'digitable_line' => $resolved[ImportTargetField::DigitableLine->value] ?? null,
            ]);
        }

        $bankId = null;
        $bankCode = trim((string) ($resolved[ImportTargetField::BankCode->value] ?? ''));
        if ($bankCode !== '') {
            $bankId = Bank::query()->active()->where('code', $bankCode)->value('id');
        }

        $depositType = $resolved[ImportTargetField::DepositType->value] ?? null;
        if (filled($depositType) && ! ($depositType instanceof DepositType)) {
            $normalized = strtolower(trim((string) $depositType));
            $depositType = match ($normalized) {
                'pix' => DepositType::Pix->value,
                'transfer', 'ted', 'doc', 'transferencia', 'transferência' => DepositType::Transfer->value,
                default => $normalized,
            };
        }

        $pixKeyType = $resolved[ImportTargetField::PixKeyType->value] ?? null;
        if (filled($pixKeyType) && ! ($pixKeyType instanceof PixKeyType)) {
            $pixKeyType = strtolower(trim((string) $pixKeyType));
        }

        $accountType = $resolved[ImportTargetField::AccountType->value] ?? null;
        if (filled($accountType) && ! ($accountType instanceof AccountType)) {
            $normalized = strtolower(trim((string) $accountType));
            $accountType = match ($normalized) {
                'checking', 'corrente', 'cc' => AccountType::Checking->value,
                'savings', 'poupanca', 'poupança', 'cp' => AccountType::Savings->value,
                default => $normalized,
            };
        }

        return PaymentRequestBankDetailsData::fromArray([
            'deposit_type' => $depositType,
            'pix_key_type' => $pixKeyType,
            'pix_key' => $resolved[ImportTargetField::PixKey->value] ?? null,
            'pix_qr_code' => $resolved[ImportTargetField::PixQrCode->value] ?? null,
            'digitable_line' => $resolved[ImportTargetField::DigitableLine->value] ?? null,
            'holder_name' => $resolved[ImportTargetField::HolderName->value] ?? null,
            'holder_document' => $resolved[ImportTargetField::HolderDocument->value] ?? null,
            'bank_id' => $bankId,
            'agency' => $resolved[ImportTargetField::Agency->value] ?? null,
            'agency_digit' => $resolved[ImportTargetField::AgencyDigit->value] ?? null,
            'account_number' => $resolved[ImportTargetField::AccountNumber->value] ?? null,
            'account_digit' => $resolved[ImportTargetField::AccountDigit->value] ?? null,
            'account_type' => $accountType,
        ]);
    }
}
