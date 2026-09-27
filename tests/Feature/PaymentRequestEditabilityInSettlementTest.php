<?php

declare(strict_types=1);

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use App\Services\PaymentSettlementService;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

it('locks a payment request in a draft settlement for operador but not for adm', function (): void {
    $item = PaymentSettlementItem::factory()->create();
    $paymentRequest = $item->paymentRequest;

    expect($paymentRequest->isEditableBy(User::factory()->operador()->create()))->toBeFalse()
        ->and($paymentRequest->isEditableBy(User::factory()->adm()->create()))->toBeTrue();
});

it('unlocks the payment request for operador after the settlement is cancelled', function (): void {
    $item = PaymentSettlementItem::factory()->create();
    $operador = User::factory()->operador()->create();

    app(PaymentSettlementService::class)->cancel($item->settlement, $operador, 'Wrong selection');

    expect($item->paymentRequest->fresh()->isEditableBy($operador))->toBeTrue();
});

it('decides editability from the eager loaded settlement item without extra queries', function (): void {
    $operador = User::factory()->operador()->create();
    actingAs($operador);
    $draftItem = PaymentSettlementItem::factory()->create();
    $cancelledItem = PaymentSettlementItem::factory()
        ->for(PaymentSettlement::factory()->cancelled(), 'settlement')
        ->create();
    $free = PaymentRequest::factory()->launched()->depositTransfer()->create();

    $requests = PaymentRequestResource::getEloquentQuery()->get()->keyBy(fn (PaymentRequest $request): string => (string) $request->getKey());

    DB::enableQueryLog();
    $editability = [
        $requests[(string) $draftItem->payment_request_id]->isEditableBy($operador),
        $requests[(string) $cancelledItem->payment_request_id]->isEditableBy($operador),
        $requests[(string) $free->getKey()]->isEditableBy($operador),
    ];

    expect($editability)->toBe([false, true, true])
        ->and(DB::getQueryLog())->toBe([]);
});
