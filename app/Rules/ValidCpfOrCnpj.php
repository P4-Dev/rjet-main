<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

final class ValidCpfOrCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_numeric($value)) {
            $fail(__('validation.custom.holder_document.invalid'));

            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        $rule = match (strlen($digits)) {
            11 => new ValidCpf,
            14 => new ValidCnpj,
            default => null,
        };

        if ($rule === null) {
            $fail(__('validation.custom.holder_document.invalid'));

            return;
        }

        $validator = Validator::make(
            ['document' => $digits],
            ['document' => [$rule]],
        );

        if ($validator->fails()) {
            $fail(__('validation.custom.holder_document.invalid'));
        }
    }
}
