<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\ContactsRelationManager;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('renders the supplier view page without lazy loading company on payment overrides', function (): void {
    actingAs(User::factory()->adm()->create());

    $company = Company::factory()->create();
    $supplier = Supplier::factory()->pj()->create();

    SupplierCompanyPaymentMethod::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'company_id' => $company->getKey(),
        'payment_method' => PaymentMethod::Deposit,
    ]);

    $this->get(route('filament.admin.resources.suppliers.view', ['record' => $supplier]))
        ->assertOk()
        ->assertSee($company->name);
});

it('keeps supplier view stable when opening addresses and contacts relation managers', function (): void {
    actingAs(User::factory()->adm()->create());

    $company = Company::factory()->create();
    $supplier = Supplier::factory()->pj()->create();

    SupplierCompanyPaymentMethod::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'company_id' => $company->getKey(),
        'payment_method' => PaymentMethod::Deposit,
    ]);

    Livewire::test(ViewSupplier::class, ['record' => $supplier->getKey()])
        ->assertOk()
        ->assertSee($company->name)
        ->set('activeRelationManager', AddressesRelationManager::class)
        ->assertOk()
        ->assertSee($company->name)
        ->set('activeRelationManager', ContactsRelationManager::class)
        ->assertOk()
        ->assertSee($company->name);
});
