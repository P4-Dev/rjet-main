<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_numeric($value)) {
            $fail(__('errors.invalid_cnpj'));

            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if (! $this->isValid($digits)) {
            $fail(__('errors.invalid_cnpj'));
        }
    }

    private function isValid(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14) {
            return false;
        }

        // Rejeita sequências repetidas (ex.: 00000000000000).
        if (preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        return $this->checkDigit($cnpj, 12) === (int) $cnpj[12]
            && $this->checkDigit($cnpj, 13) === (int) $cnpj[13];
    }

    private function checkDigit(string $cnpj, int $length): int
    {
        $sum = 0;
        $weight = $length - 7;

        for ($i = 0; $i < $length; $i++) {
            $sum += (int) $cnpj[$i] * $weight;
            $weight = $weight === 2 ? 9 : $weight - 1;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}
