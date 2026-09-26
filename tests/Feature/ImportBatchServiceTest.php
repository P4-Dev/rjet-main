<?php

declare(strict_types=1);

use App\DTOs\ImportTemplateData;
use App\DTOs\ImportTemplateMappingData;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportFileFormat;
use App\Enums\ImportTargetField;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Events\PaymentRequest\PaymentRequestBatchImported;
use App\Events\PaymentRequest\PaymentRequestCreated;
use App\Exceptions\ImportException;
use App\Integrations\Spreadsheet\SpreadsheetReader;
use App\Jobs\PaymentRequest\ProcessImportBatchJob;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\ImportBatchError;
use App\Models\ImportTemplate;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\PaymentRequestBatchImportedNotification;
use App\Services\ImportBatchService;
use App\Services\ImportTemplateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.imports.disk' => 'local']);

    $this->batchService = app(ImportBatchService::class);
    $this->templateService = app(ImportTemplateService::class);
    $this->adm = User::factory()->adm()->create();
});

/**
 * @return array{template: ImportTemplate, branch: Branch, supplier: Supplier, costCenter: CostCenter}
 */
function seedImportContext(User $actor, array $templateOverrides = []): array
{
    $branch = Branch::factory()->create();
    $supplier = Supplier::factory()->pj()->create([
        'default_payment_method' => PaymentMethod::Deposit,
    ]);
    $costCenter = CostCenter::factory()->for($branch)->create();

    $template = app(ImportTemplateService::class)->create(
        new ImportTemplateData(
            name: $templateOverrides['name'] ?? 'Lote CSV '.uniqid(),
            acceptedFormat: $templateOverrides['accepted_format'] ?? ImportFileFormat::Csv,
            isActive: true,
            companyId: $branch->company_id,
            branchId: $branch->getKey(),
            mappings: [
                new ImportTemplateMappingData(ImportTargetField::SupplierDocument, 'fornecedor'),
                new ImportTemplateMappingData(ImportTargetField::CostCenterCode, 'centro_custo'),
                new ImportTemplateMappingData(ImportTargetField::GrossAmount, 'valor'),
                new ImportTemplateMappingData(ImportTargetField::DueDate, 'vencimento'),
                new ImportTemplateMappingData(ImportTargetField::PaymentMethod, null, 'deposit'),
                new ImportTemplateMappingData(ImportTargetField::DepositType, null, 'pix'),
                new ImportTemplateMappingData(ImportTargetField::PixKeyType, null, 'email'),
                new ImportTemplateMappingData(ImportTargetField::PixKey, null, 'pix@example.com'),
            ],
        ),
        $actor,
    );

    return compact('template', 'branch', 'supplier', 'costCenter');
}

function csvFile(string $contents, string $name = 'import.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

it('imports valid rows into payment requests with import_batch_id', function (): void {
    Event::fake([PaymentRequestBatchImported::class, PaymentRequestCreated::class]);
    Queue::fake();

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},1500.00,31/12/2026\n";

    $batch = $this->batchService->start(
        csvFile($csv),
        $ctx['template']->currentVersion,
        $this->adm,
    );

    Queue::assertPushed(ProcessImportBatchJob::class);

    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->success_count)->toBe(1)
        ->and($batch->error_count)->toBe(0);

    $pr = PaymentRequest::query()->where('import_batch_id', $batch->getKey())->first();
    expect($pr)->not->toBeNull()
        ->and($pr->status)->toBe(PaymentRequestStatus::Requested)
        ->and((string) $pr->branch_id)->toBe((string) $ctx['branch']->getKey());

    Event::assertDispatched(PaymentRequestBatchImported::class);
    Event::assertDispatched(PaymentRequestCreated::class);
});

it('routes each imported payment request via PaymentRequestCreated', function (): void {
    Event::fake([PaymentRequestBatchImported::class, PaymentRequestCreated::class]);

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},1500.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $pr = PaymentRequest::query()->where('import_batch_id', $batch->getKey())->first();

    expect($pr)->not->toBeNull()
        ->and($pr->status)->toBe(PaymentRequestStatus::Requested);

    Event::assertDispatched(PaymentRequestCreated::class, function (PaymentRequestCreated $event) use ($pr): bool {
        return $event->paymentRequest->is($pr);
    });
});

it('supports partial mode with valid and invalid rows', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n".
        "{$ctx['supplier']->document},{$ctx['costCenter']->code},1000.00,31/12/2026\n".
        "00000000000,{$ctx['costCenter']->code},500.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->success_count)->toBe(1)
        ->and($batch->error_count)->toBe(1)
        ->and(PaymentRequest::query()->where('import_batch_id', $batch->getKey())->count())->toBe(1)
        ->and(ImportBatchError::query()->where('import_batch_id', $batch->getKey())->count())->toBe(1);

    $error = ImportBatchError::query()->where('import_batch_id', $batch->getKey())->first();
    expect($error->row_number)->toBe(3)
        ->and($error->message)->not->toBeEmpty();
});

it('rejects cliente from starting import', function (): void {
    $cliente = User::factory()->cliente()->create();
    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},100.00,31/12/2026\n";

    $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $cliente);
})->throws(ImportException::class);

it('records boleto rows as errors without creating payment requests', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $branch = Branch::factory()->create();
    $supplier = Supplier::factory()->pj()->create(['default_payment_method' => PaymentMethod::Boleto]);
    $costCenter = CostCenter::factory()->for($branch)->create();

    $template = $this->templateService->create(
        new ImportTemplateData(
            name: 'Boleto Batch '.uniqid(),
            acceptedFormat: ImportFileFormat::Csv,
            branchId: $branch->getKey(),
            companyId: $branch->company_id,
            mappings: [
                new ImportTemplateMappingData(ImportTargetField::SupplierDocument, 'fornecedor'),
                new ImportTemplateMappingData(ImportTargetField::CostCenterCode, 'centro_custo'),
                new ImportTemplateMappingData(ImportTargetField::GrossAmount, 'valor'),
                new ImportTemplateMappingData(ImportTargetField::DueDate, 'vencimento'),
                new ImportTemplateMappingData(ImportTargetField::PaymentMethod, null, 'boleto'),
                new ImportTemplateMappingData(ImportTargetField::DigitableLine, null, '23791234546789012345767890123457110000000012345'),
            ],
        ),
        $this->adm,
    );

    $csv = "fornecedor,centro_custo,valor,vencimento\n{$supplier->document},{$costCenter->code},200.00,31/12/2026\n";
    $batch = $this->batchService->start(csvFile($csv), $template->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->success_count)->toBe(0)
        ->and($batch->error_count)->toBe(1)
        ->and(PaymentRequest::query()->where('import_batch_id', $batch->getKey())->count())->toBe(0);

    Event::assertDispatched(PaymentRequestBatchImported::class, function (PaymentRequestBatchImported $event) use ($batch): bool {
        return $event->batch->is($batch)
            && $event->batch->success_count === 0;
    });
});

it('fails structurally when file format mismatches template', function (): void {
    $ctx = seedImportContext($this->adm, ['accepted_format' => ImportFileFormat::Csv]);
    $xlsx = UploadedFile::fake()->create('import.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->batchService->start($xlsx, $ctx['template']->currentVersion, $this->adm);
})->throws(ImportException::class);

it('marks batch failed for empty spreadsheet without dispatching batch imported event', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    expect($batch->fresh()->status)->toBe(ImportBatchStatus::Failed);
    Event::assertNotDispatched(PaymentRequestBatchImported::class);
});

it('marks batch failed when row limit is exceeded', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);
    config(['rjet.imports.max_rows' => 1]);

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n".
        "{$ctx['supplier']->document},{$ctx['costCenter']->code},100.00,31/12/2026\n".
        "{$ctx['supplier']->document},{$ctx['costCenter']->code},200.00,01/01/2027\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();

    expect($batch->status)->toBe(ImportBatchStatus::Failed)
        ->and($batch->success_count)->toBe(1)
        ->and($batch->error_count)->toBe(0)
        ->and($batch->total_rows)->toBe(2)
        ->and($batch->paymentRequests()->count())->toBe(1)
        ->and($batch->paymentRequests()->count())->toBe($batch->success_count);

    Event::assertNotDispatched(PaymentRequestBatchImported::class);
});

it('persists user-facing failure reason when creator is missing', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);
    Queue::fake();

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},100.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $batch->forceFill(['created_by' => null])->saveQuietly();

    $this->batchService->process($batch->fresh());

    $batch->refresh();
    $expected = ImportException::missingCreator()->getUserMessage();

    expect($batch->status)->toBe(ImportBatchStatus::Failed)
        ->and($batch->failure_reason)->toBe($expected)
        ->and($batch->failure_reason)->not->toContain('Import batch has no creator.');
});

it('persists user-facing failure reason for unexpected throwable', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);
    Queue::fake();

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},100.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);

    $this->app->instance(
        SpreadsheetReader::class,
        new class implements SpreadsheetReader
        {
            public function rows(string $absolutePath): Generator
            {
                throw new RuntimeException('Kernel panic in spreadsheet reader');
            }
        },
    );

    app(ImportBatchService::class)->process($batch->fresh());

    $batch->refresh();
    $expected = ImportException::processingFailed()->getUserMessage();

    expect($batch->status)->toBe(ImportBatchStatus::Failed)
        ->and($batch->failure_reason)->toBe($expected)
        ->and($batch->failure_reason)->not->toContain('Kernel panic');
});

it('marks batch failed when spreadsheet file is missing on disk', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);
    Queue::fake();

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},100.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $batch->update(['path' => 'imports/missing/'.uniqid().'.csv']);

    $this->batchService->process($batch->fresh());

    expect($batch->fresh()->status)->toBe(ImportBatchStatus::Failed);
    Event::assertNotDispatched(PaymentRequestBatchImported::class);
});

it('notifies creator when batch completes', function (): void {
    Notification::fake();
    Event::fake([PaymentRequestCreated::class]);

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},1500.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    Notification::assertSentTo(
        $this->adm,
        PaymentRequestBatchImportedNotification::class,
        function (PaymentRequestBatchImportedNotification $notification, array $channels): bool {
            return in_array('mail', $channels, true)
                && in_array('database', $channels, true);
        },
    );
});

it('soft delete keeps errors; force delete removes errors, nulls pr import_batch_id and deletes file', function (): void {
    Event::fake([PaymentRequestBatchImported::class, PaymentRequestCreated::class]);

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},1500.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $path = $batch->path;
    $disk = $batch->disk;
    expect(Storage::disk($disk)->exists($path))->toBeTrue();

    $pr = PaymentRequest::query()->where('import_batch_id', $batch->getKey())->first();
    ImportBatchError::factory()->create(['import_batch_id' => $batch->getKey(), 'row_number' => 99]);

    $batch->delete();
    expect($batch->fresh()->trashed())->toBeTrue()
        ->and(ImportBatchError::query()->where('import_batch_id', $batch->getKey())->count())->toBe(1)
        ->and($pr->fresh()->import_batch_id)->toBe($batch->getKey())
        ->and(Storage::disk($disk)->exists($path))->toBeTrue();

    $batch->forceDelete();
    expect(ImportBatchError::query()->where('import_batch_id', $batch->getKey())->count())->toBe(0)
        ->and($pr->fresh()->import_batch_id)->toBeNull()
        ->and(Storage::disk($disk)->exists($path))->toBeFalse();
});

it('marks supplier not found for unknown valid document', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $ctx = seedImportContext($this->adm);
    $unknownDoc = fake()->unique()->cnpj(false);
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$unknownDoc},{$ctx['costCenter']->code},100.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->error_count)->toBe(1)
        ->and(ImportBatchError::query()->where('import_batch_id', $batch->getKey())->first()->message)
        ->toContain($unknownDoc);
});

it('records invalid cpf as row error', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $ctx = seedImportContext($this->adm);
    $csv = "fornecedor,centro_custo,valor,vencimento\n11111111111,{$ctx['costCenter']->code},100.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->success_count)->toBe(0)
        ->and($batch->error_count)->toBe(1)
        ->and(PaymentRequest::query()->where('import_batch_id', $batch->getKey())->count())->toBe(0);
});

it('records duplicate rows within the same batch as errors', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $ctx = seedImportContext($this->adm);
    $row = "{$ctx['supplier']->document},{$ctx['costCenter']->code},100.00,31/12/2026";
    $csv = "fornecedor,centro_custo,valor,vencimento\n{$row}\n{$row}\n";

    $batch = $this->batchService->start(csvFile($csv), $ctx['template']->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->success_count)->toBe(1)
        ->and($batch->error_count)->toBe(1)
        ->and(PaymentRequest::query()->where('import_batch_id', $batch->getKey())->count())->toBe(1);
});

it('records inactive branch document as row error', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $inactiveBranch = Branch::factory()->inactive()->create();
    $supplier = Supplier::factory()->pj()->create(['default_payment_method' => PaymentMethod::Deposit]);
    $costCenter = CostCenter::factory()->for($inactiveBranch)->create();

    $template = $this->templateService->create(
        new ImportTemplateData(
            name: 'Branch Doc '.uniqid(),
            acceptedFormat: ImportFileFormat::Csv,
            branchId: null,
            companyId: null,
            mappings: [
                new ImportTemplateMappingData(ImportTargetField::SupplierDocument, 'fornecedor'),
                new ImportTemplateMappingData(ImportTargetField::BranchDocument, 'filial'),
                new ImportTemplateMappingData(ImportTargetField::CostCenterCode, 'centro_custo'),
                new ImportTemplateMappingData(ImportTargetField::GrossAmount, 'valor'),
                new ImportTemplateMappingData(ImportTargetField::DueDate, 'vencimento'),
                new ImportTemplateMappingData(ImportTargetField::PaymentMethod, null, 'deposit'),
                new ImportTemplateMappingData(ImportTargetField::DepositType, null, 'pix'),
                new ImportTemplateMappingData(ImportTargetField::PixKeyType, null, 'email'),
                new ImportTemplateMappingData(ImportTargetField::PixKey, null, 'pix@example.com'),
            ],
        ),
        $this->adm,
    );

    $csv = "fornecedor,filial,centro_custo,valor,vencimento\n".
        "{$supplier->document},{$inactiveBranch->document},{$costCenter->code},100.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $template->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->success_count)->toBe(0)
        ->and($batch->error_count)->toBe(1)
        ->and(PaymentRequest::query()->where('import_batch_id', $batch->getKey())->count())->toBe(0);
});

it('records branch document mismatch against template branch as row error', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $templateBranch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create();
    $supplier = Supplier::factory()->pj()->create(['default_payment_method' => PaymentMethod::Deposit]);
    $costCenter = CostCenter::factory()->for($templateBranch)->create();

    $template = $this->templateService->create(
        new ImportTemplateData(
            name: 'Branch Mismatch '.uniqid(),
            acceptedFormat: ImportFileFormat::Csv,
            branchId: $templateBranch->getKey(),
            companyId: $templateBranch->company_id,
            mappings: [
                new ImportTemplateMappingData(ImportTargetField::SupplierDocument, 'fornecedor'),
                new ImportTemplateMappingData(ImportTargetField::BranchDocument, 'filial'),
                new ImportTemplateMappingData(ImportTargetField::CostCenterCode, 'centro_custo'),
                new ImportTemplateMappingData(ImportTargetField::GrossAmount, 'valor'),
                new ImportTemplateMappingData(ImportTargetField::DueDate, 'vencimento'),
                new ImportTemplateMappingData(ImportTargetField::PaymentMethod, null, 'deposit'),
                new ImportTemplateMappingData(ImportTargetField::DepositType, null, 'pix'),
                new ImportTemplateMappingData(ImportTargetField::PixKeyType, null, 'email'),
                new ImportTemplateMappingData(ImportTargetField::PixKey, null, 'pix@example.com'),
            ],
        ),
        $this->adm,
    );

    $csv = "fornecedor,filial,centro_custo,valor,vencimento\n".
        "{$supplier->document},{$otherBranch->document},{$costCenter->code},100.00,31/12/2026\n";

    $batch = $this->batchService->start(csvFile($csv), $template->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->success_count)->toBe(0)
        ->and($batch->error_count)->toBe(1)
        ->and(PaymentRequest::query()->where('import_batch_id', $batch->getKey())->count())->toBe(0);
});

it('records inactive template branch as row error', function (): void {
    Event::fake([PaymentRequestBatchImported::class]);

    $inactiveBranch = Branch::factory()->inactive()->create();
    $supplier = Supplier::factory()->pj()->create(['default_payment_method' => PaymentMethod::Deposit]);
    $costCenter = CostCenter::factory()->for($inactiveBranch)->create();

    $template = $this->templateService->create(
        new ImportTemplateData(
            name: 'Inactive Branch '.uniqid(),
            acceptedFormat: ImportFileFormat::Csv,
            branchId: $inactiveBranch->getKey(),
            companyId: $inactiveBranch->company_id,
            mappings: [
                new ImportTemplateMappingData(ImportTargetField::SupplierDocument, 'fornecedor'),
                new ImportTemplateMappingData(ImportTargetField::CostCenterCode, 'centro_custo'),
                new ImportTemplateMappingData(ImportTargetField::GrossAmount, 'valor'),
                new ImportTemplateMappingData(ImportTargetField::DueDate, 'vencimento'),
                new ImportTemplateMappingData(ImportTargetField::PaymentMethod, null, 'deposit'),
                new ImportTemplateMappingData(ImportTargetField::DepositType, null, 'pix'),
                new ImportTemplateMappingData(ImportTargetField::PixKeyType, null, 'email'),
                new ImportTemplateMappingData(ImportTargetField::PixKey, null, 'pix@example.com'),
            ],
        ),
        $this->adm,
    );

    $csv = "fornecedor,centro_custo,valor,vencimento\n{$supplier->document},{$costCenter->code},100.00,31/12/2026\n";
    $batch = $this->batchService->start(csvFile($csv), $template->currentVersion, $this->adm);
    $this->batchService->process($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->success_count)->toBe(0)
        ->and($batch->error_count)->toBe(1)
        ->and(PaymentRequest::query()->where('import_batch_id', $batch->getKey())->count())->toBe(0);
});

it('rejects start when template was soft-deleted', function (): void {
    $ctx = seedImportContext($this->adm);
    $version = $ctx['template']->currentVersion;
    $this->templateService->delete($ctx['template']);

    $csv = "fornecedor,centro_custo,valor,vencimento\n{$ctx['supplier']->document},{$ctx['costCenter']->code},100.00,31/12/2026\n";

    $this->batchService->start(csvFile($csv), $version->fresh(), $this->adm);
})->throws(ImportException::class);
