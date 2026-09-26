<?php

declare(strict_types=1);

use App\Exceptions\ImportException;
use App\Services\ImportBatchService;
use Tests\TestCase;

uses(TestCase::class);

it('parses brazilian and english money formats', function (string $input, string $expected): void {
    $service = app(ImportBatchService::class);

    expect($service->parseMoney($input))->toBe($expected);
})->with([
    ['1.234,56', '1234.56'],
    ['1234.56', '1234.56'],
    ['100', '100.00'],
    ['', '0.00'],
]);

it('throws invalid amount for non-numeric money, not invalid document', function (): void {
    $service = app(ImportBatchService::class);

    $exception = null;

    try {
        $service->parseMoney('1.2.3');
    } catch (ImportException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ImportException::class)
        ->and($exception->getUserMessage())->toBe(__('import_batches.errors.invalid_amount', ['value' => '1.2.3']))
        ->and($exception->getUserMessage())->not->toContain('CPF')
        ->and($exception->getUserMessage())->not->toContain('CNPJ');
});

it('parses dates in d/m/Y and ISO formats', function (): void {
    $service = app(ImportBatchService::class);

    expect($service->parseDate('25/12/2026')->toDateString())->toBe('2026-12-25')
        ->and($service->parseDate('2026-12-25')->toDateString())->toBe('2026-12-25');
});
