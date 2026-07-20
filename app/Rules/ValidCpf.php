<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidCpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_numeric($value)) {
            $fail(__('errors.invalid_cpf'));

            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if (! $this->isValid($digits)) {
            $fail(__('errors.invalid_cpf'));
        }
    }

    private function isValid(string $cpf): bool
    {
        if (strlen($cpf) !== 11) {
            return false;
        }

        // Reject repeated sequences (e.g. 00000000000).
        if (preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        return $this->checkDigit($cpf, 9) === (int) $cpf[9]
            && $this->checkDigit($cpf, 10) === (int) $cpf[10];
    }

    private function checkDigit(string $cpf, int $length): int
    {
        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $sum += (int) $cpf[$i] * ($length + 1 - $i);
        }

        $remainder = ($sum * 10) % 11;

        return $remainder === 10 ? 0 : $remainder;
    }
}
