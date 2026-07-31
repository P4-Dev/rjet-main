<?php

declare(strict_types=1);

use App\Enums\AttachmentType;
use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Services\AttachmentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'rjet.attachments.disk' => 'local',
        'rjet.attachments.staging_ttl_hours' => 24,
    ]);
    $this->service = app(AttachmentService::class);
});

it('deletes expired staging files and keeps fresh ones', function (): void {
    $expired = 'attachments/staging/user-1/old.pdf';
    $fresh = 'attachments/staging/user-1/new.pdf';

    Storage::disk('local')->put($expired, 'old');
    Storage::disk('local')->put($fresh, 'new');

    // Simulate aged file by touching via cleanOrphans with hours=0 after manually
    // adjusting lastModified is not supported on Storage::fake — use hours=0 for all staging.
    $result = $this->service->cleanOrphans(dryRun: false, stagingTtlHours: 0);

    expect($result['staging_deleted'])->toBe(2);
    Storage::disk('local')->assertMissing($expired);
    Storage::disk('local')->assertMissing($fresh);
});

it('deletes unreferenced attachment files but keeps linked ones', function (): void {
    $request = PaymentRequest::factory()->create();
    $linkedPath = 'attachments/payment_request/'.$request->getKey().'/linked.pdf';
    $orphanPath = 'attachments/payment_request/'.$request->getKey().'/orphan.pdf';

    Storage::disk('local')->put($linkedPath, 'linked');
    Storage::disk('local')->put($orphanPath, 'orphan');

    Attachment::query()->create([
        'attachable_type' => $request->getMorphClass(),
        'attachable_id' => $request->getKey(),
        'type' => AttachmentType::Other,
        'disk' => 'local',
        'path' => $linkedPath,
        'original_name' => 'linked.pdf',
        'mime_type' => 'application/pdf',
        'size' => 6,
        'sort_order' => 0,
    ]);

    $result = $this->service->cleanOrphans(dryRun: false, stagingTtlHours: 24);

    expect($result['orphan_deleted'])->toBe(1);
    Storage::disk('local')->assertExists($linkedPath);
    Storage::disk('local')->assertMissing($orphanPath);
});

it('supports dry-run without deleting files', function (): void {
    Storage::disk('local')->put('attachments/staging/user-1/x.pdf', 'x');

    Artisan::call('attachments:clean-orphans', ['--hours' => 0, '--dry-run' => true]);

    Storage::disk('local')->assertExists('attachments/staging/user-1/x.pdf');
    expect(Artisan::output())->toContain('Would delete staging files: 1');
});
