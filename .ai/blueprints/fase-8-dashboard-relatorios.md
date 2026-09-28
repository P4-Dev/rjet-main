# Filament Blueprint: Fase 8 — Dashboard e relatórios

> **Tipo:** Plano de implementação (Filament Blueprint v5). **NÃO é código de produção.**
> **Escopo:** RF035–RF037. Dashboard com quatro indicadores e dois gráficos (filtros globais de empresa, filial e período) e relatório analítico em Excel gerado em fila, com links de anexo autenticados.
> **Fonte de requisitos:** [.ai/arquitetura/fase-8-dashboard-relatorios.md](../arquitetura/fase-8-dashboard-relatorios.md), revisão DBA §23 (**aprovado com ressalvas**, já incorporadas no texto da arquitetura). O schema vigente é o **pós-DBA**. Se algum trecho antigo divergir de §23, vale §23 e este blueprint.
> **Stack:** Laravel 12 (`laravel/framework ^12.0`; o `PROJECT.md` diz "13", vale o lock) · Filament 5.7 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL (testes em SQLite `:memory:`) · Redis · OpenSpout 4.32 (transitivo).
> **Autor:** blueprint · **Data:** 2026-09-27
> **Destinatário:** `implementer`. Este documento é a **única** fonte que o implementador verá. Regras de Filament v5, DBA e convenções do repo estão copiadas aqui.
> **Fora de escopo:** redesenho de `PaymentRequest`, alçadas (F4), importação (F5), lote de anexos (F6), baixa e CNAB (F7) além dos hooks de §1.2. Sem API REST. Sem arquivo de retorno CNAB. Sem dashboard de SLA/aprovação. Sem relatório agendado ou enviado por e-mail com anexo. Sem Livewire custom. Sem dependência nova.

`vendor/filament/blueprint/resources/markdown/planning/overview.md` **não existe** nesta cópia. A estrutura segue `.ai/skills/filament/SKILL.md` e o blueprint da Fase 7. A sintaxe de dashboard, widgets e rota foi conferida no vendor (Filament 5.7): `HasFiltersForm`, `ChartWidget`, `CanPoll`, `InteractsWithPageFilters`, `Panel::authenticatedRoutes()`, `Table::poll(Closure)`, OpenSpout `SheetView::setFreezeRow(2)`. O MCP `search-docs` não estava disponível nesta sessão.

---

## Índice

0. [Regras globais Filament v5 (LEIA PRIMEIRO)](#0-regras-globais-filament-v5-leia-primeiro)
1. [Visão geral](#1-visão-geral)
2. [Commands (ordem de execução)](#2-commands-ordem-de-execução)
3. [Models e migrations](#3-models-e-migrations)
4. [Enums](#4-enums)
5. [Exceções de domínio](#5-exceções-de-domínio)
6. [Camadas de aplicação](#6-camadas-de-aplicação)
7. [Página Dashboard](#7-página-dashboard)
8. [Widgets](#8-widgets)
9. [Filament Resource: AnalyticalReport](#9-filament-resource-analyticalreport)
10. [Authorization / Policies](#10-authorization--policies)
11. [State transitions](#11-state-transitions)
12. [Events / Listeners / Notifications / Queue](#12-events--listeners--notifications--queue)
13. [Traduções (i18n)](#13-traduções-i18n)
14. [API REST](#14-api-rest)
15. [Soft deletes, cascade e retenção](#15-soft-deletes-cascade-e-retenção)
16. [Factories e seeders](#16-factories-e-seeders)
17. [Tests (Pest)](#17-tests-pest)
18. [Estimativa](#18-estimativa)
19. [Notas, checklist e pendências](#19-notas-checklist-e-pendências)
20. [Próximos passos](#20-próximos-passos)

---

## 0. Regras globais Filament v5 (LEIA PRIMEIRO)

### 0.1 Namespaces Filament v5 (obrigatórios)

| Elemento | Namespace |
|----------|-----------|
| Form fields | `Filament\Forms\Components\{Component}` |
| Table columns | `Filament\Tables\Columns\{Column}` |
| Table filters | `Filament\Tables\Filters\{Filter}` |
| **Todas** as Actions | `Filament\Actions\{Action}` (`Action`, `BulkActionGroup`, `ViewAction`, `DeleteAction`, `RestoreAction`, `DeleteBulkAction`, `ForceDeleteAction`) |
| Infolist entries | `Filament\Infolists\Components\TextEntry` |
| Layout | `Filament\Schemas\Components\Section` |
| Reactive utilities | `Filament\Schemas\Components\Utilities\Get` e `Set` |
| Schema container | `Filament\Schemas\Schema` |
| Table container | `Filament\Tables\Table` |
| Ícones na UI Filament | `Filament\Support\Icons\Heroicon` (**enum**) |
| Ícones em `getIcon()` de enum de domínio | string `heroicon-o-*` (padrão de `PaymentRequestStatus`) |
| Notificações toast | `Filament\Notifications\Notification` |
| Auth do painel | `Filament\Facades\Filament` → `Filament::auth()->user()` |
| Dashboard base | `Filament\Pages\Dashboard` |
| Filtros do dashboard | `Filament\Pages\Dashboard\Concerns\HasFiltersForm` |
| Filtros no widget | `Filament\Widgets\Concerns\InteractsWithPageFilters` (pacote `filament/filament`, propriedade `$pageFilters`) |
| Stats | `Filament\Widgets\StatsOverviewWidget` + `Filament\Widgets\StatsOverviewWidget\Stat` |
| Gráfico | `Filament\Widgets\ChartWidget` |
| JS cru no Chart.js | `Filament\Support\RawJs` |
| Moeda na UI | `Illuminate\Support\Number::currency((float) $amount, 'BRL', 'pt_BR')` |

**Namespaces e métodos errados (não usar):**

| Errado | Certo |
|--------|-------|
| `Filament\Forms\Get` / `Set` | `Filament\Schemas\Components\Utilities\Get` / `Set` |
| `Filament\Tables\Actions\Action` | `Filament\Actions\Action` |
| `Filament\Forms\Components\Section` | `Filament\Schemas\Components\Section` |
| `->reactive()` | `->live()` |
| `->actions()` na tabela | `->recordActions()` |
| `->bulkActions()` | `->toolbarActions([BulkActionGroup::make([...])])` |
| `->form()` em Action | `->schema()` |
| `BadgeColumn`, `MultiSelect`, `Card` | `TextColumn->badge()`, `Select->multiple()`, `Section` |
| `$this->filters` dentro do widget | `$this->pageFilters` |
| `->maxHeight('320px')` / `->pollingInterval()` | propriedades `$maxHeight` e `$pollingInterval` (ver §0.9) |

### 0.2 Estrutura obrigatória

```
app/Filament/Pages/Dashboard.php                         ← final, estende Filament\Pages\Dashboard

app/Filament/Widgets/
├── PaymentOverviewStats.php                            ← final
├── PaymentsByBranchChart.php                           ← final
└── PaymentsByCostCenterChart.php                       ← final

app/Filament/Resources/AnalyticalReports/               ← PLURAL
├── AnalyticalReportResource.php                        ← final, LIMPO
├── Schemas/AnalyticalReportForm.php                    ← final
├── Schemas/AnalyticalReportInfolist.php                ← final
├── Tables/AnalyticalReportsTable.php                   ← final
├── Pages/ListAnalyticalReports.php
├── Pages/ViewAnalyticalReport.php
├── (SEM CreateAnalyticalReport e SEM EditAnalyticalReport)
└── Actions/
    ├── RequestAnalyticalReportAction.php
    ├── DownloadAnalyticalReportAction.php
    └── RetryAnalyticalReportAction.php

app/Http/Controllers/OpenReportAttachmentController.php ← final, invocável
```

**Validação:** se `AnalyticalReportResource.php` contiver `TextInput`, `TextColumn`, `Section`, `Select` ou qualquer componente de form/table/layout, está errado. Todas as classes são `final` e têm `declare(strict_types=1);`. Action Filament: `final class XAction { public static function make(): Action }`, igual a `DownloadCnabFileAction`.

Resource **sempre** via Artisan. Página `Dashboard` e o controller **não** têm `make:` equivalente útil: criar a classe na mão, no namespace acima.

### 0.3 Resource principal (espelhar `ImportBatchResource`)

- `protected static ?string $model`, `$navigationIcon` (`Heroicon::OutlinedDocumentChartBar`), `$navigationSort = 10`.
- `getModelLabel()`, `getPluralModelLabel()`, `getNavigationLabel()`, `getNavigationGroup()` com `__()`.
- `canViewAny(): bool` → `Filament::auth()->user()?->can('viewAny', AnalyticalReport::class) ?? false`.
- `form()`, `infolist()`, `table()` delegam para `Schemas/*` e `Tables/*`.
- `getRelations(): array` retorna `[]`.
- `getPages()` só `index` e `view`.
- `getEloquentQuery()` → `parent::...->with(['company', 'branch', 'creator'])`.
- `getRecordRouteBindingEloquentQuery()` → `parent::...->withoutGlobalScopes([SoftDeletingScope::class])->with([...])`. **Não** tirar o `SoftDeletingScope` em `getEloquentQuery()`.

### 0.4 Layout

Forms e infolist: `$schema->columns(1)`; cada `Section` com `->columns(2)`; `failure_reason` com `->columnSpanFull()`.

O schema que `filtersForm(Schema $schema)` recebe **já** tem `->columns(['md' => 2, 'xl' => 3, '2xl' => 4])->live()->statePath('filters')` (`HasFiltersForm::getFiltersForm()`). A `Section` dos filtros precisa de `->columnSpanFull()`. Sem isso ela ocupa uma célula do grid e os quatro campos ficam espremidos num quarto da largura.

### 0.5 i18n

Nenhuma string de UI hardcoded. Todo label, heading, placeholder, modal e toast usa `__()`. Campos comuns em `common.php` (`common.sections.audit`, `common.fields.created_at`, `common.fields.created_by`). Arquivos novos: `dashboard.php`, `analytical_reports.php`. Estender: `enums.php`, `notifications.php`. Sempre `lang/pt_BR/` e `lang/en/`. `navigation.groups.reports` já existe ("Relatórios" / "Reports").

### 0.6 Padrões do projeto

- Models: `final`, `declare(strict_types=1)`, `casts()` como **método**, PK `uuid` via `HasUuid`, `HasFactory`, `SoftDeletes`, `HasBlameable`.
- Migrations: `timestampsTz()` + `softDeletesTz()`; FK com `->index()` explícito; status sempre `string`, nunca `$table->enum()`; **sem** `after()`; **sem** `check()`; **sem** `jsonb`; **sem** índice parcial nesta migration.
- Monetário: somas saem de `decimal(10,2)`. Na borda do Service, string com 2 casas. Float só na apresentação (Chart.js e `NumericCell`).
- Policies: constante `AppServiceProvider::POLICIES`. Observer novo de `AnalyticalReport`: `#[ObservedBy]` no model (padrão `PaymentRequest`). **Não** registrar de novo em `AppServiceProvider` se o atributo já observa.
- Listeners com `handle(Event)` tipado usam **auto-discovery**. **Não** adicionar `Event::listen`. Depois, `php artisan event:list` e conferir que cada listener novo aparece **uma vez**.
- Erro de domínio na Action Filament: `catch (BusinessException $e)` → `Notification::make()->title($e->getUserMessage())->danger()->send()` → `$action->halt()`.
- Belt: `abort_unless(Filament::auth()->user()?->can(...) ?? false, 403)`.
- `Model::preventLazyLoading()` ativo fora de produção. Eager load no job, na table e no infolist.
- Agrupamento: Events, Listeners, Actions, Jobs e Integrations por domínio (`Report`, `Dashboard`). Services, DTOs, Exceptions, Models, Policies e Notifications **flat**.
- Comentários de código: mínimos, em **inglês**. Este documento: pt-BR.
- Timezone de negócio: `America/Sao_Paulo`. "Hoje" = `today(config('app.timezone'))`. `due_date` e `settlement_date` são `date` e comparam com `Y-m-d`.
- Docker (`PROJECT.md`): prefixar artisan, pest e pint com `docker compose exec app`.

### 0.7 Decisões fechadas (não reabrir)

| # | Decisão |
|---|---------|
| 1 | Sem API REST e sem Livewire custom. A única rota HTTP nova é `filament.admin.report-attachments.open` |
| 2 | Quatro indicadores: **Pago** (`settlement_date` + `payment_settlement_items.amount` do item ativo, baixa `settled`); **Pendentes / A vencer / Vencidos** são posição em "hoje" sobre PRs `requested`+`launched`, limitadas por `period_end`, **sem** limite inferior. Vence hoje = a vencer. Invariante: Pendentes = A vencer + Vencidos |
| 3 | O início do período só entra em Pago. `period_end < hoje` zera "A vencer" com descrição "período encerrado" |
| 4 | PR, baixa e item soft-deleted ficam fora de todos os números, inclusive Pago. PR `Settled` sem baixa não entra em Pago |
| 5 | Visibilidade **sempre** `PaymentRequest::visibleTo($user)`, além do filtro. Filtro forjado não alarga o escopo |
| 6 | `null` em `company_id` / `branch_id` / `statuses` significa "todos". FK de empresa e filial é `restrictOnDelete`. `statuses` vazio normaliza para `null` |
| 7 | Sem índice novo em `payment_requests`, `payment_settlements` ou `payment_settlement_items`. Sem índice composto `(created_by, created_at)`. Sem check de período |
| 8 | Cache sem tags: chave `dashboard:v{version}:{scope}:{bloco}:{hash}`, TTL 300 s (`0` desliga). `flush()` incrementa `dashboard:version` em `DB::afterCommit` |
| 9 | Gráficos: no máximo **12 barras, incluindo "Outros"**. Acima de 12, as 11 maiores (total desc) ficam nomeadas e o restante soma em "Outros". O total do gráfico continua igual ao card |
| 10 | `ChartWidget::isEmpty()` só é verdadeiro se `getData()` retornar `[]`. Array com `datasets` vazios **não** dispara o empty state |
| 11 | `pollingInterval` default do `CanPoll` é `'5s'`. Stats: `'60s'`. Gráficos: `null`. Lazy permanece no default (`true`) |
| 12 | Excel: OpenSpout já no lock. Sem Filament Exports, sem tabela `exports`, sem pacote de chart. Duas abas, cabeçalho em negrito, primeira linha congelada (`SheetView::setFreezeRow(2)`, confirmado no OpenSpout 4.32) |
| 13 | Job na conexão **default**, fila `rjet.reports.queue` (`default`), `$timeout = 75`, `$tries = 3`, `$backoff = [10, 30, 60]`. **Sem** `WithoutOverlapping`. Idempotência pelo update condicional |
| 14 | Ordem das linhas: `due_date`, `created_at`, `id`. **Não** usar `lazyById` / `chunkById`. Snapshot `pluck` de IDs + blocos de 500 com `whereKey` |
| 15 | `rowsQuery()` sem join (`whereHas` na base `settlement_date`) e sem `orderBy` |
| 16 | Colunas do Excel = §9.5 (A5). Ajuste futuro é só tradução + writer (P-F8-COLS), sem migration |
| 17 | Link de anexo: sessão + `signed:relative` **sem expiração** + `AttachmentPolicy::view` + redirect para `temporaryUrl` de 5 min (ou stream inline). O `.xlsx` em si baixa só por `Storage::download` atrás da Policy, sem `temporaryUrl` |
| 18 | Relatório só para Operador e Adm (`UserRole::seesAllBranches()`). Cliente vê o dashboard escopado e não solicita relatório. Excluir/restaurar/force delete: só Adm (`User::isAdm()`) |
| 19 | Retenção 30 dias. `Prunable` (não `MassPrunable`). Arquivo apagado em `AnalyticalReportObserver::forceDeleted`, não em `pruning()`. `model:prune` diário às 02:00 |
| 20 | Arquivo **nunca** vai anexado ao e-mail. A notificação aponta para a View do relatório |
| 21 | Navegação do relatório: grupo `reports`, sort 10. Dashboard sem grupo, no topo, substituindo `Filament\Pages\Dashboard`. Remover `FilamentInfoWidget`. Manter `AccountWidget` (sort default `-1`) |

### 0.8 Estado real do código a estender (2026-09-27)

| Artefato | Estado |
|----------|--------|
| `PaymentRequest` | Existe. Scopes `visibleTo`, `status`, `dueBetween`, `forBranch` (este **sem** `qualifyColumn` — corrigir). Relações `branch`, `supplier`, `costCenter`, `appropriation`, `bankDetails`, `attachments`, `settlementItems`, `activeSettlementItem`, `creator`. Sem data de emissão; data de solicitação = `created_at` |
| `PaymentSettlement` / `PaymentSettlementItem` | Existem. `PaymentSettlement::TIMEZONE = 'America/Sao_Paulo'`. Unique parcial de um item ativo por PR |
| `PaymentRequestObserver` | `deleted`, `restored`, `forceDeleting` (cascata de anexos). **Sem** `updated` e **sem** `forceDeleted`. Sem hook de cache |
| `LogPaymentRequestActivity` | Comentário `// Hook for F8 dashboard cache invalidation.` na linha do `handleStatusChanged`. Remover o comentário; o flush é outro listener |
| `AdminPanelProvider` | `->pages([Filament\Pages\Dashboard::class])`, widgets `AccountWidget` + `FilamentInfoWidget`, `discoverWidgets` e `discoverPages` já ligados. Pastas `Pages` e `Widgets` ainda não existem |
| `AppServiceProvider::POLICIES` | Sem `AnalyticalReport`. Bind `SpreadsheetReader → OpenSpoutSpreadsheetReader` já existe |
| `config/rjet.php` | Sem chaves `dashboard` nem `reports` |
| `routes/console.php` | `approvals:escalate-sla` e `cnab:recover-stuck-files`. Sem `model:prune` |
| `CompanyObserver::forceDeleting` | Wipe de PRs e filiais **antes** do hard delete da empresa. Ainda não mexe em relatório |
| Force delete de filial | `EditBranch` (`ForceDeleteAction`), `BranchesTable` e `BranchesRelationManager` (`ForceDeleteBulkAction`). Não há `BranchObserver` |
| Grupo `navigation.groups.reports` | Traduzido e vazio |
| OpenSpout | `FormulaCell`, `DateTimeCell`, `NumericCell`, `StringCell`, `Sheet::setSheetView`, `SheetView::setFreezeRow(2)` |

### 0.9 APIs conferidas no vendor (não inventar outra)

`HasFiltersForm` já inclui `HasFilters`. A página ganha `public ?array $filters` com `#[Url]` e persistência em sessão. Os widgets recebem isso como `pageFilters` porque `Page` passa `pageFilters` quando a propriedade `filters` existe.

`ChartWidget`:

- `protected function getType(): string` abstrato. Barras verticais e horizontais usam `'bar'`; o horizontal muda em `getOptions()` com `indexAxis: 'y'`.
- `protected function getData(): array`.
- `protected ?string $maxHeight = '320px'`.
- `protected ?string $pollingInterval` vem de `CanPoll` com default `'5s'`. Sobrescrever na classe.
- `isEmpty()` faz `empty($this->getCachedData())`. Retornar `[]` quando não houver valor.
- Empty state: sobrescrever `getEmptyStateHeading(): string|Htmlable` com `__('dashboard.charts.empty')`. A propriedade `$emptyStateHeading` não aceita `__()` no valor default de forma limpa.
- Heading dinâmico: sobrescrever `getHeading()` / `getDescription()`. O gerador cria `$heading` em inglês; **apagar** essa propriedade.

`StatsOverviewWidget` já tem `$columnSpan = 'full'` e `CanPoll` (`'5s'`). Sobrescrever `$pollingInterval = '60s'`. Cards em `getStats(): array` de `Stat::make(label, value)->description()->color()->icon(Heroicon::...)`.

`Table::poll(string|Closure|null)` existe. Closure sem injeção de `Builder`: consultar `AnalyticalReport::query()->inProgress()->exists()`.

`authenticatedRoutes(?Closure $routes)` registra a closure **dentro** do grupo com middleware de auth do painel, prefixo `admin` e nome `filament.admin.`. A closure recebe `Panel $panel`. Acrescentar `->middleware('signed:relative')`.

---

## 1. Visão geral

A Fase 8 entrega a visão gerencial em cima do ciclo que as Fases 1–7 já fecharam (`Requested → Launched → Settled` só pela baixa confirmada).

| Entrega | RF | Artefato |
|---------|----|----------|
| Total pago, pendentes, a vencer e vencidos, com filtros de empresa, filial e período, escopados por papel | RF035 | `App\Filament\Pages\Dashboard` + `PaymentOverviewStats` + `DashboardMetricsService` |
| Gráficos por filial e por centro de custo, mesmos filtros e mesmos números | RF036 | `PaymentsByBranchChart` + `PaymentsByCostCenterChart` |
| Excel analítico assíncrono com links de anexo que exigem login e Policy | RF037 | `AnalyticalReport` + `GenerateAnalyticalReportJob` + `SpreadsheetWriter` + rota `report-attachments.open` |

### 1.1 Fluxo

```
[Dashboard]
  Filtros (URL + sessão) → DashboardFilterData (sanitiza) → DashboardMetricsService
  cache dashboard:v{n}:{scope}:{bloco}:{hash} por 300s
  Cards + dois gráficos. Cliente vê só as filiais vinculadas.

[Invalidação]
  PaymentRequestCreated | StatusChanged | BatchImported → FlushDashboardMetricsCache
  Edição de due_date/net_amount/cost_center_id/branch_id, delete, restore, forceDelete
    → PaymentRequestObserver → DashboardMetricsService::flush()
  flush incrementa a versão depois do commit.

[Relatório]
  Operador/Adm → "Gerar relatório analítico" (Dashboard ou lista)
  → valida período, limite em andamento, contagem 1..20_000
  → AnalyticalReport(queued) → AnalyticalReportRequested (after commit)
  → GenerateAnalyticalReportJob (fila default, timeout 75s)
  → generating → duas abas em streaming → disco private
  → update condicional generated → AnalyticalReportGenerated
  → mail + database com link da View (sem anexo)
  → Baixar (Policy, stream) → AnalyticalReportDownloaded
  → clique no anexo do Excel → /admin/report-attachments/{id}?signature=
     login se preciso → Policy → temporaryUrl de 5 min
```

### 1.2 Hooks mínimos em código existente

| Arquivo | Mudança |
|---------|---------|
| `App\Models\PaymentRequest` | `scopeOpen()` e `scopeForCompany(Company\|string)`. Qualificar `branch_id` em `scopeForBranch` e nos dois novos (`$query->qualifyColumn(...)`) |
| `App\Observers\PaymentRequestObserver` | `updated()`: se `wasChanged(['due_date', 'net_amount', 'cost_center_id', 'branch_id'])`, `DashboardMetricsService::flush()`. Chamar `flush()` também em `deleted()`, `restored()` e num `forceDeleted()` novo. O `forceDeleting()` que apaga anexos permanece |
| `App\Listeners\PaymentRequest\LogPaymentRequestActivity` | Remover o comentário do hook da F8. Não colocar o flush aqui |
| `App\Observers\CompanyObserver::forceDeleting` | **Antes** do wipe das filiais: relatórios com `company_id` da empresa **ou** `branch_id` nas filiais (`withTrashed`) → `get()->each->forceDelete()`. Condição agrupada num único `where(fn ...)` |
| Force delete de filial | `BranchObserver::forceDeleting`: `AnalyticalReport::withTrashed()->where('branch_id', $branch->getKey())->get()->each->forceDelete()`. Cobre `EditBranch`, `BranchesTable` e `BranchesRelationManager`, porque essas actions chamam `forceDelete()` no model. Durante o wipe da empresa o `CompanyObserver` já removeu os relatórios; o observer da filial não encontra linha e segue |
| `AdminPanelProvider` | `->pages([App\Filament\Pages\Dashboard::class])` no lugar do Dashboard base. Tirar `FilamentInfoWidget` de `->widgets()`. `->authenticatedRoutes(...)` da rota de anexo. `discoverWidgets` / `discoverPages` permanecem. **Não** listar os três widgets de novo em `->widgets()` (`getWidgets()` faz `unique()`; a descoberta basta) |
| `AppServiceProvider` | `AnalyticalReport::class => AnalyticalReportPolicy::class` em `POLICIES`. Bind `SpreadsheetWriter → OpenSpoutXlsxSpreadsheetWriter` ao lado do reader |
| `config/rjet.php` + `.env.example` | Chaves de §6.8 |
| `routes/console.php` | `model:prune` só de `AnalyticalReport`, diário às 02:00, `withoutOverlapping()` |

Nada muda em `PaymentSettlement`, `PaymentSettlementService`, CNAB, alçadas, importação ou lote de anexos. `AttachmentPolicy` e `PaymentRequestPolicy` permanecem.

---

## 2. Commands (ordem de execução)

Ambiente Docker: prefixar com `docker compose exec app`.

```bash
# 1. Model + migration + factory (a migration gerada será reescrita conforme §3)
php artisan make:model AnalyticalReport --migration --factory --no-interaction

# 2. Policy
php artisan make:policy AnalyticalReportPolicy --model=AnalyticalReport --no-interaction

# 3. Resource (gera Create/Edit; apagar os dois em seguida)
php artisan make:filament-resource AnalyticalReport --generate --soft-deletes --view --panel=admin --no-interaction

# 4. Widgets
php artisan make:filament-widget PaymentOverviewStats --stats-overview --panel=admin --no-interaction
php artisan make:filament-widget PaymentsByBranchChart --chart --panel=admin --no-interaction
php artisan make:filament-widget PaymentsByCostCenterChart --chart --panel=admin --no-interaction

# 5. Testes (feature; sem --unit)
php artisan make:test --pest DashboardMetricsServiceTest --no-interaction
php artisan make:test --pest DashboardMetricsCacheTest --no-interaction
php artisan make:test --pest DashboardWidgetsTest --no-interaction
php artisan make:test --pest DashboardPageTest --no-interaction
php artisan make:test --pest DashboardPerformanceTest --no-interaction
php artisan make:test --pest AnalyticalReportServiceTest --no-interaction
php artisan make:test --pest GenerateAnalyticalReportJobTest --no-interaction
php artisan make:test --pest AnalyticalReportNotificationTest --no-interaction
php artisan make:test --pest ReportAttachmentLinkTest --no-interaction
php artisan make:test --pest AnalyticalReportDownloadTest --no-interaction
php artisan make:test --pest AnalyticalReportAuthorizationTest --no-interaction
php artisan make:test --pest AnalyticalReportResourceTest --no-interaction
php artisan make:test --pest AnalyticalReportPruneTest --no-interaction
php artisan make:test --pest AnalyticalReportSchemaTest --no-interaction
php artisan make:test --pest SpreadsheetWriterTest --no-interaction
```

Criar na mão (não há `make:` que acerte o domínio):

- Enums em `app/Enums/`
- `app/Exceptions/AnalyticalReportException.php`
- DTOs em `app/DTOs/`
- `app/Services/DashboardMetricsService.php`, `app/Services/AnalyticalReportService.php`
- `app/Actions/Report/{Request,Retry,Download}AnalyticalReportAction.php`, `OpenReportAttachmentAction.php`
- `app/Jobs/Report/GenerateAnalyticalReportJob.php`
- `app/Events/Report/AnalyticalReport{Requested,Generated,GenerationFailed,Downloaded}.php`
- `app/Listeners/Report/{QueueAnalyticalReportGeneration,NotifyAnalyticalReportGenerated,NotifyAnalyticalReportGenerationFailed,LogAnalyticalReportActivity}.php`
- `app/Listeners/Dashboard/FlushDashboardMetricsCache.php`
- `app/Notifications/AnalyticalReport{Generated,GenerationFailed}Notification.php`
- `app/Integrations/Spreadsheet/{SpreadsheetWriter,SpreadsheetCell,OpenSpoutXlsxSpreadsheetWriter}.php`
- `app/Observers/{AnalyticalReportObserver,BranchObserver}.php`
- `app/Filament/Pages/Dashboard.php`
- `app/Http/Controllers/OpenReportAttachmentController.php`
- Actions Filament em `app/Filament/Resources/AnalyticalReports/Actions/`

Depois do scaffold do Resource: apagar `Pages/CreateAnalyticalReport.php` e `Pages/EditAnalyticalReport.php` e tirá-los de `getPages()`. O gerador de chart deixa `$heading` em inglês: substituir por `getHeading()`.

Classes genéricas que o Artisan não cobre: `php artisan make:class` quando o comando existir para o tipo; o restante, arquivo novo no namespace certo. Não criar pasta base nova.

Fechamento:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/DashboardMetricsServiceTest.php
# … os arquivos de §17, no conjunto mais estreito que cubra a mudança
php artisan event:list
```

---

## 3. Models e migrations

### 3.1 `analytical_reports` (única migration nova)

Nenhuma alteração de schema em tabela existente. Nenhuma coluna nova em `payment_requests`.

| Campo | Tipo | Nullable | Default | Descrição |
|-------|------|----------|---------|-----------|
| id | uuid | não | — | PK, `HasUuid` |
| status | string(20) | não | `queued` | `AnalyticalReportStatus`. **Index** |
| company_id | foreignUuid | sim | null | FK `companies` **restrictOnDelete**, index. Null = todas |
| branch_id | foreignUuid | sim | null | FK `branches` **restrictOnDelete**, index. Null = todas |
| date_basis | string(20) | não | `due_date` | `ReportDateBasis`. **Sem índice** |
| period_start | date | não | — | Inclusivo |
| period_end | date | não | — | Inclusivo. Sem check no banco |
| statuses | json | sim | null | Snapshot de filtro. Null = todos. Nunca consultado por SQL |
| disk | string(50) | não | — | Snapshot de `rjet.reports.disk` na criação |
| path | string(500) | sim | null | `reports/{YYYY}/{MM}/{uuid}.xlsx`. Nunca exibido |
| filename | string(100) | sim | null | Nome do download, ASCII, ~55 caracteres |
| size | unsignedInteger | sim | null | Bytes. No PostgreSQL vira `integer` |
| rows_count | unsignedInteger | não | 0 | Métrica imutável do arquivo |
| attachments_count | unsignedInteger | não | 0 | Métrica imutável do arquivo |
| failure_reason | text | sim | null | `getUserMessage()`, sem path nem stack |
| started_at | timestampTz | sim | null | Primeira execução |
| generated_at | timestampTz | sim | null | Publicação |
| created_by | foreignUuid | sim | null | FK `users` **nullOnDelete**, index. Solicitante notificado |
| updated_by | foreignUuid | sim | null | FK `users` **nullOnDelete**, index |
| created_at / updated_at | timestampsTz | não | — | — |
| deleted_at | softDeletesTz | sim | null | — |

Comentários de coluna em inglês, uma linha, no estilo de `cnab_files`:

- `company_id` e `branch_id`: `Filter snapshot; null = all`
- `disk`: `Snapshot of rjet.reports.disk at request time`
- `statuses`: `Denormalized: filter snapshot, never queried by SQL; null = all statuses`
- `rows_count` e `attachments_count`: `Denormalized: generated file metrics, immutable after generated`

**Não criar:** índice `(created_by, created_at)`, índice em `date_basis` / `period_*` / `generated_at` / `created_at`, check, `->after()`, `jsonb`, `supportsPartialIndexes()` nesta migration, qualquer índice nas tabelas da F3/F7.

Esboço (a migration gerada deve ficar neste formato; FKs com `constrained`):

```php
Schema::create('analytical_reports', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('status', 20)->default('queued')->index();
    $table->foreignUuid('company_id')->nullable()->index()->constrained('companies')->restrictOnDelete();
    $table->foreignUuid('branch_id')->nullable()->index()->constrained('branches')->restrictOnDelete();
    $table->string('date_basis', 20)->default('due_date');
    $table->date('period_start');
    $table->date('period_end');
    $table->json('statuses')->nullable();
    $table->string('disk', 50);
    $table->string('path', 500)->nullable();
    $table->string('filename', 100)->nullable();
    $table->unsignedInteger('size')->nullable();
    $table->unsignedInteger('rows_count')->default(0);
    $table->unsignedInteger('attachments_count')->default(0);
    $table->text('failure_reason')->nullable();
    $table->timestampTz('started_at')->nullable();
    $table->timestampTz('generated_at')->nullable();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});
```

Os comentários `//` ficam na linha acima da coluna correspondente, como nas migrations de `cnab_files`.

### 3.2 `App\Models\AnalyticalReport`

**Casts** (`casts()` método):

```php
return [
    'status' => AnalyticalReportStatus::class,
    'date_basis' => ReportDateBasis::class,
    'period_start' => 'date',
    'period_end' => 'date',
    'statuses' => 'array',
    'size' => 'integer',
    'rows_count' => 'integer',
    'attachments_count' => 'integer',
    'started_at' => 'datetime',
    'generated_at' => 'datetime',
];
```

**Traits:** `HasBlameable`, `HasFactory`, `HasUuid`, `SoftDeletes`, `Prunable`.

**Relationships:**

```php
public function company(): BelongsTo // withTrashed()
public function branch(): BelongsTo  // withTrashed()
public function creator(): BelongsTo  // já vem de HasBlameable
```

**Métodos:**

- `isDownloadable(): bool` — status `generated` e `path` preenchido.
- `isStale(): bool` — status `queued` ou `generating` e `updated_at` mais antigo que `config('rjet.reports.stale_after_minutes')` (30).
- `isRetryable(): bool` — `failed` ou `isStale()`.
- `prunable(): Builder` — `withTrashed()->where('created_at', '<=', now()->subDays((int) config('rjet.reports.retention_days')))`.
- **Sem** `pruning()`.

**Scopes:**

```php
scopeInProgress(Builder $query): Builder  // status in AnalyticalReportStatus::inProgressValues()
scopeCreatedBy(Builder $query, User $user): Builder
```

**Observer** `App\Observers\AnalyticalReportObserver`, `#[ObservedBy(AnalyticalReportObserver::class)]`, `implements ShouldHandleEventsAfterCommit`:

`forceDeleted(AnalyticalReport $report): void` — se `disk` e `path` estiverem preenchidos, `Storage::disk($report->disk)->delete($report->path)`. Path vazio ou arquivo ausente: no-op (o `delete` do Flysystem não estoura se o arquivo não existe; ainda assim não chamar quando `path` é null).

**Soft deletes:** sim. Cascade de filhos: nenhum (não tem filhos). A limpeza do `.xlsx` é no `forceDeleted`. Prune diário, retenção 30 dias (§15).

### 3.3 `PaymentRequest` (só scopes)

`scopeOpen()`: `whereIn($query->qualifyColumn('status'), [Requested, Launched])`.

`scopeForCompany(Company|string $company)`: `whereIn($query->qualifyColumn('branch_id'), Branch::withTrashed()->where('company_id', $id)->select('id'))`.

`scopeForBranch` existente: trocar `'branch_id'` por `$query->qualifyColumn('branch_id')`. O valor comparado não muda. Fora de um join o SQL continua equivalente.

### 3.4 Sem outros models

Não há entidade de preferência de filtro. A sessão do `HasFilters` guarda os filtros do dashboard.

---

## 4. Enums

Padrão de `PaymentRequestStatus`: `string` backed, `HasLabel`, `HasColor`, `HasIcon`, labels em `__('enums.*')`. `getIcon()` devolve string `heroicon-o-*`.

### `AnalyticalReportStatus`

```php
enum AnalyticalReportStatus: string implements HasLabel, HasColor, HasIcon
{
    case Queued = 'queued';
    case Generating = 'generating';
    case Generated = 'generated';
    case Failed = 'failed';
}
```

| Caso | Cor | Ícone |
|------|-----|-------|
| Queued | gray | `heroicon-o-clock` |
| Generating | info | `heroicon-o-arrow-path` |
| Generated | success | `heroicon-o-check-circle` |
| Failed | danger | `heroicon-o-x-circle` |

`canTransitionTo(self $to): bool`:

| De | Para |
|----|------|
| queued | generating, failed, queued (retry de preso) |
| generating | generated, failed, queued (retry de preso) |
| failed | queued |
| generated | nenhuma |

`isInProgress(): bool` — queued ou generating. `isTerminal(): bool` — só generated (o arquivo publicado não volta). `failed` não é terminal no enum porque o retry existe; para **parar o polling** da View, usar `isInProgress()`, não `isTerminal()`.

`inProgressValues(): list<string>` — `['queued', 'generating']`.

### `ReportDateBasis`

| Caso | Valor | Ícone |
|------|-------|-------|
| DueDate | `due_date` | `heroicon-o-calendar` |
| SettlementDate | `settlement_date` | `heroicon-o-banknotes` |
| RequestDate | `request_date` | `heroicon-o-inbox-arrow-down` |

Cor `gray` nos três. Sem transição.

### `PaymentDueSituation`

| Caso | Valor | Cor |
|------|-------|-----|
| Paid | `paid` | success |
| Upcoming | `upcoming` | warning |
| Overdue | `overdue` | danger |

```php
public static function for(PaymentRequest $paymentRequest, CarbonImmutable $today): self
```

`Settled` → `Paid`. Aberto (`requested`/`launched`) com `due_date < $today` (comparação `Y-m-d`) → `Overdue`. Caso contrário → `Upcoming`. Usado na coluna "Situação" do Excel. Não entra no dashboard (lá a agregação é SQL).

---

## 5. Exceções de domínio

`App\Exceptions\AnalyticalReportException extends BusinessException`. Mensagem de usuário em `analytical_reports.errors.*` via `getUserMessage()`. Sem path, SQL ou stack na mensagem.

| Factory | Quando |
|---------|--------|
| `unauthorized()` | Ator sem `create` / `download` / `retry` (belt da Action de domínio) |
| `invalidPeriod()` | Início ou fim ausente, ou início > fim, na solicitação |
| `noMatchingRequests()` | Contagem zero na solicitação. Nada é criado |
| `tooManyRows(int $found, int $max)` | Acima de `max_rows`, na solicitação (lança, não cria) ou no job (`markFailed`, não lança) |
| `tooManyInProgress(int $max)` | O solicitante já tem `max` relatórios `queued`/`generating` |
| `requesterUnavailable()` | No job, `creator` nulo ou inativo ou sem `create` |
| `notDownloadable()` | Download com status diferente de `generated` |
| `fileMissing()` | `Storage::exists` falso. Log `error` com `analytical_report_id` |
| `notRetryable()` | Retry em `generated`, em andamento que não está stale, ou update condicional com zero linhas |
| `storageWriteFailed(Throwable $previous)` | Upload do `.xlsx` falhou. **Lança** (infra → retry do job) |

O dashboard não lança exceção de domínio. Filtro inválido vira default ou é ignorado (§6.1). A rota de anexo responde 404/403 pelo binding e pelo `Gate`, sem esta exceção.

---

## 6. Camadas de aplicação

Filament, widgets e Actions não falam com `Storage` nem com OpenSpout. Isso fica no Service e na Integration.

### 6.1 DTOs (`App\DTOs`, `final readonly`)

**`DashboardFilterData`**

| Campo | Tipo |
|-------|------|
| companyId | `?string` |
| branchId | `?string` |
| periodStart | `CarbonImmutable` |
| periodEnd | `CarbonImmutable` |
| today | `CarbonImmutable` |

`fromPageFilters(array $filters, User $user, CarbonImmutable $today): self`

- Data ausente ou inválida → primeiro e último dia do mês de `$today` em `America/Sao_Paulo`.
- `periodStart > periodEnd` → troca os dois.
- `company_id` / `branch_id` que não sejam UUID → `null`.
- Não consulta o banco aqui para "corrigir" filial de outra empresa. A combinação inválida chega ao Service e a query devolve zero (§0.7 item 5). O parâmetro `$user` fica na assinatura porque a sanitização é o único portão da URL; a visibilidade continua no Service, não neste DTO.
- `cacheHash(): string` — md5 de `companyId`, `branchId`, `periodStart` (`Y-m-d`), `periodEnd` (`Y-m-d`) e `today` (`Y-m-d`).
- `isDefaultMonth(): bool` — período igual ao mês corrente de `today`. O card de pago usa "no mês" nesse caso e "no período" nos demais.

**`DashboardMetrics`** — todos os valores monetários `string` com 2 casas: `paidAmount`, `paidCount`, `openAmount`, `openCount`, `requestedCount`, `launchedCount`, `upcomingAmount`, `upcomingCount`, `overdueAmount`, `overdueCount`. `toArray()` / `fromArray()` para o cache. Contagens são `int`.

**`DashboardSeries`** — `labels: list<string>`, `paid` / `upcoming` / `overdue: list<string>` (mesma ordem, 2 casas). `isEmpty(): bool` quando não há categoria ou todas as somas são `"0.00"`. `toArray()` / `fromArray()`.

**`AnalyticalReportData`** — `companyId: ?string`, `branchId: ?string`, `dateBasis: ReportDateBasis`, `periodStart` / `periodEnd: CarbonImmutable`, `statuses: ?list<PaymentRequestStatus>`. `fromArray()`, `fromModel(AnalyticalReport)`, `toArray()`. `fromModel` reidrata os valores do JSON para o enum.

### 6.2 `DashboardMetricsService`

```php
public function stats(DashboardFilterData $filters, User $user): DashboardMetrics
public function byBranch(DashboardFilterData $filters, User $user): DashboardSeries
public function byCostCenter(DashboardFilterData $filters, User $user): DashboardSeries
public function flush(): void
```

`remember()`: se `config('rjet.dashboard.cache_ttl_seconds')` for `0`, calcula direto. Senão `Cache::remember` na chave:

```
dashboard:v{version}:{scope}:{block}:{hash}
```

- `version` = `(int) Cache::get('dashboard:version', 1)`.
- `scope` = `all` quando `$user->role->seesAllBranches()`; senão `branches:{md5}` dos ids de filial do usuário, ordenados. Sem filial vinculada, o md5 de lista vazia ainda isola o cliente.
- `block` = `stats` | `by_branch` | `by_cost_center`.
- `hash` = `$filters->cacheHash()`.

`flush()`: `DB::afterCommit(function () { Cache::add('dashboard:version', 1); Cache::increment('dashboard:version'); })`. Fora de transação o callback roda na hora. Chaves velhas expiram pelo TTL.

**Base em aberto** (`openBaseQuery`): `PaymentRequest::query()->open()->visibleTo($user)` + `forCompany` / `forBranch` quando o filtro vier preenchido. Soft delete de PR já sai pelo global scope.

Uma agregação condicional (PostgreSQL e SQLite, sem `date_trunc`):

- pago **não** está nesta query;
- `open`: `due_date <= period_end`;
- `upcoming`: `due_date >= today` e `due_date <= period_end`;
- `overdue`: `due_date < today` e `due_date <= period_end`;
- `requested` / `launched`: o mesmo teto `due_date <= period_end`, partido por status.

`SUM(net_amount)` e `COUNT(*)` com `CASE`. Normalizar a soma na borda:

- string numérica (PostgreSQL) → `bcadd($value, '0', 2)`;
- float (SQLite) → `number_format(round((float) $value, 2), 2, '.', '')`.

**Base pago** (`paidBaseQuery`): partir de `PaymentRequest::query()->visibleTo($user)` (já exclui `payment_requests.deleted_at`) + filtros de empresa/filial. Joins:

```sql
join payment_settlement_items
  on payment_settlement_items.payment_request_id = payment_requests.id
 and payment_settlement_items.released_at is null
join payment_settlements
  on payment_settlements.id = payment_settlement_items.payment_settlement_id
where payment_settlements.status = 'settled'
  and payment_settlements.deleted_at is null
  and payment_settlements.settlement_date between :start and :end
```

Valor = `SUM(payment_settlement_items.amount)`, contagem = `COUNT(payment_settlement_items.id)`. Toda coluna qualificada: `status`, `branch_id`, `deleted_at`, `created_at` e `id` existem nas duas tabelas. O unique parcial de item ativo impede duplicar o valor. **Não** filtrar `payment_requests.status = settled` e **não** somar `net_amount`.

**Gráficos:** a mesma base, `GROUP BY payment_requests.branch_id` ou `payment_requests.cost_center_id`, com as três medidas (pago, a vencer, vencidos). Rótulos numa segunda consulta, `withTrashed()`. Ordenar pelo total (pago + a vencer + vencidos) desc. Corte de §0.7 item 9. Rótulo de filial: `branches.name`. Rótulo de centro de custo: `{code} — {name}`; sem filtro de filial, acrescentar ` ({branch.name})`. Filial ou centro de custo ausente (hard delete): rótulo `—`, sem derrubar a série. Chave de tradução do balde: `dashboard.charts.others`.

### 6.3 `AnalyticalReportService`

```php
public function countMatching(AnalyticalReportData $data, User $user): int
public function request(AnalyticalReportData $data, User $user): AnalyticalReport
public function generate(AnalyticalReport $report): void
public function markFailed(AnalyticalReport $report, string $userReason): void
public function retry(AnalyticalReport $report, User $user): AnalyticalReport
public function download(AnalyticalReport $report, User $user): StreamedResponse
public function rowsQuery(AnalyticalReportData $data, User $user): Builder
```

`rowsQuery`: `PaymentRequest::query()->visibleTo($user)` + empresa/filial + statuses (`whereIn` qualificado; null = sem filtro) + base de data. **Sem** `orderBy`, **sem** eager load, **sem** join.

| date_basis | Filtro |
|------------|--------|
| `due_date` | `whereDate(qualify('due_date'), '>=', start)` e `<= end`, strings `Y-m-d` |
| `settlement_date` | `whereHas('activeSettlementItem.settlement', ...)` com status `settled` e `settlement_date` no período. Subquery, sem join na query principal |
| `request_date` | `created_at >=` início do dia de `period_start` em São Paulo convertido para UTC, `created_at <` início do dia seguinte a `period_end` em São Paulo convertido para UTC. Intervalo semiaberto. Nunca `BETWEEN ... 23:59:59` |

`request()`:

1. `Gate::forUser($user)->authorize('create', AnalyticalReport::class)` falhou → `unauthorized()` (a Action também checa antes).
2. Período inválido → `invalidPeriod()`.
3. Contagem de `inProgress` do `created_by` ≥ `max_in_progress_per_user` → `tooManyInProgress`.
4. `countMatching` = 0 → `noMatchingRequests()`. `>` `max_rows` → `tooManyRows`.
5. Filial preenchida cuja `company_id` não é a empresa escolhida (quando a empresa também veio) → `noMatchingRequests()` (não grava combinação incoerente).
6. Normalizar `statuses`: valores do enum, únicos, ordenados; vazio → `null`.
7. `AnalyticalReport::create` com status `queued`, `disk = config('rjet.reports.disk')`, filtros e blameable.
8. `event(new AnalyticalReportRequested($report))` — o evento é `ShouldDispatchAfterCommit`, então o listener só enfileira depois do commit.

`markFailed`: update condicional `whereKey` e `where('status', '!=', generated)`. Zero linhas → return sem evento. Senão grava `failed`, `failure_reason`, e dispara `AnalyticalReportGenerationFailed`.

`retry`: exige `isRetryable()` e Policy `retry`. Update condicional `where id` + `status` lido + `updated_at` lido → `queued`, `failure_reason = null`. Zero linhas → `notRetryable()`. Senão `AnalyticalReportRequested`. Não revalida o teto de 3 (a linha já existe). Não apaga `path` de uma geração anterior que falhou no meio: a próxima geração sobrescreve o mesmo path.

`download`: Policy `download`, `isDownloadable()`, `Storage::disk($disk)->exists($path)` senão `fileMissing()`. `Storage::disk($disk)->download($path, $filename)`. Evento `AnalyticalReportDownloaded` com o usuário atual.

`generate()` está em §6.5.

### 6.4 Actions de domínio (`App\Actions\Report`, invocáveis, `final`)

| Classe | Faz |
|--------|-----|
| `RequestAnalyticalReportAction` | Sem `create` → `unauthorized()`. Senão `AnalyticalReportService::request` |
| `RetryAnalyticalReportAction` | Sem `retry` → `unauthorized()`. Senão `retry` |
| `DownloadAnalyticalReportAction` | Sem `download` → `unauthorized()`. Senão `download` |
| `OpenReportAttachmentAction` | §6.7 |

### 6.5 `GenerateAnalyticalReportJob`

`App\Jobs\Report\GenerateAnalyticalReportJob implements ShouldQueue`.

```php
public int $tries = 3;
public int $timeout = 75;
public array $backoff = [10, 30, 60];

public function __construct(public AnalyticalReport $report)
{
    $this->onQueue((string) config('rjet.reports.queue'));
}
```

Conexão default (não chamar `onConnection`). Sem `middleware()`.

`handle(AnalyticalReportService $service): void` → `$service->generate($this->report)`.

`failed(?Throwable $exception): void`: recarrega o model. Se já está `generated`, return. Senão `markFailed` com `$exception` `BusinessException` → `getUserMessage()`, ou `__('analytical_reports.messages.generation_interrupted')`. Log `error` com `analytical_report_id`, `exception`, `exception_class`.

**`generate()`, nesta ordem:**

1. Recarrega. Status fora de `{queued, generating}` → return.
2. Update condicional `whereIn(status, [queued, generating])` → `generating`, `started_at` se null, `updated_at = now()`.
3. `creator` nulo, inativo ou sem `create` → `markFailed(requesterUnavailable)` e return. Não lança.
4. `AnalyticalReportData::fromModel`. Snapshot:

```php
$ids = $service->rowsQuery($data, $creator)
    ->orderBy('payment_requests.due_date')
    ->orderBy('payment_requests.created_at')
    ->orderBy('payment_requests.id')
    ->pluck('payment_requests.id');
```

Contagem = `$ids->count()`. Acima de `max_rows` → `markFailed(tooManyRows)` e return. Lista vazia (os dados mudaram depois da solicitação) segue e gera só o cabeçalho.

5. `N` = colunas de anexo, teto `rjet.reports.max_attachment_columns` (10):

```sql
select max(cnt) from (
  select attachable_id, count(*) as cnt
  from attachments
  where attachable_type = 'payment_request'
    and deleted_at is null
    and attachable_id in ( /* subquery de rowsQuery()->select('payment_requests.id'), sem order */ )
  group by attachable_id
) as counts
```

`DB::query()->fromSub(...)`. Não usar `whereIn` com os 20 mil UUIDs (limite de bindings do SQLite).

6. `SpreadsheetWriter` num arquivo de `tempnam`. Aba 1, nome `__('analytical_reports.sheet.requests')`. Para cada `chunk(500)` dos IDs, na ordem do snapshot:

```php
PaymentRequest::query()->whereKey($chunk)->with([
    'branch' => fn ($q) => $q->withTrashed(),
    'branch.company' => fn ($q) => $q->withTrashed(),
    'supplier' => fn ($q) => $q->withTrashed(),
    'costCenter' => fn ($q) => $q->withTrashed(),
    'costCenter.branch' => fn ($q) => $q->withTrashed(),
    'appropriation' => fn ($q) => $q->withTrashed(),
    'bankDetails.bank' => fn ($q) => $q->withTrashed(),
    'creator',
    'attachments',
    'activeSettlementItem.settlement.branchBankAccount',
])->get()->keyBy('id');
```

Escrever na ordem do chunk, não na ordem do `get()`. Aba 2, nome `__('analytical_reports.sheet.attachments')`, segunda passada com eager load só de `branch`, `supplier` (`withTrashed`) e `attachments`.

Datas de calendário (`due_date`, `settlement_date`) como `Y-m-d` na célula de data. Instantes (`created_at`, `settled_at`, `attachments.created_at`) convertidos para `America/Sao_Paulo`. Locale dos rótulos: `config('app.locale')` (labels de enum e cabeçalhos já passam por `__()`).

URL do anexo:

```php
$relative = URL::signedRoute(
    'filament.admin.report-attachments.open',
    ['attachment' => $attachment],
    absolute: false,
);
$absolute = rtrim((string) config('app.url'), '/').$relative;
```

Sem terceiro argumento de expiração. Link da PR: `PaymentRequestResource::getUrl('view', ['record' => $paymentRequest])`.

7. `Storage::disk($report->disk)->putFileAs($directory, $tempFile, $basename)`. Diretório `reports/{Y}/{m}` a partir do `created_at` do relatório (estável entre retries). Falha → `storageWriteFailed` (lança).
8. Transação: update condicional `where id and status = generating` → `generated`, `path`, `filename`, `size`, `rows_count`, `attachments_count`, `generated_at`. Zero linhas → return **sem** evento e **sem** apagar o arquivo (o path é o da publicação que venceu).
9. Depois do commit: `AnalyticalReportGenerated`.
10. `finally`: apaga o temporário local.

**Nome do arquivo:** `{prefix}_{Ymd início}-{Ymd fim}_{YmdHi created_at em SP}.xlsx`. Prefixo = `__('analytical_reports.filename_prefix')` (`relatorio-analitico`), só ASCII.

### 6.6 Colunas do Excel

Cabeçalhos em `analytical_reports.columns.*`. Dados de usuário (nomes, notas, documentos, chaves) sempre em célula de texto. Número com formato `#,##0.00`. Data com `dd/mm/yyyy`. Data/hora com `dd/mm/yyyy hh:mm`. Link só via `HYPERLINK("url","rótulo")`: aspas do rótulo duplicadas, rótulo truncado em 255. URL absoluta com mais de 255 caracteres vira texto e gera `Log::warning`.

**Aba Solicitações** (uma linha por PR):

| # | Cabeçalho | Origem |
|---|-----------|--------|
| 1 | ID da solicitação | `id` |
| 2 | Abrir solicitação | hyperlink da View |
| 3 | Empresa | `branch.company.name` |
| 4 | Filial | `branch.name` |
| 5 | CNPJ da filial | `branch.document` |
| 6 | Status | `status->getLabel()` |
| 7 | Situação | `PaymentDueSituation::for($pr, $today)->getLabel()` com o "hoje" da geração |
| 8 | Data da solicitação | `created_at` em SP |
| 9 | Vencimento | `due_date` |
| 10 | Solicitante | `creator.name` |
| 11 | Tipo de pessoa | `supplier.person_type` (label do enum, se houver) |
| 12 | CPF/CNPJ do fornecedor | `supplier.document` |
| 13 | Fornecedor | `supplier.name` |
| 14 | Razão social do fornecedor | `supplier.legal_name` |
| 15 | Centro de custo | `code — name` |
| 16 | Apropriação | `code — name`, vazio se null |
| 17 | Forma de pagamento | label de `payment_method` |
| 18 | Tipo de depósito | label de `bankDetails.deposit_type` |
| 19 | Valor bruto | `gross_amount` |
| 20 | Descontos/deduções | `discount_amount` |
| 21 | Valor líquido | `net_amount` |
| 22 | Linha digitável / código de barras | `digitable_line` ou, se vazio, `barcode` |
| 23 | Chave PIX | `{pix_key_type}: {pix_key}`; se só houver `pix_qr_code`, o texto `QR Code` (`analytical_reports.pix_qr_code`) |
| 24 | Banco do favorecido | `bank.code — name` |
| 25 | Agência | `agency` + `-` + `agency_digit` quando houver dígito |
| 26 | Conta | `account_number-account_digit` e o tipo entre parênteses quando houver |
| 27 | Titular | `holder_name` |
| 28 | CPF/CNPJ do titular | `holder_document` |
| 29 | Situação da baixa | label do status da baixa ativa; vazio sem baixa |
| 30 | Data de baixa | `settlement_date` |
| 31 | Valor baixado | `activeSettlementItem.amount` |
| 32 | Conta pagadora | `{bank_code} · Ag {agency} · CC {account}-{digit}` a partir de `branchBankAccount` |
| 33 | Baixa confirmada em | `settled_at` em SP |
| 34 | Observações | `notes` |
| 35 | Qtd. anexos | contagem dos attachments não excluídos |
| 36… | Anexo 1 … Anexo N | hyperlink + `displayName()`, ordem `sort_order`, no máximo `N` |

**Aba Anexos** (uma linha por anexo, sem teto de N): ID da solicitação, Filial, Fornecedor, Vencimento, Tipo do anexo, Nome (`displayName()`), Tipo MIME, Tamanho (KB), Enviado em (SP), Abrir (hyperlink).

### 6.7 Rota de anexo

`OpenReportAttachmentController::__invoke(Attachment $attachment)` só chama `OpenReportAttachmentAction`.

Registro em `AdminPanelProvider`:

```php
->authenticatedRoutes(function (): void {
    Route::get('/report-attachments/{attachment}', OpenReportAttachmentController::class)
        ->middleware('signed:relative')
        ->name('report-attachments.open');
})
```

Nome final: `filament.admin.report-attachments.open`. Path: `/admin/report-attachments/{attachment}`. O `Authenticate` do painel já envolve o grupo; guest cai no login e volta por `redirect()->intended()`.

`OpenReportAttachmentAction`:

1. Binding padrão: anexo soft-deleted → 404. PR excluída já derruba o anexo em cascata → 404.
2. `attachable_type !== 'payment_request'` (morph map) → 404.
3. `Gate::authorize('view', $attachment)` → `AttachmentPolicy` → `PaymentRequestPolicy` → `isVisibleTo`. Falha → 403.
4. `$attachment->temporaryUrl((int) config('rjet.reports.attachment_redirect_ttl_minutes'))`. Não nulo → `redirect()->away($url)`. Nulo → `Storage::disk($attachment->disk)->response($attachment->path, $attachment->displayName())` inline.
5. `Log::info` com `attachment_id`, `payment_request_id`, `user_id`, `ip`.

### 6.8 Integration `App\Integrations\Spreadsheet`

**`SpreadsheetWriter`** (interface):

```php
public function open(string $absolutePath): void;
public function addSheet(string $name, array $headers): void;
public function addRow(array $cells): void;
public function close(): void;
```

`$cells` aceita `SpreadsheetCell`, escalar ou null. Escalar e null viram texto.

**`SpreadsheetCell`** (`final readonly`): `text()`, `money()`, `date()`, `dateTime()`, `link(string $url, string $label)`. `link()` duplica aspas, corta o rótulo em 255 e, se `strlen($url) > 255`, devolve texto (a URL crua) e registra warning. Quem chama não monta `FormulaCell`.

**`OpenSpoutXlsxSpreadsheetWriter`:** `OpenSpout\Writer\XLSX\Writer`. Cabeçalho com estilo negrito. Ao abrir a aba, `$writer->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(2))`. Células: `StringCell` (nunca fórmula), `NumericCell` com `#,##0.00`, `DateTimeCell` com `dd/mm/yyyy` ou `dd/mm/yyyy hh:mm`, `FormulaCell` só para o `HYPERLINK` produzido por `link()`.

Bind em `AppServiceProvider::register()`: `SpreadsheetWriter::class → OpenSpoutXlsxSpreadsheetWriter::class`.

### 6.9 Config

Acrescentar em `config/rjet.php`:

| Chave | Env | Default |
|-------|-----|---------|
| `dashboard.cache_ttl_seconds` | `RJET_DASHBOARD_CACHE_TTL` | `300` |
| `dashboard.chart_max_categories` | — | `12` |
| `reports.disk` | `RJET_REPORTS_DISK`, fallback `FILESYSTEM_DISK` | `local` |
| `reports.directory` | — | `reports` |
| `reports.queue` | `RJET_REPORTS_QUEUE` | `default` |
| `reports.max_rows` | `RJET_REPORTS_MAX_ROWS` | `20000` |
| `reports.max_in_progress_per_user` | — | `3` |
| `reports.max_attachment_columns` | — | `10` |
| `reports.retention_days` | `RJET_REPORTS_RETENTION_DAYS` | `30` |
| `reports.attachment_redirect_ttl_minutes` | — | `5` |
| `reports.stale_after_minutes` | — | `30` |

`.env.example`:

```
RJET_REPORTS_DISK=
RJET_REPORTS_QUEUE=default
RJET_REPORTS_MAX_ROWS=20000
RJET_REPORTS_RETENTION_DAYS=30
RJET_DASHBOARD_CACHE_TTL=300
```

`phpunit.xml`: nada novo (`QUEUE_CONNECTION=sync`, `CACHE_STORE=array` já estão).

`routes/console.php`:

```php
Schedule::command('model:prune', ['--model' => [\App\Models\AnalyticalReport::class]])
    ->dailyAt('02:00')
    ->withoutOverlapping();
```

---

## 7. Página Dashboard

```
Page: App\Filament\Pages\Dashboard
  Estende: Filament\Pages\Dashboard (alias BaseDashboard)
  Trait: HasFiltersForm
  Arquivo: app/Filament/Pages/Dashboard.php (final)
  Rota: herda $routePath = '/' → /admin
  Navigation: sem grupo; ícone default (home); sort default -2
  Título: getTitle() → __('dashboard.title')
  Acesso: qualquer usuário que entra no painel. O escopo é o visibleTo dentro do Service
  Colunas: getColumns() → ['md' => 2]
```

`filtersForm(Schema $schema): Schema` devolve o schema com **um** componente:

```
Section (sem heading, columnSpanFull, columns ['default' => 1, 'md' => 2, 'xl' => 4])
├── Select company_id
│     label __('dashboard.filters.company')
│     options: Operador/Adm → Company::active() ordenado por name
│              Cliente → Company::query()->whereHas('branches', fn ($q) => $q->visibleTo($user))
│     searchable, native(false), live
│     afterStateUpdated: Set branch_id = null
│     placeholder __('dashboard.filters.all')
├── Select branch_id
│     label __('dashboard.filters.branch')
│     options: Branch::visibleTo($user)->when(company_id)->orderBy('name')
│     searchable, native(false)
│     placeholder __('dashboard.filters.all')
├── DatePicker period_start
│     label __('dashboard.filters.period_start')
│     native(false), displayFormat('d/m/Y')
│     default: início do mês corrente em America/Sao_Paulo
└── DatePicker period_end
      label __('dashboard.filters.period_end')
      native(false), displayFormat('d/m/Y')
      minDate(fn (Get $get) => $get('period_start'))
      default: fim do mês corrente em America/Sao_Paulo
```

Não chamar `statePath('filters')` de novo. Não colocar `required` nos filtros: vazio significa "todas" / default aplicado no DTO.

`getHeaderActions()`:

```php
return [
    RequestAnalyticalReportAction::make()
        ->fillForm(fn (): array => [
            'company_id' => $this->filters['company_id'] ?? null,
            'branch_id' => $this->filters['branch_id'] ?? null,
            'period_start' => $this->filters['period_start'] ?? today(config('app.timezone'))->startOfMonth()->toDateString(),
            'period_end' => $this->filters['period_end'] ?? today(config('app.timezone'))->endOfMonth()->toDateString(),
            'date_basis' => ReportDateBasis::DueDate->value,
        ]),
];
```

A action já se esconde quando falta `create`.

Widgets (descobertos, ordem por `$sort`):

| Widget | sort | columnSpan |
|--------|------|------------|
| `AccountWidget` (já registrado) | default `-1` | o que o Filament já define |
| `PaymentOverviewStats` | 1 | `full` (default da classe base) |
| `PaymentsByBranchChart` | 2 | `1` |
| `PaymentsByCostCenterChart` | 3 | `1` |

---

## 8. Widgets

Os três são `final`, usam `InteractsWithPageFilters` e leem `DashboardFilterData::fromPageFilters($this->pageFilters ?? [], $user, today(config('app.timezone')))`.

### 8.1 `PaymentOverviewStats`

`extends StatsOverviewWidget`. `$sort = 1`. `$pollingInterval = '60s'`. Não desligar o lazy.

`getStats()` chama `DashboardMetricsService::stats(...)`.

| Stat | Label | Valor | Descrição | Cor | Ícone |
|------|-------|-------|-----------|-----|-------|
| paid | `dashboard.stats.paid_month` se `isDefaultMonth()`, senão `paid_period` | `Number::currency((float) $paidAmount, 'BRL', 'pt_BR')` | `dashboard.stats.paid_description` com `:from`, `:until` (`d/m`) e `:count` | success | `Heroicon::OutlinedBanknotes` |
| pending | `dashboard.stats.pending` | BRL de `openAmount` | `pending_description` com `:requested` e `:launched` | warning | `Heroicon::OutlinedClock` |
| upcoming | `dashboard.stats.upcoming` | BRL de `upcomingAmount` | se `periodEnd < today`: `period_closed`; senão `upcoming_description` com `:count` e `:until` | info | `Heroicon::OutlinedCalendarDays` |
| overdue | `dashboard.stats.overdue` | BRL de `overdueAmount` | `overdue_description` com `:count` | danger | `Heroicon::OutlinedExclamationTriangle` |

O float aqui é só formatação. A string de 2 casas é a fonte.

### 8.2 `PaymentsByBranchChart`

`extends ChartWidget`. `$sort = 2`. `getType(): string` retorna `'bar'`. `$pollingInterval = null`. `$maxHeight = '320px'`.

- `getHeading()` → `__('dashboard.charts.by_branch')`.
- `getDescription()` → período `d/m/Y – d/m/Y` a partir do `DashboardFilterData` já sanitizado.
- `getEmptyStateHeading()` → `__('dashboard.charts.empty')`.
- `getData()`: `byBranch(...)`. Se `isEmpty()`, retornar `[]`. Senão:

```php
return [
    'labels' => $series->labels,
    'datasets' => [
        ['label' => __('dashboard.charts.paid'), 'data' => /* floats */, 'backgroundColor' => '#10B981'],
        ['label' => __('dashboard.charts.upcoming'), 'data' => /* floats */, 'backgroundColor' => '#F59E0B'],
        ['label' => __('dashboard.charts.overdue'), 'data' => /* floats */, 'backgroundColor' => '#EF4444'],
    ],
];
```

`getOptions()` devolve `RawJs` com tooltip e ticks formatando BRL (`pt-BR`, moeda `BRL`). Não incluir `indexAxis`.

### 8.3 `PaymentsByCostCenterChart`

Igual ao de filial, com:

- `$sort = 3`;
- `getHeading()` → `__('dashboard.charts.by_cost_center')`;
- dados de `byCostCenter()`;
- `getOptions()` inclui `indexAxis: 'y'` além da formatação BRL.

---

## 9. Filament Resource: AnalyticalReport

### 9.1 Resource

```
Resource: AnalyticalReportResource
  Command: php artisan make:filament-resource AnalyticalReport --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\AnalyticalReports\AnalyticalReportResource
  Icon: Heroicon::OutlinedDocumentChartBar
  Group: __('navigation.groups.reports')
  Sort: 10
  Pages: ListAnalyticalReports, ViewAnalyticalReport
  Relation managers: nenhum
```

Record title: `filename`, com fallback no label traduzido quando o arquivo ainda não existe (`getRecordTitle` ou `recordTitleAttribute` + placeholder na coluna; a View pode usar o período como título se `filename` for null).

### 9.2 Form (`AnalyticalReportForm::components(): array`)

O modal da action usa este array. O `configure(Schema)` do resource, se o scaffold exigir, só envolve os mesmos components. Não há página Create.

| Campo | Componente | Validação | Config |
|-------|------------|-----------|--------|
| date_basis | `Select` | required | `->options(ReportDateBasis::class)->default(ReportDateBasis::DueDate)->native(false)` |
| period_start | `DatePicker` | required | `native(false)`, `displayFormat('d/m/Y')`, default início do mês SP |
| period_end | `DatePicker` | required, `after_or_equal:period_start` | `native(false)`, `displayFormat('d/m/Y')`, default fim do mês SP |
| company_id | `Select` | nullable | `Company::active()`, searchable, live, `afterStateUpdated` zera `branch_id` |
| branch_id | `Select` | nullable | `Branch::visibleTo($user)->when(company_id)`, searchable |
| statuses | `Select` | nullable, array | `->multiple()->options(PaymentRequestStatus::class)->placeholder(__('analytical_reports.fields.all_statuses'))` |

Labels: `analytical_reports.fields.*`. Operador e Adm veem todas as empresas ativas; a Policy já impede o Cliente de abrir o modal. O Service ainda aplica `visibleTo` no job.

### 9.3 Infolist

`columns(1)` no schema. Sections com `columns(2)`.

**Section** `analytical_reports.sections.filters`

| Entry | Componente |
|-------|------------|
| date_basis | `TextEntry->badge()` |
| period_start, period_end | `TextEntry->date('d/m/Y')` |
| company.name, branch.name | `TextEntry->placeholder(__('analytical_reports.fields.all'))` |
| statuses | `TextEntry->badge()->placeholder(__('analytical_reports.fields.all_statuses'))`. State: labels dos valores guardados no JSON |

**Section** `analytical_reports.sections.result`

| Entry | Componente |
|-------|------------|
| status | `TextEntry->badge()` |
| rows_count, attachments_count | `TextEntry` |
| filename | `TextEntry->placeholder('—')` |
| generated_at | `TextEntry->dateTime('d/m/Y H:i')` |
| failure_reason | `TextEntry->color('danger')->visible(fn ($record) => $record->status === Failed)->columnSpanFull()` |

**Section** `common.sections.audit`

`creator.name`, `created_at` (`dateTime('d/m/Y H:i')`), `started_at` (`dateTime`). Eager load de `creator`, `company`, `branch` no resource.

### 9.4 Table

| Coluna | Tipo | Searchable | Sortable | Formatação |
|--------|------|------------|----------|------------|
| created_at | TextColumn | — | sim | `dateTime('d/m/Y H:i')` |
| creator.name | TextColumn | sim | — | — |
| date_basis | TextColumn | — | — | badge |
| period | TextColumn | — | — | state `d/m/Y – d/m/Y` a partir de `period_start` e `period_end` |
| company.name | TextColumn | — | — | placeholder "Todas", `toggleable()` |
| branch.name | TextColumn | — | — | placeholder "Todas", `toggleable()` |
| status | TextColumn | — | sim | badge |
| rows_count | TextColumn | — | — | numeric |
| generated_at | TextColumn | — | sim | `dateTime('d/m/Y H:i')`, `toggleable(isToggledHiddenByDefault: true)` |

Filtros:

- `SelectFilter::make('status')->options(AnalyticalReportStatus::class)`
- `Filter::make('mine')` toggle `__('analytical_reports.filters.mine')` → `where created_by = user`
- `TrashedFilter` visível só se `Filament::auth()->user()?->isAdm()`

`defaultSort('created_at', 'desc')`.

```php
->poll(fn (): ?string => AnalyticalReport::query()->inProgress()->exists() ? '5s' : null)
```

**Record actions:** `ViewAction`, `DownloadAnalyticalReportAction`, `RetryAnalyticalReportAction`, `DeleteAction`, `RestoreAction`.

**Toolbar:** `BulkActionGroup` com `DeleteBulkAction`. A Policy restringe delete ao Adm.

Sem `EditAction` e sem `CreateAction` na tabela.

### 9.5 Pages

**ListAnalyticalReports:** header `RequestAnalyticalReportAction::make()` (defaults do próprio form, sem pré-preenchimento do dashboard).

**ViewAnalyticalReport:** header `DownloadAnalyticalReportAction`, `RetryAnalyticalReportAction`, `DeleteAction`, `RestoreAction`, `ForceDeleteAction` (Adm, `requiresConfirmation`). O force delete é o caminho de UI do teste de arquivo órfão; a lista não oferece force delete para não apagar o `.xlsx` por engano.

```php
public function getPollingInterval(): ?string
{
    return $this->getRecord()->status->isInProgress() ? '5s' : null;
}
```

### 9.6 Actions Filament

| Action | Visível | Efeito |
|--------|---------|--------|
| `RequestAnalyticalReportAction` | `can('create', AnalyticalReport::class)` | Modal com `->schema(AnalyticalReportForm::components())`, label `__('analytical_reports.actions.request')`. Submit monta `AnalyticalReportData` e chama `RequestAnalyticalReportAction` de domínio. `BusinessException` → toast danger + `halt`. Sucesso → toast `__('analytical_reports.messages.queued')` com ação para `AnalyticalReportResource::getUrl('view', ...)` |
| `DownloadAnalyticalReportAction` | `can('download', $record)` | `abort_unless` + retorno do `StreamedResponse` de `DownloadAnalyticalReportAction` |
| `RetryAnalyticalReportAction` | `can('retry', $record)` | `requiresConfirmation()`, chama `RetryAnalyticalReportAction` de domínio, mesmo tratamento de `BusinessException` |

Ícones: `Heroicon::OutlinedDocumentChartBar` (solicitar), `Heroicon::OutlinedArrowDownTray` (baixar), `Heroicon::OutlinedArrowPath` (retry).

---

## 10. Authorization / Policies

`AnalyticalReportPolicy`. Registrar em `POLICIES`.

Helper interno: staff = `$user->role->seesAllBranches()` (Operador e Adm). Adm = `$user->isAdm()`.

| Método | Regra |
|--------|-------|
| viewAny | staff |
| view | staff |
| create | staff |
| update | `false` |
| delete, deleteAny | Adm |
| restore, restoreAny | Adm |
| forceDelete, forceDeleteAny | Adm |
| download | staff e `$report->isDownloadable()` |
| retry | staff e `$report->isRetryable()` |

Cliente: `false` em todos. Operador e Adm enxergam **todos** os relatórios, não só os próprios (o conteúdo já é visível para eles).

`AttachmentPolicy` e `PaymentRequestPolicy`: sem mudança. A rota de anexo reutiliza `view` do anexo.

---

## 11. State transitions

```
queued → generating     (job, update condicional)
queued → failed         (markFailed: solicitante indisponível, tooManyRows no job)
queued → queued         (retry de relatório preso / stale)
generating → generated  (publicação condicional; só esta dispara AnalyticalReportGenerated)
generating → failed     (markFailed ou failed() do job)
generating → queued     (retry stale)
failed → queued         (retry)
generated → (nenhuma)
```

`canTransitionTo()` precisa aceitar `queued → queued` e `generating → queued`. O Service não chama o enum só como enfeite: o retry e o `markFailed` recusam a linha quando o update condicional não bate com o status lido.

---

## 12. Events / Listeners / Notifications / Queue

Eventos `final`, payload = o model, domínio `App\Events\Report`. Listeners descobertos sozinhos.

| Evento | Quando | Listeners | Fila |
|--------|--------|-----------|------|
| `AnalyticalReportRequested` (`ShouldDispatchAfterCommit`) | create `queued` ou retry que voltou a `queued` | `QueueAnalyticalReportGeneration` (dispatch do job, sync); `LogAnalyticalReportActivity` | O job é que entra na fila |
| `AnalyticalReportGenerated` | publicação | `NotifyAnalyticalReportGenerated` (`ShouldQueue`); `LogAnalyticalReportActivity` | Sim (notificação) |
| `AnalyticalReportGenerationFailed` | `markFailed` | `NotifyAnalyticalReportGenerationFailed` (`ShouldQueue`); `LogAnalyticalReportActivity` | Sim |
| `AnalyticalReportDownloaded` | download autorizado | `LogAnalyticalReportActivity` (user id, IP, `rows_count`) | Não |
| `PaymentRequestCreated`, `PaymentRequestStatusChanged`, `PaymentRequestBatchImported` (existentes) | §6.2 | `FlushDashboardMetricsCache` com `handlePaymentRequestCreated`, `handlePaymentRequestStatusChanged`, `handlePaymentRequestBatchImported` → `flush()` | Não |

`PaymentSettlementCreated`, `PaymentSettlementCancelled` e `PaymentSettlementConfirmed` **não** ganham listener. A confirmação já dispara `PaymentRequestStatusChanged` por PR.

Notificações `ShouldQueue`, `via(): ['mail', 'database']`, no estilo de `CnabFileGeneratedNotification`.

| Notification | Destino | Conteúdo |
|--------------|---------|----------|
| `AnalyticalReportGeneratedNotification` | `creator` | título `notifications.analytical_report_generated.title`; corpo com período `d/m/Y`, label da base de data e `rows_count`; ação `notifications.view_analytical_report` → URL da View. `toArray` guarda `analytical_report_id`, `title`, `body`, `url`. Sem anexo |
| `AnalyticalReportGenerationFailedNotification` | `creator` | `failure_reason` + a mesma URL |

Os dois listeners de notificação **não enviam** se `creator` for null ou o usuário estiver inativo (`requester_unavailable`). O evento ainda é logado.

Log estruturado em `LogAnalyticalReportActivity`: `analytical_report_id`, status, user id quando houver.

---

## 13. Traduções (i18n)

Criar os dois idiomas com as **mesmas chaves**. Abaixo, o texto pt-BR. O `en` espelha o sentido (não deixar valor em português no arquivo inglês).

### `lang/pt_BR/dashboard.php`

```php
return [
    'title' => 'Painel',
    'filters' => [
        'company' => 'Empresa',
        'branch' => 'Filial',
        'period_start' => 'Início do período',
        'period_end' => 'Fim do período',
        'all' => 'Todas',
    ],
    'stats' => [
        'paid_month' => 'Total pago no mês',
        'paid_period' => 'Total pago no período',
        'paid_description' => ':from – :until · :count pagamentos',
        'pending' => 'Pendentes',
        'pending_description' => ':requested solicitadas · :launched lançadas',
        'upcoming' => 'A vencer',
        'upcoming_description' => ':count até :until',
        'period_closed' => 'Período encerrado',
        'overdue' => 'Vencidos',
        'overdue_description' => ':count vencidos',
    ],
    'charts' => [
        'by_branch' => 'Pagamentos por filial',
        'by_cost_center' => 'Pagamentos por centro de custo',
        'paid' => 'Pago',
        'upcoming' => 'A vencer',
        'overdue' => 'Vencidos',
        'others' => 'Outros',
        'empty' => 'Nenhum pagamento no filtro selecionado',
    ],
];
```

### `lang/pt_BR/analytical_reports.php`

Chaves obrigatórias (valores pt-BR no arquivo; a lista de `columns` é a de §6.6):

```php
return [
    'label' => 'Relatório analítico',
    'plural' => 'Relatórios analíticos',
    'navigation_label' => 'Relatórios analíticos',
    'filename_prefix' => 'relatorio-analitico',
    'pix_qr_code' => 'QR Code',
    'fields' => [
        'date_basis' => 'Base de data',
        'period_start' => 'Início do período',
        'period_end' => 'Fim do período',
        'company_id' => 'Empresa',
        'branch_id' => 'Filial',
        'statuses' => 'Status',
        'status' => 'Status',
        'rows_count' => 'Linhas',
        'attachments_count' => 'Anexos',
        'filename' => 'Arquivo',
        'generated_at' => 'Gerado em',
        'failure_reason' => 'Motivo da falha',
        'started_at' => 'Iniciado em',
        'all' => 'Todas',
        'all_statuses' => 'Todos os status',
        'period' => 'Período',
    ],
    'sections' => [
        'filters' => 'Filtros',
        'result' => 'Resultado',
    ],
    'filters' => [
        'mine' => 'Meus relatórios',
        'status' => 'Status',
    ],
    'actions' => [
        'request' => 'Gerar relatório analítico',
        'download' => 'Baixar',
        'retry' => 'Tentar de novo',
    ],
    'messages' => [
        'queued' => 'Relatório enfileirado. Você será notificado quando estiver pronto.',
        'generation_interrupted' => 'A geração foi interrompida. Tente de novo.',
        'requester_unavailable' => 'O solicitante não está mais disponível para gerar este relatório.',
    ],
    'errors' => [
        'unauthorized' => 'Você não pode executar esta ação neste relatório.',
        'invalid_period' => 'Informe um período válido.',
        'no_matching_requests' => 'Nenhuma solicitação encontrada com esses filtros.',
        'too_many_rows' => 'O filtro tem :found solicitações. O máximo é :max. Reduza o período.',
        'too_many_in_progress' => 'Você já tem :max relatórios em geração. Espere um terminar.',
        'requester_unavailable' => 'O solicitante não está mais disponível para gerar este relatório.',
        'not_downloadable' => 'Este relatório ainda não está disponível para download.',
        'file_missing' => 'O arquivo deste relatório não está mais disponível. Gere outro.',
        'not_retryable' => 'Este relatório não pode ser gerado de novo agora.',
        'storage_write_failed' => 'Não foi possível gravar o arquivo. A geração será tentada de novo.',
    ],
    'sheet' => [
        'requests' => 'Solicitações',
        'attachments' => 'Anexos',
    ],
    'columns' => [
        // aba 1: request_id, open_request, company, branch, branch_document, status,
        // situation, request_date, due_date, requester, person_type, supplier_document,
        // supplier, supplier_legal_name, cost_center, appropriation, payment_method,
        // deposit_type, gross_amount, discount_amount, net_amount, digitable_line,
        // pix_key, beneficiary_bank, agency, account, holder_name, holder_document,
        // settlement_status, settlement_date, settled_amount, payer_account,
        // settled_at, notes, attachments_count, attachment => 'Anexo :number'
        // aba 2: request_id, branch, supplier, due_date, attachment_type, name,
        // mime_type, size_kb, uploaded_at, open
    ],
];
```

O implementador preenche cada chave de `columns` com o cabeçalho da tabela de §6.6. `filename_prefix` é **idêntico** nos dois idiomas (`relatorio-analitico`), porque entra no nome do arquivo.

### `lang/*/enums.php` (estender)

```php
'analytical_report_status' => [
    'queued' => 'Na fila',
    'generating' => 'Gerando',
    'generated' => 'Pronto',
    'failed' => 'Falhou',
],
'report_date_basis' => [
    'due_date' => 'Vencimento',
    'settlement_date' => 'Data de baixa',
    'request_date' => 'Data da solicitação',
],
'payment_due_situation' => [
    'paid' => 'Pago',
    'upcoming' => 'A vencer',
    'overdue' => 'Vencido',
],
```

Inglês: Queued, Generating, Ready, Failed; Due date, Settlement date, Request date; Paid, Upcoming, Overdue.

### `lang/*/notifications.php` (estender)

```php
'view_analytical_report' => 'Ver relatório',
'analytical_report_generated' => [
    'title' => 'Relatório analítico pronto',
    'body' => 'O relatório de :from a :until (:basis) está pronto, com :count linhas.',
],
'analytical_report_generation_failed' => [
    'title' => 'Falha ao gerar o relatório analítico',
    'body' => 'Não foi possível gerar o relatório de :from a :until. :reason',
],
```

---

## 14. API REST

Não expor. Sem Sanctum, Passport, Resource de API ou Swagger. A rota de §6.7 é página interna do painel, sessão + assinatura, e existe porque o Excel precisa de um GET fora do Livewire.

---

## 15. Soft deletes, cascade e retenção

| Entidade | Política |
|----------|----------|
| `AnalyticalReport` | SoftDeletes. Adm esconde pela lista. O arquivo continua no disco até o force delete |
| Prune | `Prunable::prunable()` com `withTrashed()` e `created_at <= now()->subDays(retention)`. `model:prune` chama `forceDelete()` no model, que dispara o observer |
| Arquivo | Só `AnalyticalReportObserver::forceDeleted`, `ShouldHandleEventsAfterCommit` |
| Leituras | Dashboard e relatório ignoram PR, baixa e anexo soft-deleted. Rótulos usam `withTrashed` |
| Empresa | `CompanyObserver::forceDeleting` apaga os relatórios (Eloquent, um a um) **antes** das filiais |
| Filial | `BranchObserver::forceDeleting` apaga relatórios com aquele `branch_id` |
| Soft delete de empresa/filial | Não mexe em relatório. A FK restrict só entra no hard delete |

Não usar `forceDelete()` em query builder. Não usar `MassPrunable`. Não implementar `pruning()`.

---

## 16. Factories e seeders

`AnalyticalReportFactory` (gerada pelo `make:model` e reescrita):

| | |
|--|--|
| Definition | `status = queued`, `date_basis = due_date`, período = mês corrente em SP, `disk = config('rjet.reports.disk')`, `created_by` = Operador (`recycle` de `User::factory()->state` operador, seguindo o padrão das factories de artefato) |
| States | `queued()`, `generating()` (preenche `started_at`), `generated()` (`path` no formato `reports/Y/m/{uuid}.xlsx`, `filename`, `size`, `rows_count`, `generated_at`; o teste grava o binário com `Storage::fake`), `failed(string $reason)`, `stale()` (`generating` + `updated_at` anterior a `stale_after_minutes`), `forCompany(Company)`, `forBranch(Branch)` (também preenche `company_id` com a empresa da filial), `basis(ReportDateBasis)`, `withStatuses(array)` (únicos, ordenados; vazio → `null`), `expired()` (`created_at` além da retenção) |

Soft delete: `trashed()` nativo. Não criar state `trashed` próprio.

Factories existentes, sem coluna nova:

- `PaymentRequestFactory`: usar `launched()`, `settled()`, `forBranch()`. Acrescentar state `dueOn(string $date)` só se os testes do dashboard ficarem ilegíveis sem ele.
- `PaymentSettlementFactory`: `settled()`, `withItems()`, `dated()` para o card de pago.
- `AttachmentFactory`: anexos da PR nos testes do Excel e da rota.

**Seeder:** nenhum obrigatório. Opcional, só dev e idempotente: estender `DevelopmentSeeder` com PRs em aberto (vencidas e a vencer) em duas filiais e uma baixa `settled` no mês, para o dashboard não abrir zerado. Não criar `AnalyticalReportSeeder`.

---

## 17. Tests (Pest)

Feature tests, SQLite em memória, `Storage::fake`, `Queue::fake` / `Event::fake` / `Notification::fake` conforme o caso, `Carbon::setTestNow` com timezone `America/Sao_Paulo`. Factories, não `Model::create` solto. Cada arquivo cobre o comportamento e as falhas da linha; não acrescentar suíte extra.

| # | Arquivo | Critério |
|---|---------|----------|
| 1 | `tests/Feature/DashboardMetricsServiceTest.php` | Fixture: `requested`/`launched` vencidas, vencendo hoje e futuras; baixa `settled` no mês e no mês anterior; baixa `draft`; item com `released_at`. `stats()` igual à conta manual de §1 / arquitetura §3.1 |
| 2 | idem | `open = upcoming + overdue` em valor (`bccomp`) e contagem, em três períodos: mês corrente, período passado (`end < hoje`), período futuro |
| 3 | idem | Editar `net_amount` de PR `Settled` não muda Pago. Baixa `draft` e item liberado não contam. PR soft-deleted sai de todos os indicadores |
| 4 | idem | Empresa, filial e período filtram. Filial de outra empresa → zeros. Início do período não esconde vencido do mês anterior |
| 5 | idem | Cliente só vê filiais vinculadas. `branch_id` de outra filial → zeros. Operador e Adm veem o total |
| 6 | idem | `setTestNow` 00:30 em São Paulo. "Hoje" é a data de SP. PR que vence nesse dia é "a vencer" |
| 7 | `tests/Feature/DashboardMetricsCacheTest.php` | Segunda chamada com os mesmos filtros não consulta de novo (`expectsDatabaseQueryCount` ou contagem antes/depois). Invalidam: `PaymentRequestCreated`, `PaymentRequestStatusChanged`, `PaymentRequestBatchImported`, e o observer em `due_date` / `net_amount` / `cost_center_id` / `branch_id`. `PaymentSettlementCancelled` não incrementa a versão. Chave de Cliente ≠ chave de staff. `flush()` dentro de transação só muda a versão depois do commit |
| 8 | `DashboardMetricsServiceTest` | Soma de `byBranch()` e de `byCostCenter()` = `stats()` (pago, a vencer, vencidos), com e sem filtros |
| 9 | idem | Mais de 12 categorias: no máximo 12 labels, a última é "Outros", a soma das séries continua igual ao card. Sem filtro de filial, o rótulo do centro de custo inclui a filial. Filial ou centro de custo soft-deleted mantém o nome |
| 10 | `tests/Feature/DashboardWidgetsTest.php` | Livewire do chart com `pageFilters` sem dados: `getData()` vazio e heading de empty state. Com dados: labels e as três cores |
| 11 | `tests/Feature/DashboardPageTest.php` | Dashboard renderiza para Cliente, Operador e Adm. Options de empresa/filial do Cliente só listam as dele. Action de relatório ausente para o Cliente e presente para Operador/Adm. `?filters[period_start]=nao-e-data` não quebra a página |
| 12 | `tests/Feature/DashboardPerformanceTest.php` | ~2 000 PRs. Render dos três widgets com TTL 0 faz um número fechado de queries (agregação + rótulos), sem N+1. Não assertar tempo |
| 13 | `tests/Feature/AnalyticalReportServiceTest.php` | `request()` cria `queued` e o evento só aparece depois do commit. Cliente → `unauthorized`. Período invertido, zero linhas, acima de `max_rows`, quarto em andamento |
| 14 | `tests/Feature/GenerateAnalyticalReportJobTest.php` | Sucesso: arquivo no path `reports/Y/m/{uuid}.xlsx`, status `generated`, contagens e size. Lido com `SpreadsheetReader`: abas e cabeçalhos na ordem, uma linha conferida (datas, valores, situação) |
| 15 | idem | Colunas Anexo 1..N com N = máximo do resultado e teto 10. Aba Anexos lista todos, inclusive o 11º. `settlement_date` só traz PRs pagas no período (o conjunto bate com o card Pago). `request_date` respeita o dia de SP (registro às 22:30 SP entra; o equivalente UTC do dia seguinte não entra no dia anterior) |
| 16 | idem | Job aplica `visibleTo` do solicitante. Solicitante inativo → `failed`, sem nova tentativa, sem notificação |
| 17 | idem | Falha de `putFileAs` → exceção → `failed()` marca `failed` com mensagem genérica e dispara `AnalyticalReportGenerationFailed`. Retry volta a `queued` e enfileira. Job já `generated` é no-op. Duas execuções concorrentes disparam um único `AnalyticalReportGenerated` |
| 18 | idem | Fila `config('rjet.reports.queue')`, timeout 75, tries 3, backoff `[10, 30, 60]`, `middleware()` vazio |
| 19 | `tests/Feature/AnalyticalReportNotificationTest.php` | `AnalyticalReportGenerated` → mail + database para o `creator`, URL da View, sem anexo. Listener `ShouldQueue` |
| 20 | `tests/Feature/ReportAttachmentLinkTest.php` | URL gerada com `signature` e comprimento ≤ 255 no `APP_URL` de teste. Guest → redirect ao login. Assinatura adulterada → 403. Cliente de outra filial → 403. Cliente da filial → redirect. Operador → redirect ou stream. Anexo ou PR excluídos → 404. Anexo de `attachment_batch` → 404 |
| 21 | `tests/Feature/AnalyticalReportDownloadTest.php` | Operador e Adm `assertDownload`. Cliente 403. `failed` e `queued` não baixam. Arquivo ausente → `fileMissing`. Evento `AnalyticalReportDownloaded` |
| 22 | `tests/Feature/AnalyticalReportAuthorizationTest.php` | Matriz de §10 por papel e por status (`download` só `generated`, `retry` só `failed` ou stale) |
| 23 | `tests/Feature/AnalyticalReportResourceTest.php` | Lista e View por papel. Action cria o registro. Retry visível em `failed` e `stale()`, oculto em `generating` recente |
| 24 | `tests/Feature/AnalyticalReportPruneTest.php` | `model:prune` remove registro e arquivo além da retenção, inclusive soft-deleted, e mantém os recentes. `forceDelete()` do Adm apaga o arquivo. Relatório sem `path` é removido sem erro |
| 25 | `tests/Feature/AnalyticalReportSchemaTest.php` | Colunas, defaults (`queued`, `due_date`, contadores 0), FK restrict (hard delete direto de filial referenciada lança `QueryException`), ausência do índice `(created_by, created_at)` |
| 26 | `tests/Feature/SpreadsheetWriterTest.php` | Células de texto que começam com `=`, `+`, `-`, `@` continuam texto na leitura. `HYPERLINK` com aspas no rótulo escapa. URL > 255 vira texto |
| 27 | `GenerateAnalyticalReportJobTest` | Mais de 500 PRs, vencimentos fora da ordem de criação: aba 1 completa, sem duplicata, ordem `due_date`, `created_at`, `id`. `rows_count` = total |
| 28 | `DashboardMetricsServiceTest` + teste de wipe (pode ser o de prune ou `CompanyCascade` estendido, sem apagar testes existentes) | Pago com filtro de filial não estoura coluna ambígua. Force delete de empresa com relatório filtrado por ela remove o relatório e o arquivo, sem `QueryException` |

O caso 28 de filial isolada (force delete em `EditBranch`) entra no mesmo arquivo do wipe: um relatório `forBranch()` some junto com o arquivo.

---

## 18. Estimativa

| Componente | Complexidade | Tempo |
|------------|--------------|-------|
| Migration, model, factory, observer, scopes | Média | 1 h |
| Enums, exceção, config, traduções | Baixa | 1 h |
| `DashboardMetricsService` + cache + hooks | Alta | 2,5 h |
| Página Dashboard + 3 widgets | Média | 1,5 h |
| Writer OpenSpout + colunas | Alta | 2,5 h |
| `AnalyticalReportService` + job + actions | Alta | 3 h |
| Resource (list/view/actions) + Policy | Média | 1,5 h |
| Rota de anexo + force delete de cadastro + schedule | Média | 1 h |
| Testes §17 | Alta | 4 h |
| **Total** | — | **~18 h** |

---

## 19. Notas, checklist e pendências

1. Este arquivo é plano. A implementação começa depois da aprovação.
2. Não promover `openspout/openspout` nem instalar `league/flysystem-aws-s3-v3` nesta fase. As duas pendências exigem aprovação de dependência e estão fora do código da F8.
3. P-F8-COLS não bloqueia: a lista de colunas pode mudar sem migration.
4. Gatilho futuro de índice (não implementar agora): `payment_requests` acima de 200 mil linhas **ou** `EXPLAIN (ANALYZE, BUFFERS)` da agregação de abertos, sem cache, filtro "todas", acima de 50 ms. Candidato: índice parcial `payment_requests_open_due_date_index` em `(due_date) WHERE status IN ('requested','launched') AND deleted_at IS NULL`, com literais na migration e `supportsPartialIndexes()`. `(status, settlement_date)` em `payment_settlements` só acima de ~50 mil baixas.

### Checklist de implementação

- [ ] Enums + `lang/*/enums.php`
- [ ] Migration §3.1 + model + factory + `AnalyticalReportObserver`
- [ ] `config/rjet.php` + `.env.example`
- [ ] Scopes qualificados em `PaymentRequest`
- [ ] `CompanyObserver` e `BranchObserver` apagam relatórios no force delete
- [ ] DTOs §6.1
- [ ] `AnalyticalReportException`
- [ ] `SpreadsheetWriter` + bind
- [ ] `DashboardMetricsService` + `AnalyticalReportService`
- [ ] Actions `App\Actions\Report\*`
- [ ] Events, listeners (`Report` e `Dashboard`), notificações
- [ ] `PaymentRequestObserver` (`updated`, `flush` em deleted/restored/forceDeleted) e remoção do comentário em `LogPaymentRequestActivity`
- [ ] `GenerateAnalyticalReportJob`
- [ ] `AnalyticalReportPolicy` em `POLICIES`
- [ ] Controller + `authenticatedRoutes`
- [ ] `App\Filament\Pages\Dashboard` no lugar do Dashboard base; sem `FilamentInfoWidget`
- [ ] Três widgets, `$pollingInterval` explícito
- [ ] Resource sem Create/Edit
- [ ] `model:prune` às 02:00
- [ ] Traduções pt_BR e en
- [ ] Testes §17 verdes
- [ ] `vendor/bin/pint --dirty --format agent`
- [ ] `php artisan event:list` sem listener duplicado

### Checklist Filament (recorte)

- [ ] Resource limpo, pasta plural, classes `final`
- [ ] `getRecordRouteBindingEloquentQuery()` sem o global scope de soft delete
- [ ] Labels com `__()`
- [ ] `recordActions` / `toolbarActions` / `schema()` nas actions
- [ ] `Select` de enum com `->options(Enum::class)`
- [ ] Section dos filtros com `columnSpanFull()`
- [ ] `getData()` do gráfico retorna `[]` no vazio
- [ ] Gráficos com `$pollingInterval = null`

### Pendências herdadas (não implementar na F8)

| ID | Tema | Bloqueia a F8? |
|----|------|----------------|
| P-F8-COLS | Validar colunas com a RJET | Não |
| P-S3-DRIVER | Driver S3 ausente do lock. `temporaryUrl` em produção depende disso | Não em local. Sim para produção em S3 |
| P-F5-OPENSPOUT | Promover OpenSpout a `require` direto | Não enquanto `filament/actions` o trouxer |
| Log duplicado em `LogPaymentRequestActivity` | `Event::listen` explícito junto com discovery | Não. Conferir `event:list`; o listener novo do dashboard usa só discovery |

---

## 20. Próximos passos

1. Revisão humana deste blueprint.
2. `implementer`, nesta ordem: enums e traduções → migration e model → config → scopes → DTOs → exceção → writer → services → actions → events/listeners/notifications → observer da PR → job → policy → controller e rota → Filament (dashboard, widgets, resource) → observers de empresa/filial → schedule → testes §17 → Pint.
3. Não abrir API REST.
4. Dependências de produção (S3 e OpenSpout direto) ficam para uma aprovação separada.

Referências já incorporadas: `.ai/docs/queues.md`, `.ai/docs/notifications.md`, `.ai/docs/file-storage.md`, `.ai/docs/scheduling.md`, `.ai/docs/soft-deletes.md`, `.ai/checklists.md`, `.ai/skills/filament/SKILL.md`.
