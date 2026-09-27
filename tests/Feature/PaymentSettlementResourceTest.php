<?php

declare(strict_types=1);

use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Filament\Resources\PaymentSettlements\Pages\CreatePaymentSettlement;
use App\Filament\Resources\PaymentSettlements\Pages\ListPaymentSettlements;
use App\Filament\Resources\PaymentSettlements\Pages\ViewPaymentSettlement;
use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\CnabFile;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('renders list, selection and view pages for staff', function (string $role): void {
    actingAs(User::factory()->{$role}()->create());
    $settlement = PaymentSettlement::factory()->withItems(1)->create();

    Livewire::test(ListPaymentSettlements::class)->assertOk()->assertCanSeeTableRecords([$settlement]);
    Livewire::test(CreatePaymentSettlement::class)->assertOk();
    Livewire::test(ViewPaymentSettlement::class, ['record' => $settlement->getKey()])->assertOk();
})->with(['operador', 'adm']);

it('forbids cliente from every settlement page', function (): void {
    actingAs(User::factory()->cliente()->withBranches(1)->create());
    $settlement = PaymentSettlement::factory()->create();

    Livewire::test(ListPaymentSettlements::class)->assertForbidden();
    Livewire::test(CreatePaymentSettlement::class)->assertForbidden();
    expect(fn () => Livewire::test(ViewPaymentSettlement::class, ['record' => $settlement->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('lists only eligible payment requests on the selection page', function (): void {
    actingAs(User::factory()->operador()->create());
    $eligible = PaymentRequest::factory()->launched()->depositTransfer()->create();
    $requested = PaymentRequest::factory()->requested()->depositTransfer()->create();
    $inSettlement = PaymentSettlement::factory()->withItems(1)->create()->items()->sole()->paymentRequest;

    Livewire::test(CreatePaymentSettlement::class)
        ->assertCanSeeTableRecords([$eligible])
        ->assertCanNotSeeTableRecords([$requested, $inSettlement]);
});

it('creates the settlement from the bulk action and redirects to its view', function (): void {
    actingAs(User::factory()->operador()->create());
    $account = BranchBankAccount::factory()->itau()->create();
    $requests = PaymentRequest::factory()->count(2)->launched()->depositTransfer()->forBranch($account->branch)->create();

    $component = Livewire::test(CreatePaymentSettlement::class)
        ->selectTableRecords($requests->modelKeys())
        ->callAction(TestAction::make('createSettlement')->table()->bulk(), data: [
            'settlement_date' => today(PaymentSettlement::TIMEZONE)->toDateString(),
            'branch_bank_account_id' => $account->getKey(),
        ])
        ->assertHasNoFormErrors();

    $settlement = PaymentSettlement::query()->sole();
    $component->assertRedirect(PaymentSettlementResource::getUrl('view', ['record' => $settlement]));
    expect($settlement->items_count)->toBe(2);
});

it('requires a settlement date and a bank account for a single branch selection', function (): void {
    actingAs(User::factory()->operador()->create());
    $account = BranchBankAccount::factory()->itau()->create();
    $request = PaymentRequest::factory()->launched()->depositTransfer()->forBranch($account->branch)->create();

    Livewire::test(CreatePaymentSettlement::class)
        ->selectTableRecords([$request->getKey()])
        ->callAction(TestAction::make('createSettlement')->table()->bulk(), data: [
            'settlement_date' => null,
            'branch_bank_account_id' => null,
        ])
        ->assertHasFormErrors(['settlement_date' => 'required', 'branch_bank_account_id' => 'required']);

    expect(PaymentSettlement::query()->count())->toBe(0);
});

it('refuses a selection spanning branches without creating anything', function (): void {
    actingAs(User::factory()->operador()->create());
    $account = BranchBankAccount::factory()->itau()->create();
    $requests = [
        PaymentRequest::factory()->launched()->depositTransfer()->forBranch($account->branch)->create(),
        PaymentRequest::factory()->launched()->depositTransfer()->forBranch(Branch::factory()->create())->create(),
    ];

    Livewire::test(CreatePaymentSettlement::class)
        ->selectTableRecords(array_map(fn (PaymentRequest $request): string => (string) $request->getKey(), $requests))
        ->callAction(TestAction::make('createSettlement')->table()->bulk(), data: [
            'settlement_date' => today(PaymentSettlement::TIMEZONE)->toDateString(),
        ])
        ->assertNotified(__('payment_settlements.errors.mixed_branches'));

    expect(PaymentSettlement::query()->count())->toBe(0);
});

it('shows draft actions and hides them once settled', function (): void {
    actingAs(User::factory()->operador()->create());
    $draft = createCnabReadySettlement();
    $settled = PaymentSettlement::factory()->settled()->create();

    Livewire::test(ViewPaymentSettlement::class, ['record' => $draft->getKey()])
        ->assertActionVisible('confirmSettlement')
        ->assertActionVisible('cancelSettlement')
        ->assertActionVisible('generateCnab')
        ->assertActionHidden('downloadCnab');

    Livewire::test(ViewPaymentSettlement::class, ['record' => $settled->getKey()])
        ->assertActionHidden('confirmSettlement')
        ->assertActionHidden('cancelSettlement')
        ->assertActionHidden('generateCnab');
});

it('offers the download once a file is generated', function (): void {
    actingAs(User::factory()->operador()->create());
    $settlement = createCnabReadySettlement();
    CnabFile::factory()->for($settlement, 'settlement')->generated()->create();

    Livewire::test(ViewPaymentSettlement::class, ['record' => $settlement->getKey()])
        ->assertActionVisible('downloadCnab')
        ->assertActionHidden('generateCnab');
});

it('does not offer settled in the payment request status transition', function (): void {
    actingAs(User::factory()->operador()->create());
    $request = PaymentRequest::factory()->launched()->depositTransfer()->create();

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
        ->assertActionHidden('transitionStatus');
});

it('polls the cnab section only while a file is being generated', function (string $state): void {
    actingAs(User::factory()->operador()->create());
    $settlement = createCnabReadySettlement();
    CnabFile::factory()->for($settlement, 'settlement')->{$state}()->create();

    Livewire::test(ViewPaymentSettlement::class, ['record' => $settlement->getKey()])
        ->assertSeeHtml('wire:poll.5s');
})->with(['queued', 'generating']);

it('does not poll the cnab section without a generation in progress', function (string $state): void {
    actingAs(User::factory()->operador()->create());
    $settlement = createCnabReadySettlement();

    if ($state !== 'none') {
        CnabFile::factory()->for($settlement, 'settlement')->{$state}()->create();
    }

    Livewire::test(ViewPaymentSettlement::class, ['record' => $settlement->getKey()])
        ->assertDontSeeHtml('wire:poll.5s');
})->with(['none', 'generated', 'failed']);
