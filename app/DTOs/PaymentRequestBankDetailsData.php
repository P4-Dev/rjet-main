<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PixKeyType;

final readonly class PaymentRequestBankDetailsData
{
    public function __construct(
        public ?DepositType $depositType = null,
        public ?PixKeyType $pixKeyType = null,
        public ?string $pixKey = null,
        public ?string $pixQrCode = null,
        public ?string $digitableLine = null,
        public ?string $barcode = null,
        public ?string $bankId = null,
        public ?string $agency = null,
        public ?string $agencyDigit = null,
        public ?string $accountNumber = null,
        public ?string $accountDigit = null,
        public ?AccountType $accountType = null,
        public ?string $holderName = null,
        public ?string $holderDocument = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $holderDocument = $data['holder_document'] ?? null;
        if ($holderDocument !== null) {
            $holderDocument = preg_replace('/\D/', '', (string) $holderDocument) ?: null;
        }

        return new self(
            depositType: self::enumOrNull($data['deposit_type'] ?? null, DepositType::class),
            pixKeyType: self::enumOrNull($data['pix_key_type'] ?? null, PixKeyType::class),
            pixKey: isset($data['pix_key']) ? ($data['pix_key'] !== null ? (string) $data['pix_key'] : null) : null,
            pixQrCode: isset($data['pix_qr_code']) ? ($data['pix_qr_code'] !== null ? (string) $data['pix_qr_code'] : null) : null,
            digitableLine: isset($data['digitable_line']) ? ($data['digitable_line'] !== null ? (string) $data['digitable_line'] : null) : null,
            barcode: isset($data['barcode']) ? ($data['barcode'] !== null ? (string) $data['barcode'] : null) : null,
            bankId: isset($data['bank_id']) ? ($data['bank_id'] !== null ? (string) $data['bank_id'] : null) : null,
            agency: isset($data['agency']) ? ($data['agency'] !== null ? (string) $data['agency'] : null) : null,
            agencyDigit: isset($data['agency_digit']) ? ($data['agency_digit'] !== null ? (string) $data['agency_digit'] : null) : null,
            accountNumber: isset($data['account_number']) ? ($data['account_number'] !== null ? (string) $data['account_number'] : null) : null,
            accountDigit: isset($data['account_digit']) ? ($data['account_digit'] !== null ? (string) $data['account_digit'] : null) : null,
            accountType: self::enumOrNull($data['account_type'] ?? null, AccountType::class),
            holderName: isset($data['holder_name']) ? ($data['holder_name'] !== null ? (string) $data['holder_name'] : null) : null,
            holderDocument: $holderDocument,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'deposit_type' => $this->depositType,
            'pix_key_type' => $this->pixKeyType,
            'pix_key' => $this->pixKey,
            'pix_qr_code' => $this->pixQrCode,
            'digitable_line' => $this->digitableLine,
            'barcode' => $this->barcode,
            'bank_id' => $this->bankId,
            'agency' => $this->agency,
            'agency_digit' => $this->agencyDigit,
            'account_number' => $this->accountNumber,
            'account_digit' => $this->accountDigit,
            'account_type' => $this->accountType,
            'holder_name' => $this->holderName,
            'holder_document' => $this->holderDocument,
        ];
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enumClass
     * @return T|null
     */
    private static function enumOrNull(mixed $value, string $enumClass): ?\BackedEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof $enumClass) {
            return $value;
        }

        return $enumClass::from((string) $value);
    }
}
