<?php

declare(strict_types=1);

use App\Support\BoletoBarcode;

it('converts a 47-digit digitable line into the 44-digit barcode', function (): void {
    expect(BoletoBarcode::fromDigitableLine('23791.23454 67890.123457 67890.123457 1 10000000012345'))
        ->toBe('23791100000000123451234567890123456789012345');
});

it('rejects a digitable line with invalid check digits', function (): void {
    BoletoBarcode::fromDigitableLine('23791234556789012345767890123457110000000012345');
})->throws(InvalidArgumentException::class);

it('validates the barcode general check digit', function (string $barcode, bool $valid): void {
    expect(BoletoBarcode::isValid($barcode))->toBe($valid);
})->with([
    'bradesco' => ['23791100000000123451234567890123456789012345', true],
    'itau' => ['34198999900001500001571234567890123456789012', true],
    'wrong check digit' => ['23792100000000123451234567890123456789012345', false],
    'too short' => ['2379110000000012345', false],
]);

it('detects utility bills', function (string $code, bool $isUtility): void {
    expect(BoletoBarcode::isUtilityBill($code))->toBe($isUtility);
})->with([
    '48-digit utility line' => ['836200000005667800481000180975657313001589636081', true],
    '44-digit utility barcode' => ['83620000000667800481001809756573100158963608', true],
    'bank slip' => ['23791100000000123451234567890123456789012345', false],
]);

it('rejects utility barcodes as bank slips', function (): void {
    expect(BoletoBarcode::isValid('83620000000667800481001809756573100158963608'))->toBeFalse();
});

it('extracts the issuing bank code', function (): void {
    expect(BoletoBarcode::bankCode('34198999900001500001571234567890123456789012'))->toBe('341');
});
