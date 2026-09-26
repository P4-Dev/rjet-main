<?php

declare(strict_types=1);

return [
    'label' => 'Template de importação',
    'plural' => 'Templates de importação',
    'navigation_label' => 'Templates de importação',

    'fields' => [
        'name' => 'Nome',
        'company_id' => 'Empresa alvo',
        'branch_id' => 'Filial alvo',
        'accepted_format' => 'Formato aceito',
        'current_version' => 'Versão atual',
        'mappings' => 'Mapeamentos',
        'source_column' => 'Coluna da planilha',
        'target_field' => 'Campo destino',
        'default_value' => 'Valor padrão',
        'version' => 'Versão',
        'is_current' => 'Atual',
        'published_at' => 'Publicado em',
    ],

    'sections' => [
        'general' => 'Informações gerais',
        'mappings' => 'Mapeamento de colunas',
        'versions' => 'Versões',
    ],

    'hints' => [
        'branch_default' => 'Filial padrão usada quando a planilha não traz coluna de filial.',
    ],

    'actions' => [
        'add_mapping' => 'Adicionar mapeamento',
        'publish_version' => 'Publicar nova versão',
    ],

    'messages' => [
        'created' => 'Template de importação criado.',
        'updated' => 'Template atualizado.',
        'deleted' => 'Template excluído.',
        'version_published' => 'Nova versão publicada.',
    ],

    'errors' => [
        'inactive' => 'Template inativo ou excluído.',
        'missing_required_mappings' => 'Mapeie ou defina default para todos os campos obrigatórios.',
        'version_immutable' => 'Não é possível editar mapeamentos de uma versão publicada. Publique uma nova versão.',
        'version_in_use' => 'Esta versão possui lotes vinculados e não pode ser excluída.',
    ],
];
