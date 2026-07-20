<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PaymentMethod;
use App\Enums\PersonType;

final readonly class SupplierData
{
    public function __construct(
        public PersonType $personType,
        public string $document,
        public string $name,
        public PaymentMethod $defaultPaymentMethod,
        public ?string $legalName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $notes = null,
        public bool $isActive = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            personType: $data['person_type'] instanceof PersonType
                ? $data['person_type']
                : PersonType::from((string) $data['person_type']),
            document: preg_replace('/\D/', '', (string) $data['document']) ?? '',
            name: (string) $data['name'],
            defaultPaymentMethod: $data['default_payment_method'] instanceof PaymentMethod
                ? $data['default_payment_method']
                : PaymentMethod::from((string) $data['default_payment_method']),
            legalName: isset($data['legal_name']) ? (string) $data['legal_name'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'person_type' => $this->personType->value,
            'document' => $this->document,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'email' => $this->email,
            'phone' => $this->phone,
            'default_payment_method' => $this->defaultPaymentMethod->value,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
        ];
    }
}
