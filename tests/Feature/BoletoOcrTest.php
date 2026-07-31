<?php

declare(strict_types=1);

use App\Actions\PaymentRequest\ExtractBoletoDataAction;
use App\DTOs\BoletoOcrResult;
use App\Integrations\Ocr\BoletoOcrClient;
use App\Integrations\Ocr\LocalBoletoOcrClient;
use App\Integrations\Ocr\NullBoletoOcrClient;
use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestService;
use Database\Factories\PaymentRequestFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('uses null client when ocr is disabled', function (): void {
    config(['rjet.ocr.enabled' => false, 'rjet.ocr.driver' => 'null']);
    $this->app->bind(BoletoOcrClient::class, fn () => new NullBoletoOcrClient);

    $file = UploadedFile::fake()->create('boleto.pdf', 10, 'application/pdf');
    $result = app(ExtractBoletoDataAction::class)->fromUploadedFile($file);

    expect($result->wasSuccessful)->toBeFalse();
});

it('returns failed for images without throwing', function (): void {
    $client = new LocalBoletoOcrClient;
    $result = $client->extract('/tmp/fake.png', 'image/png');

    expect($result->wasSuccessful)->toBeFalse()
        ->and($result->message)->not->toBeEmpty();
});

it('does not overwrite filled fields when applying ocr result', function (): void {
    $request = PaymentRequest::factory()->boleto()->create([
        'gross_amount' => '999.00',
        'discount_amount' => '0.00',
        'net_amount' => '999.00',
        'due_date' => '2030-01-15',
    ]);

    $request->bankDetails->update([
        'digitable_line' => PaymentRequestFactory::VALID_DIGITABLE_LINE,
    ]);

    $result = BoletoOcrResult::success(
        digitableLine: PaymentRequestFactory::VALID_DIGITABLE_LINE,
        amount: '10.00',
        dueDate: now()->toImmutable()->addDays(3),
    );

    $updated = app(PaymentRequestService::class)->applyOcrResult($request, $result);

    expect($updated->gross_amount)->toBe('999.00')
        ->and($updated->due_date->toDateString())->toBe('2030-01-15')
        ->and($updated->bankDetails->digitable_line)->toBe(PaymentRequestFactory::VALID_DIGITABLE_LINE);
});

it('captures parser exceptions inside ExtractBoletoDataAction', function (): void {
    $this->app->bind(BoletoOcrClient::class, fn () => new class implements BoletoOcrClient
    {
        public function extract(string $absolutePath, string $mimeType): BoletoOcrResult
        {
            throw new RuntimeException('boom');
        }
    });

    config(['rjet.ocr.enabled' => true]);

    $file = UploadedFile::fake()->create('boleto.pdf', 10, 'application/pdf');
    $result = app(ExtractBoletoDataAction::class)->fromUploadedFile($file);

    expect($result->wasSuccessful)->toBeFalse();
});
