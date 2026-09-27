<?php

declare(strict_types=1);

use App\Events\Attachment\AttachmentBatchRenamed;
use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use App\Listeners\Attachment\NotifyAttachmentBatchRenamed;
use App\Models\AttachmentBatch;
use App\Models\User;
use App\Notifications\AttachmentBatchRenamedNotification;
use App\Services\AttachmentBatchNamingService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
});

it('listens to the batch renamed event with the notify listener', function (): void {
    Event::fake();

    Event::assertListening(AttachmentBatchRenamed::class, NotifyAttachmentBatchRenamed::class);
});

it('notifies the batch creator exactly once by mail and database after a fully successful rename', function (): void {
    Notification::fake();
    $creator = User::factory()->operador()->create();
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified()->state(['created_by' => $creator->getKey()]), [
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory(),
    ]);

    app(AttachmentBatchNamingService::class)->renameBatch($batch);

    Notification::assertSentToTimes($creator, AttachmentBatchRenamedNotification::class, 1);
    Notification::assertSentTo(
        $creator,
        AttachmentBatchRenamedNotification::class,
        fn (AttachmentBatchRenamedNotification $notification, array $channels): bool => $notification->batch->is($batch)
            && in_array('mail', $channels, true)
            && in_array('database', $channels, true),
    );
});

it('does not notify anyone when the rename ends partially failed', function (): void {
    Notification::fake();
    $creator = User::factory()->operador()->create();
    $batch = createAttachmentBatchWithItems(AttachmentBatch::factory()->classified()->state(['created_by' => $creator->getKey()]), [
        fn ($f) => $f->operationalCategory(),
        fn ($f) => $f->operationalCategory(),
    ]);
    $broken = $batch->items()->with('attachment')->get()->last();
    Storage::disk('local')->delete($broken->attachment->path);

    app(AttachmentBatchNamingService::class)->renameBatch($batch);

    Notification::assertNothingSent();
});

it('skips the notification when the batch has no creator', function (): void {
    Notification::fake();
    $batch = AttachmentBatch::factory()->renamed()->create(['created_by' => null]);

    app(NotifyAttachmentBatchRenamed::class)->handle(new AttachmentBatchRenamed($batch));

    Notification::assertNothingSent();
});

it('exposes the batch link and renamed count in the database payload', function (): void {
    $creator = User::factory()->operador()->create();
    $batch = AttachmentBatch::factory()->renamed()->create(['renamed_count' => 3]);

    $payload = (new AttachmentBatchRenamedNotification($batch))->toArray($creator);

    expect($payload)
        ->toMatchArray([
            'attachment_batch_id' => $batch->getKey(),
            'renamed_count' => 3,
            'title' => __('notifications.attachment_batch_renamed.subject'),
            'body' => __('notifications.attachment_batch_renamed.body', ['count' => 3]),
            'url' => AttachmentBatchResource::getUrl('view', ['record' => $batch->getKey()]),
        ]);
});
