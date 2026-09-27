<?php

declare(strict_types=1);

use App\DTOs\CnabRemittanceItemData;
use App\DTOs\CnabValidationError;
use App\Enums\AccountType;
use App\Enums\CnabFileStatus;
use App\Enums\CnabPaymentType;
use App\Integrations\Cnab\CnabRemittanceValidator;
use App\Integrations\Cnab\FixedWidthFormatter;
use App\Integrations\Cnab\Itau\Itau240Layout;
use App\Integrations\Cnab\Itau\Itau240RemittanceAdapter;
use App\Models\User;
use App\Services\CnabFileService;
use App\Services\CnabRemittanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

function goldenFile(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/cnab/itau240/{$name}.rem"));
}

it('renders each payment form exactly as the golden file', function (string $kind): void {
    $result = (new Itau240RemittanceAdapter)->build(makeCnabRemittanceData([makeCnabItemData($kind)]));

    expect($result->content)->toBe(goldenFile($kind))
        ->and($result->batchesCount)->toBe(1);
})->with(['boleto_itau', 'boleto_other', 'ted', 'credit_itau', 'pix_key']);

it('groups items into one batch per payment form', function (): void {
    $data = makeCnabRemittanceData([
        makeCnabItemData('ted', 1),
        makeCnabItemData('pix_key', 2),
        makeCnabItemData('boleto_itau', 3),
        makeCnabItemData('ted', 4, '10.01'),
        makeCnabItemData('boleto_other', 5),
    ], 42);

    $result = (new Itau240RemittanceAdapter)->build($data);

    expect($result->content)->toBe(goldenFile('mixed'))
        ->and($result->batchesCount)->toBe(4)
        ->and($result->totalAmount)->toBe('610.01')
        ->and($result->placements['item-ted-1'])->toBe(['batch_number' => $result->placements['item-ted-4']['batch_number'], 'record_sequence' => 1, 'payment_form_code' => Itau240Layout::FORM_TED])
        ->and($result->placements['item-ted-4']['record_sequence'])->toBe(3)
        ->and($result->placements['item-boleto_itau-3']['payment_form_code'])->toBe(Itau240Layout::FORM_BOLETO_ITAU)
        ->and($result->placements['item-boleto_other-5']['payment_form_code'])->toBe(Itau240Layout::FORM_BOLETO_OTHER)
        ->and($result->placements['item-pix_key-2']['payment_form_code'])->toBe(Itau240Layout::FORM_PIX_TRANSFER)
        ->and((new CnabRemittanceValidator)->validate($result->content, $data, $result))->toBe([]);
});

it('names the file by bank, company document, payment date and sequence', function (): void {
    $data = makeCnabRemittanceData([makeCnabItemData('ted')], 7);

    expect((new Itau240RemittanceAdapter)->fileName($data))->toBe('341_11222333000181_20260928_000007.rem');
});

function transferItemWithAccount(string $bankCode, string $agency, string $accountNumber, string $accountDigit): CnabRemittanceItemData
{
    return new CnabRemittanceItemData(
        settlementItemId: 'item-oversized',
        reference: sprintf('%020d', 1),
        paymentType: CnabPaymentType::Transfer,
        amount: '150.00',
        dueDate: CarbonImmutable::parse('2026-09-30', 'America/Sao_Paulo'),
        beneficiaryName: 'FORNECEDOR TESTE',
        beneficiaryDocument: '11444777000161',
        beneficiaryBankCode: $bankCode,
        agency: $agency,
        accountNumber: $accountNumber,
        accountDigit: $accountDigit,
        accountType: AccountType::Checking,
    );
}

/**
 * @return list<array{code: string, max: int|string|null}>
 */
function transferErrors(CnabRemittanceItemData $item): array
{
    return array_map(
        fn (CnabValidationError $error): array => ['code' => $error->code, 'max' => $error->params['max'] ?? null],
        (new Itau240RemittanceAdapter)->validateItem($item, CarbonImmutable::parse('2026-09-28', 'America/Sao_Paulo')),
    );
}

it('rejects beneficiary agency, account and digit longer than the bank fields', function (string $bankCode, string $agency, string $account, string $digit, string $code, int $max): void {
    expect(transferErrors(transferItemWithAccount($bankCode, $agency, $account, $digit)))
        ->toBe([['code' => $code, 'max' => $max]]);
})->with([
    'itau account with 7 digits' => ['341', '4321', '9876543', '1', 'beneficiary_account_too_long', 6],
    'itau agency with 5 digits' => ['341', '43210', '98765', '1', 'beneficiary_agency_too_long', 4],
    'other bank account with 13 digits' => ['237', '4321', '1234567890123', '1', 'beneficiary_account_too_long', 12],
    'other bank agency with 6 digits' => ['237', '543210', '9876543', '1', 'beneficiary_agency_too_long', 5],
    'digit with 2 characters' => ['237', '4321', '9876543', '12', 'beneficiary_account_digit_too_long', 1],
]);

it('accepts beneficiary accounts at the field limits counting only digits', function (string $bankCode, string $agency, string $account): void {
    expect(transferErrors(transferItemWithAccount($bankCode, $agency, $account, 'X')))->toBe([]);
})->with([
    'itau' => ['341', '4321', '98.765-4'],
    'other bank' => ['237', '54321', '1234-5678-9012'],
]);

it('refuses to render a truncated numeric field', function (): void {
    FixedWidthFormatter::numeric('1234567890123', 12);
})->throws(LogicException::class);

it('fails the dry-run instead of truncating a 13-digit beneficiary account', function (): void {
    Storage::fake('local');
    config(['rjet.cnab.disk' => 'local']);
    $settlement = createCnabReadySettlement();
    $settlement->items()->sole()->paymentRequest->bankDetails()->update(['account_number' => '1234567890123']);

    $report = app(CnabRemittanceService::class)->validate($settlement->fresh());
    $file = app(CnabFileService::class)->request($settlement, User::factory()->operador()->create())->fresh();

    expect($report->itemErrorCodes())->toContain('beneficiary_account_too_long')
        ->and($file->status)->toBe(CnabFileStatus::Failed)
        ->and($file->file_sequence)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
