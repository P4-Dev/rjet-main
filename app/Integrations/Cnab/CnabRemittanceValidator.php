<?php

declare(strict_types=1);

namespace App\Integrations\Cnab;

use App\DTOs\CnabRemittanceData;
use App\DTOs\CnabRemittanceResult;
use App\DTOs\CnabValidationError;

/**
 * Bank-agnostic structural checks for CNAB 240 files, based on the FEBRABAN common positions
 * (bank 1-3, batch 4-7, record type 8, sequence 9-13, trailers 18-41, NSA 158-163).
 */
final class CnabRemittanceValidator
{
    private const LINE_LENGTH = 240;

    /**
     * @return list<CnabValidationError>
     */
    public function validate(string $content, CnabRemittanceData $data, CnabRemittanceResult $result): array
    {
        $lineEnding = $data->config['line_ending'];

        if ($content === '' || ! str_ends_with($content, $lineEnding)) {
            return [new CnabValidationError('line_length_invalid', params: ['line' => 1])];
        }

        $lines = explode($lineEnding, substr($content, 0, -strlen($lineEnding)));

        $errors = $this->validateLines($lines, $data->debitAccount['bank_code']);

        if ($errors !== []) {
            return $errors;
        }

        return [
            ...$this->validateStructure($lines),
            ...$this->validateTotals($lines, $data, $result),
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<CnabValidationError>
     */
    private function validateLines(array $lines, string $bankCode): array
    {
        $errors = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;

            if (strlen($line) !== self::LINE_LENGTH) {
                $errors[] = new CnabValidationError('line_length_invalid', params: ['line' => $lineNumber]);

                continue;
            }

            if (preg_match('/[^\x20-\x7E]/', $line) === 1) {
                $errors[] = new CnabValidationError('non_ascii_character', params: ['line' => $lineNumber]);
            }

            if (substr($line, 0, 3) !== $bankCode) {
                $errors[] = new CnabValidationError('bank_code_mismatch', params: ['line' => $lineNumber]);
            }
        }

        return $errors;
    }

    /**
     * Expected order: 0 → (1 → 3+ → 5)+ → 9, with contiguous batch numbers and record sequences.
     *
     * @param  list<string>  $lines
     * @return list<CnabValidationError>
     */
    private function validateStructure(array $lines): array
    {
        $lastIndex = count($lines) - 1;

        if ($lastIndex < 3 || $lines[0][7] !== '0' || $lines[$lastIndex][7] !== '9') {
            return [new CnabValidationError('record_order_invalid', params: ['line' => 1])];
        }

        $expectedBatch = 0;
        $expectedSequence = 1;
        $state = 'file_header';

        for ($index = 1; $index < $lastIndex; $index++) {
            $line = $lines[$index];
            $lineNumber = $index + 1;
            $type = $line[7];
            $batch = (int) substr($line, 3, 4);

            $validTransition = match ($type) {
                '1' => in_array($state, ['file_header', 'batch_trailer'], true),
                '3' => in_array($state, ['batch_header', 'detail'], true),
                '5' => $state === 'detail',
                default => false,
            };

            if (! $validTransition) {
                return [new CnabValidationError('record_order_invalid', params: ['line' => $lineNumber])];
            }

            if ($type === '1') {
                $expectedBatch++;
                $expectedSequence = 1;
            }

            if ($batch !== $expectedBatch) {
                return [new CnabValidationError('sequence_gap', params: ['line' => $lineNumber])];
            }

            if ($type === '3') {
                if ((int) substr($line, 8, 5) !== $expectedSequence) {
                    return [new CnabValidationError('sequence_gap', params: ['line' => $lineNumber])];
                }

                $expectedSequence++;
            }

            $state = match ($type) {
                '1' => 'batch_header',
                '3' => 'detail',
                '5' => 'batch_trailer',
            };
        }

        if ($state !== 'batch_trailer') {
            return [new CnabValidationError('record_order_invalid', params: ['line' => $lastIndex + 1])];
        }

        return [];
    }

    /**
     * @param  list<string>  $lines
     * @return list<CnabValidationError>
     */
    private function validateTotals(array $lines, CnabRemittanceData $data, CnabRemittanceResult $result): array
    {
        $errors = [];
        $amountsByItem = [];

        foreach ($data->items as $item) {
            $amountsByItem[$item->settlementItemId] = $item->amount;
        }

        $expectedByBatch = [];

        foreach ($result->placements as $itemId => $placement) {
            $batch = $placement['batch_number'];
            $expectedByBatch[$batch] = bcadd($expectedByBatch[$batch] ?? '0.00', $amountsByItem[$itemId] ?? '0.00', 2);
        }

        $batchRecords = 0;
        $batchCount = 0;
        $fileSum = '0.00';

        foreach (array_slice($lines, 1, -1) as $line) {
            $batchRecords++;

            if ($line[7] === '1') {
                $batchRecords = 1;
                $batchCount++;
            }

            if ($line[7] !== '5') {
                continue;
            }

            $batch = (int) substr($line, 3, 4);
            $declaredRecords = (int) substr($line, 17, 6);
            $declaredSum = bcdiv(ltrim(substr($line, 23, 18), '0') ?: '0', '100', 2);
            $fileSum = bcadd($fileSum, $declaredSum, 2);

            if ($declaredRecords !== $batchRecords || bccomp($declaredSum, $expectedByBatch[$batch] ?? '0.00', 2) !== 0) {
                $errors[] = new CnabValidationError('batch_trailer_mismatch', params: ['batch' => $batch]);
            }
        }

        $trailer = $lines[count($lines) - 1];

        if ((int) substr($trailer, 17, 6) !== $batchCount || (int) substr($trailer, 23, 6) !== count($lines)) {
            $errors[] = new CnabValidationError('file_trailer_mismatch');
        }

        $expectedTotal = $data->totalAmount();

        if (bccomp($fileSum, $expectedTotal, 2) !== 0 || bccomp($result->totalAmount, $expectedTotal, 2) !== 0) {
            $errors[] = new CnabValidationError('total_amount_mismatch');
        }

        if ((int) substr($lines[0], 157, 6) !== $data->fileSequence) {
            $errors[] = new CnabValidationError('file_sequence_mismatch');
        }

        return $errors;
    }
}
