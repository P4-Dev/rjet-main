<?php

declare(strict_types=1);

use App\Enums\AttachmentBatchStatus;
use App\Events\Attachment\AttachmentBatchRenamed;
use App\Exceptions\AttachmentException;
use App\Jobs\Attachment\RenameAttachmentBatchJob;
use App\Models\AttachmentBatch;
use App\Services\AttachmentBatchNamingService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
});

it('is a no-op when the batch is already renamed', function (): void {
    Event::fake([AttachmentBatchRenamed::class]);
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->renamed(), [
        fn ($f) => $f->operationalCategory()->renamed(),
    ]);
    $attachment = $batch->items()->with('attachment')->first()->attachment;

    (new RenameAttachmentBatchJob($batch))->handle(app(AttachmentBatchNamingService::class));

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::Renamed)
        ->and($attachment->fresh()->path)->toBe($attachment->path)
        ->and($attachment->fresh()->standardized_name)->toBeNull();
    Event::assertNotDispatched(AttachmentBatchRenamed::class);
});

it('renames a classified batch through handle', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
    ]);

    (new RenameAttachmentBatchJob($batch))->handle(app(AttachmentBatchNamingService::class));

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::Renamed);
});

it('marks a batch still renaming as failed with a translated reason, logging the raw message', function (): void {
    Log::spy();
    $batch = AttachmentBatch::factory()->renaming()->create();

    (new RenameAttachmentBatchJob($batch))->failed(new RuntimeException('SQLSTATE secret /var/storage/path'));

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::Failed)
        ->and($batch->fresh()->failure_reason)->toBe(__('attachment_batches.messages.rename_interrupted'));
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $context['exception'] === 'SQLSTATE secret /var/storage/path')
        ->once();
});

it('stores the user message of a business exception as failure reason', function (): void {
    $batch = AttachmentBatch::factory()->renaming()->create();

    (new RenameAttachmentBatchJob($batch))->failed(AttachmentException::fileNotFound('/raw/path.pdf'));

    expect($batch->fresh()->failure_reason)->toBe(__('attachments.errors.file_not_found'));
});

it('does not touch a finished batch when the job fails late', function (): void {
    $batch = AttachmentBatch::factory()->renamed()->create();

    (new RenameAttachmentBatchJob($batch))->failed(new RuntimeException('late'));

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::Renamed);
});

it('declares retry, timeout and overlap protection per batch', function (): void {
    $batch = AttachmentBatch::factory()->classified()->create();
    $job = new RenameAttachmentBatchJob($batch);
    $middleware = $job->middleware();

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(300)
        ->and($job->backoff)->toBe([10, 30, 60])
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe((string) $batch->getKey())
        ->and($middleware[0]->releaseAfter)->toBeNull()
        ->and($middleware[0]->expiresAfter)->toBe($job->timeout + 60);
});
