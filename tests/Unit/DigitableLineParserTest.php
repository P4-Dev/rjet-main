<?php

declare(strict_types=1);

use App\Rules\ValidDigitableLine;
use Database\Factories\PaymentRequestFactory;
use Tests\TestCase;

uses(TestCase::class);

it('accepts a valid 47-digit digitable line', function (): void {
    expect(ValidDigitableLine::isValid(PaymentRequestFactory::VALID_DIGITABLE_LINE))->toBeTrue();
});

it('accepts a masked digitable line', function (): void {
    $line = PaymentRequestFactory::VALID_DIGITABLE_LINE;
    $masked = substr($line, 0, 5).'.'.substr($line, 5, 5).' '
        .substr($line, 10, 5).'.'.substr($line, 15, 6).' '
        .substr($line, 21, 5).'.'.substr($line, 26, 6).' '
        .substr($line, 32, 1).' '.substr($line, 33);

    expect(ValidDigitableLine::isValid($masked))->toBeTrue();
});

it('rejects an invalid check digit', function (): void {
    $line = PaymentRequestFactory::VALID_DIGITABLE_LINE;
    $invalid = substr($line, 0, 9).((int) $line[9] === 0 ? '1' : '0').substr($line, 10);

    expect(ValidDigitableLine::isValid($invalid))->toBeFalse();
});

it('rejects unexpected lengths', function (): void {
    expect(ValidDigitableLine::isValid('123'))->toBeFalse()
        ->and(ValidDigitableLine::isValid(str_repeat('1', 46)))->toBeFalse();
});

it('converts digitable line to barcode with factor and amount', function (): void {
    $barcode = ValidDigitableLine::toBarcode(PaymentRequestFactory::VALID_DIGITABLE_LINE);

    expect($barcode)->not->toBeNull()
        ->and(strlen($barcode))->toBe(44)
        ->and(substr($barcode, 5, 4))->toBe('1000')
        ->and(substr($barcode, 9, 10))->toBe('0000012345');
});
