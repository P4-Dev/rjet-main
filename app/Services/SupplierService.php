<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\SupplierData;
use App\Enums\PersonType;
use App\Exceptions\SupplierException;
use App\Models\Supplier;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SupplierService
{
    /**
     * @throws SupplierException
     * @throws ValidationException
     */
    public function create(SupplierData $data): Supplier
    {
        $this->assertDocumentMatchesPersonType($data->personType, $data->document);
        $this->assertDocumentIsUnique($data->document);

        return DB::transaction(fn (): Supplier => Supplier::query()->create($data->toArray()));
    }

    /**
     * @throws SupplierException
     * @throws ValidationException
     */
    public function update(Supplier $supplier, SupplierData $data): Supplier
    {
        $this->assertDocumentMatchesPersonType($data->personType, $data->document);
        $this->assertDocumentIsUnique($data->document, (string) $supplier->getKey());

        return DB::transaction(function () use ($supplier, $data): Supplier {
            $supplier->update($data->toArray());

            return $supplier->refresh();
        });
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }

    /**
     * @throws SupplierException
     * @throws ValidationException
     */
    private function assertDocumentMatchesPersonType(PersonType $personType, string $document): void
    {
        $rule = $personType === PersonType::Pf ? new ValidCpf : new ValidCnpj;

        $validator = Validator::make(
            ['document' => $document],
            ['document' => [$rule]],
        );

        if ($validator->fails()) {
            throw SupplierException::documentInconsistentWithPersonType();
        }
    }

    /**
     * @throws SupplierException
     */
    private function assertDocumentIsUnique(string $document, ?string $ignoreId = null): void
    {
        $exists = Supplier::query()
            ->where('document', $document)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw SupplierException::duplicateDocument($document);
        }
    }
}
