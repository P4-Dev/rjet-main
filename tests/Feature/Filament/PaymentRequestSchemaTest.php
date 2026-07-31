<?php

declare(strict_types=1);

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\PaymentRequests\Pages\CreatePaymentRequest;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Models\Company;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('marks net_amount as read only on the create form', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(CreatePaymentRequest::class)
        ->assertSchemaComponentExists('net_amount', checkComponentUsing: function (TextInput $component): bool {
            return $component->isReadOnly();
        })
        ->assertFormFieldIsReadOnly('net_amount');
});

it('marks net_amount table column as sortable money', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(ListPaymentRequests::class)
        ->assertTableColumnExists('net_amount', function (TextColumn $column): bool {
            return $column->isSortable() && $column->isMoney();
        });
});

it('shows is_appropriation_required toggle for adm on company form', function (): void {
    actingAs(User::factory()->adm()->create());

    $company = Company::factory()->create([
        'is_appropriation_required' => false,
    ]);

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])
        ->assertFormFieldIsVisible('is_appropriation_required');
});

it('lets adm persist is_appropriation_required as true', function (): void {
    actingAs(User::factory()->adm()->create());

    $company = Company::factory()->create([
        'is_appropriation_required' => false,
    ]);

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])
        ->fillForm([
            'is_appropriation_required' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()->is_appropriation_required)->toBeTrue();
});
