<?php

declare(strict_types=1);

return [
    'label' => 'CNAB file',
    'plural' => 'CNAB files',

    'fields' => [
        'file_sequence' => 'File sequence',
        'status' => 'Status',
        'filename' => 'File',
        'items_count' => 'Payments',
        'records_count' => 'Lines',
        'total_amount' => 'Total amount',
        'failure_reason' => 'Failure reason',
        'requested_by' => 'Requested by',
        'generated_at' => 'Generated at',
        'downloaded_at' => 'First download',
        'supersede_reason' => 'Replacement reason',
    ],

    'actions' => [
        'download' => 'Download file',
        'retry' => 'Try again',
        'regenerate' => 'Regenerate',
        'view_errors' => 'View errors',
    ],

    'messages' => [
        'queued' => 'Remittance generation queued.',
        'retry_queued' => 'New attempt queued.',
        'regenerate_queued' => 'File replaced. New generation queued.',
        'regenerate_warning' => 'The current file will be invalidated. If it was already sent to the bank, there is a risk of duplicate payment.',
        'remittance_valid' => 'Valid remittance.',
        'remittance_errors' => 'The remittance has :count error(s):',
        'generation_interrupted' => 'Generation was interrupted. Try again.',
        'cancellation' => 'Settlement cancelled',
    ],

    'sections' => [
        'config_errors' => 'Configuration',
        'item_errors' => 'Payments',
        'structural_errors' => 'File structure',
    ],

    'errors' => [
        'config_not_found' => 'The paying account has no CNAB configuration.',
        'config_inactive' => 'The CNAB configuration for this account is inactive.',
        'payment_date_in_past' => 'The settlement date has passed. Cancel and recreate the settlement or confirm without CNAB.',
        'settlement_not_draft' => 'CNAB can only be generated for draft settlements.',
        'generation_already_active' => 'There is already an active remittance for this settlement.',
        'remittance_invalid' => 'Remittance failed validation: :count error(s).',
        'not_retryable' => 'This file cannot be generated again.',
        'not_downloadable' => 'This file is not available for download.',
        'file_missing' => 'The file was not found. Ask an administrator to regenerate it.',
        'integrity_failed' => 'The file failed the integrity check. Ask an administrator to regenerate it.',
        'storage_failed' => 'Failed to write the file.',
        'file_sequence_exhausted' => 'The file sequence for this account reached its limit.',
    ],

    'validation' => [
        'payment_request_unavailable' => 'The request is no longer launched or was deleted.',
        'amount_changed' => 'The request amount changed since selection.',
        'amount_not_positive' => 'The amount must be greater than zero.',
        'payment_date_in_past' => 'The payment date has passed.',
        'missing_barcode' => 'Boleto without barcode or digitable line.',
        'invalid_barcode' => 'Invalid barcode or digitable line.',
        'utility_bill_not_supported' => 'Utility bills and taxes are not supported in CNAB.',
        'pix_qr_code_not_supported' => 'PIX by QR Code is not supported in CNAB. Provide the PIX key.',
        'missing_pix_key' => 'Missing PIX key.',
        'invalid_pix_key' => 'PIX key is invalid for the given type.',
        'missing_transfer_data' => 'Beneficiary bank details are incomplete.',
        'beneficiary_agency_too_long' => 'Beneficiary agency has more than :max digits for this bank.',
        'beneficiary_account_too_long' => 'Beneficiary account has more than :max digits for this bank.',
        'beneficiary_account_digit_too_long' => 'Beneficiary account digit must have at most :max character.',
        'invalid_holder_document' => 'Invalid beneficiary CPF/CNPJ.',
        'missing_beneficiary_name' => 'Missing beneficiary name.',
        'beneficiary_bank_missing' => 'Missing beneficiary bank.',
        'branch_document_invalid' => 'Invalid branch CNPJ.',
        'account_data_incomplete' => 'Paying account details are incomplete.',
        'line_length_invalid' => 'Line :line length is not 240.',
        'non_ascii_character' => 'Invalid character on line :line.',
        'record_order_invalid' => 'Invalid record order on line :line.',
        'sequence_gap' => 'Record sequence gap on line :line.',
        'batch_trailer_mismatch' => 'Batch :batch trailer does not match.',
        'file_trailer_mismatch' => 'File trailer does not match.',
        'total_amount_mismatch' => 'File total differs from the payments total.',
        'bank_code_mismatch' => 'Bank code differs from the expected one.',
        'file_sequence_mismatch' => 'Header file sequence differs from the number assigned to the file.',
        'no_valid_items' => 'The settlement has no active payments for the remittance.',
        'unknown' => 'Validation error.',
    ],
];
