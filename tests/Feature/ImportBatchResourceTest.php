<?php

declare(strict_types=1);

use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\ImportBatches\Pages\CreateImportBatch;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Models\ImportBatch;
use App\Models\User;
use App\Policies\ImportBatchPolicy;
use App\Services\ImportTemplateService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.imports.disk' => 'local']);
});

it('allows operador to list import batches', function (): void {
    actingAs(User::factory()->operador()->create());

    Livewire::test(ListImportBatches::class)->assertOk();
});

it('allows adm to list import batches', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(ListImportBatches::class)->assertOk();
});

it('allows operador to open create and view pages', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = ImportBatch::factory()->create();
    Storage::disk($batch->disk)->put($batch->path, "col\nvalue\n");

    Livewire::test(CreateImportBatch::class)->assertOk();
    Livewire::test(ViewImportBatch::class, ['record' => $batch->getKey()])->assertOk();
});

it('validates required fields on create', function (): void {
    actingAs(User::factory()->operador()->create());

    Livewire::test(CreateImportBatch::class)
        ->fillForm([
            'import_template_version_id' => null,
            'spreadsheet' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['import_template_version_id', 'spreadsheet']);
});

it('shows download action when spreadsheet exists', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = ImportBatch::factory()->create();
    Storage::disk($batch->disk)->put($batch->path, "col\nvalue\n");

    Livewire::test(ViewImportBatch::class, ['record' => $batch->getKey()])
        ->assertActionVisible('downloadSpreadsheet');
});

it('keeps historical batch view after soft-deleted template', function (): void {
    $adm = User::factory()->adm()->create();
    actingAs($adm);

    $batch = ImportBatch::factory()->create(['created_by' => $adm->getKey()]);
    $template = $batch->templateVersion->template;
    Storage::disk($batch->disk)->put($batch->path, "col\nvalue\n");

    app(ImportTemplateService::class)->delete($template);

    Livewire::test(ViewImportBatch::class, ['record' => $batch->getKey()])->assertOk();
});

it('forbids cliente from viewing batches', function (): void {
    $cliente = User::factory()->cliente()->create();
    actingAs($cliente);

    Livewire::test(ListImportBatches::class)->assertForbidden();

    $policy = new ImportBatchPolicy;
    $batch = ImportBatch::factory()->create();

    expect($policy->viewAny($cliente))->toBeFalse()
        ->and($policy->view($cliente, $batch))->toBeFalse()
        ->and($policy->create($cliente))->toBeFalse();
});

it('does not expose edit page for batches', function (): void {
    expect(array_keys(ImportBatchResource::getPages()))->not->toContain('edit');
});
