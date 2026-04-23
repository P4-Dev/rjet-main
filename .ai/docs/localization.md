# Padrões de Internacionalização (i18n)

> Esta guideline define como usar traduções em todo o projeto.
> **REGRA:** Nunca usar strings hardcoded em labels, mensagens ou textos de UI.
> Sempre usar `__('chave')` com arquivos de tradução em `lang/`.

---

## 1. Regra Fundamental

```php
// ❌ PROIBIDO - string hardcoded
TextInput::make('name')->label('Nome');
Section::make('Informações Gerais');
Notification::make()->title('Pedido criado com sucesso!');

// ✅ OBRIGATÓRIO - chave de tradução
TextInput::make('name')->label(__('customers.fields.name'));
Section::make(__('customers.sections.general'));
Notification::make()->title(__('customers.messages.created'));
```

**Isso se aplica a:**
- Labels de campos (forms, tables, infolists)
- Títulos de sections, tabs, wizards
- Mensagens de notificação (sucesso, erro, confirmação)
- Labels de actions e botões
- Textos de filtros
- Placeholders e descriptions
- Labels de navegação (groups, items)
- Textos de widgets e stats
- Mensagens de validação customizadas
- Modal headings e descriptions

---

## 2. Estrutura de Arquivos

```
lang/
├── en/
│   ├── common.php              # Traduções compartilhadas (campos, ações, mensagens genéricas)
│   ├── navigation.php          # Grupos e labels de navegação
│   ├── orders.php              # Resource: Orders
│   ├── customers.php           # Resource: Customers
│   ├── suppliers.php           # Resource: Suppliers
│   └── products.php            # Resource: Products
├── pt_BR/
│   ├── common.php
│   ├── navigation.php
│   ├── orders.php
│   ├── customers.php
│   ├── suppliers.php
│   └── products.php
```

**Regra:** cada Resource/Model tem seu próprio arquivo de tradução em ambos os idiomas.

---

## 3. Arquivo Comum: `common.php`

Campos e textos reutilizados em múltiplos Resources.

### `lang/pt_BR/common.php`

```php
<?php

return [
    // Campos comuns a vários models
    'fields' => [
        'name' => 'Nome',
        'title' => 'Título',
        'email' => 'Email',
        'phone' => 'Telefone',
        'mobile' => 'Celular',
        'document' => 'CPF/CNPJ',
        'description' => 'Descrição',
        'notes' => 'Observações',
        'slug' => 'Slug',
        'code' => 'Código',
        'reference' => 'Referência',
        'is_active' => 'Ativo',
        'is_default' => 'Padrão',
        'sort_order' => 'Ordem',
        'status' => 'Status',
        'type' => 'Tipo',
        'priority' => 'Prioridade',
        'price' => 'Preço',
        'total' => 'Total',
        'quantity' => 'Quantidade',
        'created_at' => 'Criado em',
        'updated_at' => 'Atualizado em',
        'deleted_at' => 'Excluído em',
        'created_by' => 'Criado por',
        'updated_by' => 'Atualizado por',
    ],

    // Ações genéricas
    'actions' => [
        'create' => 'Criar',
        'edit' => 'Editar',
        'delete' => 'Excluir',
        'view' => 'Visualizar',
        'save' => 'Salvar',
        'cancel' => 'Cancelar',
        'search' => 'Buscar',
        'filter' => 'Filtrar',
        'export' => 'Exportar',
        'import' => 'Importar',
        'confirm' => 'Confirmar',
        'back' => 'Voltar',
        'activate' => 'Ativar',
        'deactivate' => 'Desativar',
    ],

    // Mensagens genéricas (use :resource como placeholder)
    'messages' => [
        'created' => ':resource criado com sucesso!',
        'updated' => ':resource atualizado com sucesso!',
        'deleted' => ':resource removido com sucesso!',
        'restored' => ':resource restaurado com sucesso!',
        'confirm_delete' => 'Tem certeza que deseja excluir este :resource?',
        'confirm_delete_description' => 'Esta ação não pode ser desfeita.',
        'no_results' => 'Nenhum resultado encontrado.',
        'empty' => 'Nenhum :resource cadastrado.',
    ],

    // Sections comuns
    'sections' => [
        'general' => 'Informações Gerais',
        'details' => 'Detalhes',
        'notes' => 'Observações',
        'settings' => 'Configurações',
        'address' => 'Endereço',
        'addresses' => 'Endereços',
        'contact' => 'Contato',
        'contacts' => 'Contatos',
        'attachments' => 'Anexos',
        'history' => 'Histórico',
        'financial' => 'Financeiro',
    ],

    // Endereço (morph)
    'address' => [
        'street' => 'Logradouro',
        'number' => 'Número',
        'complement' => 'Complemento',
        'neighborhood' => 'Bairro',
        'city' => 'Cidade',
        'state' => 'Estado',
        'zip_code' => 'CEP',
        'country' => 'País',
        'type' => 'Tipo de endereço',
        'types' => [
            'main' => 'Principal',
            'billing' => 'Cobrança',
            'shipping' => 'Entrega',
        ],
    ],

    // Contato (morph)
    'contact' => [
        'name' => 'Nome do contato',
        'email' => 'Email do contato',
        'phone' => 'Telefone',
        'mobile' => 'Celular',
        'position' => 'Cargo',
        'department' => 'Departamento',
        'type' => 'Tipo de contato',
        'types' => [
            'main' => 'Principal',
            'billing' => 'Financeiro',
            'technical' => 'Técnico',
            'support' => 'Suporte',
        ],
    ],
];
```

### `lang/en/common.php`

```php
<?php

return [
    'fields' => [
        'name' => 'Name',
        'title' => 'Title',
        'email' => 'Email',
        'phone' => 'Phone',
        'mobile' => 'Mobile',
        'document' => 'Tax ID',
        'description' => 'Description',
        'notes' => 'Notes',
        'slug' => 'Slug',
        'code' => 'Code',
        'reference' => 'Reference',
        'is_active' => 'Active',
        'is_default' => 'Default',
        'sort_order' => 'Sort Order',
        'status' => 'Status',
        'type' => 'Type',
        'priority' => 'Priority',
        'price' => 'Price',
        'total' => 'Total',
        'quantity' => 'Quantity',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
        'deleted_at' => 'Deleted At',
        'created_by' => 'Created By',
        'updated_by' => 'Updated By',
    ],

    'actions' => [
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'view' => 'View',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'search' => 'Search',
        'filter' => 'Filter',
        'export' => 'Export',
        'import' => 'Import',
        'confirm' => 'Confirm',
        'back' => 'Back',
        'activate' => 'Activate',
        'deactivate' => 'Deactivate',
    ],

    'messages' => [
        'created' => ':resource created successfully!',
        'updated' => ':resource updated successfully!',
        'deleted' => ':resource deleted successfully!',
        'restored' => ':resource restored successfully!',
        'confirm_delete' => 'Are you sure you want to delete this :resource?',
        'confirm_delete_description' => 'This action cannot be undone.',
        'no_results' => 'No results found.',
        'empty' => 'No :resource registered.',
    ],

    'sections' => [
        'general' => 'General Information',
        'details' => 'Details',
        'notes' => 'Notes',
        'settings' => 'Settings',
        'address' => 'Address',
        'addresses' => 'Addresses',
        'contact' => 'Contact',
        'contacts' => 'Contacts',
        'attachments' => 'Attachments',
        'history' => 'History',
        'financial' => 'Financial',
    ],

    'address' => [
        'street' => 'Street',
        'number' => 'Number',
        'complement' => 'Complement',
        'neighborhood' => 'Neighborhood',
        'city' => 'City',
        'state' => 'State',
        'zip_code' => 'Zip Code',
        'country' => 'Country',
        'type' => 'Address Type',
        'types' => [
            'main' => 'Main',
            'billing' => 'Billing',
            'shipping' => 'Shipping',
        ],
    ],

    'contact' => [
        'name' => 'Contact Name',
        'email' => 'Contact Email',
        'phone' => 'Phone',
        'mobile' => 'Mobile',
        'position' => 'Position',
        'department' => 'Department',
        'type' => 'Contact Type',
        'types' => [
            'main' => 'Main',
            'billing' => 'Billing',
            'technical' => 'Technical',
            'support' => 'Support',
        ],
    ],
];
```

---

## 4. Arquivo de Navegação: `navigation.php`

### `lang/pt_BR/navigation.php`

```php
<?php

return [
    'groups' => [
        'main' => 'Principal',
        'records' => 'Cadastros',
        'operations' => 'Operações',
        'reports' => 'Relatórios',
        'settings' => 'Configurações',
    ],
];
```

### `lang/en/navigation.php`

```php
<?php

return [
    'groups' => [
        'main' => 'Main',
        'records' => 'Records',
        'operations' => 'Operations',
        'reports' => 'Reports',
        'settings' => 'Settings',
    ],
];
```

---

## 5. Arquivo por Resource: Template

Para cada Resource/Model, criar um arquivo em ambos idiomas.

### `lang/pt_BR/{resources}.php` (template)

```php
<?php

return [
    // Labels do Resource
    'label' => 'Pedido',
    'plural' => 'Pedidos',

    // Campos específicos deste Resource (não presentes em common.php)
    'fields' => [
        'customer_id' => 'Cliente',
        'order_number' => 'Número do Pedido',
        'subtotal' => 'Subtotal',
        'discount' => 'Desconto',
        'shipping_cost' => 'Frete',
        'payment_method' => 'Forma de Pagamento',
        'due_date' => 'Data de Vencimento',
        'delivered_at' => 'Entregue em',
    ],

    // Sections específicas
    'sections' => [
        'order_info' => 'Informações do Pedido',
        'items' => 'Itens do Pedido',
        'payment' => 'Pagamento',
        'shipping' => 'Envio',
    ],

    // Filtros específicos
    'filters' => [
        'customer' => 'Cliente',
        'date_range' => 'Período',
        'payment_method' => 'Forma de Pagamento',
    ],

    // Actions específicas
    'actions' => [
        'approve' => 'Aprovar',
        'ship' => 'Enviar',
        'deliver' => 'Marcar como Entregue',
        'cancel' => 'Cancelar',
        'cancel_reason' => 'Motivo do cancelamento',
    ],

    // Mensagens específicas
    'messages' => [
        'approved' => 'Pedido aprovado com sucesso!',
        'shipped' => 'Pedido enviado!',
        'delivered' => 'Pedido marcado como entregue!',
        'cancelled' => 'Pedido cancelado.',
        'confirm_cancel' => 'Tem certeza que deseja cancelar este pedido?',
    ],

    // Widgets/Stats
    'stats' => [
        'total_orders' => 'Total de Pedidos',
        'pending' => 'Pendentes',
        'revenue' => 'Faturamento',
    ],
];
```

### `lang/en/{resources}.php` (template)

```php
<?php

return [
    'label' => 'Order',
    'plural' => 'Orders',

    'fields' => [
        'customer_id' => 'Customer',
        'order_number' => 'Order Number',
        'subtotal' => 'Subtotal',
        'discount' => 'Discount',
        'shipping_cost' => 'Shipping Cost',
        'payment_method' => 'Payment Method',
        'due_date' => 'Due Date',
        'delivered_at' => 'Delivered At',
    ],

    'sections' => [
        'order_info' => 'Order Information',
        'items' => 'Order Items',
        'payment' => 'Payment',
        'shipping' => 'Shipping',
    ],

    'filters' => [
        'customer' => 'Customer',
        'date_range' => 'Date Range',
        'payment_method' => 'Payment Method',
    ],

    'actions' => [
        'approve' => 'Approve',
        'ship' => 'Ship',
        'deliver' => 'Mark as Delivered',
        'cancel' => 'Cancel',
        'cancel_reason' => 'Cancellation reason',
    ],

    'messages' => [
        'approved' => 'Order approved successfully!',
        'shipped' => 'Order shipped!',
        'delivered' => 'Order marked as delivered!',
        'cancelled' => 'Order cancelled.',
        'confirm_cancel' => 'Are you sure you want to cancel this order?',
    ],

    'stats' => [
        'total_orders' => 'Total Orders',
        'pending' => 'Pending',
        'revenue' => 'Revenue',
    ],
];
```

---

## 6. Como Usar nos Componentes

### Filament Resource

```php
// Resource principal
public static function getModelLabel(): string
{
    return __('orders.label');
}

public static function getPluralModelLabel(): string
{
    return __('orders.plural');
}

protected static ?string $navigationGroup = null;

public static function getNavigationGroup(): ?string
{
    return __('navigation.groups.operations');
}
```

### Form Schema

```php
Section::make(__('orders.sections.order_info'))
    ->schema([
        Select::make('customer_id')
            ->label(__('orders.fields.customer_id'))
            ->relationship('customer', 'name')
            ->searchable()
            ->preload()
            ->required(),

        Select::make('status')
            ->label(__('common.fields.status'))    // campo comum
            ->options(OrderStatus::class)
            ->required(),

        TextInput::make('total')
            ->label(__('common.fields.total'))      // campo comum
            ->numeric()
            ->prefix('R$')
            ->disabled(),
    ]),

Section::make(__('common.sections.notes'))
    ->schema([
        Textarea::make('notes')
            ->label(__('common.fields.notes'))
            ->rows(3),
    ]),
```

### Table

```php
TextColumn::make('customer.name')
    ->label(__('orders.fields.customer_id'))
    ->searchable()
    ->sortable(),

TextColumn::make('status')
    ->label(__('common.fields.status'))
    ->badge()
    ->sortable(),

TextColumn::make('total')
    ->label(__('common.fields.total'))
    ->money('BRL')
    ->sortable(),

TextColumn::make('created_at')
    ->label(__('common.fields.created_at'))
    ->dateTime('d/m/Y H:i')
    ->sortable(),
```

### Filters

```php
SelectFilter::make('status')
    ->label(__('common.fields.status'))
    ->options(OrderStatus::class),

SelectFilter::make('customer')
    ->label(__('orders.filters.customer'))
    ->relationship('customer', 'name'),
```

### Actions

```php
Action::make('approve')
    ->label(__('orders.actions.approve'))
    ->icon('heroicon-o-check-circle')
    ->color('success')
    ->requiresConfirmation()
    ->modalHeading(__('orders.actions.approve') . ' ' . __('orders.label'))
    ->action(function (Order $record): void {
        $record->update(['status' => OrderStatus::Approved]);

        Notification::make()
            ->title(__('orders.messages.approved'))
            ->success()
            ->send();
    });
```

### Notifications

```php
// Usando mensagem específica
Notification::make()
    ->title(__('orders.messages.approved'))
    ->success()
    ->send();

// Usando mensagem genérica com placeholder
Notification::make()
    ->title(__('common.messages.created', ['resource' => __('orders.label')]))
    ->success()
    ->send();
```

### Widgets/Stats

```php
Stat::make(__('orders.stats.total_orders'), Order::count())
    ->icon('heroicon-o-shopping-cart'),

Stat::make(__('orders.stats.pending'), Order::pending()->count())
    ->color('warning'),

Stat::make(__('orders.stats.revenue'), 'R$ ' . number_format(Order::sum('total'), 2, ',', '.'))
    ->color('success'),
```

---

## 7. Regras de Prioridade

Quando um campo existe em `common.php` e no arquivo do resource, **priorize:**

1. **Campo específico do resource** → use `__('orders.fields.customer_id')`
2. **Campo genérico comum** → use `__('common.fields.status')`
3. **Seção específica** → use `__('orders.sections.order_info')`
4. **Seção genérica** → use `__('common.sections.general')`

**Regra prática:** se o campo/texto existe em mais de 3 resources, coloque em `common.php`.

---

## 8. Checklist de Nova Feature/Resource

Ao criar qualquer novo Resource, **obrigatoriamente:**

- [ ] Criar `lang/pt_BR/{resource}.php` com todas as chaves
- [ ] Criar `lang/en/{resource}.php` com todas as chaves
- [ ] Usar `__()` em todos os labels do Form Schema
- [ ] Usar `__()` em todos os labels das Table Columns
- [ ] Usar `__()` em todos os labels dos Filters
- [ ] Usar `__()` em todos os labels de Actions
- [ ] Usar `__()` em todas as Notifications
- [ ] Usar `__()` nos títulos de Sections/Tabs
- [ ] Usar `__()` no getModelLabel/getPluralModelLabel
- [ ] Usar `__()` no navigationGroup
- [ ] Usar `__()` nos Stats dos Widgets
- [ ] Verificar se campos comuns já existem em `common.php` antes de duplicar
- [ ] Adicionar campos novos comuns em `common.php` se usados em 3+ resources

---

## 9. Configuração do Laravel

### `config/app.php`

```php
'locale' => 'pt_BR',
'fallback_locale' => 'en',
```

### Publicar traduções do Filament

```bash
php artisan vendor:publish --tag=filament-translations
```
