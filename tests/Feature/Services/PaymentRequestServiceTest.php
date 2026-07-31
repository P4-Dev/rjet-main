<?php

declare(strict_types=1);

use App\DTOs\PaymentRequestBankDetailsData;
use App\DTOs\PaymentRequestData;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Enums\PixKeyType;
use App\Enums\UserRole;
use App\Events\PaymentRequest\PaymentRequestCreated;
use App\Exceptions\PaymentRequestException;
use App\Models\Appropriation;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentRequestService;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentRequestFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->service = app(PaymentRequestService::class);
});

function makePaymentRequestData(array $overrides = []): PaymentRequestData
{
    $branch = $overrides['branch'] ?? Branch::factory()->create();
    $supplier = $overrides['supplier'] ?? Supplier::factory()->create();
    $costCenter = $overrides['cost_center'] ?? CostCenter::factory()->for($branch)->create();

    $branchId = array_key_exists('branch_id', $overrides)
        ? $overrides['branch_id']
        : (string) $branch->getKey();

    return new PaymentRequestData(
        branchId: $branchId !== null ? (string) $branchId : null,
        supplierId: (string) ($overrides['supplier_id'] ?? $supplier->getKey()),
        costCenterId: (string) ($overrides['cost_center_id'] ?? $costCenter->getKey()),
        appropriationId: $overrides['appropriation_id'] ?? null,
        paymentMethod: $overrides['payment_method'] ?? PaymentMethod::Deposit,
        grossAmount: $overrides['gross_amount'] ?? '1000.00',
        discountAmount: $overrides['discount_amount'] ?? '100.00',
        dueDate: $overrides['due_date'] ?? CarbonImmutable::now()->addDays(7),
        notes: $overrides['notes'] ?? null,
        bankDetails: $overrides['bank_details'] ?? new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'financeiro@exemplo.com',
        ),
    );
}

it('creates a payment request with requested status and calculated net amount', function (): void {
    Event::fake([PaymentRequestCreated::class]);

    $actor = User::factory()->create(['role' => UserRole::Adm]);
    $data = makePaymentRequestData();

    $request = $this->service->create($data, $actor);

    expect($request->status)->toBe(PaymentRequestStatus::Requested)
        ->and($request->net_amount)->toBe('900.00')
        ->and($request->bankDetails)->not->toBeNull()
        ->and($request->statusHistories)->toHaveCount(1)
        ->and($request->statusHistories->first()->from_status)->toBeNull()
        ->and($request->statusHistories->first()->to_status)->toBe(PaymentRequestStatus::Requested);

    Event::assertDispatched(PaymentRequestCreated::class);
});

it('resolves the only linked branch for a cliente without branch_id', function (): void {
    $branch = Branch::factory()->create();
    $cliente = User::factory()->cliente()->withBranches([$branch])->create();

    $data = makePaymentRequestData([
        'branch' => $branch,
        'branch_id' => null,
        'cost_center' => CostCenter::factory()->for($branch)->create(),
    ]);

    $request = $this->service->create($data, $cliente);

    expect($request->branch_id)->toBe($branch->getKey());
});

it('uses the default branch when cliente has multiple branches', function (): void {
    $default = Branch::factory()->create();
    $other = Branch::factory()->create();
    $cliente = User::factory()->cliente()->withBranches([$default, $other])->create();

    $data = makePaymentRequestData([
        'branch' => $default,
        'branch_id' => null,
        'cost_center' => CostCenter::factory()->for($default)->create(),
    ]);

    $request = $this->service->create($data, $cliente);

    expect($request->branch_id)->toBe($default->getKey());
});

it('rejects cliente without default when multiple branches and no branch_id', function (): void {
    $a = Branch::factory()->create();
    $b = Branch::factory()->create();
    $cliente = User::factory()->cliente()->create();
    $cliente->branches()->attach($a->getKey(), [
        'id' => (string) \Illuminate\Support\Str::orderedUuid(),
        'is_default' => false,
    ]);
    $cliente->branches()->attach($b->getKey(), [
        'id' => (string) \Illuminate\Support\Str::orderedUuid(),
        'is_default' => false,
    ]);

    $data = makePaymentRequestData(['branch' => $a, 'branch_id' => null]);

    expect(fn () => $this->service->create($data, $cliente))
        ->toThrow(PaymentRequestException::class);
});

it('rejects branch not linked to cliente', function (): void {
    $allowed = Branch::factory()->create();
    $foreign = Branch::factory()->create();
    $cliente = User::factory()->cliente()->withBranches([$allowed])->create();

    $data = makePaymentRequestData([
        'branch' => $foreign,
        'cost_center' => CostCenter::factory()->for($foreign)->create(),
    ]);

    expect(fn () => $this->service->create($data, $cliente))
        ->toThrow(PaymentRequestException::class);
});

it('requires appropriation when company flag is enabled', function (): void {
    $company = Company::factory()->requiresAppropriation()->create();
    $branch = Branch::factory()->for($company)->create();
    $actor = User::factory()->create(['role' => UserRole::Adm]);

    $data = makePaymentRequestData([
        'branch' => $branch,
        'cost_center' => CostCenter::factory()->for($branch)->create(),
        'appropriation_id' => null,
    ]);

    expect(fn () => $this->service->create($data, $actor))
        ->toThrow(PaymentRequestException::class);
});

it('creates without appropriation when company does not require it', function (): void {
    $company = Company::factory()->create(['is_appropriation_required' => false]);
    $branch = Branch::factory()->for($company)->create();
    $actor = User::factory()->create(['role' => UserRole::Adm]);

    $request = $this->service->create(makePaymentRequestData([
        'branch' => $branch,
        'cost_center' => CostCenter::factory()->for($branch)->create(),
    ]), $actor);

    expect($request->appropriation_id)->toBeNull();
});

it('requires boleto attachment on create', function (): void {
    $actor = User::factory()->create(['role' => UserRole::Adm]);
    $data = makePaymentRequestData([
        'payment_method' => PaymentMethod::Boleto,
        'bank_details' => new PaymentRequestBankDetailsData(
            digitableLine: PaymentRequestFactory::VALID_DIGITABLE_LINE,
        ),
    ]);

    expect(fn () => $this->service->create($data, $actor, attachmentCount: 0))
        ->toThrow(PaymentRequestException::class);

    $request = $this->service->create($data, $actor, attachmentCount: 1);

    expect($request)->toBeInstanceOf(PaymentRequest::class);
});

it('creates boleto with attachments in the same transaction', function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);

    $actor = User::factory()->create(['role' => UserRole::Adm]);
    $file = \Illuminate\Http\UploadedFile::fake()->create('boleto.pdf', 40, 'application/pdf');
    $path = $file->store('tmp', 'local');

    $data = makePaymentRequestData([
        'payment_method' => PaymentMethod::Boleto,
        'bank_details' => new PaymentRequestBankDetailsData(
            digitableLine: PaymentRequestFactory::VALID_DIGITABLE_LINE,
        ),
    ]);

    $request = $this->service->create(
        $data,
        $actor,
        attachmentPaths: [$path],
        attachmentOriginalNames: [$path => 'boleto.pdf'],
    );

    expect($request->attachments)->toHaveCount(1)
        ->and($request->has_attachments)->toBeTrue();
});

it('rolls back create when attachment path fails mime validation', function (): void {
    Storage::fake('local');
    config(['rjet.attachments.disk' => 'local']);

    $actor = User::factory()->create(['role' => UserRole::Adm]);
    $file = \Illuminate\Http\UploadedFile::fake()->create('nfe.xml', 10, 'text/xml');
    $path = $file->store('tmp', 'local');

    $data = makePaymentRequestData([
        'payment_method' => PaymentMethod::Boleto,
        'bank_details' => new PaymentRequestBankDetailsData(
            digitableLine: PaymentRequestFactory::VALID_DIGITABLE_LINE,
        ),
    ]);

    expect(fn () => $this->service->create($data, $actor, attachmentPaths: [$path]))
        ->toThrow(\App\Exceptions\AttachmentException::class);

    expect(PaymentRequest::query()->count())->toBe(0);
});

it('recalculates net amount on update ignoring divergent payload', function (): void {
    $actor = User::factory()->create(['role' => UserRole::Adm]);
    $request = $this->service->create(makePaymentRequestData(), $actor);

    $updated = $this->service->update($request, makePaymentRequestData([
        'branch' => $request->branch,
        'supplier_id' => $request->supplier_id,
        'cost_center_id' => $request->cost_center_id,
        'gross_amount' => '500.00',
        'discount_amount' => '50.00',
    ]), $actor);

    expect($updated->net_amount)->toBe('450.00');
});

it('soft deletes while keeping status history', function (): void {
    $actor = User::factory()->create(['role' => UserRole::Adm]);
    $request = $this->service->create(makePaymentRequestData(), $actor);

    $this->service->delete($request);

    expect($request->fresh()->trashed())->toBeTrue()
        ->and($request->statusHistories()->count())->toBe(1);
});
