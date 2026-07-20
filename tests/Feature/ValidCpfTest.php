<?php

declare(strict_types=1);

use App\Rules\ValidCpf;
use Illuminate\Support\Facades\Validator;

function validateCpf(mixed $value): bool
{
    return Validator::make(
        ['document' => $value],
        ['document' => [new ValidCpf]],
    )->passes();
}

it('accepts a valid CPF', function (): void {
    expect(validateCpf(fake()->cpf(false)))->toBeTrue()
        ->and(validateCpf(fake()->cpf(true)))->toBeTrue();
});

it('rejects invalid CPFs', function (mixed $value): void {
    expect(validateCpf($value))->toBeFalse();
})->with([
    'too short' => '123',
    'repeated digits' => '11111111111',
    'wrong check digit' => '12345678900',
    'letters' => 'abcdefghijk',
]);
