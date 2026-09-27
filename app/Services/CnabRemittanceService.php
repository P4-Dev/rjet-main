<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CnabRemittanceData;
use App\DTOs\CnabRemittanceItemData;
use App\DTOs\CnabRemittanceResult;
use App\DTOs\CnabValidationError;
use App\DTOs\CnabValidationReport;
use App\Enums\CnabPaymentType;
use App\Enums\DepositType;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\CnabException;
use App\Integrations\Cnab\CnabAdapterResolver;
use App\Integrations\Cnab\CnabRemittanceAdapter;
use App\Integrations\Cnab\CnabRemittanceValidator;
use App\Models\CnabConfig;
use App\Models\CnabFileItem;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Support\BoletoBarcode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Pure remittance logic: builds and validates CNAB content without persisting anything.
 */
final class CnabRemittanceService
{
    public function __construct(
        private readonly CnabAdapterResolver $adapterResolver,
        private readonly CnabRemittanceValidator $validator,
    ) {}

    /**
     * Dry-run: config + items + structural validation with a provisional NSA (never consumed).
     *
     * @throws CnabException
     */
    public function validate(PaymentSettlement $settlement, ?CnabConfig $config = null): CnabValidationReport
    {
        $config ??= $this->liveConfigFor($settlement);
        $adapter = $this->adapterResolver->for($config->layout);
        $settlement->loadMissing(['branch', 'branchBankAccount']);

        $configErrors = $adapter->validateConfig($config, $settlement->branchBankAccount, $settlement->branch);

        $itemErrors = [];
        $validItems = [];

        foreach ($this->mapItems($settlement, $adapter) as $itemId => [$itemData, $errors]) {
            if ($errors !== []) {
                $itemErrors[$itemId] = $errors;

                continue;
            }

            $validItems[] = $itemData;
        }

        if ($validItems === [] && $itemErrors === []) {
            return new CnabValidationReport($configErrors, structuralErrors: [new CnabValidationError('no_valid_items')]);
        }

        if ($configErrors !== [] || $validItems === []) {
            return new CnabValidationReport($configErrors, $itemErrors);
        }

        $provisionalSequence = $config->last_file_sequence + 1;

        if ($provisionalSequence > CnabConfig::MAX_FILE_SEQUENCE) {
            throw CnabException::fileSequenceExhausted();
        }

        $data = $this->makeData($settlement, $config, $provisionalSequence, $validItems);
        $result = $adapter->build($data);

        return new CnabValidationReport(
            $configErrors,
            $itemErrors,
            $this->validator->validate($result->content, $data, $result),
        );
    }

    /**
     * Builds the adapter input with every active item; callers must validate first.
     *
     * @throws CnabException
     */
    public function buildData(PaymentSettlement $settlement, CnabConfig $config, int $fileSequence): CnabRemittanceData
    {
        $adapter = $this->adapterResolver->for($config->layout);
        $settlement->loadMissing(['branch', 'branchBankAccount']);

        $items = [];

        foreach ($this->mapItems($settlement, $adapter) as [$itemData]) {
            if ($itemData !== null) {
                $items[] = $itemData;
            }
        }

        return $this->makeData($settlement, $config, $fileSequence, $items);
    }

    /**
     * @throws CnabException
     */
    public function render(CnabRemittanceData $data): CnabRemittanceResult
    {
        return $this->adapterResolver->for($data->layout)->build($data);
    }

    /**
     * @throws CnabException
     */
    public function liveConfigFor(PaymentSettlement $settlement): CnabConfig
    {
        /** @var CnabConfig|null $config */
        $config = CnabConfig::query()->forAccount((string) $settlement->branch_bank_account_id)->first();

        if ($config === null) {
            throw CnabException::configNotFound();
        }

        if (! $config->is_active) {
            throw CnabException::configInactive();
        }

        return $config;
    }

    /**
     * Deterministic order: payment type → due date → payment request id.
     *
     * @return array<string, array{?CnabRemittanceItemData, list<CnabValidationError>}>
     */
    private function mapItems(PaymentSettlement $settlement, CnabRemittanceAdapter $adapter): array
    {
        /** @var Collection<int, PaymentSettlementItem> $items */
        $items = $settlement->activeItems()
            ->with(['paymentRequest.bankDetails.bank', 'paymentRequest.supplier'])
            ->get();

        $paymentDate = $this->paymentDate($settlement);

        $sorted = $items->sortBy([
            fn (PaymentSettlementItem $a, PaymentSettlementItem $b): int => CnabPaymentType::intendedFor($a->paymentRequest)->value <=> CnabPaymentType::intendedFor($b->paymentRequest)->value,
            fn (PaymentSettlementItem $a, PaymentSettlementItem $b): int => $a->paymentRequest->due_date?->toDateString() <=> $b->paymentRequest->due_date?->toDateString(),
            fn (PaymentSettlementItem $a, PaymentSettlementItem $b): int => (string) $a->payment_request_id <=> (string) $b->payment_request_id,
        ]);

        $mapped = [];

        foreach ($sorted as $item) {
            $itemId = (string) $item->getKey();
            $errors = $this->commonItemErrors($item, $paymentDate);
            $itemData = $this->toItemData($item, $paymentDate);

            if ($itemData === null) {
                $errors[] = new CnabValidationError($this->unsupportedTypeCode($item->paymentRequest), settlementItemId: $itemId);
            } else {
                array_push($errors, ...$adapter->validateItem($itemData, $paymentDate));
            }

            $mapped[$itemId] = [$itemData, $errors];
        }

        return $mapped;
    }

    /**
     * @return list<CnabValidationError>
     */
    private function commonItemErrors(PaymentSettlementItem $item, CarbonImmutable $paymentDate): array
    {
        $paymentRequest = $item->paymentRequest;
        $itemId = (string) $item->getKey();
        $errors = [];

        if ($paymentRequest->trashed() || $paymentRequest->status !== PaymentRequestStatus::Launched) {
            $errors[] = new CnabValidationError('payment_request_unavailable', settlementItemId: $itemId);
        }

        if (bccomp((string) $paymentRequest->net_amount, (string) $item->amount, 2) !== 0) {
            $errors[] = new CnabValidationError('amount_changed', 'amount', settlementItemId: $itemId);
        }

        if (bccomp((string) $item->amount, '0', 2) <= 0) {
            $errors[] = new CnabValidationError('amount_not_positive', 'amount', settlementItemId: $itemId);
        }

        if ($paymentDate->toDateString() < today(PaymentSettlement::TIMEZONE)->toDateString()) {
            $errors[] = new CnabValidationError('payment_date_in_past', 'settlement_date', settlementItemId: $itemId);
        }

        return $errors;
    }

    private function unsupportedTypeCode(PaymentRequest $paymentRequest): string
    {
        $details = $paymentRequest->bankDetails;

        if ($details?->deposit_type === DepositType::Pix) {
            return $details->hasPixQrCode() ? 'pix_qr_code_not_supported' : 'missing_pix_key';
        }

        return 'missing_transfer_data';
    }

    private function toItemData(PaymentSettlementItem $item, CarbonImmutable $paymentDate): ?CnabRemittanceItemData
    {
        $paymentRequest = $item->paymentRequest;
        $paymentType = CnabPaymentType::fromPaymentRequest($paymentRequest);

        if ($paymentType === null) {
            return null;
        }

        $details = $paymentRequest->bankDetails;
        $supplier = $paymentRequest->supplier;
        $supplierName = $supplier?->legal_name ?: $supplier?->name;

        $base = [
            'settlementItemId' => (string) $item->getKey(),
            'reference' => CnabFileItem::referenceFor((string) $paymentRequest->getKey()),
            'paymentType' => $paymentType,
            'amount' => (string) $item->amount,
            'dueDate' => $paymentRequest->due_date !== null
                ? CarbonImmutable::parse($paymentRequest->due_date->toDateString(), PaymentSettlement::TIMEZONE)
                : $paymentDate,
        ];

        return match ($paymentType) {
            CnabPaymentType::Boleto => new CnabRemittanceItemData(
                ...$base,
                beneficiaryName: $supplierName,
                beneficiaryDocument: $supplier?->document,
                barcode: $this->resolveBarcode($details?->digitable_line, $details?->barcode),
            ),
            CnabPaymentType::Transfer => new CnabRemittanceItemData(
                ...$base,
                beneficiaryName: $details?->holder_name ?: $supplierName,
                beneficiaryDocument: $details?->holder_document,
                beneficiaryBankCode: $details?->bank?->code,
                agency: $details?->agency,
                agencyDigit: $details?->agency_digit,
                accountNumber: $details?->account_number,
                accountDigit: $details?->account_digit,
                accountType: $details?->account_type,
            ),
            CnabPaymentType::PixKey => new CnabRemittanceItemData(
                ...$base,
                beneficiaryName: $details?->holder_name ?: $supplierName,
                beneficiaryDocument: $details?->holder_document ?: $supplier?->document,
                pixKeyType: $details?->pix_key_type,
                pixKey: $details?->pix_key,
            ),
        };
    }

    /**
     * Prefers the digitable line (checked digits); invalid or utility codes are kept raw so the
     * adapter reports them.
     */
    private function resolveBarcode(?string $digitableLine, ?string $barcode): ?string
    {
        $lineDigits = BoletoBarcode::digits((string) $digitableLine);

        if ($lineDigits !== '') {
            if (strlen($lineDigits) !== 47) {
                return $lineDigits;
            }

            try {
                return BoletoBarcode::fromDigitableLine($lineDigits);
            } catch (InvalidArgumentException) {
                return $lineDigits;
            }
        }

        $barcodeDigits = BoletoBarcode::digits((string) $barcode);

        return $barcodeDigits === '' ? null : $barcodeDigits;
    }

    /**
     * @param  list<CnabRemittanceItemData>  $items
     */
    private function makeData(PaymentSettlement $settlement, CnabConfig $config, int $fileSequence, array $items): CnabRemittanceData
    {
        $account = $settlement->branchBankAccount;
        $branch = $settlement->branch;

        return new CnabRemittanceData(
            layout: $config->layout,
            fileSequence: $fileSequence,
            generatedAt: CarbonImmutable::now(PaymentSettlement::TIMEZONE),
            paymentDate: $this->paymentDate($settlement),
            company: [
                'document' => BoletoBarcode::digits((string) $branch->document),
                'name' => (string) ($config->company_name ?: $branch->legal_name),
            ],
            debitAccount: [
                'bank_code' => (string) $account->bank_code,
                'agency' => (string) $account->agency,
                'agency_digit' => $account->agency_digit,
                'account_number' => (string) $account->account_number,
                'account_digit' => $account->account_digit,
            ],
            config: [
                'agreement_code' => $config->agreement_code,
                'wallet_code' => $config->wallet_code,
                'payment_type_code' => (string) $config->payment_type_code,
                'line_ending' => (string) config('rjet.cnab.line_ending'),
            ],
            items: $items,
        );
    }

    private function paymentDate(PaymentSettlement $settlement): CarbonImmutable
    {
        return CarbonImmutable::parse($settlement->settlement_date->toDateString(), PaymentSettlement::TIMEZONE);
    }
}
