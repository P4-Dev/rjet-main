# Entrega Técnica — Fase 8: Dashboard e relatórios analíticos

| Campo | Valor |
|-------|-------|
| **Fase** | 8 — Dashboard gerencial e relatório analítico em Excel |
| **Data** | 2026-09-27 |
| **Status** | Entregue (colunas do Excel a validar com a RJET e driver S3 pendente, ver "Riscos / pendências") |
| **Stack** | Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL · Redis · OpenSpout (já no lock, transitivo; sem nova dependência) |
| **Requisitos** | RF035–RF037 |

---

## Resumo executivo

A Fase 8 entrega a **visão gerencial** sobre o ciclo `Requested → Launched → Settled` fechado nas fases anteriores. O painel do Filament passa a ser um `App\Filament\Pages\Dashboard` próprio (substitui `Filament\Pages\Dashboard`) com **quatro indicadores** (Pago, Pendentes, A vencer, Vencidos) e **dois gráficos** (por filial e por centro de custo), todos com filtros de empresa, filial e período e sempre escopados por `PaymentRequest::visibleTo($user)`. Os números vêm de `DashboardMetricsService`, com cache versionado invalidado depois do commit. O **relatório analítico** (`AnalyticalReport`) é pedido por Operador ou Adm, gerado em fila por `GenerateAnalyticalReportJob` num `.xlsx` de duas abas (Solicitações e Anexos) e baixado atrás de policy. Os links de anexo dentro do Excel exigem **sessão + assinatura + `AttachmentPolicy::view`**. Retenção de 30 dias via `model:prune`. Sem API REST, sem dependência nova.

---

## Objetivos / RFs cobertos

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| **RF035** | Quatro indicadores (total pago no período, pendentes, a vencer, vencidos) com filtros de empresa, filial e período, escopo `PaymentRequest::visibleTo` | `App\Filament\Pages\Dashboard` (`HasFiltersForm`), `PaymentOverviewStats`, `DashboardMetricsService::stats()`, `DashboardFilterData`, `DashboardMetrics` |
| **RF036** | Gráficos por filial e por centro de custo, mesmos filtros e mesmos totais dos cards; no máximo 12 barras incluindo "Outros" | `PaymentsByBranchChart`, `PaymentsByCostCenterChart`, `DashboardMetricsService::byBranch()` / `byCostCenter()`, `DashboardSeries` |
| **RF037** | Excel analítico assíncrono, duas abas, links de anexo com sessão + assinatura + policy | `AnalyticalReport`, `AnalyticalReportService`, `GenerateAnalyticalReportJob`, `SpreadsheetWriter` / `OpenSpoutXlsxSpreadsheetWriter`, `AnalyticalReportResource`, rota `filament.admin.report-attachments.open` |

---

## O que foi entregue

### Domínio e persistência

- **Model:** `AnalyticalReport` (`HasUuid`, `HasBlameable`, `SoftDeletes`, `Prunable`; `#[ObservedBy(AnalyticalReportObserver)]`). Relações `company()` e `branch()` com `withTrashed()`. Helpers `isDownloadable()` (`generated` com `path`), `isStale()` (em andamento sem update há mais de `stale_after_minutes` = 30), `isRetryable()` (`failed` ou stale). Scopes `inProgress()` e `createdBy()`.
- **Migration** (única nova): `2026_09_27_183243_create_analytical_reports_table`. Colunas: `status` (`string(20)`, default `queued`, indexada), snapshot dos filtros (`company_id` e `branch_id` nullable, FK `restrictOnDelete`; `date_basis` default `due_date`; `period_start` / `period_end`; `statuses` JSON nullable), `disk` (snapshot de `rjet.reports.disk`), `path`, `filename`, `size`, `rows_count` / `attachments_count` (default 0), `failure_reason`, `started_at`, `generated_at`, `created_by` / `updated_by`, `timestampsTz`, `softDeletesTz`. `null` nos filtros = "todos". **Sem índice novo** em `payment_requests`, `payment_settlements` ou `payment_settlement_items`.
- **Sem colunas novas** em tabelas existentes. `PaymentRequest` ganhou só scopes: `scopeOpen()` (`requested` + `launched`), `scopeForCompany()`, e `scopeForBranch()` passou a qualificar a coluna.
- **Enums:** `AnalyticalReportStatus` (`queued → generating → generated`; `failed` → `queued` via retry; `generated` terminal), `ReportDateBasis` (`due_date`, `settlement_date`, `request_date`), `PaymentDueSituation` (`paid`, `upcoming`, `overdue`; usado na coluna "Situação" do Excel).
- **DTOs:** `DashboardFilterData` (sanitiza filtros vindos da URL/sessão: data inválida cai no default, UUID inválido vira `null`, período invertido é trocado), `DashboardMetrics`, `DashboardSeries`, `AnalyticalReportData`.
- **Exceção:** `AnalyticalReportException` (`unauthorized`, `invalidPeriod`, `noMatchingRequests`, `tooManyRows`, `tooManyInProgress`, `requesterUnavailable`, `notDownloadable`, `fileMissing`, `notRetryable`, `storageWriteFailed`).
- **Services:**
  - `DashboardMetricsService`: `stats()`, `byBranch()`, `byCostCenter()`, `flush()`, `cacheKey()`.
  - `AnalyticalReportService`: `request()`, `generate()`, `markFailed()`, `retry()`, `download()`, `rowsQuery()`, `countMatching()`, `attachmentUrl()`.
- **Actions de domínio** (`App\Actions\Report\`): `RequestAnalyticalReportAction`, `RetryAnalyticalReportAction`, `DownloadAnalyticalReportAction`, `OpenReportAttachmentAction`.
- **Integração** (`App\Integrations\Spreadsheet\`): contrato `SpreadsheetWriter` (bind para `OpenSpoutXlsxSpreadsheetWriter` no `AppServiceProvider`, ao lado do reader da F5) e `SpreadsheetCell` (texto, dinheiro, número, data, data-hora, link).
- **Observers:**
  - `AnalyticalReportObserver` (`ShouldHandleEventsAfterCommit`): `forceDeleted` apaga o arquivo do disco.
  - `BranchObserver` (novo): `forceDeleting` remove, via Eloquent, os relatórios filtrados pela filial antes do hard delete (FK `restrict`).
  - `CompanyObserver::forceDeleting`: remove relatórios com `company_id` da empresa **ou** `branch_id` em suas filiais, antes do wipe.
  - `PaymentRequestObserver`: `updated` (se mudou `due_date`, `net_amount`, `cost_center_id` ou `branch_id`), `deleted`, `restored` e `forceDeleted` chamam `DashboardMetricsService::flush()`.
- **HTTP:** `OpenReportAttachmentController` (invocável) → `OpenReportAttachmentAction`. Única rota nova.
- **Config:** `config/rjet.php` → `rjet.dashboard` (`cache_ttl_seconds` 300, `chart_max_categories` 12) e `rjet.reports` (`disk`, `directory` `reports`, `queue` `default`, `max_rows` 20000, `max_in_progress_per_user` 3, `max_attachment_columns` 10, `retention_days` 30, `attachment_redirect_ttl_minutes` 5, `stale_after_minutes` 30). `.env.example` com as cinco variáveis novas.
- **Factory:** `AnalyticalReportFactory`.
- **Limpeza:** removido o comentário de hook da F8 em `LogPaymentRequestActivity`; o flush ficou em listener próprio.

### Dashboard (RF035 / RF036)

- **`App\Filament\Pages\Dashboard`** estende `Filament\Pages\Dashboard` com `HasFiltersForm`: filtros de empresa, filial (dependente da empresa) e período (default: mês corrente em `config('app.timezone')`). Opções de empresa/filial do Cliente listam só as filiais vinculadas. Header action "Gerar relatório analítico" pré-preenche o modal com os filtros atuais (só Operador e Adm).
- **`AdminPanelProvider`:** `->pages([App\Filament\Pages\Dashboard::class])`; `AccountWidget` permanece; **`FilamentInfoWidget` removido**. Widgets por descoberta (`discoverWidgets`), sem listagem duplicada.
- **Widgets** (`app/Filament/Widgets`, `final`, `InteractsWithPageFilters`):

| Widget | Tipo | Sort | Polling |
|--------|------|------|---------|
| `PaymentOverviewStats` | `StatsOverviewWidget`, 4 cards | 1 | `60s` |
| `PaymentsByBranchChart` | `ChartWidget` barras (Pago / A vencer / Vencidos) | 2 | `null` |
| `PaymentsByCostCenterChart` | `ChartWidget` barras horizontais (`indexAxis: 'y'`) | 3 | `null` |

- **Definição dos indicadores** (`DashboardMetricsService`):
  - **Pago:** soma de `payment_settlement_items.amount` do item ativo (`released_at IS NULL`) de baixa `settled`, não excluída, com `settlement_date` dentro do período. PR `Settled` sem baixa não entra. Rótulo "Pago no mês" no período default, "Pago no período" nos demais.
  - **Pendentes / A vencer / Vencidos:** posição em "hoje" sobre PRs `requested` + `launched` com `due_date <= period_end`, **sem limite inferior** (o início do período só vale para Pago). Vence hoje = a vencer. Invariante: Pendentes = A vencer + Vencidos. Período já encerrado zera "A vencer" com a descrição "período encerrado".
  - PR, baixa ou item soft-deleted ficam fora de todos os números.
  - Comparações de data em intervalo semiaberto `Y-m-d` (`>= início`, `< fim + 1 dia`), mesma SQL em PostgreSQL e SQLite.
- **Gráficos:** no máximo **12 barras, incluindo "Outros"**. Acima disso, as 11 maiores por total ficam nomeadas e o resto soma em "Outros" (`__('dashboard.charts.others')`). A soma das séries continua igual aos cards. Sem filtro de filial, o rótulo do centro de custo inclui a filial. Filial ou centro de custo soft-deleted mantém o nome. Sem dados, `getData()` retorna `[]` e o empty state aparece.
- **Cache:** chave `dashboard:v{version}:{scope}:{block}:{locale}:{hash}`, TTL 300 s (`RJET_DASHBOARD_CACHE_TTL`; `0` desliga). `scope` = `all` para Operador/Adm ou `branches:{md5}` das filiais do Cliente. O **locale entra na chave** para o rótulo "Outros" não vazar entre idiomas. `flush()` faz `Cache::add` + `increment` de `dashboard:version` dentro de `DB::afterCommit`; chaves antigas expiram pelo TTL (sem tags).
- **Invalidação:** `FlushDashboardMetricsCache` (por discovery) em `PaymentRequestCreated`, `PaymentRequestStatusChanged` (cobre a confirmação da baixa) e `PaymentRequestBatchImported`; mais os hooks do `PaymentRequestObserver`. `PaymentSettlementCancelled` não invalida (baixa `draft` não entra em nenhum número).

### Relatório analítico (RF037)

- **Pedido** (`AnalyticalReportService::request()`): exige policy `create`; valida período (presente e não invertido), teto de **3 relatórios em andamento por usuário**, coerência filial × empresa e contagem de linhas entre 1 e **20 000** (`RJET_REPORTS_MAX_ROWS`). Cria `queued` em transação e dispara `AnalyticalReportRequested` (`ShouldDispatchAfterCommit`).
- **Base de data** (`rowsQuery()`, sem join e sem `orderBy`):
  - `due_date`: vencimento no período.
  - `settlement_date`: `whereHas` no item ativo de baixa `settled`, não excluída, com data no período (mesmo conjunto do card Pago).
  - `request_date`: `created_at` entre o início do dia em `America/Sao_Paulo` e o início do dia seguinte, **convertidos para `config('app.timezone')`** (não para UTC), porque a aplicação grava timestamps nesse fuso.
  - Filtro opcional por `statuses`.
- **Geração** (`generate()`):
  - `fresh()` ignora o global scope de soft delete; **relatório soft-deleted retorna cedo**, assim como relatório fora de `queued`/`generating`.
  - Update condicional `queued|generating → generating` (idempotência sem lock).
  - Solicitante ausente, inativo ou sem policy `create` → `failed` com `requester_unavailable`, sem exceção (sem nova tentativa).
  - Snapshot de IDs (`pluck`) ordenado por `due_date`, `created_at`, `id`; se passar de `max_rows` no momento da geração → `failed`.
  - Escrita em streaming num arquivo temporário, em blocos de 500 com `whereKey` e eager loading (`withTrashed` nos cadastros).
  - Grava em `reports/{YYYY}/{MM}/{uuid}.xlsx` no disco do snapshot. Falha de `putFileAs` → `storageWriteFailed` (exceção → o job tenta de novo).
  - Publicação por update condicional `generating → generated`; só quem publica dispara `AnalyticalReportGenerated`. Execuções concorrentes geram um único evento.
  - Nome de download: `relatorio-analitico_{Ymd}-{Ymd}_{YmdHi}.xlsx` (prefixo traduzido).
- **`markFailed()`** só atualiza relatório em `queued` ou `generating`; senão não faz nada e não dispara evento.
- **Retry** (`retry()`): relatório `failed` ou stale (30 min sem update), com policy `retry`; volta a `queued` por update condicional em `status` + `updated_at` e dispara `AnalyticalReportRequested` de novo.
- **Download** (`download()`): exige `isDownloadable()` e policy `download`; arquivo ausente → log `error` + `fileMissing`; dispara `AnalyticalReportDownloaded` e faz `Storage::download` (stream, sem `temporaryUrl`).

**Planilha** (`OpenSpoutXlsxSpreadsheetWriter`): cabeçalho em negrito, primeira linha congelada (`SheetView::setFreezeRow(2)`), dinheiro `#,##0.00`, datas `dd/mm/yyyy` e `dd/mm/yyyy hh:mm` em `America/Sao_Paulo`. Todo texto é gravado como `StringCell`: conteúdo que começa com `=`, `+`, `-` ou `@` não vira fórmula.

| Aba | Conteúdo |
|-----|----------|
| **Solicitações** | 35 colunas fixas (ID, link "Abrir solicitação", empresa, filial, CNPJ da filial, status, situação, datas, solicitante, fornecedor, centro de custo, apropriação, forma/tipo de pagamento, valores bruto/desconto/líquido, linha digitável, chave PIX, dados bancários do favorecido, status/data/valor da baixa, conta pagadora, liquidado em, observações, qtd. de anexos) + colunas **Anexo 1..N**, com N = maior número de anexos no resultado, teto 10 |
| **Anexos** | Uma linha por anexo, sem teto (ID da PR, filial, fornecedor, vencimento, tipo, nome, MIME, tamanho em KB, enviado em, link) |

- **Conta pagadora** formatada por `__('analytical_reports.payer_account_format')` (pt_BR: `:bank · Ag :agency · CC :account`).
- **Links** são fórmulas `=HYPERLINK("url","rótulo")` com aspas escapadas. URL acima de 255 caracteres (limite do Excel) vira texto e gera log `warning`. O rótulo é cortado em 255.

**Link de anexo** (`GET /admin/report-attachments/{attachment}`, nome `filament.admin.report-attachments.open`):

- Registrado em `authenticatedRoutes()` do painel: exige **sessão** do Filament (guest → login) e `signed:relative`, **sem expiração** (o Excel pode ser aberto dias depois).
- `OpenReportAttachmentAction`: anexo que não é de `PaymentRequest` → 404; `AttachmentPolicy::view` (Cliente de outra filial → 403); **arquivo ausente no disco → 404 antes de `temporaryUrl` ou stream**; log `info` (anexo, PR, usuário, IP); redirect para `temporaryUrl` de 5 min quando o disco suporta, senão stream inline (`Storage::response`).
- A URL assinada é relativa e prefixada com `config('app.url')`.

### Filament (painel admin)

- **`AnalyticalReportResource`** (grupo `reports`, sort 10, ícone `OutlinedDocumentChartBar`): Pages **List** e **View**, sem Create/Edit. `getRecordRouteBindingEloquentQuery()` sem o global scope de soft delete (o Adm abre relatório excluído).
- **List:** header action "Gerar relatório analítico"; colunas de data, solicitante, base de data, período, empresa, filial, status, linhas; filtros de status, "Meus relatórios" e `TrashedFilter` (só Adm); record actions View, Baixar, Tentar de novo, Delete, Restore; bulk Delete. A tabela faz polling de 5 s enquanto existir relatório em andamento.
- **View:** seções Filtros, Resultado e Auditoria; header actions Baixar, Tentar de novo, Delete, Restore, ForceDelete (com confirmação).
- **Atualização da View:** enquanto o status está em andamento, a Section de resultado recebe `wire:poll.5s="refreshReport"` via `extraAttributes()`. `ViewAnalyticalReport::refreshReport()` chama `PartialsComponentHook::forceRender($this)` e **recarrega a página inteira**, então o botão Baixar do cabeçalho aparece quando o relatório fica `generated`. **Não** é `Section::poll()`: no Filament 5.7 esse método só redesenha o partial da Section e o botão Baixar continuaria oculto. `getPollingInterval()` na página não existe no Filament 5.7 e foi removido.
- **Actions Filament** (`Resources/AnalyticalReports/Actions/`): `RequestAnalyticalReportAction` (modal com `AnalyticalReportForm::components()`, erros de negócio viram notificação e `halt()`), `DownloadAnalyticalReportAction`, `RetryAnalyticalReportAction`. Visibilidade e execução checam a policy.

### Autorização e regras de negócio

**`AnalyticalReportPolicy`** (staff = `UserRole::seesAllBranches()`, ou seja, Operador e Adm):

| Ability | Cliente | Operador | Adm |
|---------|---------|----------|-----|
| `viewAny` / `create` | Não | Sim | Sim |
| `view` | Não | Sim, se não excluído | Sim, inclusive excluído |
| `download` | Não | `view` + `generated` com arquivo | idem, inclusive excluído |
| `retry` | Não | `view` + `failed` ou stale | idem, inclusive excluído |
| `update` | Não | Não | Não |
| `delete` / `restore` / `forceDelete` (e `*Any`) | Não | Não | Sim |

- **Cliente** vê o dashboard escopado às filiais vinculadas e **não** solicita relatório (action oculta, resource fora da navegação).
- **Relatório soft-deleted:** Operador não abre, não baixa e não faz retry. Adm continua podendo ver, baixar e restaurar.
- **Visibilidade:** dashboard e relatório aplicam **sempre** `PaymentRequest::visibleTo($user)` além dos filtros; filtro forjado na URL não alarga o escopo. O job usa o `visibleTo` do **solicitante**.

### Events / Listeners / Notifications / Jobs

| Artefato | Detalhe |
|----------|---------|
| `AnalyticalReportRequested` | `ShouldDispatchAfterCommit` → `QueueAnalyticalReportGeneration` → `GenerateAnalyticalReportJob::dispatch()`; `LogAnalyticalReportActivity` |
| `AnalyticalReportGenerated` | `NotifyAnalyticalReportGenerated` (`ShouldQueue`, `$afterCommit = true`) → `AnalyticalReportGeneratedNotification`; `LogAnalyticalReportActivity` |
| `AnalyticalReportGenerationFailed` | `NotifyAnalyticalReportGenerationFailed` (`ShouldQueue`, `$afterCommit = true`) → `AnalyticalReportGenerationFailedNotification`; `LogAnalyticalReportActivity` |
| `AnalyticalReportDownloaded` | `LogAnalyticalReportActivity` (usuário, IP) |
| `PaymentRequestCreated` / `StatusChanged` / `BatchImported` (existentes) | `FlushDashboardMetricsCache` (sync, por discovery) |
| Notifications | `mail` + `database`, `ShouldQueue`, destino = quem pediu; link para a **View do relatório**; o arquivo **nunca** vai anexado |

**`GenerateAnalyticalReportJob`** (`App\Jobs\Report`):

| Parâmetro | Valor |
|-----------|-------|
| Conexão | default |
| Fila | `config('rjet.reports.queue')` ← `RJET_REPORTS_QUEUE` (`default`) |
| `$timeout` | 75 |
| `$tries` | 3 |
| `$backoff` | `[10, 30, 60]` |
| Middleware | nenhum (**sem** `WithoutOverlapping`; idempotência pelos updates condicionais) |
| `failed()` | Se o relatório não é terminal, `markFailed()` com a mensagem de usuário da `BusinessException` ou `generation_interrupted`; exceção crua só no log |

**Retenção:** `AnalyticalReport` usa `Prunable` (não `MassPrunable`), `prunable()` com `withTrashed()` e `created_at` há mais de 30 dias (`RJET_REPORTS_RETENTION_DAYS`). Agendado em `routes/console.php`: `model:prune --model=AnalyticalReport` diário às **02:00**, `withoutOverlapping()`. O arquivo é apagado em `AnalyticalReportObserver::forceDeleted` (após o commit), não em `pruning()`.

### i18n

- Novos: `lang/{pt_BR,en}/dashboard.php`, `lang/{pt_BR,en}/analytical_reports.php` (labels, colunas, abas, mensagens, erros, `payer_account_format`, `filename_prefix`).
- Extensões: `enums.php` (3 enums novos), `notifications.php` (relatório gerado / falha). Grupo `navigation.groups.reports` já existia.

### Testes (Pest)

**Suíte completa** executada em 2026-09-27:

```bash
docker compose exec app php artisan test --compact
```

Resultado: **833 testes, 2783 asserções, todos passando, ~29 s.**

Subconjunto da Fase 8, reexecutado para este documento: **152 passed · 541 assertions**.

```bash
docker compose exec -T app php artisan test --compact --filter='AnalyticalReport|Dashboard|GenerateAnalyticalReportJob|ReportAttachmentLink|SpreadsheetWriter'
```

| Área | Arquivo |
|------|---------|
| Indicadores, filtros, escopo, gráficos, "Outros" | `tests/Feature/DashboardMetricsServiceTest.php` |
| Cache (chave, invalidação, after commit) | `tests/Feature/DashboardMetricsCacheTest.php` |
| Página Dashboard por papel, filtros malformados | `tests/Feature/DashboardPageTest.php` |
| Widgets (empty state, séries) | `tests/Feature/DashboardWidgetsTest.php` |
| Número fechado de queries (~2 000 PRs, sem N+1) | `tests/Feature/DashboardPerformanceTest.php` |
| `request()` e validações | `tests/Feature/AnalyticalReportServiceTest.php` |
| Job (arquivo, abas, colunas, ordem, bases de data, falhas, concorrência, parâmetros) | `tests/Feature/GenerateAnalyticalReportJobTest.php` |
| Notificações | `tests/Feature/AnalyticalReportNotificationTest.php` |
| Link de anexo (assinatura, sessão, policy, 404) | `tests/Feature/ReportAttachmentLinkTest.php` |
| Download | `tests/Feature/AnalyticalReportDownloadTest.php` |
| Policy por papel e status | `tests/Feature/AnalyticalReportAuthorizationTest.php` |
| Filament (lista, View, actions) | `tests/Feature/AnalyticalReportResourceTest.php` |
| Retenção / force delete / wipe de cadastro | `tests/Feature/AnalyticalReportPruneTest.php` |
| Schema | `tests/Feature/AnalyticalReportSchemaTest.php` |
| Writer (fórmula como texto, HYPERLINK, URL longa) | `tests/Feature/SpreadsheetWriterTest.php` |
| Unit | `tests/Unit/AnalyticalReportStatusTest.php` |

---

## Decisões relevantes

1. **Dashboard próprio** no lugar do `Filament\Pages\Dashboard`, sem Livewire custom: `HasFiltersForm` + widgets nativos com `InteractsWithPageFilters`.
2. **Pago por baixa, não por status:** o valor vem do item ativo de baixa `settled` na `settlement_date`. Editar `net_amount` de PR já paga não muda o Pago.
3. **Posição em aberto em "hoje"**, limitada só por `period_end`: vencido de mês anterior continua aparecendo.
4. **Cache versionado sem tags**, flush após o commit, locale na chave. TTL curto (300 s) no lugar de invalidação fina.
5. **Tabela dedicada `analytical_reports`** em vez de Filament Exports: snapshot dos filtros, estados próprios, retenção e policy.
6. **OpenSpout direto** atrás de `SpreadsheetWriter`, sem pacote novo; texto sempre como texto (defesa contra injeção de fórmula).
7. **Link de anexo por rota do painel** (sessão + assinatura sem expiração + policy) em vez de URL pré-assinada do S3 gravada no Excel: o arquivo pode circular e a URL do S3 expiraria.
8. **Job sem `WithoutOverlapping`:** a idempotência vem dos updates condicionais (`generating`, `generated`, `failed`). Timeout 75 fica abaixo do `retry_after` 90 da conexão default.
9. **Relatório sem reconciliador:** em andamento há mais de 30 min vira "stale" e o Retry aparece na UI.
10. **Soft-deleted:** `generate()` desiste de relatório excluído; Operador perde acesso, Adm mantém.
11. **Arquivo nunca no e-mail;** a notificação aponta para a View.
12. **Polling da View por `wire:poll.5s` + `refreshReport()`**, porque `Section::poll()` do Filament 5.7 não atualiza o cabeçalho.

---

## Fora de escopo

| Item | Fase prevista |
|------|---------------|
| API REST / Sanctum / Swagger | — |
| Exportação da tabela do Filament (Filament Exports) | — |
| Relatório para o perfil Cliente | — |
| Anexar o `.xlsx` ao e-mail | — (decisão: só link para a View) |
| Índices novos para as agregações do dashboard | — (gatilho em blueprint §19 item 4) |
| Driver S3 (`league/flysystem-aws-s3-v3`) e OpenSpout como `require` direto | Aprovação de dependência separada |

---

## Como validar

### 1. Ambiente e migrations

```bash
docker compose exec app php artisan migrate
```

Não foi verificado se a migration `create_analytical_reports_table` já rodou no banco de desenvolvimento.

### 2. Variáveis de ambiente (`.env`)

| Variável | Default no código | `.env.example` | Descrição |
|----------|-------------------|----------------|-----------|
| `RJET_REPORTS_DISK` | `FILESYSTEM_DISK` (também quando **vazia**) | vazio | Disco privado dos `.xlsx` (S3 em produção) |
| `RJET_REPORTS_QUEUE` | `default` | `default` | Fila do `GenerateAnalyticalReportJob` (conexão default) |
| `RJET_REPORTS_MAX_ROWS` | `20000` | `20000` | Teto de linhas por relatório |
| `RJET_REPORTS_RETENTION_DAYS` | `30` | `30` | Retenção antes do `model:prune` |
| `RJET_DASHBOARD_CACHE_TTL` | `300` | `300` | TTL do cache do dashboard (`0` desliga) |

`RJET_REPORTS_DISK` usa `env(...) ?: env('FILESYSTEM_DISK', 'local')`: a variável presente e vazia no `.env` cai no `FILESYSTEM_DISK`.

### 3. Workers e agendador

- O job roda na conexão e fila **default**: os workers existentes já o consomem. Nenhum processo novo.
- `model:prune` depende do agendador. O `supervisord-prod.conf` **não** tem agendador (pendência registrada na entrega da F7); sem ele a retenção de 30 dias não roda em produção.

```bash
docker compose exec app php artisan config:show rjet.reports
docker compose exec app php artisan schedule:list
docker compose exec app php artisan route:list --name=report-attachments
```

### 4. Testes Pest

```bash
# Subconjunto Fase 8 (esperado: 152 passed, 541 assertions)
docker compose exec -T app php artisan test --compact --filter='AnalyticalReport|Dashboard|GenerateAnalyticalReportJob|ReportAttachmentLink|SpreadsheetWriter'

# Suíte completa (2026-09-27: 833 passed, 2783 assertions, ~29 s)
docker compose exec app php artisan test --compact
```

### 5. Smoke manual (Filament)

> Não executado no navegador para esta entrega. Roteiro sugerido:

1. **Cliente:** abrir o Painel. Cards e gráficos mostram só as filiais vinculadas; os selects de empresa/filial listam só essas; o botão "Gerar relatório analítico" não aparece e "Relatórios analíticos" não está no menu.
2. **Operador:** conferir Pendentes = A vencer + Vencidos. Mudar período para um mês encerrado: "A vencer" zera com "período encerrado". Filtrar filial: gráficos e cards batem.
3. Base com mais de 12 filiais ou centros de custo: no máximo 12 barras, a última "Outros".
4. **Gerar relatório analítico** a partir do Painel (modal pré-preenchido com os filtros) ou da lista. A View mostra `Na fila → Gerando → Gerado` sozinha a cada 5 s e o botão **Baixar** aparece no cabeçalho. Notificação no sininho e por e-mail com link para a View.
5. Abrir o `.xlsx`: abas Solicitações e Anexos, cabeçalho em negrito e congelado, datas e valores formatados, colunas Anexo 1..N.
6. Clicar num link de anexo: sem sessão → login; com sessão → arquivo abre. Com URL adulterada → 403.
7. Pedir um quarto relatório com três em andamento → notificação de limite. Período sem PR → "nenhuma solicitação".
8. **Adm:** excluir um relatório; como Operador, ele some da lista e a URL da View não abre. Como Adm, com o filtro de excluídos, ainda baixar e restaurar. ForceDelete apaga o arquivo.

---

## Riscos / pendências

- **P-F8-COLS — colunas do Excel a validar com a RJET.** Não bloqueia. Mudança futura é só tradução (`analytical_reports.columns.*`) + writer/`requestRow()`, sem migration.
- **P-S3-DRIVER — driver S3 ausente do lock.** `temporaryUrl` do link de anexo em produção S3 depende de `league/flysystem-aws-s3-v3`. Em disco local o fallback é stream inline. Exige aprovação de dependência.
- **P-F5-OPENSPOUT — promover `openspout/openspout` a `require` direto.** Não bloqueia enquanto `filament/actions` o trouxer como transitivo.
- **Listeners duplicados pré-existentes:** `LogPaymentRequestActivity` (e o listener do lote importado) já apareciam duplicados no `event:list` antes desta fase (`Event::listen` explícito + discovery). Os listeners novos da F8 aparecem uma vez.
- **Ajustes deixados de fora de propósito:**
  - o teto de 3 relatórios em geração não usa lock: dois cliques simultâneos podem passar;
  - o corte do rótulo do `HYPERLINK` em 255 acontece antes de escapar as aspas (rótulo com muitas aspas pode passar do limite);
  - o modal do dashboard pré-preenche o período cru da URL e não troca datas invertidas (o `DashboardFilterData` do painel troca; no modal, o `afterOrEqual` do form e o `invalidPeriod` do `request()` barram o pedido).
- **Duração do job perto de 20 mil linhas:** monitorar contra o timeout 75 (decisão fechada no blueprint §0.7 item 13). Se estourar, revisar `max_rows` ou o timeout antes de mexer na conexão.
- **Agendador de produção ausente** (herdado da F7): sem `schedule:work`/cron, o `model:prune` diário não roda e os arquivos se acumulam.
- **Não verificado nesta entrega:** migration aplicada no banco de desenvolvimento e smoke test no navegador.

---

## Referências

| Documento | Caminho |
|-----------|---------|
| Blueprint (plano de implementação) | [`.ai/blueprints/fase-8-dashboard-relatorios.md`](../blueprints/fase-8-dashboard-relatorios.md) — §0.7 (decisões fechadas), §17 (testes), §19 (pendências) |
| Arquitetura e decisões | [`.ai/arquitetura/fase-8-dashboard-relatorios.md`](../arquitetura/fase-8-dashboard-relatorios.md) — §3.1 (indicadores), §3.5 (cache), §3.8 (links de anexo), §3.9 (fila) |
| DRF v1 (RF035–RF037) | [`.ai/requisitos/drf-financeiro-v1.md`](../requisitos/drf-financeiro-v1.md) — §7.1 (matriz F8) |
| Entrega Fase 7 | [`.ai/entregas/fase-7-baixa-cnab.md`](fase-7-baixa-cnab.md) |
| Config | [`config/rjet.php`](../../config/rjet.php) (`dashboard`, `reports`), [`routes/console.php`](../../routes/console.php) |

---

## Histórico

| Versão | Data | Descrição |
|--------|------|-----------|
| 1.0 | 2026-09-27 | Documento de entrega da Fase 8 |
