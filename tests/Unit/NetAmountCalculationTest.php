<?php

declare(strict_types=1);

use App\Exceptions\PaymentRequestException;
use App\Services\PaymentRequestService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->service = app(PaymentRequestService::class);
});

it('calculates net amount with bcmath precision', function (string $gross, string $discount, string $expected): void {
    expect($this->service->calculateNetAmount($gross, $discount))->toBe($expected);
})->with([
    ['1000.00', '0.00', '1000.00'],
    ['1000.00', '150.50', '849.50'],
    ['0.10', '0.01', '0.09'],
    ['1000.00', '1000.00', '0.00'],
]);

it('rejects discount greater than gross', function (): void {
    expect(fn () => $this->service->assertAmounts('100.00', '150.00'))
        ->toThrow(PaymentRequestException::class);
});

it('rejects non-positive gross amount', function (): void {
    expect(fn () => $this->service->assertAmounts('0.00', '0.00'))
        ->toThrow(PaymentRequestException::class);
});

it('rejects negative discount', function (): void {
    expect(fn () => $this->service->assertAmounts('100.00', '-1.00'))
        ->toThrow(PaymentRequestException::class);
});
