<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Models\AttachmentBatchItemClassification;
use App\Models\PaymentRequest;
use App\Services\AttachmentBatchNamingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
});

it('allows one active item per attachment and frees it after soft delete', function (): void {
    $item = AttachmentBatchItem::factory()->create();

    expect(fn () => AttachmentBatchItem::factory()->create([
        'attachment_batch_id' => $item->attachment_batch_id,
        'attachment_id' => $item->attachment_id,
    ]))->toThrow(QueryException::class);

    $item->delete();

    $reused = AttachmentBatchItem::factory()->create([
        'attachment_batch_id' => $item->attachment_batch_id,
        'attachment_id' => $item->attachment_id,
    ]);

    expect($reused->attachment_id)->toBe($item->attachment_id);
})->skip(fn (): bool => ! in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true), 'Partial indexes require pgsql/sqlite');

it('soft-deletes items and batch-bound attachments but not attachments already rebound to a payment request', function (): void {
    $paymentRequest = PaymentRequest::factory()->create();
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->forPaymentRequest($paymentRequest),
        fn ($f) => $f->operationalCategory(),
    ]);
    app(AttachmentBatchNamingService::class)->renameBatch($batch);
    [$reboundItem, $batchItem] = $batch->items()->get()->all();

    $batch->fresh()->delete();

    expect(AttachmentBatchItem::query()->where('attachment_batch_id', $batch->getKey())->count())->toBe(0)
        ->and(AttachmentBatchItem::onlyTrashed()->where('attachment_batch_id', $batch->getKey())->count())->toBe(2)
        ->and(Attachment::query()->find($reboundItem->attachment_id))->not->toBeNull()
        ->and($paymentRequest->fresh()->has_attachments)->toBeTrue()
        ->and(Attachment::query()->find($batchItem->attachment_id))->toBeNull()
        ->and(Attachment::onlyTrashed()->find($batchItem->attachment_id))->not->toBeNull();
});

it('restores items and batch-bound attachments with the batch', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);
    $item = $batch->items->first();

    $batch->delete();
    $batch->restore();

    expect($item->fresh()->trashed())->toBeFalse()
        ->and(Attachment::query()->find($item->attachment_id))->not->toBeNull();
});

it('keeps classifications on item soft delete and removes them on force delete', function (): void {
    $item = AttachmentBatchItem::factory()->operationalCategory()->create();
    AttachmentBatchItemClassification::factory()->count(2)->create(['attachment_batch_item_id' => $item->getKey()]);

    $item->delete();
    expect(AttachmentBatchItemClassification::query()->where('attachment_batch_item_id', $item->getKey())->count())->toBe(2);

    $item->forceDelete();
    expect(AttachmentBatchItemClassification::query()->where('attachment_batch_item_id', $item->getKey())->count())->toBe(0);
});

it('force-deletes the batch cascade without touching rebound attachments', function (): void {
    $paymentRequest = PaymentRequest::factory()->create();
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->forPaymentRequest($paymentRequest),
        fn ($f) => $f->operationalCategory(),
    ]);
    app(AttachmentBatchNamingService::class)->renameBatch($batch);
    [$reboundItem, $batchItem] = $batch->items()->with('attachment')->get()->all();
    $batchFilePath = $batchItem->attachment->path;

    $batch->fresh()->forceDelete();

    expect(AttachmentBatch::withTrashed()->find($batch->getKey()))->toBeNull()
        ->and(AttachmentBatchItem::withTrashed()->where('attachment_batch_id', $batch->getKey())->count())->toBe(0)
        ->and(Attachment::query()->find($reboundItem->attachment_id))->not->toBeNull()
        ->and(Attachment::withTrashed()->find($batchItem->attachment_id))->toBeNull();

    Storage::disk('local')->assertMissing($batchFilePath);
});

/**
 * The restrictOnDelete FK stays as a safety net for raw SQL deletes that bypass AttachmentObserver.
 */
it('blocks a raw SQL delete of an attachment still referenced by an item', function (): void {
    $item = AttachmentBatchItem::factory()->create();

    expect(fn () => DB::table('attachments')->where('id', $item->attachment_id)->delete())
        ->toThrow(QueryException::class);
});

/**
 * Product behavior: Eloquent force delete goes through AttachmentObserver, which removes the batch items
 * (trashed included) before the attachment row, so the restrict FK never fires.
 */
it('force-deletes an attachment together with its batch items and their classifications', function (): void {
    $item = AttachmentBatchItem::factory()->operationalCategory()->create();
    $item->delete();
    AttachmentBatchItemClassification::factory()->create(['attachment_batch_item_id' => $item->getKey()]);

    $item->attachment()->first()->forceDelete();

    expect(Attachment::withTrashed()->find($item->attachment_id))->toBeNull()
        ->and(AttachmentBatchItem::withTrashed()->find($item->getKey()))->toBeNull()
        ->and(AttachmentBatchItemClassification::query()->where('attachment_batch_item_id', $item->getKey())->exists())->toBeFalse();
});

it('force-deletes a payment request whose attachment was rebound from a batch', function (): void {
    $paymentRequest = PaymentRequest::factory()->create();
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->forPaymentRequest($paymentRequest),
    ]);
    app(AttachmentBatchNamingService::class)->renameBatch($batch);
    $item = $batch->items()->with('attachment')->first();
    $filePath = $item->attachment->path;

    expect($item->attachment->attachable_id)->toBe($paymentRequest->getKey());

    $paymentRequest->fresh()->forceDelete();

    expect(PaymentRequest::withTrashed()->find($paymentRequest->getKey()))->toBeNull()
        ->and(Attachment::withTrashed()->find($item->attachment_id))->toBeNull()
        ->and(AttachmentBatchItem::withTrashed()->find($item->getKey()))->toBeNull();

    Storage::disk('local')->assertMissing($filePath);
});

it('stores classifications without updated_at', function (): void {
    $classification = AttachmentBatchItemClassification::factory()->create();

    expect(Schema::hasColumn('attachment_batch_item_classifications', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('attachment_batch_item_classifications', 'deleted_at'))->toBeFalse()
        ->and($classification->fresh()->classified_at)->not->toBeNull();
});

it('adds a nullable standardized_name without breaking existing attachments', function (): void {
    $attachment = Attachment::factory()->create();

    expect($attachment->fresh()->standardized_name)->toBeNull()
        ->and($attachment->displayName())->toBe('documento.pdf')
        ->and($attachment->attachable)->toBeInstanceOf(PaymentRequest::class);

    Attachment::factory()->withStandardizedName('dup.pdf')->create();
    expect(Attachment::factory()->withStandardizedName('dup.pdf')->create()->standardized_name)->toBe('dup.pdf');
});

it('stores the longest batch status without truncation', function (): void {
    $batch = AttachmentBatch::factory()->pendingClassification()->create();

    expect($batch->fresh()->getRawOriginal('status'))->toBe('pending_classification');
});
