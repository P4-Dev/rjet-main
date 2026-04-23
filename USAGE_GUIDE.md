# Guia de Uso - Laravel AI Framework

> Framework de produtividade para desenvolvimento Laravel com Claude Code.
> Integra agentes especializados, comandos, skills, guidelines e MCP Tools
> para gerar codigo de alta qualidade com padroes consistentes.

---

## O Que e Este Framework

Um conjunto de arquivos de configuracao que transformam o Claude Code em uma equipe completa de desenvolvimento:

- **8 Sub-Agentes** especializados (arquiteto, DBA, tester, etc.)
- **7 Slash Commands** para tarefas comuns (/feature, /migrate, etc.)
- **7 Skills** com templates e padroes (arquitetura, Filament, deployment, etc.)
- **18 Guidelines** com regras do projeto (banco, API, enums, etc.)
- **15 Checklists** unificados por tipo de artefato
- **8 MCP Tools** do Laravel Boost para acesso direto ao projeto

Tudo e configurado via `PROJECT.md` — o unico arquivo que voce edita.

---

## Estrutura de Arquivos

```
projeto/
├── PROJECT.md                    # CONFIGURACAO CENTRAL (voce edita este)
├── CLAUDE.md                     # Gerado pelo Boost (nao editar)
│
├── .ai/
│   ├── guidelines/
│   │   ├── kickoff.md            # Ponto de entrada — como usar o framework
│   │   ├── architecture.md       # DTOs, Services, Actions, Models
│   │   ├── testing.md            # Padroes de testes Pest
│   │   ├── database.md           # Normalizacao, nomenclatura, morph
│   │   ├── enums.md              # PHP Enums, HasLabel/HasColor/HasIcon
│   │   ├── localization.md       # i18n, __(), arquivos de traducao
│   │   ├── api.md                # REST API, Sanctum, Swagger
│   │   ├── livewire.md           # Livewire v4, Islands, breaking changes
│   │   ├── performance.md        # N+1, cache, indices, paginacao
│   │   ├── queues.md             # Jobs, batch, chain, Horizon
│   │   ├── notifications.md      # Mail, database, broadcast, Filament
│   │   ├── error-handling.md     # BusinessException, logging
│   │   ├── git.md                # Branches, commits, CI/CD
│   │   ├── events.md             # Events, Listeners, Observers
│   │   ├── file-storage.md       # Upload, Storage, Filament FileUpload
│   │   ├── factories-seeders.md  # Factories, states, seeders
│   │   ├── scheduling.md         # Commands, schedule, Docker
│   │   ├── soft-deletes.md       # SoftDeletes, cascade, pruning
│   │   ├── phpstan.md            # Larastan, analise estatica
│   │   ├── pint.md               # Formatacao, pre-commit hook
│   │   └── mcp-tools.md          # 8 ferramentas MCP do Boost
│   │
│   ├── skills/
│   │   ├── architecture/SKILL.md # DTOs, Services, Actions, domain grouping
│   │   ├── filament/SKILL.md     # Resources, Forms, Tables, Actions
│   │   ├── testing/SKILL.md      # Pest, Livewire::test(), mocking
│   │   ├── api-rest/SKILL.md     # Controllers, Resources, Swagger
│   │   ├── livewire-components/SKILL.md  # Componentes Livewire v4
│   │   ├── api-integration/SKILL.md      # Clients, webhooks, DTOs
│   │   └── deployment/SKILL.md   # Docker, CI/CD, Nginx, Supervisor
│   │
│   ├── checklists.md             # 15 checklists por tipo de artefato
│   └── decision-tree.md          # Qual agente/comando usar para cada tarefa
│
├── .claude/
│   ├── commands/                  # Slash commands (digitados pelo usuario)
│   │   ├── feature.md            # /feature Nome
│   │   ├── resource.md           # /resource Model
│   │   ├── service.md            # /service Nome
│   │   ├── test.md               # /test Classe
│   │   ├── migrate.md            # /migrate Tabela
│   │   ├── blueprint.md          # /blueprint Descricao
│   │   └── docs.md               # /docs tipo Feature
│   │
│   └── agents/                    # Sub-agentes (invocados por nome)
│       ├── business-analyst.md
│       ├── architect.md
│       ├── dba.md
│       ├── implementer.md
│       ├── tester.md
│       ├── security.md
│       ├── reviewer.md
│       └── tech-writer.md
│
└── docker/                        # Infraestrutura (referenciada pelo skill deployment)
    ├── entrypoint-prod.sh
    ├── nginx/
    ├── php/
    └── supervisor/
```

---

## Configuracao: PROJECT.md

O `PROJECT.md` e a **unica fonte de verdade**. Todos os agentes, comandos e skills leem dele.

### Secoes Principais

#### 1. Preferencias de Comunicacao e Estilo de Codigo

Define como a IA se comporta em TODAS as interacoes:

```yaml
idioma_resposta: "pt-BR"           # Idioma das respostas da IA
nivel_resposta: "detalhado"        # detalhado | conciso | auto

comentarios:
  nivel: "minimo"                  # nenhum | minimo | moderado | verboso
  idioma: "en"                     # Idioma dos comentarios no codigo

variaveis:
  php_variaveis: "camelCase"       # $orderTotal
  classes: "PascalCase"            # OrderService
  tabelas_banco: "snake_case"      # order_items
  rotas: "kebab-case"             # /order-items

idioma_documentacao: "pt-BR"       # Idioma dos docs gerados
```

#### 2. Ambiente de Execucao

```yaml
usar_docker: true
container_app: "app"
docker_compose: "docker compose"
```

Quando `usar_docker: true`, todos os agentes prefixam comandos com `docker compose exec app`.

#### 3. Arquitetura e Agrupamento por Dominio

```yaml
agrupar_por_dominio:
  jobs: true          # app/Jobs/Email/, app/Jobs/Stock/
  integrations: true  # app/Integrations/Asaas/, app/Integrations/Correios/
  events: true        # app/Events/Order/, app/Events/Stock/
  actions: true       # app/Actions/Order/, app/Actions/Stock/
  listeners: true     # app/Listeners/Order/
  dtos: false         # Flat por padrao
  services: false     # Flat por padrao
```

#### 4. Banco de Dados

```yaml
usa_uuid: true
usa_soft_deletes: true
enum_como_string: true             # $table->string(), nunca $table->enum()
normalizacao_minima: "3NF"
```

#### 5. Padroes Visuais (Filament)

Cores, icones, navegacao, tabelas, formularios — tudo configuravel.

---

## Catalogo de Recursos

### Sub-Agentes

Invocados com: `"Use o {agente} para..."`

| Agente | Especialidade | Quando Usar |
|--------|---------------|-------------|
| `business-analyst` | Requisitos e regras de negocio | Inicio de feature, escopo vago |
| `architect` | Design e decisoes tecnicas | Apos requisitos, antes de codar |
| `dba` | Banco, migrations, queries, indices | Antes/depois de criar tabelas |
| `implementer` | Codigo de producao | Durante desenvolvimento |
| `tester` | Testes Pest completos | Apos implementacao |
| `security` | Auditoria OWASP, vulnerabilidades | Antes de PR/deploy |
| `reviewer` | Code review baseado em guidelines | Antes de merge |
| `tech-writer` | Documentacao formal (DRF, DTA, DCT) | Apos cada fase |

### Slash Commands

Digitados diretamente no Claude Code:

| Comando | O Que Faz | Output |
|---------|-----------|--------|
| `/feature Nome` | Feature completa | Model + Migration + DTO + Service + Resource + Tests |
| `/resource Model` | Filament Resource | Resource + Form + Table + Pages |
| `/service Nome` | Service Layer | Service + DTO + Tests |
| `/test Classe` | Testes Pest | Arquivo de testes completo |
| `/migrate Tabela` | Migration inteligente | Migration seguindo database.md + sugestao de Enums |
| `/blueprint Descricao` | Plano Filament | Plano detalhado para implementacao |
| `/docs tipo Feature` | Documentacao | DRF, DTA, DCT, API docs |

### Skills

Carregados automaticamente quando o contexto e relevante:

| Skill | Contexto |
|-------|----------|
| `architecture/` | Criando DTOs, Services, Actions |
| `filament/` | Trabalhando com Resources, Forms, Tables |
| `testing/` | Escrevendo testes Pest |
| `api-rest/` | Criando API REST (controllers, resources) |
| `livewire-components/` | Componentes Livewire v4 customizados |
| `api-integration/` | Integrando com APIs externas |
| `deployment/` | Docker, CI/CD, Nginx, PHP-FPM, Supervisor |

### MCP Tools (Laravel Boost)

Ferramentas que o Claude usa para acessar seu projeto em tempo real:

| Tool | O Que Faz |
|------|-----------|
| `schema` | Estrutura do banco (tabelas, colunas, indices, FKs) |
| `routes` | Rotas registradas (URI, nome, middleware) |
| `search-docs` | Documentacao oficial versionada dos pacotes |
| `database-query` | Consultas SELECT no banco (apenas leitura) |
| `tinker` | Execucao de PHP no contexto da app |
| `browser-logs` | Erros e logs do console do navegador |
| `list-artisan-commands` | Comandos Artisan disponiveis |
| `get-absolute-url` | URL absoluta correta do projeto |

### Guidelines (18)

Regras do projeto, consultadas automaticamente por contexto:

| # | Guideline | Escopo |
|---|-----------|--------|
| 1 | `database.md` | Normalizacao 3NF, nomenclatura, morph vs dedicado |
| 2 | `localization.md` | i18n, __(), arquivos de traducao |
| 3 | `api.md` | REST API, Sanctum/Passport, Swagger |
| 4 | `livewire.md` | Livewire v4, Islands, breaking changes |
| 5 | `performance.md` | N+1, cache, indices, paginacao |
| 6 | `queues.md` | Jobs, batch, chain, Horizon |
| 7 | `notifications.md` | Mail, database, broadcast, Filament |
| 8 | `error-handling.md` | BusinessException, logging estruturado |
| 9 | `git.md` | Branches, conventional commits, CI/CD |
| 10 | `enums.md` | PHP Enums, HasLabel/HasColor/HasIcon |
| 11 | `events.md` | Events, Listeners, Subscribers, Observers |
| 12 | `file-storage.md` | Upload, Storage facade, Filament FileUpload |
| 13 | `factories-seeders.md` | Factories, states, seeders idempotentes |
| 14 | `scheduling.md` | Commands, schedule, Docker scheduler |
| 15 | `soft-deletes.md` | SoftDeletes, cascade manual, pruning |
| 16 | `phpstan.md` | Larastan, levels, baseline, CI |
| 17 | `pint.md` | Formatacao, pre-commit hook |
| 18 | `mcp-tools.md` | 8 ferramentas MCP do Boost |

---

## Caso Real: Sistema de Pedidos de Restaurante

Exemplo completo de como usar o framework para construir um modulo real, do zero ate o deploy.

### Contexto

> "Preciso de um sistema de pedidos para um restaurante. Mesas, cardapio com categorias, pedidos com itens, status de preparo e conta final."

### Passo 1 — Levantar Requisitos

```
Use o business-analyst para levantar os requisitos do sistema de pedidos do restaurante
```

O BA pergunta:
- Quantas mesas? Mesas fixas ou dinamicas?
- Cardapio tem variantes (tamanho, adicional)?
- Pedido pode ser dividido entre clientes?
- Como funciona o status de preparo? (pedido → cozinha → pronto → entregue)
- Precisa de impressao de comanda?
- Formas de pagamento? Gorjeta?

Apos as respostas:

```
/docs requisitos Pedidos
```

Gera `docs/requirements/DRF-001-pedidos.md` com requisitos numerados (RF001..RF020).

### Passo 2 — Desenhar Arquitetura

```
Use o architect para desenhar a solucao do sistema de pedidos
```

Output do architect:

```
Entidades:
- Table (mesa) — numero, capacidade, status (available/occupied/reserved)
- MenuCategory — nome, sort_order, is_active
- MenuItem — nome, preco, categoria, is_available
- Order — mesa, status, garcom, subtotal, discount, total
- OrderItem — pedido, item do menu, quantidade, preco unitario, observacao, status

Enums:
- TableStatus: Available, Occupied, Reserved, Maintenance
- OrderStatus: Open, InPreparation, Ready, Delivered, Closed, Cancelled
- OrderItemStatus: Pending, Preparing, Ready, Delivered, Cancelled

Relationships:
- Table hasMany Orders
- MenuCategory hasMany MenuItems
- Order belongsTo Table
- Order hasMany OrderItems
- OrderItem belongsTo MenuItem

Events:
- OrderCreated → notificar cozinha
- OrderItemStatusChanged → atualizar status do pedido
- OrderClosed → calcular total, liberar mesa

Jobs:
- Jobs/Order/NotifyKitchenJob
- Jobs/Order/CalculateOrderTotalJob
```

### Passo 3 — Plano Filament

```
/blueprint Sistema de pedidos de restaurante com mesas, cardapio, pedidos com itens e status de preparo
```

Gera plano detalhado com campos, colunas, filtros, actions e validacoes para cada Resource.

### Passo 4 — Migrations

```
/migrate tables
```

O comando `/migrate` automaticamente:
- Detecta UUID como PK (de PROJECT.md)
- Adiciona `sort_order` com indice
- Cria `status` como `string(20)` (nunca `$table->enum()`)
- Sugere criacao do Enum `TableStatus`
- Adiciona indices em `status` e `is_active`

```php
// database/migrations/xxxx_create_tables_table.php
Schema::create('tables', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('number', 10)->unique();
    $table->unsignedInteger('capacity')->default(4);
    $table->string('status', 20)->default('available')->index();
    $table->boolean('is_active')->default(true)->index();
    $table->unsignedInteger('sort_order')->default(0)->index();
    $table->timestamps();
    $table->softDeletes();
});
```

Repetir para cada entidade:

```
/migrate menu_categories
/migrate menu_items
/migrate orders
/migrate order_items
```

### Passo 5 — Revisar Banco

```
Use o dba para revisar as 5 migrations do modulo de pedidos
```

O DBA verifica:
- Normalizacao 3NF
- Convencoes de nomenclatura (database.md)
- Indices adequados
- FKs com cascade correto
- Nenhum `$table->enum()` (proibido)

### Passo 6 — Implementar Features

```
/feature Table
/feature MenuCategory
/feature MenuItem
/feature Order
/feature OrderItem
```

Cada `/feature` cria:

```
app/Models/Table.php                      # Model com casts, relationships, scopes
app/DTOs/TableData.php                    # DTO com fromArray, fromModel
app/Services/TableService.php             # Service com create, update, delete
app/Enums/TableStatus.php                 # Enum com HasLabel, HasColor, HasIcon
app/Policies/TablePolicy.php              # Policy para autorizacao
app/Filament/Resources/TableResource.php  # Resource Filament completo
database/factories/TableFactory.php       # Factory com states
database/seeders/TableSeeder.php          # Seeder idempotente
lang/pt_BR/tables.php                     # Traducoes pt-BR
lang/en/tables.php                        # Traducoes en
tests/Feature/Filament/TableResourceTest.php  # Testes Pest
```

Para Order (com agrupamento por dominio habilitado):

```
app/Events/Order/OrderCreated.php
app/Events/Order/OrderClosed.php
app/Listeners/Order/SendKitchenNotification.php
app/Jobs/Order/NotifyKitchenJob.php
app/Jobs/Order/CalculateOrderTotalJob.php
app/Actions/Order/CloseOrderAction.php
app/Actions/Order/CancelOrderAction.php
```

### Passo 7 — Testes

```
/test OrderResource
```

Gera testes Pest cobrindo:

```php
describe('OrderResource', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    });

    describe('list', function () {
        it('renders the list page', function () { ... });
        it('lists orders with table and status', function () { ... });
        it('filters by status', function () { ... });
        it('searches by table number', function () { ... });
    });

    describe('create', function () {
        it('creates an order with items', function () { ... });
        it('validates required fields', function () { ... });
        it('calculates total automatically', function () { ... });
    });

    describe('actions', function () {
        it('closes an order and frees the table', function () { ... });
        it('cannot close an already closed order', function () { ... });
        it('cancels an order with reason', function () { ... });
    });
});
```

### Passo 8 — Seguranca e Review

```
Use o security para auditar o modulo de pedidos
```

Verifica: mass assignment, authorization, input validation, SQL injection.

```
Use o reviewer para revisar o codigo final do modulo de pedidos
```

Verifica: aderencia as guidelines, qualidade, performance, testes.

### Passo 9 — Documentacao

```
/docs completo Pedidos
```

Gera:
- `docs/requirements/DRF-001-pedidos.md`
- `docs/architecture/DTA-001-pedidos.md`
- `docs/testing/DCT-001-pedidos.md`

### Resultado Final

```
app/
├── Actions/
│   └── Order/
│       ├── CloseOrderAction.php
│       └── CancelOrderAction.php
├── DTOs/
│   ├── TableData.php
│   ├── MenuCategoryData.php
│   ├── MenuItemData.php
│   ├── OrderData.php
│   └── OrderItemData.php
├── Enums/
│   ├── TableStatus.php
│   ├── OrderStatus.php
│   └── OrderItemStatus.php
├── Events/
│   └── Order/
│       ├── OrderCreated.php
│       └── OrderClosed.php
├── Jobs/
│   └── Order/
│       ├── NotifyKitchenJob.php
│       └── CalculateOrderTotalJob.php
├── Listeners/
│   └── Order/
│       └── SendKitchenNotification.php
├── Models/
│   ├── Table.php
│   ├── MenuCategory.php
│   ├── MenuItem.php
│   ├── Order.php
│   └── OrderItem.php
├── Services/
│   ├── TableService.php
│   ├── MenuCategoryService.php
│   ├── MenuItemService.php
│   ├── OrderService.php
│   └── OrderItemService.php
├── Policies/
│   ├── TablePolicy.php
│   ├── OrderPolicy.php
│   └── MenuItemPolicy.php
└── Filament/Resources/
    ├── TableResource/
    ├── MenuCategoryResource/
    ├── MenuItemResource/
    ├── OrderResource/
    └── OrderItemResource/

database/
├── migrations/
│   ├── xxxx_create_tables_table.php
│   ├── xxxx_create_menu_categories_table.php
│   ├── xxxx_create_menu_items_table.php
│   ├── xxxx_create_orders_table.php
│   └── xxxx_create_order_items_table.php
├── factories/
└── seeders/

tests/Feature/Filament/
├── TableResourceTest.php
├── MenuCategoryResourceTest.php
├── MenuItemResourceTest.php
├── OrderResourceTest.php
└── OrderItemResourceTest.php

lang/
├── pt_BR/
│   ├── tables.php
│   ├── menu_categories.php
│   ├── menu_items.php
│   ├── orders.php
│   ├── order_items.php
│   └── enums.php
└── en/
    └── (mesma estrutura)

docs/
├── requirements/DRF-001-pedidos.md
├── architecture/DTA-001-pedidos.md
└── testing/DCT-001-pedidos.md
```

---

## Workflows

### Feature Completa (recomendado para features grandes)

```
1. business-analyst  → Levantar requisitos
2. /docs requisitos  → Formalizar requisitos
3. architect         → Desenhar solucao
4. /blueprint        → Plano Filament detalhado
5. dba               → Revisar migrations
6. /migrate          → Criar migrations
7. /feature          → Implementar (Model → Tests)
8. tester            → Cobertura adicional de testes
9. security          → Auditoria
10. reviewer         → Code review
11. /docs completo   → Documentacao formal
```

### Feature Rapida (para CRUDs simples)

```
/migrate products
/feature Product
```

### Migration Inteligente

```
/migrate order_items
```

O comando detecta automaticamente: UUID PK, FKs com `constrained()`, `decimal` para precos, `string` para status (nunca `enum`), indices compostos, e sugere Enums PHP.

### Revisao de Codigo Existente

```
Use o reviewer para revisar o OrderService
Use o dba para otimizar as queries do modulo de relatorios
Use o security para auditar os endpoints de API
```

### Deployment

```
Use o skill deployment para configurar Docker com queue workers
```

Referencia os arquivos existentes em `docker/` e segue as boas praticas do skill.

---

## Arvore de Decisao Rapida

```
O que voce quer fazer?
│
├── Criar feature nova?
│   ├── Complexa → business-analyst → architect → /blueprint → /feature
│   └── Simples → /feature
│
├── Criar migration?
│   └── /migrate
│
├── Criar Resource Filament?
│   └── /resource
│
├── Criar Service/DTO?
│   └── /service
│
├── Criar testes?
│   └── /test ou tester
│
├── Revisar codigo?
│   ├── Codigo → reviewer
│   ├── Seguranca → security
│   └── Banco → dba
│
├── Gerar documentacao?
│   └── /docs ou tech-writer
│
├── Configurar Docker/CI?
│   └── skill deployment/
│
└── Nao sabe por onde comecar?
    └── Descreva o que precisa — o Claude consulta kickoff.md e decision-tree.md
```

---

## Como Funciona Por Dentro

### O que o Claude le automaticamente ao abrir o projeto

```
1. CLAUDE.md          → Guidelines mescladas pelo Boost (Laravel, Filament, Pest, projeto)
2. .claude/commands/  → Ficam disponiveis como /comando
3. .claude/agents/    → Ficam disponiveis como "Use o {agente} para..."
4. .ai/skills/        → Carregados sob demanda quando contexto e relevante
5. MCP Tools          → schema, routes, tinker, search-docs, etc.
```

### Fluxo de uma interacao

```
Voce: "Quero criar um sistema de pedidos"
  │
  ├── Claude le PROJECT.md
  │   ├── Preferencias: responder em pt-BR, detalhado
  │   ├── Arquitetura: UUID, soft deletes, agrupamento por dominio
  │   └── Visual: heroicons outline, cor primary blue
  │
  ├── Claude le kickoff.md
  │   └── Sabe que deve perguntar detalhes ou usar business-analyst
  │
  ├── Claude consulta MCP Tools
  │   ├── schema → tabelas existentes
  │   ├── routes → rotas existentes
  │   └── search-docs → sintaxe atualizada
  │
  └── Claude responde seguindo preferencias configuradas
```

### Por que agentes leem PROJECT.md primeiro

Todo agente e comando tem esta instrucao:

> **Leia "Preferencias de Comunicacao e Estilo de Codigo" em PROJECT.md** — idioma de resposta, nivel de detalhe, comentarios (nivel e idioma), convencao de variaveis. **Siga rigorosamente.**

Isso garante que **qualquer** interacao segue suas preferencias, independente de qual agente ou comando foi usado.

---

## Customizacao

### Mudar idioma de resposta

```yaml
# PROJECT.md → Preferencias de Comunicacao
idioma_resposta: "en"    # Agora responde em ingles
```

### Mudar nivel de comentarios no codigo

```yaml
comentarios:
  nivel: "moderado"      # PHPDoc em metodos publicos + logica complexa
  idioma: "pt-BR"        # Comentarios em portugues
```

### Ativar agrupamento por dominio em Services

```yaml
agrupar_por_dominio:
  services: true         # Agora cria app/Services/Payment/, app/Services/Order/
```

### Adicionar nova guideline

Crie o arquivo em `.ai/guidelines/minha-guideline.md` e adicione a referencia em `kickoff.md`.

### Adicionar novo skill

Crie `.ai/skills/meu-skill/SKILL.md` seguindo o formato dos existentes.

### Adicionar novo command

Crie `.claude/commands/meu-comando.md`:

```markdown
---
description: Descricao do comando
---

# /meu-comando $ARGUMENTS

Instrucoes para executar quando chamado com $ARGUMENTS...
```

### Adicionar novo agent

Crie `.claude/agents/meu-agent.md`:

```markdown
---
name: meu-agent
description: O que este agent faz
tools: Read, Write, Edit, Bash, Grep, Glob
---

# Sub-Agent: Meu Agent

Voce e um especialista em...

## Sua Funcao
...

## Comportamento

### Ao receber uma tarefa:

1. **Leia "Preferencias de Comunicacao e Estilo de Codigo"** em PROJECT.md
2. ...
```

---

## Dicas

1. **Comece pelo business-analyst** para features grandes — requisitos claros geram codigo melhor
2. **Use `/migrate` antes de `/feature`** — a migration inteligente detecta padroes automaticamente
3. **DBA antes de implementar** — indice errado ou normalizacao ruim custa caro depois
4. **Security antes do merge** — vulnerabilidades sao mais baratas de corrigir cedo
5. **Tudo e configuravel** — mude o `PROJECT.md` e todos os agentes seguem
6. **Nao precisa usar tudo** — `/feature Product` sozinho ja gera codigo de qualidade
7. **Combine agentes** — "Use o architect e depois o implementer"
8. **`search-docs` e seu melhor amigo** — o Boost retorna docs especificas para suas versoes
