---
description: Cria um Filament Resource para um Model existente
---

# /resource $ARGUMENTS

Crie um Filament Resource para o Model **$ARGUMENTS**.

## Pré-requisitos

- O Model já deve existir em `app/Models/`
- A migration já deve ter sido executada

## Passos

### 0. Preferências (OBRIGATÓRIO)

Leia **"Preferências de Comunicação e Estilo de Código"** em PROJECT.md — idioma de resposta, nível de detalhe, comentários (nível e idioma), convenção de variáveis. **Siga rigorosamente.**

### 1. Análise do Model

Verifique:
- Campos fillable
- Casts configurados
- Relationships existentes
- Enums utilizados
- Scopes disponíveis

### 2. Referência Obrigatória

**ANTES de criar o Resource, LEIA `.ai/skills/filament/SKILL.md` INTEIRO.**
Este skill define a estrutura OBRIGATÓRIA de pastas e classes para Filament v5.

### 3. Geração Base

```bash
php artisan make:filament-resource $ARGUMENTS --generate --soft-deletes --view --panel={panel} --no-interaction
```

O comando já gera a estrutura v5 correta (pasta PLURAL, Resource DENTRO, classes separadas).

### 4. Customizar Estrutura Gerada

A estrutura gerada pelo Artisan:
```
{Models}/                                ← PLURAL (ex: Users/, ServiceCategories/)
├── {Model}Resource.php                  ← DENTRO da pasta, final, LIMPO
├── Schemas/{Model}Form.php              ← final class com configure(Schema)
├── Schemas/{Model}Infolist.php          ← final class com configure(Schema) — gerado por --view
├── Tables/{Models}Table.php             ← final class com configure(Table), nome PLURAL
├── Pages/ (Create, Edit, List, View{Model})
├── RelationManagers/                    ← se Model tem hasMany/belongsToMany
└── Actions/ (se custom actions)
```

#### 4.1 Resource Principal (APENAS delegates):
```php
public static function form(Schema $schema): Schema
{
    return {Model}Form::configure($schema);
}

public static function infolist(Schema $schema): Schema
{
    return {Model}Infolist::configure($schema);
}

public static function table(Table $table): Table
{
    return {Models}Table::configure($table);
}
```

**PROIBIDO** definir componentes (TextInput, TextColumn, Section, etc.) no Resource principal.

#### 4.2 Form ({Model}Form.php)
- Organize em Sections ou Tabs (`Filament\Schemas\Components\`)
- Configure labels com `__()` — nunca hardcoded
- Adicione validações
- Use Select com `->relationship()` para relacionamentos

#### 4.2.5 Infolist ({Model}Infolist.php)
- Configure entries com `TextEntry`, `IconEntry`, etc. de `Filament\Infolists\Components\`
- Configure labels com `__()` — nunca hardcoded
- Organize em Sections para visualização

#### 4.3 Table ({Models}Table.php)
- Use `->recordActions()` (NÃO `->actions()`)
- Use `->toolbarActions()` com `BulkActionGroup` (NÃO `->bulkActions()`)
- Configure searchable, sortable, badges, formatação de datas/moedas

#### 4.4 Filtros
- SelectFilter para enums, TernaryFilter para booleanos
- Filtro de relacionamento, filtro de data se relevante

#### 4.5 Soft Deletes (se Model usa SoftDeletes)
- `getRecordRouteBindingEloquentQuery()` no Resource (NÃO `getEloquentQuery()`)
- `TrashedFilter` nos filtros
- `RestoreAction` e `ForceDeleteAction` nas recordActions
- `RestoreBulkAction` e `ForceDeleteBulkAction` nas toolbarActions
- Consulte `.ai/docs/soft-deletes.md`

#### 4.6 Relation Managers (se Model tem hasMany/belongsToMany)

Se o Model tem relações hasMany/belongsToMany:
```bash
php artisan make:filament-relation-manager $ARGUMENTS_Resource {relationship} {titleAttribute} --generate --soft-deletes --panel={panel} --no-interaction
```
- Registrar em `getRelations()` no Resource principal

### 5. Configuração do Resource

- Defina `$navigationIcon` com Heroicon enum (NÃO string): `Heroicon::OutlinedXxx`
- Defina `$navigationGroup` via `getNavigationGroup()` com `__()`
- Defina `$navigationSort`
- Configure `getModelLabel()` e `getPluralModelLabel()` com `__()`
- Configure `$recordTitleAttribute` para global search

### 6. Arquivos de Tradução (OBRIGATÓRIO)

Crie os arquivos de tradução para o Resource:
- `lang/pt_BR/{nomes}.php` - com label, plural, fields, sections, actions, messages
- `lang/en/{nomes}.php` - mesmas chaves em inglês
- Se `lang/pt_BR/common.php` não existir, crie-o também
- Consulte `.ai/docs/localization.md` para templates

Todos os labels no Form, Table, Filters e Actions devem usar `__()`.

### 7. Testes

Crie testes em `tests/Feature/Filament/{Nome}ResourceTest.php` via `php artisan make:test --pest Filament/{Nome}ResourceTest`:

```php
<?php

use App\Filament\Resources\{Models}\Pages\{Create{Model}, Edit{Model}, List{Models}};
use App\Models\{Model};
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    actingAs(User::factory()->admin()->create());
});

describe('list page', function () {
    it('renders successfully', function () {
        Livewire::test(List{Models}::class)->assertSuccessful();
    });
});

describe('create page', function () {
    it('renders successfully', function () {
        Livewire::test(Create{Model}::class)->assertSuccessful();
    });

    it('creates a record', function () {
        // ...
    });
});

describe('edit page', function () {
    it('renders successfully', function () {
        $record = {Model}::factory()->create();
        Livewire::test(Edit{Model}::class, ['record' => $record->id])->assertSuccessful();
    });

    it('updates a record', function () {
        // ...
    });

    it('deletes a record', function () {
        // ...
    });
});
```

### 8. Code Quality

Execute antes de finalizar:
```bash
vendor/bin/pint --dirty --format agent
```
Consulte `.ai/docs/pint.md`

## Output

```
✅ Resource criado (estrutura v5):
- app/Filament/Resources/{Models}/{Model}Resource.php (final, LIMPO — só delegates, DENTRO da pasta)
- app/Filament/Resources/{Models}/Schemas/{Model}Form.php (final)
- app/Filament/Resources/{Models}/Schemas/{Model}Infolist.php (final)
- app/Filament/Resources/{Models}/Tables/{Models}Table.php (final, nome PLURAL)
- app/Filament/Resources/{Models}/Pages/Create{Model}.php (final)
- app/Filament/Resources/{Models}/Pages/Edit{Model}.php (final)
- app/Filament/Resources/{Models}/Pages/List{Models}.php (final)
- app/Filament/Resources/{Models}/Pages/View{Model}.php (final)
- app/Filament/Resources/{Models}/RelationManagers/ (se hasMany/belongsToMany)
- lang/pt_BR/{models}.php
- lang/en/{models}.php
- tests/Feature/Filament/{Model}ResourceTest.php

📝 Padrão v5 aplicado:
- Pasta PLURAL ({Models}/) com Resource DENTRO
- Resource principal limpo (form/table/infolist delegam para classes separadas)
- Form: {Model}Form.php / Infolist: {Model}Infolist.php / Table: {Models}Table.php (PLURAL)
- Table usa recordActions() + toolbarActions()
- Soft Deletes via getRecordRouteBindingEloquentQuery()
- $navigationIcon com Heroicon enum (NÃO string)
- Todas classes final
- Labels com __()
```
