<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PersonType;
use App\Enums\PixKeyType;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\ContactsRelationManager;
use App\Models\Bank;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function supplierCreateFormData(array $overrides = []): array
{
    return array_replace_recursive([
        'person_type' => PersonType::Pj->value,
        'document' => fake()->unique()->cnpj(false),
        'name' => 'Fornecedor Teste',
        'legal_name' => 'Fornecedor Teste LTDA',
        'default_payment_method' => PaymentMethod::Deposit->value,
        'is_active' => true,
        'bankDetails' => [
            'deposit_type' => DepositType::Pix->value,
            'pix_key_type' => PixKeyType::Email->value,
            'pix_key' => 'pix@exemplo.com',
        ],
    ], $overrides);
}

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

it('hides pix and transfer fields when the default payment method is boleto', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(CreateSupplier::class)
        ->fillForm(supplierCreateFormData([
            'default_payment_method' => PaymentMethod::Boleto->value,
            'bankDetails' => [
                'deposit_type' => null,
                'pix_key_type' => null,
                'pix_key' => null,
            ],
        ]))
        ->assertFormFieldIsHidden('bankDetails.deposit_type')
        ->assertFormFieldIsHidden('bankDetails.pix_key_type')
        ->assertFormFieldIsHidden('bankDetails.pix_key')
        ->assertFormFieldIsHidden('bankDetails.holder_document')
        ->assertFormFieldIsHidden('bankDetails.bank_id');
});

it('requires deposit_type for deposit and hides pix and transfer fields until it is chosen', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(CreateSupplier::class)
        ->fillForm(supplierCreateFormData([
            'bankDetails' => [
                'deposit_type' => null,
                'pix_key_type' => null,
                'pix_key' => null,
            ],
        ]))
        ->assertFormFieldIsVisible('bankDetails.deposit_type')
        ->assertFormFieldIsHidden('bankDetails.pix_key_type')
        ->assertFormFieldIsHidden('bankDetails.pix_key')
        ->assertFormFieldIsHidden('bankDetails.holder_document')
        ->assertFormFieldIsHidden('bankDetails.bank_id')
        ->call('create')
        ->assertHasFormErrors(['bankDetails.deposit_type' => 'required']);
});

it('shows pix fields and hides transfer fields for deposit + pix', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(CreateSupplier::class)
        ->fillForm(supplierCreateFormData())
        ->assertFormFieldIsVisible('bankDetails.pix_key_type')
        ->assertFormFieldIsVisible('bankDetails.pix_key')
        ->assertFormFieldIsHidden('bankDetails.holder_document')
        ->assertFormFieldIsHidden('bankDetails.bank_id');
});

it('requires pix key type and key for deposit + pix', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(CreateSupplier::class)
        ->fillForm(supplierCreateFormData([
            'bankDetails' => [
                'deposit_type' => DepositType::Pix->value,
                'pix_key_type' => null,
                'pix_key' => null,
            ],
        ]))
        ->call('create')
        ->assertHasFormErrors([
            'bankDetails.pix_key_type' => 'required',
            'bankDetails.pix_key' => 'required',
        ]);
});

it('persists pix key in the format required by the selected type', function (): void {
    actingAs(User::factory()->adm()->create());
    $cpf = fake()->cpf(false);
    $document = fake()->unique()->cnpj(false);

    Livewire::test(CreateSupplier::class)
        ->fillForm(supplierCreateFormData([
            'document' => $document,
            'bankDetails' => [
                'deposit_type' => DepositType::Pix->value,
                'pix_key_type' => PixKeyType::Cpf->value,
                'pix_key' => vsprintf('%s.%s.%s-%s', [
                    substr($cpf, 0, 3),
                    substr($cpf, 3, 3),
                    substr($cpf, 6, 3),
                    substr($cpf, 9, 2),
                ]),
            ],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $supplier = Supplier::query()->with('bankDetails')->where('document', $document)->first();

    expect($supplier)->not->toBeNull()
        ->and($supplier->default_payment_method)->toBe(PaymentMethod::Deposit)
        ->and($supplier->bankDetails?->deposit_type)->toBe(DepositType::Pix)
        ->and($supplier->bankDetails?->pix_key_type)->toBe(PixKeyType::Cpf)
        ->and($supplier->bankDetails?->pix_key)->toBe($cpf);
});

it('shows transfer fields and persists bank agency and account for deposit + ted', function (): void {
    actingAs(User::factory()->adm()->create());
    $bank = Bank::factory()->create();
    $document = fake()->unique()->cnpj(false);
    $holderDocument = fake()->cnpj(false);

    Livewire::test(CreateSupplier::class)
        ->fillForm(supplierCreateFormData([
            'document' => $document,
            'bankDetails' => [
                'deposit_type' => DepositType::Transfer->value,
                'pix_key_type' => null,
                'pix_key' => null,
                'holder_document' => $holderDocument,
                'holder_name' => 'Favorecido Teste',
                'bank_id' => $bank->getKey(),
                'agency' => '1234',
                'agency_digit' => '0',
                'account_number' => '123456',
                'account_digit' => '7',
                'account_type' => AccountType::Checking->value,
            ],
        ]))
        ->assertFormFieldIsVisible('bankDetails.holder_document')
        ->assertFormFieldIsVisible('bankDetails.bank_id')
        ->assertFormFieldIsVisible('bankDetails.agency')
        ->assertFormFieldIsVisible('bankDetails.account_number')
        ->assertFormFieldIsVisible('bankDetails.account_digit')
        ->assertFormFieldIsVisible('bankDetails.account_type')
        ->assertFormFieldIsHidden('bankDetails.pix_key_type')
        ->assertFormFieldIsHidden('bankDetails.pix_key')
        ->call('create')
        ->assertHasNoFormErrors();

    $supplier = Supplier::query()->with('bankDetails')->where('document', $document)->first();

    expect($supplier?->bankDetails)->not->toBeNull()
        ->and($supplier->bankDetails->deposit_type)->toBe(DepositType::Transfer)
        ->and($supplier->bankDetails->bank_id)->toBe($bank->getKey())
        ->and($supplier->bankDetails->agency)->toBe('1234')
        ->and($supplier->bankDetails->account_number)->toBe('123456')
        ->and($supplier->bankDetails->account_digit)->toBe('7')
        ->and($supplier->bankDetails->account_type)->toBe(AccountType::Checking)
        ->and($supplier->bankDetails->holder_document)->toBe($holderDocument);
});

it('renders pix bank details on the supplier view page', function (): void {
    actingAs(User::factory()->adm()->create());
    $supplier = Supplier::factory()->withPixDetails()->create();
    $pixKey = $supplier->load('bankDetails')->bankDetails->pix_key;

    $this->get(route('filament.admin.resources.suppliers.view', ['record' => $supplier]))
        ->assertOk()
        ->assertSee($pixKey);
});
