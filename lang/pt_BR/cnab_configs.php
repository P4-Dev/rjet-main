<?php

declare(strict_types=1);

return [
    'label' => 'Configuração CNAB',
    'plural' => 'Configurações CNAB',
    'navigation_label' => 'Configurações CNAB',

    'fields' => [
        'branch' => 'Filial',
        'branch_bank_account' => 'Conta bancária',
        'bank_code' => 'Banco',
        'account' => 'Agência / Conta',
        'layout' => 'Layout',
        'company_name' => 'Nome da empresa no arquivo',
        'agreement_code' => 'Convênio',
        'wallet_code' => 'Carteira',
        'payment_type_code' => 'Tipo de pagamento',
        'last_file_sequence' => 'Último NSA',
        'is_active' => 'Ativa',
    ],

    'sections' => [
        'account' => 'Conta',
        'parameters' => 'Parâmetros da remessa',
        'files' => 'Arquivos emitidos',
    ],

    'filters' => [
        'branch' => 'Filial',
        'layout' => 'Layout',
        'is_active' => 'Ativa',
    ],

    'formats' => [
        'account' => ':bank — Ag :agency / CC :account-:digit',
        'agency_account' => 'Ag :agency / CC :account-:digit',
    ],

    'help' => [
        'layout' => 'Deve corresponder ao banco da conta.',
        'company_name_fallback' => 'Até 30 caracteres. Vazio usa a razão social da filial.',
        'payment_type_code' => '20 = pagamento a fornecedores.',
        'last_file_sequence' => 'Número sequencial do último arquivo enviado. Travado após a primeira emissão.',
        'is_active' => 'Desativar pausa a geração. Para substituir a configuração, exclua-a e crie outra.',
    ],

    'messages' => [
        'created' => 'Configuração CNAB criada.',
        'updated' => 'Configuração CNAB atualizada.',
        'deleted' => 'Configuração CNAB excluída.',
        'delete_replaces' => 'Excluir libera a conta para uma nova configuração, que continua a numeração de arquivos.',
    ],

    'errors' => [
        'already_exists' => 'Esta conta já tem uma configuração CNAB.',
        'locked_after_issue' => 'Conta, layout e sequência não podem mudar após a emissão de arquivos.',
        'layout_not_supported' => 'Layout CNAB não suportado.',
        'bank_mismatch' => 'O layout exige o banco :expected, mas a conta é do banco :actual.',
        'sequence_below_issued' => 'O último NSA não pode ser menor que :max, já emitido para esta conta.',
        'has_active_generation' => 'Há remessa em geração com esta configuração.',
    ],
];
