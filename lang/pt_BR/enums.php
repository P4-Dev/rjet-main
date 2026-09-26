<?php

declare(strict_types=1);

return [
    'user_role' => [
        'cliente' => 'Cliente',
        'operador' => 'Operador',
        'adm' => 'Administrador',
    ],

    'account_type' => [
        'checking' => 'Conta corrente',
        'savings' => 'Conta poupança',
    ],

    'person_type' => [
        'pf' => 'Pessoa física',
        'pj' => 'Pessoa jurídica',
    ],

    'payment_method' => [
        'boleto' => 'Boleto',
        'deposit' => 'Depósito',
    ],

    'address_type' => [
        'main' => 'Principal',
        'billing' => 'Cobrança',
        'shipping' => 'Entrega',
    ],

    'contact_type' => [
        'main' => 'Principal',
        'billing' => 'Financeiro',
        'technical' => 'Técnico',
    ],

    'payment_request_status' => [
        'requested' => 'Solicitada',
        'launched' => 'Lançada',
        'settled' => 'Liquidada',
    ],

    'deposit_type' => [
        'pix' => 'Pix',
        'transfer' => 'Transferência (TED/DOC/conta)',
    ],

    'pix_key_type' => [
        'random' => 'Chave aleatória',
        'cpf' => 'CPF',
        'phone' => 'Telefone',
        'email' => 'E-mail',
    ],

    'attachment_type' => [
        'boleto' => 'Boleto',
        'other' => 'Outro',
    ],

    'approval_status' => [
        'pending' => 'Pendente',
        'approved' => 'Aprovada',
        'rejected' => 'Rejeitada',
    ],

    'import_file_format' => [
        'csv' => 'CSV',
        'xlsx' => 'Excel (XLSX)',
    ],

    'import_batch_status' => [
        'pending' => 'Pendente',
        'processing' => 'Processando',
        'completed' => 'Concluído',
        'failed' => 'Falhou',
    ],

    'import_target_field' => [
        'branch_document' => 'CNPJ da filial',
        'supplier_document' => 'CPF/CNPJ do fornecedor',
        'cost_center_code' => 'Código do centro de custo',
        'appropriation_code' => 'Código da apropriação',
        'payment_method' => 'Forma de pagamento',
        'gross_amount' => 'Valor bruto',
        'discount_amount' => 'Desconto',
        'due_date' => 'Vencimento',
        'notes' => 'Observações',
        'deposit_type' => 'Tipo de depósito',
        'digitable_line' => 'Linha digitável',
        'pix_key_type' => 'Tipo de chave Pix',
        'pix_key' => 'Chave Pix',
        'pix_qr_code' => 'QR Code Pix',
        'holder_name' => 'Nome do titular',
        'holder_document' => 'Documento do titular',
        'bank_code' => 'Código do banco',
        'agency' => 'Agência',
        'agency_digit' => 'Dígito da agência',
        'account_number' => 'Conta',
        'account_digit' => 'Dígito da conta',
        'account_type' => 'Tipo de conta',
    ],
];
