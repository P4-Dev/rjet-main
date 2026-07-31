<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\AppropriationException;
use App\Exceptions\BankException;
use App\Exceptions\BranchException;
use App\Exceptions\CostCenterException;
use App\Exceptions\SupplierException;
use App\Models\Appropriation;
use App\Models\Attachment;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestBankDetails;
use App\Models\PaymentRequestStatusHistory;
use App\Models\Supplier;
use App\Services\AppropriationService;
use App\Services\AttachmentService;
use App\Services\BankService;
use App\Services\BranchService;
use App\Services\CostCenterService;
use App\Services\SupplierService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);
});

it('soft deletes bank details and attachments but keeps history', function (): void {
    $request = PaymentRequest::factory()->boleto()->create();
    $file = UploadedFile::fake()->create('a.pdf', 20, 'application/pdf');
    $attachment = app(AttachmentService::class)->storeUploadedFile($request, $file);
    PaymentRequestStatusHistory::factory()->create(['payment_request_id' => $request->getKey()]);

    $request->delete();

    expect($request->fresh()->trashed())->toBeTrue()
        ->and(PaymentRequestBankDetails::withTrashed()->where('payment_request_id', $request->getKey())->first()->trashed())->toBeTrue()
        ->and(Attachment::withTrashed()->find($attachment->getKey())->trashed())->toBeTrue()
        ->and(PaymentRequestStatusHistory::query()->where('payment_request_id', $request->getKey())->count())->toBeGreaterThan(0);
});

it('restores bank details and attachments with threshold', function (): void {
    $request = PaymentRequest::factory()->boleto()->create();
    $file = UploadedFile::fake()->create('a.pdf', 20, 'application/pdf');
    app(AttachmentService::class)->storeUploadedFile($request, $file);

    $request->delete();
    $request->restore();

    expect($request->fresh()->trashed())->toBeFalse()
        ->and($request->bankDetails()->exists())->toBeTrue()
        ->and($request->attachments()->exists())->toBeTrue();
});

it('force deletes attachments from storage and history via fk', function (): void {
    $request = PaymentRequest::factory()->boleto()->create();
    $file = UploadedFile::fake()->create('a.pdf', 20, 'application/pdf');
    $attachment = app(AttachmentService::class)->storeUploadedFile($request, $file);
    $path = $attachment->path;
    PaymentRequestStatusHistory::factory()->create(['payment_request_id' => $request->getKey()]);

    $request->forceDelete();

    Storage::disk('local')->assertMissing($path);
    expect(PaymentRequestStatusHistory::query()->where('payment_request_id', $request->getKey())->count())->toBe(0);
});

it('cascades company soft and force delete through payment requests', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $request = PaymentRequest::factory()->forBranch($branch)->boleto()->create();
    $file = UploadedFile::fake()->create('a.pdf', 20, 'application/pdf');
    $attachment = app(AttachmentService::class)->storeUploadedFile($request, $file);
    $path = $attachment->path;
    $companyId = $company->getKey();
    $requestId = $request->getKey();

    $company->delete();

    expect(PaymentRequest::withTrashed()->find($requestId)->trashed())->toBeTrue();

    Company::withTrashed()->findOrFail($companyId)->forceDelete();

    expect(PaymentRequest::withTrashed()->find($requestId))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('guards related services against payment request dependencies', function (): void {
    $request = PaymentRequest::factory()->depositTransfer()->create();

    expect(fn () => app(SupplierService::class)->delete($request->supplier))
        ->toThrow(SupplierException::class)
        ->and(fn () => app(BranchService::class)->ensureDeletable($request->branch))
        ->toThrow(BranchException::class)
        ->and(fn () => app(CostCenterService::class)->ensureDeletable($request->costCenter))
        ->toThrow(CostCenterException::class)
        ->and(fn () => app(BankService::class)->ensureDeletable($request->bankDetails->bank))
        ->toThrow(BankException::class);

    $appropriation = Appropriation::factory()->create();
    PaymentRequest::factory()->create(['appropriation_id' => $appropriation->getKey()]);

    expect(fn () => app(AppropriationService::class)->ensureDeletable($appropriation))
        ->toThrow(AppropriationException::class);
});
