<?php

declare(strict_types=1);

use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Events\Attachment\AttachmentBatchRenamed;
use App\Events\Attachment\AttachmentRenamed;
use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Services\AttachmentBatchNamingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
    $this->travelTo(CarbonImmutable::parse('2026-09-26 10:15:30', 'America/Sao_Paulo'));
    $this->naming = app(AttachmentBatchNamingService::class);
});

it('names files YYYYMMDD_HHMMSS_SEQ.ext by order in the app timezone and keeps the original name', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory()->state([
            'attachment_id' => fn (array $attributes): string => (string) Attachment::factory()
                ->image()
                ->forBatch(AttachmentBatch::query()->findOrFail($attributes['attachment_batch_id']))
                ->state(['path' => 'attachments/attachment_batch/'.$attributes['attachment_batch_id'].'/upload.png'])
                ->create()
                ->getKey(),
        ]),
    ]);

    $this->naming->renameBatch($batch);

    $attachments = $batch->items()->with('attachment')->get()->pluck('attachment');
    $directory = 'attachments/attachment_batch/'.$batch->getKey();

    expect($attachments[0]->standardized_name)->toBe('20260926_101530_001.pdf')
        ->and($attachments[0]->original_name)->toBe('documento.pdf')
        ->and($attachments[0]->path)->toBe($directory.'/20260926_101530_001.pdf')
        ->and($attachments[0]->displayName())->toBe('20260926_101530_001.pdf')
        ->and($attachments[1]->standardized_name)->toBe('20260926_101530_002.png')
        ->and($attachments[1]->original_name)->toBe('comprovante.png')
        ->and($batch->fresh()->naming_generated_at->format('Y-m-d H:i:s'))->toBe('2026-09-26 10:15:30');

    Storage::disk('local')->assertExists($attachments[0]->path);
    Storage::disk('local')->assertExists($attachments[1]->path);
});

it('adds a _2 suffix when the standardized path is already taken', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
    ]);
    $takenPath = 'attachments/attachment_batch/'.$batch->getKey().'/20260926_101530_001.pdf';
    Storage::disk('local')->put($takenPath, 'someone else');

    $this->naming->renameBatch($batch);

    $attachment = $batch->items()->with('attachment')->first()->attachment;

    expect($attachment->standardized_name)->toBe('20260926_101530_001_2.pdf')
        ->and(Storage::disk('local')->get($takenPath))->toBe('someone else');
});

it('marks the item failed when all collision suffixes are exhausted', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
    ]);
    $directory = 'attachments/attachment_batch/'.$batch->getKey();
    Storage::disk('local')->put($directory.'/20260926_101530_001.pdf', 'x');
    foreach (range(2, AttachmentBatchNamingService::MAX_COLLISION_SUFFIX) as $suffix) {
        Storage::disk('local')->put($directory."/20260926_101530_001_{$suffix}.pdf", 'x');
    }

    $this->naming->renameBatch($batch);

    $item = $batch->items()->first();

    expect($item->status)->toBe(AttachmentBatchItemStatus::Failed)
        ->and($item->rename_error)->toBe(__('attachments.errors.naming_collision_unresolved'))
        ->and($batch->fresh()->status)->toBe(AttachmentBatchStatus::Failed);
});

it('rebinds the same attachment to the payment request and syncs has_attachments', function (): void {
    $paymentRequest = PaymentRequest::factory()->create(['has_attachments' => false]);
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->forPaymentRequest($paymentRequest),
    ]);
    $item = $batch->items()->first();
    $attachmentId = $item->attachment_id;
    $attachmentCount = Attachment::query()->count();

    $this->naming->renameBatch($batch);

    $attachment = Attachment::query()->findOrFail($attachmentId);

    expect(Attachment::query()->count())->toBe($attachmentCount)
        ->and($attachment->attachable_type)->toBe('payment_request')
        ->and($attachment->attachable_id)->toBe($paymentRequest->getKey())
        ->and($attachment->path)->toBe('attachments/payment_request/'.$paymentRequest->getKey().'/20260926_101530_001.pdf')
        ->and($paymentRequest->fresh()->has_attachments)->toBeTrue()
        ->and($paymentRequest->attachments()->pluck('id')->all())->toBe([$attachmentId])
        ->and($batch->attachments()->count())->toBe(0)
        ->and($item->fresh()->status)->toBe(AttachmentBatchItemStatus::Renamed);

    Storage::disk('local')->assertExists($attachment->path);
});

it('keeps supplier and operational category attachments on the batch', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->forSupplier(Supplier::factory()->create()),
        fn ($f) => $f->operationalCategory(),
    ]);

    $this->naming->renameBatch($batch);

    $attachments = $batch->items()->with('attachment')->get()->pluck('attachment');

    foreach ($attachments as $attachment) {
        expect($attachment->attachable_type)->toBe('attachment_batch')
            ->and($attachment->attachable_id)->toBe($batch->getKey())
            ->and($attachment->standardized_name)->not->toBeNull();
    }

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::Renamed);
});

it('marks only the item whose move fails and ends the batch partially failed', function (): void {
    Event::fake([AttachmentBatchRenamed::class, AttachmentRenamed::class]);
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory(),
    ]);
    [$healthy, $broken] = $batch->items()->with('attachment')->get()->all();
    Storage::disk('local')->delete($broken->attachment->path);
    $brokenPath = $broken->attachment->path;

    $this->naming->renameBatch($batch);

    $batch->refresh();

    expect($healthy->fresh()->status)->toBe(AttachmentBatchItemStatus::Renamed)
        ->and($broken->fresh()->status)->toBe(AttachmentBatchItemStatus::Failed)
        ->and($broken->fresh()->rename_error)->toBe(__('attachments.errors.storage_move_failed'))
        ->and($broken->fresh()->attachment->path)->toBe($brokenPath)
        ->and($broken->fresh()->attachment->standardized_name)->toBeNull()
        ->and($batch->status)->toBe(AttachmentBatchStatus::PartiallyFailed)
        ->and($batch->renamed_count)->toBe(1)
        ->and($batch->failed_count)->toBe(1);

    Event::assertDispatchedTimes(AttachmentRenamed::class, 1);
    Event::assertNotDispatched(AttachmentBatchRenamed::class);
});

it('retries only failed items and keeps the original sequence and timestamp', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory(),
    ]);
    [$healthy, $broken] = $batch->items()->with('attachment')->get()->all();
    $brokenPath = $broken->attachment->path;
    Storage::disk('local')->move($brokenPath, $brokenPath.'.hidden');

    $this->naming->renameBatch($batch);
    $healthyPath = $healthy->fresh()->attachment->path;

    Storage::disk('local')->move($brokenPath.'.hidden', $brokenPath);
    $this->travel(5)->minutes();
    Event::fake([AttachmentBatchRenamed::class]);

    $this->naming->renameBatch($batch->fresh());

    expect($healthy->fresh()->attachment->path)->toBe($healthyPath)
        ->and($broken->fresh()->status)->toBe(AttachmentBatchItemStatus::Renamed)
        ->and($broken->fresh()->attachment->standardized_name)->toBe('20260926_101530_002.pdf')
        ->and($batch->fresh()->status)->toBe(AttachmentBatchStatus::Renamed);

    Event::assertDispatched(AttachmentBatchRenamed::class);
});

it('dispatches the batch renamed event only on full success', function (): void {
    Event::fake([AttachmentBatchRenamed::class]);
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory(),
    ]);

    $this->naming->renameBatch($batch);

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::Renamed)
        ->and($batch->fresh()->renamed_count)->toBe(2)
        ->and($batch->fresh()->renamed_at)->not->toBeNull();
    Event::assertDispatchedTimes(AttachmentBatchRenamed::class, 1);
});
