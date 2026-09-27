<?php

declare(strict_types=1);

use App\Actions\Attachment\RetryFailedAttachmentBatchRenamesAction;
use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Exceptions\AttachmentException;
use App\Jobs\Attachment\RenameAttachmentBatchJob;
use App\Models\AttachmentBatch;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
    $this->operador = User::factory()->operador()->create();
});

it('recovers a fully failed batch by renaming its failed items again', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->failed(), [
        fn ($f) => $f->operationalCategory()->failed(),
        fn ($f) => $f->operationalCategory()->failed(),
    ]);

    app(RetryFailedAttachmentBatchRenamesAction::class)($batch, $this->operador);

    $batch->refresh();

    expect($batch->status)->toBe(AttachmentBatchStatus::Renamed)
        ->and($batch->failure_reason)->toBeNull()
        ->and($batch->renamed_count)->toBe(2)
        ->and($batch->failed_count)->toBe(0)
        ->and($batch->items()->pluck('status')->unique()->all())->toBe([AttachmentBatchItemStatus::Renamed])
        ->and($batch->items()->pluck('rename_error')->filter()->all())->toBe([]);
});

it('recovers a batch failed by the job while items were still classified', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->failed(), [
        fn ($f) => $f->operationalCategory()->renamed(),
        fn ($f) => $f->operationalCategory(),
    ]);
    $batch->update(['failed_count' => 0]);

    app(RetryFailedAttachmentBatchRenamesAction::class)($batch, $this->operador);

    $batch->refresh();

    expect($batch->status)->toBe(AttachmentBatchStatus::Renamed)
        ->and($batch->renamed_count)->toBe(2);
});

it('refuses to retry batches without failed renames and queues nothing', function (Closure $makeBatch): void {
    Queue::fake();
    $batch = $makeBatch();
    $statusBefore = $batch->status;

    expect(fn () => app(RetryFailedAttachmentBatchRenamesAction::class)($batch, $this->operador))
        ->toThrow(AttachmentException::class, 'no failed renames');

    expect($batch->fresh()->status)->toBe($statusBefore);
    Queue::assertNotPushed(RenameAttachmentBatchJob::class);
})->with([
    'renamed batch' => [fn (): AttachmentBatch => createAttachmentBatchWithItems(AttachmentBatch::factory()->renamed(), [
        fn ($f) => $f->operationalCategory()->renamed(),
    ])],
    'partially failed batch whose failures were already fixed' => [fn (): AttachmentBatch => createAttachmentBatchWithItems(AttachmentBatch::factory()->partiallyFailed(), [
        fn ($f) => $f->operationalCategory()->renamed(),
    ])],
    'batch still pending classification' => [fn (): AttachmentBatch => createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [
        fn ($f) => $f->failed(),
    ])],
]);

it('forbids cliente from retrying failed renames', function (): void {
    Queue::fake();
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->partiallyFailed(), [
        fn ($f) => $f->operationalCategory()->renamed(),
        fn ($f) => $f->operationalCategory()->failed(),
    ]);

    expect(fn () => app(RetryFailedAttachmentBatchRenamesAction::class)($batch, User::factory()->cliente()->create()))
        ->toThrow(AttachmentException::class, 'Unauthorized');

    Queue::assertNothingPushed();
});
