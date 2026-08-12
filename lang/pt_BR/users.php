<?php

declare(strict_types=1);

return [
    'label' => 'Usuário',
    'plural' => 'Usuários',

    'fields' => [
        'name' => 'Nome',
        'email' => 'E-mail',
        'password' => 'Senha',
        'role' => 'Perfil',
        'can_approve' => 'Pode aprovar',
        'branches' => 'Filiais',
        'default_branch' => 'Filial padrão',
    ],

    'sections' => [
        'user_info' => 'Dados do usuário',
        'branches' => 'Filiais',
    ],

    'errors' => [
        'cannot_delete_self' => 'Um administrador não pode excluir ou desativar a própria conta.',
        'cannot_delete_last_admin' => 'Não é possível excluir ou desativar o último administrador ativo do sistema.',
        'cannot_delete_with_pending_approvals' => 'Não é possível excluir o usuário enquanto houver aprovações pendentes ou regras de alçada vinculadas.',
    ],
];
