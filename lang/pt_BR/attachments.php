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
    ],
];
