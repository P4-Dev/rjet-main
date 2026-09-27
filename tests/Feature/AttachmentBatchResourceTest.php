<?php

declare(strict_types=1);

use App\Enums\AttachmentBatchDestinationType;
use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use App\Filament\Resources\AttachmentBatches\Pages\ClassifyAttachmentBatch;
use App\Filament\Resources\AttachmentBatches\Pages\CreateAttachmentBatch;
use App\Filament\Resources\AttachmentBatches\Pages\ListAttachmentBatches;
use App\Filament\Resources\AttachmentBatches\Pages\ViewAttachmentBatch;
use App\Filament\Resources\AttachmentBatches\RelationManagers\ClassificationsRelationManager;
use App\Filament\Resources\AttachmentBatches\RelationManagers\ItemsRelationManager;
use App\Jobs\Attachment\RenameAttachmentBatchJob;
use App\Livewire\Attachment\ClassifyAttachmentBatch as ClassifyAttachmentBatchComponent;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItemClassification;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
});

it('lets operador list, open create and view pages', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory(), [null]);

    Livewire::test(ListAttachmentBatches::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$batch]);
    Livewire::test(CreateAttachmentBatch::class)->assertOk();
    Livewire::test(ViewAttachmentBatch::class, ['record' => $batch->getKey()])->assertOk();
});

it('forbids cliente from the attachment batch resource', function (): void {
    actingAs(User::factory()->cliente()->create());
    $batch = AttachmentBatch::factory()->create();

    Livewire::test(ListAttachmentBatches::class)->assertForbidden();

    expect(fn () => Livewire::test(ViewAttachmentBatch::class, ['record' => $batch->getKey()]))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => Livewire::test(ClassifyAttachmentBatch::class, ['record' => $batch->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('creates a batch from a multiple upload and redirects to the view page', function (): void {
    $operador = User::factory()->operador()->create();
    actingAs($operador);

    Livewire::test(CreateAttachmentBatch::class)
        ->fillForm([
            'files' => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $batch = AttachmentBatch::query()->sole();

    expect($batch->items_count)->toBe(2)
        ->and($batch->created_by)->toBe($operador->getKey());
});

it('requires files on create', function (): void {
    actingAs(User::factory()->operador()->create());

    Livewire::test(CreateAttachmentBatch::class)
        ->fillForm(['files' => []])
        ->call('create')
        ->assertHasFormErrors(['files' => 'required']);
});

it('shows only the delete action to adm', function (): void {
    $batch = AttachmentBatch::factory()->create();

    actingAs(User::factory()->operador()->create());
    Livewire::test(ViewAttachmentBatch::class, ['record' => $batch->getKey()])->assertActionHidden('delete');

    actingAs(User::factory()->adm()->create());
    Livewire::test(ViewAttachmentBatch::class, ['record' => $batch->getKey()])->assertActionVisible('delete');
});

it('concludes classification from the view page and queues naming', function (): void {
    Queue::fake();
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [
        fn ($f) => $f->operationalCategory(),
    ]);

    Livewire::test(ViewAttachmentBatch::class, ['record' => $batch->getKey()])
        ->callAction('concludeClassification')
        ->assertNotified(__('attachment_batches.messages.classification_concluded'));

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::Classified);
    Queue::assertPushed(RenameAttachmentBatchJob::class);
});

it('notifies and keeps the batch pending when concluding an incomplete classification', function (): void {
    Queue::fake();
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);

    Livewire::test(ViewAttachmentBatch::class, ['record' => $batch->getKey()])
        ->callAction('concludeClassification')
        ->assertNotified(__('attachments.errors.batch_classification_incomplete'));

    expect($batch->fresh()->status)->toBe(AttachmentBatchStatus::PendingClassification);
    Queue::assertNothingPushed();
});

it('queues a retry only for batches with failed renames', function (): void {
    Queue::fake();
    actingAs(User::factory()->operador()->create());
    $failedBatch = createAttachmentBatchWithItems(AttachmentBatch::factory()->partiallyFailed(), [
        fn ($f) => $f->operationalCategory()->renamed(),
        fn ($f) => $f->operationalCategory()->failed(),
    ]);
    $renamedBatch = createAttachmentBatchWithItems(AttachmentBatch::factory()->renamed(), [
        fn ($f) => $f->operationalCategory()->renamed(),
    ]);

    Livewire::test(ViewAttachmentBatch::class, ['record' => $renamedBatch->getKey()])
        ->assertActionHidden('retryFailedRenames');

    Livewire::test(ViewAttachmentBatch::class, ['record' => $failedBatch->getKey()])
        ->callAction('retryFailedRenames')
        ->assertNotified(__('attachment_batches.messages.retry_queued'));

    Queue::assertPushed(RenameAttachmentBatchJob::class, 1);
});

it('shows the retry action for a failed batch whose items are still classified', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->failed(), [
        fn ($f) => $f->operationalCategory(),
    ]);
    $batch->update(['failed_count' => 0]);

    Livewire::test(ViewAttachmentBatch::class, ['record' => $batch->getKey()])
        ->assertActionVisible('retryFailedRenames');
});

it('polls the status section only while the batch is not terminal', function (): void {
    actingAs(User::factory()->operador()->create());
    $renamingBatch = AttachmentBatch::factory()->renaming()->create();
    $renamedBatch = AttachmentBatch::factory()->renamed()->create();

    Livewire::test(ViewAttachmentBatch::class, ['record' => $renamingBatch->getKey()])
        ->assertSeeHtml('wire:poll.5s');
    Livewire::test(ViewAttachmentBatch::class, ['record' => $renamedBatch->getKey()])
        ->assertDontSeeHtml('wire:poll.5s');
});

it('renders item and classification history relation managers', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [
        fn ($f) => $f->operationalCategory(),
        null,
    ]);
    AttachmentBatchItemClassification::factory()->create([
        'attachment_batch_item_id' => $batch->items->first()->getKey(),
    ]);

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewAttachmentBatch::class])
        ->assertOk()
        ->assertCanSeeTableRecords($batch->items);
    Livewire::test(ClassificationsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewAttachmentBatch::class])
        ->assertOk()
        ->assertCountTableRecords(1);
});

it('downloads an item file from the items relation manager', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);
    $item = $batch->items()->with('attachment')->first();

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewAttachmentBatch::class])
        ->assertTableActionVisible('download', $item)
        ->callTableAction('download', $item)
        ->assertFileDownloaded($item->attachment->displayName());
});

it('opens the classify page for operador on a pending batch', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);

    Livewire::test(ClassifyAttachmentBatch::class, ['record' => $batch->getKey()])->assertOk();
});

it('forbids the classify page for a batch no longer pending', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = AttachmentBatch::factory()->classified()->create();

    Livewire::test(ClassifyAttachmentBatch::class, ['record' => $batch->getKey()])->assertForbidden();
});

it('classifies, reorders and reclassifies items through the livewire component', function (): void {
    $operador = User::factory()->operador()->create();
    actingAs($operador);
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null, null]);
    [$first, $second] = $batch->items->all();

    $component = Livewire::test(ClassifyAttachmentBatchComponent::class, ['batchId' => $batch->getKey()])
        ->assertSet('selectedItemId', $first->getKey())
        ->fillForm([
            'destination_type' => AttachmentBatchDestinationType::OperationalCategory->value,
            'operational_label' => 'Frete',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('attachment_batches.messages.classification_saved'))
        ->assertSet('selectedItemId', $second->getKey());

    expect($first->fresh()->status)->toBe(AttachmentBatchItemStatus::Classified)
        ->and($first->fresh()->operational_label)->toBe('Frete');

    $component->call('sortItem', $second->getKey(), 0);

    expect($second->fresh()->sort_order)->toBe(0)
        ->and($first->fresh()->sort_order)->toBe(1);

    $component->call('selectItem', $first->getKey())
        ->fillForm([
            'destination_type' => AttachmentBatchDestinationType::OperationalCategory->value,
            'operational_label' => 'Aluguel',
        ])
        ->call('save');

    expect(AttachmentBatchItemClassification::query()->where('attachment_batch_item_id', $first->getKey())->count())->toBe(2);
});

it('forbids cliente from mounting the classify livewire component', function (): void {
    actingAs(User::factory()->cliente()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);

    Livewire::test(ClassifyAttachmentBatchComponent::class, ['batchId' => $batch->getKey()])->assertForbidden();
});

it('redirects the classify livewire component to the view page once the batch left pending', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified(), [
        fn ($f) => $f->operationalCategory(),
    ]);

    Livewire::test(ClassifyAttachmentBatchComponent::class, ['batchId' => $batch->getKey()])
        ->assertRedirect(AttachmentBatchResource::getUrl('view', ['record' => $batch->getKey()]));
});

it('rejects a save made after the batch was concluded elsewhere and redirects to the view page', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);

    $component = Livewire::test(ClassifyAttachmentBatchComponent::class, ['batchId' => $batch->getKey()]);

    $batch->forceFill(['status' => AttachmentBatchStatus::Classified])->saveQuietly();

    $component
        ->fillForm([
            'destination_type' => AttachmentBatchDestinationType::OperationalCategory->value,
            'operational_label' => 'Frete',
        ])
        ->call('save')
        ->assertNotified(__('attachments.errors.unauthorized_batch_operation'))
        ->assertRedirect(AttachmentBatchResource::getUrl('view', ['record' => $batch->getKey()]));

    expect(AttachmentBatchItemClassification::query()->count())->toBe(0)
        ->and($batch->items->first()->fresh()->status)->toBe(AttachmentBatchItemStatus::Pending);
});

it('downloads the selected item file under its display name', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);

    Livewire::test(ClassifyAttachmentBatchComponent::class, ['batchId' => $batch->getKey()])
        ->call('downloadSelected')
        ->assertFileDownloaded($batch->items->first()->attachment->displayName());
});

it('notifies instead of failing when the selected item file is missing', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);
    Storage::disk('local')->delete($batch->items->first()->attachment->path);

    Livewire::test(ClassifyAttachmentBatchComponent::class, ['batchId' => $batch->getKey()])
        ->call('downloadSelected')
        ->assertNoFileDownloaded()
        ->assertNotified(__('attachments.errors.file_not_found'));
});

it('validates the conditional destination fields in the livewire component', function (): void {
    actingAs(User::factory()->operador()->create());
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->pendingClassification(), [null]);

    Livewire::test(ClassifyAttachmentBatchComponent::class, ['batchId' => $batch->getKey()])
        ->fillForm(['destination_type' => AttachmentBatchDestinationType::PaymentRequest->value])
        ->call('save')
        ->assertHasFormErrors(['payment_request_id' => 'required']);

    expect(AttachmentBatchItemClassification::query()->count())->toBe(0);
});
