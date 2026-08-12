<?php

declare(strict_types=1);

use App\Enums\PaymentRequestStatus;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('shows approve and reject actions for assignee', function (): void {
    $approver = User::factory()->operador()->approver()->create();
    $request = PaymentRequest::factory()->depositPix()->create();
    Approval::factory()->pending()->forPaymentRequest($request)->forApprover($approver)->create();

    actingAs($approver);

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
        ->assertSuccessful()
        ->assertActionVisible('approvePaymentRequest')
        ->assertActionVisible('rejectPaymentRequest');
});

it('requires reason on reject action', function (): void {
    $approver = User::factory()->operador()->approver()->create();
    $request = PaymentRequest::factory()->depositPix()->create();
    Approval::factory()->pending()->forPaymentRequest($request)->forApprover($approver)->create();

    actingAs($approver);

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
        ->callAction('rejectPaymentRequest', data: ['reason' => 'ok'])
        ->assertHasActionErrors(['reason']);
});

it('filters awaiting_my_approval tab', function (): void {
    $approver = User::factory()->operador()->approver()->create();
    $mine = PaymentRequest::factory()->depositPix()->create();
    Approval::factory()->pending()->forPaymentRequest($mine)->forApprover($approver)->create();

    $other = PaymentRequest::factory()->depositPix()->create();
    Approval::factory()->pending()->forPaymentRequest($other)->forApprover(
        User::factory()->operador()->approver()->create()
    )->create();

    actingAs($approver);

    Livewire::test(ListPaymentRequests::class)
        ->assertSuccessful()
        ->set('activeTab', 'awaiting_my_approval')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('shows sla field for adm on company form', function (): void {
    $adm = User::factory()->adm()->create();
    $company = \App\Models\Company::factory()->create();

    actingAs($adm);

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])
        ->assertSuccessful()
        ->assertFormFieldExists('approval_sla_business_days');
});

it('shows approval required error when launching without approval', function (): void {
    $adm = User::factory()->adm()->approver()->create();
    $request = PaymentRequest::factory()->depositPix()->create();

    actingAs($adm);

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
        ->callAction('transitionStatus', data: [
            'to_status' => PaymentRequestStatus::Launched->value,
            'notes' => null,
        ]);

    expect($request->fresh()->status)->toBe(PaymentRequestStatus::Requested);
});

it('shows resubmit action after rejection for requester with access', function (): void {
    $branch = Branch::factory()->create();
    $cliente = User::factory()->cliente()->withBranches([$branch])->create();
    $request = PaymentRequest::factory()->forBranch($branch)->depositPix()->returned()->create([
        'created_by' => $cliente->getKey(),
    ]);

    actingAs($cliente);

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->getKey()])
        ->assertSuccessful()
        ->assertActionVisible('resubmitForApproval')
        ->assertActionHidden('approvePaymentRequest');
});
