<?php

declare(strict_types=1);

use App\Actions\Attachment\ClassifyAttachmentBatchItemAction;
use App\Actions\Attachment\ConcludeAttachmentBatchClassificationAction;
use App\Actions\Attachment\ReorderAttachmentBatchItemsAction;
use App\DTOs\AttachmentBatchItemClassificationData;
use App\Enums\AttachmentBatchDestinationType;
use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Events\Attachment\AttachmentBatchClassified;
use App\Exceptions\AttachmentException;
use App\Jobs\Attachment\RenameAttachmentBatchJob;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItemClassification;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AttachmentBatchClassificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
    $this->operador = User::factory()->operador()->create();
});

function operationalData(string $label = 'Despesas gerais'): AttachmentBatchItemClassificationData
{
    return new AttachmentBatchItemClassificationData(
        destinationType: AttachmentBatchDestinationType::OperationalCategory,
        operationalLabel: $label,
    );
}

it('reorders items and the new order drives the rename sequence', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory(),
    ]);
    [$first, $second, $third] = $batch->items->all();

    app(ReorderAttachmentBatchItemsAction::class)(
        $batch,
        [$third->getKey(), $first->getKey(), $second->getKey()],
        $this->operador,
    );

    expect($third->fresh()->sort_order)->toBe(0)
        ->and($first->fresh()->sort_order)->toBe(1)
        ->and($second->fresh()->sort_order)->toBe(2);

    $this->travelTo(CarbonImmutable::parse('2026-09-26 10:15:00', 'America/Sao_Paulo'));
    app(ConcludeAttachmentBatchClassificationAction::class)($batch, $this->operador);

    expect($third->fresh()->attachment->standardized_name)->toBe('20260926_101500_001.pdf')
        ->and($first->fresh()->attachment->standardized_name)->toBe('20260926_101500_002.pdf')
        ->and($second->fresh()->attachment->standardized_name)->toBe('20260926_101500_003.pdf');
});

it('records a new history row on every classification, including reclassification', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);
    $item = $batch->items->first();
    $supplier = Supplier::factory()->create();

    app(ClassifyAttachmentBatchItemAction::class)($item, operationalData('Frete'), $this->operador);
    $firstRow = AttachmentBatchItemClassification::query()->sole();

    app(ClassifyAttachmentBatchItemAction::class)(
        $item->fresh(),
        new AttachmentBatchItemClassificationData(
            destinationType: AttachmentBatchDestinationType::Supplier,
            supplierId: $supplier->getKey(),
        ),
        $this->operador,
    );

    $item->refresh();
    $rows = AttachmentBatchItemClassification::query()->orderBy('classified_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->first()->is($firstRow))->toBeTrue()
        ->and($rows->first()->destination_type)->toBe(AttachmentBatchDestinationType::OperationalCategory)
        ->and($rows->first()->operational_label)->toBe('Frete')
        ->and($rows->last()->destination_type)->toBe(AttachmentBatchDestinationType::Supplier)
        ->and($rows->last()->user_id)->toBe($this->operador->getKey())
        ->and($item->status)->toBe(AttachmentBatchItemStatus::Classified)
        ->and($item->destination_type)->toBe(AttachmentBatchDestinationType::Supplier)
        ->and($item->supplier_id)->toBe($supplier->getKey())
        ->and($item->operational_label)->toBeNull()
        ->and($item->classified_by)->toBe($this->operador->getKey())
        ->and($batch->fresh()->classified_count)->toBe(1);
});

it('rejects inconsistent destinations', function (array $attributes): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);

    expect(fn () => app(ClassifyAttachmentBatchItemAction::class)(
        $batch->items->first(),
        AttachmentBatchItemClassificationData::fromArray($attributes),
        $this->operador,
    ))->toThrow(AttachmentException::class, 'Invalid or incomplete');

    expect(AttachmentBatchItemClassification::query()->count())->toBe(0)
        ->and($batch->items->first()->fresh()->status)->toBe(AttachmentBatchItemStatus::Pending);
})->with([
    'payment request without id' => [['destination_type' => 'payment_request']],
    'payment request with a label' => [fn (): array => [
        'destination_type' => 'payment_request',
        'payment_request_id' => PaymentRequest::factory()->create()->getKey(),
        'operational_label' => 'extra',
    ]],
    'unknown payment request' => [['destination_type' => 'payment_request', 'payment_request_id' => '00000000-0000-0000-0000-000000000000']],
    'supplier without id' => [['destination_type' => 'supplier']],
    'operational category without label' => [['destination_type' => 'operational_category']],
    'operational label too long' => [['destination_type' => 'operational_category', 'operational_label' => str_repeat('a', 121)]],
    'unknown destination type' => [['destination_type' => 'warehouse']],
]);

it('keeps the batch pending and queues nothing when concluding with pending items', function (): void {
    Queue::fake();
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [
        fn ($f) => $f->operationalCategory(),
        null,
    ]);

    expect(fn () => app(ConcludeAttachmentBatchClassificationAction::class)($batch, $this->operador))
        ->toThrow(AttachmentException::class, 'pending items');

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::PendingClassification)
        ->and($batch->fresh()->classified_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('dispatches the classified event when concluding a fully classified batch', function (): void {
    Event::fake([AttachmentBatchClassified::class]);
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [
        fn ($f) => $f->operationalCategory(),
    ]);

    app(ConcludeAttachmentBatchClassificationAction::class)($batch, $this->operador);

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::Classified)
        ->and($batch->fresh()->classified_at)->not->toBeNull();
    Event::assertDispatched(AttachmentBatchClassified::class, fn (AttachmentBatchClassified $event): bool => $event->batch->is($batch));
});

it('queues exactly one rename job per batch on conclusion', function (): void {
    Queue::fake();
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory(),
    ]);

    app(ConcludeAttachmentBatchClassificationAction::class)($batch, $this->operador);

    Queue::assertPushed(RenameAttachmentBatchJob::class, 1);
    Queue::assertPushed(RenameAttachmentBatchJob::class, fn (RenameAttachmentBatchJob $job): bool => $job->batch->is($batch));
});

it('denies the classify action once the batch left pending classification', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
    ]);

    expect(fn () => app(ClassifyAttachmentBatchItemAction::class)($batch->items->first(), operationalData(), $this->operador))
        ->toThrow(AttachmentException::class, 'Unauthorized');

    expect(AttachmentBatchItemClassification::query()->count())->toBe(0);
});

it('rejects classification service calls for a batch that is no longer pending', function (): void {
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
    ]);

    expect(fn () => app(AttachmentBatchClassificationService::class)->classifyItem(
        $batch->items->first(),
        operationalData(),
        $this->operador,
    ))->toThrow(AttachmentException::class, 'not classifiable');

    expect(fn () => app(AttachmentBatchClassificationService::class)->conclude($batch, $this->operador))
        ->toThrow(AttachmentException::class, 'not classifiable');
});
