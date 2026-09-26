<?php

declare(strict_types=1);

use App\Enums\ImportTargetField;
use App\Models\ImportBatch;
use App\Models\ImportBatchError;
use App\Models\ImportTemplate;
use App\Models\ImportTemplateMapping;
use App\Models\ImportTemplateVersion;
use App\Models\PaymentRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('enforces partial unique name on active templates', function (): void {
    ImportTemplate::factory()->create(['name' => 'Unique Name']);

    expect(fn () => ImportTemplate::factory()->create(['name' => 'Unique Name']))
        ->toThrow(QueryException::class);

    $trashed = ImportTemplate::factory()->create(['name' => 'Reusable Name']);
    $trashed->delete();

    expect(ImportTemplate::factory()->create(['name' => 'Reusable Name']))->toBeInstanceOf(ImportTemplate::class);
})->skip(fn (): bool => ! in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true), 'Partial indexes require pgsql/sqlite');

it('enforces one current version per template', function (): void {
    $template = ImportTemplate::factory()->create();
    ImportTemplateVersion::factory()->forTemplate($template)->current()->create(['version' => 1]);

    expect(fn () => ImportTemplateVersion::factory()->forTemplate($template)->current()->create(['version' => 2]))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => ! in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true), 'Partial indexes require pgsql/sqlite');

it('does not reuse version numbers after soft delete', function (): void {
    $template = ImportTemplate::factory()->create();
    $v1 = ImportTemplateVersion::factory()->forTemplate($template)->current()->create(['version' => 1]);
    $v1->update(['is_current' => false]);
    $v1->delete();

    expect(fn () => ImportTemplateVersion::factory()->forTemplate($template)->create(['version' => 1, 'is_current' => true]))
        ->toThrow(QueryException::class);
});

it('allows multiple mappings with null source_column on same version', function (): void {
    $version = ImportTemplateVersion::factory()->create();

    ImportTemplateMapping::factory()->defaultOnly(ImportTargetField::PaymentMethod, 'deposit')->create([
        'import_template_version_id' => $version->getKey(),
    ]);
    ImportTemplateMapping::factory()->defaultOnly(ImportTargetField::DepositType, 'pix')->create([
        'import_template_version_id' => $version->getKey(),
    ]);

    expect(ImportTemplateMapping::query()->where('import_template_version_id', $version->getKey())->whereNull('source_column')->count())->toBe(2);
});

it('keeps errors on soft delete and cascades on force delete; nulls payment request fk', function (): void {
    $batch = ImportBatch::factory()->create();
    ImportBatchError::factory()->create(['import_batch_id' => $batch->getKey()]);
    $pr = PaymentRequest::factory()->fromImportBatch($batch)->depositPix()->create();

    $batch->delete();
    expect(ImportBatchError::query()->where('import_batch_id', $batch->getKey())->exists())->toBeTrue()
        ->and($pr->fresh()->import_batch_id)->toBe($batch->getKey());

    $batch->forceDelete();
    expect(ImportBatchError::query()->where('import_batch_id', $batch->getKey())->exists())->toBeFalse()
        ->and($pr->fresh()->import_batch_id)->toBeNull();
});

it('restricts force delete of version with batches', function (): void {
    $version = ImportTemplateVersion::factory()->create();
    ImportBatch::factory()->create(['import_template_version_id' => $version->getKey()]);

    expect(fn () => $version->forceDelete())->toThrow(QueryException::class);
});

it('keeps payment request f3 columns after import_batch_id alter', function (): void {
    expect(Schema::hasColumns('payment_requests', [
        'branch_id',
        'supplier_id',
        'cost_center_id',
        'appropriation_id',
        'payment_method',
        'status',
        'gross_amount',
        'discount_amount',
        'net_amount',
        'due_date',
        'import_batch_id',
    ]))->toBeTrue();
});
