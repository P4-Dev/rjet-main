# Arquitetura: Fase 8 — Dashboard e relatórios

> **Escopo:** RF035–RF037  
> **Fora de escopo:** não redesenhar `PaymentRequest`, workflow de alçadas (F4), importação em lote (F5), lote de anexos (F6), baixa e CNAB (F7). Só **hooks mínimos** e documentados (§2.4). Sem API REST. Sem arquivo de retorno CNAB, sem dashboards de SLA/aprovação e sem agendamento de relatórios por e-mail.  
> **Stack:** Laravel 12 (`composer.json`: `laravel/framework ^12.0`; o `PROJECT.md` diz "13", mas vale o lock) · Filament 5.7 · PHP 8.4 · Pest 4 · Livewire 4 · PostgreSQL (testes em SQLite `:memory:`) · Redis · OpenSpout 4.32 (transitivo)  
> **Fonte:** DRF v1.2 (§2.2 item 8, §2.3, §2.4, RF035–RF037, RNF001–RNF012, §5, §6) + `PROJECT.md` (regra de negócio *"Relatórios de exportação analítico com todos os dados, e os anexos vem na planilha com o link para abrir no navegador"*) + arquiteturas F1–F7 + código real do working tree  
> **Autor:** architect (subagent)  
> **Data:** 2026-09-27  
> **Status:** Revisão `dba` concluída em 2026-09-27 (**aprovado com ressalvas**, §23; correções já aplicadas nas seções afetadas). Pronto para `/blueprint`. Ainda **não** pronto para `implementer` (aguarda `.ai/blueprints/fase-8-dashboard-relatorios.md`).

---

## 1. Contexto

As Fases 1–7 fecharam o ciclo operacional. A solicitação nasce `Requested`, passa pela alçada (F4), vira `Launched` e é liquidada (`Settled`) somente pela baixa confirmada (F7). A F7 também deixou a baixa rastreável: data (`settlement_date`), conta pagadora e valor pago (snapshot `amount` no item).

A **Fase 8** entrega a visão gerencial em duas partes:

1. **Dashboard Filament (RF035 + RF036):** indicadores de total pago, pendentes, a vencer e vencidos, mais gráficos por filial e por centro de custo. Tudo responde a filtros globais de empresa, filial e período, e respeita a visibilidade por papel.
2. **Relatório analítico em Excel (RF037):** job assíncrono gera um `.xlsx` com todos os dados da solicitação e links para os anexos que abrem no navegador, protegidos por autenticação. O solicitante recebe notificação e baixa o arquivo pela aplicação.

| Capacidade já existente | Origem |
|-------------------------|--------|
| `PaymentRequestStatus` (`Requested → Launched → Settled`) | F3 |
| `PaymentRequest::scopeVisibleTo(User)` / `isVisibleTo(User)` (Cliente só vê as filiais vinculadas; Operador/Adm veem tudo via `UserRole::seesAllBranches()`) | F1/F3 |
| `PaymentRequest::scopeDueBetween`, `scopeForBranch`, `scopeStatus` | F3 |
| `payment_requests.cost_center_id` (NOT NULL, FK `cost_centers`, restrict) e `CostCenter.branch_id` (centro de custo **por filial**) | F2/F3 |
| `PaymentSettlement(status settled, settlement_date)` + `PaymentSettlementItem(amount, released_at)` | F7 |
| Morph `attachments` (`attachable_type = payment_request`), `Attachment::temporaryUrl()`, `displayName()`, `AttachmentPolicy::view` que delega para `PaymentRequestPolicy::view` | F3/F6 |
| Padrão de artefato gerado: status + job + `failed()` → `markFailed` + evento → notificação `mail` + `database` + download privado via Policy | F5/F7 |
| `OpenSpoutSpreadsheetReader` atrás do contrato `SpreadsheetReader` (bind no `AppServiceProvider`) | F5 |
| Grupo de navegação `reports` ("Relatórios") já traduzido em `lang/*/navigation.php` e ainda sem itens | F1 |

### 1.1 Estado real do código (2026-09-27)

| Artefato | Status |
|----------|--------|
| `App\Models\PaymentRequest` | **Existe.** `status` (enum), `net_amount`/`gross_amount`/`discount_amount` `decimal:2`, `due_date` (`date`, indexado), `branch_id`, `cost_center_id`, `appropriation_id`, `supplier_id`, `payment_method`, `notes`, `has_attachments`; relações `branch`, `supplier`, `costCenter`, `appropriation`, `bankDetails`, `attachments` (HasAttachments), `settlementItems`, `activeSettlementItem`, `creator` (HasBlameable). **Não há** coluna de data de emissão nem de "data de solicitação": a data de solicitação é `created_at` |
| `App\Models\PaymentSettlement` / `PaymentSettlementItem` | **Existem** (F7, working tree). Índice `(branch_id, settlement_date)` e `status` em `payment_settlements`. Unique parcial "um item ativo por PR". `PaymentSettlement::TIMEZONE = 'America/Sao_Paulo'` |
| `App\Models\CostCenter` | **Existe**: `branch_id`, `code`, `name`, `is_active`, SoftDeletes |
| `App\Models\Branch` / `Company` | **Existem**. `Branch::scopeVisibleTo(User)`; `Company` sem scope de visibilidade (a relação `branches` é hasMany) |
| `App\Models\Attachment` | **Existe**: `disk`, `path`, `original_name`, `standardized_name`, `mime_type`, `size`, `sort_order`, `type` (`AttachmentType`: `boleto`, `other`); `temporaryUrl(int $minutes = 30)`; SoftDeletes |
| `PaymentRequestObserver` | **Existe** (`#[ObservedBy]`): cascata de soft delete/restore/forceDelete de `bankDetails` e `attachments`. Sem hook de cache |
| `LogPaymentRequestActivity::handleStatusChanged` | **Existe**, com o comentário `// Hook for F8 dashboard cache invalidation.` Só loga |
| `PaymentRequestStatusChanged` | **Existe**, `ShouldDispatchAfterCommit` (F7). `PaymentRequestCreated` **não** é after-commit. A importação F5 cria PRs com `$dispatchEvent = false` e dispara um único `PaymentRequestBatchImported` |
| Eventos F7 | `PaymentSettlementCreated/Confirmed/Cancelled` só logam. A confirmação já dispara `PaymentRequestStatusChanged` para cada PR |
| Painel Filament | Único `admin`; `->pages([Filament\Pages\Dashboard::class])`; `->widgets([AccountWidget, FilamentInfoWidget])`; `discoverWidgets(app/Filament/Widgets)` e `discoverPages(app/Filament/Pages)`, mas as duas pastas **não existem**. Nenhum widget próprio |
| Filament Dashboard filters | **Disponível** no vendor: `Filament\Pages\Dashboard\Concerns\HasFiltersForm` (`filtersForm(Schema)`, filtros em `#[Url]` e na sessão) + `Filament\Widgets\Concerns\InteractsWithPageFilters` (`$this->pageFilters`) |
| `Filament\Widgets\ChartWidget` | **Disponível** (Chart.js empacotado pelo próprio Filament; `HasEmptyState`; `CanPoll` com **default `5s`**) |
| `Panel::authenticatedRoutes()` | **Disponível** (`vendor/filament/filament/src/Panel/Concerns/HasRoutes.php`); o login Filament usa `redirect()->intended()` |
| Pacote de chart ou planilha em `composer.json` | **Nenhum** direto. `openspout/openspout` v4.32 é transitivo de `filament/actions` (pendência herdada da F5). OpenSpout **não** tem hyperlink nativo; tem `FormulaCell`, `DateTimeCell` e `NumericCell` |
| `league/flysystem-aws-s3-v3` | **Ausente** do `composer.lock`. O disco `s3` não funciona hoje (pendência herdada, §19) |
| Jobs existentes | `ProcessImportBatchJob`, `RenameAttachmentBatchJob` (`tries=3`, `backoff=[10,30,60]`, `timeout=120`, `WithoutOverlapping(...)->dontRelease()`) e `GenerateCnabFileJob` (conexão dedicada, `releaseAfter(30)`) |
| Filas | Conexão default `redis`/`database`, `retry_after = 90`. Worker de produção `queue:work redis --tries=3` consome só `default`. Conexão `cnab_*` dedicada (F7) |
| Cache | `CACHE_STORE=redis` (`.env.example`), `array` nos testes |
| `app/Http/Controllers` | Só o `Controller.php` base. Não há rotas próprias além de `/` → login |
| `routes/console.php` | `approvals:escalate-sla` (15 min) e `cnab:recover-stuck-files` (5 min). Sem `model:prune`. Nenhum model usa `Prunable` hoje |
| Morph map | `supplier`, `payment_request`, `user`, `attachment_batch`. **A F8 não adiciona morph** |
| `.ai/rules/` | **Ausente**; convenções vêm de `PROJECT.md`, `.ai/docs` e F1–F7 |
| Filament Blueprint vendor | `filament/blueprint` está em `require-dev`, mas `vendor/filament/blueprint/resources/markdown/planning/` **não existe** nesta cópia. A estrutura segue `.ai/skills/filament/SKILL.md` (igual F6/F7) |

### 1.2 Preferências de projeto aplicadas

- Documento em **pt-BR**; classes, tabelas, colunas e chaves em **inglês**.
- `agrupar_por_dominio`: Jobs, Events, Actions, Listeners e Integrations agrupados (domínios `Report` e `Dashboard`); Services, DTOs, Models e Policies flat.
- Comentários no código: nível mínimo, em inglês.
- Timezone de negócio: `America/Sao_Paulo` (`config('app.timezone')`, já com esse default). "Hoje" = `today(config('app.timezone'))`.
- Traduções `pt_BR` + `en`, um arquivo por Resource/página.
- Todas as classes `final`; eventos no passado; decimal(10,2); status em string + Enum PHP.

---

## 2. Requisitos

### 2.1 Funcionais (Fase 8)

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| RF035 | Painel com total pago, pendentes, a vencer e vencidos; filtros por empresa, filial e período | `App\Filament\Pages\Dashboard` (`HasFiltersForm`) + `PaymentOverviewStats` + `DashboardMetricsService` |
| RF036 | Gráficos por filial e por centro de custo que respondem aos filtros globais | `PaymentsByBranchChart` + `PaymentsByCostCenterChart` (ChartWidget nativo) |
| RF037 | Job `GenerateAnalyticalReportJob` gera Excel analítico com links para anexos que respeitam autenticação | `AnalyticalReport` + `AnalyticalReportService` + `SpreadsheetWriter` (OpenSpout) + rota autenticada e assinada `report-attachments.open` |

**Critérios de aceite (DRF):**

RF035:
- [ ] Números batem com consultas de referência em base de teste (§3.1, testes #1–#6).
- [ ] Filtro por empresa, filial e período quando previsto (§3.2).

RF036:
- [ ] Gráficos respondem aos filtros globais do dashboard (§3.4).
- [ ] Performance adequada para ~70 pagamentos/dia (RNF011, §10.1).

RF037:
- [ ] Excel contém as colunas acordadas e abre em ferramenta padrão (§3.7, A5).
- [ ] Links de anexos respeitam autenticação: URL assinada **e** sessão autenticada **e** Policy (§3.8).

### 2.2 Não-funcionais aplicáveis

| RNF | Aplicação na F8 |
|-----|-----------------|
| RNF001 | `analytical_reports` com UUID, `timestampsTz`/`softDeletesTz`, `created_by`/`updated_by` (HasBlameable) |
| RNF002 | Somatórios vêm de colunas `decimal(10,2)`. Na borda do Service, normaliza para string com 2 casas. Float só na apresentação (Chart.js e célula numérica do Excel) |
| RNF003 | `analytical_reports.status` e `date_basis` em `string(20)` + Enum |
| RNF004 | Anexos continuam no morph existente. O relatório **lê** `attachments` e não cria anexo |
| RNF006 | Dashboard escopado por `visibleTo`. Relatório só para Operador/Adm. Link de anexo passa por Policy. URL assinada |
| RNF009 | `GenerateAnalyticalReportJob` em fila |
| RNF010 | Eventos `AnalyticalReportRequested/Generated/GenerationFailed/Downloaded`, `final`, payload = model |
| RNF011 | ~70 PRs/dia (~1,5 mil/mês, ~25 mil/ano). Agregação direta com cache curto; sem tabela materializada nem índice novo (§10.1) |
| RNF012 | Excel em disco private (`rjet.reports.disk`); anexos abertos por `temporaryUrl` de 5 min (S3) ou stream (local) |

### 2.3 API REST

> **Decisão herdada e fechada: NÃO expor API.** As Fases 1–7 são painel Filament interno e o DRF não pede consumo externo de indicadores nem de relatórios. Sem Sanctum, Passport ou Swagger. A única rota HTTP nova (§9.6) é interna ao painel, autenticada por sessão, e existe só porque o link dentro do Excel precisa de um GET fora do Livewire.

### 2.4 Fora de escopo e hooks mínimos

| Item | Tratamento |
|------|-----------|
| Indicadores de aprovação/SLA, fila de aprovadores | Fora do DRF F8. Os eventos F4 continuam disponíveis para uma fase futura |
| Relatório agendado ou enviado por e-mail com anexo | Fora. O arquivo **nunca** vai anexado ao e-mail (vazamento e tamanho); só o link para a página do relatório |
| Relatório para Cliente | Fora do MVP (A3). Policy e job já aplicam `visibleTo`, então liberar depois é mudança de Policy |
| Data de emissão do documento (citada no `PROJECT.md`) | Não existe no schema de `payment_requests`; fica fora do relatório |

**Hooks mínimos em código existente:**

| Arquivo | Mudança | Motivo |
|---------|---------|--------|
| `App\Listeners\PaymentRequest\LogPaymentRequestActivity` | Remover o comentário `// Hook for F8 dashboard cache invalidation.` | O hook passa a ser o listener dedicado `FlushDashboardMetricsCache` (§8) |
| `App\Observers\PaymentRequestObserver` | Novo `updated()`: se `wasChanged(['due_date', 'net_amount', 'cost_center_id', 'branch_id'])`, chama `DashboardMetricsService::flush()`. Acrescentar `flush()` em `deleted()`, `restored()` e novo `forceDeleted()` | Edições sem evento de domínio mudam os indicadores. O observer já existe e é o único ponto que vê essas mudanças |
| `App\Models\PaymentRequest` | Scopes `scopeOpen()` (`status IN (requested, launched)`) e `scopeForCompany(Company\|string)` (`branch_id IN (SELECT id FROM branches WHERE company_id = ?)`, com `withTrashed` nas filiais). **Colunas qualificadas** (`$query->qualifyColumn(...)`) nos dois novos e em `scopeForBranch` existente (`branch_id` → `payment_requests.branch_id`) (§23 #3) | Filtros reutilizados pelo dashboard e pelo relatório. A consulta de Pago faz join com `payment_settlements`, que também tem `branch_id`, `status` e `deleted_at`. Qualificar não muda o SQL gerado fora de joins |
| `App\Observers\CompanyObserver::forceDeleting` | Antes de apagar as filiais: `AnalyticalReport::withTrashed()` com `company_id = empresa` **ou** `branch_id IN filiais da empresa` → `get()->each->forceDelete()` (§23 #1) | FK `restrictOnDelete` (§4.1). O `AnalyticalReportObserver` apaga o `.xlsx` de cada um |
| Force delete isolado de filial (`EditBranch`/`BranchesRelationManager`, `ForceDeleteAction`) | Mesmo passo para `branch_id = filial`, no `->before()` da action ou num `BranchService::forceDelete()` (§23 #1) | Idem. Sem isso o restrict vira `QueryException` na UI |
| `App\Providers\Filament\AdminPanelProvider` | `->pages([App\Filament\Pages\Dashboard::class])` no lugar do Dashboard base; remover `FilamentInfoWidget`; `->authenticatedRoutes(...)` registrando a rota de abertura de anexo (§9.6) | Dashboard com filtros; rota protegida pelo `Authenticate` do Filament |
| `App\Providers\AppServiceProvider` | Registrar `AnalyticalReport => AnalyticalReportPolicy` em `POLICIES`; bind `SpreadsheetWriter → OpenSpoutXlsxSpreadsheetWriter` | Padrão F5/F7 |
| `config/rjet.php` / `.env.example` | Chaves `dashboard.*` e `reports.*` (§9.8) | Parametrização |
| `routes/console.php` | `Schedule::command('model:prune', ['--model' => [AnalyticalReport::class]])->dailyAt('02:00')->withoutOverlapping()` | Retenção do `.xlsx` (§3.10) |

**Nada muda** em `PaymentSettlement`, `PaymentSettlementService`, CNAB, alçadas, importação ou lote de anexos.

---

## 3. Decisões de Design

### 3.1 Definições fechadas dos indicadores (RF035)

**Premissas comuns:**
- **"Hoje"** = `today(config('app.timezone'))` (`America/Sao_Paulo`). `due_date` e `settlement_date` são colunas `date`, sem fuso, e são comparadas com strings `Y-m-d`.
- **Período** = `[period_start, period_end]`, datas inclusivas. Default: primeiro e último dia do mês corrente em São Paulo.
- **Soft delete:** PR, baixa e item liberado nunca entram. PR excluída por Adm é correção ("não deveria existir"), inclusive se já foi baixada.
- **Visibilidade:** toda consulta aplica o mesmo escopo de `PaymentRequest::scopeVisibleTo($user)`. Nas consultas com join, isso vira `payment_requests.branch_id IN (filiais do usuário)` quando `! $user->role->seesAllBranches()`.
- **Empresa/filial:** filtram por `payment_requests.branch_id` (empresa via filiais da empresa, §3.2).
- **"Em aberto"** = `status IN (requested, launched)`. Não há status de cancelamento no enum; toda PR não excluída acaba `Settled` ou continua em aberto.

| Indicador | Base | Status | Data de referência | Faixa | Valor | Contagem / descrição |
|-----------|------|--------|--------------------|-------|-------|----------------------|
| **Total pago** ("no mês" com o período default; "no período" caso contrário) | `payment_settlement_items` com `released_at IS NULL` ⨝ `payment_settlements` (`status = settled`, não excluída) ⨝ `payment_requests` (não excluída) | Implícito: toda PR em baixa `settled` está `Settled` | `payment_settlements.settlement_date` (data em que o banco pagou) | `period_start ≤ settlement_date ≤ period_end` | `SUM(payment_settlement_items.amount)` | `COUNT(itens)`; descrição "dd/mm – dd/mm · N pagamentos" |
| **Pendentes** | `payment_requests` não excluídas | `requested`, `launched` | `due_date` | `due_date ≤ period_end` (**sem limite inferior**) | `SUM(net_amount)` | `COUNT`; descrição "X solicitadas · Y lançadas" |
| **A vencer** | idem | `requested`, `launched` | `due_date` | `hoje ≤ due_date ≤ period_end` | `SUM(net_amount)` | `COUNT`. Com `period_end < hoje`, fica zero e a descrição diz "período encerrado" |
| **Vencidos** | idem | `requested`, `launched` | `due_date` | `due_date < hoje` **e** `due_date ≤ period_end` | `SUM(net_amount)` | `COUNT` |

**Invariante testável:** `Pendentes = A vencer + Vencidos`, em valor e em contagem, para qualquer período.

**Por que essas escolhas:**

1. **Pago usa `settlement_date` + `item.amount`, e não `PR.status = Settled` + `net_amount`.** O `PROJECT.md` define a data de baixa como "data pagamento banco". O `amount` é o snapshot do que foi efetivamente baixado (a confirmação F7 exige `net_amount == amount`). Um Adm pode editar o `net_amount` de uma PR já liquidada (`isEditableBy` libera Adm em qualquer status), e isso não pode mudar o "pago".
2. **A data de início do período só vale para Pago.** Pendentes, A vencer e Vencidos são uma **posição em "hoje"**. Limitar vencidos pelo início do período esconderia o backlog vencido de meses anteriores (com o default "mês corrente", o operador deixaria de ver o atraso do mês passado). O fim do período limita o horizonte do que precisa ser pago.
3. **Vence hoje = "a vencer"**, não vencido.
4. **Sem feriados e sem dias úteis** (P-FERIADOS continua adiada; vencimento é data de calendário).
5. **PRs `Settled` sem baixa** (só possíveis em dados anteriores à F7, porque o guard de `transitionStatus` agora exige baixa) **não entram em Pago**. Em produção esse conjunto é vazio. A7 registra isso.

**Cliente vs. Operador/Adm:** mesmos quatro indicadores e mesmas definições. O Cliente vê apenas PRs das filiais vinculadas, inclusive o valor pago das PRs dessas filiais (ele já vê a PR `Settled` na listagem). As opções dos selects de empresa e filial também são restritas (§3.2). Operador e Adm veem todas as empresas.

### 3.2 Filtros globais do dashboard

`App\Filament\Pages\Dashboard extends Filament\Pages\Dashboard`, com `HasFiltersForm`. Os filtros ficam em `$this->filters` (URL `#[Url]` + sessão, comportamento nativo) e chegam aos widgets por `InteractsWithPageFilters` (`$this->pageFilters`).

| Filtro | Componente | Opções | Default | Aplicação |
|--------|-----------|--------|---------|-----------|
| `company_id` | `Select` searchable, nullable | Operador/Adm: `Company::active()`. Cliente: empresas que têm filial vinculada ao usuário (`whereHas('branches', visibleTo)`) | vazio (todas) | `PaymentRequest::scopeForCompany` |
| `branch_id` | `Select` searchable, nullable, dependente de `company_id` (`live()`, reset ao trocar empresa) | `Branch::visibleTo($user)->when(company)` | vazio (todas) | `forBranch` |
| `period_start` | `DatePicker` (`native(false)`) | — | 1º dia do mês corrente (SP) | Só Pago (§3.1) |
| `period_end` | `DatePicker`, `minDate(period_start)` | — | último dia do mês corrente (SP) | Pago + limite dos indicadores em aberto |

**Sanitização (obrigatória, porque os filtros vêm da URL e da sessão):** `DashboardFilterData::fromPageFilters(array $filters, User $user, CarbonImmutable $today)`:
- datas inválidas ou ausentes → defaults; `start > end` → troca os valores (defensivo; a UI já impede);
- `company_id`/`branch_id` que não sejam UUID → ignorados;
- o escopo de visibilidade **nunca** vem do filtro: o Service sempre aplica `visibleTo($user)` **e** o filtro. Um Cliente que forja `branch_id` de outra filial recebe zeros, não dados;
- filial que não pertence à empresa escolhida → resultado vazio (combinação legítima, porém vazia).

**Herança pelos gráficos:** os três widgets leem o mesmo `DashboardFilterData` e chamam o mesmo `DashboardMetricsService`, que usa a mesma base de consulta. Os totais somados dos gráficos são iguais aos cards (invariante testável, §17).

**Centro de custo:** coluna real `payment_requests.cost_center_id` (NOT NULL). `CostCenter` pertence a uma filial (`cost_centers.branch_id`), então não existe "centro de custo global". O gráfico agrupa por `cost_center_id` com rótulo `code — name`. Sem filtro de filial, o rótulo acrescenta ` (filial)` para desambiguar nomes repetidos entre filiais. **Não há filtro global de centro de custo** (o DRF pede gráfico por CC, não filtro; A2).

### 3.3 Acesso por papel

| Recurso | Cliente | Operador | Adm |
|---------|---------|----------|-----|
| Dashboard (cards + gráficos) | Sim, só filiais vinculadas | Sim, tudo | Sim, tudo |
| Solicitar relatório analítico | **Não** (A3) | Sim | Sim |
| Listar/ver/baixar relatórios | Não | Sim (todos) | Sim (todos) |
| Retry de relatório | Não | Sim | Sim |
| Excluir relatório | Não | Não | Sim |
| Abrir link de anexo do Excel | Se `AttachmentPolicy::view` (PR visível) | Sim | Sim |

Operador e Adm veem todos os relatórios porque o conteúdo de qualquer relatório já é visível para eles (veem todas as filiais). Isso segue o padrão `ImportBatchPolicy`/`CnabFilePolicy`.

### 3.4 Gráficos (RF036)

| Widget | Tipo Chart.js | Eixo de categoria | Datasets | Limite de categorias |
|--------|---------------|-------------------|----------|----------------------|
| `PaymentsByBranchChart` | `bar` agrupado (vertical) | `payment_requests.branch_id` → `branches.name` (`withTrashed` para histórico) | Pago (período), A vencer, Vencidos, com as definições de §3.1 | `rjet.dashboard.chart_max_categories` (12). O excedente vira "Outros" |
| `PaymentsByCostCenterChart` | `bar` horizontal (`indexAxis: 'y'`) | `payment_requests.cost_center_id` → `code — name` (+ filial) | idem | idem, ordenado pelo total (pago + aberto) desc |

- Valores em R$ (float só no array do Chart.js). Tooltip formata BRL via `RawJs` em `getOptions()`. Cores do `PROJECT.md`: Pago `#10B981`, A vencer `#F59E0B`, Vencidos `#EF4444`.
- **Filtro que zera o gráfico:** se todas as categorias somam zero, `getData()` retorna `[]` e o `HasEmptyState` do ChartWidget exibe `__('dashboard.charts.empty')` (teste #10).
- **Sem pacote de chart novo.** O `ChartWidget` do Filament já empacota Chart.js. Nenhuma aprovação de dependência é necessária.

### 3.5 Cache e invalidação

O volume (~25 mil PRs/ano) permite agregação direta em milissegundos. Mesmo assim, o `.ai/docs/performance.md` §4–5 pede cache para agregados de dashboard e o F3 deixou o hook. Decisão: **cache simples com TTL curto e versão global**, sem tabela de agregação.

| Item | Decisão |
|------|---------|
| Store | Store default (`redis` em produção, `array` nos testes). **Sem tags**, para funcionar em qualquer store |
| Chave | `dashboard:v{version}:{scope}:{hash}`. `scope` = `all` para Operador/Adm; `branches:{md5(ids ordenados)}` para Cliente (mudança de vínculo gera chave nova). `hash` = md5 de `company_id`, `branch_id`, `period_start`, `period_end` e **a data de hoje** (a virada do dia muda vencidos e a vencer) |
| Granularidade | Uma entrada por bloco: `stats`, `by_branch`, `by_cost_center` |
| TTL | `rjet.dashboard.cache_ttl_seconds` = **300** (`0` desliga o cache) |
| Versão | `dashboard:version`, inteiro em `Cache::forever`. `DashboardMetricsService::flush()` faz `Cache::add(..., 1)` e depois `Cache::increment(...)`. As chaves antigas expiram pelo TTL |
| Momento do flush | `DB::afterCommit(fn () => increment)` dentro de `flush()`: roda na hora se não houver transação e evita recachear dado não commitado |
| Staleness máxima | Mudanças cobertas pelos gatilhos: imediata. Qualquer outra: 5 min (TTL) |

**Gatilhos de invalidação (lista fechada, só eventos que já existem):**

| Gatilho | Por que invalida | Mecanismo |
|---------|------------------|-----------|
| `PaymentRequestCreated` | Nova PR em aberto | Listener `FlushDashboardMetricsCache` (sync) |
| `PaymentRequestStatusChanged` (after-commit) | `Launched → Settled` altera Pago e os abertos; `Requested → Launched` altera a descrição de Pendentes | idem. É o hook comentado no F3 |
| `PaymentRequestBatchImported` | A importação F5 cria PRs sem `PaymentRequestCreated` | idem |
| Edição de `due_date`, `net_amount`, `cost_center_id` ou `branch_id`; soft delete, restore e forceDelete de PR | Não existe evento de domínio para essas mudanças | Hook no `PaymentRequestObserver` (§2.4) |

**Não invalidam (e não ganham listener):** `PaymentSettlementCreated` e `PaymentSettlementCancelled` (baixa `draft` não entra em nenhum indicador; a PR continua `Launched`). `PaymentSettlementConfirmed` também fica de fora, porque a confirmação já dispara `PaymentRequestStatusChanged` para cada PR depois do commit.

### 3.6 Registro de geração do relatório: tabela dedicada `analytical_reports`

O relatório precisa de estado assíncrono (fila → pronto ou falhou), de um dono para a notificação, de um arquivo privado para download autorizado, de retry e de retenção. Isso é um **artefato gerado com ciclo de vida próprio**, igual a `ImportBatch` e `CnabFile`.

| Opção | Avaliação |
|-------|-----------|
| Sem tabela (job grava arquivo e notifica com link) | Sem estado para falha recuperável, retry, auditoria de quem pediu e retenção; link de download teria de ser URL compartilhável. **Descartada** |
| Morph `attachments` para guardar o `.xlsx` | `attachments` é para documento de negócio do usuário, restrito a PDF e imagem (RNF012), e aparece nas telas da PR. Não tem status. **Descartada** |
| Filament Exports (`ExportAction` + `exports` + `job_batches`) | Ver §3.11. **Descartada** |
| **Tabela dedicada `analytical_reports` (escolhida)** | Entidade core da fase, FK para filtros (empresa/filial), status próprio e colunas de arquivo no padrão `import_batches`/`cnab_files` |

**Normalização:**
- Filtros como **colunas tipadas** (`company_id`, `branch_id`, `date_basis`, `period_start`, `period_end`) em vez de JSON: FKs reais, exibíveis e filtráveis.
- `statuses` (lista opcional de `PaymentRequestStatus`) em **JSON**. É um snapshot de filtro multivalorado, nunca consultado por SQL; uma tabela pivot seria desproporcional para um artefato de 30 dias (desnormalização intencional, comentada na migration).
- `rows_count` e `attachments_count` são métricas imutáveis do arquivo gerado (desnormalização intencional).
- `company_id` + `branch_id` juntos são **snapshot de filtro**, não dependência funcional: `company_id` sozinho é um filtro válido, e com filial preenchida a empresa é derivável dela. O Service rejeita filial fora da empresa escolhida (cai em `noMatchingRequests`) e a factory `forBranch()` preenche `company_id` com a empresa da filial, para nunca gravar combinação incoerente (§23 #8).
- `null` em `company_id`/`branch_id`/`statuses` significa "todos". Por isso a FK **não** pode ser `nullOnDelete` (§4.1, §23 #1), e `statuses` vazio é normalizado para `null` (uma só representação de "todos").

### 3.7 Excel: formato, biblioteca e colunas

**Biblioteca:** OpenSpout 4.32, que já está no lock e já é usado pelo reader da F5. O novo contrato `App\Integrations\Spreadsheet\SpreadsheetWriter` é implementado por `OpenSpoutXlsxSpreadsheetWriter` (streaming e memória constante). **Sem dependência nova.** A promoção de `openspout/openspout` a `require` direto continua a pendência herdada da F5 (§19); a F8 aumenta a dependência dela (reader + writer).

**Formato:** `.xlsx` com duas abas, cabeçalho em negrito e primeira linha congelada (se o `SheetView` do OpenSpout 4.32 permitir; senão, só negrito). Datas em `DateTimeCell` com formato `dd/mm/yyyy`; valores em `NumericCell` com formato `#,##0.00`. Links via `FormulaCell` `HYPERLINK("url","rótulo")` (sintaxe com vírgula, padrão do XML). Funciona em Excel, LibreOffice e Google Sheets.

**Escopo de linhas:** mesmos filtros e mesma visibilidade do solicitante (`visibleTo($report->creator)`, aplicado **no job**).

| `date_basis` (`ReportDateBasis`) | Coluna | Semântica |
|----------------------------------|--------|-----------|
| `due_date` (default) | `payment_requests.due_date` | Vencimento no período |
| `settlement_date` | `payment_settlements.settlement_date` (item ativo, baixa `settled`) | Pago no período (bate com o card "Total pago") |
| `request_date` | `payment_requests.created_at` entre início e fim do dia em São Paulo | Solicitado no período ("Data solicitação" = `created_at`, `PROJECT.md`) |

`statuses` opcional (null = todos). Ordem das linhas: `due_date` asc, `created_at` asc, `id`. Essa ordem **não** é compatível com `lazyById` (ver §9.5 passo 4 e §23 #2).

**Filtro por base de data (SQL):** `due_date` compara `date` com `Y-m-d`; `settlement_date` usa `whereHas('activeSettlementItem.settlement', status = settled AND settlement_date BETWEEN)`, **sem join** (join em `payment_requests` traz `id`, `status`, `branch_id` e `deleted_at` duplicados e quebra a hidratação e o chunking); `request_date` usa intervalo semiaberto `created_at >= início do dia SP (em UTC) AND created_at < início do dia seguinte SP`, nunca `BETWEEN … 23:59:59` (perde frações de segundo).

**Aba 1 — "Solicitações"** (uma linha por PR). **A5: colunas derivadas do schema real; o DRF só diz "colunas acordadas".**

| # | Coluna (pt_BR) | Origem | Tipo |
|---|----------------|--------|------|
| 1 | ID da solicitação | `payment_requests.id` | texto |
| 2 | Abrir solicitação | `HYPERLINK` → `PaymentRequestResource::getUrl('view')` (exige login) | link |
| 3 | Empresa | `branch.company.name` | texto |
| 4 | Filial | `branch.name` | texto |
| 5 | CNPJ da filial | `branch.document` | texto |
| 6 | Status | `status->getLabel()` | texto |
| 7 | Situação | `PaymentDueSituation` (Pago / A vencer / Vencido), relativa à data de geração em SP e com a regra de §3.1 | texto |
| 8 | Data da solicitação | `created_at` (SP) | data |
| 9 | Vencimento | `due_date` | data |
| 10 | Solicitante | `creator.name` | texto |
| 11 | Tipo de pessoa | `supplier.person_type` | texto |
| 12 | CPF/CNPJ do fornecedor | `supplier.document` | texto |
| 13 | Fornecedor | `supplier.name` | texto |
| 14 | Razão social do fornecedor | `supplier.legal_name` | texto |
| 15 | Centro de custo | `costCenter.code — name` | texto |
| 16 | Apropriação | `appropriation.code — name` (vazio se null) | texto |
| 17 | Forma de pagamento | `payment_method` | texto |
| 18 | Tipo de depósito | `bankDetails.deposit_type` | texto |
| 19 | Valor bruto | `gross_amount` | número |
| 20 | Descontos/deduções | `discount_amount` | número |
| 21 | Valor líquido | `net_amount` | número |
| 22 | Linha digitável / código de barras | `bankDetails.digitable_line` ?? `barcode` | texto |
| 23 | Chave PIX | `pix_key_type: pix_key` (ou "QR Code" se só `pix_qr_code`) | texto |
| 24 | Banco do favorecido | `bankDetails.bank.code — name` | texto |
| 25 | Agência | `agency` + `-agency_digit` | texto |
| 26 | Conta | `account_number-account_digit` (+ tipo) | texto |
| 27 | Titular | `holder_name` | texto |
| 28 | CPF/CNPJ do titular | `holder_document` | texto |
| 29 | Situação da baixa | `activeSettlementItem.settlement.status` (Rascunho / Confirmada / vazio) | texto |
| 30 | Data de baixa | `settlement.settlement_date` | data |
| 31 | Valor baixado | `activeSettlementItem.amount` | número |
| 32 | Conta pagadora | `branchBankAccount.bank_code · Ag agency · CC account-digit` | texto |
| 33 | Baixa confirmada em | `settlement.settled_at` (SP) | data/hora |
| 34 | Observações | `notes` | texto |
| 35 | Qtd. anexos | `attachments` não excluídos | número |
| 36…35+N | Anexo 1 … Anexo N | `HYPERLINK(rota assinada, displayName())`, ordem `sort_order` | link |

`N` = maior quantidade de anexos por PR no resultado, limitada a `rjet.reports.max_attachment_columns` (10, igual a `rjet.attachments.max_files`). Anexos acima de `N` continuam acessíveis pela aba 2.

**Aba 2 — "Anexos"** (uma linha por anexo, completa): ID da solicitação · Filial · Fornecedor · Vencimento · Tipo do anexo · Nome (`displayName()`) · Tipo MIME · Tamanho (KB) · Enviado em (SP) · Abrir (`HYPERLINK`).

**Regras de segurança da planilha:**
- Dados do usuário (notas, nomes) **sempre** em `StringCell`, que vira inline string e nunca é interpretada como fórmula. Não há injeção de fórmula.
- `FormulaCell` só para `HYPERLINK` montado pelo sistema. Aspas do rótulo são duplicadas e o rótulo é truncado em 255 caracteres.
- **Limite do Excel:** o argumento URL do `HYPERLINK` aceita no máximo 255 caracteres. A URL assinada relativa ao `APP_URL` tem cerca de 170 caracteres (`APP_URL` + `/admin/report-attachments/{uuid}?signature={64 hex}`). Se passar de 255 (APP_URL muito longo), a célula recebe a URL como texto e um `warning` vai para o log. Teste #20 garante ≤ 255 com o `APP_URL` de teste.

**Nome do arquivo:** `relatorio-analitico_{Ymd início}-{Ymd fim}_{YmdHi created_at SP}.xlsx` (prefixo traduzível `analytical_reports.filename_prefix`, só ASCII).

### 3.8 Links de anexos: rota autenticada e assinada, sem URL pré-assinada do S3

| Opção | Avaliação |
|-------|-----------|
| `temporaryUrl` do S3 gravada na célula | É token portador: quem recebe a planilha encaminhada abre sem login. Validade máxima de 7 dias (SigV4) e bem menor com credencial de role/STS. Planilha "morre" em horas ou dias. No disco local é uma rota `storage.local` com a mesma limitação. **Descartada** |
| Link para a página da PR no Filament | Exige login, mas não "abre o anexo no navegador" (regra do `PROJECT.md`). Fica como coluna 2, complementar |
| **Rota da aplicação por anexo: autenticada + assinada + Policy → redirect para `temporaryUrl` de 5 min (escolhida)** | Planilha encaminhada é inútil para quem não tem login e permissão. O link não expira enquanto o anexo existir e o usuário tiver acesso (a planilha continua útil meses depois). Atende o "URL assinada ou equivalente" do DRF |

**Especificação da rota:**
- Registro: `AdminPanelProvider->authenticatedRoutes(...)`. Path `/admin/report-attachments/{attachment}`, nome `filament.admin.report-attachments.open`. Middlewares do painel (`Authenticate` do Filament → guest vai para o login e volta via `intended`; usuário inativo barrado por `canAccessPanel`) + `signed:relative`.
- Assinatura **relativa** (`URL::signedRoute(..., absolute: false)`), prefixada com `config('app.url')` na geração. A validação não depende de host ou esquema atrás de proxy.
- **Sem expiração na assinatura.** Autenticação + Policy já controlam o acesso; a assinatura garante que o link foi emitido pelo sistema e impede montar URLs. Rotação de `APP_KEY` é suportada por `app.previous_keys` (`APP_PREVIOUS_KEYS`, já em `config/app.php`).
- Handler: `OpenReportAttachmentController` (invocável e fino) → `OpenReportAttachmentAction`:
  1. binding do `Attachment` (anexo excluído → 404; PR excluída → anexos já excluídos em cascata pelo observer → 404);
  2. `attachable_type !== 'payment_request'` → 404 (superfície mínima);
  3. `Gate::authorize('view', $attachment)` → `AttachmentPolicy::view` → `PaymentRequestPolicy::view` → `isVisibleTo` (403 caso contrário);
  4. `Attachment::temporaryUrl(rjet.reports.attachment_redirect_ttl_minutes = 5)` → `redirect()->away()`. S3 serve PDF e imagem inline pelo `Content-Type` gravado no upload. Se `null` (driver sem suporte) → `Storage::disk()->response($path, displayName())` inline;
  5. log `info` estruturado (`attachment_id`, `payment_request_id`, `user_id`, `ip`).
- Um Cliente que recebe a planilha de um Operador só abre anexos das próprias filiais. Esse efeito é desejado e testado.

### 3.9 Fila, job e idempotência

| Item | Decisão | Justificativa |
|------|---------|---------------|
| Conexão/fila | Conexão default, fila `config('rjet.reports.queue')` = `default` | Padrão F5/F6. O worker de produção já consome `default`. O `PROJECT.md` sugere `low` para relatórios, mas nenhum worker consome `low` hoje; mudar para `low` depende só de config + worker quando o Horizon chegar |
| `$timeout` | **75 s** | Tem de ficar abaixo de `retry_after = 90` da conexão default, para não repetir o problema que a F7 resolveu com conexão dedicada (reentrega durante a execução). Com `max_rows = 20 000` e streaming, a geração leva segundos |
| `$tries` / `$backoff` | `3` / `[10, 30, 60]` | Padrão F5/F6/F7 para falhas de infra (disco, S3) |
| Overlap | **Sem `WithoutOverlapping`** | `WithoutOverlapping` com `dontRelease` recria o bug da F7 (reentrega descartada sem `failed()` → relatório preso). A geração é **idempotente** (§9.5): execuções concorrentes produzem o mesmo arquivo no mesmo path, e só a que publica via update condicional dispara o evento |
| Falha determinística | `tooManyRows`, `requesterUnavailable`: `markFailed` + evento, **sem** lançar (não gasta retry) | Padrão F7 §9.4 passo 3 |
| Falha de infra | Lança exceção → retry → `failed()` → `markFailed(generation_interrupted)` | Padrão F7 |
| Relatório preso (fila perdeu o job) | Action **Retry** também aparece para `queued`/`generating` com `updated_at` mais antigo que `rjet.reports.stale_after_minutes` (30) | 30 min cobre o pior caso de 3 tentativas + backoff + `retry_after`. **Sem reconciliador agendado**: o impacto é baixo (o usuário vê o status e pede de novo), ao contrário do CNAB |
| Limite por usuário | Até `rjet.reports.max_in_progress_per_user` (3) relatórios `queued`/`generating` | Evita spam de jobs |
| Limite de linhas | `rjet.reports.max_rows` (20 000), checado **na solicitação** (síncrono, com mensagem) e de novo no job | Mantém o job abaixo do timeout; ~20 mil linhas ≈ 1 ano de operação |
| Resultado vazio | Bloqueado na solicitação (`noMatchingRequests`). Se os dados mudarem até o job rodar, gera o arquivo só com cabeçalho | Evita job inútil sem esconder uma mudança posterior |

### 3.10 Retenção

- O `.xlsx` fica disponível por `rjet.reports.retention_days` (**30**). `AnalyticalReport` usa `Prunable` (não `MassPrunable`, porque o delete em massa não dispara eventos e o arquivo ficaria órfão): `prunable()` = `withTrashed()->where('created_at', '<=', now()->subDays(retention))`. `model:prune` diário às 02:00, só para esse model.
- **O arquivo é apagado no `forceDeleted` do `AnalyticalReportObserver`** (padrão `ImportBatchObserver`), e não em `pruning()`. O `Prunable` chama `forceDelete()` em model com SoftDeletes, então o prune passa pelo observer; o mesmo vale para o `ForceDelete` do Adm (Policy libera) e para o force delete de empresa/filial (§2.4). O observer implementa `ShouldHandleEventsAfterCommit`: dentro de transação (wipe de empresa), o arquivo só some depois do commit. Path ou disk vazio → no-op; arquivo ausente → no-op (§23 #4).
- Os links de anexo **não** dependem do relatório (§3.8). Continuam válidos depois do prune enquanto o anexo existir e o usuário tiver acesso.
- Soft delete (Adm) só esconde o registro; o arquivo continua até o prune.

### 3.11 Alternativas descartadas

| Alternativa | Motivo |
|-------------|--------|
| **Filament Exports** (`ExportAction`/`Exporter`) | Exige tabelas `exports` + `job_batches` (migrations novas) e divide o trabalho em N jobs em lote. O download só é liberado para quem exportou e não passa por Policy de negócio. Não manda mail. Hyperlinks e duas abas ficam fora do modelo do Exporter. O DRF nomeia `GenerateAnalyticalReportJob` e `AnalyticalReportGenerated`, e o padrão F5/F7 (status + evento + notificação mail/database) é o do projeto |
| Tabela de agregação materializada (por dia/filial/CC) | ~70 PRs/dia não justificam. Criaria mais um ponto de consistência a manter. Reavaliar só no gatilho de §10.1 |
| Pacote de chart (ApexCharts etc.) | O ChartWidget nativo atende barras e empty state; não muda dependências |
| `temporaryUrl` do S3 na planilha | §3.8 |
| Relatório síncrono (download direto na Action) | O RNF009 exige fila; também bloquearia o request em relatórios anuais |

### 3.12 Assunções

| # | Assunção | Alternativa descartada / nota |
|---|----------|-------------------------------|
| A1 | Definições de §3.1: Pago por `settlement_date` + `item.amount`; abertos como posição em "hoje", limitados pelo fim do período e sem limite inferior | Filtrar abertos também pelo início do período esconderia o backlog vencido |
| A2 | Filtros globais = empresa, filial, período. Centro de custo é dimensão de gráfico, não filtro | O DRF pede "gráficos por CC", não filtro por CC |
| A3 | Relatório analítico só para Operador/Adm; o Cliente vê o dashboard escopado | O DRF (§2.3) restringe o Cliente a visualizar e criar solicitações. Como o job aplica `visibleTo`, liberar depois é mudança de Policy |
| A4 | Operador/Adm veem e baixam **todos** os relatórios, não só os próprios | O conteúdo de qualquer relatório já é visível para eles (padrão `ImportBatchPolicy`) |
| A5 | Colunas do Excel = §3.7 (todos os dados da PR, fornecedor, dados de pagamento, classificação, baixa e anexos), duas abas | "Colunas acordadas" não foram listadas no DRF. Derivadas do schema real e do "todos os dados" do `PROJECT.md`. Validar com a RJET (P-F8-COLS), sem bloquear o schema |
| A6 | Links de anexo: rota autenticada + assinada sem expiração + Policy → `temporaryUrl` de 5 min | URL pré-assinada na célula: token portador e curta duração (§3.8) |
| A7 | PRs `Settled` sem baixa (legado pré-F7) não entram em "Pago" | O guard F7 impede novos casos |
| A8 | Fila `default` + timeout 75 s, sem conexão dedicada | Uma fila dedicada como a da F7 só se justificaria com timeout > 90 s; o `max_rows` evita isso |
| A9 | Retenção do `.xlsx`: 30 dias | Parâmetro operacional (`RJET_REPORTS_RETENTION_DAYS`) |
| A10 | PRs soft-deleted ficam fora de todos os números, inclusive "Pago" | Exclusão é correção do Adm; incluir quebraria a consistência com as listagens |

---

## 4. Models

### 4.1 `App\Models\AnalyticalReport` (novo)

`php artisan make:model AnalyticalReport --migration --factory --no-interaction`

| Coluna | Tipo | Regras |
|--------|------|--------|
| `id` | `uuid` PK | HasUuid |
| `status` | `string(20)` default `queued`, index | `AnalyticalReportStatus` |
| `company_id` | `foreignUuid` nullable, index, FK `companies` **restrictOnDelete** | Filtro (null = todas) |
| `branch_id` | `foreignUuid` nullable, index, FK `branches` **restrictOnDelete** | Filtro (null = todas) |
| `date_basis` | `string(20)` default `due_date`, **sem índice** | `ReportDateBasis` (maior valor `settlement_date` = 15). Nunca filtrado por SQL |
| `period_start` | `date` | Inclusivo |
| `period_end` | `date` | Inclusivo, `>= period_start`. Validado no form (`after_or_equal`) e no Service (`invalidPeriod`). **Sem check constraint** (§23, decisão 2) |
| `statuses` | `json` nullable | Lista de valores de `PaymentRequestStatus`, únicos e ordenados; vazio → `null` (= todos). Desnormalização intencional (§3.6) |
| `disk` | `string(50)` NOT NULL | Snapshot de `rjet.reports.disk` na criação |
| `path` | `string(500)` nullable | `reports/{YYYY}/{MM}/{uuid}.xlsx` (YYYY/MM do `created_at`, estável entre retries). Nunca exibido |
| `filename` | `string(100)` nullable | Nome de download (§3.7), ~55 caracteres ASCII. Mesmo tamanho de `cnab_files.filename` (artefato gerado) |
| `size` | `unsignedInteger` nullable | Bytes |
| `rows_count` | `unsignedInteger` default 0 | Métrica do arquivo |
| `attachments_count` | `unsignedInteger` default 0 | Métrica do arquivo |
| `failure_reason` | `text` nullable | Mensagem segura (`getUserMessage()`) |
| `started_at` | `timestampTz` nullable | Primeira execução |
| `generated_at` | `timestampTz` nullable | Publicação |
| `created_by` / `updated_by` | `foreignUuid` nullable, index, FK `users` nullOnDelete | HasBlameable. `created_by` = solicitante notificado |
| `created_at` / `updated_at` / `deleted_at` | `timestampsTz` / `softDeletesTz` | — |

- **Índices:** `status` (convenção `database.md` §5, precedente `import_batches`/`cnab_files`) e as FKs (`->index()` explícito). **Sem** o composto `(created_by, created_at)`: a retenção de 30 dias limita a tabela a algumas centenas de linhas, abaixo do piso de 1 000 de `performance.md` §3, e o filtro "meus relatórios" já usa o índice de `created_by` (§23 #5). Nenhum índice em `date_basis`, `period_*`, `created_at` ou `generated_at`.
- **`restrictOnDelete` em empresa/filial (substitui o `nullOnDelete` original, §23 #1):** aqui `null` significa "todas". Com `nullOnDelete`, o hard delete de uma empresa transformaria um relatório filtrado num relatório "todas as empresas": a View mostraria o filtro errado e um Retry geraria outro escopo. Restrict segue o precedente dos artefatos gerados (`cnab_files`, `import_batches`). Os dois caminhos de force delete de cadastro removem antes os relatórios que os referenciam (§2.4). No uso normal empresa e filial sofrem soft delete e a FK nem é acionada.
- **Comentários de coluna (inglês, uma linha, estilo `payment_requests.net_amount`):** `statuses` ("Denormalized: filter snapshot, never queried by SQL; null = all statuses"); `company_id`/`branch_id` ("Filter snapshot; null = all"); `rows_count`/`attachments_count` ("Denormalized: generated file metrics, immutable after generated", igual a `cnab_files`); `disk` ("Snapshot of rjet.reports.disk at request time").
- **Traits:** `HasBlameable`, `HasFactory`, `HasUuid`, `SoftDeletes`, `Prunable`. Observer `App\Observers\AnalyticalReportObserver` (`#[ObservedBy]`, `forceDeleted` apaga o arquivo, `ShouldHandleEventsAfterCommit`, §3.10).
- **Casts:** `status` → `AnalyticalReportStatus`, `date_basis` → `ReportDateBasis`, `period_start`/`period_end` → `date`, `statuses` → `array`, `size`/`rows_count`/`attachments_count` → `integer`, `started_at`/`generated_at` → `datetime`.
- **Relações:** `company()` BelongsTo (`withTrashed`), `branch()` BelongsTo (`withTrashed`), `creator()` (HasBlameable).
- **Métodos:** `isDownloadable(): bool` (`generated` + `path` preenchido); `isStale(): bool` (`queued`/`generating` e `updated_at` mais antigo que `stale_after_minutes`); `isRetryable(): bool` (`failed` ou `isStale()`); `prunable(): Builder`. **Sem** `pruning()` (a limpeza do arquivo fica no observer).
- **Scopes:** `scopeInProgress()` (`queued`, `generating`), `scopeCreatedBy(User)`.

### 4.2 `App\Models\PaymentRequest` (existente, só scopes, §2.4)

`scopeOpen()` e `scopeForCompany(Company|string)`. Nenhuma coluna nova.

### 4.3 Sem outros models novos

Dashboard = widgets + consultas agregadas. Não há entidade de configuração de dashboard nem preferência de filtro persistida: a sessão do Filament já guarda os filtros.

---

## 5. Relacionamentos

```
AnalyticalReport ──belongsTo──> Company (nullable, withTrashed, FK restrictOnDelete)
AnalyticalReport ──belongsTo──> Branch  (nullable, withTrashed, FK restrictOnDelete)
AnalyticalReport ──belongsTo──> User    (created_by → creator, updated_by → editor; FK nullOnDelete)

Leitura (sem FK nova):
PaymentRequest ──belongsTo──> Branch ──belongsTo──> Company
PaymentRequest ──belongsTo──> CostCenter ──belongsTo──> Branch
PaymentRequest ──hasOne────> PaymentSettlementItem (active) ──belongsTo──> PaymentSettlement
PaymentRequest ──morphMany─> Attachment (via HasAttachments)
```

---

## 6. Enums (novos, `HasLabel`/`HasColor`/`HasIcon`, labels via `__('enums.*')`)

| Enum | Casos | Cor / ícone | Métodos |
|------|-------|-------------|---------|
| `AnalyticalReportStatus` | `Queued='queued'`, `Generating='generating'`, `Generated='generated'`, `Failed='failed'` | gray/`heroicon-o-clock`, info/`heroicon-o-arrow-path`, success/`heroicon-o-check-circle`, danger/`heroicon-o-x-circle` | `canTransitionTo()`: `queued → generating\|failed`; `generating → generated\|failed`; `failed → queued` (retry); `queued\|generating → queued` (retry de preso). `isInProgress()`, `isTerminal()` (`generated`), `inProgressValues(): list<string>` |
| `ReportDateBasis` | `DueDate='due_date'`, `SettlementDate='settlement_date'`, `RequestDate='request_date'` | gray / `heroicon-o-calendar`, `heroicon-o-banknotes`, `heroicon-o-inbox-arrow-down` | — |
| `PaymentDueSituation` | `Paid='paid'`, `Upcoming='upcoming'`, `Overdue='overdue'` | success, warning, danger | `static for(PaymentRequest, CarbonImmutable $today): self` (Settled → Paid; aberto com `due_date < today` → Overdue; senão Upcoming). Usado na coluna 7 do Excel e nos testes |

---

## 7. Exceções de domínio

`App\Exceptions\AnalyticalReportException extends BusinessException` (static factories, `userMessage` em `analytical_reports.errors.*`):

| Factory | Cenário |
|---------|---------|
| `unauthorized()` | Ator sem `create`/`download`/`retry` (belt da Action) |
| `invalidPeriod()` | Início ou fim ausente, ou `start > end` na solicitação |
| `noMatchingRequests()` | Contagem zero na solicitação |
| `tooManyRows(int $found, int $max)` | Acima de `max_rows` (na solicitação ou no job) |
| `tooManyInProgress(int $max)` | Usuário já tem `max` relatórios em andamento |
| `requesterUnavailable()` | `creator` nulo ou inativo no momento do job |
| `notDownloadable()` | Status ≠ `generated` |
| `fileMissing()` | Arquivo sumiu do disco (log `error`) |
| `notRetryable()` | Retry em `generated`, ou em andamento sem estar stale |
| `storageWriteFailed(Throwable)` | Upload do `.xlsx` falhou (infra → retry) |

O dashboard não lança exceção de domínio: filtro inválido é normalizado (§3.2). Na rota de anexo, 404/403 vêm do binding e do Gate (sem exceção de domínio).

---

## 8. Events, listeners e notificações

Domínios `Report` e `Dashboard`. Payload = model. Listeners com `handle*` tipado usam **auto-discovery** (o `AppServiceProvider` já avisa que registro explícito duplica). **Não** adicionar `Event::listen` para eles. Observação para o `implementer`: rodar `php artisan event:list` e confirmar que cada listener novo aparece uma vez só.

| Event | Quando | Listener(s) | Fila |
|-------|--------|-------------|------|
| `App\Events\Report\AnalyticalReportRequested` (`ShouldDispatchAfterCommit`) | Registro `queued` criado ou recolocado em `queued` por retry | `App\Listeners\Report\QueueAnalyticalReportGeneration` → `GenerateAnalyticalReportJob::dispatch($report)` (sync fino, padrão `QueueCnabFileGeneration`); `LogAnalyticalReportActivity` | Job em fila |
| `App\Events\Report\AnalyticalReportGenerated` (DRF §6) | Publicação `generating → generated` | `App\Listeners\Report\NotifyAnalyticalReportGenerated` (`ShouldQueue`) → `AnalyticalReportGeneratedNotification` ao `creator`; `LogAnalyticalReportActivity` | **Sim** (notify) |
| `App\Events\Report\AnalyticalReportGenerationFailed` | `markFailed` | `NotifyAnalyticalReportGenerationFailed` (`ShouldQueue`) → `AnalyticalReportGenerationFailedNotification`; `LogAnalyticalReportActivity` | Sim (notify) |
| `App\Events\Report\AnalyticalReportDownloaded` | Cada download autorizado | `LogAnalyticalReportActivity` (usuário, IP, `rows_count`) | Não |
| `PaymentRequestCreated`, `PaymentRequestStatusChanged`, `PaymentRequestBatchImported` (existentes) | §3.5 | `App\Listeners\Dashboard\FlushDashboardMetricsCache` (sync; métodos `handlePaymentRequestCreated`, `handlePaymentRequestStatusChanged`, `handlePaymentRequestBatchImported` → `DashboardMetricsService::flush()`) | Não (um `increment`) |

**Notificações** (`App\Notifications`, `ShouldQueue`, `via: ['mail', 'database']`, padrão `CnabFileGeneratedNotification`):
- `AnalyticalReportGeneratedNotification(AnalyticalReport)`: título `notifications.analytical_report_generated.title`; corpo com período (dd/mm/aaaa), base de data e `rows_count`; ação `notifications.view_analytical_report` → `AnalyticalReportResource::getUrl('view', …)`. **Nunca** link direto ao arquivo e **nunca** o arquivo anexado.
- `AnalyticalReportGenerationFailedNotification(AnalyticalReport)`: `failure_reason` seguro + mesma URL.

---

## 9. Camadas

### 9.1 DTOs (flat `App\DTOs`, `final readonly`)

| DTO | Campos | Uso |
|-----|--------|-----|
| `DashboardFilterData` | `companyId` (?string), `branchId` (?string), `periodStart` (CarbonImmutable), `periodEnd` (CarbonImmutable), `today` (CarbonImmutable); `fromPageFilters(array, User, CarbonImmutable $today)`; `cacheHash(): string`; `isDefaultMonth(): bool` (rótulo "no mês" vs "no período") | Entrada única dos widgets e do Service |
| `DashboardMetrics` | `paidAmount`, `paidCount`, `openAmount`, `openCount`, `requestedCount`, `launchedCount`, `upcomingAmount`, `upcomingCount`, `overdueAmount`, `overdueCount` (valores em string com 2 casas); `toArray()`/`fromArray()` para o cache | Cards |
| `DashboardSeries` | `labels` (list<string>), `paid`, `upcoming`, `overdue` (list<string> alinhadas); `isEmpty(): bool`; `toArray()`/`fromArray()` | Gráficos |
| `AnalyticalReportData` | `companyId`, `branchId`, `dateBasis` (ReportDateBasis), `periodStart`, `periodEnd`, `statuses` (?list<PaymentRequestStatus>); `fromArray()`, `fromModel(AnalyticalReport)`, `toArray()` | Solicitação e job |

### 9.2 Services (flat `App\Services`)

| Service | Responsabilidades (assinaturas em prosa) |
|---------|------------------------------------------|
| `DashboardMetricsService` | `stats(DashboardFilterData, User): DashboardMetrics`; `byBranch(DashboardFilterData, User): DashboardSeries`; `byCostCenter(DashboardFilterData, User): DashboardSeries`. Todos passam por `remember()` (§3.5). `flush(): void` (`DB::afterCommit` + increment da versão). Internos: `openBaseQuery(filters, user)` (Eloquent `PaymentRequest::open()` + `visibleTo` + filtros); `paidBaseQuery(filters, user)` (base `PaymentRequest::query()->visibleTo($user)` + filtros de empresa/filial, que já exclui `payment_requests.deleted_at` pelo escopo do SoftDeletes e reutiliza a mesma regra de visibilidade dos abertos; `join payment_settlement_items ON payment_settlement_items.payment_request_id = payment_requests.id AND payment_settlement_items.released_at IS NULL`; `join payment_settlements ON payment_settlements.id = payment_settlement_items.payment_settlement_id`; `where payment_settlements.status = 'settled'`, `whereNull('payment_settlements.deleted_at')`, faixa de `payment_settlements.settlement_date`; `select` só de agregados e da chave de agrupamento). **Toda coluna qualificada com a tabela**: `status`, `branch_id`, `deleted_at`, `created_at` e `id` existem em `payment_requests` e em `payment_settlements`, e coluna ambígua é erro no PostgreSQL (§23 #3). O unique parcial `payment_settlement_items_active_request_unique` garante no máximo um item ativo por PR, então o join não duplica valores. Agrupa por `branch_id`/`cost_center_id` com `SUM`/`COUNT`; resolve rótulos numa segunda consulta (`withTrashed`); corta em N + "Outros". **SQL portável** (PostgreSQL em produção, SQLite nos testes): nada de `date_trunc` ou função de data específica. Somas normalizadas para string com 2 casas na borda (PostgreSQL devolve numeric exato; SQLite devolve float, arredondado a 2 casas; ordem de grandeza < 10¹³ mantém exatidão de centavos) |
| `AnalyticalReportService` | `countMatching(AnalyticalReportData, User): int`; `request(AnalyticalReportData, User): AnalyticalReport` (asserts: `create` na Policy, período válido, limite em andamento, contagem entre 1 e `max_rows`; cria `queued` com `disk`; `AnalyticalReportRequested` após o commit); `generate(AnalyticalReport): void` (§9.5); `markFailed(AnalyticalReport, string $userReason): void` (update condicional se ainda não `generated`; evento); `retry(AnalyticalReport, User): AnalyticalReport` (`isRetryable()`, reseta para `queued` com **update condicional** `where id = ? and status = <status lido>` e `updated_at` lido, limpa `failure_reason`; zero linhas afetadas → `notRetryable()`, para dois cliques simultâneos não enfileirarem dois jobs; evento `Requested`); `download(AnalyticalReport, User): StreamedResponse` (asserts, `exists`, `Storage::download($path, $filename)`, evento `Downloaded`); `rowsQuery(AnalyticalReportData, User): Builder` (base única: `visibleTo` + filtros + `date_basis` via `where`/`whereHas`, **sem join** e **sem** `orderBy` nem eager load; reutilizada por contagem, snapshot de IDs e cálculo de `N`, §3.7 e §9.5). `request()` normaliza `statuses` (únicos, ordenados, vazio → `null`) antes de gravar |

**Regra:** Filament, Actions e widgets não falam com `Storage` nem com OpenSpout; tudo passa pelos Services e pela Integration.

### 9.3 Actions (`App\Actions\Report\`, classes finais invocáveis, padrão `App\Actions\Cnab\*`)

| Action | Uso |
|--------|-----|
| `RequestAnalyticalReportAction` | `can('create', AnalyticalReport::class)` senão `unauthorized()` → `AnalyticalReportService::request` |
| `RetryAnalyticalReportAction` | `can('retry', $report)` → `retry` |
| `DownloadAnalyticalReportAction` | `can('download', $report)` → `download` |
| `OpenReportAttachmentAction` | 404/403 (§3.8) → redirect para `temporaryUrl` ou stream inline; log |

### 9.4 Job: `App\Jobs\Report\GenerateAnalyticalReportJob`

- `implements ShouldQueue`; `use Queueable, SerializesModels`.
- `public int $tries = 3;` `public int $timeout = 75;` `public array $backoff = [10, 30, 60];`
- Construtor: `public AnalyticalReport $report`; `onQueue(config('rjet.reports.queue'))` (conexão default).
- **Sem** `middleware()` de overlap (§3.9).
- `handle(AnalyticalReportService $service)`: `$service->generate($this->report)`.
- `failed(?Throwable)`: recarrega; se não `generated`, `markFailed` com `getUserMessage()` (BusinessException) ou `analytical_reports.messages.generation_interrupted`; log `error` com `analytical_report_id`, `exception`, `exception_class`.

### 9.5 `AnalyticalReportService::generate()`, passo a passo

1. Recarrega. Se status ∉ {`queued`, `generating`} → **return** (idempotente).
2. Update condicional (`whereIn status queued|generating`) → `generating`, `started_at` (se null), `updated_at = now()`.
3. Resolve o `creator`. Nulo, inativo ou sem `create` na Policy → `markFailed(requesterUnavailable)` e **return**, sem lançar.
4. `AnalyticalReportData::fromModel()` → `rowsQuery(data, creator)`. **Snapshot ordenado de IDs:** `rowsQuery()->orderBy('payment_requests.due_date')->orderBy('payment_requests.created_at')->orderBy('payment_requests.id')->pluck('payment_requests.id')` (≤ 20 000 UUIDs, ~2–3 MB). A contagem é o tamanho da lista. Acima de `max_rows` → `markFailed(tooManyRows)` e **return**. **Não usar `lazyById`/`chunkById`:** eles mantêm os `ORDER BY` existentes antes de `id` e paginam por `id > último`, então com a ordem de §3.7 pulam linhas a partir da segunda página (§23 #2). O snapshot também fixa o conjunto de linhas: `rows_count`, aba 1 e aba 2 veem as mesmas PRs mesmo que alguém edite um vencimento durante a geração.
5. Calcula `N` (colunas de anexo, §3.7) numa consulta agregada portável: `MAX(cnt)` sobre `SELECT attachable_id, COUNT(*) AS cnt FROM attachments WHERE attachable_type = 'payment_request' AND deleted_at IS NULL AND attachable_id IN (<rowsQuery()->select('payment_requests.id')>) GROUP BY attachable_id` (`DB::query()->fromSub(...)`). Subquery, e não `whereIn` com a lista de IDs, para não esbarrar no limite de bindings do SQLite. Usa o índice `(attachable_type, attachable_id, type)`.
6. `SpreadsheetWriter` abre um arquivo temporário local (`tempnam`). **Aba 1:** para cada bloco de 500 IDs do snapshot (`$ids->chunk(500)`), `PaymentRequest::query()->whereKey($bloco)->with([...])->get()->keyBy('id')` e escreve na ordem do bloco. Eager load: `branch` e `branch.company`, `supplier`, `costCenter` e `costCenter.branch`, `appropriation`, `bankDetails.bank`, `creator`, `attachments` (a relação do `HasAttachments` já ordena por `sort_order`), `activeSettlementItem.settlement.branchBankAccount`. Relações de rótulo (`branch`, `company`, `supplier`, `costCenter`, `appropriation`, `bank`) carregadas com `withTrashed()` via closure no `with()`, porque as relações do `PaymentRequest` não têm `withTrashed` e um cadastro soft-deleted deixaria a coluna vazia (§23 #7). **Aba 2:** segunda passada pelos mesmos blocos, com eager load só de `branch`, `supplier` (ambos `withTrashed`) e `attachments`. URLs de anexo via `URL::signedRoute('filament.admin.report-attachments.open', …, absolute: false)` prefixadas com `config('app.url')`; link da PR via `PaymentRequestResource::getUrl('view', …)`. Datas convertidas para SP; locale `config('app.locale')`.
7. `Storage::disk($disk)->putFileAs(dir, tempFile, basename)`; falha → `storageWriteFailed` (lança → retry).
8. Transação: update **condicional** `where id = ? and status = generating` → `generated`, `path`, `filename`, `size`, `rows_count`, `attachments_count`, `generated_at`. Zero linhas afetadas (outra execução publicou, ou `markFailed` venceu) → **return sem evento** e sem apagar o arquivo (o path é o mesmo da publicação vencedora).
9. Após o commit: `AnalyticalReportGenerated`.
10. `finally`: remove o temporário local.

### 9.6 HTTP (única rota nova)

`App\Http\Controllers\OpenReportAttachmentController` (final, invocável, só delega para `OpenReportAttachmentAction`). Registrado via `AdminPanelProvider->authenticatedRoutes()` com `->middleware('signed:relative')->name('report-attachments.open')` → `filament.admin.report-attachments.open`. Sem Form Request (GET sem input além do binding).

### 9.7 Integrations (`App\Integrations\Spreadsheet\`)

| Classe | Papel |
|--------|-------|
| `SpreadsheetWriter` (interface, nova) | `open(string $absolutePath): void`; `addSheet(string $name, list<string> $headers): void`; `addRow(list<SpreadsheetCell\|scalar\|null>): void`; `close(): void`. Tipos de célula expostos sem vazar OpenSpout: texto, número (`#,##0.00`), data (`dd/mm/yyyy`), data/hora, hyperlink (url, rótulo) |
| `SpreadsheetCell` (value object `final readonly` na mesma pasta) | Fábricas `text()`, `money()`, `date()`, `dateTime()`, `link()`. `link()` aplica o limite de 255 e o fallback para texto (§3.7) |
| `OpenSpoutXlsxSpreadsheetWriter` (nova) | Implementação com `OpenSpout\Writer\XLSX\Writer`, `StringCell`/`NumericCell`/`DateTimeCell`/`FormulaCell` e estilos. **Bind** no `AppServiceProvider::register()` ao lado do reader |

### 9.8 Config (`config/rjet.php`, novas chaves)

| Chave | Env | Default |
|-------|-----|---------|
| `dashboard.cache_ttl_seconds` | `RJET_DASHBOARD_CACHE_TTL` | `300` |
| `dashboard.chart_max_categories` | — | `12` |
| `reports.disk` | `RJET_REPORTS_DISK` → `FILESYSTEM_DISK` | `local` |
| `reports.directory` | — | `reports` |
| `reports.queue` | `RJET_REPORTS_QUEUE` | `default` |
| `reports.max_rows` | `RJET_REPORTS_MAX_ROWS` | `20000` |
| `reports.max_in_progress_per_user` | — | `3` |
| `reports.max_attachment_columns` | — | `10` |
| `reports.retention_days` | `RJET_REPORTS_RETENTION_DAYS` | `30` |
| `reports.attachment_redirect_ttl_minutes` | — | `5` |
| `reports.stale_after_minutes` | — | `30` |

`.env.example`: `RJET_REPORTS_DISK=`, `RJET_REPORTS_QUEUE=default`, `RJET_REPORTS_MAX_ROWS=20000`, `RJET_REPORTS_RETENTION_DAYS=30`, `RJET_DASHBOARD_CACHE_TTL=300`. `phpunit.xml`: nada novo (`QUEUE_CONNECTION=sync` e `CACHE_STORE=array` já existem).

---

## 10. Performance, file storage e soft deletes

### 10.1 Performance

| Tema | Decisão |
|------|---------|
| Volume | ~1,5 mil PRs/mês; dezenas de baixas/mês. Agregações `SUM`/`COUNT` com `GROUP BY` em milissegundos |
| Cache | §3.5 (TTL 300 s, versão global, chave por escopo + filtros + dia) |
| Consultas por render | Cards: 1 agregada de abertos (`SUM`/`COUNT` condicionais por faixa: soma total, a vencer, vencidos, solicitadas, lançadas) + 1 de pagos. Cada gráfico: 2 agregadas + 1 de rótulos. Sem N+1 (nada de Eloquent em loop) |
| Widgets | `$isLazy = true` (default) nos três; `pollingInterval`: **stats `60s`, gráficos `null`** (o default `5s` do `CanPoll` refaria consultas à toa); `maxHeight('320px')` nos gráficos |
| Índices existentes usados | `payment_requests`: `status`, `due_date`, `branch_id`, `cost_center_id` (simples). `payment_settlements`: `status`, `(branch_id, settlement_date)`. `payment_settlement_items`: `payment_settlement_id`, `payment_request_id` |
| **Índices novos** | **Nenhum** em `payment_requests`, `payment_settlements` ou `payment_settlement_items` (confirmado pela `dba`, §23). A nota da F7 sobre `(status, settlement_date)` não se justifica: `payment_settlements` tem dezenas de linhas/mês e o composto `(branch_id, settlement_date)` + `status` bastam. `payment_requests` em aberto são uma fração pequena (a maioria liquida), então o índice `status` é seletivo. Sem índice em `payment_requests.created_at` para a base `request_date` (volume) |
| Gatilho para reavaliar | Alinhado ao gatilho da F7 §21 #9: `payment_requests` > **200 mil** linhas **ou** `EXPLAIN (ANALYZE, BUFFERS)` da agregação de abertos (sem cache, filtro "todas") > **50 ms** no PostgreSQL de produção. Candidato: índice parcial `payment_requests_open_due_date_index ON payment_requests (due_date) WHERE status IN ('requested','launched') AND deleted_at IS NULL`, com literais na migration (nunca interpolar o enum, F7 §21 #4) e `supportsPartialIndexes()`. Para Pago, só reavaliar `payment_settlements (status, settlement_date)` acima de ~50 mil baixas. p95 de página não é gatilho de índice (mistura render, rede e cache) |
| Relatório | Snapshot ordenado de IDs + blocos de 500 com `whereKey` + eager load + escrita em streaming (§9.5); **não** `lazyById` (incompatível com a ordem de §3.7); `max_rows` 20 000; timeout 75 s |
| Eager loading | Obrigatório no job (§9.5 passo 6). `Model::preventLazyLoading()` já ativo fora de produção pega regressões nos testes |

### 10.2 File storage

| Tema | Decisão |
|------|---------|
| Disco | `rjet.reports.disk` (private; local em dev, S3 em produção quando houver driver, §19) |
| Diretório | `reports/{YYYY}/{MM}/{report_uuid}.xlsx`. Path baseado em UUID, nunca exibido |
| Download do `.xlsx` | `Storage::download($path, $filename)` atrás de `AnalyticalReportPolicy::download`. **Sem `temporaryUrl`**, para não gerar link compartilhável da planilha completa (padrão CNAB F7) |
| Anexos | `temporaryUrl` de 5 min (S3) ou stream inline (local), só depois de auth + assinatura + Policy (§3.8) |
| Upload de usuário | Nenhum na F8 |

### 10.3 Soft deletes e ciclo de vida

| Entidade | Política |
|----------|----------|
| `AnalyticalReport` | SoftDeletes (Adm esconde) + `Prunable` após 30 dias. Todo `forceDelete` (prune, Adm, wipe de cadastro) passa pelo `AnalyticalReportObserver::forceDeleted`, que apaga o arquivo depois do commit |
| Leituras do dashboard/relatório | Excluem PR, baixa e anexo soft-deleted (A10). Rótulos de filial, CC, empresa, fornecedor, apropriação e banco usam `withTrashed` para não "sumir" com o histórico |
| Cascata manual | O relatório não tem filhos. No sentido inverso, as FKs `restrictOnDelete` para `companies`/`branches` exigem que o force delete de empresa (`CompanyObserver::forceDeleting`) e o de filial removam antes os relatórios que as referenciam (§2.4). Soft delete de empresa/filial não mexe nos relatórios |

---

## 11. Filament (formato Blueprint obrigatório)

> `vendor/filament/blueprint/resources/markdown/planning/overview.md` **não existe** nesta cópia (§1.1). A estrutura segue `.ai/skills/filament/SKILL.md`, como nas F6 e F7.

### 11.1 Página `Dashboard`

```
Page: App\Filament\Pages\Dashboard
  Command: criar a classe manualmente estendendo Filament\Pages\Dashboard (padrão Filament para dashboard custom);
           AdminPanelProvider: ->pages([App\Filament\Pages\Dashboard::class]) no lugar do base; remover FilamentInfoWidget de ->widgets()
  Location: app/Filament/Pages/Dashboard.php (final)
  Traits: Filament\Pages\Dashboard\Concerns\HasFiltersForm
  Navigation: sem grupo (topo), ícone default do Dashboard (home), título __('dashboard.title')
  Access: todo usuário ativo do painel (Cliente escopado nos widgets)
  Columns: getColumns() → ['md' => 2]
  FiltersForm (filtersForm(Schema $schema)):
    Section (sem heading, columns 4):
      Field: company_id
        Component: Filament\Forms\Components\Select
        Config: ->label(__('dashboard.filters.company')) ->options(empresas visíveis ao usuário, §3.2) ->searchable() ->native(false)
                ->live() ->afterStateUpdated(fn (Set $set) => $set('branch_id', null)) ->placeholder(__('dashboard.filters.all'))
      Field: branch_id
        Component: Filament\Forms\Components\Select
        Config: ->label(__('dashboard.filters.branch')) ->options(Branch::visibleTo(user)->when(company_id)) ->searchable() ->native(false)
                ->placeholder(__('dashboard.filters.all'))
      Field: period_start
        Component: Filament\Forms\Components\DatePicker
        Config: ->label(__('dashboard.filters.period_start')) ->native(false) ->displayFormat('d/m/Y') ->default(startOfMonth SP)
      Field: period_end
        Component: Filament\Forms\Components\DatePicker
        Config: ->label(__('dashboard.filters.period_end')) ->native(false) ->displayFormat('d/m/Y') ->minDate(get('period_start')) ->default(endOfMonth SP)
  HeaderActions: [RequestAnalyticalReportAction (Filament), visível só se can('create', AnalyticalReport::class);
                  pré-preenche company_id, branch_id, period_start, period_end com os filtros atuais; date_basis = due_date]
  Widgets (discovered em app/Filament/Widgets, ordem por $sort):
    AccountWidget (existente, sort -1)
    PaymentOverviewStats (sort 1, columnSpan 'full')
    PaymentsByBranchChart (sort 2, columnSpan 1)
    PaymentsByCostCenterChart (sort 3, columnSpan 1)
```

### 11.2 Widgets (`app/Filament/Widgets`, todos `final`)

```
Widget: App\Filament\Widgets\PaymentOverviewStats
  Command: php artisan make:filament-widget PaymentOverviewStats --stats-overview --panel=admin --no-interaction
  Base: Filament\Widgets\StatsOverviewWidget
  Traits: Filament\Widgets\Concerns\InteractsWithPageFilters
  Config: $sort = 1; pollingInterval '60s'; lazy (default); columnSpan 'full'
  Data: DashboardMetricsService::stats(DashboardFilterData::fromPageFilters($this->pageFilters, user, today SP), user)
  Stats (Filament\Widgets\StatsOverviewWidget\Stat):
    Stat: paid        → label __('dashboard.stats.paid_month'|'paid_period') · value Number::currency(BRL, pt_BR)
                        · description "dd/mm – dd/mm · :count pagamentos" · color success · icon Heroicon::OutlinedBanknotes
    Stat: pending     → label __('dashboard.stats.pending') · value BRL · description ":requested solicitadas · :launched lançadas"
                        · color warning · icon Heroicon::OutlinedClock
    Stat: upcoming    → label __('dashboard.stats.upcoming') · value BRL · description ":count até dd/mm" | "período encerrado"
                        · color info · icon Heroicon::OutlinedCalendarDays
    Stat: overdue     → label __('dashboard.stats.overdue') · value BRL · description ":count vencidos" · color danger
                        · icon Heroicon::OutlinedExclamationTriangle

Widget: App\Filament\Widgets\PaymentsByBranchChart
  Command: php artisan make:filament-widget PaymentsByBranchChart --chart --panel=admin --no-interaction
  Base: Filament\Widgets\ChartWidget
  Traits: InteractsWithPageFilters
  Config: $sort = 2; getType() 'bar'; pollingInterval null; maxHeight '320px'; heading __('dashboard.charts.by_branch');
          description = período; emptyStateHeading __('dashboard.charts.empty')
  Data: DashboardMetricsService::byBranch(...) → [] se isEmpty(); senão datasets Pago/A vencer/Vencidos + labels
  Options: RawJs com tooltip e eixo em BRL

Widget: App\Filament\Widgets\PaymentsByCostCenterChart
  Command: php artisan make:filament-widget PaymentsByCostCenterChart --chart --panel=admin --no-interaction
  Base/Traits/Config: iguais; $sort = 3; heading __('dashboard.charts.by_cost_center'); Options: indexAxis 'y'
  Data: DashboardMetricsService::byCostCenter(...)
```

### 11.3 Resource `AnalyticalReportResource`

```
Resource: AnalyticalReportResource
  Command: php artisan make:filament-resource AnalyticalReport --generate --soft-deletes --view --panel=admin --no-interaction
           (depois: remover as páginas Create/Edit geradas; criação só pela Action em modal)
  Location: App\Filament\Resources\AnalyticalReports\AnalyticalReportResource
  Structure:  (pasta PLURAL: AnalyticalReports/)
    - AnalyticalReportResource.php (final, LIMPO: só delegates; canViewAny via Policy; getEloquentQuery with(['company','branch','creator']))
    - Schemas/AnalyticalReportForm.php (final; components() reutilizado pela Action de solicitação)
    - Schemas/AnalyticalReportInfolist.php (final)
    - Tables/AnalyticalReportsTable.php (final)
    - Pages/ListAnalyticalReports.php (header: RequestAnalyticalReportAction), Pages/ViewAnalyticalReport.php (header: Download, Retry)
    - Actions/RequestAnalyticalReportAction.php, DownloadAnalyticalReportAction.php, RetryAnalyticalReportAction.php
  SoftDeletes: getRecordRouteBindingEloquentQuery() sem SoftDeletingScope (NÃO getEloquentQuery())
  Icon: Heroicon::OutlinedDocumentChartBar
  Navigation:
    Group: __('navigation.groups.reports')
    Sort: 10
  Form (AnalyticalReportForm::components(), usado no modal):
    Field: date_basis
      Component: Filament\Forms\Components\Select
      Validation: required
      Config: ->options(ReportDateBasis::class) ->default(ReportDateBasis::DueDate) ->native(false)
    Field: period_start
      Component: Filament\Forms\Components\DatePicker
      Validation: required
      Config: ->native(false) ->displayFormat('d/m/Y') ->default(startOfMonth SP)
    Field: period_end
      Component: Filament\Forms\Components\DatePicker
      Validation: required, after_or_equal:period_start
      Config: ->native(false) ->displayFormat('d/m/Y') ->default(endOfMonth SP)
    Field: company_id
      Component: Filament\Forms\Components\Select
      Validation: nullable, exists (empresa ativa)
      Config: ->options(Company::active()) ->searchable() ->live() ->afterStateUpdated(reset branch_id)
    Field: branch_id
      Component: Filament\Forms\Components\Select
      Validation: nullable
      Config: ->options(Branch::visibleTo(user)->when(company_id)) ->searchable()
    Field: statuses
      Component: Filament\Forms\Components\Select
      Validation: nullable, array, in:PaymentRequestStatus
      Config: ->multiple() ->options(PaymentRequestStatus::class) ->placeholder(__('analytical_reports.fields.all_statuses'))
  Infolist (AnalyticalReportInfolist):
    Section: __('analytical_reports.sections.filters')
      Entry: date_basis → Filament\Infolists\Components\TextEntry ->badge()
      Entry: period_start / period_end → TextEntry ->date('d/m/Y')
      Entry: company.name / branch.name → TextEntry ->placeholder(__('analytical_reports.fields.all'))
      Entry: statuses → TextEntry ->badge() ->placeholder(__('analytical_reports.fields.all_statuses'))
    Section: __('analytical_reports.sections.result')
      Entry: status → TextEntry ->badge()
      Entry: rows_count / attachments_count → TextEntry
      Entry: filename → TextEntry ->placeholder('—')
      Entry: generated_at → TextEntry ->dateTime('d/m/Y H:i')
      Entry: failure_reason → TextEntry ->visible(status failed) ->color('danger')
    Section: __('common.sections.audit')
      Entry: creator.name / created_at / started_at → TextEntry
  Table (AnalyticalReportsTable):
    Column: created_at → Filament\Tables\Columns\TextColumn ->dateTime('d/m/Y H:i') ->sortable()
    Column: creator.name → TextColumn ->searchable()
    Column: date_basis → TextColumn ->badge()
    Column: period → TextColumn (state "dd/mm/aaaa – dd/mm/aaaa")
    Column: company.name / branch.name → TextColumn ->placeholder(__('analytical_reports.fields.all')) ->toggleable()
    Column: status → TextColumn ->badge() ->sortable()
    Column: rows_count → TextColumn ->numeric()
    Column: generated_at → TextColumn ->dateTime('d/m/Y H:i') ->toggleable(isToggledHiddenByDefault: true)
    Filter: status → Filament\Tables\Filters\SelectFilter ->options(AnalyticalReportStatus::class)
    Filter: mine → Filament\Tables\Filters\Filter (toggle "Meus relatórios" → where created_by = user)
    Filter: TrashedFilter (visível só para Adm)
    DefaultSort: created_at desc
    Poll: ->poll('5s') só enquanto houver registro visível em queued/generating (closure)
  RelationManagers: nenhum (sem hasMany)
  RecordActions: [View, DownloadAnalyticalReportAction, RetryAnalyticalReportAction, Delete (Adm), Restore (Adm)]
  ToolbarActions: [BulkActionGroup → [DeleteBulk (Adm)]]
```

**Actions Filament:**

| Action | Visível quando | Efeito |
|--------|----------------|--------|
| `RequestAnalyticalReportAction` (Dashboard + List) | `can('create', AnalyticalReport::class)` | Modal com `AnalyticalReportForm::components()` → `App\Actions\Report\RequestAnalyticalReportAction`. `BusinessException` → `Notification::danger(getUserMessage())` + halt. Sucesso → toast "Relatório enfileirado; você será notificado" + link para a View |
| `DownloadAnalyticalReportAction` | `can('download', $record)` (status `generated`) | `abort_unless(can)` → stream do `.xlsx` |
| `RetryAnalyticalReportAction` | `can('retry', $record)` (`failed` ou stale) | `requiresConfirmation` → `retry` |

### 11.4 Policies

| Policy | viewAny / view | create | Custom | update | delete / restore / forceDelete |
|--------|----------------|--------|--------|--------|--------------------------------|
| `AnalyticalReportPolicy` | Operador, Adm | Operador, Adm | `download` = Operador/Adm + `isDownloadable()`; `retry` = Operador/Adm + `isRetryable()` | `false` | `delete`/`deleteAny`/`restore`/`restoreAny` = Adm; `forceDelete`/`forceDeleteAny` = Adm |

Cliente: `false` em tudo (A3). Registrar no array `POLICIES` do `AppServiceProvider`. `AttachmentPolicy` e `PaymentRequestPolicy`: **sem mudança** (reutilizadas pela rota de anexo).

---

## 12. Livewire custom

**Não é necessário.** Os filtros globais (`HasFiltersForm`), os cards (`StatsOverviewWidget`), os gráficos (`ChartWidget` com empty state) e a solicitação do relatório (Action com schema em modal) são Filament puro (`.ai/docs/livewire.md` §8, Regra #1). Um componente custom só se justificaria com drill-down interativo (clicar na barra e abrir a lista filtrada), e isso não está no DRF.

---

## 13. Fluxos

### 13.1 Dashboard

1. Usuário entra no painel e cai no Dashboard. Os filtros vêm da URL ou da sessão, ou usam o default "mês corrente".
2. Os widgets (lazy) montam `DashboardFilterData` (sanitizado) e chamam o `DashboardMetricsService`.
3. O Service busca a chave `dashboard:v{n}:{scope}:{hash}`. Se não houver, agrega (abertos + pagos) com o escopo de visibilidade e grava por 300 s.
4. Ao mudar um filtro, o Livewire re-renderiza os widgets, que usam a nova chave de cache.
5. Uma baixa confirmada dispara `PaymentRequestStatusChanged` por PR depois do commit; `FlushDashboardMetricsCache` incrementa a versão e o próximo render recalcula.

### 13.2 Relatório analítico

1. Operador clica em **"Gerar relatório analítico"** no Dashboard (pré-preenchido) ou na lista de relatórios e confirma período, base de data, empresa, filial e status.
2. `RequestAnalyticalReportAction` → Service valida Policy, período, limite em andamento e contagem (1..20 000) → cria `AnalyticalReport(queued)` → `AnalyticalReportRequested` (after-commit) → `QueueAnalyticalReportGeneration` → job na fila `default`.
3. Job (§9.5): `generating` → escreve as duas abas em streaming → upload privado → publicação condicional `generated` → `AnalyticalReportGenerated`.
4. `NotifyAnalyticalReportGenerated` (fila) → mail + sininho com link para a View do relatório.
5. Operador abre a View → **Baixar** (Policy; stream) → `AnalyticalReportDownloaded`.
6. No Excel, clica em "Anexo 1" → navegador abre `/admin/report-attachments/{id}?signature=…`. Se não estiver logado, faz login e volta (`intended`). Assinatura, Policy e redirect para `temporaryUrl` de 5 min → PDF ou imagem abre no navegador.

### 13.3 Alternativos

| Cenário | Comportamento |
|---------|---------------|
| Filtro sem dados (ex.: filial sem PR no período) | Cards zerados; gráficos em empty state |
| Cliente forja `branch_id` de outra filial na URL | Zeros (visibilidade sempre aplicada); nenhum dado vaza |
| Filial fora da empresa selecionada | Resultado vazio |
| `period_end < hoje` | "A vencer" = 0 com descrição "período encerrado"; vencidos até `period_end` |
| Cliente tenta solicitar relatório | Action oculta; chamada direta → `unauthorized()` |
| Período invertido ou vazio | Validação do form; belt `invalidPeriod()` |
| Nenhuma PR no filtro | `noMatchingRequests()`; nada é criado |
| Mais de 20 000 linhas | `tooManyRows()` com a quantidade encontrada; orientação para reduzir o período |
| Quarto relatório em andamento do mesmo usuário | `tooManyInProgress(3)` |
| Solicitante desativado antes do job | `failed` (`requester_unavailable`), sem retry; notificação não é enviada (usuário inativo) |
| Falha de disco/S3 | Retry 10/30/60 → `failed()` → `failed` + notificação; **Retry** na UI reenfileira o mesmo registro |
| Job duplicado ou reentregue | Idempotente; só uma publicação dispara evento |
| Job perdido pela fila | Após 30 min em `queued`/`generating`, **Retry** aparece |
| Download de relatório `failed`/`queued` | Action oculta; chamada direta → `notDownloadable()` |
| Arquivo apagado do disco (prune ou manual) | `fileMissing()` + log; usuário gera de novo |
| Link de anexo sem login | Login do Filament e retorno ao link |
| Link de anexo com assinatura adulterada | 403 (`signed`) |
| Link de anexo de PR não visível ao usuário | 403 (Policy) |
| Anexo ou PR excluídos depois da geração | 404 |
| Planilha aberta meses depois | Links funcionam enquanto anexo existir e usuário tiver acesso; o download do `.xlsx` expira em 30 dias (prune) |

---

## 14. Segurança

| Tema | Controle |
|------|----------|
| Autorização | `AnalyticalReportPolicy` + `abort_unless(can(...), 403)` nas Actions Filament + belt nas Actions de domínio; dashboard com `visibleTo` em todas as consultas |
| Entrada não confiável | Filtros do dashboard vêm de URL/sessão → `DashboardFilterData` sanitiza; o escopo nunca é derivado do filtro |
| Cache | Chave inclui o escopo (Cliente por conjunto de filiais). Sem vazamento entre Clientes; staff compartilha a visão "all", que é igual para todos os staff |
| Planilha | Disco private; download só via app autenticada (sem link compartilhável); nunca anexada a e-mail; retenção de 30 dias |
| Links de anexo | Sessão (`Authenticate` do Filament + `canAccessPanel`) + assinatura HMAC (`signed:relative`, rotação por `APP_PREVIOUS_KEYS`) + `AttachmentPolicy::view` + restrição a `payment_request` + `temporaryUrl` de 5 min. Path interno nunca exposto |
| Injeção de fórmula | Dados do usuário só em `StringCell`; `FormulaCell` exclusivo para `HYPERLINK` do sistema, com escape de aspas |
| Rastreabilidade | `created_by` do relatório; eventos Requested/Generated/Failed/Downloaded em log estruturado; log de cada abertura de anexo (usuário, IP) |
| Mensagens | `getUserMessage()` sem path nem stack; detalhes técnicos só em log |
| LGPD | O DRF dispensa mascaramento de fornecedor. Dados bancários do favorecido saem no Excel por decisão A5 ("todos os dados"), protegidos por Policy + disco private + retenção |

---

## 15. Factories e seeders

| Factory | Definition | States |
|---------|------------|--------|
| `AnalyticalReportFactory` (nova) | `status=queued`, `date_basis=due_date`, período = mês corrente, `disk = config('rjet.reports.disk')`, `created_by` = usuário Operador (`recycle`) | `queued()`, `generating()` (`started_at`), `generated()` (`path`, `filename`, `size`, `rows_count`, `generated_at`; o teste grava o conteúdo com `Storage::fake`), `failed(string $reason)`, `stale()` (`generating` + `updated_at` antigo), `forCompany(Company)`, `forBranch(Branch)` (preenche também `company_id` com a empresa da filial, §3.6), `basis(ReportDateBasis)`, `withStatuses(array)` (grava únicos e ordenados; vazio → `null`), `expired()` (`created_at` além da retenção). Soft delete via `trashed()` nativo da factory. `generated()` usa `path` no formato de §4.1 e o `disk` da definição |
| `PaymentRequestFactory` (existente) | — | Usar `launched()`, `settled()`, `forBranch()`. Novo state `dueOn(string $date)` como atalho de testes do dashboard (opcional) |
| `PaymentSettlementFactory` (existente, F7) | — | Usar `settled()` + `withItems()` + `dated()` para montar "pago no período" |
| `AttachmentFactory` (existente) | — | Usar para anexos de PR nos testes do Excel e da rota |

**Seeders:** nenhum obrigatório (relatórios são transacionais e transitórios). Opcional: estender o `DevelopmentSeeder` com PRs em aberto (vencidas e a vencer) em duas filiais e uma baixa `settled` no mês, para demonstração visual do dashboard (idempotente, só dev).

`php artisan make:model AnalyticalReport --migration --factory --no-interaction`.

---

## 16. i18n (`pt_BR` + `en`)

| Arquivo | Conteúdo |
|---------|----------|
| `lang/*/dashboard.php` (novo) | `title`, `filters.*` (company, branch, period_start, period_end, all), `stats.*` (paid_month, paid_period, pending, upcoming, overdue + descrições com `:count`, `:requested`, `:launched`, `:until`, `period_closed`), `charts.*` (by_branch, by_cost_center, paid, upcoming, overdue, others, empty) |
| `lang/*/analytical_reports.php` (novo) | `label`, `plural`, `navigation_label`, `fields.*`, `sections.*`, `actions.*` (request, download, retry), `messages.*` (queued, generation_interrupted, requester_unavailable), `errors.*` (§7), `sheet.requests`, `sheet.attachments`, `columns.*` (cabeçalhos de §3.7), `filename_prefix` |
| `lang/*/enums.php` | `analytical_report_status.*`, `report_date_basis.*`, `payment_due_situation.*` |
| `lang/*/notifications.php` | `view_analytical_report`, `analytical_report_generated.{title,body}`, `analytical_report_generation_failed.{title,body}` |

---

## 17. Testes previstos (Pest, feature; não implementar aqui)

Feature tests com SQLite em memória (`phpunit.xml`), `Storage::fake`, `Queue::fake`/`Event::fake`/`Notification::fake` conforme o caso, e `Carbon::setTestNow` em `America/Sao_Paulo`.

| # | Arquivo sugerido | Critério | Cenário |
|---|------------------|----------|---------|
| 1 | `DashboardMetricsServiceTest` | RF035 números | Fixture conhecida (PRs `requested`/`launched` vencidas, vencendo hoje e futuras; baixa `settled` no mês e no mês anterior; baixa `draft`; item liberado) → `stats()` igual aos valores calculados à mão (consulta de referência §3.1) |
| 2 | `DashboardMetricsServiceTest` | RF035 números | Invariante `pending = upcoming + overdue` (valor e contagem) em três períodos: mês corrente, período passado (`end < hoje`), período futuro |
| 3 | `DashboardMetricsServiceTest` | RF035 números | Pago usa `item.amount` e `settlement_date`: editar `net_amount` de PR `Settled` não muda Pago; baixa `draft` e item liberado não contam; PR soft-deleted sai de todos os indicadores |
| 4 | `DashboardMetricsServiceTest` | RF035 filtro | Empresa, filial e período filtram; filial fora da empresa → zeros; início do período não afeta vencidos (backlog do mês anterior aparece) |
| 5 | `DashboardMetricsServiceTest` | RF035 escopo | Cliente vê só as filiais vinculadas; `branch_id` forjado de outra filial → zeros; Operador/Adm veem tudo |
| 6 | `DashboardMetricsServiceTest` | RF035 timezone | `setTestNow` às 00:30 de São Paulo (03:30 UTC): "hoje" é a data de SP; PR que vence nesse dia conta como a vencer |
| 7 | `DashboardMetricsCacheTest` | RF035/RF036 | Segundo cálculo com os mesmos filtros não consulta o banco (contagem de queries); `PaymentRequestCreated`, `PaymentRequestStatusChanged`, `PaymentRequestBatchImported` e edição de `due_date`/`net_amount`/`cost_center_id`/`branch_id` pelo observer invalidam; `PaymentSettlementCancelled` não invalida; chave de Cliente ≠ chave de staff; flush dentro de transação só acontece após o commit |
| 8 | `DashboardMetricsServiceTest` | RF036 filtros | `byBranch()` e `byCostCenter()` somados = `stats()` (pago, a vencer, vencidos) com e sem filtros |
| 9 | `DashboardMetricsServiceTest` | RF036 | Corte em N categorias + "Outros" preserva o total; rótulo de CC inclui a filial sem filtro de filial; filial/CC soft-deleted mantém o rótulo |
| 10 | `DashboardWidgetsTest` (Livewire) | RF036 filtro que zera | Widget com `pageFilters` sem dados → `isEmpty()` e texto do empty state; com dados → datasets e labels esperados |
| 11 | `DashboardPageTest` | RF035/RF036 | Dashboard renderiza para Cliente, Operador e Adm; selects de empresa/filial do Cliente só listam as dele; Action de relatório visível só para Operador/Adm; filtros inválidos na URL não quebram a página (defaults) |
| 12 | `DashboardPerformanceTest` | RF036 performance | Com ~2 000 PRs (≈ 1 mês a 70/dia), render dos três widgets sem cache faz no máximo um número fixo de queries (sem N+1). Não testar tempo absoluto |
| 13 | `AnalyticalReportServiceTest` | RF037 | `request()`: cria `queued` + evento after-commit; nega Cliente; período inválido; zero linhas; acima de `max_rows`; quarto em andamento |
| 14 | `GenerateAnalyticalReportJobTest` | RF037 abre em ferramenta padrão | Sucesso: arquivo em `Storage::fake` no path esperado; `generated` com `rows_count`, `attachments_count`, `size`; lido de volta com o `SpreadsheetReader` existente: abas "Solicitações" e "Anexos", cabeçalhos de §3.7 na ordem e valores de uma linha conferidos (datas, valores, situação) |
| 15 | `GenerateAnalyticalReportJobTest` | RF037 colunas | Colunas "Anexo 1..N" com N = máximo do resultado (limite 10); aba "Anexos" lista todos; `date_basis` `settlement_date` só traz PRs pagas no período (bate com o card Pago); `request_date` respeita dia de SP |
| 16 | `GenerateAnalyticalReportJobTest` | RF037 escopo | Job aplica `visibleTo` do solicitante; solicitante inativo → `failed` sem retry |
| 17 | `GenerateAnalyticalReportJobTest` | RF037 job failed | Falha de storage → exceção → `failed()` marca `failed` com mensagem genérica e dispara `AnalyticalReportGenerationFailed`; Retry volta a `queued` e reenfileira; job em `generated` → no-op; duas execuções → um único `AnalyticalReportGenerated` |
| 18 | `GenerateAnalyticalReportJobTest` | RF037 | Job na fila `rjet.reports.queue`, `timeout 75 < 90`, `tries 3`, backoff `[10, 30, 60]`, sem middleware de overlap |
| 19 | `AnalyticalReportNotificationTest` | DRF §6 | `AnalyticalReportGenerated` → notificação `mail` + `database` ao `creator` com URL da View e sem anexo; listener `ShouldQueue` |
| 20 | `ReportAttachmentLinkTest` | RF037 links | URL gerada ≤ 255 caracteres e com `signature`; guest → redirect para o login do Filament; assinatura adulterada → 403; Cliente de outra filial → 403; Cliente da mesma filial → redirect; Operador → redirect para `temporaryUrl` (disco fake) ou stream inline; anexo/PR excluídos → 404; anexo de `attachment_batch` → 404 |
| 21 | `AnalyticalReportDownloadTest` | RF037 | Operador/Adm baixam (`assertDownload(filename)`); Cliente 403; `failed`/`queued` não baixáveis; arquivo ausente → `fileMissing`; evento `AnalyticalReportDownloaded` |
| 22 | `AnalyticalReportAuthorizationTest` | RNF006 | Matriz da Policy (§11.4) por papel e status |
| 23 | `AnalyticalReportResourceTest` | RF037 | Lista/View por papel; Action de solicitação cria registro; Retry visível para `failed` e `stale()`, oculta para `generating` recente |
| 24 | `AnalyticalReportPruneTest` | §3.10 | `model:prune` remove registro e arquivo além da retenção (inclusive soft-deleted); mantém os recentes; `forceDelete()` do Adm também apaga o arquivo; relatório sem `path` (falhou) é removido sem erro |
| 25 | `AnalyticalReportSchemaTest` | DBA | Colunas, defaults (`status = queued`, `date_basis = due_date`, contadores 0), FKs `restrictOnDelete` de empresa/filial (hard delete direto de filial referenciada falha), **ausência** do índice `(created_by, created_at)` |
| 27 | `GenerateAnalyticalReportJobTest` | DBA (§23 #2) | Com mais de 500 PRs (bloco de 500 + resto) e vencimentos fora da ordem de criação, a aba 1 tem todas as linhas, sem duplicata, na ordem `due_date, created_at, id`; `rows_count` = total |
| 28 | `DashboardMetricsServiceTest` / `CompanyForceDeleteTest` | DBA (§23 #1, #3) | Pago com filtro de filial roda no join sem coluna ambígua (SQLite também acusa ambiguidade em `branch_id`/`status`); force delete de empresa com relatório filtrado por ela remove o relatório e o arquivo, sem `QueryException` |
| 26 | `SpreadsheetWriterTest` | RF037 | Células texto começando com `=`, `+`, `-`, `@` continuam texto na leitura; `HYPERLINK` com aspas no rótulo é escapado; URL > 255 cai para texto |

---

## 18. Trade-offs

| Decisão | Custo | Benefício |
|---------|-------|-----------|
| Início do período só afeta "Pago" | Semântica de período diferente entre cards (explicada na descrição) | Backlog vencido nunca some; invariante Pendentes = A vencer + Vencidos |
| Pago por `item.amount` + `settlement_date` | Join com as tabelas da F7 | Valor efetivamente baixado; imune a edição posterior da PR; data real de pagamento |
| Cache com versão global (sem tags) | Qualquer mudança invalida todo o dashboard | Store-agnóstico; invalidação trivial e correta; volume baixo |
| Sem índice novo e sem tabela materializada | Revisitar se o volume crescer muito | Sem over-engineering; gatilho objetivo em §10.1 |
| Tabela `analytical_reports` dedicada | Uma migration e um Resource | Estado, retry, notificação, download autorizado e retenção no padrão F5/F7 |
| OpenSpout (transitivo) em vez de Filament Exports | Writer próprio para manter | Duas abas, hyperlinks, job/evento nomeados no DRF, sem `exports`/`job_batches` |
| Link de anexo via rota autenticada, sem `temporaryUrl` na célula | Exige login para abrir; um hop extra | Planilha encaminhada não vaza anexos; links não expiram enquanto o acesso existir |
| Assinatura sem expiração | Link não "morre" sozinho | O controle real é sessão + Policy; a planilha continua útil |
| Fila `default` com timeout 75 s | Relatório maior que 20 000 linhas é recusado | Nenhum worker ou conexão nova; sem o risco de reentrega da F7 |
| Sem reconciliador de relatório preso | Até 30 min para o Retry aparecer | Menos infraestrutura; impacto baixo (basta pedir de novo) |
| Relatório só para Operador/Adm | Cliente não exporta | Coerente com o DRF §2.3 e com A5 da F7; liberar é mudança de Policy |

---

## 19. Pendências (só o que código e DRF não fecham)

| ID | Tema | Bloqueia? | Nota |
|----|------|-----------|------|
| P-F8-COLS | Validar com a RJET a lista de colunas (A5) | Não | Ajuste de colunas não muda o schema; só `columns.*` e o writer |
| P-S3-DRIVER (herdada, transversal) | `league/flysystem-aws-s3-v3` ausente do lock: o disco `s3` não funciona em produção para anexos (F3/F6), CNAB (F7) e relatórios (F8) | **Sim para produção em S3** | Exige aprovação de dependência (`AGENTS.md`). Em dev/local não bloqueia. `temporaryUrl` depende disso em produção |
| P-F5-OPENSPOUT (herdada) | Promover `openspout/openspout` a `require` direto | Não (funciona via `filament/actions`) | A F8 passa a usar o writer; o risco de o Filament remover a dependência cresce. Aprovação pendente desde a F5 |
| Observação | `LogPaymentRequestActivity` tem `Event::listen` explícito com métodos `handle*` que o auto-discovery também encontra: possível log duplicado | Não (fora do escopo) | O `implementer` confere com `event:list`; o `FlushDashboardMetricsCache` depende só de discovery para não repetir o caso |

---

## 20. Schema consolidado (para a revisão `dba`)

**Uma migration nova:** `create_analytical_reports_table`.

Versão revisada pela `dba` (§23):

```
analytical_reports
  id                 uuid PK
  status             varchar(20) NOT NULL DEFAULT 'queued'         INDEX
  company_id         uuid NULL  FK companies(id) ON DELETE RESTRICT INDEX   -- Filter snapshot; null = all
  branch_id          uuid NULL  FK branches(id)  ON DELETE RESTRICT INDEX   -- Filter snapshot; null = all
  date_basis         varchar(20) NOT NULL DEFAULT 'due_date'       (sem índice)
  period_start       date NOT NULL
  period_end         date NOT NULL                                 (sem check; form + Service)
  statuses           json NULL                                     -- Denormalized: filter snapshot, never queried by SQL; null = all statuses
  disk               varchar(50) NOT NULL                          -- Snapshot of rjet.reports.disk at request time
  path               varchar(500) NULL
  filename           varchar(100) NULL
  size               integer NULL        (unsignedInteger; unsigned não é imposto no PostgreSQL)
  rows_count         integer NOT NULL DEFAULT 0                    -- Denormalized: generated file metrics, immutable after generated
  attachments_count  integer NOT NULL DEFAULT 0
  failure_reason     text NULL
  started_at         timestamptz NULL
  generated_at       timestamptz NULL
  created_by         uuid NULL FK users(id) ON DELETE SET NULL     INDEX
  updated_by         uuid NULL FK users(id) ON DELETE SET NULL     INDEX
  created_at, updated_at timestamptz
  deleted_at         timestamptz NULL
```

**Nenhuma alteração de schema** em tabelas existentes. **Nenhum índice novo** em `payment_requests`, `payment_settlements` ou `payment_settlement_items` (§10.1). Mudanças de código em tabelas existentes limitadas a §2.4 (scopes qualificados, limpeza de relatórios no force delete de empresa/filial).

Pontos que estavam abertos para a `dba` (respostas em §23, "Decisões DBA"):
1. `nullOnDelete` em `company_id`/`branch_id` → **trocado por `restrictOnDelete`** + limpeza no force delete de cadastro.
2. Check `period_end >= period_start` → **não criar**.
3. JSON em `statuses` → **confirmado** (`json`, não `jsonb`), com normalização.
4. Gatilho de índice futuro → **ajustado** (200 mil linhas ou `EXPLAIN ANALYZE` > 50 ms, alinhado à F7).

---

## 21. Checklist de implementação (pós-aprovação)

- [ ] Enums `AnalyticalReportStatus`, `ReportDateBasis`, `PaymentDueSituation` + `lang/*/enums.php`
- [ ] Migration `analytical_reports` conforme §20 revisado (restrict, `filename` 100, sem composto, comentários de coluna) + model + factory + `AnalyticalReportObserver`
- [ ] `config/rjet.php` (`dashboard.*`, `reports.*`) + `.env.example`
- [ ] Scopes `PaymentRequest::scopeOpen`/`scopeForCompany` (qualificados) + qualificar `scopeForBranch`
- [ ] Limpeza de relatórios no force delete de empresa (`CompanyObserver::forceDeleting`) e de filial
- [ ] DTOs (§9.1)
- [ ] `AnalyticalReportException`
- [ ] `SpreadsheetWriter` + `SpreadsheetCell` + `OpenSpoutXlsxSpreadsheetWriter` + bind
- [ ] `DashboardMetricsService` + `AnalyticalReportService`
- [ ] Actions `App\Actions\Report\*`
- [ ] Events `App\Events\Report\*`, listeners `Report\*` e `Dashboard\FlushDashboardMetricsCache`, notificações
- [ ] Hook no `PaymentRequestObserver`; remover o comentário do `LogPaymentRequestActivity`
- [ ] Job `GenerateAnalyticalReportJob`
- [ ] `AnalyticalReportPolicy` + registro
- [ ] `OpenReportAttachmentController` + `authenticatedRoutes` no painel
- [ ] Página `Dashboard` + três widgets; ajustar o `AdminPanelProvider`
- [ ] `AnalyticalReportResource` (List/View, Schemas, Table, Actions)
- [ ] `model:prune` agendado em `routes/console.php`
- [ ] Traduções `pt_BR` + `en` (§16)
- [ ] Testes §17
- [ ] `vendor/bin/pint --dirty --format agent`

---

## 22. Próximos passos / Handoff

1. **`dba`**: ✅ concluída em 2026-09-27 (aprovado com ressalvas, §23). Correções aplicadas em §2.4, §3.6, §3.7, §3.10, §4.1, §5, §9.2, §9.5, §10.1, §10.3, §15, §17, §20 e §21.
2. **`/blueprint`**: plano Filament detalhado a partir de §11 (página `Dashboard` com `HasFiltersForm`, `PaymentOverviewStats`, `PaymentsByBranchChart`, `PaymentsByCostCenterChart`, `AnalyticalReportResource` e suas Actions, rota `report-attachments.open`), gravado em `.ai/blueprints/fase-8-dashboard-relatorios.md`.
3. **`implementer`**, nesta ordem: enums + traduções → migration → model/factory → config → scopes → DTOs → exceção → Integration (writer) → Services → Actions → Events/Listeners/Notifications → hook no observer → Job → Policy → controller + rota → Filament (Dashboard + widgets + Resource) → schedule do prune → testes §17 → Pint.
4. **Aprovações de dependência (fora da F8, mas bloqueiam produção em S3):** P-S3-DRIVER e P-F5-OPENSPOUT (§19).
5. **API REST: não** (decisão herdada, §2.3).

Referências: `.ai/docs/queues.md` (job), `.ai/docs/notifications.md` (mail + database), `.ai/docs/file-storage.md` (disco private e download), `.ai/docs/scheduling.md` (`model:prune`), `.ai/checklists.md` (checklists de Model, Job, Policy, Resource e Widget).

---

## 23. Revisão DBA

> **Revisor:** dba (subagent) · **Data:** 2026-09-27 · **Driver:** PostgreSQL (testes em SQLite `:memory:`)  
> **Escopo:** schema novo `analytical_reports` (§4.1, §20); FKs e `onDelete`; SoftDeletes + `Prunable` + `model:prune`; consultas do dashboard (§3.1, §9.2, §10.1: joins, soft delete, `SUM`/`COUNT`, cache); iteração e eager load do job (§9.5); decisão de nenhum índice novo em `payment_requests`, `payment_settlements`, `payment_settlement_items`.  
> Guidelines: `database.md`, `enums.md`, `performance.md`, `soft-deletes.md`, `factories-seeders.md`, `PROJECT.md`. Formato de saída igual à F7 §21.  
> Inspeção em migrations e código reais (sem MCP de schema nesta sessão): `import_batches` (status indexado, `disk` 50 NOT NULL, `path` 500, composto `(created_by, created_at)`, restrict na FK de template), `cnab_files` (restrict nas FKs de origem, `filename` 100, `json` para snapshot, comentários "Denormalized: …", `supportsPartialIndexes()`), `payment_requests` (índices simples `status`, `due_date`, `branch_id`, `cost_center_id`; `branch_id` cascade), `payment_settlements` (`status`, `(branch_id, settlement_date)`), `payment_settlement_items` (unique parcial de item ativo), `attachments` (índice `(attachable_type, attachable_id, type)`, `sort_order`), `branches.company_id` cascade, `CompanyObserver::forceDeleting` (wipe ordenado), `BranchService::ensureDeletable`, `ImportBatchObserver::forceDeleted` (apaga arquivo), `HasAttachments` (já ordena por `sort_order`), `PaymentRequest::scopeForBranch` (coluna não qualificada), `BuildsQueries::orderedLazyById` + `Query\Builder::forPageAfterId` do vendor. Repo: zero `->check()`, zero `->after()`, zero `jsonb`.

### Veredito

**Aprovado com ressalvas.** A tabela `analytical_reports` está em 3NF com desnormalizações documentadas (snapshot de filtro, métricas do arquivo, `disk`), segue o padrão de artefato gerado do repo (status string + cast, UUID, `timestampsTz`/`softDeletesTz`, blameable `nullOnDelete`, disco/path/filename/failure_reason) e não mexe em schema existente. "Nenhum índice novo" nas tabelas da F3/F7 está correto para ~70 PRs/dia. Havia um erro crítico no job: `lazyById` com a ordenação por vencimento pula linhas. Também havia um `onDelete` que corrompia o significado do filtro. As duas correções, junto com as demais, já estão aplicadas nas seções do documento. Nada exige redesenho. Pronto para `/blueprint`.

### O que está correto

| Item | Avaliação |
|------|-----------|
| Tabela dedicada para artefato com ciclo de vida (sem morph, sem Filament Exports) | ✅ |
| Filtros como colunas tipadas com FK; `statuses` multivalorado em `json` | ✅ |
| `status` e `date_basis` em `string(20)`; zero `$table->enum()` | ✅ |
| Tamanhos medidos: `generating` 10, `generated` 9, `queued`/`failed` 6; `settlement_date` 15, `request_date` 12, `due_date` 8 | ✅ cabem em 20 |
| `status` indexado (convenção + precedente); `date_basis` sem índice (nunca filtrado por SQL) | ✅ |
| `disk` 50 NOT NULL (snapshot), `path` 500, `failure_reason` text | ✅ (igual a `import_batches`/`cnab_files`) |
| `period_*` como `date` (calendário, como `due_date`/`settlement_date`); `started_at`/`generated_at` `timestampTz` | ✅ |
| `rows_count`/`attachments_count` desnormalizados e imutáveis (precedente `cnab_files.records_count`) | ✅ |
| Blameable `nullOnDelete` + `creator` nulo → `requesterUnavailable` | ✅ |
| SoftDeletes (Adm esconde) + `Prunable` (não `MassPrunable`) + `model:prune --model` diário | ✅ (limpeza do arquivo movida, #4) |
| Pago via `payment_settlement_items.amount` + `settlement_date`, sem dupla contagem (unique parcial de item ativo) | ✅ |
| Abertos em uma agregação com `SUM`/`COUNT` condicionais; rótulos numa segunda consulta; sem N+1 | ✅ |
| SQL portável (sem `date_trunc`); soma normalizada para string com 2 casas | ✅ |
| Cache com versão global e chave por escopo, sem tags | ✅ (nada a exigir do banco) |
| Nenhum índice novo em `payment_requests`/`payment_settlements`/`payment_settlement_items` | ✅ |
| `json` e não `jsonb` (precedente; sem consulta por chave) | ✅ |

### Achados (Crítico / Importante / Sugestão)

| # | Nível | Achado | Impacto | Correção aplicada |
|---|-------|--------|---------|-------------------|
| 1 | **Crítico** | `nullOnDelete` em `company_id`/`branch_id`, sendo que `null` significa "todas". O repo tem `ForceDeleteAction` em Empresa e Filial, e `CompanyObserver::forceDeleting` faz o wipe completo. | Após o hard delete, o relatório filtrado vira "todas as empresas": a View mostra o filtro errado e o Retry gera outro escopo. O artefato continua no disco com dados do cadastro apagado. | §4.1/§5/§20: **`restrictOnDelete`** (precedente `cnab_files`/`import_batches`). §2.4/§10.3: `CompanyObserver::forceDeleting` e o force delete de filial removem antes os relatórios que os referenciam (Eloquent, `each->forceDelete()`). §17 #25/#28. |
| 2 | **Crítico** | Job com `rowsQuery()->lazyById(500)` e ordem `due_date, created_at, id` (§3.7). `forPageAfterId` só remove o `ORDER BY` de `id`, mantém os outros na frente e pagina por `id > último`. | A partir da segunda página, linhas são **puladas** sem erro: planilha incompleta com `rows_count` certo. Com join (base `settlement_date`), `id` ainda fica ambíguo ou sobrescrito. | §3.7/§9.2/§9.5/§10.1: **snapshot ordenado de IDs** (`pluck`) + blocos de 500 com `whereKey` + eager load; `rowsQuery` sem join (`whereHas`) e sem `orderBy`. §17 #27. |
| 3 | Importante | A consulta de Pago faz join de `payment_requests` com `payment_settlements`, e as duas têm `status`, `branch_id`, `deleted_at`, `created_at` e `id`. `scopeForBranch` existente usa `branch_id` sem qualificar. | `column reference is ambiguous` no PostgreSQL, ou filtro aplicado na tabela errada. | §2.4: scopes qualificados (`qualifyColumn`), incluindo `scopeForBranch`. §9.2: base `PaymentRequest::query()->visibleTo()`, condições do item no `ON`, toda coluna qualificada. §17 #28. |
| 4 | Importante | O arquivo era apagado em `pruning()`, que só roda no `model:prune`. A Policy libera `forceDelete` para o Adm, e o wipe de cadastro também faz force delete. | `.xlsx` com dados bancários órfão no disco privado, sem registro que permita encontrá-lo. | §3.10/§4.1/§10.3: `AnalyticalReportObserver::forceDeleted` (padrão `ImportBatchObserver`), `ShouldHandleEventsAfterCommit`; sem `pruning()`. §17 #24. |
| 5 | Sugestão | Composto `(created_by, created_at)`, copiado de `import_batches`. Aqui a tabela é podada a cada 30 dias (algumas centenas de linhas), e `created_by` já tem índice próprio. | Over-indexing (`performance.md` §3: < 1 000 linhas). | §4.1/§20: removido. `status` e FKs mantidos por convenção. §17 #25. |
| 6 | Sugestão | `filename` `string(255)` para um nome gerado de ~55 caracteres ASCII. | Inconsistente com `cnab_files.filename` (100). | §4.1/§20: `string(100)`. |
| 7 | Sugestão | Relações de rótulo do `PaymentRequest` (`branch`, `supplier`, `costCenter`, `appropriation`) não usam `withTrashed`. | Cadastro soft-deleted deixaria colunas vazias no Excel (o doc só previa `withTrashed` nos gráficos). | §9.5 passo 6/§10.3: `withTrashed()` por closure no `with()` do job. |
| 8 | Sugestão | `company_id` + `branch_id` juntos: a empresa é derivável da filial. `statuses` vazio e `null` seriam duas formas de dizer "todos". | Combinação incoerente gravada; comparação e exibição ambíguas. | §3.6: registrado como snapshot de filtro; Service rejeita filial fora da empresa; `statuses` normalizado (únicos, ordenados, vazio → `null`); factory `forBranch()` preenche a empresa. §15. |
| 9 | Sugestão | Base `request_date` descrita como "entre início e fim do dia". | `BETWEEN … 23:59:59` perde registros com fração de segundo. | §3.7: intervalo semiaberto `>= início` e `< início do dia seguinte` (SP → UTC). |
| 10 | Sugestão | Retry sem update condicional. | Dois cliques simultâneos enfileiram dois jobs (inofensivo pela idempotência, mas gera evento e log duplicados). | §9.2: update condicional pelo status/`updated_at` lido; zero linhas → `notRetryable()`. |
| 11 | Sugestão | Gatilho de índice futuro (500 mil linhas ou p95 > 500 ms) diferente do gatilho da F7 e baseado em métrica de página. | Critérios divergentes entre fases; p95 de página não isola o custo da consulta. | §10.1: 200 mil linhas **ou** `EXPLAIN (ANALYZE, BUFFERS)` > 50 ms; candidato com nome, literais e `supportsPartialIndexes()`. |

### Checklist DBA (resumo)

| Área | Resultado |
|------|-----------|
| Normalização 3NF | ✅ (4 desnormalizações documentadas: `statuses`, filtros empresa+filial, métricas, `disk`) |
| Morph vs dedicado | ✅ dedicado; morph map inalterado |
| Campos (`_at` para instante, `date` para calendário, `_by`, `_id`, `_count`) | ✅ |
| Enums (`string(20)`, sem `$table->enum()`, tamanhos medidos) | ✅ |
| UUID + `timestampsTz` + `softDeletesTz` | ✅ |
| Monetários | n/a na tabela nova; agregados vêm de `decimal(10,2)` |
| FK `->index()` explícito | ✅ |
| `onDelete` | ✅ corrigido (#1): restrict em empresa/filial, `nullOnDelete` em blameable |
| Soft delete vs FK | ✅ limpeza no force delete de cadastro; soft delete não afeta relatórios |
| Pruning | ✅ `Prunable` + observer no `forceDeleted` (#4) |
| Índices só com WHERE/ORDER BY real e tabela relevante | ✅ (#5) |
| Tabelas existentes | ✅ sem índice novo; gatilho objetivo (#11) |
| Consultas: N+1, ambiguidade, chunking | ✅ corrigido (#2, #3, #7) |
| CHECK multi-coluna / `after()` | ✅ não usar |
| Factories | ✅ (#8, `trashed()` nativo) |

### Decisões DBA confirmadas (2026-09-27)

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | `nullOnDelete` em `analytical_reports.company_id` e `branch_id`? | **Não. `restrictOnDelete`**: `null` = "todas", então `SET NULL` muda o significado da linha. O force delete de empresa (`CompanyObserver::forceDeleting`) e o de filial removem antes os relatórios referenciados (#1) |
| 2 | Check `period_end >= period_start`? | **Não criar.** Zero `->check()` no repo (F7 §21: "sem check multi-coluna"); o Blueprint do Laravel 12 não tem API para isso, e a alternativa (`DB::statement` com `ALTER TABLE … ADD CONSTRAINT`) não roda no SQLite dos testes. O form (`after_or_equal`) e o Service (`invalidPeriod`) cobrem, e a tabela só é escrita pelo Service |
| 3 | JSON em `statuses`? | **Sim, `json`** (não `jsonb`, precedente `cnab_files.config_snapshot`/`import_batches.mappings_snapshot`). Snapshot multivalorado nunca consultado por SQL; pivot seria desproporcional para um artefato de 30 dias. Normalizado: valores únicos e ordenados, vazio → `null` |
| 4 | Gatilho de índice futuro e nenhum índice novo em `payment_requests`/`payment_settlements`/`payment_settlement_items`? | **Nenhum índice novo: confirmado.** Gatilho ajustado para 200 mil linhas em `payment_requests` **ou** `EXPLAIN (ANALYZE, BUFFERS)` da agregação de abertos > 50 ms (igual à F7). Candidato: `payment_requests_open_due_date_index` parcial `(due_date) WHERE status IN ('requested','launched') AND deleted_at IS NULL`. `payment_settlements (status, settlement_date)` só acima de ~50 mil baixas |
| 5 | `date_basis` indexar? | **Não** (nunca em WHERE; tabela pequena) |
| 6 | `status` indexar? | **Sim**, por convenção e precedente, mesmo com tabela pequena (mesmo critério do `is_active` de `cnab_configs` na F7) |
| 7 | Composto `(created_by, created_at)`? | **Não** (#5) |
| 8 | `unsignedInteger` no PostgreSQL precisa da ressalva da F7? | Vira `integer` e o unsigned não é imposto, igual à F7. **Sem guard extra**: os valores são escritos só pelo job e são limitados pelo domínio (`rows_count` ≤ 20 000, `attachments_count` ≤ 200 000, `size` de um `.xlsx` de 20 mil linhas na ordem de MB, bem abaixo de 2³¹). Diferente do NSA da F7, não há limite de layout a respeitar |
| 9 | `Prunable` ou `MassPrunable`? | **`Prunable`**, porque o delete em massa não dispara `forceDeleted`. Arquivo apagado no observer, não em `pruning()` |
| 10 | `lazyById(500)` no job? | **Não** (#2). Snapshot ordenado de IDs + blocos de 500 com `whereKey` |
| 11 | Cache do dashboard | Sem exigência de banco. Volume permite agregação direta; o cache é só para reduzir consultas repetidas |

### Notas para implementer

1. Migration `create_analytical_reports_table` (`make:model AnalyticalReport --migration --factory`), colunas na ordem de §20. `status` `string(20)->default('queued')->index()`; `date_basis` `string(20)->default('due_date')` **sem** `->index()`.
2. FKs: `$table->foreignUuid('company_id')->nullable()->index()->constrained('companies')->restrictOnDelete();` e o mesmo para `branch_id`/`branches`. Blameable: `->nullable()->index()->constrained('users')->nullOnDelete()`, igual a `import_batches`.
3. `filename` `string(100)`; `disk` `string(50)` NOT NULL; `path` `string(500)->nullable()`; `size` `unsignedInteger()->nullable()`; contadores `unsignedInteger()->default(0)`; `failure_reason` `text()->nullable()`; `started_at`/`generated_at` `timestampTz()->nullable()`; `timestampsTz()` + `softDeletesTz()`.
4. **Não criar:** índice composto `(created_by, created_at)`, índice em `date_basis`/`period_*`/`generated_at`, check constraint, `->after()`, `jsonb`, nenhum índice em `payment_requests`/`payment_settlements`/`payment_settlement_items`. Sem `supportsPartialIndexes()` nesta migration (não há índice parcial).
5. Comentários de coluna em inglês, uma linha, acima da coluna (estilo `cnab_files`): `// Filter snapshot; null = all` (sobre `company_id` e `branch_id`), `// Snapshot of rjet.reports.disk at request time` (`disk`), `// Denormalized: filter snapshot, never queried by SQL; null = all statuses` (`statuses`), `// Denormalized: generated file metrics, immutable after generated` (`rows_count`/`attachments_count`).
6. `AnalyticalReportObserver` (`#[ObservedBy]`, `implements ShouldHandleEventsAfterCommit`): `forceDeleted` → se `path` e `disk` preenchidos, `Storage::disk($disk)->delete($path)`. O model **não** implementa `pruning()`.
7. `CompanyObserver::forceDeleting`: antes do bloco das filiais, `AnalyticalReport::withTrashed()->where(fn ($q) => $q->where('company_id', $id)->orWhereIn('branch_id', $branchIds))->get()->each->forceDelete()` (condição agrupada). Force delete isolado de filial: mesmo passo por `branch_id` (no `->before()` das `ForceDeleteAction`/`ForceDeleteBulkAction` de filial ou num `BranchService::forceDelete()`).
8. Qualificar colunas: `scopeForBranch` → `$query->where($query->qualifyColumn('branch_id'), …)`; idem nos novos `scopeOpen`/`scopeForCompany`. Na consulta de Pago, prefixar toda coluna (`payment_settlements.status`, `payment_settlements.deleted_at`, `payment_settlements.settlement_date`, `payment_settlement_items.released_at`, `payment_requests.branch_id`, `payment_requests.cost_center_id`).
9. Job: `pluck('payment_requests.id')` com `orderBy` qualificado (`due_date`, `created_at`, `id`) → `chunk(500)` → `whereKey($bloco)->with([...])->get()->keyBy('id')` e escrita na ordem do bloco. `N` por subquery agregada (§9.5 passo 5), nunca por `whereIn` com a lista inteira.
10. Relações de rótulo no `with()` do job com `fn ($q) => $q->withTrashed()` (`branch`, `branch.company`, `supplier`, `costCenter`, `costCenter.branch`, `appropriation`, `bankDetails.bank`).
11. `statuses`: normalizar no Service (`array_values(array_unique(...))` ordenado; `[]` → `null`) antes do `create`; cast `array`.
12. Retry: update condicional `where id = ? and status = ? and updated_at = ?` (valores lidos); zero linhas → `AnalyticalReportException::notRetryable()`.
13. Schedule: `Schedule::command('model:prune', ['--model' => [AnalyticalReport::class]])->dailyAt('02:00')->withoutOverlapping();` (estilo das entradas atuais de `routes/console.php`).
14. Testes Pest: §17 #24, #25, #27 e #28 cobrem as correções desta revisão (prune e force delete apagam o arquivo, FK restrict, ausência do composto, ordem e completude com mais de 500 linhas, join sem ambiguidade, wipe de empresa com relatório).
