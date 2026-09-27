<?php

use App\DTOs\CnabRemittanceData;
use App\DTOs\CnabRemittanceItemData;
use App\Enums\AccountType;
use App\Enums\CnabLayout;
use App\Enums\CnabPaymentType;
use App\Enums\PixKeyType;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Services\AttachmentBatchService;
use App\Services\PaymentSettlementService;
use Carbon\CarbonImmutable;
use Database\Factories\AttachmentBatchFactory;
use Database\Factories\AttachmentBatchItemFactory;
use Database\Factories\PaymentRequestFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Creates a batch with one item per entry; each entry may customize the item factory.
 * Physical files are written to the (faked) attachments disk.
 *
 * @param  list<(Closure(AttachmentBatchItemFactory): AttachmentBatchItemFactory)|null>  $itemStates
 */
function createAttachmentBatchWithItems(
    AttachmentBatchFactory $batchFactory,
    array $itemStates,
): AttachmentBatch {
    $batch = $batchFactory->create();

    foreach (array_values($itemStates) as $index => $state) {
        $factory = AttachmentBatchItem::factory()
            ->for($batch, 'batch')
            ->state(['sort_order' => $index]);

        $item = ($state !== null ? $state($factory) : $factory)->create();
        $attachment = $item->attachment;

        Storage::disk($attachment->disk)->put($attachment->path, 'file-'.$index);
    }

    app(AttachmentBatchService::class)->syncCounters($batch);

    return $batch->refresh();
}

/**
 * Draft settlement dated today on an Itaú account with a live CNAB config; one active item per
 * payment request factory (defaults to a single valid transfer).
 *
 * @param  list<PaymentRequestFactory>  $requestFactories
 */
function createCnabReadySettlement(array $requestFactories = [], int $lastFileSequence = 0): PaymentSettlement
{
    $account = BranchBankAccount::factory()->itau()->create();
    CnabConfig::factory()->forAccount($account)->withSequence($lastFileSequence)->create();
    $settlement = PaymentSettlement::factory()->forAccount($account)->create();

    foreach ($requestFactories ?: [PaymentRequest::factory()->depositTransfer()] as $factory) {
        $request = $factory->launched()->forBranch($settlement->branch)->create();
        PaymentSettlementItem::factory()->for($settlement, 'settlement')->forPaymentRequest($request)->create();
    }

    app(PaymentSettlementService::class)->recalculateTotals($settlement);

    return $settlement->refresh();
}

/**
 * Deterministic adapter input for golden files and structural validation.
 *
 * @param  list<CnabRemittanceItemData>  $items
 */
function makeCnabRemittanceData(array $items, int $fileSequence = 1): CnabRemittanceData
{
    return new CnabRemittanceData(
        layout: CnabLayout::Itau240,
        fileSequence: $fileSequence,
        generatedAt: CarbonImmutable::parse('2026-09-28 10:15:30', 'America/Sao_Paulo'),
        paymentDate: CarbonImmutable::parse('2026-09-28', 'America/Sao_Paulo'),
        company: ['document' => '11222333000181', 'name' => 'RJET FILIAL TESTE LTDA'],
        debitAccount: ['bank_code' => '341', 'agency' => '1234', 'agency_digit' => null, 'account_number' => '56789', 'account_digit' => '0'],
        config: ['agreement_code' => null, 'wallet_code' => null, 'payment_type_code' => '20', 'line_ending' => "\r\n"],
        items: $items,
    );
}

/**
 * @param  'boleto_itau'|'boleto_other'|'ted'|'credit_itau'|'pix_key'  $kind
 */
function makeCnabItemData(string $kind, int $index = 1, string $amount = '150.00'): CnabRemittanceItemData
{
    $base = [
        'settlementItemId' => sprintf('item-%s-%d', $kind, $index),
        'reference' => sprintf('%020d', $index),
        'amount' => $amount,
        'dueDate' => CarbonImmutable::parse('2026-09-30', 'America/Sao_Paulo'),
        'beneficiaryName' => 'FORNECEDOR TESTE '.$index,
        'beneficiaryDocument' => '11444777000161',
    ];

    return match ($kind) {
        'boleto_itau' => new CnabRemittanceItemData(...$base, paymentType: CnabPaymentType::Boleto, barcode: '34198999900001500001571234567890123456789012'),
        'boleto_other' => new CnabRemittanceItemData(...$base, paymentType: CnabPaymentType::Boleto, barcode: '23791100000000123451234567890123456789012345'),
        'ted' => new CnabRemittanceItemData(...$base, paymentType: CnabPaymentType::Transfer, beneficiaryBankCode: '237', agency: '4321', accountNumber: '9876543', accountDigit: '1', accountType: AccountType::Checking),
        'credit_itau' => new CnabRemittanceItemData(...$base, paymentType: CnabPaymentType::Transfer, beneficiaryBankCode: '341', agency: '4321', accountNumber: '98765', accountDigit: '2', accountType: AccountType::Checking),
        'pix_key' => new CnabRemittanceItemData(...$base, paymentType: CnabPaymentType::PixKey, pixKeyType: PixKeyType::Email, pixKey: 'financeiro@fornecedor.com.br'),
    };
}
