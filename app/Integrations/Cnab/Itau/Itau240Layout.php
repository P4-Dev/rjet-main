<?php

declare(strict_types=1);

namespace App\Integrations\Cnab\Itau;

use App\Integrations\Cnab\FixedWidthFormatter;
use LogicException;

/**
 * Field positions for Itaú SISPAG CNAB 240 (payments to suppliers).
 *
 * Reference: SISPAG CNAB 240 layout as consolidated in the phase 7 plan; positions still pending
 * confirmation against the bank's current manual and homologation (P-F7-SPEC / P-F7-HOMOLOG).
 * The NSA uses the FEBRABAN header position (158-163).
 *
 * Each field is [start (1-based), length, kind] where kind is 'n' (numeric) or 'a' (alphanumeric).
 */
final class Itau240Layout
{
    public const LINE_LENGTH = 240;

    public const FILE_LAYOUT_VERSION = '081';

    public const BATCH_LAYOUT_TRANSFER = '040';

    public const BATCH_LAYOUT_BOLETO = '030';

    public const BANK_NAME = 'BANCO ITAU SA';

    public const FORM_CREDIT_ITAU = '01';

    public const FORM_TED = '41';

    public const FORM_PIX_TRANSFER = '45';

    public const FORM_BOLETO_ITAU = '30';

    public const FORM_BOLETO_OTHER = '31';

    public const CLEARING_CREDIT_ITAU = '000';

    public const CLEARING_TED = '018';

    public const CLEARING_PIX = '009';

    public const TED_PURPOSE_SUPPLIERS = '00005';

    /** @var array<string, array{int, int, string}> */
    public const FILE_HEADER = [
        'bank_code' => [1, 3, 'n'],
        'batch' => [4, 4, 'n'],
        'record_type' => [8, 1, 'n'],
        'blank_1' => [9, 6, 'a'],
        'file_layout' => [15, 3, 'n'],
        'company_document_type' => [18, 1, 'n'],
        'company_document' => [19, 14, 'n'],
        'blank_2' => [33, 20, 'a'],
        'agency' => [53, 5, 'n'],
        'blank_3' => [58, 1, 'a'],
        'account' => [59, 12, 'n'],
        'blank_4' => [71, 1, 'a'],
        'account_digit' => [72, 1, 'a'],
        'company_name' => [73, 30, 'a'],
        'bank_name' => [103, 30, 'a'],
        'blank_5' => [133, 10, 'a'],
        'file_code' => [143, 1, 'n'],
        'generation_date' => [144, 8, 'n'],
        'generation_time' => [152, 6, 'n'],
        'file_sequence' => [158, 6, 'n'],
        'zeros' => [164, 3, 'n'],
        'density' => [167, 5, 'n'],
        'blank_6' => [172, 69, 'a'],
    ];

    /** @var array<string, array{int, int, string}> */
    public const BATCH_HEADER = [
        'bank_code' => [1, 3, 'n'],
        'batch' => [4, 4, 'n'],
        'record_type' => [8, 1, 'n'],
        'operation' => [9, 1, 'a'],
        'payment_type' => [10, 2, 'n'],
        'payment_form' => [12, 2, 'n'],
        'batch_layout' => [14, 3, 'n'],
        'blank_1' => [17, 1, 'a'],
        'company_document_type' => [18, 1, 'n'],
        'company_document' => [19, 14, 'n'],
        'agreement_code' => [33, 20, 'a'],
        'agency' => [53, 5, 'n'],
        'blank_2' => [58, 1, 'a'],
        'account' => [59, 12, 'n'],
        'blank_3' => [71, 1, 'a'],
        'account_digit' => [72, 1, 'a'],
        'company_name' => [73, 30, 'a'],
        'batch_purpose' => [103, 30, 'a'],
        'account_history' => [133, 10, 'a'],
        'address' => [143, 30, 'a'],
        'address_number' => [173, 5, 'n'],
        'address_complement' => [178, 15, 'a'],
        'city' => [193, 20, 'a'],
        'zip_code' => [213, 8, 'n'],
        'state' => [221, 2, 'a'],
        'blank_4' => [223, 8, 'a'],
        'occurrences' => [231, 10, 'a'],
    ];

    /** @var array<string, array{int, int, string}> */
    public const SEGMENT_A = [
        'bank_code' => [1, 3, 'n'],
        'batch' => [4, 4, 'n'],
        'record_type' => [8, 1, 'n'],
        'record_sequence' => [9, 5, 'n'],
        'segment' => [14, 1, 'a'],
        'movement_type' => [15, 3, 'n'],
        'clearing_code' => [18, 3, 'n'],
        'beneficiary_bank' => [21, 3, 'n'],
        'beneficiary_account' => [24, 20, 'a'],
        'beneficiary_name' => [44, 30, 'a'],
        'reference' => [74, 20, 'a'],
        'payment_date' => [94, 8, 'n'],
        'currency' => [102, 3, 'a'],
        'ispb' => [105, 8, 'n'],
        'zeros' => [113, 7, 'n'],
        'amount' => [120, 15, 'n'],
        'our_number' => [135, 15, 'a'],
        'blank_1' => [150, 5, 'a'],
        'effective_date' => [155, 8, 'n'],
        'effective_amount' => [163, 15, 'n'],
        'purpose_detail' => [178, 20, 'a'],
        'blank_2' => [198, 6, 'a'],
        'beneficiary_document' => [204, 14, 'n'],
        'doc_purpose' => [218, 2, 'a'],
        'ted_purpose' => [220, 5, 'a'],
        'blank_3' => [225, 5, 'a'],
        'notice' => [230, 1, 'n'],
        'occurrences' => [231, 10, 'a'],
    ];

    /** @var array<string, array{int, int, string}> */
    public const SEGMENT_B = [
        'bank_code' => [1, 3, 'n'],
        'batch' => [4, 4, 'n'],
        'record_type' => [8, 1, 'n'],
        'record_sequence' => [9, 5, 'n'],
        'segment' => [14, 1, 'a'],
        'pix_key_type' => [15, 2, 'a'],
        'blank_1' => [17, 1, 'a'],
        'beneficiary_document_type' => [18, 1, 'n'],
        'beneficiary_document' => [19, 14, 'n'],
        'tx_id' => [33, 30, 'a'],
        'information' => [63, 65, 'a'],
        'pix_key' => [128, 99, 'a'],
        'blank_2' => [227, 4, 'a'],
        'occurrences' => [231, 10, 'a'],
    ];

    /** @var array<string, array{int, int, string}> */
    public const SEGMENT_J = [
        'bank_code' => [1, 3, 'n'],
        'batch' => [4, 4, 'n'],
        'record_type' => [8, 1, 'n'],
        'record_sequence' => [9, 5, 'n'],
        'segment' => [14, 1, 'a'],
        'movement_type' => [15, 3, 'n'],
        'barcode' => [18, 44, 'n'],
        'beneficiary_name' => [62, 30, 'a'],
        'due_date' => [92, 8, 'n'],
        'face_amount' => [100, 15, 'n'],
        'discount_amount' => [115, 15, 'n'],
        'addition_amount' => [130, 15, 'n'],
        'payment_date' => [145, 8, 'n'],
        'payment_amount' => [153, 15, 'n'],
        'zeros' => [168, 15, 'n'],
        'reference' => [183, 20, 'a'],
        'blank_1' => [203, 13, 'a'],
        'our_number' => [216, 15, 'a'],
        'occurrences' => [231, 10, 'a'],
    ];

    /** @var array<string, array{int, int, string}> */
    public const SEGMENT_J52 = [
        'bank_code' => [1, 3, 'n'],
        'batch' => [4, 4, 'n'],
        'record_type' => [8, 1, 'n'],
        'record_sequence' => [9, 5, 'n'],
        'segment' => [14, 1, 'a'],
        'movement_type' => [15, 3, 'n'],
        'record_id' => [18, 2, 'n'],
        'payer_document_type' => [20, 1, 'n'],
        'payer_document' => [21, 15, 'n'],
        'payer_name' => [36, 40, 'a'],
        'beneficiary_document_type' => [76, 1, 'n'],
        'beneficiary_document' => [77, 15, 'n'],
        'beneficiary_name' => [92, 40, 'a'],
        'drawer_document_type' => [132, 1, 'n'],
        'drawer_document' => [133, 15, 'n'],
        'drawer_name' => [148, 40, 'a'],
        'blank_1' => [188, 53, 'a'],
    ];

    /** @var array<string, array{int, int, string}> */
    public const BATCH_TRAILER = [
        'bank_code' => [1, 3, 'n'],
        'batch' => [4, 4, 'n'],
        'record_type' => [8, 1, 'n'],
        'blank_1' => [9, 9, 'a'],
        'records_count' => [18, 6, 'n'],
        'total_amount' => [24, 18, 'n'],
        'zeros' => [42, 18, 'n'],
        'blank_2' => [60, 171, 'a'],
        'occurrences' => [231, 10, 'a'],
    ];

    /** @var array<string, array{int, int, string}> */
    public const FILE_TRAILER = [
        'bank_code' => [1, 3, 'n'],
        'batch' => [4, 4, 'n'],
        'record_type' => [8, 1, 'n'],
        'blank_1' => [9, 9, 'a'],
        'batches_count' => [18, 6, 'n'],
        'records_count' => [24, 6, 'n'],
        'blank_2' => [30, 211, 'a'],
    ];

    /**
     * Renders one record. Missing numeric fields become zeros, missing alphanumeric fields become blanks.
     *
     * @param  array<string, array{int, int, string}>  $spec
     * @param  array<string, string|int|null>  $values
     */
    public static function render(array $spec, array $values): string
    {
        $unknown = array_diff_key($values, $spec);

        if ($unknown !== []) {
            throw new LogicException('Unknown CNAB fields: '.implode(', ', array_keys($unknown)));
        }

        $line = '';
        $expectedStart = 1;

        foreach ($spec as $name => [$start, $length, $kind]) {
            if ($start !== $expectedStart) {
                throw new LogicException("CNAB field {$name} starts at {$start}, expected {$expectedStart}.");
            }

            $value = $values[$name] ?? null;

            $line .= $kind === 'n'
                ? FixedWidthFormatter::numeric($value, $length)
                : FixedWidthFormatter::alpha($value === null ? null : (string) $value, $length);

            $expectedStart += $length;
        }

        if (strlen($line) !== self::LINE_LENGTH) {
            throw new LogicException('CNAB record length is '.strlen($line).', expected 240.');
        }

        return $line;
    }
}
