<?php

declare(strict_types=1);

return [
    'label' => 'Arquivo CNAB',
    'plural' => 'Arquivos CNAB',

    'fields' => [
        'file_sequence' => 'NSA',
        'status' => 'Status',
        'filename' => 'Arquivo',
        'items_count' => 'Pagamentos',
        'records_count' => 'Linhas',
        'total_amount' => 'Valor total',
        'failure_reason' => 'Motivo da falha',
        'requested_by' => 'Solicitado por',
        'generated_at' => 'Gerado em',
        'downloaded_at' => 'Primeiro download',
        'supersede_reason' => 'Motivo da substituição',
    ],

    'actions' => [
        'download' => 'Baixar arquivo',
        'retry' => 'Tentar novamente',
        'regenerate' => 'Regenerar',
        'view_errors' => 'Ver erros',
    ],

    'messages' => [
        'queued' => 'Geração da remessa enfileirada.',
        'retry_queued' => 'Nova tentativa enfileirada.',
        'regenerate_queued' => 'Arquivo substituído. Nova geração enfileirada.',
        'regenerate_warning' => 'O arquivo atual será invalidado. Se ele já foi enviado ao banco, há risco de pagamento duplicado.',
        'remittance_valid' => 'Remessa válida.',
        'remittance_errors' => 'A remessa tem :count erro(s):',
        'generation_interrupted' => 'A geração foi interrompida. Tente novamente.',
        'cancellation' => 'Baixa cancelada',
    ],

    'sections' => [
        'config_errors' => 'Configuração',
        'item_errors' => 'Pagamentos',
        'structural_errors' => 'Estrutura do arquivo',
    ],

    'errors' => [
        'config_not_found' => 'A conta pagadora não tem configuração CNAB.',
        'config_inactive' => 'A configuração CNAB desta conta está desativada.',
        'payment_date_in_past' => 'A data de baixa já passou. Cancele e recrie a baixa ou confirme sem CNAB.',
        'settlement_not_draft' => 'Só é possível gerar CNAB para baixas em rascunho.',
        'generation_already_active' => 'Já existe uma remessa ativa para esta baixa.',
        'remittance_invalid' => 'Remessa reprovada na validação: :count erro(s).',
        'not_retryable' => 'Este arquivo não pode ser gerado novamente.',
        'not_downloadable' => 'Este arquivo não está disponível para download.',
        'file_missing' => 'O arquivo não foi encontrado. Peça a um administrador para regenerar.',
        'integrity_failed' => 'O arquivo não passou na verificação de integridade. Peça a um administrador para regenerar.',
        'storage_failed' => 'Falha ao gravar o arquivo.',
        'file_sequence_exhausted' => 'A numeração de arquivos (NSA) desta conta chegou ao limite.',
    ],

    'validation' => [
        'payment_request_unavailable' => 'A solicitação não está mais lançada ou foi excluída.',
        'amount_changed' => 'O valor da solicitação mudou desde a seleção.',
        'amount_not_positive' => 'O valor deve ser maior que zero.',
        'payment_date_in_past' => 'A data de pagamento já passou.',
        'missing_barcode' => 'Boleto sem código de barras ou linha digitável.',
        'invalid_barcode' => 'Código de barras ou linha digitável inválido.',
        'utility_bill_not_supported' => 'Contas de consumo e tributos não são suportados no CNAB.',
        'pix_qr_code_not_supported' => 'PIX por QR Code não é suportado no CNAB. Informe a chave PIX.',
        'missing_pix_key' => 'Chave PIX ausente.',
        'invalid_pix_key' => 'Chave PIX inválida para o tipo informado.',
        'missing_transfer_data' => 'Dados bancários do favorecido incompletos.',
        'beneficiary_agency_too_long' => 'Agência do favorecido com mais de :max dígitos para este banco.',
        'beneficiary_account_too_long' => 'Conta do favorecido com mais de :max dígitos para este banco.',
        'beneficiary_account_digit_too_long' => 'Dígito da conta do favorecido deve ter no máximo :max caractere.',
        'invalid_holder_document' => 'CPF/CNPJ do favorecido inválido.',
        'missing_beneficiary_name' => 'Nome do favorecido ausente.',
        'beneficiary_bank_missing' => 'Banco do favorecido ausente.',
        'branch_document_invalid' => 'CNPJ da filial inválido.',
        'account_data_incomplete' => 'Dados da conta pagadora incompletos.',
        'line_length_invalid' => 'Linha :line com tamanho diferente de 240.',
        'non_ascii_character' => 'Caractere inválido na linha :line.',
        'record_order_invalid' => 'Ordem de registros inválida na linha :line.',
        'sequence_gap' => 'Sequência de registros com lacuna na linha :line.',
        'batch_trailer_mismatch' => 'Trailer do lote :batch não confere.',
        'file_trailer_mismatch' => 'Trailer do arquivo não confere.',
        'total_amount_mismatch' => 'Somatório do arquivo diferente do total dos pagamentos.',
        'bank_code_mismatch' => 'Código do banco diferente do esperado.',
        'file_sequence_mismatch' => 'NSA do header diferente do número atribuído ao arquivo.',
        'no_valid_items' => 'A baixa não tem pagamentos ativos para a remessa.',
        'unknown' => 'Erro de validação.',
    ],
];
