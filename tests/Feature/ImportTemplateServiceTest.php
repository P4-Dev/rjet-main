<?php

declare(strict_types=1);

use App\DTOs\ImportTemplateData;
use App\DTOs\ImportTemplateMappingData;
use App\Enums\ImportFileFormat;
use App\Enums\ImportTargetField;
use App\Exceptions\ImportException;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportTemplateMapping;
use App\Models\ImportTemplateVersion;
use App\Models\User;
use App\Services\ImportTemplateService;

beforeEach(function (): void {
    $this->service = app(ImportTemplateService::class);
    $this->adm = User::factory()->adm()->create();
});

/**
 * @param  list<ImportTemplateMappingData>|null  $mappings
 */
function makeImportTemplateData(array $overrides = [], ?array $mappings = null): ImportTemplateData
{
    $branch = $overrides['branch'] ?? Branch::factory()->create();

    $defaultMappings = $mappings ?? [
        new ImportTemplateMappingData(ImportTargetField::SupplierDocument, 'fornecedor'),
        new ImportTemplateMappingData(ImportTargetField::CostCenterCode, 'centro_custo'),
        new ImportTemplateMappingData(ImportTargetField::GrossAmount, 'valor'),
        new ImportTemplateMappingData(ImportTargetField::DueDate, 'vencimento'),
        new ImportTemplateMappingData(ImportTargetField::PaymentMethod, null, 'deposit'),
        new ImportTemplateMappingData(ImportTargetField::DepositType, null, 'pix'),
        new ImportTemplateMappingData(ImportTargetField::PixKeyType, null, 'email'),
        new ImportTemplateMappingData(ImportTargetField::PixKey, null, 'pix@example.com'),
    ];

    return new ImportTemplateData(
        name: $overrides['name'] ?? 'Template Teste',
        acceptedFormat: $overrides['accepted_format'] ?? ImportFileFormat::Csv,
        isActive: $overrides['is_active'] ?? true,
        companyId: $overrides['company_id'] ?? $branch->company_id,
        branchId: array_key_exists('branch_id', $overrides) ? $overrides['branch_id'] : $branch->getKey(),
        mappings: $defaultMappings,
    );
}

it('creates template with version 1 and mappings', function (): void {
    $template = $this->service->create(makeImportTemplateData(), $this->adm);

    expect($template->currentVersion)->not->toBeNull()
        ->and($template->currentVersion->version)->toBe(1)
        ->and($template->currentVersion->is_current)->toBeTrue()
        ->and($template->currentVersion->mappings)->not->toBeEmpty();
});

it('publishes v2 and moves is_current', function (): void {
    $template = $this->service->create(makeImportTemplateData(['name' => 'Publish Test']), $this->adm);
    $v1 = $template->currentVersion;
    expect($v1)->not->toBeNull();

    $v1MappingIds = $v1->mappings->pluck('id')->all();

    ImportBatch::factory()->create([
        'import_template_version_id' => $v1->getKey(),
        'created_by' => $this->adm->getKey(),
    ]);

    $v2 = $this->service->publishVersion($template, makeImportTemplateData()->mappings, $this->adm);

    expect($v2->version)->toBe(2)
        ->and($v2->is_current)->toBeTrue()
        ->and($v1->fresh()->is_current)->toBeFalse()
        ->and($v1->fresh()->trashed())->toBeFalse()
        ->and(ImportBatch::query()->where('import_template_version_id', $v1->getKey())->exists())->toBeTrue()
        ->and(ImportTemplateMapping::query()->whereIn('id', $v1MappingIds)->count())->toBe(count($v1MappingIds))
        ->and($v2->mappings->pluck('id')->intersect($v1MappingIds)->isEmpty())->toBeTrue();
});

it('rejects publish without required mappings', function (): void {
    $template = $this->service->create(makeImportTemplateData(['name' => 'Missing Maps']), $this->adm);

    $this->service->publishVersion($template, [
        new ImportTemplateMappingData(ImportTargetField::Notes, 'obs'),
    ], $this->adm);
})->throws(ImportException::class);

it('soft deletes template without cascading soft delete to versions', function (): void {
    $template = $this->service->create(makeImportTemplateData(['name' => 'Soft Delete']), $this->adm);
    $versionId = $template->currentVersion->getKey();

    $this->service->delete($template);

    expect($template->fresh()->trashed())->toBeTrue()
        ->and(ImportTemplateVersion::withTrashed()->find($versionId)?->trashed())->toBeFalse();
});

it('excludes soft-deleted template from current import version select', function (): void {
    $template = $this->service->create(makeImportTemplateData(['name' => 'Select Soft Delete']), $this->adm);
    $versionId = $template->currentVersion->getKey();

    $this->service->delete($template);

    $selectable = ImportTemplateVersion::query()
        ->where('is_current', true)
        ->whereNull('deleted_at')
        ->whereHas('template', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
        ->whereKey($versionId)
        ->exists();

    expect($selectable)->toBeFalse();
});

it('blocks soft delete of version with batches', function (): void {
    $template = $this->service->create(makeImportTemplateData(['name' => 'Version In Use']), $this->adm);
    $version = $template->currentVersion;

    ImportBatch::factory()->create([
        'import_template_version_id' => $version->getKey(),
        'created_by' => $this->adm->getKey(),
    ]);

    $this->service->softDeleteVersion($version);
})->throws(ImportException::class);
