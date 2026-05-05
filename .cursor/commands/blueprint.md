---
description: Gera um plano detalhado usando Filament Blueprint v5 (Planning Mode)
---

# /blueprint $ARGUMENTS

Gere um **Filament Blueprint** (plano de implementação detalhado) para **$ARGUMENTS**.

## Sobre o Filament Blueprint

O Filament Blueprint é uma extensão do Filament v5 que gera planos de implementação detalhados e precisos. O objetivo é criar uma especificação completa ANTES de implementar, para que a implementação seja precisa na primeira tentativa.

## Preferências (OBRIGATÓRIO)

Leia **"Preferências de Comunicação e Estilo de Código"** em PROJECT.md — idioma de resposta, nível de detalhe, comentários (nível e idioma), convenção de variáveis. **Siga rigorosamente no plano gerado.**

## IMPORTANTE: Referência Oficial

**ANTES de gerar o plano, leia:**
- `/vendor/filament/blueprint/resources/markdown/planning/overview.md` - Formato do plano e seções obrigatórias
- Use `search-docs` para verificar sintaxe atual do Filament v5

**Namespaces obrigatórios (Filament v5):**
| Elemento | Namespace |
|----------|-----------|
| Form fields | `Filament\Forms\Components\{Component}` |
| Table columns | `Filament\Tables\Columns\{Column}` |
| Table filters | `Filament\Tables\Filters\{Filter}` |
| Actions | `Filament\Actions\{Action}` |
| Infolist entries | `Filament\Infolists\Components\{Entry}` |
| Layout | `Filament\Schemas\Components\{Component}` |
| Schema utilities | `Filament\Schemas\Components\Utilities\*` |
| Icons | `Filament\Support\Icons\Heroicon` enum |

## Formato do Blueprint

O plano deve seguir esta estrutura:

---

# Filament Blueprint: {Nome da Feature}

## Visão Geral

{Descrição breve do que será construído}

## Models

### {NomeDoModel}

**Tabela:** `{nome_tabela}`

**Atributos:**
| Campo | Tipo | Nullable | Default | Descrição |
|-------|------|----------|---------|-----------|
| id | uuid | não | - | Primary key |
| ... | ... | ... | ... | ... |

**Casts:**
```php
'status' => StatusEnum::class,
'metadata' => 'array',
```

**Relationships:**
```php
public function parent(): BelongsTo
public function children(): HasMany
```

**Traits:**
- HasUuid
- SoftDeletes
- BelongsToTenant (se multi-tenant)

**Soft Deletes:**
- {Sim/Não} — Entidades de domínio usam SoftDeletes por padrão
- Cascade: {relações que precisam cascade manual}
- Pruning: {política de retenção, se aplicável}
- Consulte `.ai/docs/soft-deletes.md`

**Scopes:**
```php
scopeActive(Builder $query): Builder
scopeByStatus(Builder $query, Status $status): Builder
```

### Enums

> Consulte `.ai/docs/enums.md` para padrões completos.

**{Nome}Status**
```php
enum {Nome}Status: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';

    // getLabel(), getColor(), getIcon() implementados
    // canTransitionTo() para validar transições
}
```

### Exceções de Domínio

> Consulte `.ai/docs/error-handling.md` para hierarquia completa.

**{Nome}Exception extends BusinessException**
```php
{Nome}Exception::cannotCancel($id)
{Nome}Exception::invalidTransition($from, $to)
```

## Filament Resource

### Scaffold Command

```bash
php artisan make:filament-resource {Nome} --generate
```

### Namespace

`App\Filament\Resources\{Nome}Resource`

### Navigation

- **Icon:** `heroicon-o-{icon}`
- **Group:** `{Grupo}`
- **Sort:** `{número}`
- **Label:** `{Label em português}`

### Form Schema

```
Section: "Informações Gerais"
├── Select: parent_id (relationship, searchable, preload)
├── TextInput: name (required, max:100)
├── Select: status (enum options, default:pending)
└── Textarea: description (nullable, rows:3)

Section: "Configurações"
├── Toggle: is_active (default:true)
└── KeyValue: metadata (nullable)

Section: "Itens" (se hasMany)
└── Repeater: items (relationship)
    ├── Select: product_id
    ├── TextInput: quantity (numeric)
    └── TextInput: price (numeric, prefix:R$)
```

### Table Schema

**Columns:**
| Coluna | Tipo | Searchable | Sortable | Formatação |
|--------|------|------------|----------|------------|
| name | TextColumn | ✓ | ✓ | - |
| status | TextColumn | - | ✓ | badge |
| total | TextColumn | - | ✓ | money:BRL |
| created_at | TextColumn | - | ✓ | dateTime:d/m/Y |

**Filters:**
- SelectFilter: status (enum options)
- SelectFilter: parent (relationship, searchable)
- Filter: created_at (date range)

**Actions:**
- ViewAction
- EditAction
- DeleteAction
- Custom: {nome} (se necessário)

**Bulk Actions:**
- DeleteBulkAction
- Custom: {nome} (se necessário)

### Pages

- ListOrders (index)
- CreateOrder (create)
- EditOrder (edit)
- ViewOrder (view) - opcional

### Relation Managers

Se houver hasMany que precisa de gerenciamento:

```
ItemsRelationManager
├── Table columns: product, quantity, price
├── Form: product_id, quantity, price
└── Actions: create, edit, delete
```

## Authorization

### Policy Rules

| Método | Regra |
|--------|-------|
| viewAny | Usuário autenticado |
| view | Usuário autenticado |
| create | Usuário com role admin ou manager |
| update | Dono do registro ou admin |
| delete | Apenas admin |

## State Transitions

Se houver status com transições:

```
pending → active (Action: Ativar)
active → completed (Action: Concluir)
active → cancelled (Action: Cancelar, requer motivo)
```

## Events

> Consulte `.ai/docs/events.md` para padrões de dispatch e listeners.

| Evento | Quando | Listeners | Queue |
|--------|--------|-----------|-------|
| {Nome}Created | Após criação no Service | SendNotification, UpdateStats | Sim, Não |
| {Nome}StatusChanged | Mudança de status | NotifyCustomer, LogAudit | Sim, Não |

## Jobs

> Consulte `.ai/docs/queues.md` para padrões de Jobs completos.

| Job | Quando | Queue | Tries | Timeout |
|-----|--------|-------|-------|---------|
| Process{Nome}Job | Após criação | high | 3 | 60s |

## Notificações

> Consulte `.ai/docs/notifications.md` para templates.

| Notification | Canais | Trigger |
|-------------|--------|---------|
| {Nome}CreatedNotification | mail, database | Após criação |

## Traduções (OBRIGATÓRIO)

O plano deve incluir a estrutura de tradução para cada Resource:

**Arquivos a criar:**
- `lang/pt_BR/{resource}.php`
- `lang/en/{resource}.php`

**Chaves obrigatórias:**
```php
return [
    'label' => '...',
    'plural' => '...',
    'fields' => [ /* campos específicos */ ],
    'sections' => [ /* títulos de sections/tabs */ ],
    'filters' => [ /* labels de filtros */ ],
    'actions' => [ /* labels de actions */ ],
    'messages' => [ /* notificações e confirmações */ ],
    'stats' => [ /* labels de widgets/stats */ ],
];
```

**Regra:** Todos os labels no Form, Table, Actions e Widgets devem usar `__()`.
Campos comuns (status, is_active, etc.) ficam em `common.php`.
Consulte `.ai/docs/localization.md` para templates completos.

## API REST (se aplicável)

Se a feature precisa de API REST, inclua no blueprint:

### Endpoints

| Método | Path | Descrição | Auth |
|--------|------|-----------|------|
| GET | `/api/v1/{nomes}` | Listar (paginado) | Sanctum/Passport |
| POST | `/api/v1/{nomes}` | Criar | Sanctum/Passport |
| GET | `/api/v1/{nomes}/{id}` | Detalhar | Sanctum/Passport |
| PUT | `/api/v1/{nomes}/{id}` | Atualizar | Sanctum/Passport |
| DELETE | `/api/v1/{nomes}/{id}` | Remover | Sanctum/Passport |

### API Resource Fields

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | uuid | ID do recurso |
| name | string | Nome |
| status | string | Status atual |
| created_at | ISO 8601 | Data de criação |

### Filtros Disponíveis

| Parâmetro | Tipo | Descrição |
|-----------|------|-----------|
| status | string | Filtrar por status |
| search | string | Busca por nome |
| sort | string | Ordenação (prefixo `-` para desc) |
| per_page | integer | Itens por página (max 100) |

### Swagger Tags

- `{Nomes}` — CRUD de {nomes}

Consulte `.ai/docs/api.md` para regras completas e skill `api-rest` para templates.

## Componentes Livewire Custom (se aplicável)

Se a feature precisa de componentes que vão além do Filament padrão:

### Componentes a Criar

| Componente | Tipo | Motivo |
|------------|------|--------|
| `{Nome}Chart` | Widget (ChartWidget) | Visualização gráfica |
| `{Nome}Sortable` | Class-based + wire:sort | Reordenação drag-and-drop |
| `{Nome}Dashboard` | Class-based + Islands | Dashboard com regiões independentes |

### Islands Planejadas

| Island | Conteúdo | Loading |
|--------|----------|---------|
| `stats` | KPIs e contadores | `lazy: true` |
| `chart` | Gráfico de tendência | `defer: true` |
| `feed` | Lista de atividades | `lazy: true` + append |

### Interação JavaScript

| Feature | Técnica | Descrição |
|---------|---------|-----------|
| Busca assíncrona | `#[Json]` + Alpine | Busca com retorno JS |
| Loading otimista | `wire:show` + `data-loading` | Feedback instantâneo |
| Drag-and-drop | `wire:sort` | Reordenação nativa |

Consulte `.ai/docs/livewire.md` e skill `livewire-components` para templates.

## Commands de Implementação

Ordem recomendada de execução:

```bash
# 1. Migration
php artisan make:migration create_{table}_table

# 2. Model
php artisan make:model {Nome}

# 3. Enum
# Criar manualmente em app/Enums/

# 4. Policy
php artisan make:policy {Nome}Policy --model={Nome}

# 5. Resource
php artisan make:filament-resource {Nome} --generate

# 6. Factory
php artisan make:factory {Nome}Factory

# 7. Seeder
php artisan make:seeder {Nome}Seeder

# 8. Tests
# Criar manualmente seguindo padrões

# 9. Code Quality
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

## Estimativa

| Componente | Complexidade | Tempo Estimado |
|------------|--------------|----------------|
| Model + Migration | Baixa | 15 min |
| Resource básico | Média | 30 min |
| Actions customizadas | Média | 20 min |
| Testes | Média | 45 min |
| **Total** | - | **~2h** |

---

## Notas Importantes

1. Este blueprint é um PLANO, não implementação
2. Revise o plano antes de implementar
3. Ajuste conforme necessidades específicas do projeto
4. Use `/feature` ou `/resource` para implementar
5. Consulte `.ai/checklists.md` para verificar completude do plano
6. Considere soft deletes para entidades de domínio (`.ai/docs/soft-deletes.md`)
7. Planeje factories com states para testes (`.ai/docs/factories-seeders.md`)

## Próximos Passos

Após aprovar este blueprint:
1. Execute `/feature {Nome}` para implementação completa
2. Ou implemente manualmente seguindo a ordem de commands

---

## Instruções para Geração

Ao receber $ARGUMENTS:

1. **Analise** os requisitos descritos
2. **Identifique** Models, relacionamentos, status
3. **Estruture** o blueprint seguindo o formato acima
4. **Seja específico** em:
   - Tipos de dados exatos
   - Componentes Filament corretos
   - Validações necessárias
   - Transições de estado
5. **Consulte** `search-docs` para sintaxe atual
6. **Siga** os padrões de PROJECT.md

O output deve ser o Blueprint completo em Markdown, pronto para revisão humana antes da implementação.
