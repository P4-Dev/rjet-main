<?php

declare(strict_types=1);

return [
    'label' => 'Anexo',
    'plural' => 'Anexos',

    'fields' => [
        'file' => 'Arquivo',
        'type' => 'Tipo',
        'original_name' => 'Nome do arquivo',
        'mime_type' => 'Tipo MIME',
        'size' => 'Tamanho',
        'standardized_name' => 'Nome padronizado',
    ],

    'actions' => [
        'download' => 'Baixar',
    ],

    'messages' => [
        'uploaded' => 'Anexo enviado com sucesso.',
    ],

    'errors' => [
        'invalid_mime_type' => 'Tipo de arquivo não permitido.',
        'file_too_large' => 'O arquivo excede o tamanho máximo de :max KB.',
        'file_not_found' => 'Arquivo não encontrado no armazenamento.',
        'batch_classification_incomplete' => 'Classifique todos os itens antes de concluir.',
        'batch_not_classifiable' => 'Este lote não está pendente de classificação.',
        'batch_not_retryable' => 'Este lote não possui falhas de nomenclatura para retentar.',
        'invalid_destination' => 'Destino de classificação inválido ou incompleto.',
        'naming_collision_unresolved' => 'Não foi possível gerar um nome único para o arquivo.',
        'storage_move_failed' => 'Falha ao mover o arquivo no armazenamento.',
        'batch_empty' => 'Envie ao menos um arquivo para criar o lote.',
        'unauthorized_batch_operation' => 'Você não tem permissão para esta operação no lote de anexos.',
    ],
];
