<?php

declare(strict_types=1);

use App\Rules\ValidCnpj;
use Illuminate\Support\Facades\Validator;

function validateCnpj(mixed $value): bool
{
    return Validator::make(
        ['document' => $value],
        ['document' => [new ValidCnpj]],
    )->passes();
}

it('accepts a valid CNPJ', function (): void {
    expect(validateCnpj(fake()->cnpj(false)))->toBeTrue()
        ->and(validateCnpj(fake()->cnpj(true)))->toBeTrue();
});

it('rejects invalid CNPJs', function (mixed $value): void {
    expect(validateCnpj($value))->toBeFalse();
})->with([
    'too short' => '123',
    'repeated digits' => '11111111111111',
    'wrong check digit' => '11222333000180',
    'letters' => 'abcdefghijklmn',
]);
