<?php

declare(strict_types=1);

use App\Enums\AttachmentType;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Services\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
    $this->service = app(AttachmentService::class);
    $this->request = PaymentRequest::factory()->create();
});

it('stores an uploaded file and creates an attachment', function (): void {
    $file = UploadedFile::fake()->create('boleto.pdf', 100, 'application/pdf');

    $attachment = $this->service->storeUploadedFile($this->request, $file, AttachmentType::Boleto);

    expect($attachment->original_name)->toBe('boleto.pdf')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->attachable_type)->toBe('payment_request')
        ->and($this->request->fresh()->has_attachments)->toBeTrue();

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects invalid mime types', function (): void {
    $file = UploadedFile::fake()->create('nfe.xml', 10, 'text/xml');

    expect(fn () => $this->service->storeUploadedFile($this->request, $file))
        ->toThrow(AttachmentException::class);
});

it('rejects files larger than the configured max', function (): void {
    config(['rjet.attachments.max_kilobytes' => 1]);
    $file = UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf');

    expect(fn () => $this->service->storeUploadedFile($this->request, $file))
        ->toThrow(AttachmentException::class);
});

it('keeps the physical file on soft delete and removes it on force delete', function (): void {
    $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
    $attachment = $this->service->storeUploadedFile($this->request, $file);
    $path = $attachment->path;

    $this->service->delete($attachment);
    Storage::disk('local')->assertExists($path);
    expect($this->request->fresh()->has_attachments)->toBeFalse();

    $this->service->forceDelete($attachment->fresh());
    Storage::disk('local')->assertMissing($path);
});

it('scopes morph attachments to the correct payment request', function (): void {
    $other = PaymentRequest::factory()->create();
    $file = UploadedFile::fake()->create('a.pdf', 20, 'application/pdf');

    $this->service->storeUploadedFile($this->request, $file);
    $this->service->storeUploadedFile($other, $file);

    expect($this->request->attachments()->count())->toBe(1)
        ->and($other->attachments()->count())->toBe(1);
});

it('revalidates mime and size when storing from existing paths', function (): void {
    $file = UploadedFile::fake()->create('evil.xml', 10, 'text/xml');
    $path = $file->store('tmp', 'local');

    expect(fn () => $this->service->storeManyFromPaths($this->request, [$path]))
        ->toThrow(AttachmentException::class);

    expect($this->request->attachments()->count())->toBe(0);
});

it('rejects oversized files when storing from existing paths', function (): void {
    config(['rjet.attachments.max_kilobytes' => 1]);

    $path = 'tmp/big.pdf';
    Storage::disk('local')->put($path, str_repeat('x', 2048 * 1024));

    expect(fn () => $this->service->storeManyFromPaths($this->request, [$path]))
        ->toThrow(AttachmentException::class);
});

it('stores valid pdf paths as attachments', function (): void {
    $file = UploadedFile::fake()->create('boleto.pdf', 50, 'application/pdf');
    $path = $file->store('tmp', 'local');

    $attachments = $this->service->storeManyFromPaths(
        $this->request,
        [$path],
        [$path => 'boleto.pdf'],
        AttachmentType::Boleto,
    );

    expect($attachments)->toHaveCount(1)
        ->and($attachments->first()->original_name)->toBe('boleto.pdf')
        ->and($attachments->first()->mime_type)->toBe('application/pdf');
});
