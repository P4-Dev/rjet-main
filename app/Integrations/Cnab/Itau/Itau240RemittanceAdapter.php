<?php

declare(strict_types=1);

namespace App\Integrations\Cnab\Itau;

use App\DTOs\CnabRemittanceData;
use App\DTOs\CnabRemittanceItemData;
use App\DTOs\CnabRemittanceResult;
use App\DTOs\CnabValidationError;
use App\Enums\CnabLayout;
use App\Enums\CnabPaymentType;
use App\Enums\PixKeyType;
use App\Integrations\Cnab\CnabRemittanceAdapter;
use App\Integrations\Cnab\FixedWidthFormatter;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use App\Support\BoletoBarcode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class Itau240RemittanceAdapter implements CnabRemittanceAdapter
{
    private const BANK_CODE = '341';

    /** @var array{agency: int, account: int} */
    private const ITAU_ACCOUNT_LIMITS = ['agency' => 4, 'account' => 6];

    /** @var array{agency: int, account: int} */
    private const OTHER_BANK_ACCOUNT_LIMITS = ['agency' => 5, 'account' => 12];

    private const ACCOUNT_DIGIT_LENGTH = 1;

    public function layout(): CnabLayout
    {
        return CnabLayout::Itau240;
    }

    public function supportsBankCode(string $code): bool
    {
        return $code === self::BANK_CODE;
    }

    public function validateConfig(CnabConfig $config, BranchBankAccount $account, Branch $branch): array
    {
        $errors = [];

        if (! $this->isValidDocument($branch->document)) {
            $errors[] = new CnabValidationError('branch_document_invalid', 'branch.document');
        }

        $agency = (string) $account->agency;
        $accountNumber = (string) $account->account_number;

        if (
            preg_match('/^\d{1,5}$/', $agency) !== 1
            || preg_match('/^\d{1,12}$/', $accountNumber) !== 1
            || blank($account->account_digit)
        ) {
            $errors[] = new CnabValidationError('account_data_incomplete', 'branch_bank_account');
        }

        if (! $this->supportsBankCode((string) $account->bank_code)) {
            $errors[] = new CnabValidationError('bank_code_mismatch', 'branch_bank_account.bank_code');
        }

        return $errors;
    }

    public function validateItem(CnabRemittanceItemData $item, CarbonImmutable $paymentDate): array
    {
        return match ($item->paymentType) {
            CnabPaymentType::Boleto => $this->validateBoleto($item),
            CnabPaymentType::Transfer => $this->validateTransfer($item),
            CnabPaymentType::PixKey => $this->validatePixKey($item),
        };
    }

    public function paymentFormCode(CnabRemittanceItemData $item, string $debitBankCode): string
    {
        return match ($item->paymentType) {
            CnabPaymentType::Boleto => BoletoBarcode::bankCode((string) $item->barcode) === self::BANK_CODE
                ? Itau240Layout::FORM_BOLETO_ITAU
                : Itau240Layout::FORM_BOLETO_OTHER,
            CnabPaymentType::Transfer => $item->beneficiaryBankCode === $debitBankCode
                ? Itau240Layout::FORM_CREDIT_ITAU
                : Itau240Layout::FORM_TED,
            CnabPaymentType::PixKey => Itau240Layout::FORM_PIX_TRANSFER,
        };
    }

    public function build(CnabRemittanceData $data): CnabRemittanceResult
    {
        $bankCode = $data->debitAccount['bank_code'];
        $lineEnding = $data->config['line_ending'];

        /** @var array<string, list<CnabRemittanceItemData>> $groups */
        $groups = [];

        foreach ($data->items as $item) {
            $groups[$this->paymentFormCode($item, $bankCode)][] = $item;
        }

        ksort($groups, SORT_STRING);

        $lines = [$this->fileHeader($data)];
        $placements = [];
        $batchNumber = 0;
        $totalAmount = '0.00';

        foreach ($groups as $formCode => $items) {
            $batchNumber++;
            $formCode = (string) $formCode;
            $batchLines = [$this->batchHeader($data, $batchNumber, $formCode)];
            $sequence = 0;
            $batchAmount = '0.00';

            foreach ($items as $item) {
                $placements[$item->settlementItemId] = [
                    'batch_number' => $batchNumber,
                    'record_sequence' => $sequence + 1,
                    'payment_form_code' => $formCode,
                ];

                foreach ($this->detailRecords($data, $item, $batchNumber, $formCode, $sequence) as $record) {
                    $batchLines[] = $record;
                }

                $sequence = count($batchLines) - 1;
                $batchAmount = bcadd($batchAmount, $item->amount, 2);
            }

            $batchLines[] = Itau240Layout::render(Itau240Layout::BATCH_TRAILER, [
                'bank_code' => $bankCode,
                'batch' => $batchNumber,
                'record_type' => 5,
                'records_count' => count($batchLines) + 1,
                'total_amount' => FixedWidthFormatter::amountInCents($batchAmount, 18),
            ]);

            array_push($lines, ...$batchLines);
            $totalAmount = bcadd($totalAmount, $batchAmount, 2);
        }

        $lines[] = Itau240Layout::render(Itau240Layout::FILE_TRAILER, [
            'bank_code' => $bankCode,
            'batch' => 9999,
            'record_type' => 9,
            'batches_count' => $batchNumber,
            'records_count' => count($lines) + 1,
        ]);

        return new CnabRemittanceResult(
            content: implode($lineEnding, $lines).$lineEnding,
            recordsCount: count($lines),
            batchesCount: $batchNumber,
            placements: $placements,
            totalAmount: $totalAmount,
        );
    }

    public function fileName(CnabRemittanceData $data): string
    {
        return sprintf(
            '%s_%s_%s_%06d.rem',
            $data->debitAccount['bank_code'],
            BoletoBarcode::digits($data->company['document']),
            $data->paymentDate->format('Ymd'),
            $data->fileSequence,
        );
    }

    private function fileHeader(CnabRemittanceData $data): string
    {
        return Itau240Layout::render(Itau240Layout::FILE_HEADER, [
            'bank_code' => $data->debitAccount['bank_code'],
            'batch' => 0,
            'record_type' => 0,
            'file_layout' => Itau240Layout::FILE_LAYOUT_VERSION,
            'company_document_type' => FixedWidthFormatter::documentType($data->company['document']),
            'company_document' => $data->company['document'],
            'agency' => $data->debitAccount['agency'],
            'account' => $data->debitAccount['account_number'],
            'account_digit' => $data->debitAccount['account_digit'],
            'company_name' => $data->company['name'],
            'bank_name' => Itau240Layout::BANK_NAME,
            'file_code' => 1,
            'generation_date' => FixedWidthFormatter::date($data->generatedAt),
            'generation_time' => FixedWidthFormatter::time($data->generatedAt),
            'file_sequence' => $data->fileSequence,
        ]);
    }

    private function batchHeader(CnabRemittanceData $data, int $batchNumber, string $formCode): string
    {
        $isBoleto = in_array($formCode, [Itau240Layout::FORM_BOLETO_ITAU, Itau240Layout::FORM_BOLETO_OTHER], true);

        return Itau240Layout::render(Itau240Layout::BATCH_HEADER, [
            'bank_code' => $data->debitAccount['bank_code'],
            'batch' => $batchNumber,
            'record_type' => 1,
            'operation' => 'C',
            'payment_type' => $data->config['payment_type_code'],
            'payment_form' => $formCode,
            'batch_layout' => $isBoleto ? Itau240Layout::BATCH_LAYOUT_BOLETO : Itau240Layout::BATCH_LAYOUT_TRANSFER,
            'company_document_type' => FixedWidthFormatter::documentType($data->company['document']),
            'company_document' => $data->company['document'],
            'agreement_code' => $data->config['agreement_code'],
            'agency' => $data->debitAccount['agency'],
            'account' => $data->debitAccount['account_number'],
            'account_digit' => $data->debitAccount['account_digit'],
            'company_name' => $data->company['name'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function detailRecords(
        CnabRemittanceData $data,
        CnabRemittanceItemData $item,
        int $batchNumber,
        string $formCode,
        int $sequence,
    ): array {
        $control = [
            'bank_code' => $data->debitAccount['bank_code'],
            'batch' => $batchNumber,
            'record_type' => 3,
        ];

        if ($item->paymentType === CnabPaymentType::Boleto) {
            return [
                Itau240Layout::render(Itau240Layout::SEGMENT_J, $control + [
                    'record_sequence' => $sequence + 1,
                    'segment' => 'J',
                    'barcode' => $item->barcode,
                    'beneficiary_name' => $item->beneficiaryName,
                    'due_date' => FixedWidthFormatter::date($item->dueDate),
                    'face_amount' => FixedWidthFormatter::amountInCents($item->amount, 15),
                    'payment_date' => FixedWidthFormatter::date($data->paymentDate),
                    'payment_amount' => FixedWidthFormatter::amountInCents($item->amount, 15),
                    'reference' => $item->reference,
                ]),
                Itau240Layout::render(Itau240Layout::SEGMENT_J52, $control + [
                    'record_sequence' => $sequence + 2,
                    'segment' => 'J',
                    'record_id' => 52,
                    'payer_document_type' => FixedWidthFormatter::documentType($data->company['document']),
                    'payer_document' => $data->company['document'],
                    'payer_name' => $data->company['name'],
                    'beneficiary_document_type' => FixedWidthFormatter::documentType($item->beneficiaryDocument),
                    'beneficiary_document' => $item->beneficiaryDocument,
                    'beneficiary_name' => $item->beneficiaryName,
                ]),
            ];
        }

        $isPix = $item->paymentType === CnabPaymentType::PixKey;

        return [
            Itau240Layout::render(Itau240Layout::SEGMENT_A, $control + [
                'record_sequence' => $sequence + 1,
                'segment' => 'A',
                'clearing_code' => match ($formCode) {
                    Itau240Layout::FORM_TED => Itau240Layout::CLEARING_TED,
                    Itau240Layout::FORM_PIX_TRANSFER => Itau240Layout::CLEARING_PIX,
                    default => Itau240Layout::CLEARING_CREDIT_ITAU,
                },
                'beneficiary_bank' => $isPix ? null : $item->beneficiaryBankCode,
                'beneficiary_account' => $isPix ? null : $this->beneficiaryAccount($item),
                'beneficiary_name' => $item->beneficiaryName,
                'reference' => $item->reference,
                'payment_date' => FixedWidthFormatter::date($data->paymentDate),
                'currency' => 'REA',
                'amount' => FixedWidthFormatter::amountInCents($item->amount, 15),
                'beneficiary_document' => $item->beneficiaryDocument,
                'ted_purpose' => $formCode === Itau240Layout::FORM_TED ? Itau240Layout::TED_PURPOSE_SUPPLIERS : null,
            ]),
            Itau240Layout::render(Itau240Layout::SEGMENT_B, $control + [
                'record_sequence' => $sequence + 2,
                'segment' => 'B',
                'pix_key_type' => $isPix ? $this->pixKeyTypeCode($item->pixKeyType) : null,
                'beneficiary_document_type' => FixedWidthFormatter::documentType($item->beneficiaryDocument),
                'beneficiary_document' => $item->beneficiaryDocument,
                'pix_key' => $isPix ? $item->pixKey : null,
            ]),
        ];
    }

    /**
     * Itaú favored account: "0AAAA 000000CCCCCC D" for Itaú, "AAAAA CCCCCCCCCCCC D" for other banks.
     */
    private function beneficiaryAccount(CnabRemittanceItemData $item): string
    {
        $limits = $this->accountLimits($item->beneficiaryBankCode);
        $digit = FixedWidthFormatter::alpha($item->accountDigit, self::ACCOUNT_DIGIT_LENGTH);
        $agency = FixedWidthFormatter::numeric($item->agency, $limits['agency']);
        $account = FixedWidthFormatter::numeric($item->accountNumber, $limits['account']);

        if ($item->beneficiaryBankCode === self::BANK_CODE) {
            return '0'.$agency.' 000000'.$account.' '.$digit;
        }

        return $agency.' '.$account.' '.$digit;
    }

    /**
     * @return array{agency: int, account: int}
     */
    private function accountLimits(?string $beneficiaryBankCode): array
    {
        return $beneficiaryBankCode === self::BANK_CODE ? self::ITAU_ACCOUNT_LIMITS : self::OTHER_BANK_ACCOUNT_LIMITS;
    }

    private function pixKeyTypeCode(?PixKeyType $type): string
    {
        return match ($type) {
            PixKeyType::Phone => '01',
            PixKeyType::Email => '02',
            PixKeyType::Cpf => '03',
            PixKeyType::Random, null => '04',
        };
    }

    /**
     * @return list<CnabValidationError>
     */
    private function validateBoleto(CnabRemittanceItemData $item): array
    {
        $barcode = (string) $item->barcode;

        if ($barcode === '') {
            return [$this->itemError($item, 'missing_barcode', 'barcode')];
        }

        if (BoletoBarcode::isUtilityBill($barcode)) {
            return [$this->itemError($item, 'utility_bill_not_supported', 'barcode')];
        }

        if (! BoletoBarcode::isValid($barcode)) {
            return [$this->itemError($item, 'invalid_barcode', 'barcode')];
        }

        return [];
    }

    /**
     * @return list<CnabValidationError>
     */
    private function validateTransfer(CnabRemittanceItemData $item): array
    {
        $errors = [];

        if (blank($item->beneficiaryBankCode)) {
            $errors[] = $this->itemError($item, 'beneficiary_bank_missing', 'bank_id');
        }

        if (blank($item->agency) || blank($item->accountNumber) || blank($item->accountDigit)) {
            $errors[] = $this->itemError($item, 'missing_transfer_data', 'account_number');
        } else {
            array_push($errors, ...$this->accountLengthErrors($item));
        }

        if (! $this->isValidDocument($item->beneficiaryDocument)) {
            $errors[] = $this->itemError($item, 'invalid_holder_document', 'holder_document');
        }

        if (blank($item->beneficiaryName)) {
            $errors[] = $this->itemError($item, 'missing_beneficiary_name', 'holder_name');
        }

        return $errors;
    }

    /**
     * @return list<CnabValidationError>
     */
    private function validatePixKey(CnabRemittanceItemData $item): array
    {
        if (blank($item->pixKey) || $item->pixKeyType === null) {
            return [$this->itemError($item, 'missing_pix_key', 'pix_key')];
        }

        $errors = [];
        $validator = Validator::make(
            ['pix_key' => $item->pixKey],
            ['pix_key' => $item->pixKeyType->validationRules()],
        );

        if ($validator->fails()) {
            $errors[] = $this->itemError($item, 'invalid_pix_key', 'pix_key');
        }

        if (filled($item->beneficiaryDocument) && ! $this->isValidDocument($item->beneficiaryDocument)) {
            $errors[] = $this->itemError($item, 'invalid_holder_document', 'holder_document');
        }

        if (blank($item->beneficiaryName)) {
            $errors[] = $this->itemError($item, 'missing_beneficiary_name', 'holder_name');
        }

        return $errors;
    }

    /**
     * Mirrors what beneficiaryAccount() renders, so nothing is truncated in the file.
     *
     * @return list<CnabValidationError>
     */
    private function accountLengthErrors(CnabRemittanceItemData $item): array
    {
        $limits = $this->accountLimits($item->beneficiaryBankCode);
        $errors = [];

        if (strlen(BoletoBarcode::digits((string) $item->agency)) > $limits['agency']) {
            $errors[] = $this->itemError($item, 'beneficiary_agency_too_long', 'agency', ['max' => $limits['agency']]);
        }

        if (strlen(BoletoBarcode::digits((string) $item->accountNumber)) > $limits['account']) {
            $errors[] = $this->itemError($item, 'beneficiary_account_too_long', 'account_number', ['max' => $limits['account']]);
        }

        if (mb_strlen(trim((string) $item->accountDigit)) > self::ACCOUNT_DIGIT_LENGTH) {
            $errors[] = $this->itemError($item, 'beneficiary_account_digit_too_long', 'account_digit', ['max' => self::ACCOUNT_DIGIT_LENGTH]);
        }

        return $errors;
    }

    /**
     * @param  array<string, int|string>  $params
     */
    private function itemError(CnabRemittanceItemData $item, string $code, string $field, array $params = []): CnabValidationError
    {
        return new CnabValidationError($code, $field, $params, $item->settlementItemId);
    }

    private function isValidDocument(?string $document): bool
    {
        $digits = BoletoBarcode::digits((string) $document);
        $rule = match (strlen($digits)) {
            11 => new ValidCpf,
            14 => new ValidCnpj,
            default => null,
        };

        return $rule !== null
            && Validator::make(['document' => $digits], ['document' => [$rule]])->passes();
    }
}
