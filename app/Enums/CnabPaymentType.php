<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\PaymentRequest;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CnabPaymentType: string implements HasColor, HasIcon, HasLabel
{
    case Boleto = 'boleto';
    case Transfer = 'transfer';
    case PixKey = 'pix_key';

    public function getLabel(): string
    {
        return match ($this) {
            self::Boleto => __('enums.cnab_payment_type.boleto'),
            self::Transfer => __('enums.cnab_payment_type.transfer'),
            self::PixKey => __('enums.cnab_payment_type.pix_key'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Boleto => 'info',
            self::Transfer => 'primary',
            self::PixKey => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Boleto => 'heroicon-o-document-text',
            self::Transfer => 'heroicon-o-arrows-right-left',
            self::PixKey => 'heroicon-o-bolt',
        };
    }

    /**
     * Null means the payment request cannot be paid through CNAB (e.g. PIX by QR code only).
     */
    public static function fromPaymentRequest(PaymentRequest $paymentRequest): ?self
    {
        if ($paymentRequest->payment_method === PaymentMethod::Boleto) {
            return self::Boleto;
        }

        $details = $paymentRequest->bankDetails;

        if ($details === null) {
            return null;
        }

        return match ($details->deposit_type) {
            DepositType::Transfer => self::Transfer,
            DepositType::Pix => $details->hasPixKey() ? self::PixKey : null,
            default => null,
        };
    }

    /**
     * Best-effort type used to record rejected items, which still need a non-null payment type.
     */
    public static function intendedFor(PaymentRequest $paymentRequest): self
    {
        if ($paymentRequest->payment_method === PaymentMethod::Boleto) {
            return self::Boleto;
        }

        return $paymentRequest->bankDetails?->deposit_type === DepositType::Transfer
            ? self::Transfer
            : self::PixKey;
    }
}
