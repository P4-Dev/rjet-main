<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImportTargetField: string implements HasLabel
{
    case BranchDocument = 'branch_document';
    case SupplierDocument = 'supplier_document';
    case CostCenterCode = 'cost_center_code';
    case AppropriationCode = 'appropriation_code';
    case PaymentMethod = 'payment_method';
    case GrossAmount = 'gross_amount';
    case DiscountAmount = 'discount_amount';
    case DueDate = 'due_date';
    case Notes = 'notes';
    case DepositType = 'deposit_type';
    case DigitableLine = 'digitable_line';
    case PixKeyType = 'pix_key_type';
    case PixKey = 'pix_key';
    case PixQrCode = 'pix_qr_code';
    case HolderName = 'holder_name';
    case HolderDocument = 'holder_document';
    case BankCode = 'bank_code';
    case Agency = 'agency';
    case AgencyDigit = 'agency_digit';
    case AccountNumber = 'account_number';
    case AccountDigit = 'account_digit';
    case AccountType = 'account_type';

    public function getLabel(): ?string
    {
        return __('enums.import_target_field.'.$this->value);
    }

    /**
     * Fields that must have a source mapping or default value at publish time.
     *
     * @return list<self>
     */
    public static function requiredForPublish(?string $branchId, ?string $companyId = null): array
    {
        $required = [
            self::SupplierDocument,
            self::CostCenterCode,
            self::GrossAmount,
            self::DueDate,
        ];

        if (blank($branchId)) {
            $required[] = self::BranchDocument;
        }

        return $required;
    }

    public function isBankField(): bool
    {
        return in_array($this, [
            self::DepositType,
            self::DigitableLine,
            self::PixKeyType,
            self::PixKey,
            self::PixQrCode,
            self::HolderName,
            self::HolderDocument,
            self::BankCode,
            self::Agency,
            self::AgencyDigit,
            self::AccountNumber,
            self::AccountDigit,
            self::AccountType,
        ], true);
    }
}
