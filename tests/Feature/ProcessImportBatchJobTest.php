<?php

declare(strict_types=1);

use App\Enums\ImportBatchStatus;
use App\Jobs\PaymentRequest\ProcessImportBatchJob;
use App\Models\ImportBatch;
use App\Models\ImportTemplateVersion;
use App\Models\User;
use App\Services\ImportBatchService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('is a no-op when batch is already completed', function (): void {
    Storage::fake('local');

    $batch = ImportBatch::factory()->completed()->create();
    Storage::disk($batch->disk)->put($batch->path, "a\nb\n");

    Event::fake();

    app(ImportBatchService::class)->process($batch);

    expect($batch->fresh()->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->fresh()->success_count)->toBe(1);
});

it('dispatches process import batch job onto the queue', function (): void {
    Queue::fake();

    $batch = ImportBatch::factory()->pending()->create();

    ProcessImportBatchJob::dispatch($batch);

    Queue::assertPushed(ProcessImportBatchJob::class, function (ProcessImportBatchJob $job) use ($batch): bool {
        return $job->batch->is($batch);
    });
});

it('processes pending batch through job handle', function (): void {
    Storage::fake('local');

    $version = ImportTemplateVersion::factory()->create();
    $batch = ImportBatch::factory()->pending()->create([
        'import_template_version_id' => $version->getKey(),
        'created_by' => User::factory()->adm()->create()->getKey(),
    ]);
    Storage::disk($batch->disk)->put($batch->path, "col\n");

    $job = new ProcessImportBatchJob($batch);
    $job->handle(app(ImportBatchService::class));

    expect($batch->fresh()->status)->toBe(ImportBatchStatus::Failed);
});

it('marks batch failed when job failed callback runs', function (): void {
    Storage::fake('local');

    $batch = ImportBatch::factory()->pending()->create([
        'created_by' => User::factory()->adm()->create()->getKey(),
    ]);
    Storage::disk($batch->disk)->put($batch->path, "col\nvalue\n");

    $job = new ProcessImportBatchJob($batch);
    $job->failed(new RuntimeException('Worker crashed'));

    expect($batch->fresh()->status)->toBe(ImportBatchStatus::Failed)
        ->and($batch->fresh()->failure_reason)->toContain('Worker crashed');
});
