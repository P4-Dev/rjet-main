<?php

declare(strict_types=1);

use App\Actions\Attachment\CreateAttachmentBatchAction;
use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
    $this->operador = User::factory()->operador()->create();
});

it('rejects an upload with a disallowed mime type without creating anything', function (): void {
    $files = [
        UploadedFile::fake()->create('ok.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('nfe.xml', 10, 'text/xml'),
    ];

    expect(fn () => app(CreateAttachmentBatchAction::class)($files, $this->operador))
        ->toThrow(AttachmentException::class, 'Invalid attachment MIME type');

    expect(AttachmentBatch::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
});

it('rejects an upload larger than the configured max size', function (): void {
    config(['rjet.attachments.max_kilobytes' => 1]);

    expect(fn () => app(CreateAttachmentBatchAction::class)(
        [UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf')],
        $this->operador,
    ))->toThrow(AttachmentException::class, 'exceeds max size');

    expect(AttachmentBatch::query()->count())->toBe(0);
});

it('creates one attachment and one pending item per file, bound to the batch', function (): void {
    $batch = app(CreateAttachmentBatchAction::class)([
        UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->image('b.png'),
        UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], $this->operador);

    $batch->refresh()->load('items.attachment');

    expect($batch->status)->toBe(AttachmentBatchStatus::PendingClassification)
        ->and($batch->items_count)->toBe(3)
        ->and($batch->classified_count)->toBe(0)
        ->and($batch->created_by)->toBe($this->operador->getKey())
        ->and($batch->items->pluck('sort_order')->all())->toBe([0, 1, 2])
        ->and($batch->items->pluck('status')->unique()->all())->toBe([AttachmentBatchItemStatus::Pending])
        ->and($batch->items->pluck('attachment.original_name')->all())->toBe(['a.pdf', 'b.png', 'c.pdf']);

    foreach ($batch->items as $item) {
        expect($item->attachment->attachable_type)->toBe('attachment_batch')
            ->and($item->attachment->attachable_id)->toBe($batch->getKey())
            ->and($item->attachment->path)->toStartWith('attachments/attachment_batch/'.$batch->getKey().'/');

        Storage::disk('local')->assertExists($item->attachment->path);
    }
});

it('rejects an empty upload', function (): void {
    expect(fn () => app(CreateAttachmentBatchAction::class)([], $this->operador))
        ->toThrow(AttachmentException::class, 'at least one file');

    expect(AttachmentBatch::query()->count())->toBe(0);
});

it('forbids cliente from creating a batch', function (): void {
    expect(fn () => app(CreateAttachmentBatchAction::class)(
        [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
        User::factory()->cliente()->create(),
    ))->toThrow(AttachmentException::class, 'Unauthorized');
});
