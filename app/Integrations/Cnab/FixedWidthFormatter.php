<?php

declare(strict_types=1);

namespace App\Integrations\Cnab;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use LogicException;

final class FixedWidthFormatter
{
    /**
     * Digits only, zero-padded on the left. Overflow is a programming error: adapters must
     * reject oversized values during validation, never truncate them into another number.
     *
     * @throws LogicException
     */
    public static function numeric(string|int|null $value, int $length): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        if (strlen($digits) > $length) {
            throw new LogicException('CNAB numeric value has '.strlen($digits)." digits, field allows {$length}.");
        }

        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    /**
     * Uppercase printable ASCII, space-padded on the right and truncated to the length.
     */
    public static function alpha(?string $value, int $length): string
    {
        $ascii = Str::upper(Str::ascii((string) $value));
        $clean = preg_replace('/[^\x20-\x7E]/', ' ', $ascii) ?? '';

        return str_pad(substr($clean, 0, $length), $length, ' ', STR_PAD_RIGHT);
    }

    public static function date(?CarbonInterface $date): string
    {
        return $date === null ? str_repeat('0', 8) : $date->format('dmY');
    }

    public static function time(CarbonInterface $date): string
    {
        return $date->format('His');
    }

    public static function amountInCents(string $decimal, int $length): string
    {
        return self::numeric(bcmul($decimal, '100', 0), $length);
    }

    public static function blank(int $length): string
    {
        return str_repeat(' ', $length);
    }

    public static function documentType(?string $document): string
    {
        return match (strlen(preg_replace('/\D/', '', (string) $document) ?? '')) {
            11 => '1',
            14 => '2',
            default => '0',
        };
    }
}
