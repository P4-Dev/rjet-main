<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidDigitableLine implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_numeric($value)) {
            $fail(__('validation.custom.digitable_line.invalid'));

            return;
        }

        if (! self::isValid((string) $value)) {
            $fail(__('validation.custom.digitable_line.invalid'));
        }
    }

    public static function isValid(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return match (strlen($digits)) {
            47 => self::isValidBankSlip($digits),
            48 => self::isValidCollectionSlip($digits),
            default => false,
        };
    }

    public static function toBarcode(string $digitableLine): ?string
    {
        $digits = preg_replace('/\D/', '', $digitableLine) ?? '';

        if (strlen($digits) !== 47 || ! self::isValidBankSlip($digits)) {
            return null;
        }

        return substr($digits, 0, 4)
            .substr($digits, 32, 1)
            .substr($digits, 33, 14)
            .substr($digits, 4, 5)
            .substr($digits, 10, 10)
            .substr($digits, 21, 10);
    }

    private static function isValidBankSlip(string $digits): bool
    {
        $field1 = substr($digits, 0, 9);
        $dv1 = (int) $digits[9];
        $field2 = substr($digits, 10, 10);
        $dv2 = (int) $digits[20];
        $field3 = substr($digits, 21, 10);
        $dv3 = (int) $digits[31];
        $generalDv = (int) $digits[32];

        if (
            self::mod10($field1) !== $dv1
            || self::mod10($field2) !== $dv2
            || self::mod10($field3) !== $dv3
        ) {
            return false;
        }

        $barcodeWithoutDv = substr($digits, 0, 4)
            .substr($digits, 33, 14)
            .substr($digits, 4, 5)
            .substr($digits, 10, 10)
            .substr($digits, 21, 10);

        return self::mod11Barcode($barcodeWithoutDv) === $generalDv;
    }

    private static function isValidCollectionSlip(string $digits): bool
    {
        $blocks = [
            substr($digits, 0, 11),
            substr($digits, 12, 11),
            substr($digits, 24, 11),
            substr($digits, 36, 11),
        ];
        $dvs = [
            (int) $digits[11],
            (int) $digits[23],
            (int) $digits[35],
            (int) $digits[47],
        ];

        $productId = (int) $digits[2];
        $useMod10 = in_array($productId, [6, 7], true);

        foreach ($blocks as $index => $block) {
            $expected = $useMod10 ? self::mod10($block) : self::mod11Collection($block);

            if ($expected !== $dvs[$index]) {
                return false;
            }
        }

        return true;
    }

    private static function mod10(string $number): int
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $product = ((int) $number[$i]) * $weight;

            if ($product > 9) {
                $product = intdiv($product, 10) + ($product % 10);
            }

            $sum += $product;
            $weight = $weight === 2 ? 1 : 2;
        }

        $remainder = $sum % 10;

        return $remainder === 0 ? 0 : 10 - $remainder;
    }

    private static function mod11Barcode(string $number): int
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum += ((int) $number[$i]) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $remainder = $sum % 11;
        $dv = 11 - $remainder;

        if ($dv === 0 || $dv === 10 || $dv === 11) {
            return 1;
        }

        return $dv;
    }

    private static function mod11Collection(string $number): int
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum += ((int) $number[$i]) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $remainder = $sum % 11;

        if ($remainder === 0 || $remainder === 1) {
            return 0;
        }

        if ($remainder === 10) {
            return 1;
        }

        return 11 - $remainder;
    }
}
