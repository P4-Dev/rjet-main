# Skill: Filament v5 Resources

---

## 🚨 REGRA CRÍTICA: ESTRUTURA OBRIGATÓRIA DE RESOURCE v5

**NUNCA crie Filament Resources com form/table INLINE no arquivo principal.**

O Resource principal (`{Model}Resource.php`) DEVE ser **LIMPO** — apenas delega para classes separadas.

**A pasta do Resource usa o PLURAL do modelo** (ex: `Users/`, `Orders/`, `ExpenseCategories/`):

```
{Models}/                                ← PLURAL (Users/, Orders/, etc.)
├── {Model}Resource.php                  ← LIMPO: só delegates (DENTRO da pasta plural)
├── Schemas/{Model}Form.php              ← form() delega aqui
├── Schemas/{Model}Infolist.php          ← infolist() delega aqui (SEMPRE gerar)
├── Tables/{Models}Table.php             ← table() delega aqui (PLURAL: UsersTable, OrdersTable)
├── Pages/                               ← Create, Edit, List, View (SEMPRE incluir View)
├── RelationManagers/                    ← Relation Managers (quando hasMany/belongsToMany)
├── Actions/                             ← Actions customizadas (se houver)
└── Widgets/                             ← Widgets do Resource (se houver)
```

**Exemplo concreto (model `User`):**
```
Users/
├── UserResource.php
├── Schemas/UserForm.php
├── Schemas/UserInfolist.php
├── Tables/UsersTable.php
├── Pages/CreateUser.php, EditUser.php, ListUsers.php, ViewUser.php
└── RelationManagers/ (se houver)
```

**Comando Artisan (SEMPRE usar, NUNCA criar na mão):**
```bash
php artisan make:filament-resource {Model} --generate --soft-deletes --view --panel={panel} --no-interaction
```

**Ícone de Navegação:** Usar `Heroicon` enum (NÃO string):
```php
use Filament\Support\Icons\Heroicon;
protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
// NÃO: protected static ?string $navigationIcon = 'heroicon-o-users';
```

**VALIDAÇÃO:** Se o Resource principal contiver `TextInput`, `TextColumn`, `Section`, `Select`,
`Toggle`, `Textarea` ou qualquer componente de form/table/layout diretamente, **está ERRADO e será descartado**.

**Table API v5:**
- `->recordActions()` para ações por linha (NÃO `->actions()`)
- `->headerActions()` para ações no header
- `->toolbarActions()` com `BulkActionGroup` (NÃO `->bulkActions()` direto)

**Soft Deletes:** Use `getRecordRouteBindingEloquentQuery()` (NÃO `getEloquentQuery()`) para incluir registros soft-deleted.

**Todas as classes DEVEM ser `final`.**

**Referência oficial Blueprint:** `vendor/filament/blueprint/resources/markdown/planning/`

---

## ⚠️ IMPORTANTE: Filament v5

O Filament v5 usa namespaces unificados de Actions. **Todas as actions** usam:

```php
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
```

**NÃO use mais** os namespaces antigos:
- ~~`Filament\Tables\Actions\Action`~~ → `Filament\Actions\Action`
- ~~`Filament\Forms\Components\Actions`~~ → `Filament\Schemas\Components\Actions`

---

## Quando Usar

Use este skill quando precisar criar ou modificar:

- Filament Resources
- Forms e Infolists (Schemas)
- Tables
- Actions customizadas
- Widgets
- Pages customizadas

---

## Estrutura de Arquivos (v5)

```
app/Filament/{Panel}/Resources/
└── Orders/                              # PLURAL do modelo
    ├── OrderResource.php                # Arquivo principal (DENTRO, limpo, final)
    ├── Actions/                         # Actions customizadas
    │   ├── ApproveOrderAction.php
    │   ├── CancelOrderAction.php
    │   └── ShipOrderAction.php
    ├── Pages/                           # Pages do Resource
    │   ├── CreateOrder.php
    │   ├── EditOrder.php
    │   ├── ListOrders.php
    │   └── ViewOrder.php
    ├── RelationManagers/                # Relation Managers
    │   └── ItemsRelationManager.php
    ├── Schemas/                         # Forms e Infolists
    │   ├── OrderForm.php                # {Model}Form (singular)
    │   └── OrderInfolist.php            # {Model}Infolist (singular)
    ├── Tables/                          # Table configuration
    │   └── OrdersTable.php              # {Models}Table (PLURAL)
    └── Widgets/                         # Widgets do Resource
        └── OrderStatsWidget.php
```

**Comando Artisan (SEMPRE usar):**
```bash
php artisan make:filament-resource {Model} --generate --soft-deletes --view --panel={panel} --no-interaction
```
- `--generate` → gera form e table baseado no banco
- `--soft-deletes` → adiciona TrashedFilter e getRecordRouteBindingEloquentQuery()
- `--view` → gera View page + Infolist (SEMPRE incluir)
- `--panel` → define o panel destino

**Relation Manager (quando o Model tem hasMany/belongsToMany):**
```bash
php artisan make:filament-relation-manager {Model}Resource {relationship} {titleAttribute} --generate --soft-deletes --panel={panel} --no-interaction
```

---

## IMPORTANTE: Internacionalização

**Todos os labels, mensagens e textos de UI devem usar `__()` com arquivos de tradução.**
Consulte `.ai/docs/localization.md` para regras completas e templates.

```php
// ❌ PROIBIDO
TextInput::make('name')->label('Nome');
Section::make('Informações Gerais');

// ✅ OBRIGATÓRIO
TextInput::make('name')->label(__('customers.fields.name'));
Section::make(__('common.sections.general'));
```

---

## Resource Principal (v5)

O Resource principal delega para classes específicas:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('orders.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('orders.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getRelations(): array
    {
        return [
            // RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
```

---

## Schemas (Forms e Infolists)

### Form Schema

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('orders.sections.order_info'))
                    ->schema([
                        Select::make('customer_id')
                            ->label(__('orders.fields.customer_id'))
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('status')
                            ->label(__('common.fields.status'))
                            ->options(OrderStatus::class)
                            ->default(OrderStatus::Pending)
                            ->required(),

                        TextInput::make('total')
                            ->label(__('common.fields.total'))
                            ->numeric()
                            ->prefix('R$')
                            ->disabled(),
                    ])
                    ->columns(3),

                Section::make(__('common.sections.notes'))
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('common.fields.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }
}
```

### Infolist Schema

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('orders.sections.order_info'))
                    ->schema([
                        TextEntry::make('number')
                            ->label(__('orders.fields.order_number')),

                        TextEntry::make('customer.name')
                            ->label(__('orders.fields.customer_id')),

                        TextEntry::make('status')
                            ->label(__('common.fields.status'))
                            ->badge(),

                        TextEntry::make('total')
                            ->label(__('common.fields.total'))
                            ->money('BRL'),

                        TextEntry::make('created_at')
                            ->label(__('common.fields.created_at'))
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }
}
```

---

## Tables (v5)

### Métodos de Table no v5

| Método v4 | Descrição |
|-----------|-----------|
| `->recordActions([])` | Actions por linha (row actions) |
| `->headerActions([])` | Actions no header da tabela |
| `->toolbarActions([])` | Actions na toolbar |
| `->bulkActions([])` | Bulk actions (seleção múltipla) |

### Table Class

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\ApproveOrderAction;
use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\Actions\ShipOrderAction;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordActions(self::recordActions())
            ->headerActions(self::headerActions())
            ->toolbarActions(self::toolbarActions())
            ->defaultSort('created_at', 'desc');
    }

    private static function columns(): array
    {
        return [
            TextColumn::make('number')
                ->label(__('orders.fields.order_number'))
                ->searchable()
                ->sortable(),

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
        ];
    }

    private static function filters(): array
    {
        return [
            SelectFilter::make('status')
                ->label(__('common.fields.status'))
                ->options(OrderStatus::class),

            SelectFilter::make('customer')
                ->label(__('orders.filters.customer'))
                ->relationship('customer', 'name')
                ->searchable()
                ->preload(),
        ];
    }

    /**
     * Record Actions - aparecem em cada linha da tabela
     */
    private static function recordActions(): array
    {
        return [
            ActionGroup::make([
                ViewAction::make(),
                EditAction::make(),
                ApproveOrderAction::make(),
                ShipOrderAction::make(),
                CancelOrderAction::make(),
                DeleteAction::make(),
            ]),
        ];
    }

    /**
     * Header Actions - aparecem no header da tabela
     */
    private static function headerActions(): array
    {
        return [
            // Actions que ficam visíveis no header
        ];
    }

    /**
     * Toolbar Actions - aparecem na toolbar (inclui bulk actions)
     */
    private static function toolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteBulkAction::make(),
            ]),
        ];
    }
}
```

---

## Actions Customizadas (v5)

### Namespace Unificado

No Filament v5, **todas as actions** usam `Filament\Actions\Action`:

```php
use Filament\Actions\Action;  // ✅ Correto no v5
```

### Action Simples

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class ApproveOrderAction
{
    public static function make(): Action
    {
        return Action::make('approve')
            ->label(__('orders.actions.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('orders.actions.approve') . ' ' . __('orders.label'))
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::Pending)
            ->action(function (Order $record): void {
                $record->update(['status' => OrderStatus::Approved]);

                Notification::make()
                    ->title(__('orders.messages.approved'))
                    ->success()
                    ->send();
            });
    }
}
```

### Action com Form (Modal)

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

final class CancelOrderAction
{
    public static function make(): Action
    {
        return Action::make('cancel')
            ->label(__('orders.actions.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('orders.actions.cancel') . ' ' . __('orders.label'))
            ->schema([
                Textarea::make('cancellation_reason')
                    ->label(__('orders.actions.cancel_reason'))
                    ->required()
                    ->rows(3),
            ])
            ->visible(fn (Order $record): bool => $record->status->canTransitionTo(OrderStatus::Cancelled))
            ->action(function (Order $record, array $data): void {
                $record->update([
                    'status' => OrderStatus::Cancelled,
                    'cancellation_reason' => $data['cancellation_reason'],
                ]);

                Notification::make()
                    ->title(__('orders.messages.cancelled'))
                    ->warning()
                    ->send();
            });
    }
}
```

### Action com Redirect

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use Filament\Actions\Action;

final class ShipOrderAction
{
    public static function make(): Action
    {
        return Action::make('ship')
            ->label(__('orders.actions.ship'))
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->url(fn (Order $record): string => route('orders.shipping', $record))
            ->openUrlInNewTab();
    }
}
```

---

## Pages (v5)

### List Page

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\OrderStatsWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OrderStatsWidget::class,
        ];
    }
}
```

### View Page com Header Actions

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Actions\ApproveOrderAction;
use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\Actions\ShipOrderAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ApproveOrderAction::make(),
            ShipOrderAction::make(),
            CancelOrderAction::make(),
        ];
    }
}
```

### Edit Page com Header Actions

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Actions\ApproveOrderAction;
use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ApproveOrderAction::make(),
            CancelOrderAction::make(),
            DeleteAction::make(),
        ];
    }
}
```

### Create Page

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;
}
```

---

## Relation Managers (v5)

### Quando Usar

Use Relation Managers quando o Resource tem relacionamentos `hasMany`, `belongsToMany` ou `morphMany`.

### Comando Artisan (SEMPRE usar)

```bash
php artisan make:filament-relation-manager {Model}Resource {relationship} {titleAttribute} \
    --generate --soft-deletes --panel={panel} --no-interaction
```

- `{Model}Resource` → nome do Resource pai (ex: `OrderResource`)
- `{relationship}` → nome do método de relacionamento no Model (ex: `items`)
- `{titleAttribute}` → campo usado como label (ex: `name`)
- `--generate` → gera form e table baseado no banco
- `--soft-deletes` → se o model relacionado usa soft deletes

### Estrutura Gerada

```
{Models}/
└── RelationManagers/
    └── {Relationship}RelationManager.php    ← ex: ItemsRelationManager.php
```

### Exemplo: ItemsRelationManager

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label(__('order_items.fields.product_id'))
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('quantity')
                    ->label(__('order_items.fields.quantity'))
                    ->numeric()
                    ->required()
                    ->minValue(1),

                TextInput::make('unit_price')
                    ->label(__('order_items.fields.unit_price'))
                    ->numeric()
                    ->prefix('R$')
                    ->required(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('product.name')
                    ->label(__('order_items.fields.product_id')),

                TextEntry::make('quantity')
                    ->label(__('order_items.fields.quantity')),

                TextEntry::make('unit_price')
                    ->label(__('order_items.fields.unit_price'))
                    ->money('BRL'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label(__('order_items.fields.product_id'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label(__('order_items.fields.quantity'))
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->label(__('order_items.fields.unit_price'))
                    ->money('BRL')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

### Registrar no Resource

```php
// Em {Model}Resource.php
public static function getRelations(): array
{
    return [
        RelationManagers\ItemsRelationManager::class,
    ];
}
```

---

## Widgets

### Stats Widget

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class OrderStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make(__('orders.stats.total_orders'), Order::count())
                ->icon('heroicon-o-shopping-cart'),

            Stat::make(__('orders.stats.pending'), Order::where('status', OrderStatus::Pending)->count())
                ->color('warning')
                ->icon('heroicon-o-clock'),

            Stat::make(__('orders.stats.revenue'), 'R$ ' . number_format(Order::sum('total'), 2, ',', '.'))
                ->color('success')
                ->icon('heroicon-o-currency-dollar'),
        ];
    }
}
```

---

## Form Components (v5)

### Componentes Comuns

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;

// Texto
TextInput::make('name')
    ->label(__('common.fields.name'))
    ->required()
    ->maxLength(100);

// Email
TextInput::make('email')
    ->label(__('common.fields.email'))
    ->email()
    ->required()
    ->unique(ignoreRecord: true);

// Número
TextInput::make('price')
    ->label(__('common.fields.price'))
    ->numeric()
    ->prefix('R$')
    ->step(0.01)
    ->minValue(0);

// Select com Enum
Select::make('status')
    ->label(__('common.fields.status'))
    ->options(OrderStatus::class)
    ->required()
    ->native(false);

// Select com Relationship
Select::make('category_id')
    ->label(__('products.fields.category_id'))
    ->relationship('category', 'name')
    ->searchable()
    ->preload()
    ->createOptionForm([
        TextInput::make('name')
            ->label(__('common.fields.name'))
            ->required(),
    ]);

// Toggle
Toggle::make('is_active')
    ->label(__('common.fields.is_active'))
    ->default(true);

// Textarea
Textarea::make('description')
    ->label(__('common.fields.description'))
    ->rows(5)
    ->columnSpanFull();

// DatePicker
DatePicker::make('due_date')
    ->label(__('orders.fields.due_date'))
    ->required()
    ->native(false);

// FileUpload
FileUpload::make('attachment')
    ->label(__('common.sections.attachments'))
    ->directory('attachments')
    ->maxSize(5120);
```

### Layout Components (v5)

```php
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

// Section
Section::make(__('common.sections.general'))
    ->schema([
        // componentes aqui
    ])
    ->columns(2)
    ->collapsible();

// Grid
Grid::make(3)
    ->schema([
        // componentes aqui
    ]);

// Tabs
Tabs::make(__('common.sections.settings'))
    ->tabs([
        Tab::make(__('common.sections.general'))
            ->schema([
                // componentes aqui
            ]),
        Tab::make(__('common.sections.details'))
            ->schema([
                // componentes aqui
            ]),
    ]);
```

### Actions em Forms (v5)

Para inserir actions dentro de um schema, use `Filament\Schemas\Components\Actions`:

```php
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;

Section::make('Configurações')
    ->schema([
        // campos do form...
    ])
    ->footer([
        Actions::make([
            Action::make('test')
                ->label('Testar Configuração')
                ->action(function () {
                    // lógica aqui
                }),
        ]),
    ]);
```

---

## Table Columns

### Colunas Comuns

```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\ToggleColumn;

// Texto
TextColumn::make('name')
    ->label(__('common.fields.name'))
    ->searchable()
    ->sortable();

// Texto com Badge (Enum)
TextColumn::make('status')
    ->label(__('common.fields.status'))
    ->badge()  // Usa HasColor e HasLabel do Enum
    ->sortable();

// Money
TextColumn::make('price')
    ->label(__('common.fields.price'))
    ->money('BRL')
    ->sortable();

// Data
TextColumn::make('created_at')
    ->label(__('common.fields.created_at'))
    ->dateTime('d/m/Y H:i')
    ->sortable();

// Relacionamento
TextColumn::make('customer.name')
    ->label(__('orders.fields.customer_id'))
    ->searchable();

// Boolean como Ícone
IconColumn::make('is_active')
    ->label(__('common.fields.is_active'))
    ->boolean();

// Imagem
ImageColumn::make('avatar')
    ->label(__('users.fields.avatar'))
    ->circular();

// Toggle editável
ToggleColumn::make('is_featured')
    ->label(__('products.fields.is_featured'));
```

---

## Filters

```php
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Forms\Components\DatePicker;

// SelectFilter com Enum
SelectFilter::make('status')
    ->label(__('common.fields.status'))
    ->options(OrderStatus::class);

// SelectFilter com Relationship
SelectFilter::make('customer')
    ->label(__('orders.filters.customer'))
    ->relationship('customer', 'name')
    ->searchable()
    ->preload();

// TernaryFilter (Sim/Não/Todos)
TernaryFilter::make('is_active')
    ->label(__('common.fields.is_active'));

// Filter customizado com form
Filter::make('created_at')
    ->form([
        DatePicker::make('from')
            ->label(__('orders.filters.date_from')),
        DatePicker::make('until')
            ->label(__('orders.filters.date_until')),
    ])
    ->query(function ($query, array $data) {
        return $query
            ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
    });
```

---

## Resumo de Namespaces v5

### Actions (Unificado)

```php
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
```

### Schemas

```php
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Actions;  // Wrapper para actions em forms
```

### Forms

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
```

### Infolists

```php
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
```

### Tables

```php
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
```

---

## Checklist de Resource (v5)

### Geração (NUNCA criar na mão)
- [ ] Gerado via `php artisan make:filament-resource {Model} --generate --soft-deletes --view --panel={panel} --no-interaction`
- [ ] Relation Managers via `php artisan make:filament-relation-manager` (se hasMany/belongsToMany)

### Estrutura de Pastas
- [ ] Pasta PLURAL do modelo (ex: `Users/`, `Orders/`, `ExpenseCategories/`)
- [ ] `{Model}Resource.php` - Arquivo principal limpo (DENTRO da pasta plural)
- [ ] `Schemas/` - `{Model}Form` e `{Model}Infolist` (SEMPRE gerar Infolist)
- [ ] `Tables/` - `{Models}Table` (PLURAL: `UsersTable`, `OrdersTable`)
- [ ] `Pages/` - Create, Edit, List, View (SEMPRE incluir View page)
- [ ] `RelationManagers/` - Relation Managers (se houver relações hasMany/belongsToMany)
- [ ] `Actions/` - Actions customizadas separadas (se houver)
- [ ] `Widgets/` - Widgets do Resource (se houver)

### Resource Principal
- [ ] Namespace correto: `App\Filament\{Panel}\Resources\{Models}\{Model}Resource`
- [ ] Model definido
- [ ] Labels via `__()` (getModelLabel, getPluralModelLabel)
- [ ] Ícone via `Heroicon` enum (NÃO string): `Heroicon::OutlinedUsers`
- [ ] Grupo de navegação via `__('navigation.groups.*')`
- [ ] Form delega para `Schemas/{Model}Form::configure()`
- [ ] Infolist delega para `Schemas/{Model}Infolist::configure()` (SEMPRE)
- [ ] Table delega para `Tables/{Models}Table::configure()`
- [ ] Relation Managers registrados em `getRelations()`
- [ ] Pages registradas (incluindo View)
- [ ] `getRecordRouteBindingEloquentQuery()` para soft deletes

### Traduções (OBRIGATÓRIO)
- [ ] `lang/pt_BR/{resource}.php` criado
- [ ] `lang/en/{resource}.php` criado
- [ ] Todos labels usam `__()` - nenhum hardcoded
- [ ] Campos comuns referenciam `common.*`
- [ ] Campos específicos referenciam `{resource}.*`

### Schemas
- [ ] `{Model}Form` com `Filament\Schemas\Schema`
- [ ] `{Model}Infolist` para visualização (SEMPRE gerar, não é opcional)
- [ ] Sections e layouts de `Filament\Schemas\Components\`

### Relation Managers (se aplicável)
- [ ] Gerado via `make:filament-relation-manager` (NUNCA criar na mão)
- [ ] `form()` com campos do model relacionado
- [ ] `infolist()` com entries de visualização
- [ ] `table()` com columns, filters, recordActions, headerActions, toolbarActions
- [ ] Registrado no Resource via `getRelations()`

### Tables
- [ ] `->recordActions()` para ações por linha
- [ ] `->headerActions()` para ações no header
- [ ] `->toolbarActions()` para toolbar e bulk actions
- [ ] Columns com labels em português
- [ ] Searchable nos campos importantes
- [ ] Filters úteis

### Actions (Namespace Unificado)
- [ ] Todas usam `Filament\Actions\Action`
- [ ] Actions separadas em arquivos próprios
- [ ] Método `make()` retornando Action
- [ ] Confirmação quando necessário
- [ ] Notificações de sucesso/erro
- [ ] Condição de visibilidade (`visible()`)
