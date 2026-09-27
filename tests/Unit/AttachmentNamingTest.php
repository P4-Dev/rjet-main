<?php

declare(strict_types=1);

use App\Services\AttachmentBatchNamingService;
use App\Services\AttachmentService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

it('maps accepted mime types to extensions', function (string $mime, string $extension): void {
    expect((new AttachmentService)->extensionFor($mime, 'ignored.xyz'))->toBe($extension);
})->with([
    ['application/pdf', 'pdf'],
    ['image/jpeg', 'jpg'],
    ['image/png', 'png'],
    ['image/webp', 'webp'],
]);

it('falls back to the lowercased original extension for unknown mime types', function (): void {
    $service = new AttachmentService;

    expect($service->extensionFor('application/octet-stream', 'Scan.TIFF'))->toBe('tiff')
        ->and($service->extensionFor('application/octet-stream', 'no-extension'))->toBe('bin');
});

it('formats the base name in the Sao Paulo timezone with a 3-digit sequence', function (): void {
    $generatedAt = CarbonImmutable::parse('2026-09-26 13:05:09', 'UTC');

    expect(AttachmentBatchNamingService::formatBaseName($generatedAt, 7, 12, 'America/Sao_Paulo'))
        ->toBe('20260926_100509_007');
});

it('widens the sequence padding beyond 999 items', function (): void {
    $generatedAt = CarbonImmutable::parse('2026-01-02 03:04:05', 'America/Sao_Paulo');

    expect(AttachmentBatchNamingService::formatBaseName($generatedAt, 42, 1200, 'America/Sao_Paulo'))
        ->toBe('20260102_030405_0042');
});
