<?php

declare(strict_types=1);

return [
    'label' => 'Lote de anexos',
    'plural' => 'Lotes de anexos',
    'navigation_label' => 'Lote de anexos',

    'fields' => [
        'files' => 'Arquivos',
        'status' => 'Status',
        'items_count' => 'Itens',
        'classified_count' => 'Classificados',
        'renamed_count' => 'Renomeados',
        'failed_count' => 'Falhas',
        'failure_reason' => 'Motivo da falha',
        'classified_at' => 'Classificado em',
        'classified_by' => 'Classificado por',
        'renaming_started_at' => 'Início da nomenclatura',
        'renamed_at' => 'Nomenclatura concluída em',
        'naming_generated_at' => 'Prefixo de data/hora',
        'sort_order' => 'Ordem',
        'destination_type' => 'Destino',
        'payment_request_id' => 'Solicitação de pagamento',
        'supplier_id' => 'Fornecedor',
        'operational_label' => 'Categoria operacional',
        'rename_error' => 'Erro de nomenclatura',
        'display_name' => 'Nome',
        'mime_type' => 'Tipo MIME',
    ],

    'sections' => [
        'upload' => 'Upload de arquivos',
        'status' => 'Status do lote',
        'items' => 'Itens',
        'classifications' => 'Histórico de classificação',
        'classify' => 'Classificar anexos',
        'preview' => 'Pré-visualização',
    ],

    'actions' => [
        'classify' => 'Classificar',
        'conclude_classification' => 'Concluir classificação',
        'retry_failed_renames' => 'Retentar falhas de nomenclatura',
        'download' => 'Baixar',
        'save_classification' => 'Salvar classificação',
        'back_to_view' => 'Voltar ao lote',
    ],

    'messages' => [
        'created' => 'Lote de anexos criado.',
        'classification_saved' => 'Classificação salva.',
        'classification_concluded' => 'Classificação concluída. Nomenclatura enfileirada.',
        'reordered' => 'Ordem atualizada.',
        'retry_queued' => 'Retentativa de nomenclatura enfileirada.',
        'renamed' => 'Nomenclatura do lote concluída.',
        'partially_failed' => 'Nomenclatura concluída com falhas parciais.',
        'rename_failed' => 'Nenhum arquivo do lote pôde ser renomeado.',
        'rename_interrupted' => 'A nomenclatura do lote foi interrompida por um erro inesperado. Tente novamente as renomeações com falha.',
    ],

    'hints' => [
        'upload' => 'PDF, JPEG, PNG ou WebP. Mesmos limites dos anexos da solicitação.',
        'operational_label' => 'Rótulo livre operacional (sem catálogo nesta fase).',
        'seq_order' => 'A ordem define o SEQ da nomenclatura (001, 002, …). Arraste para reordenar.',
        'conclude' => 'Todos os itens precisam estar classificados. Após concluir, a classificação não pode ser reaberta.',
        'preview_unavailable' => 'Pré-visualização indisponível para este armazenamento. Baixe o arquivo para conferir.',
        'select_item' => 'Selecione um item da lista para classificar.',
    ],
];
