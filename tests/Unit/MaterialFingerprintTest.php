<?php

declare(strict_types=1);

use App\Models\Approval;
use App\Models\Appropriation;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('changes fingerprint when material fields change', function (): void {
    $request = PaymentRequest::factory()->depositPix()->create(['net_amount' => '1000.00']);
    $original = $request->currentMaterialFingerprint();

    $request->update(['net_amount' => '1500.00']);
    expect($request->fresh()->currentMaterialFingerprint())->not->toBe($original);

    $request = PaymentRequest::factory()->depositPix()->create();
    $original = $request->currentMaterialFingerprint();
    $request->update(['supplier_id' => Supplier::factory()->create()->getKey()]);
    expect($request->fresh()->currentMaterialFingerprint())->not->toBe($original);

    $request = PaymentRequest::factory()->depositPix()->create();
    $original = $request->currentMaterialFingerprint();
    $otherBranch = Branch::factory()->create();
    $request->update(['branch_id' => $otherBranch->getKey()]);
    expect($request->fresh()->currentMaterialFingerprint())->not->toBe($original);

    $request = PaymentRequest::factory()->depositPix()->create();
    $original = $request->currentMaterialFingerprint();
    $request->bankDetails->update(['pix_key' => 'changed@example.com']);
    expect($request->fresh()->currentMaterialFingerprint())->not->toBe($original);
});

it('changes fingerprint when boleto attachment changes', function (): void {
    $request = PaymentRequest::factory()->boleto()->create();
    $original = $request->currentMaterialFingerprint();

    \App\Models\Attachment::factory()->boleto()->for($request, 'attachable')->create();

    expect($request->fresh()->currentMaterialFingerprint())->not->toBe($original);
});

it('keeps fingerprint when non-material fields change', function (): void {
    $request = PaymentRequest::factory()->depositPix()->create([
        'notes' => 'before',
    ]);
    $costCenter = CostCenter::factory()->for($request->branch)->create();
    $appropriation = Appropriation::factory()->forCompany($request->branch->company)->create();
    $original = $request->currentMaterialFingerprint();

    $request->update([
        'notes' => 'after notes change',
        'cost_center_id' => $costCenter->getKey(),
        'appropriation_id' => $appropriation->getKey(),
    ]);

    expect($request->fresh()->currentMaterialFingerprint())->toBe($original);
});

it('hasApprovedForLaunch only when approved fingerprint matches', function (): void {
    $request = PaymentRequest::factory()->depositPix()->create();
    $fingerprint = $request->currentMaterialFingerprint();
    $approver = User::factory()->adm()->approver()->create();

    Approval::factory()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->approved()
        ->state(['material_fingerprint' => $fingerprint])
        ->create();

    expect($request->fresh()->hasApprovedForLaunch())->toBeTrue();

    $request->update(['net_amount' => bcadd((string) $request->net_amount, '10.00', 2)]);

    expect($request->fresh()->hasApprovedForLaunch())->toBeFalse();
});
