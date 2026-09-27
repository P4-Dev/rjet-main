<?php

declare(strict_types=1);

use App\DTOs\CnabValidationError;
use App\DTOs\CnabValidationReport;
use App\Enums\PaymentMethod;
use App\Models\CnabConfig;
use App\Models\CnabFileItem;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Services\CnabRemittanceService;
use Illuminate\Support\Facades\Storage;

/**
 * @return list<string>
 */
function reportCodes(CnabValidationReport $report): array
{
    return [
        ...array_map(fn (CnabValidationError $error): string => $error->code, $report->configErrors),
        ...$report->itemErrorCodes(),
        ...array_map(fn (CnabValidationError $error): string => $error->code, $report->structuralErrors),
    ];
}

function firstPaymentRequestOf(PaymentSettlement $settlement): PaymentRequest
{
    return $settlement->items()->firstOrFail()->paymentRequest()->with('bankDetails')->firstOrFail();
}

it('validates a correct settlement without writing files or consuming the nsa', function (): void {
    Storage::fake('local');
    config(['rjet.cnab.disk' => 'local']);
    $settlement = createCnabReadySettlement([
        PaymentRequest::factory()->depositTransfer(),
        PaymentRequest::factory()->depositPix(),
        PaymentRequest::factory()->boleto(),
    ], lastFileSequence: 4);

    $report = app(CnabRemittanceService::class)->validate($settlement);

    expect(reportCodes($report))->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(CnabConfig::query()->sole()->last_file_sequence)->toBe(4);
});

it('reports the expected error for each invalid situation', function (Closure $break, string $expectedCode): void {
    $settlement = createCnabReadySettlement();
    $break($settlement, firstPaymentRequestOf($settlement));

    $report = app(CnabRemittanceService::class)->validate($settlement->fresh());

    expect($report->isValid())->toBeFalse()
        ->and(reportCodes($report))->toContain($expectedCode);
})->with([
    'pix by qr code only' => [
        fn (PaymentSettlement $settlement, PaymentRequest $request) => $request->bankDetails->forceFill([
            'deposit_type' => 'pix', 'pix_key' => null, 'pix_key_type' => null, 'pix_qr_code' => '00020126580014BR.GOV.BCB.PIX',
        ])->saveQuietly(),
        'pix_qr_code_not_supported',
    ],
    'utility bill' => [
        function (PaymentSettlement $settlement, PaymentRequest $request): void {
            $request->forceFill(['payment_method' => PaymentMethod::Boleto])->saveQuietly();
            $request->bankDetails->forceFill(['deposit_type' => null, 'barcode' => '836200000005667800481000180975657313001589636081'])->saveQuietly();
        },
        'utility_bill_not_supported',
    ],
    'incomplete transfer' => [
        fn (PaymentSettlement $settlement, PaymentRequest $request) => $request->bankDetails->forceFill(['account_number' => null])->saveQuietly(),
        'missing_transfer_data',
    ],
    'invalid holder document' => [
        fn (PaymentSettlement $settlement, PaymentRequest $request) => $request->bankDetails->forceFill(['holder_document' => '11111111111111'])->saveQuietly(),
        'invalid_holder_document',
    ],
    'amount changed' => [
        fn (PaymentSettlement $settlement, PaymentRequest $request) => $request->forceFill(['net_amount' => bcadd((string) $request->net_amount, '0.01', 2)])->saveQuietly(),
        'amount_changed',
    ],
    'payment date in the past' => [
        fn (PaymentSettlement $settlement) => $settlement->forceFill(['settlement_date' => today(PaymentSettlement::TIMEZONE)->subDay()->toDateString()])->saveQuietly(),
        'payment_date_in_past',
    ],
    'trashed payment request' => [
        fn (PaymentSettlement $settlement, PaymentRequest $request) => $request->delete(),
        'payment_request_unavailable',
    ],
    'invalid branch document' => [
        fn (PaymentSettlement $settlement) => $settlement->branch->forceFill(['document' => '11111111111111'])->saveQuietly(),
        'branch_document_invalid',
    ],
]);

it('derives the reference from the last 20 hex digits of the payment request id', function (): void {
    expect(CnabFileItem::referenceFor('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'))->toBe('7E5F8A9B0C1D2E3F4A5B');
});

it('keeps references distinct for payment requests created in sequence', function (): void {
    [$first, $second] = PaymentRequest::factory()->count(2)->launched()->depositTransfer()->create()->all();

    expect(CnabFileItem::referenceFor((string) $first->getKey()))
        ->toBe(strtoupper(substr(str_replace('-', '', (string) $first->getKey()), -20)))
        ->not->toBe(CnabFileItem::referenceFor((string) $second->getKey()));
});
