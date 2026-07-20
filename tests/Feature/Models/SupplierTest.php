<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PersonType;
use App\Exceptions\SupplierException;
use App\Models\Address;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use App\Services\SupplierService;
use App\DTOs\SupplierData;
use Illuminate\Database\QueryException;

it('enforces partial unique document on suppliers', function (): void {
    $document = fake()->unique()->cnpj(false);

    Supplier::factory()->pj()->create(['document' => $document]);

    expect(fn () => Supplier::factory()->pj()->create(['document' => $document]))
        ->toThrow(QueryException::class);
});

it('allows reusing a soft-deleted supplier document', function (): void {
    $document = fake()->unique()->cnpj(false);

    $supplier = Supplier::factory()->pj()->create(['document' => $document]);
    $supplier->delete();

    $recreated = Supplier::factory()->pj()->create(['document' => $document]);

    expect($recreated->document)->toBe($document)
        ->and($recreated->trashed())->toBeFalse();
});

it('resolves paymentMethodFor with company override fallback', function (): void {
    $company = Company::factory()->create();
    $supplier = Supplier::factory()->pj()->create([
        'default_payment_method' => PaymentMethod::Boleto,
    ]);

    expect($supplier->paymentMethodFor($company))->toBe(PaymentMethod::Boleto);

    SupplierCompanyPaymentMethod::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'company_id' => $company->getKey(),
        'payment_method' => PaymentMethod::Deposit,
    ]);

    expect($supplier->fresh()->paymentMethodFor($company))->toBe(PaymentMethod::Deposit);
});

it('rejects person type and document mismatch in SupplierService', function (): void {
    $service = app(SupplierService::class);

    $data = SupplierData::fromArray([
        'person_type' => PersonType::Pf->value,
        'document' => fake()->cnpj(false),
        'name' => 'Invalid PF',
        'default_payment_method' => PaymentMethod::Boleto->value,
    ]);

    expect(fn () => $service->create($data))->toThrow(SupplierException::class);
});

it('cascades soft delete of supplier to overrides addresses and contacts', function (): void {
    $supplier = Supplier::factory()->pj()->create();
    $company = Company::factory()->create();

    $override = SupplierCompanyPaymentMethod::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'company_id' => $company->getKey(),
    ]);

    $address = Address::factory()->create([
        'addressable_type' => 'supplier',
        'addressable_id' => $supplier->getKey(),
    ]);

    $contact = Contact::factory()->create([
        'contactable_type' => 'supplier',
        'contactable_id' => $supplier->getKey(),
    ]);

    $supplier->delete();

    expect($override->fresh()->trashed())->toBeTrue()
        ->and($address->fresh()->trashed())->toBeTrue()
        ->and($contact->fresh()->trashed())->toBeTrue();
});

it('restores supplier children deleted by cascade', function (): void {
    $supplier = Supplier::factory()->pj()->create();
    $company = Company::factory()->create();

    $override = SupplierCompanyPaymentMethod::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'company_id' => $company->getKey(),
    ]);

    $supplier->delete();
    $supplier->restore();

    expect($override->fresh()->trashed())->toBeFalse();
});
