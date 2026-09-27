<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('denies cliente every attachment batch ability', function (): void {
    $cliente = User::factory()->cliente()->create();
    $batch = AttachmentBatch::factory()->create();

    expect($cliente->can('viewAny', AttachmentBatch::class))->toBeFalse()
        ->and($cliente->can('view', $batch))->toBeFalse()
        ->and($cliente->can('create', AttachmentBatch::class))->toBeFalse()
        ->and($cliente->can('classify', $batch))->toBeFalse()
        ->and($cliente->can('delete', $batch))->toBeFalse()
        ->and(AttachmentBatch::query()->visibleTo($cliente)->count())->toBe(0);
});

it('lets operador and adm view, create and classify but only adm delete', function (string $role, bool $canDelete): void {
    $user = User::factory()->{$role}()->create();
    $batch = AttachmentBatch::factory()->create();

    expect($user->can('viewAny', AttachmentBatch::class))->toBeTrue()
        ->and($user->can('view', $batch))->toBeTrue()
        ->and($user->can('create', AttachmentBatch::class))->toBeTrue()
        ->and($user->can('classify', $batch))->toBeTrue()
        ->and($user->can('update', $batch))->toBeFalse()
        ->and($user->can('delete', $batch))->toBe($canDelete)
        ->and($user->can('restore', $batch))->toBe($canDelete)
        ->and($user->can('forceDelete', $batch))->toBe($canDelete)
        ->and(AttachmentBatch::query()->visibleTo($user)->count())->toBe(1);
})->with([
    'operador' => ['operador', false],
    'adm' => ['adm', true],
]);

it('allows classifying an item only while its batch is pending classification', function (): void {
    $operador = User::factory()->operador()->create();
    $pendingItem = AttachmentBatchItem::factory()->for(AttachmentBatch::factory()->pendingClassification(), 'batch')->create();
    $closedItem = AttachmentBatchItem::factory()->for(AttachmentBatch::factory()->classified(), 'batch')->create();

    expect($operador->can('classify', $pendingItem))->toBeTrue()
        ->and($operador->can('classify', $closedItem))->toBeFalse()
        ->and($operador->can('view', $closedItem))->toBeTrue()
        ->and(User::factory()->cliente()->create()->can('view', $pendingItem))->toBeFalse();
});

it('authorizes batch attachments through the batch policy, even after soft delete', function (): void {
    $batch = AttachmentBatch::factory()->create();
    $attachment = Attachment::factory()->forBatch($batch)->create();
    $batch->delete();
    $attachment = Attachment::withTrashed()->findOrFail($attachment->getKey());

    expect(User::factory()->operador()->create()->can('view', $attachment))->toBeTrue()
        ->and(User::factory()->cliente()->create()->can('view', $attachment))->toBeFalse();
});

it('reuses an eager-loaded batch when authorizing a batch attachment', function (): void {
    $operador = User::factory()->operador()->create();
    $attachment = Attachment::factory()->forBatch(AttachmentBatch::factory()->create())->create()->load('attachable');

    DB::enableQueryLog();
    $canView = $operador->can('view', $attachment);

    expect($canView)->toBeTrue()
        ->and(DB::getQueryLog())->toBe([]);
});

it('still authorizes through the trashed batch when the eager-loaded morph is empty', function (): void {
    $batch = AttachmentBatch::factory()->create();
    $attachment = Attachment::factory()->forBatch($batch)->create();
    $batch->delete();
    $attachment = Attachment::withTrashed()->with('attachable')->findOrFail($attachment->getKey());

    expect($attachment->attachable)->toBeNull()
        ->and(User::factory()->operador()->create()->can('view', $attachment))->toBeTrue()
        ->and(User::factory()->cliente()->create()->can('view', $attachment))->toBeFalse();
});
