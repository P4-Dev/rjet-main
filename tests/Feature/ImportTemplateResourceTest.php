<?php

declare(strict_types=1);

use App\Enums\ImportFileFormat;
use App\Enums\ImportTargetField;
use App\Filament\Resources\ImportTemplates\Pages\CreateImportTemplate;
use App\Filament\Resources\ImportTemplates\Pages\EditImportTemplate;
use App\Filament\Resources\ImportTemplates\Pages\ListImportTemplates;
use App\Filament\Resources\ImportTemplates\Pages\ViewImportTemplate;
use App\Models\Branch;
use App\Models\ImportTemplate;
use App\Models\User;
use App\Policies\ImportTemplatePolicy;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('allows adm to view template list', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(ListImportTemplates::class)->assertOk();
});

it('allows adm to open create and view pages', function (): void {
    actingAs(User::factory()->adm()->create());
    $template = ImportTemplate::factory()->create();

    Livewire::test(CreateImportTemplate::class)->assertOk();
    Livewire::test(ViewImportTemplate::class, ['record' => $template->getKey()])->assertOk();
    Livewire::test(EditImportTemplate::class, ['record' => $template->getKey()])->assertOk();
});

it('allows adm to create a template via filament form', function (): void {
    $adm = User::factory()->adm()->create();
    actingAs($adm);
    $branch = Branch::factory()->create();

    Livewire::test(CreateImportTemplate::class)
        ->fillForm([
            'name' => 'Template Filament '.uniqid(),
            'company_id' => $branch->company_id,
            'branch_id' => $branch->getKey(),
            'accepted_format' => ImportFileFormat::Csv->value,
            'is_active' => true,
            'mappings' => [
                [
                    'source_column' => 'fornecedor',
                    'target_field' => ImportTargetField::SupplierDocument->value,
                    'default_value' => null,
                ],
                [
                    'source_column' => 'centro_custo',
                    'target_field' => ImportTargetField::CostCenterCode->value,
                    'default_value' => null,
                ],
                [
                    'source_column' => 'valor',
                    'target_field' => ImportTargetField::GrossAmount->value,
                    'default_value' => null,
                ],
                [
                    'source_column' => 'vencimento',
                    'target_field' => ImportTargetField::DueDate->value,
                    'default_value' => null,
                ],
                [
                    'source_column' => null,
                    'target_field' => ImportTargetField::PaymentMethod->value,
                    'default_value' => 'deposit',
                ],
                [
                    'source_column' => null,
                    'target_field' => ImportTargetField::DepositType->value,
                    'default_value' => 'pix',
                ],
                [
                    'source_column' => null,
                    'target_field' => ImportTargetField::PixKeyType->value,
                    'default_value' => 'email',
                ],
                [
                    'source_column' => null,
                    'target_field' => ImportTargetField::PixKey->value,
                    'default_value' => 'pix@example.com',
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ImportTemplate::query()->where('branch_id', $branch->getKey())->exists())->toBeTrue();
});

it('validates required fields on create', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(CreateImportTemplate::class)
        ->fillForm([
            'name' => '',
            'accepted_format' => null,
            'mappings' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['name', 'accepted_format', 'mappings']);
});

it('shows publish version action for adm on view page', function (): void {
    actingAs(User::factory()->adm()->create());
    $template = ImportTemplate::factory()->create();

    Livewire::test(ViewImportTemplate::class, ['record' => $template->getKey()])
        ->assertActionVisible('publishVersion');
});

it('forbids operador from template list via livewire', function (): void {
    actingAs(User::factory()->operador()->create());

    Livewire::test(ListImportTemplates::class)->assertForbidden();
});

it('forbids cliente from template list via livewire', function (): void {
    actingAs(User::factory()->cliente()->create());

    Livewire::test(ListImportTemplates::class)->assertForbidden();
});

it('hides template resource from operador via policy', function (): void {
    $operador = User::factory()->operador()->create();
    $policy = new ImportTemplatePolicy;

    expect($policy->viewAny($operador))->toBeFalse();
});

it('hides template resource from cliente', function (): void {
    $cliente = User::factory()->cliente()->create();
    $policy = new ImportTemplatePolicy;

    expect($policy->viewAny($cliente))->toBeFalse();
});

it('adm can update templates', function (): void {
    $adm = User::factory()->adm()->create();
    $template = ImportTemplate::factory()->create();
    $policy = new ImportTemplatePolicy;

    expect($policy->update($adm, $template))->toBeTrue()
        ->and($policy->create($adm))->toBeTrue();
});
