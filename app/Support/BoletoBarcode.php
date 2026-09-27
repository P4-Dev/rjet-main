<?php

declare(strict_types=1);

namespace App\Support;

use App\Rules\ValidDigitableLine;
use InvalidArgumentException;

final class BoletoBarcode
{
    /**
     * Converts a 47-digit bank slip digitable line into the 44-digit barcode.
     *
     * @throws InvalidArgumentException
     */
    public static function fromDigitableLine(string $line): string
    {
        $barcode = ValidDigitableLine::toBarcode($line);

        if ($barcode === null) {
            throw new InvalidArgumentException('Invalid bank slip digitable line.');
        }

        return $barcode;
    }

    /**
     * Collection slips (utilities/taxes) start with 8 and have 44 (barcode) or 48 (digitable line) digits.
     */
    public static function isUtilityBill(string $code): bool
    {
        $digits = self::digits($code);

        return str_starts_with($digits, '8') && in_array(strlen($digits), [44, 48], true);
    }

    public static function isValid(string $barcode): bool
    {
        $digits = self::digits($barcode);

        if (strlen($digits) !== 44 || self::isUtilityBill($digits)) {
            return false;
        }

        $withoutDv = substr($digits, 0, 4).substr($digits, 5);

        return self::mod11($withoutDv) === (int) $digits[4];
    }

    public static function bankCode(string $barcode): string
    {
        return substr(self::digits($barcode), 0, 3);
    }

    public static function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    private static function mod11(string $number): int
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum += ((int) $number[$i]) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $dv = 11 - ($sum % 11);

        return in_array($dv, [0, 10, 11], true) ? 1 : $dv;
    }
}
