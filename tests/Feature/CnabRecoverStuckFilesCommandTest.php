<?php

declare(strict_types=1);

use App\Enums\CnabFileStatus;
use App\Events\Cnab\CnabFileGenerationFailed;
use App\Jobs\Cnab\GenerateCnabFileJob;
use App\Listeners\Cnab\NotifyCnabFileGenerationFailed;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\User;
use App\Notifications\CnabFileGenerationFailedNotification;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function stuckCnabFile(CnabFile $file, int $createdSecondsAgo, int $updatedSecondsAgo): CnabFile
{
    CnabFile::withTrashed()->whereKey($file->getKey())->toBase()->update([
        'created_at' => now()->subSeconds($createdSecondsAgo),
        'updated_at' => now()->subSeconds($updatedSecondsAgo),
    ]);

    return CnabFile::withTrashed()->findOrFail($file->getKey());
}

beforeEach(function (): void {
    config(['rjet.cnab.queue.connection' => 'cnab_database']);
    Carbon::setTestNow(now()->startOfSecond());
    Queue::fake();
    Event::fake([CnabFileGenerationFailed::class]);
});

it('requeues the same stuck file on the cnab connection without touching its nsa', function (): void {
    $settlement = createCnabReadySettlement(lastFileSequence: 7);
    $file = stuckCnabFile(
        CnabFile::factory()->for($settlement, 'settlement')->generating()->create(['file_sequence' => 7]),
        createdSecondsAgo: 10 * 60,
        updatedSecondsAgo: 301,
    );

    $this->artisan('cnab:recover-stuck-files')->assertSuccessful();

    Queue::assertPushedOn('cnab', GenerateCnabFileJob::class, fn (GenerateCnabFileJob $job): bool => $job->file->is($file) && $job->connection === 'cnab_database');
    expect($file->fresh())
        ->status->toBe(CnabFileStatus::Generating)
        ->file_sequence->toBe(7)
        ->updated_at->toEqual(now())
        ->and(CnabConfig::query()->sole()->last_file_sequence)->toBe(7)
        ->and(CnabFile::query()->count())->toBe(1);
    Event::assertNotDispatched(CnabFileGenerationFailed::class);
});

it('fails a stuck file older than the recovery ceiling', function (): void {
    $file = stuckCnabFile(
        CnabFile::factory()->for(createCnabReadySettlement(), 'settlement')->queued()->create(),
        createdSecondsAgo: 21 * 60,
        updatedSecondsAgo: 21 * 60,
    );

    $this->artisan('cnab:recover-stuck-files')->assertSuccessful();

    expect($file->fresh())
        ->status->toBe(CnabFileStatus::Failed)
        ->failure_reason->toBe(__('cnab_files.messages.generation_interrupted'));
    Event::assertDispatched(CnabFileGenerationFailed::class, fn (CnabFileGenerationFailed $event): bool => $event->file->is($file));
    Queue::assertNothingPushed();
});

it('queues the failure notification of a stuck file only after the recovery transaction commits', function (): void {
    Notification::fake();
    Event::getFacadeRoot()->except(CnabFileGenerationFailed::class);
    Queue::getFacadeRoot()->except(CallQueuedListener::class);
    $requester = User::factory()->operador()->create();
    $file = stuckCnabFile(
        CnabFile::factory()->for(createCnabReadySettlement(), 'settlement')->queued()->create(['created_by' => $requester->getKey()]),
        createdSecondsAgo: 21 * 60,
        updatedSecondsAgo: 21 * 60,
    );
    $baseTransactionLevel = DB::transactionLevel();
    $transactionLevelWhenNotifying = null;
    app()->resolving(NotifyCnabFileGenerationFailed::class, function () use (&$transactionLevelWhenNotifying): void {
        $transactionLevelWhenNotifying = DB::transactionLevel();
    });

    $this->artisan('cnab:recover-stuck-files')->assertSuccessful();

    expect($transactionLevelWhenNotifying)->toBe($baseTransactionLevel)
        ->and($file->fresh()->status)->toBe(CnabFileStatus::Failed);
    Notification::assertSentTo($requester, CnabFileGenerationFailedNotification::class, fn (CnabFileGenerationFailedNotification $notification): bool => $notification->file->is($file));
});

it('ignores recent, finished and soft-deleted files', function (): void {
    $files = collect([
        stuckCnabFile(CnabFile::factory()->generating()->create(), createdSecondsAgo: 600, updatedSecondsAgo: 200),
        stuckCnabFile(CnabFile::factory()->generated()->create(), createdSecondsAgo: 3600, updatedSecondsAgo: 3600),
        stuckCnabFile(CnabFile::factory()->failed()->create(), createdSecondsAgo: 3600, updatedSecondsAgo: 3600),
        stuckCnabFile(CnabFile::factory()->superseded()->create(), createdSecondsAgo: 3600, updatedSecondsAgo: 3600),
        stuckCnabFile(tap(CnabFile::factory()->generating()->create())->delete(), createdSecondsAgo: 600, updatedSecondsAgo: 600),
    ]);
    $before = $files->map(fn (CnabFile $file): array => $file->getAttributes())->all();

    $this->artisan('cnab:recover-stuck-files')->assertSuccessful();

    expect($files->map(fn (CnabFile $file): array => CnabFile::withTrashed()->findOrFail($file->getKey())->getAttributes())->all())
        ->toBe($before);
    Queue::assertNothingPushed();
    Event::assertNotDispatched(CnabFileGenerationFailed::class);
});

it('skips a file that stopped being stuck between the selection and the lock', function (array $change): void {
    $files = new EloquentCollection([
        stuckCnabFile(CnabFile::factory()->generating()->create(), createdSecondsAgo: 600, updatedSecondsAgo: 600),
        stuckCnabFile(CnabFile::factory()->queued()->create(), createdSecondsAgo: 30 * 60, updatedSecondsAgo: 30 * 60),
    ]);
    $changed = [];
    CnabFile::retrieved(function (CnabFile $retrieved) use (&$changed, $change): void {
        if (! in_array($retrieved->getKey(), $changed, true)) {
            $changed[] = $retrieved->getKey();
            CnabFile::withTrashed()->whereKey($retrieved->getKey())->toBase()->update(value($change));
        }
    });

    $this->artisan('cnab:recover-stuck-files')
        ->expectsOutput('Requeued 0 CNAB file(s) and failed 0.')
        ->assertSuccessful();

    expect($changed)->toEqualCanonicalizing($files->modelKeys());
    Queue::assertNothingPushed();
    Event::assertNotDispatched(CnabFileGenerationFailed::class);
})->with([
    'touched by the job' => [fn (): array => ['updated_at' => now()]],
    'failed meanwhile' => [fn (): array => ['status' => CnabFileStatus::Failed->value, 'failure_reason' => 'Failed elsewhere']],
    'soft-deleted meanwhile' => [fn (): array => ['deleted_at' => now()]],
]);

it('recovers every stuck file across chunks regardless of updated_at order', function (): void {
    CnabFile::factory()->count(101)->generating()->create()
        ->sortBy(fn (CnabFile $file): string => (string) $file->getKey())
        ->values()
        ->each(fn (CnabFile $file, int $position): CnabFile => stuckCnabFile($file, createdSecondsAgo: 600, updatedSecondsAgo: 301 + $position));

    $this->artisan('cnab:recover-stuck-files')
        ->expectsOutput('Requeued 101 CNAB file(s) and failed 0.')
        ->assertSuccessful();

    Queue::assertPushed(GenerateCnabFileJob::class, 101);
});

it('derives the stuck threshold from the cnab connection retry_after', function (): void {
    config(['queue.connections.cnab_database.retry_after' => 600]);
    $recent = stuckCnabFile(CnabFile::factory()->generating()->create(), createdSecondsAgo: 15 * 60, updatedSecondsAgo: 400);
    $stuck = stuckCnabFile(CnabFile::factory()->generating()->create(), createdSecondsAgo: 15 * 60, updatedSecondsAgo: 661);

    $this->artisan('cnab:recover-stuck-files')->assertSuccessful();

    Queue::assertPushed(GenerateCnabFileJob::class, 1);
    Queue::assertPushed(GenerateCnabFileJob::class, fn (GenerateCnabFileJob $job): bool => $job->file->is($stuck));
    expect($recent->fresh()->updated_at)->toEqual($recent->updated_at);
});

it('is scheduled every five minutes without overlapping', function (): void {
    $scheduled = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'cnab:recover-stuck-files'));

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled->sole())
        ->expression->toBe('*/5 * * * *')
        ->withoutOverlapping->toBeTrue();
});

it('only counts stuck files on dry-run', function (): void {
    $requeueable = stuckCnabFile(CnabFile::factory()->generating()->create(), createdSecondsAgo: 600, updatedSecondsAgo: 600);
    $expired = stuckCnabFile(CnabFile::factory()->queued()->create(), createdSecondsAgo: 30 * 60, updatedSecondsAgo: 30 * 60);
    $before = [$requeueable->getAttributes(), $expired->getAttributes()];

    $this->artisan('cnab:recover-stuck-files', ['--dry-run' => true])
        ->expectsOutput('Found 1 CNAB file(s) to requeue and 1 to fail (dry-run).')
        ->assertSuccessful();

    expect([$requeueable->fresh()->getAttributes(), $expired->fresh()->getAttributes()])->toBe($before);
    Queue::assertNothingPushed();
    Event::assertNotDispatched(CnabFileGenerationFailed::class);
});
