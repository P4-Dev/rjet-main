<?php

declare(strict_types=1);

return [
    'label' => 'Lote de importação',
    'plural' => 'Lotes de importação',
    'navigation_label' => 'Importação em lote',

    'fields' => [
        'import_template_version_id' => 'Template',
        'template' => 'Template',
        'spreadsheet' => 'Planilha',
        'status' => 'Status',
        'original_filename' => 'Arquivo',
        'total_rows' => 'Linhas',
        'success_count' => 'Sucessos',
        'error_count' => 'Erros',
        'failure_reason' => 'Motivo da falha',
        'started_at' => 'Início',
        'finished_at' => 'Fim',
        'row_number' => 'Linha',
        'target_field' => 'Campo',
        'message' => 'Mensagem',
    ],

    'sections' => [
        'upload' => 'Upload',
        'status' => 'Processamento',
        'errors' => 'Erros por linha',
        'payment_requests' => 'Solicitações geradas',
    ],

    'actions' => [
        'download' => 'Baixar planilha',
        'refresh' => 'Atualizar status',
    ],

    'messages' => [
        'started' => 'Importação enfileirada.',
        'completed' => 'Importação concluída: :success sucesso(s), :errors erro(s).',
        'failed' => 'Importação falhou: :reason',
    ],

    'errors' => [
        'incompatible_format' => 'O arquivo não corresponde ao formato do template.',
        'unreadable' => 'Não foi possível ler a planilha.',
        'row_limit_exceeded' => 'A planilha excede o limite de :max linhas.',
        'empty' => 'A planilha não contém linhas de dados.',
        'not_pending' => 'Este lote não está pendente de processamento.',
        'unauthorized' => 'Você não tem permissão para importar.',
        'supplier_not_found' => 'Fornecedor não encontrado para o documento :document.',
        'invalid_document' => 'CPF/CNPJ inválido: :document.',
        'invalid_amount' => 'Valor monetário inválido: :value.',
        'missing_creator' => 'O lote de importação não possui criador.',
        'processing_failed' => 'Falha ao processar o lote de importação.',
        'cost_center_not_found' => 'Centro de custo não encontrado: :code.',
        'appropriation_required' => 'Apropriação obrigatória para esta empresa.',
        'appropriation_not_found' => 'Apropriação não encontrada: :code.',
        'boleto_not_supported' => 'Boleto não suportado em lote — use cadastro individual ou lote de anexos (Fase 6).',
        'duplicate_row' => 'Possível duplicata no lote (linha :row).',
    ],
];
