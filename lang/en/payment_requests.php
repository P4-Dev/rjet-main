<?php

declare(strict_types=1);

return [
    'label' => 'Payment request',
    'plural' => 'Payment requests',

    'fields' => [
        'company' => 'Company',
        'branch_id' => 'Branch',
        'supplier_id' => 'Supplier',
        'cost_center_id' => 'Cost center',
        'appropriation_id' => 'Appropriation',
        'payment_method' => 'Payment method',
        'gross_amount' => 'Gross amount',
        'discount_amount' => 'Discounts/deductions',
        'net_amount' => 'Net amount',
        'due_date' => 'Due date',
        'has_attachments' => 'Attachments',
        'attachments' => 'Attachments',
        'next_status' => 'Next status',
        'approval_state' => 'Approval',
        'approver' => 'Approver',
        'due_at' => 'SLA due at',
        'escalated_at' => 'Escalated at',
        'has_approved_for_launch' => 'Ready to launch',
    ],

    'sections' => [
        'identification' => 'Identification',
        'amounts' => 'Amounts',
        'payment' => 'Payment and attachments',
        'settlement' => 'Settlement details',
        'approval' => 'Approval',
    ],

    'hints' => [
        'select_branch_first' => 'Select a branch to load the available options.',
        'appropriation_required' => 'This company requires an appropriation on the request.',
        'appropriation_optional' => 'Optional for this company; can be filled later.',
        'net_amount_auto' => 'Calculated automatically: gross amount minus discounts.',
        'digitable_line' => 'Filled automatically when a boleto PDF is attached.',
        'pix_qr_code' => 'Paste the Pix copy-and-paste code (QR payload) here.',
        'holder_document' => 'Beneficiary CPF or CNPJ (digits only).',
        'settlement' => 'Displayed fields vary according to the selected payment method.',
        'attachments' => 'PDF, JPEG, PNG or WEBP. Max 10 MB per file. Required for boleto.',
    ],

    'filters' => [
        'due_from' => 'Due from',
        'due_until' => 'Due until',
        'created_from' => 'Created from',
        'created_until' => 'Created until',
        'approval_status' => 'Approval status',
        'awaiting_my_approval' => 'Pending for me',
    ],

    'tabs' => [
        'all' => 'All',
        'awaiting_my_approval' => 'My pending',
        'all_pending_approvals' => 'Awaiting approval',
        'returned' => 'Returned',
    ],

    'approval_states' => [
        'awaiting' => 'Awaiting approval',
        'returned' => 'Returned',
        'approved_ready' => 'Approved (ready to launch)',
        'no_rule' => 'No approval rule',
        'none' => '—',
    ],

    'actions' => [
        'transition_status' => 'Change status',
        'extract_ocr' => 'Read boleto (OCR)',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'resubmit' => 'Resubmit for approval',
        'send_for_approval' => 'Send for approval',
    ],

    'messages' => [
        'confirm_transition' => 'Confirm the new status for this request.',
        'status_changed' => 'Status updated successfully!',
        'ocr_succeeded' => 'Boleto data extracted successfully. Review before saving.',
        'ocr_failed' => 'Could not read the boleto automatically. Fill in the data manually.',
        'ocr_image_not_supported' => 'Automatic reading is available only for boleto PDFs. Fill in the data manually.',
        'ocr_disabled' => 'Automatic boleto reading is disabled.',
        'ocr_not_found' => 'No valid digitable line was found in the PDF.',
        'no_attachments' => 'No attachments.',
        'confirm_approve' => 'Confirm approval of this request?',
        'approved' => 'Request approved.',
        'rejected' => 'Request rejected and returned to the requester.',
        'resubmitted' => 'Request resubmitted for approval.',
        'sent_for_approval' => 'Request sent for approval.',
        'routed_no_rule' => 'Request created, but no approval rule matches. Configure a range or use Send for approval.',
    ],

    'errors' => [
        'branch_not_allowed' => 'You are not allowed to create requests for this branch.',
        'invalid_status_transition' => 'This status transition is not allowed.',
        'unauthorized_status_transition' => 'You are not allowed to change the status of this request.',
        'cannot_edit_in_status' => 'You cannot edit a request in this status.',
        'appropriation_required' => 'Appropriation is required for this company.',
        'appropriation_not_allowed' => 'The appropriation does not belong to the branch company.',
        'cost_center_not_allowed' => 'The cost center does not belong to the selected branch.',
        'boleto_attachment_required' => 'Attach at least one boleto file.',
        'discount_exceeds_gross' => 'Discount cannot be greater than the gross amount.',
        'invalid_net_amount' => 'The informed amounts are invalid.',
        'incomplete_bank_details' => 'Settlement details are incomplete.',
        'pix_details_incomplete' => 'Provide the Pix key (type + key) or the QR code payload.',
        'transfer_details_incomplete' => 'Fill in all required transfer details.',
        'invalid_holder_document' => 'The beneficiary CPF/CNPJ is invalid.',
        'approval_required' => 'A valid approval (without material changes) is required before launching.',
    ],
];
