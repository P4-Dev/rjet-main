<?php

declare(strict_types=1);

return [
    'fields' => [
        'name' => 'Nome',
        'legal_name' => 'Razão social',
        'document' => 'CNPJ',
        'email' => 'E-mail',
        'password' => 'Senha',
        'is_active' => 'Ativo',
        'is_default' => 'Padrão',
        'status' => 'Status',
        'type' => 'Tipo',
        'notes' => 'Observações',
        'file' => 'Arquivo',
        'created_at' => 'Criado em',
        'updated_at' => 'Atualizado em',
        'deleted_at' => 'Excluído em',
        'created_by' => 'Criado por',
        'updated_by' => 'Atualizado por',
    ],

    'actions' => [
        'create' => 'Criar',
        'edit' => 'Editar',
        'delete' => 'Excluir',
        'view' => 'Visualizar',
        'save' => 'Salvar',
        'cancel' => 'Cancelar',
        'confirm' => 'Confirmar',
        'activate' => 'Ativar',
        'deactivate' => 'Desativar',
    ],

    'sections' => [
        'general' => 'Informações Gerais',
        'audit' => 'Auditoria',
        'attachments' => 'Anexos',
        'history' => 'Histórico',
    ],

    'messages' => [
        'deleted' => 'Registro excluído com sucesso.',
    ],
];
