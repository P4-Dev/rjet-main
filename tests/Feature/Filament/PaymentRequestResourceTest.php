<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Enums\PixKeyType;
use App\Filament\Resources\PaymentRequests\Pages\CreatePaymentRequest;
use App\Filament\Resources\PaymentRequests\Pages\EditPaymentRequest;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Filament\Resources\PaymentRequests\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\PaymentRequests\RelationManagers\StatusHistoriesRelationManager;
use App\Models\Appropriation;
use App\Models\Attachment;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestStatusHistory;
use App\Models\Supplier;
use App\Models\User;
use Database\Factories\PaymentRequestFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

/**
 * @return array{branch: Branch, supplier: Supplier, costCenter: CostCenter}
 */
function paymentRequestCreateContext(array $overrides = []): array
{
    $branch = $overrides['branch'] ?? Branch::factory()->create();
    $supplier = $overrides['supplier'] ?? Supplier::factory()->create([
        'default_payment_method' => PaymentMethod::Deposit,
    ]);
    $costCenter = $overrides['cost_center'] ?? CostCenter::factory()->for($branch)->create();

    return [
        'branch' => $branch,
        'supplier' => $supplier,
        'costCenter' => $costCenter,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function baseCreateFormData(Branch $branch, Supplier $supplier, CostCenter $costCenter, array $overrides = []): array
{
    return array_replace_recursive([
        'branch_id' => $branch->getKey(),
        'supplier_id' => $supplier->getKey(),
        'cost_center_id' => $costCenter->getKey(),
        'appropriation_id' => null,
        'payment_method' => PaymentMethod::Deposit->value,
        'gross_amount' => '1000.00',
        'discount_amount' => '0.00',
        'due_date' => now()->addDays(7)->toDateString(),
        'notes' => null,
        'bankDetails' => [
            'deposit_type' => DepositType::Pix->value,
            'pix_key_type' => PixKeyType::Email->value,
            'pix_key' => 'financeiro@exemplo.com',
        ],
    ], $overrides);
}

beforeEach(function (): void {
    Storage::fake((string) config('rjet.attachments.disk'));
});

describe('authorization', function (): void {
    it('loads the list page for cliente, operador and adm', function (string $role): void {
        $user = match ($role) {
            'cliente' => User::factory()->cliente()->withBranches(1)->create(),
            'operador' => User::factory()->operador()->create(),
            default => User::factory()->adm()->create(),
        };

        actingAs($user);

        Livewire::test(ListPaymentRequests::class)
            ->assertOk();
    })->with(['cliente', 'operador', 'adm']);

    it('lets cliente see only payment requests from linked branches', function (): void {
        $mine = Branch::factory()->create();
        $theirs = Branch::factory()->create();
        $cliente = User::factory()->cliente()->withBranches([$mine])->create();

        $visible = PaymentRequest::factory()->forBranch($mine)->create();
        $hidden = PaymentRequest::factory()->forBranch($theirs)->create();

        actingAs($cliente);

        Livewire::test(ListPaymentRequests::class)
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$hidden]);
    });

    it('shows trashed filter only for adm', function (): void {
        actingAs(User::factory()->adm()->create());

        Livewire::test(ListPaymentRequests::class)
            ->assertTableFilterVisible('trashed');

        actingAs(User::factory()->cliente()->withBranches(1)->create());

        Livewire::test(ListPaymentRequests::class)
            ->assertTableFilterHidden('trashed');

        actingAs(User::factory()->operador()->create());

        Livewire::test(ListPaymentRequests::class)
            ->assertTableFilterHidden('trashed');
    });

    it('hides delete action for cliente and operador and shows it for adm', function (): void {
        $request = PaymentRequest::factory()->create();

        actingAs(User::factory()->cliente()->withBranches([$request->branch])->create());

        Livewire::test(ListPaymentRequests::class)
            ->assertActionHidden(TestAction::make('delete')->table($request));

        actingAs(User::factory()->operador()->create());

        Livewire::test(ListPaymentRequests::class)
            ->assertActionHidden(TestAction::make('delete')->table($request));

        actingAs(User::factory()->adm()->create());

        Livewire::test(ListPaymentRequests::class)
            ->assertActionVisible(TestAction::make('delete')->table($request));
    });

    it('strips forged branch_id from cliente before save', function (): void {
        $mine = Branch::factory()->create();
        $theirs = Branch::factory()->create();
        $cliente = User::factory()->cliente()->withBranches([$mine])->create();
        $request = PaymentRequest::factory()->forBranch($mine)->requested()->create();

        actingAs($cliente);

        $page = Livewire::test(EditPaymentRequest::class, ['record' => $request->getKey()]);

        $mutated = invade($page->instance())->mutateFormDataBeforeSave([
            'branch_id' => $theirs->getKey(),
            'gross_amount' => (string) $request->gross_amount,
            'discount_amount' => (string) $request->discount_amount,
            'notes' => 'updated by cliente',
        ]);

        expect($mutated)->not->toHaveKey('branch_id')
            ->and($mutated['notes'])->toBe('updated by cliente');
    });
});

describe('create conditional form', function (): void {
    it('requires digitable_line for boleto and hides pix and transfer fields', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'payment_method' => PaymentMethod::Boleto->value,
                'bankDetails' => [
                    'deposit_type' => null,
                    'pix_key_type' => null,
                    'pix_key' => null,
                    'digitable_line' => null,
                ],
                'attachment_files' => [UploadedFile::fake()->create('boleto.pdf', 100, 'application/pdf')],
            ]))
            ->assertFormFieldIsVisible('bankDetails.digitable_line')
            ->assertFormFieldIsHidden('bankDetails.pix_key_type')
            ->assertFormFieldIsHidden('bankDetails.pix_key')
            ->assertFormFieldIsHidden('bankDetails.holder_document')
            ->assertFormFieldIsHidden('bankDetails.bank_id')
            ->call('create')
            ->assertHasFormErrors(['bankDetails.digitable_line' => 'required']);
    });

    it('requires attachment_files for boleto', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'payment_method' => PaymentMethod::Boleto->value,
                'bankDetails' => [
                    'deposit_type' => null,
                    'pix_key_type' => null,
                    'pix_key' => null,
                    'digitable_line' => PaymentRequestFactory::VALID_DIGITABLE_LINE,
                ],
                'attachment_files' => [],
            ]))
            ->call('create')
            ->assertHasFormErrors(['attachment_files' => 'required']);
    });

    it('requires deposit_type for deposit and hides boleto fields', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'payment_method' => PaymentMethod::Deposit->value,
                'bankDetails' => [
                    'deposit_type' => null,
                    'pix_key_type' => null,
                    'pix_key' => null,
                ],
            ]))
            ->assertFormFieldIsVisible('bankDetails.deposit_type')
            ->assertFormFieldIsHidden('bankDetails.digitable_line')
            ->assertFormFieldIsHidden('bankDetails.barcode')
            ->call('create')
            ->assertHasFormErrors(['bankDetails.deposit_type' => 'required']);
    });

    it('shows pix fields and hides transfer fields for deposit + pix', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'payment_method' => PaymentMethod::Deposit->value,
                'bankDetails' => [
                    'deposit_type' => DepositType::Pix->value,
                    'pix_key_type' => PixKeyType::Email->value,
                    'pix_key' => 'pix@exemplo.com',
                ],
            ]))
            ->assertFormFieldIsVisible('bankDetails.pix_key_type')
            ->assertFormFieldIsVisible('bankDetails.pix_key')
            ->assertFormFieldIsHidden('bankDetails.holder_document')
            ->assertFormFieldIsHidden('bankDetails.bank_id');
    });

    it('accepts deposit + pix with only pix_qr_code', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'payment_method' => PaymentMethod::Deposit->value,
                'bankDetails' => [
                    'deposit_type' => DepositType::Pix->value,
                    'pix_key_type' => null,
                    'pix_key' => null,
                    'pix_qr_code' => '00020126580014BR.GOV.BCB.PIX0136'.fake()->uuid().'5204000053039865802BR5913Empresa Teste6009SAO PAULO62070503***6304ABCD',
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        assertDatabaseHas(PaymentRequest::class, [
            'branch_id' => $branch->getKey(),
            'payment_method' => PaymentMethod::Deposit->value,
        ]);
    });

    it('requires pix fields when deposit + pix has neither key nor qr code', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'payment_method' => PaymentMethod::Deposit->value,
                'bankDetails' => [
                    'deposit_type' => DepositType::Pix->value,
                    'pix_key_type' => null,
                    'pix_key' => null,
                    'pix_qr_code' => null,
                ],
            ]))
            ->call('create')
            ->assertHasFormErrors([
                'bankDetails.pix_key_type' => 'required',
                'bankDetails.pix_key' => 'required',
                'bankDetails.pix_qr_code' => 'required',
            ]);
    });

    it('requires transfer settlement fields for deposit + transfer', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'payment_method' => PaymentMethod::Deposit->value,
                'bankDetails' => [
                    'deposit_type' => DepositType::Transfer->value,
                    'pix_key_type' => null,
                    'pix_key' => null,
                    'holder_document' => null,
                    'bank_id' => null,
                    'agency' => null,
                    'account_number' => null,
                    'account_digit' => null,
                    'account_type' => null,
                ],
            ]))
            ->assertFormFieldIsVisible('bankDetails.holder_document')
            ->assertFormFieldIsVisible('bankDetails.bank_id')
            ->assertFormFieldIsVisible('bankDetails.agency')
            ->assertFormFieldIsVisible('bankDetails.account_number')
            ->assertFormFieldIsVisible('bankDetails.account_digit')
            ->assertFormFieldIsVisible('bankDetails.account_type')
            ->call('create')
            ->assertHasFormErrors([
                'bankDetails.holder_document' => 'required',
                'bankDetails.bank_id' => 'required',
                'bankDetails.agency' => 'required',
                'bankDetails.account_number' => 'required',
                'bankDetails.account_digit' => 'required',
                'bankDetails.account_type' => 'required',
            ]);
    });

    it('does not require agency_digit or holder_name for deposit + transfer', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();
        $bank = Bank::factory()->create();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'payment_method' => PaymentMethod::Deposit->value,
                'bankDetails' => [
                    'deposit_type' => DepositType::Transfer->value,
                    'pix_key_type' => null,
                    'pix_key' => null,
                    'holder_document' => fake()->cnpj(false),
                    'holder_name' => null,
                    'bank_id' => $bank->getKey(),
                    'agency' => '1234',
                    'agency_digit' => null,
                    'account_number' => '123456',
                    'account_digit' => '7',
                    'account_type' => AccountType::Checking->value,
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();
    });

    it('recalculates net_amount when gross and discount are filled', function (): void {
        actingAs(User::factory()->adm()->create());

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm([
                'gross_amount' => '1000.00',
                'discount_amount' => '150.00',
            ])
            ->assertSchemaStateSet([
                'net_amount' => '850.00',
            ]);
    });

    it('rejects discount_amount greater than gross_amount', function (): void {
        actingAs(User::factory()->adm()->create());
        ['branch' => $branch, 'supplier' => $supplier, 'costCenter' => $costCenter] = paymentRequestCreateContext();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'gross_amount' => '100.00',
                'discount_amount' => '150.00',
            ]))
            ->call('create')
            ->assertHasFormErrors(['discount_amount' => 'lte']);
    });

    it('requires appropriation_id when company flag is enabled', function (): void {
        actingAs(User::factory()->adm()->create());
        $company = Company::factory()->requiresAppropriation()->create();
        $branch = Branch::factory()->for($company)->create();
        $supplier = Supplier::factory()->create(['default_payment_method' => PaymentMethod::Deposit]);
        $costCenter = CostCenter::factory()->for($branch)->create();
        Appropriation::factory()->for($company)->create();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'appropriation_id' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['appropriation_id' => 'required']);
    });

    it('allows optional appropriation_id when company flag is disabled', function (): void {
        actingAs(User::factory()->adm()->create());
        $company = Company::factory()->create(['is_appropriation_required' => false]);
        $branch = Branch::factory()->for($company)->create();
        $supplier = Supplier::factory()->create(['default_payment_method' => PaymentMethod::Deposit]);
        $costCenter = CostCenter::factory()->for($branch)->create();

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'appropriation_id' => null,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();
    });

    it('pre-fills and locks branch_id for cliente with a single branch and still persists it', function (): void {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create(['default_payment_method' => PaymentMethod::Deposit]);
        $costCenter = CostCenter::factory()->for($branch)->create();
        $cliente = User::factory()->cliente()->withBranches([$branch])->create();

        actingAs($cliente);

        Livewire::test(CreatePaymentRequest::class)
            ->assertFormFieldIsDisabled('branch_id')
            ->assertSchemaStateSet(['branch_id' => $branch->getKey()])
            ->fillForm(baseCreateFormData($branch, $supplier, $costCenter, [
                'bankDetails' => [
                    'deposit_type' => DepositType::Pix->value,
                    'pix_key_type' => null,
                    'pix_key' => null,
                    'pix_qr_code' => '00020126580014BR.GOV.BCB.PIX0136'.fake()->uuid().'5204000053039865802BR5913Empresa Teste6009SAO PAULO62070503***6304ABCD',
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        expect(PaymentRequest::query()->first()->branch_id)->toBe($branch->getKey());
    });

    it('fills payment_method from supplier suggestion when supplier_id is selected', function (): void {
        actingAs(User::factory()->adm()->create());
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create([
            'default_payment_method' => PaymentMethod::Deposit,
        ]);

        Livewire::test(CreatePaymentRequest::class)
            ->fillForm([
                'branch_id' => $branch->getKey(),
                'supplier_id' => $supplier->getKey(),
            ])
            ->assertSchemaStateSet([
                'payment_method' => PaymentMethod::Deposit,
            ]);
    });

    it('keeps cost_center_id options empty before a branch is selected', function (): void {
        actingAs(User::factory()->adm()->create());
        CostCenter::factory()->create();

        Livewire::test(CreatePaymentRequest::class)
            ->assertFormFieldExists('cost_center_id', checkFieldUsing: function (Select $field): bool {
                return $field->getOptions() === [];
            });
    });
});

describe('filters', function (): void {
    it('filters by status', function (): void {
        actingAs(User::factory()->adm()->create());

        $requested = PaymentRequest::factory()->requested()->create();
        $launched = PaymentRequest::factory()->launched()->create();

        Livewire::test(ListPaymentRequests::class)
            ->filterTable('status', PaymentRequestStatus::Requested)
            ->assertCanSeeTableRecords([$requested])
            ->assertCanNotSeeTableRecords([$launched]);
    });

    it('filters by branch', function (): void {
        actingAs(User::factory()->adm()->create());

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $match = PaymentRequest::factory()->forBranch($branchA)->create();
        $other = PaymentRequest::factory()->forBranch($branchB)->create();

        Livewire::test(ListPaymentRequests::class)
            ->filterTable('branch', $branchA)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    });

    it('filters by company via branch relationship', function (): void {
        actingAs(User::factory()->adm()->create());

        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $match = PaymentRequest::factory()->forBranch(Branch::factory()->for($companyA)->create())->create();
        $other = PaymentRequest::factory()->forBranch(Branch::factory()->for($companyB)->create())->create();

        Livewire::test(ListPaymentRequests::class)
            ->filterTable('company', $companyA->getKey())
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    });

    it('filters by due_date range', function (): void {
        actingAs(User::factory()->adm()->create());

        $inside = PaymentRequest::factory()->create(['due_date' => now()->addDays(5)->toDateString()]);
        $outside = PaymentRequest::factory()->create(['due_date' => now()->addDays(40)->toDateString()]);

        Livewire::test(ListPaymentRequests::class)
            ->filterTable('due_date', [
                'due_from' => now()->toDateString(),
                'due_until' => now()->addDays(10)->toDateString(),
            ])
            ->assertCanSeeTableRecords([$inside])
            ->assertCanNotSeeTableRecords([$outside]);
    });

    it('filters trashed records for adm', function (): void {
        actingAs(User::factory()->adm()->create());

        $active = PaymentRequest::factory()->create();
        $trashed = PaymentRequest::factory()->create();
        $trashed->delete();

        Livewire::test(ListPaymentRequests::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$trashed])
            ->assertCanNotSeeTableRecords([$active]);
    });
});

describe('actions', function (): void {
    it('hides transition status action for cliente and shows it for operador', function (): void {
        $branch = Branch::factory()->create();
        $request = PaymentRequest::factory()->forBranch($branch)->requested()->create();

        actingAs(User::factory()->cliente()->withBranches([$branch])->create());

        Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
            ->assertActionHidden('transitionStatus');

        actingAs(User::factory()->operador()->create());

        Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
            ->assertActionVisible('transitionStatus');
    });

    it('transitions requested to launched and creates history', function (): void {
        $operador = User::factory()->operador()->approver()->create();
        actingAs($operador);

        $request = PaymentRequest::factory()->depositPix()->requested()->create();
        \App\Models\Approval::factory()
            ->approved()
            ->forPaymentRequest($request)
            ->forApprover($operador)
            ->create();

        Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
            ->callAction('transitionStatus', [
                'to_status' => PaymentRequestStatus::Launched->value,
                'notes' => 'Liberado para pagamento',
            ])
            ->assertHasNoActionErrors();

        expect($request->fresh()->status)->toBe(PaymentRequestStatus::Launched);

        assertDatabaseHas(PaymentRequestStatusHistory::class, [
            'payment_request_id' => $request->getKey(),
            'to_status' => PaymentRequestStatus::Launched->value,
        ]);
    });

    it('hides transition status action when status is settled', function (): void {
        actingAs(User::factory()->operador()->create());

        $request = PaymentRequest::factory()->settled()->create();

        Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
            ->assertActionHidden('transitionStatus');
    });

    it('blocks cliente from calling transitionStatus via Livewire', function (): void {
        $branch = Branch::factory()->create();
        $request = PaymentRequest::factory()->forBranch($branch)->requested()->create();

        actingAs(User::factory()->cliente()->withBranches([$branch])->create());

        try {
            Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
                ->callAction('transitionStatus', [
                    'to_status' => PaymentRequestStatus::Launched->value,
                ]);
        } catch (Throwable) {
            // Hidden/unauthorized actions may abort or refuse to mount.
        }

        expect($request->fresh()->status)->toBe(PaymentRequestStatus::Requested);
    });
});

describe('relation managers', function (): void {
    it('renders attachments on the attachments relation manager', function (): void {
        actingAs(User::factory()->adm()->create());

        $request = PaymentRequest::factory()->create();
        $attachment = Attachment::factory()->for($request, 'attachable')->create([
            'original_name' => 'nota-fiscal.pdf',
        ]);

        Livewire::test(AttachmentsRelationManager::class, [
            'ownerRecord' => $request,
            'pageClass' => ViewPaymentRequest::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$attachment])
            ->assertSee('nota-fiscal.pdf');
    });

    it('hides attachment create when cliente cannot manage attachments', function (): void {
        $branch = Branch::factory()->create();
        $cliente = User::factory()->cliente()->withBranches([$branch])->create();
        actingAs($cliente);

        $request = PaymentRequest::factory()->forBranch($branch)->launched()->create();

        Livewire::test(AttachmentsRelationManager::class, [
            'ownerRecord' => $request,
            'pageClass' => ViewPaymentRequest::class,
        ])
            ->assertOk()
            ->assertActionDoesNotExist('create');
    });

    it('renders status histories and does not expose create edit or delete', function (): void {
        actingAs(User::factory()->adm()->create());

        $request = PaymentRequest::factory()->create();
        $history = PaymentRequestStatusHistory::factory()->for($request)->create([
            'from_status' => null,
            'to_status' => PaymentRequestStatus::Requested,
            'notes' => 'Criação inicial',
        ]);

        Livewire::test(StatusHistoriesRelationManager::class, [
            'ownerRecord' => $request,
            'pageClass' => ViewPaymentRequest::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$history])
            ->assertSee('Criação inicial')
            ->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    });
});
