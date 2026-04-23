# 📋 PROJECT.md - Configure seu projeto aqui

> ⚠️ **EDITE ESTE ARQUIVO** para cada novo projeto.
> Depois rode `./setup.sh` para aplicar as configurações.

---

## Informações Básicas

```yaml
nome: "RJET"
descricao: "Plataforma interna de gerenciamento de BPO financeiro e DHO da empresa RJET."
versao_laravel: "13"        # 10, 11, ou 12
versao_filament: "5"        # 3, 4, ou 5
versao_php: "8.4"           # 8.1, 8.2, 8.3, ou 8.4
```

---

## Preferências de Comunicação e Estilo de Código

> **OBRIGATÓRIO:** Todo agente, comando e skill DEVE ler esta seção antes de interagir.
> Estas configurações definem como a IA se comunica, comenta código e gera documentação.

```yaml
# === 1. Idioma de Resposta da IA ===
# Em que idioma a IA deve responder ao usuário
idioma_resposta: "pt-BR"           # pt-BR, en, es
# A IA SEMPRE responde neste idioma, independente do idioma da pergunta

# === 2. Nível de Resposta ===
# Quão detalhadas devem ser as explicações da IA
nivel_resposta: "detalhado"        # detalhado | conciso | auto
# detalhado: explica decisões, alternativas, motivos, trade-offs
# conciso: respostas diretas, sem explicação extra, vai direto ao ponto
# auto: detalhado para conceitos novos, conciso para tarefas repetitivas

# === 3. Comentários no Código ===
comentarios:
  nivel: "minimo"                  # nenhum | minimo | moderado | verboso
  # nenhum:   zero comentários — código 100% auto-documentado
  # minimo:   só em lógica complexa/não-óbvia (RECOMENDADO)
  # moderado: PHPDoc em métodos públicos + lógica complexa
  # verboso:  PHPDoc em tudo + inline em cada bloco lógico

  idioma: "en"                     # pt-BR | en — idioma dos comentários no código
  # "en" = comentários em inglês (padrão da indústria)
  # "pt-BR" = comentários em português

# === 4. Convenção de Variáveis ===
variaveis:
  php_variaveis: "camelCase"       # $orderTotal, $isActive, $userName
  php_metodos: "camelCase"         # getTotal(), calculateDiscount(), isActive()
  php_propriedades: "camelCase"    # $this->orderTotal, $this->isActive
  php_constantes: "UPPER_SNAKE"    # MAX_ATTEMPTS, DEFAULT_STATUS
  classes: "PascalCase"            # OrderService, CreateOrderAction
  enums_keys: "PascalCase"         # Pending, Active, Cancelled
  tabelas_banco: "snake_case"      # orders, order_items, stock_movements (plural)
  colunas_banco: "snake_case"      # is_active, created_by, sort_order
  rotas: "kebab-case"             # /order-items, /stock-movements
  views_blade: "kebab-case"       # order-details.blade.php, user-profile.blade.php
  config_keys: "snake_case"        # queue_driver, max_upload
  js_variaveis: "camelCase"        # orderTotal, isActive
  js_componentes: "PascalCase"     # OrderCard, UserProfile (Alpine/Vue)
  css_classes: "kebab-case"        # order-card, user-profile

# === 5. Idioma das Documentações ===
idioma_documentacao: "pt-BR"       # pt-BR | en
# Idioma para docs gerados: README, ADRs, DRFs, changelogs, comentários em .md
# Arquivos de código (.php, .js) seguem 'comentarios.idioma' acima
```

---

## Models dos Agentes (Claude)

> **OBRIGATÓRIO:** Ao acionar qualquer agente via `Task`, usar o model definido aqui.
> Configuração centralizada para otimizar custo vs qualidade por tipo de tarefa.

```yaml
# === Models disponíveis ===
# opus:   Máxima capacidade, raciocínio complexo — decisões, arquitetura, código
# sonnet: Alta capacidade, rápido — testes, reviews, análise
# haiku:  Rápido e econômico — documentação, buscas simples

# === Configuração por Agente ===
agentes:
  business-analyst: "opus"       # Análise profunda de requisitos, edge cases, faseamento
  architect: "opus"              # Decisões arquiteturais, trade-offs, normalização
  implementer: "opus"            # Geração de código de alta qualidade
  tester: "sonnet"               # Escrita de testes seguindo padrões definidos
  reviewer: "sonnet"             # Code review, detecção de problemas
  dba: "sonnet"                  # SQL, índices, migrations — padrões bem definidos
  security: "sonnet"             # Análise de segurança, OWASP
  tech-writer: "haiku"           # Documentação, tarefa mais simples e repetitiva

# === Resumo de custo ===
# Opus:   BA + Architect + Implementer  (3 agentes — PENSAM e CRIAM)
# Sonnet: Tester + Reviewer + DBA +     (4 agentes — VALIDAM e REVISAM)
#         Security
# Haiku:  Tech-writer                   (1 agente  — DOCUMENTA)
```

---

## Ambiente de Execução

```yaml
# Docker
usar_docker: true                # true = comandos rodam dentro do container
container_app: "ia"              # Nome do container principal (PHP/artisan)
container_db: "postgres_ia"               # Nome do container do banco de dados
docker_compose: "docker compose" # Comando do compose (docker compose ou docker-compose)

# Prefixo de comandos — quando usar_docker = true, todos os comandos
# artisan, pest, composer, npm devem ser executados assim:
docker compose exec ia php artisan migrate
docker compose exec ia ./vendor/bin/pest
docker compose exec ia composer install
docker compose exec ia npm run build
#
# Quando usar_docker = false, rodar diretamente:
#   php artisan migrate
#   ./vendor/bin/pest
```

---

## Arquitetura

### Estrutura de Pastas

```yaml
# Defina onde ficam seus arquivos (use padrão Laravel ou customize)
models: "app/Models"
dtos: "app/DTOs"
services: "app/Services"
actions: "app/Actions"
repositories: "app/Repositories"      # Deixe vazio se não usar
integrations: "app/Integrations"      # Para integrações com APIs externas
jobs: "app/Jobs"
events: "app/Events"
listeners: "app/Listeners"
policies: "app/Policies"
filament_resources: "app/Filament/Resources"
filament_pages: "app/Filament/Pages"
filament_widgets: "app/Filament/Widgets"
```

### Agrupamento por Domínio

> **Regra:** Comece flat. Quando uma pasta ultrapassar ~10 arquivos, agrupe por domínio.
> O agrupamento é por subpasta com o nome do domínio (PascalCase).

```yaml
# Quais pastas devem ser organizadas por domínio/responsabilidade?
agrupar_por_dominio:
  jobs: true                    # SEMPRE agrupar — multiplicam rápido
  # app/Jobs/Email/SendWelcomeJob.php
  # app/Jobs/Stock/UpdateStockJob.php
  # app/Jobs/Payment/ProcessRefundJob.php

  integrations: true            # SEMPRE agrupar — natural por provider
  # app/Integrations/Asaas/AsaasClient.php
  # app/Integrations/Correios/CorreiosClient.php

  events: true                  # SEMPRE agrupar — natural por entidade
  # app/Events/Order/OrderCreated.php
  # app/Events/Stock/StockLow.php

  actions: true                 # SEMPRE agrupar — multiplicam rápido
  # app/Actions/Order/CreateOrderAction.php
  # app/Actions/Order/CancelOrderAction.php
  # app/Actions/Stock/AdjustStockAction.php

  listeners: true               # Acompanha a organização dos events
  # app/Listeners/Order/SendOrderConfirmation.php
  # app/Listeners/Stock/NotifyLowStock.php

  dtos: false                   # Flat por padrão — agrupar só se múltiplos DTOs por entidade
  # Se true:
  # app/DTOs/Order/CreateOrderData.php
  # app/DTOs/Order/UpdateOrderData.php

  services: false               # Flat por padrão — geralmente 1 por entidade
  # Se true (sub-domínio complexo):
  # app/Services/Payment/PaymentService.php
  # app/Services/Payment/RefundService.php

  # Estes NUNCA agrupam por domínio (já tem organização própria):
  models: false                 # Flat — Laravel convention
  policies: false               # Flat — 1 por model
```

#### Exemplo Completo com Agrupamento

```
app/
├── Actions/
│   ├── Order/
│   │   ├── CreateOrderAction.php
│   │   ├── ApproveOrderAction.php
│   │   └── CancelOrderAction.php
│   └── Stock/
│       └── AdjustStockAction.php
├── DTOs/
│   ├── OrderData.php               # Flat (poucos)
│   └── ProductData.php
├── Events/
│   ├── Order/
│   │   ├── OrderCreated.php
│   │   ├── OrderShipped.php
│   │   └── OrderCancelled.php
│   └── Stock/
│       └── StockLow.php
├── Integrations/
│   ├── Asaas/
│   │   ├── AsaasClient.php
│   │   ├── AsaasPaymentDTO.php
│   │   └── AsaasException.php
│   └── Correios/
│       ├── CorreiosClient.php
│       └── CorreiosException.php
├── Jobs/
│   ├── Email/
│   │   ├── SendWelcomeJob.php
│   │   └── SendOrderConfirmationJob.php
│   ├── Stock/
│   │   ├── UpdateStockJob.php
│   │   └── SyncInventoryJob.php
│   └── Payment/
│       └── ProcessRefundJob.php
├── Listeners/
│   ├── Order/
│   │   └── SendOrderConfirmation.php
│   └── Stock/
│       └── NotifyLowStock.php
├── Models/                          # Flat (sempre)
│   ├── Order.php
│   └── Product.php
├── Policies/                        # Flat (sempre)
│   ├── OrderPolicy.php
│   └── ProductPolicy.php
└── Services/
    ├── OrderService.php             # Flat (poucos)
    └── ProductService.php
```

### Padrões de Código

```yaml
# Marque com [x] os padrões que você usa
usar_dtos: true                 # Data Transfer Objects
usar_services: true             # Service classes para lógica
usar_actions: true              # Action classes (single responsibility)
usar_repositories: false        # Repository pattern
usar_form_requests: true        # Form Requests para validação
usar_policies: true             # Policies para autorização
usar_events: true               # Events e Listeners
usar_observers: false           # Model Observers
usar_traits_customizados: true  # Traits reutilizáveis nos Models
```

### Traits de Model

```yaml
# Liste os traits que seus Models devem usar
traits:
  - "HasUuid"                   # Se usa UUID como primary key
  - "SoftDeletes"               # Se usa soft deletes
  # - "BelongsToTenant"         # Se usa multi-tenancy
  # - "HasCreatedBy"            # Se rastreia quem criou
  # - "Auditable"               # Se usa auditoria
```

---

## Banco de Dados

> **IMPORTANTE:** Consulte `.ai/docs/database.md` para regras completas de
> normalização, padronização de campos e decisão morph vs dedicado.
> Consulte `.ai/docs/enums.md` para padrões de Enums PHP com Filament.
> Consulte `.ai/docs/performance.md` para índices e otimização de queries.

```yaml
driver: "pgsql"                 # mysql, pgsql, sqlite
usa_uuid: true                  # UUID como primary key
usa_soft_deletes: true          # Soft deletes por padrão
usa_timestamps: true            # created_at, updated_at
enum_como_string: true          # Enum no banco = string, cast para Enum PHP
normalizacao_minima: "3NF"      # Terceira Forma Normal
morph_map: true                 # Usar Relation::enforceMorphMap()
```

### Padronização de Campos

```yaml
# Convenção obrigatória de nomes (ver database.md para lista completa)
booleanos: "is_*, has_*"        # is_active, is_default, has_attachments
ordenacao: "sort_order"          # NÃO usar: order, position, order_column
datas_evento: "*_at"             # published_at, approved_at, cancelled_at
autoria: "*_by"                  # created_by, updated_by, approved_by
monetarios: "decimal(10,2)"     # NÃO usar: float, double
status_tipo: "string(20-30)"    # NÃO usar: enum no banco
```

### Entidades Compartilhadas

```yaml
# Entidades que usam morph (tabela única com morphTo)
morph:
  - addresses                    # Endereços (HasAddresses trait)
  - contacts                     # Contatos (HasContacts trait)
  - notes                        # Notas (HasNotes trait)
  - attachments                  # Anexos (HasAttachments trait)

# Ver .ai/docs/database.md para matriz de decisão morph vs dedicado
```

### Padrão para Enums

```php
// Migration: sempre string, nunca enum
$table->string('status', 20)->default('pending')->index();

// Model: cast para Enum PHP
protected function casts(): array
{
    return [
        'status' => OrderStatus::class,
    ];
}
```

---

## Filament

```yaml
# Configurações do Filament
tema_icones: "heroicon"         # heroicon, phosphor, tabler
estilo_icones: "outline"        # outline, solid
usar_tabs_em_forms: true        # Organizar forms em tabs
usar_sections_em_forms: true    # Usar sections nos forms
acoes_async: true               # Actions executam via Jobs
```

## Livewire v4

> **IMPORTANTE:** Consulte `.ai/docs/livewire.md` para regras completas do v4.

```yaml
# Configurações de componentes Livewire customizados
livewire:
  versao: "4"
  formato_padrao: "class"       # class (para Filament) ou sfc (standalone)
  usar_islands: true            # Islands para render parcial
  usar_lazy: true               # #[Lazy] para componentes pesados
  usar_form_objects: true       # Form Objects para forms complexos

  # Estrutura de pastas
  componentes: "app/Livewire/Components"
  modais: "app/Livewire/Modals"
  forms: "app/Livewire/Forms"
  views: "resources/views/livewire"
```

### Estrutura de Resource

```
app/Filament/Resources/
└── {Nome}Resource/
    ├── Actions/                # Actions customizadas separadas
    │   ├── ApproveAction.php
    │   └── CancelAction.php
    ├── Pages/                  # Pages do Resource
    │   ├── Create{Nome}.php
    │   ├── Edit{Nome}.php
    │   ├── List{Nome}s.php
    │   └── View{Nome}.php
    ├── Schemas/                # Forms e Infolists
    │   ├── {Nome}FormSchema.php
    │   └── {Nome}InfolistSchema.php
    ├── Tables/                 # Table, Columns, Filters
    │   └── {Nome}Table.php
    ├── Widgets/                # Widgets do Resource
    │   └── {Nome}StatsWidget.php
    └── {Nome}Resource.php      # Arquivo principal (limpo)
```

### Regras
- Resource principal delega para classes específicas
- Actions separadas em arquivos próprios (não inline)
- Forms e Infolists em `Schemas/`
- Configuração de Table em `Tables/`

---

## Testes

```yaml
framework: "pest"                # pest ou phpunit
usar_factories: true
usar_seeders: true
mock_externo: true              # Mockar APIs/SSH externas
coverage_minimo: 80             # Porcentagem mínima
```

---

## API RESTful (opcional)

> **IMPORTANTE:** Consulte `.ai/docs/api.md` para regras completas de
> versionamento, autenticação, documentação Swagger e boas práticas.

```yaml
# Descomente e configure se o projeto expõe API REST
# api:
#   habilitado: true
#   autenticacao: "sanctum"       # sanctum ou passport
#   versao_atual: "v1"
#   documentacao: "l5-swagger"    # l5-swagger para OpenAPI/Swagger
#   rate_limit: 60                # requests por minuto
#   cors_origins: "*"             # origens permitidas
#   prefixo: "/api"
#
#   # Estrutura de pastas
#   controllers: "app/Http/Controllers/Api"
#   resources: "app/Http/Resources/Api"
#   requests: "app/Http/Requests/Api"
#   routes: "routes/api"
```

---

## Events e Listeners

> **IMPORTANTE:** Consulte `.ai/docs/events.md` para padrões de Events, Listeners,
> Subscribers e Model Observers.

```yaml
usar_events: true               # Events e Listeners para ações de negócio
usar_observers: false            # Model Observers para lifecycle (slug, cache)
# subscriber_por_dominio: true   # Agrupar listeners em Subscribers por domínio
```

## Error Handling

> **IMPORTANTE:** Consulte `.ai/docs/error-handling.md` para hierarquia de exceções,
> logging estruturado e tratamento em Filament.

```yaml
# Exceções customizadas
base_exception: "BusinessException"        # Exceção base para erros de negócio
integration_exception: "IntegrationException"  # Exceção para APIs externas
logging: "structured"                      # Sempre logging estruturado (array de contexto)
```

## Filas e Jobs

> **IMPORTANTE:** Consulte `.ai/docs/queues.md` para padrões de Jobs, Batch, Chain,
> rate limiting e configuração do Horizon.

```yaml
# Configuração de filas
queue_driver: "redis"            # redis, database, sqs
usar_horizon: true               # Dashboard e supervisor de filas
filas:
  high: "Pagamentos, autenticação"
  default: "Emails, notificações"
  low: "Relatórios, cleanup"
```

## Notificações

> **IMPORTANTE:** Consulte `.ai/docs/notifications.md` para padrões de Notifications,
> Mailables, templates e Filament in-app.

```yaml
# Canais de notificação
canais: ["mail", "database"]     # mail, database, broadcast, sms, slack
filament_bell: true              # Notificações no dropdown do Filament
queue_notifications: true        # Sempre usar ShouldQueue
```

## Git e CI/CD

> **IMPORTANTE:** Consulte `.ai/docs/git.md` para padrões de branches, commits
> convencionais, PR template e pipelines GitHub Actions.

```yaml
# Estratégia de branch
branch_strategy: "git-flow-simplificado"  # main + develop + feature/fix/hotfix
commit_format: "conventional"              # feat/fix/refactor/test/docs/chore
deploy_ci: "github-actions"               # GitHub Actions para CI/CD
registry: "ghcr.io"                        # Container registry
```

## File Storage & Uploads

> **IMPORTANTE:** Consulte `.ai/docs/file-storage.md` para regras completas de
> upload, Storage facade, validação de arquivos e integração com Filament.

```yaml
# Configuração de storage
storage:
  disco_padrao: "local"            # local, public, s3
  disco_publico: "public"          # Para arquivos acessíveis publicamente
  max_upload: 10240                # 10MB em KB
  tipos_permitidos: "pdf,doc,docx,xls,xlsx,jpg,png,webp"
  imagem_max: 2048                 # 2MB para imagens
```

## Factories & Seeders

> **IMPORTANTE:** Consulte `.ai/docs/factories-seeders.md` para padrões de
> factories, states, sequences e seeders idempotentes.

```yaml
# Configuração de factories e seeders
factories:
  faker_locale: "pt_BR"           # Locale do Faker
  usar_states: true                # States para variações (active, pending, etc.)
  usar_sequences: true             # Sequences para dados alternados
  usar_recycle: true               # recycle() para parents compartilhados
seeders:
  idempotente: true                # updateOrCreate/firstOrCreate
  ordem: "roles → users → config" # Ordem de execução
```

## Scheduling & Console Commands

> **IMPORTANTE:** Consulte `.ai/docs/scheduling.md` para padrões de commands,
> schedule (via `routes/console.php`) e execução em Docker.

```yaml
# Configuração de scheduling
scheduling:
  timezone: "America/Sao_Paulo"    # Timezone explícito
  scheduler_docker: "schedule:work" # Via Supervisor no Docker
  overlap_prevention: true          # withoutOverlapping()
  one_server: true                  # onOneServer() em multi-server
```

## Soft Deletes & Data Lifecycle

> **IMPORTANTE:** Consulte `.ai/docs/soft-deletes.md` para regras de soft delete,
> cascade manual, pruning e integração com Filament.

```yaml
# Configuração de soft deletes
soft_deletes:
  padrao_domain: true              # SoftDeletes padrão para entidades de domínio
  hard_delete: "logs, sessions, tokens"  # Hard delete para dados transientes
  cascade_manual: true             # cascadeOnDelete NÃO funciona com SoftDeletes
  pruning: true                    # MassPrunable/Prunable para cleanup
```

## Qualidade de Codigo

> **IMPORTANTE:** Consulte `.ai/docs/phpstan.md` para Larastan e `.ai/docs/pint.md` para formatacao.

```yaml
# Analise estatica e formatacao
qualidade:
  phpstan_level: 5                 # Nivel minimo (target: 8)
  pint_preset: "laravel"           # Preset do Pint
  pint_obrigatorio: true           # Rodar Pint antes de commit
  pre_commit_hook: true            # Git hook para Pint + PHPStan
```

---

## Integrações (opcional)

```yaml
# Descomente e configure as integrações que usar
# filas:
#   driver: "redis"
#   usar_horizon: true
#
# cache:
#   driver: "redis"
#
# broadcasting:
#   driver: "soketi"            # pusher, soketi, redis
#
# storage:
#   driver: "s3"
#
# ssh:
#   biblioteca: "phpseclib"
#
# pagamentos:
#   provider: "asaas"           # asaas, stripe, pagarme
```

---

## Multi-tenancy (opcional)

```yaml
# Descomente se usar multi-tenancy
# multitenancy:
#   habilitado: true
#   pacote: "stancl/tenancy"    # stancl/tenancy, spatie/laravel-multitenancy
#   coluna: "tenant_id"
#   trait: "BelongsToTenant"
```

---

## Internacionalização (i18n)

> **IMPORTANTE:** Consulte `.ai/docs/localization.md` para regras completas.

```yaml
# Configuração de idioma
locale: "pt_BR"                  # Idioma padrão da aplicação
fallback_locale: "en"            # Idioma fallback
idiomas_obrigatorios:            # Sempre criar traduções em:
  - "pt_BR"
  - "en"

# Regras
usar_traducoes: true             # OBRIGATÓRIO: __('chave') em todos os labels
lang_por_resource: true          # Arquivo de tradução por Resource
lang_common: true                # common.php para campos compartilhados
lang_navigation: true            # navigation.php para menu
```

### Estrutura de Tradução por Resource

```
lang/
├── pt_BR/
│   ├── common.php               # Campos compartilhados
│   ├── navigation.php           # Menu de navegação
│   └── {resources}.php          # Um arquivo por Resource
├── en/
│   ├── common.php
│   ├── navigation.php
│   └── {resources}.php
```

---

## Convenções de Nomenclatura

```yaml
# Idioma para nomenclatura
idioma_codigo: "en"             # Código em inglês
idioma_ui: "pt-BR"              # Interface em português

# Exemplos de nomenclatura que você prefere
# (o agente seguirá esse padrão)
exemplos:
  model: "Order"                # Singular, PascalCase
  table: "orders"               # Plural, snake_case
  controller: "OrderController"
  resource: "OrderResource"
  dto: "OrderData"
  service: "OrderService"
  action: "CreateOrderAction"
  job: "ProcessOrderJob"
  event: "OrderCreated"
  policy: "OrderPolicy"
  test: "OrderTest"
```

---

## 🎨 Padrões Visuais (Filament)

> **IMPORTANTE:** Esta seção define como a interface será gerada.
> Configure aqui para manter consistência visual em todo o projeto.

### Cores

```yaml
# Cores do tema (use cores Tailwind ou hex)
cores:
  primary: "blue"               # Cor principal (botões, links)
  # Opções: slate, gray, zinc, neutral, stone, red, orange, 
  # amber, yellow, lime, green, emerald, teal, cyan, sky, 
  # blue, indigo, violet, purple, fuchsia, pink, rose

  # Cores de status (para badges e indicadores)
  status:
    success: "success"          # Verde - ativo, concluído
    warning: "warning"          # Amarelo - pendente, atenção
    danger: "danger"            # Vermelho - erro, cancelado
    info: "info"                # Azul - informação
    gray: "gray"                # Cinza - inativo, rascunho
```

### Ícones

```yaml
# Biblioteca de ícones
icones:
  biblioteca: "heroicon"        # heroicon, phosphor, tabler
  estilo: "outline"             # outline, solid
  
  # Mapeamento de ícones comuns (customize conforme seu projeto)
  mapeamento:
    # Navegação
    dashboard: "heroicon-o-home"
    usuarios: "heroicon-o-users"
    configuracoes: "heroicon-o-cog-6-tooth"
    
    # CRUD
    criar: "heroicon-o-plus"
    editar: "heroicon-o-pencil"
    deletar: "heroicon-o-trash"
    visualizar: "heroicon-o-eye"
    
    # Status
    ativo: "heroicon-o-check-circle"
    inativo: "heroicon-o-x-circle"
    pendente: "heroicon-o-clock"
    
    # Ações
    buscar: "heroicon-o-magnifying-glass"
    filtrar: "heroicon-o-funnel"
    exportar: "heroicon-o-arrow-down-tray"
    importar: "heroicon-o-arrow-up-tray"
    
    # Adicione ícones específicos do seu projeto:
    # produtos: "heroicon-o-cube"
    # pedidos: "heroicon-o-shopping-cart"
    # clientes: "heroicon-o-user-group"
    # estoque: "heroicon-o-archive-box"
    # financeiro: "heroicon-o-currency-dollar"
    # relatorios: "heroicon-o-chart-bar"
```

### Navegação

```yaml
# Grupos do menu lateral (sidebar)
navegacao:
  grupos:
    - nome: "Principal"
      ordem: 1
      # items: Dashboard
      
    - nome: "Cadastros"
      ordem: 2
      # items: Produtos, Categorias, Clientes, etc
      
    - nome: "Operações"
      ordem: 3
      # items: Pedidos, Movimentações, etc
      
    - nome: "Relatórios"
      ordem: 4
      # items: Vendas, Estoque, Financeiro, etc
      
    - nome: "Configurações"
      ordem: 99
      # items: Usuários, Permissões, Sistema
```

### Tabelas (Table)

```yaml
tabelas:
  itens_por_pagina: 15
  
  # Colunas padrão que devem aparecer em todas as tabelas
  colunas_padrao:
    - nome/titulo (searchable, sortable)
    - status (badge colorido, filterable)
    - created_at (datetime, sortable, toggleable hidden)
  
  # Ações padrão em cada linha
  acoes_linha:
    - view (se tiver página de visualização)
    - edit
    - delete (com confirmação)
  
  # Ações em massa (bulk actions)
  acoes_massa:
    - delete
  
  # Filtros padrão
  filtros:
    - status (quando houver)
    - created_at (range de datas)
```

### Formulários (Form)

```yaml
formularios:
  # Layout dos formulários
  layout: "sections"            # sections, tabs, wizard, simple
  colunas: 2                    # Número de colunas por seção
  
  # Componentes preferidos por tipo de dado
  componentes:
    texto_curto: "TextInput"
    texto_longo: "Textarea"     # rows: 3
    rich_text: "RichEditor"
    selecao_unica: "Select"     # searchable, preload
    selecao_multipla: "Select"  # multiple
    boolean: "Toggle"
    data: "DatePicker"          # native: false
    datetime: "DateTimePicker"  # native: false
    dinheiro: "TextInput"       # prefix: R$, numeric
    arquivo: "FileUpload"
    imagem: "FileUpload"        # image, max 2MB
    
  # Validações visuais
  mostrar_erros_inline: true
  campos_obrigatorios: "asterisco"  # asterisco, texto, nenhum
```

### Cards e Widgets

```yaml
widgets:
  # Stats (cards numéricos do dashboard)
  stats:
    mostrar_icone: true
    mostrar_descricao: true
    mostrar_trend: false        # Seta indicando tendência
    
  # Gráficos
  charts:
    tipo_padrao: "line"         # line, bar, pie, doughnut
    periodo_padrao: "30 dias"
    cores: ["#3B82F6", "#10B981", "#F59E0B", "#EF4444"]
```

### Mensagens e Notificações

```yaml
mensagens:
  # Posição das notificações
  posicao: "top-right"          # top-right, top-center, bottom-right
  
  # Textos padrão (use {recurso} como placeholder)
  sucesso:
    criar: "{recurso} criado com sucesso!"
    atualizar: "{recurso} atualizado com sucesso!"
    deletar: "{recurso} removido com sucesso!"
    
  confirmacao:
    deletar: "Tem certeza que deseja excluir este {recurso}?"
    
  # Empty states (quando lista está vazia)
  vazio:
    lista: "Nenhum {recurso} cadastrado"
    busca: "Nenhum resultado encontrado"
```

---

## Regras Específicas do Projeto

```markdown
<!-- Adicione aqui regras específicas do seu projeto -->
<!-- Exemplo: -->

### Regras de Negócio
- Pedidos só podem ser cancelados se status != 'shipped'
- Usuários admin podem ver todos os recursos
- Notificações são enviadas via queue

### Padrões de API
- Sempre retornar JSON
- Usar API Resources para transformação
- Versionamento via URL (/api/v1/)

### Outras Regras
- ...
```

---

## Checklist de Setup

- [ ] Editei as informações básicas acima
- [ ] Configurei a arquitetura conforme meu projeto
- [ ] Defini os padrões de testes
- [ ] Configurei os padrões visuais (cores, ícones, navegação)
- [ ] Mapeei ícones para os módulos do meu projeto
- [ ] Adicionei regras específicas do projeto
- [ ] Configurei storage e uploads
- [ ] Criei factories para todos os Models
- [ ] Configurei scheduling e commands
- [ ] Defini regras de soft delete
- [ ] Configurei PHPStan (level 5+) e Pint
- [ ] Rodei `./setup.sh` para aplicar configurações
- [ ] Rodei `php artisan boost:update` para gerar CLAUDE.md
