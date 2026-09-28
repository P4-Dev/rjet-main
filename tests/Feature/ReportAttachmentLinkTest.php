<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\AnalyticalReportService;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    Storage::fake('local');
    $this->branch = Branch::factory()->create();
    $this->request = PaymentRequest::factory()->forBranch($this->branch)->create();
    $this->attachment = Attachment::factory()->create(['attachable_id' => $this->request->getKey()]);
    Storage::disk('local')->put($this->attachment->path, 'pdf-content');
    $this->url = app(AnalyticalReportService::class)->attachmentUrl($this->attachment);
});

it('builds a signed absolute url that fits the excel limit', function (): void {
    expect($this->url)->toStartWith(rtrim((string) config('app.url'), '/').'/admin/report-attachments/')
        ->and($this->url)->toContain('signature=')
        ->and(strlen($this->url))->toBeLessThanOrEqual(255);
});

it('sends guests to the login page', function (): void {
    get($this->url)->assertRedirect(route('filament.admin.auth.login'));
});

it('rejects a tampered signature', function (): void {
    actingAs(User::factory()->operador()->create());

    get($this->url.'x')->assertForbidden();
});

it('authorizes by the payment request visibility', function (string $role, int $status): void {
    $user = match ($role) {
        'cliente_outside' => User::factory()->cliente()->withBranches(1)->create(),
        'cliente_inside' => User::factory()->cliente()->withBranches([$this->branch])->create(),
        'operador' => User::factory()->operador()->create(),
    };
    actingAs($user);

    $response = get($this->url);

    $status === 403 ? $response->assertForbidden() : expect($response->status())->toBeIn([200, 302]);
})->with([
    'client from another branch' => ['cliente_outside', 403],
    'client from the branch' => ['cliente_inside', 200],
    'operator' => ['operador', 200],
]);

it('returns not found for deleted attachments, deleted requests and batch attachments', function (string $case): void {
    actingAs(User::factory()->operador()->create());
    $url = $this->url;

    match ($case) {
        'attachment deleted' => $this->attachment->delete(),
        'request deleted' => $this->request->delete(),
        'batch attachment' => $url = app(AnalyticalReportService::class)->attachmentUrl(
            Attachment::factory()->forBatch(AttachmentBatch::factory()->create())->create(),
        ),
    };

    get($url)->assertNotFound();
})->with(['attachment deleted', 'request deleted', 'batch attachment']);

it('returns not found when the attachment file is missing', function (): void {
    actingAs(User::factory()->operador()->create());
    Storage::disk('local')->delete($this->attachment->path);

    get($this->url)->assertNotFound();
});

it('keeps the signed link valid long after the report was generated', function (): void {
    actingAs(User::factory()->operador()->create());

    $this->travel(400)->days();

    expect(get($this->url)->status())->toBeIn([200, 302]);
});
