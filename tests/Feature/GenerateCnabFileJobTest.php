<?php

declare(strict_types=1);

use App\Enums\CnabFileStatus;
use App\Enums\CnabPaymentType;
use App\Events\Cnab\CnabFileGenerated;
use App\Events\Cnab\CnabFileGenerationFailed;
use App\Exceptions\CnabException;
use App\Integrations\Cnab\Itau\Itau240Layout;
use App\Jobs\Cnab\GenerateCnabFileJob;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\CnabFileItem;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Notifications\CnabFileGeneratedNotification;
use App\Notifications\CnabFileGenerationFailedNotification;
use App\Services\CnabFileService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function cnabFileService(): CnabFileService
{
    return app(CnabFileService::class);
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.cnab.disk' => 'local']);
    $this->operador = User::factory()->operador()->create();
});

it('generates the file, its items and consumes the nsa once', function (): void {
    Notification::fake();
    $settlement = createCnabReadySettlement([
        PaymentRequest::factory()->depositTransfer(),
        PaymentRequest::factory()->depositPix(),
    ], lastFileSequence: 9);

    $file = cnabFileService()->request($settlement, $this->operador)->fresh();

    expect($file->status)->toBe(CnabFileStatus::Generated)
        ->and($file->file_sequence)->toBe(10)
        ->and($file->filename)->toEndWith('_000010.rem')
        ->and($file->items_count)->toBe(2)
        ->and((string) $file->total_amount)->toBe((string) $settlement->total_amount)
        ->and(CnabConfig::query()->sole()->last_file_sequence)->toBe(10);
    Storage::disk('local')->assertExists($file->path);
    expect($file->checksum)->toBe(hash('sha256', Storage::disk('local')->get($file->path)))
        ->and(CnabFileItem::query()->where('cnab_file_id', $file->getKey())->get()->map->only(['payment_type', 'batch_number', 'record_sequence', 'payment_form_code'])->sortBy('batch_number')->values()->all())
        ->toEqual([
            ['payment_type' => CnabPaymentType::Transfer, 'batch_number' => 1, 'record_sequence' => 1, 'payment_form_code' => Itau240Layout::FORM_TED],
            ['payment_type' => CnabPaymentType::PixKey, 'batch_number' => 2, 'record_sequence' => 1, 'payment_form_code' => Itau240Layout::FORM_PIX_TRANSFER],
        ]);
    Notification::assertSentTo($this->operador, CnabFileGeneratedNotification::class);
});

it('dispatches the generated event', function (): void {
    Event::fake([CnabFileGenerated::class]);
    $settlement = createCnabReadySettlement();
    $file = CnabFile::factory()->for($settlement, 'settlement')->create(['created_by' => $this->operador->getKey()]);

    cnabFileService()->generate($file);

    Event::assertDispatched(CnabFileGenerated::class, fn (CnabFileGenerated $event): bool => $event->file->is($file));
});

it('fails without writing a file or consuming the nsa when the dry-run rejects items', function (): void {
    Event::fake([CnabFileGenerationFailed::class]);
    $settlement = createCnabReadySettlement([
        PaymentRequest::factory()->depositTransfer(),
        PaymentRequest::factory()->depositPixQrCode(),
    ], lastFileSequence: 3);

    $file = cnabFileService()->request($settlement, $this->operador)->fresh();

    $invalidItem = CnabFileItem::query()->where('cnab_file_id', $file->getKey())->sole();
    expect($file->status)->toBe(CnabFileStatus::Failed)
        ->and($file->file_sequence)->toBeNull()
        ->and($invalidItem->is_valid)->toBeFalse()
        ->and($invalidItem->validation_errors[0]['code'])->toBe('pix_qr_code_not_supported')
        ->and(CnabConfig::query()->sole()->last_file_sequence)->toBe(3)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Event::assertDispatchedTimes(CnabFileGenerationFailed::class, 1);
});

it('marks the file failed with a friendly message when storage fails and allows a retry', function (): void {
    config([
        'filesystems.disks.cnab-broken' => ['driver' => 'local', 'root' => '/proc/rjet-cnab-unwritable', 'throw' => false],
        'rjet.cnab.disk' => 'cnab-broken',
    ]);
    $settlement = createCnabReadySettlement();

    expect(fn () => cnabFileService()->request($settlement, $this->operador))
        ->toThrow(CnabException::class, CnabException::storageWriteFailed()->getMessage());

    $failed = CnabFile::query()->sole();
    expect($failed->status)->toBe(CnabFileStatus::Failed)
        ->and($failed->failure_reason)->toBe(CnabException::storageWriteFailed()->getUserMessage());

    Queue::fake();
    $retry = cnabFileService()->retry($failed, $this->operador);

    expect($retry->getKey())->not->toBe($failed->getKey())
        ->and($retry->status)->toBe(CnabFileStatus::Queued);
    Queue::assertPushed(GenerateCnabFileJob::class, fn (GenerateCnabFileJob $job): bool => $job->file->is($retry));
});

it('refuses a second request while a file is active', function (): void {
    Queue::fake();
    $settlement = createCnabReadySettlement();
    cnabFileService()->request($settlement, $this->operador);

    expect(fn () => cnabFileService()->request($settlement, $this->operador))
        ->toThrow(CnabException::class, CnabException::generationAlreadyActive()->getMessage());
});

it('does nothing when the job runs for an already generated file', function (): void {
    $settlement = createCnabReadySettlement(lastFileSequence: 5);
    $file = CnabFile::factory()->for($settlement, 'settlement')->generated()->create(['file_sequence' => 5]);
    $before = $file->fresh()->getAttributes();

    (new GenerateCnabFileJob($file))->handle(cnabFileService());

    expect($file->fresh()->getAttributes())->toBe($before)
        ->and(CnabConfig::query()->sole()->last_file_sequence)->toBe(5);
});

it('reuses the nsa already assigned by a previous attempt', function (): void {
    $settlement = createCnabReadySettlement(lastFileSequence: 5);
    $file = CnabFile::factory()->for($settlement, 'settlement')->generating()->create([
        'file_sequence' => 5,
        'created_by' => $this->operador->getKey(),
    ]);

    (new GenerateCnabFileJob($file))->handle(cnabFileService());

    expect($file->fresh())
        ->status->toBe(CnabFileStatus::Generated)
        ->file_sequence->toBe(5)
        ->and(CnabConfig::query()->sole()->last_file_sequence)->toBe(5);
});

it('notifies the requester when generation fails', function (): void {
    Notification::fake();
    $settlement = createCnabReadySettlement([PaymentRequest::factory()->depositPixQrCode()]);

    cnabFileService()->request($settlement, $this->operador);

    Notification::assertSentTo($this->operador, CnabFileGenerationFailedNotification::class);
    Notification::assertNotSentTo($this->operador, CnabFileGeneratedNotification::class);
});

it('regenerates by superseding the generated file and queuing a new one', function (): void {
    Queue::fake();
    $adm = User::factory()->adm()->create();
    $settlement = createCnabReadySettlement(lastFileSequence: 5);
    $generated = CnabFile::factory()->for($settlement, 'settlement')->generated()->create(['file_sequence' => 5]);

    $regenerated = cnabFileService()->regenerate($generated, $adm, 'Wrong payment date');

    expect($generated->fresh())
        ->status->toBe(CnabFileStatus::Superseded)
        ->superseded_by->toBe($adm->getKey())
        ->supersede_reason->toBe('Wrong payment date')
        ->and($regenerated->getKey())->not->toBe($generated->getKey())
        ->and($regenerated->status)->toBe(CnabFileStatus::Queued)
        ->and($regenerated->file_sequence)->toBeNull();
    Queue::assertPushed(GenerateCnabFileJob::class, fn (GenerateCnabFileJob $job): bool => $job->file->is($regenerated));
});

it('refuses to regenerate outside a draft settlement with a generated file', function (Closure $makeFile, string $expectedMessage): void {
    $file = $makeFile();

    expect(fn () => cnabFileService()->regenerate($file, User::factory()->adm()->create(), 'Reason'))
        ->toThrow(CnabException::class, $expectedMessage)
        ->and(CnabFile::query()->count())->toBe(1);
})->with([
    'failed file' => [
        fn (): CnabFile => CnabFile::factory()->for(createCnabReadySettlement(), 'settlement')->failed()->create(),
        fn (): string => CnabException::fileNotRetryable()->getMessage(),
    ],
    'settled settlement' => [
        fn (): CnabFile => CnabFile::factory()->for(PaymentSettlement::factory()->settled()->create(), 'settlement')->generated()->create(),
        fn (): string => CnabException::settlementNotDraft()->getMessage(),
    ],
]);

it('dispatches on the dedicated cnab connection and queue with release-based overlap control', function (): void {
    config(['rjet.cnab.queue.connection' => 'cnab_database']);
    Queue::fake();

    cnabFileService()->request(createCnabReadySettlement(), $this->operador);

    Queue::assertPushedOn('cnab', GenerateCnabFileJob::class, function (GenerateCnabFileJob $job): bool {
        /** @var WithoutOverlapping $overlap */
        $overlap = $job->middleware()[0];

        return $job->connection === 'cnab_database'
            && $job->tries === 10
            && $job->maxExceptions === 3
            && $overlap->releaseAfter === 30
            && $overlap->expiresAfter === 180;
    });
});

it('releases the job back to the queue while the settlement lock is held, then generates', function (): void {
    config(['rjet.cnab.queue.connection' => 'cnab_database']);
    $file = CnabFile::factory()->for(createCnabReadySettlement(), 'settlement')->create(['created_by' => $this->operador->getKey()]);
    $job = new GenerateCnabFileJob($file);
    $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 180);
    $lock->get();
    dispatch($job);

    $this->artisan('queue:work', ['connection' => 'cnab_database', '--queue' => 'cnab', '--once' => true]);

    expect($file->fresh()->status)->toBe(CnabFileStatus::Queued)
        ->and(DB::table('jobs')->where('queue', 'cnab')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $lock->release();
    $this->travel(31)->seconds();
    $this->artisan('queue:work', ['connection' => 'cnab_database', '--queue' => 'cnab', '--once' => true]);

    expect($file->fresh()->status)->toBe(CnabFileStatus::Generated)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('touches updated_at on re-entry of a generating file and keeps its nsa', function (): void {
    $settlement = createCnabReadySettlement(lastFileSequence: 5);
    $this->travel(-10)->minutes();
    $file = CnabFile::factory()->for($settlement, 'settlement')->generating()->create([
        'file_sequence' => 5,
        'created_by' => $this->operador->getKey(),
    ]);
    $this->travelBack();
    $startedAt = $file->started_at;
    $updates = [];
    CnabFile::updating(function (CnabFile $updating) use (&$updates): void {
        $updates[] = array_keys($updating->getDirty());
    });

    (new GenerateCnabFileJob($file))->handle(cnabFileService());

    expect($updates[0])->toBe(['updated_at'])
        ->and($file->fresh())
        ->started_at->toEqual($startedAt)
        ->file_sequence->toBe(5)
        ->and(CnabConfig::query()->sole()->last_file_sequence)->toBe(5);
});

it('discards the stored file without completing when the file was failed before the final step', function (): void {
    Event::fake([CnabFileGenerated::class]);
    $file = CnabFile::factory()->for(createCnabReadySettlement(), 'settlement')->create(['created_by' => $this->operador->getKey()]);
    CnabFile::updated(function (CnabFile $updated): void {
        if ($updated->wasChanged('file_sequence')) {
            CnabFile::query()->whereKey($updated->getKey())->toBase()->update(['status' => CnabFileStatus::Failed->value]);
        }
    });

    cnabFileService()->generate($file);

    expect($file->fresh()->status)->toBe(CnabFileStatus::Failed)
        ->and(CnabFileItem::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Event::assertNotDispatched(CnabFileGenerated::class);
});

it('fails the file when the nsa is exhausted', function (): void {
    $settlement = createCnabReadySettlement(lastFileSequence: 999999);

    $file = cnabFileService()->request($settlement, $this->operador)->fresh();

    expect($file->status)->toBe(CnabFileStatus::Failed)
        ->and($file->failure_reason)->toBe(CnabException::fileSequenceExhausted()->getUserMessage())
        ->and($file->file_sequence)->toBeNull();
});
