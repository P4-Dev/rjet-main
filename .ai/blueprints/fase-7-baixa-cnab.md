# Filament Blueprint: Fase 7 — Baixa em lote e CNAB 240

> **Tipo:** Plano de implementação (Filament Blueprint v5). **NÃO é código de produção.**
> **Escopo:** RF030–RF034. Baixa em lote (`PaymentSettlement` + itens), configuração CNAB 240 por conta bancária da filial (`CnabConfig`), geração assíncrona do arquivo de remessa com dry-run (`CnabFile` + `CnabFileItem` + `GenerateCnabFileJob`), download restrito e confirmação `Launched → Settled`.
> **Fonte de requisitos:** [.ai/arquitetura/fase-7-baixa-cnab.md](../arquitetura/fase-7-baixa-cnab.md), revisão DBA §21 (**aprovado com ressalvas**, já incorporadas no texto da arquitetura). O schema vigente é o **pós-DBA**. Se algum trecho antigo divergir de §21, vale §21 e este blueprint.
> **Stack:** Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL (testes em sqlite) · Redis (filas).
> **Autor:** blueprint · **Data:** 2026-09-26
> **Revisão 2026-09-27:** fila CNAB dedicada + reconciliador de arquivos parados (arquitetura §3.11). Afeta §0.7 item 21, §2, §6.2–§6.4, §6.7, §11.2, §11.3 (novo), §16.5/§16.5.1 e §18.1. Sem índice e sem coluna nova (DBA 2026-09-27).
> **Destinatário:** `implementer`. Este documento é a **única** fonte que o implementador verá. Todas as regras relevantes (Filament v5, DBA, convenções do repo) foram copiadas para dentro dele.
> **Fora de escopo:** Fase 8 (dashboard/Excel), API REST, arquivo de retorno CNAB, tributos/concessionárias (segmentos O/N), PIX por QR Code no CNAB, Livewire custom, redesign de `PaymentRequest`/alçadas/importação/anexos.

---

## Índice

0. [Regras globais Filament v5 (LEIA PRIMEIRO)](#0-regras-globais-filament-v5-leia-primeiro)
1. [Visão geral](#1-visão-geral)
2. [Commands (ordem de execução)](#2-commands-ordem-de-execução)
3. [Models e migrations](#3-models-e-migrations)
4. [Enums](#4-enums)
5. [Exceções de domínio e códigos de validação](#5-exceções-de-domínio-e-códigos-de-validação)
6. [Camadas de aplicação](#6-camadas-de-aplicação)
7. [Filament Resource: CnabConfig](#7-filament-resource-cnabconfig)
8. [Filament Resource: PaymentSettlement](#8-filament-resource-paymentsettlement)
9. [Authorization / Policies](#9-authorization--policies)
10. [State transitions](#10-state-transitions)
11. [Events / Listeners / Notifications / Queue](#11-events--listeners--notifications--queue)
12. [Traduções (i18n)](#12-traduções-i18n)
13. [API REST](#13-api-rest)
14. [Soft deletes, cascade e guards](#14-soft-deletes-cascade-e-guards)
15. [Factories e seeders](#15-factories-e-seeders)
16. [Tests (Pest)](#16-tests-pest)
17. [Estimativa](#17-estimativa)
18. [Notas, checklist e pendências](#18-notas-checklist-e-pendências)
19. [Próximos passos](#19-próximos-passos)

---

## 0. Regras globais Filament v5 (LEIA PRIMEIRO)

### 0.1 Namespaces Filament v5 (obrigatórios; errar aqui quebra a implementação)

| Elemento | Namespace |
|----------|-----------|
| Form fields | `Filament\Forms\Components\{Component}` |
| Table columns | `Filament\Tables\Columns\{Column}` |
| Table filters | `Filament\Tables\Filters\{Filter}` |
| Table summarizers | `Filament\Tables\Columns\Summarizers\{Summarizer}` (ex. `Sum`) |
| **Todas** as Actions (row, header, bulk, page, modal) | `Filament\Actions\{Action}` (`Action`, `BulkAction`, `BulkActionGroup`, `ViewAction`, `EditAction`, `DeleteAction`, `RestoreAction`, `DeleteBulkAction`, `CreateAction`) |
| Infolist entries | `Filament\Infolists\Components\{Entry}` (`TextEntry`, `IconEntry`) |
| Layout (Section, Grid, Fieldset, Tabs, Flex) | `Filament\Schemas\Components\{Component}` |
| Reactive utilities | `Filament\Schemas\Components\Utilities\Get` e `...\Set` |
| Ícones | `Filament\Support\Icons\Heroicon` (**enum**, nunca string no Filament; strings `heroicon-o-*` só dentro de `getIcon()` dos enums de domínio, padrão do repo) |
| Schema container | `Filament\Schemas\Schema` |
| Table container | `Filament\Tables\Table` |
| Table na página custom | `Filament\Tables\Contracts\HasTable` + `Filament\Tables\Concerns\InteractsWithTable` |
| Página de Resource custom | `Filament\Resources\Pages\Page` |
| Notificações toast | `Filament\Notifications\Notification` |
| Auth do painel | `Filament\Facades\Filament` → `Filament::auth()->user()` |

**Namespaces ERRADOS (não usar):**

| Errado | Certo |
|--------|-------|
| `Filament\Forms\Get` / `Filament\Forms\Set` | `Filament\Schemas\Components\Utilities\Get` / `Set` |
| `Filament\Tables\Actions\Action` / `BulkAction` | `Filament\Actions\Action` / `Filament\Actions\BulkAction` |
| `Filament\Forms\Components\Section` | `Filament\Schemas\Components\Section` |
| `Filament\Forms\Components\Actions` | `Filament\Schemas\Components\Actions` |
| `Filament\Pages\Actions\*` | `Filament\Actions\*` |

**Métodos ERRADOS:** `->reactive()` → **`->live()`**. `->actions()` na tabela → **`->recordActions()`**. `->bulkActions()` → **`->toolbarActions([BulkActionGroup::make([...])])`**. `->form()` em Action → **`->schema()`**.

**Componentes que não existem:** `Card` (use `Section`), `BadgeColumn` (use `TextColumn->badge()`), `BooleanColumn` (use `IconColumn->boolean()`), `DateColumn` (use `TextColumn->date()`), `MultiSelect` (use `Select->multiple()`), `BelongsToSelect` (use `Select->relationship()`).

**Docs (backup):** https://filamentphp.com/docs/5.x/resources/overview · https://filamentphp.com/docs/5.x/resources/custom-pages · https://filamentphp.com/docs/5.x/tables/overview · https://filamentphp.com/docs/5.x/tables/filters/overview · https://filamentphp.com/docs/5.x/actions/overview · https://filamentphp.com/docs/5.x/actions/bulk-actions · https://filamentphp.com/docs/5.x/infolists/overview · https://filamentphp.com/docs/5.x/schemas/overview · https://filamentphp.com/docs/5.x/resources/managing-relationships · https://filamentphp.com/docs/5.x/testing/overview

> O MCP Laravel Boost (`search-docs`) **não está disponível** nesta sessão. A sintaxe foi conferida em `.ai/skills/filament/SKILL.md`, em `vendor/filament/blueprint/resources/markdown/planning/*` e nos Resources reais `ImportBatchResource` / `AttachmentBatchResource`. Se tiver `search-docs` na implementação, confirme `Page` + `HasTable` e `BulkAction::schema()` antes de escrever.

### 0.2 Estrutura obrigatória do Resource (pasta PLURAL, Resource LIMPO)

```
app/Filament/Resources/CnabConfigs/                    ← PLURAL
├── CnabConfigResource.php                             ← final, LIMPO: só delegates
├── Schemas/CnabConfigForm.php                         ← final
├── Schemas/CnabConfigInfolist.php                     ← final (SEMPRE)
├── Tables/CnabConfigsTable.php                        ← final, nome PLURAL
├── Pages/{ListCnabConfigs, CreateCnabConfig, EditCnabConfig, ViewCnabConfig}.php
└── RelationManagers/CnabFilesRelationManager.php      ← somente leitura

app/Filament/Resources/PaymentSettlements/             ← PLURAL
├── PaymentSettlementResource.php                      ← final, LIMPO
├── Schemas/PaymentSettlementForm.php                  ← final (schema do modal da bulk action)
├── Schemas/PaymentSettlementInfolist.php              ← final (SEMPRE)
├── Tables/PaymentSettlementsTable.php                 ← final
├── Tables/EligiblePaymentRequestsTable.php            ← final (tabela da página de seleção)
├── Pages/ListPaymentSettlements.php
├── Pages/CreatePaymentSettlement.php                  ← Page + HasTable, NÃO CreateRecord
├── Pages/ViewPaymentSettlement.php
├── (SEM EditPaymentSettlement: baixa imutável)
├── RelationManagers/ItemsRelationManager.php
├── RelationManagers/CnabFilesRelationManager.php
└── Actions/
    ├── CreatePaymentSettlementBulkAction.php
    ├── ConfirmPaymentSettlementAction.php
    ├── CancelPaymentSettlementAction.php
    ├── ReleasePaymentSettlementItemAction.php
    ├── ValidateCnabRemittanceAction.php
    ├── GenerateCnabFileAction.php
    ├── RetryCnabFileGenerationAction.php
    ├── RegenerateCnabFileAction.php
    ├── DownloadCnabFileAction.php
    └── ViewCnabValidationErrorsAction.php
```

**VALIDAÇÃO:** se `*Resource.php` contiver `TextInput`, `TextColumn`, `Section`, `Select`, `Toggle` ou qualquer componente de form/table/layout diretamente, **está errado**. Todas as classes Filament são `final` e têm `declare(strict_types=1);`. Classes de Action Filament seguem o padrão do repo: `final class XAction { public static function make(): Action { ... } }` (ver `DownloadImportSpreadsheetAction`, `TransitionStatusAction`).

**Comando Artisan (SEMPRE usar, NUNCA criar Resource à mão):**

```bash
php artisan make:filament-resource CnabConfig --generate --soft-deletes --view --panel=admin --no-interaction
php artisan make:filament-resource PaymentSettlement --generate --soft-deletes --view --panel=admin --no-interaction
```

Após o scaffold de `PaymentSettlement`: **apagar** `EditPaymentSettlement.php` e **substituir** o `CreatePaymentSettlement` gerado (que estende `CreateRecord`) pela página custom descrita em §8.4.

### 0.3 Resource principal (espelhar `ImportBatchResource`)

Todo Resource desta fase tem, e só tem:

- `protected static ?string $model`, `$navigationIcon` (`Heroicon::...`), `$navigationSort`, `$recordTitleAttribute` (opcional).
- `getModelLabel()`, `getPluralModelLabel()`, `getNavigationLabel()`, `getNavigationGroup()` → todos com `__()`.
- `canViewAny(): bool` → `Filament::auth()->user()?->can('viewAny', Model::class) ?? false`.
- `form()`, `infolist()`, `table()` → delegam para `Schemas/*Form::configure`, `Schemas/*Infolist::configure`, `Tables/*Table::configure`.
- `getRelations()`, `getPages()`.
- `getEloquentQuery()` → `parent::getEloquentQuery()->with([...])` + `visibleTo($user)` quando o model tiver o scope.
- `getRecordRouteBindingEloquentQuery()` → `parent::...->withoutGlobalScopes([SoftDeletingScope::class])->with([...])` (**obrigatório** em models com SoftDeletes, para View de registro excluído e Restore).

### 0.4 Layout (largura efetiva)

| Colunas do Schema | Colunas da Section | Largura efetiva | OK? |
|---|---|---|---|
| 2 | 2 | 25% | **NÃO** |
| **1** | **2** | **50%** | **Sim ← padrão desta fase** |
| 1 | 1 | 100% | Sim (Textarea, relatórios) |

**Decisão:** `$schema->columns(1)` em todo Form e Infolist; cada `Section` com `->columns(2)`; `Textarea`/`notes`/`failure_reason` com `->columnSpanFull()`. O modal da bulk action usa uma coluna (sem Section).

### 0.5 i18n (obrigatório)

Nenhuma string de UI hardcoded. Todo label/heading/placeholder/helperText/hint/notificação/modal usa `__()`. Campos comuns em `common.php` (`common.fields.created_by`, `common.sections.audit`, etc.). Arquivos novos: `payment_settlements.php`, `cnab_configs.php`, `cnab_files.php`. Estender: `enums.php`, `navigation.php` (não precisa de grupo novo), `notifications.php`, `payment_requests.php` (erro `settlement_required` e entries da baixa), `branch_bank_accounts.php` (erros dos guards). Sempre em **`lang/pt_BR/`** e **`lang/en/`**.

### 0.6 Padrões do projeto (F1–F6 já implementados; seguir sem desviar)

- Models de domínio: `final`, `declare(strict_types=1)`, `casts()` como **método**, PK `uuid` via trait `HasUuid` (`Str::orderedUuid()`), traits `HasFactory`, `SoftDeletes`, `HasBlameable` quando aplicável.
- Migrations: `timestampsTz()` + `softDeletesTz()` onde houver SoftDeletes; FK com `->index()` explícito (Postgres); **status/layout/tipo sempre `string`**, nunca `$table->enum()`; **sem** `after()`; **sem** `check()` multi-coluna (validação no Service).
- Unique parcial: **nunca** `$table->unique()` quando há SoftDeletes ou condição. Sempre `DB::statement` dentro de `if ($this->supportsPartialIndexes())`, com `DROP INDEX IF EXISTS` no `down()` **antes** do `Schema::dropIfExists`. Helper privado copiado de `database/migrations/2026_08_12_125043_create_approvals_table.php`:
  ```php
  private function supportsPartialIndexes(): bool
  {
      return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
  }
  ```
- Monetário: `decimal(10,2)`, cast `decimal:2`, somas com bcmath (`bcadd`, `bccomp`), nunca float.
- Policies: registrar na constante `AppServiceProvider::POLICIES`. Observers: `Model::observe(...)` no `AppServiceProvider::boot()` (padrão `ImportBatch::observe`).
- Listeners com `handle(Event)` tipado usam **auto-discovery**. **Não** adicionar `Event::listen` para eles (o `AppServiceProvider` já avisa que duplicaria).
- Erros de domínio: Action Filament captura `BusinessException` → `Notification::make()->title($e->getUserMessage())->danger()->send()` → `$action->halt()`.
- Belt de autorização dentro da Action Filament: `abort_unless(Filament::auth()->user()?->can(...) ?? false, 403)` (padrão `DownloadImportSpreadsheetAction`).
- `Model::preventLazyLoading()` ativo fora de produção → **eager loading obrigatório** em tabelas, RMs, infolists e no job.
- Agrupamento (`agrupar_por_dominio`): Events/Listeners/Actions/Jobs/Integrations por domínio (`Settlement`, `Cnab`); Services/DTOs/Exceptions/Models/Policies/Notifications **flat**.
- Comentários de código: mínimos, em **inglês**. Este documento: pt-BR.
- Timezone de negócio: `America/Sao_Paulo`. "Hoje" = `today('America/Sao_Paulo')`; comparações de `settlement_date` via `->toDateString()`.
- Ambiente Docker (`PROJECT.md`): prefixar artisan/pest/pint/composer com `docker compose exec app`.

### 0.7 Decisões FECHADAS (inclui ressalvas DBA; NÃO reabrir, NÃO inventar)

| # | Decisão |
|---|---------|
| 1 | **Sem** API REST; **sem** Livewire custom (tabela Filament + bulk action cobrem RF030/RF031) |
| 2 | Baixa em **dois passos** na mesma entidade: `draft` (itens + data + conta) → `settled` (confirmação). CNAB é **opcional** e só em `draft` |
| 3 | Uma baixa = **uma filial** = **uma conta pagadora** (`branch_bank_account_id`) = no máximo **um arquivo ativo** |
| 4 | `CnabConfig` é 1:1 com `BranchBankAccount` (chave `branch_bank_account_id`), não `(branch_id, bank_id)` |
| 5 | Unique parcial de config: `(branch_bank_account_id) WHERE deleted_at IS NULL`. **Desativar não libera a vaga.** Substituir config = **soft delete + create** (DBA #2) |
| 6 | NSA **por conta**: `CnabConfigService::create` semeia `last_file_sequence` com `MAX(cnab_files.file_sequence)` das configs anteriores da mesma conta (`withTrashed`). `update` rejeita valor menor (`fileSequenceBelowIssued`). Próximo NSA `> 999999` → `fileSequenceExhausted` (DBA #2, #8) |
| 7 | Unique NSA: `(cnab_config_id, file_sequence) WHERE file_sequence IS NOT NULL`, **sem** `deleted_at` no predicado (NSA de arquivo soft-deleted nunca volta). `CnabFile::config()` com `withTrashed()` |
| 8 | Arquivo ativo por baixa: unique parcial `(payment_settlement_id) WHERE status IN ('queued','generating','generated') AND deleted_at IS NULL`, com **literais SQL** na migration; teste garante igualdade com `CnabFileStatus::activeValues()` (DBA #4) |
| 9 | ForceDelete da baixa: transação; `$settlement->cnabFiles()->withTrashed()->get()->each->forceDelete()` (observer apaga o `.rem`); depois `$settlement->forceDelete()`. Físico **após o commit**. Ordem: `cnab_file_items → cnab_files → payment_settlement_items → payment_settlements`. `forceDelete()` em query builder **não** dispara observer (DBA #1) |
| 10 | `payment_settlements.branch_id` e `cnab_files.layout` são **desnormalizações documentadas** (comentário na migration). Guard: `BranchBankAccount.branch_id` não muda se a conta já foi usada por baixa ou config (DBA #3) |
| 11 | `cnab_file_items`: `unique(cnab_file_id, payment_settlement_item_id)` e `unique(cnab_file_id, reference)` via Blueprint. `reference` = **últimos** 20 hex maiúsculos do UUID da PR sem hífens. **Sem** índice em `is_valid` (DBA #5) |
| 12 | **Sem** índice simples em `payment_settlements.settlement_date` (coberto por `(branch_id, settlement_date)`) e **sem** índice em `cnab_configs.layout` (DBA #7) |
| 13 | `settlement_date` é `date` (como `due_date`), não `*_at`. `settled_at` é o instante (`timestampTz`) da confirmação (DBA #10) |
| 14 | `payment_requests`: **sem colunas novas e sem índice novo** |
| 15 | Status `string(20)` (maior valor: `generating`/`superseded` = 10); `layout` `string(30)`; `payment_type` `string(20)` |
| 16 | `PaymentSettlementItem` e `CnabFileItem` **sem** SoftDeletes. Item de baixa usa `released_at`; item de arquivo é append-only |
| 17 | Itens inválidos no dry-run reprovam o **arquivo inteiro** (tudo ou nada) |
| 18 | `Launched → Settled` **só** via `PaymentSettlementService::confirm`. `TransitionStatusAction` deixa de oferecer `Settled` |
| 19 | Gerador CNAB **próprio** (sem nova dependência). Layout byte a byte fica em `Itau240Layout`, não neste plano |
| 20 | Download via `Storage::disk()->download()` atrás de Policy; **sem** `temporaryUrl`; checksum SHA-256 conferido |
| 21 | ~~Fila `default`~~ **(substituída em 2026-09-27, arquitetura §3.11).** `GenerateCnabFileJob` roda em conexão dedicada `config('rjet.cnab.queue.connection')` (`cnab_database` por padrão; `cnab_redis` em produção e no `.env.example`; `sync` no phpunit), fila `cnab`, `retry_after` **240**. Job: `$timeout` 120, `$tries` **10**, `$maxExceptions` **3**, `$backoff` `[10, 30, 60]`, `WithoutOverlapping(payment_settlement_id)->releaseAfter(30)->expireAfter(180)` (sem `dontRelease()`). Worker `cnab` com `--timeout=150`. Reconciliador `cnab:recover-stuck-files` a cada 5 min: reenfileira o **mesmo** `CnabFile` parado há ≥ 300 s e marca `failed` após 20 min de idade. **Sem índice novo e sem coluna nova** (recusados pela DBA 2026-09-27). Notificações `mail` + `database` ao solicitante (inalterado) |
| 22 | Cliente: **nega tudo** em baixa, config e arquivo |
| 23 | Navegação: `CnabConfigResource` em `settings` sort **30**; `PaymentSettlementResource` em `operations` sort **20** |
| 24 | Widgets: **nenhum** (Fase 8) |

### 0.8 Estado real do código a estender (inspecionado 2026-09-26)

| Artefato | Estado |
|----------|--------|
| `App\Models\PaymentRequest` | Existe: `status` (cast `PaymentRequestStatus`), `payment_method`, `net_amount` decimal:2, `due_date`, `branch_id`; scopes `visibleTo`, `status`, `dueBetween`, `forBranch`; relação `bankDetails()`, `supplier()`, `branch()`; `isEditableBy()` |
| `App\Models\PaymentRequestBankDetails` | Existe: `deposit_type`, `pix_key_type`, `pix_key`, `pix_qr_code`, `digitable_line` (54), `barcode` (48), `bank_id`, `agency`, `agency_digit`, `account_number`, `account_digit`, `account_type`, `holder_name`, `holder_document` |
| `App\Models\BranchBankAccount` | Existe: `branch_id`, `bank_id`, `bank_code` (3), `bank_name`, `agency`, `agency_digit`, `account_number`, `account_digit`, `account_type`, `holder_name`, `is_default`, `is_active`; SoftDeletes; HasBlameable |
| `App\Models\Branch` / `Company` / `Bank` | Existem (`document`, `legal_name`, `is_active`; `Bank.code`, `ispb`) |
| `PaymentRequestService::transitionStatus()` | Existe; `Launched → Settled` hoje é livre → **estreitar** |
| `TransitionStatusAction` (PR) | Oferece `allowedTransitions()` inclusive `Settled` → **remover `Settled`** |
| `App\Events\PaymentRequest\PaymentRequestStatusChanged` | Existe → **implementar `ShouldDispatchAfterCommit`** |
| Jobs de referência | `App\Jobs\PaymentRequest\ProcessImportBatchJob`, `App\Jobs\Attachment\RenameAttachmentBatchJob` |
| Integrations de referência | `App\Integrations\Ocr\*`, `App\Integrations\Spreadsheet\*` (bind no `AppServiceProvider::register()`) |
| Download de referência | `App\Filament\Resources\ImportBatches\Actions\DownloadImportSpreadsheetAction` |
| Polling de referência | `ViewImportBatch::getPollingInterval()` → `'5s'` enquanto não terminal |
| Config `rjet.*` | `attachments`, `ocr`, `imports`; **sem** `cnab` → criar |
| Biblioteca CNAB | Nenhuma (e não adicionar) |
| Morph map | **Sem alteração** |
| `.ai/rules/` | Ausente nesta cópia |

---

## 1. Visão geral

A Fase 7 fecha o ciclo operacional do BPO. Regra literal do `PROJECT.md`: *"tela de baixa com filtros de filial/data vencimento. Na tela de baixa selecionar o banco que foi pago para gravar em todos os pagamentos filtrados e também inserir a data de baixa (data pagamento banco). Status será Pagamento efetivado."* e *"Gerar arquivo cnab de pagamento... Na configuração dos cnabs, inserir dados e parâmetros da conta de cada filial do cliente."*

| Entrega | RF | Artefato |
|---------|----|----------|
| Tela de seleção de PRs elegíveis com filtros e seleção de subconjunto | RF030 | `Pages/CreatePaymentSettlement` (Page + HasTable) + `PaymentRequest::scopeEligibleForSettlement` |
| Data de baixa + conta pagadora gravadas no lote | RF031 | `PaymentSettlement` (`settlement_date`, `branch_bank_account_id`) via bulk action |
| Configuração CNAB 240 por conta da filial (Adm), auditável | RF032 | `CnabConfigResource` + `CnabConfigService` + HasBlameable + `cnab_files.config_snapshot` |
| Geração assíncrona com Strategy por banco, dry-run e falha recuperável | RF033 | `GenerateCnabFileJob` + `CnabRemittanceAdapter`/`Itau240RemittanceAdapter` + `CnabRemittanceValidator` |
| Download restrito com nome na convenção Itaú 240 | RF034 | `DownloadCnabFileAction` + `CnabFilePolicy::download` + `adapter->fileName()` |

### 1.1 Fluxo

```
[1 Seleção]    Baixas → Nova baixa → tabela de PRs Launched elegíveis (filtros filial/vencimento/forma)
               → seleciona → bulk "Baixar selecionados" (data + conta + notas)
               → PaymentSettlement(draft) + itens (snapshot amount). PRs continuam Launched.

[2 CNAB, opcional, só em draft com CnabConfig ativa e settlement_date >= hoje]
               "Validar remessa"  → dry-run síncrono em memória → relatório em modal (não persiste, não consome NSA)
               "Gerar CNAB"       → CnabFile(queued) → evento → listener → GenerateCnabFileJob
               job: generating → dry-run gate → NSA → render → validação estrutural → disco private
                    → generated (+ cnab_file_items) → notificação ao solicitante
                    └─ dry-run reprovado / erro de infra → failed (+ itens com erros) → "Retry" cria novo CnabFile
               "Baixar arquivo"   → stream .rem (Policy + checksum) → upload manual no internet banking

[3 Confirmar]  "Confirmar baixa" (settlement_date <= hoje, sem CNAB queued/generating)
               → transação: cada PR Launched → Settled via transitionStatus(..., $settlement) → baixa settled

[Sem CNAB]     1 → 3 direto (pagamento manual ou banco sem adapter). Confirmação avisa "nenhuma remessa gerada".

[Cancelar]     draft sem CNAB em andamento → cancelled; itens released_at; CnabFile generated → superseded.
               PRs voltam a ser elegíveis.
```

**Consequência prática das datas:** data futura D → cria, gera CNAB, faz upload, confirma em D ou depois. Data passada (pagamento já feito fora do sistema) → cria e confirma, sem CNAB.

### 1.2 Hooks mínimos em código existente

| Arquivo | Mudança |
|---------|---------|
| `App\Services\PaymentRequestService::transitionStatus()` | Novo parâmetro opcional `?PaymentSettlement $settlement = null`. Se `$to === Settled`, exige `$settlement` em `draft` contendo a PR como item **ativo**; senão `PaymentRequestException::settlementRequired()` (nova factory; estender a exceção existente) |
| `App\Filament\Resources\PaymentRequests\Actions\TransitionStatusAction` | Remover `Settled` das opções do Select (filtrar `allowedTransitions()`); se não sobrar opção, action invisível |
| `App\Events\PaymentRequest\PaymentRequestStatusChanged` | `implements ShouldDispatchAfterCommit` |
| `App\Models\PaymentRequest` | Relações `settlementItems()`, `activeSettlementItem()`; scope `scopeEligibleForSettlement()`; helper `isInDraftSettlement()`; `isEditableBy()` retorna `false` para não-Adm em baixa `draft`. **Sem colunas novas** |
| `PaymentRequestInfolist` (opcional) | Entries somente leitura: data de baixa, conta pagadora, link para a baixa |
| `App\Services\BranchBankAccountService` (ou Policy/guard equivalente existente) | Bloquear exclusão com `CnabConfig` viva; bloquear mudança de `branch_id` em conta referenciada por baixa ou config |
| `tests/Feature/Services/PaymentRequestStatusTransitionTest.php` | Casos `Launched → Settled` passam a criar `PaymentSettlement` e passá-la ao service (atualizar, não remover) |

---

## 2. Commands (ordem de execução)

> Ambiente Docker: prefixar tudo com `docker compose exec app`. **Não** existe `EditPaymentSettlement`.

```bash
# ── 2.1 Enums (criar como classe e trocar por enum backed) ────────────────────
php artisan make:class Enums/PaymentSettlementStatus --no-interaction
php artisan make:class Enums/CnabFileStatus --no-interaction
php artisan make:class Enums/CnabLayout --no-interaction
php artisan make:class Enums/CnabPaymentType --no-interaction
# Trocar `final class` por `enum X: string implements HasLabel, HasColor, HasIcon` (§4)

# ── 2.2 Config ────────────────────────────────────────────────────────────────
# Editar config/rjet.php: nova chave 'cnab' (§6.7), inclusive cnab.queue.* e cnab.recovery.*
# Editar config/queue.php: conexões cnab_database e cnab_redis (§6.7, rev. 2026-09-27)
# Editar .env.example e phpunit.xml (RJET_CNAB_QUEUE_CONNECTION; §6.7)

# ── 2.3 Migrations (ORDEM OBRIGATÓRIA) ────────────────────────────────────────
php artisan make:migration create_payment_settlements_table --create=payment_settlements --no-interaction
php artisan make:migration create_payment_settlement_items_table --create=payment_settlement_items --no-interaction
php artisan make:migration create_cnab_configs_table --create=cnab_configs --no-interaction
php artisan make:migration create_cnab_files_table --create=cnab_files --no-interaction
php artisan make:migration create_cnab_file_items_table --create=cnab_file_items --no-interaction

# ── 2.4 Models + Factories ────────────────────────────────────────────────────
php artisan make:model PaymentSettlement --factory --no-interaction
php artisan make:model PaymentSettlementItem --factory --no-interaction
php artisan make:model CnabConfig --factory --no-interaction
php artisan make:model CnabFile --factory --no-interaction
php artisan make:model CnabFileItem --factory --no-interaction
# ESTENDER (não recriar): PaymentRequest (relações/scope/isEditableBy),
#   BranchBankAccountFactory (itau()), PaymentRequestBankDetailsFactory (boleto válido, utilityBill())

# ── 2.5 Exceptions ────────────────────────────────────────────────────────────
php artisan make:class Exceptions/PaymentSettlementException --no-interaction
php artisan make:class Exceptions/CnabException --no-interaction
# ESTENDER: PaymentRequestException::settlementRequired()

# ── 2.6 Support + Integrations (sem HTTP) ─────────────────────────────────────
php artisan make:class Support/BoletoBarcode --no-interaction
php artisan make:interface Integrations/Cnab/CnabRemittanceAdapter --no-interaction
php artisan make:class Integrations/Cnab/CnabAdapterResolver --no-interaction
php artisan make:class Integrations/Cnab/CnabRemittanceValidator --no-interaction
php artisan make:class Integrations/Cnab/FixedWidthFormatter --no-interaction
php artisan make:class Integrations/Cnab/Itau/Itau240RemittanceAdapter --no-interaction
php artisan make:class Integrations/Cnab/Itau/Itau240Layout --no-interaction
# Bind do resolver no AppServiceProvider::register() (§6.5)

# ── 2.7 DTOs / Services ───────────────────────────────────────────────────────
php artisan make:class DTOs/PaymentSettlementData --no-interaction
php artisan make:class DTOs/EligiblePaymentFilterData --no-interaction
php artisan make:class DTOs/CnabRemittanceData --no-interaction
php artisan make:class DTOs/CnabRemittanceItemData --no-interaction
php artisan make:class DTOs/CnabRemittanceResult --no-interaction
php artisan make:class DTOs/CnabValidationError --no-interaction
php artisan make:class DTOs/CnabValidationReport --no-interaction
php artisan make:class Services/PaymentSettlementService --no-interaction
php artisan make:class Services/CnabConfigService --no-interaction
php artisan make:class Services/CnabRemittanceService --no-interaction
php artisan make:class Services/CnabFileService --no-interaction

# ── 2.8 Actions de domínio ────────────────────────────────────────────────────
php artisan make:class Actions/Settlement/CreatePaymentSettlementAction --no-interaction
php artisan make:class Actions/Settlement/ReleasePaymentSettlementItemAction --no-interaction
php artisan make:class Actions/Settlement/ConfirmPaymentSettlementAction --no-interaction
php artisan make:class Actions/Settlement/CancelPaymentSettlementAction --no-interaction
php artisan make:class Actions/Cnab/ValidateCnabRemittanceAction --no-interaction
php artisan make:class Actions/Cnab/RequestCnabFileGenerationAction --no-interaction
php artisan make:class Actions/Cnab/RetryCnabFileGenerationAction --no-interaction
php artisan make:class Actions/Cnab/RegenerateCnabFileAction --no-interaction
php artisan make:class Actions/Cnab/DownloadCnabFileAction --no-interaction
php artisan make:class Actions/Cnab/RecoverStuckCnabFilesAction --no-interaction   # rev. 2026-09-27 (§11.3)

# ── 2.9 Job / Events / Listeners / Notifications ──────────────────────────────
php artisan make:job Cnab/GenerateCnabFileJob --no-interaction
php artisan make:event Settlement/PaymentSettlementCreated --no-interaction
php artisan make:event Settlement/PaymentSettlementConfirmed --no-interaction
php artisan make:event Settlement/PaymentSettlementCancelled --no-interaction
php artisan make:event Cnab/CnabFileGenerationRequested --no-interaction
php artisan make:event Cnab/CnabFileGenerated --no-interaction
php artisan make:event Cnab/CnabFileGenerationFailed --no-interaction
php artisan make:event Cnab/CnabFileDownloaded --no-interaction
php artisan make:listener Settlement/LogPaymentSettlementActivity --no-interaction
php artisan make:listener Cnab/QueueCnabFileGeneration --event=App\\Events\\Cnab\\CnabFileGenerationRequested --no-interaction
php artisan make:listener Cnab/NotifyCnabFileGenerated --event=App\\Events\\Cnab\\CnabFileGenerated --queued --no-interaction
php artisan make:listener Cnab/NotifyCnabFileGenerationFailed --event=App\\Events\\Cnab\\CnabFileGenerationFailed --queued --no-interaction
php artisan make:listener Cnab/LogCnabFileActivity --no-interaction
php artisan make:notification CnabFileGeneratedNotification --no-interaction
php artisan make:notification CnabFileGenerationFailedNotification --no-interaction
# Listeners: auto-discovery (NÃO adicionar Event::listen)

# ── 2.9.1 Reconciliador (rev. 2026-09-27, §11.3) ──────────────────────────────
# app/Console/Commands é flat (padrão ApprovalsEscalateSlaCommand; commands fora de agrupar_por_dominio)
php artisan make:command CnabRecoverStuckFilesCommand --command=cnab:recover-stuck-files --no-interaction
# Agendar em routes/console.php: Schedule::command('cnab:recover-stuck-files')->everyFiveMinutes()->withoutOverlapping();

# ── 2.10 Observers ────────────────────────────────────────────────────────────
php artisan make:observer PaymentSettlementObserver --model=PaymentSettlement --no-interaction
php artisan make:observer CnabFileObserver --model=CnabFile --no-interaction
# Registrar no AppServiceProvider::boot(): PaymentSettlement::observe(...); CnabFile::observe(...)

# ── 2.11 Policies ─────────────────────────────────────────────────────────────
php artisan make:policy PaymentSettlementPolicy --model=PaymentSettlement --no-interaction
php artisan make:policy CnabConfigPolicy --model=CnabConfig --no-interaction
php artisan make:policy CnabFilePolicy --model=CnabFile --no-interaction
# Registrar as três em AppServiceProvider::POLICIES

# ── 2.12 Filament ─────────────────────────────────────────────────────────────
php artisan make:filament-resource CnabConfig --generate --soft-deletes --view --panel=admin --no-interaction
php artisan make:filament-relation-manager CnabConfigResource cnabFiles file_sequence --panel=admin --no-interaction
php artisan make:filament-resource PaymentSettlement --generate --soft-deletes --view --panel=admin --no-interaction
# Apagar Pages/EditPaymentSettlement.php e remover de getPages()
# Reescrever Pages/CreatePaymentSettlement.php como Page + HasTable (§8.4)
php artisan make:filament-relation-manager PaymentSettlementResource items id --panel=admin --no-interaction
php artisan make:filament-relation-manager PaymentSettlementResource cnabFiles file_sequence --panel=admin --no-interaction
# Actions Filament em PaymentSettlements/Actions/ criadas à mão (padrão make(): Action)
# View Blade do relatório de dry-run: resources/views/filament/cnab/validation-report.blade.php

# ── 2.13 Seeder (apenas dev) ──────────────────────────────────────────────────
php artisan make:seeder CnabConfigSeeder --no-interaction
# Chamar a partir do DevelopmentSeeder

# ── 2.14 Testes ───────────────────────────────────────────────────────────────
php artisan make:test --pest --unit BoletoBarcodeTest --no-interaction
php artisan make:test --pest --unit CnabRemittanceValidatorTest --no-interaction
php artisan make:test --pest --unit SettlementEnumsTest --no-interaction
php artisan make:test --pest PaymentSettlementEligibilityTest --no-interaction
php artisan make:test --pest PaymentSettlementServiceTest --no-interaction
php artisan make:test --pest PaymentSettlementAuthorizationTest --no-interaction
php artisan make:test --pest PaymentRequestEditabilityInSettlementTest --no-interaction
php artisan make:test --pest CnabConfigServiceTest --no-interaction
php artisan make:test --pest CnabRemittanceDryRunTest --no-interaction
php artisan make:test --pest Itau240RemittanceAdapterTest --no-interaction
php artisan make:test --pest GenerateCnabFileJobTest --no-interaction
php artisan make:test --pest CnabFileDownloadTest --no-interaction
php artisan make:test --pest SettlementSchemaConstraintsTest --no-interaction
php artisan make:test --pest PaymentSettlementResourceTest --no-interaction
php artisan make:test --pest CnabConfigResourceTest --no-interaction
php artisan make:test --pest CnabRecoverStuckFilesCommandTest --no-interaction   # rev. 2026-09-27
# ATUALIZAR (não criar): tests/Feature/Services/PaymentRequestStatusTransitionTest.php
# Golden files: tests/Fixtures/cnab/itau240/*.rem

# ── 2.15 Qualidade ────────────────────────────────────────────────────────────
php artisan migrate
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter='Settlement|Cnab|BoletoBarcode|PaymentRequestStatusTransition'
```

---

## 3. Models e migrations

> Schema **DBA-aprovado** (arquitetura §4 + §21). Convenções: PK UUID; FK com `->index()`; `timestampsTz`/`softDeletesTz`; blameable `nullOnDelete`; status `string`; sem `after()`; sem `check()`; sem `$table->enum()`.

### 3.1 Ordem das migrations e `down()`

1. `create_payment_settlements_table`
2. `create_payment_settlement_items_table` (+ unique parcial `payment_settlement_items_active_request_unique`)
3. `create_cnab_configs_table` (+ unique parcial `cnab_configs_account_unique`)
4. `create_cnab_files_table` (+ uniques parciais `cnab_files_settlement_active_unique` e `cnab_files_config_sequence_unique`)
5. `create_cnab_file_items_table` (+ 2 uniques Blueprint)

Cada `down()`: `DROP INDEX IF EXISTS` de cada índice parcial (dentro de `supportsPartialIndexes()`) **antes** de `Schema::dropIfExists`. Rollback em ordem inversa (5 → 1).

### 3.2 Matriz de FKs (fechada pela DBA)

| Filha.coluna | Pai | `onDelete` | Motivo |
|--------------|-----|-----------|--------|
| `payment_settlements.branch_id` | `branches` | **restrict** | Registro financeiro bloqueia hard delete da filial |
| `payment_settlements.branch_bank_account_id` | `branch_bank_accounts` | **restrict** | Idem para a conta pagadora |
| `payment_settlement_items.payment_settlement_id` | `payment_settlements` | **cascade** | Filha hard-owned; só dispara no forceDelete |
| `payment_settlement_items.payment_request_id` | `payment_requests` | **restrict** | PR que passou por baixa não é apagada fisicamente |
| `cnab_configs.branch_bank_account_id` | `branch_bank_accounts` | **restrict** | Config bloqueia hard delete da conta |
| `cnab_files.payment_settlement_id` | `payment_settlements` | **restrict** | Obriga a apagar arquivos (e o físico, via observer) antes da baixa |
| `cnab_files.cnab_config_id` | `cnab_configs` | **restrict** | Config com arquivo emitido não é apagada fisicamente |
| `cnab_file_items.cnab_file_id` | `cnab_files` | **cascade** | Append-only, hard-owned |
| `cnab_file_items.payment_settlement_item_id` | `payment_settlement_items` | **restrict** | Item de baixa já escrito em arquivo não some sozinho |
| `*_by` (`created_by`, `updated_by`, `settled_by`, `cancelled_by`, `released_by`, `superseded_by`, `downloaded_by`) | `users` | **nullOnDelete** | Blameable (padrão F1–F6) |

**Sem ciclo de restrict.** O caminho cruzado `payment_settlements ← cnab_files ← cnab_file_items → payment_settlement_items → payment_settlements` resolve-se com a ordem fixa de forceDelete (§14.2): arquivos primeiro (itens de arquivo por cascade), depois a baixa (itens de baixa por cascade). Tentar a baixa primeiro falha no restrict de `cnab_files` (erro, sem corrupção).

### 3.3 Desnormalizações (comentário obrigatório em inglês na migration, uma linha, estilo `payment_requests.net_amount`)

| # | Coluna | Comentário sugerido |
|---|--------|---------------------|
| 1 | `payment_settlements.items_count`, `total_amount` | `// Denormalized: list cache — source of truth is active items (recalculated in service)` |
| 2 | `payment_settlement_items.amount` | `// Snapshot of payment_requests.net_amount at selection time` |
| 3 | `cnab_files.config_snapshot` | `// Snapshot of config + account + branch used to generate this file` |
| 4 | `cnab_files.items_count`, `total_amount`, `records_count` | `// Denormalized: generated file metrics, immutable after generated` |
| 5 | `cnab_file_items.amount`, `reference` | `// Snapshot of what was written to the remittance file` |
| 6 | `payment_settlements.branch_id` | `// Denormalized: transitive via branch_bank_account_id, kept for listing/scope; consistency enforced in service` |
| 7 | `cnab_files.layout` | `// Denormalized: snapshot of cnab_configs.layout used to resolve the adapter` |

Comentário nas FKs cascade (padrão `attachment_batch_items`): `// applies to hard/force delete only`.

### 3.4 `PaymentSettlement`

```
Migration: create_payment_settlements_table
Model: App\Models\PaymentSettlement (final)
Traits: HasFactory, HasUuid, SoftDeletes, HasBlameable
SoftDeletes: SIM (delete só Adm e só em cancelled)
```

| Campo | Tipo | Nullable | Default | Índice / FK | Descrição |
|-------|------|:--------:|---------|-------------|-----------|
| `id` | uuid | não | – | PK | |
| `branch_id` | foreignUuid | não | – | `->index()` → `branches` restrict | Filial única. **Desnormalizado** (#6) |
| `branch_bank_account_id` | foreignUuid | não | – | `->index()` → `branch_bank_accounts` restrict | Conta pagadora; deve pertencer a `branch_id` (Service) |
| `status` | string(20) | não | `'draft'` | `->index()` | `PaymentSettlementStatus` |
| `settlement_date` | date | não | – | **sem** índice simples | Data de baixa/pagamento no banco (RF031). `date` como `due_date` |
| `items_count` | unsignedInteger | não | 0 | – | Desnormalizado (#1) |
| `total_amount` | decimal(10,2) | não | 0 | – | Desnormalizado (#1); overflow → `totalAmountOverflow` |
| `notes` | text | sim | null | – | |
| `settled_at` | timestampTz | sim | null | – | Confirmação |
| `settled_by` | foreignUuid | sim | null | `->index()` → users nullOnDelete | |
| `cancelled_at` | timestampTz | sim | null | – | |
| `cancelled_by` | foreignUuid | sim | null | `->index()` → users nullOnDelete | |
| `cancellation_reason` | string(500) | sim | null | – | |
| `created_by` / `updated_by` | foreignUuid | sim | null | `->index()` → users nullOnDelete | HasBlameable |
| `created_at` / `updated_at` | timestampTz | sim | – | – | `timestampsTz()` |
| `deleted_at` | timestampTz | sim | null | – | `softDeletesTz()` |

**Índice composto:** `$table->index(['branch_id', 'settlement_date']);`

**Casts:** `status` → `PaymentSettlementStatus::class`; `settlement_date` → `date`; `total_amount` → `decimal:2`; `items_count` → `integer`; `settled_at`, `cancelled_at` → `datetime`.

**Fillable:** `branch_id`, `branch_bank_account_id`, `status`, `settlement_date`, `items_count`, `total_amount`, `notes`, `settled_at`, `settled_by`, `cancelled_at`, `cancelled_by`, `cancellation_reason`.

**Relationships:**
```php
public function branch(): BelongsTo                  // Branch
public function branchBankAccount(): BelongsTo       // ->withTrashed()
public function items(): HasMany                     // PaymentSettlementItem
public function activeItems(): HasMany               // ->whereNull('released_at')
public function cnabFiles(): HasMany                 // CnabFile
public function currentCnabFile(): HasOne            // ->ofMany: latest com status em CnabFileStatus::activeValues()
public function creator(): BelongsTo                 // created_by
public function settler(): BelongsTo                 // settled_by
public function canceller(): BelongsTo               // cancelled_by
```

> `currentCnabFile` como `hasOne(CnabFile::class)->ofMany(['created_at' => 'max'], fn ($q) => $q->whereIn('status', CnabFileStatus::activeValues()))`. Para "último arquivo" (inclusive `failed`, usado no Retry) adicionar `latestCnabFile(): HasOne` → `->latestOfMany()`.

**Scopes:** `scopeVisibleTo(Builder, User)` (Operador/Adm tudo; Cliente `whereRaw('1 = 0')`, igual `ImportBatch`); `scopeStatus(Builder, PaymentSettlementStatus)`; `scopeForBranch(Builder, Branch|string)`.

**Helpers:** `isDraft(): bool`, `isTerminal(): bool`, `hasActiveCnabGeneration(): bool` (arquivo `queued|generating`), `hasGeneratedCnabFile(): bool` (arquivo `generated`), `cnabConfig(): ?CnabConfig` (config **viva e ativa** da conta, `CnabConfig::active()->forAccount($this->branch_bank_account_id)->first()`), `isSettlementDateFuture(): bool`, `isSettlementDatePast(): bool` (comparação com `today('America/Sao_Paulo')`).

### 3.5 `PaymentSettlementItem`

```
Migration: create_payment_settlement_items_table
Model: App\Models\PaymentSettlementItem (final)
Traits: HasFactory, HasUuid — SEM SoftDeletes, SEM HasBlameable
Ciclo de vida: released_at (cancelar ≠ excluir)
```

| Campo | Tipo | Nullable | Default | Índice / FK | Descrição |
|-------|------|:--------:|---------|-------------|-----------|
| `id` | uuid | não | – | PK | |
| `payment_settlement_id` | foreignUuid | não | – | `->index()` → `payment_settlements` **cascade** | `// applies to hard/force delete only` |
| `payment_request_id` | foreignUuid | não | – | `->index()` → `payment_requests` **restrict** | Índice simples mantido (histórico) além do parcial |
| `amount` | decimal(10,2) | não | – | – | Snapshot de `net_amount` (#2) |
| `released_at` | timestampTz | sim | null | – | Cancelamento da baixa ou remoção do item |
| `released_by` | foreignUuid | sim | null | `->index()` → users nullOnDelete | |
| `created_at` / `updated_at` | timestampTz | sim | – | – | `timestampsTz()` |

**Unique parcial (crítico, concorrência):**

```php
if ($this->supportsPartialIndexes()) {
    DB::statement(
        'CREATE UNIQUE INDEX payment_settlement_items_active_request_unique ON payment_settlement_items (payment_request_id) WHERE released_at IS NULL'
    );
}
// down():
// if ($this->supportsPartialIndexes()) {
//     DB::statement('DROP INDEX IF EXISTS payment_settlement_items_active_request_unique');
// }
// Schema::dropIfExists('payment_settlement_items');
```

Uma PR tem no máximo **um** item ativo (em baixa `draft` ou `settled`). O predicado não olha `payment_settlements.deleted_at` e isso é seguro: só baixa `cancelled` (itens já liberados) pode ser soft-deleted.

**Casts:** `amount` → `decimal:2`; `released_at` → `datetime`.
**Fillable:** `payment_settlement_id`, `payment_request_id`, `amount`, `released_at`, `released_by`.
**Relationships:** `settlement(): BelongsTo` (FK `payment_settlement_id`), `paymentRequest(): BelongsTo` (`->withTrashed()`), `releaser(): BelongsTo` (`released_by`), `cnabFileItems(): HasMany`.
**Scopes:** `scopeActive(Builder)` → `whereNull('released_at')`.
**Helper:** `isActive(): bool`.

### 3.6 `CnabConfig`

```
Migration: create_cnab_configs_table
Model: App\Models\CnabConfig (final)
Traits: HasFactory, HasUuid, SoftDeletes, HasBlameable
SoftDeletes: SIM (soft delete = caminho de substituição; desativar só pausa)
```

| Campo | Tipo | Nullable | Default | Índice / FK | Descrição |
|-------|------|:--------:|---------|-------------|-----------|
| `id` | uuid | não | – | PK | |
| `branch_bank_account_id` | foreignUuid | não | – | `->index()` → `branch_bank_accounts` restrict | Chave de negócio |
| `layout` | string(30) | não | – | **sem índice** | `CnabLayout` |
| `company_name` | string(30) | sim | null | – | Nome no header. 30 = limite do campo no layout (fallback `branch.legal_name` truncado pelo adapter) |
| `agreement_code` | string(20) | sim | null | – | Convênio (Itaú SISPAG não exige) |
| `wallet_code` | string(10) | sim | null | – | Reservado a adapters futuros |
| `payment_type_code` | string(2) | não | `'20'` | – | Tipo de pagamento do lote (`20` fornecedores; confirmar na spec) |
| `last_file_sequence` | unsignedInteger | não | 0 | – | Último NSA usado. `integer` no Postgres: Service/Form garantem `0..999999` |
| `is_active` | boolean | não | true | `->index()` | Mantido por consistência com `banks`/`branch_bank_accounts` |
| `created_by` / `updated_by` | foreignUuid | sim | null | `->index()` → users nullOnDelete | RF032 auditável |
| `created_at` / `updated_at` / `deleted_at` | timestampTz | sim | – | – | `timestampsTz()` + `softDeletesTz()` |

**Unique parcial:**

```php
if ($this->supportsPartialIndexes()) {
    DB::statement(
        'CREATE UNIQUE INDEX cnab_configs_account_unique ON cnab_configs (branch_bank_account_id) WHERE deleted_at IS NULL'
    );
}
// down(): DROP INDEX IF EXISTS cnab_configs_account_unique; depois dropIfExists
```

Config **inativa** continua ocupando a vaga.

**Casts:** `layout` → `CnabLayout::class`; `is_active` → `boolean`; `last_file_sequence` → `integer`.
**Fillable:** `branch_bank_account_id`, `layout`, `company_name`, `agreement_code`, `wallet_code`, `payment_type_code`, `last_file_sequence`, `is_active`.
**Relationships:** `branchBankAccount(): BelongsTo` (`->withTrashed()`), `cnabFiles(): HasMany`, `creator()`, `updater()`. Acessores de leitura `branch()` e `bank()` via conta (sem colunas).
**Scopes:** `scopeActive(Builder)` (`is_active = true`), `scopeForAccount(Builder, BranchBankAccount|string)`.
**Helpers:** `hasIssuedFiles(): bool` → `cnabFiles()->withTrashed()->whereNotNull('file_sequence')->exists()`.
**Regra de edição:** após o primeiro arquivo emitido, `branch_bank_account_id`, `layout` e `last_file_sequence` ficam imutáveis (`configLockedAfterIssue`). Mudar exige **soft delete** + nova config, que herda o NSA da conta.

### 3.7 `CnabFile`

```
Migration: create_cnab_files_table
Model: App\Models\CnabFile (final)
Traits: HasFactory, HasUuid, SoftDeletes, HasBlameable
SoftDeletes: SIM (sem delete na UI; soft só em cascata da baixa)
```

| Campo | Tipo | Nullable | Default | Índice / FK | Descrição |
|-------|------|:--------:|---------|-------------|-----------|
| `id` | uuid | não | – | PK | |
| `payment_settlement_id` | foreignUuid | não | – | `->index()` → `payment_settlements` **restrict** | |
| `cnab_config_id` | foreignUuid | não | – | `->index()` → `cnab_configs` **restrict** | |
| `layout` | string(30) | não | – | – | Snapshot (#7) |
| `status` | string(20) | não | `'queued'` | `->index()` | `CnabFileStatus` |
| `file_sequence` | unsignedInteger | sim | null | parcial abaixo | NSA; atribuído uma vez na geração aprovada; máx. 999999 |
| `disk` | string(50) | sim | null | – | |
| `path` | string(500) | sim | null | – | Interno; **nunca** exibido |
| `filename` | string(100) | sim | null | – | Nome de download |
| `size` | unsignedInteger | sim | null | – | bytes |
| `checksum` | string(64) | sim | null | – | SHA-256 hex |
| `records_count` | unsignedInteger | não | 0 | – | Desnormalizado (#4) |
| `items_count` | unsignedInteger | não | 0 | – | Desnormalizado (#4) |
| `total_amount` | decimal(10,2) | não | 0 | – | Desnormalizado (#4) |
| `config_snapshot` | json | sim | null | – | #3 (precedente `import_batches.mappings_snapshot`) |
| `failure_reason` | text | sim | null | – | Mensagem segura (UI) |
| `started_at` | timestampTz | sim | null | – | |
| `generated_at` | timestampTz | sim | null | – | |
| `superseded_at` | timestampTz | sim | null | – | |
| `superseded_by` | foreignUuid | sim | null | `->index()` → users nullOnDelete | |
| `supersede_reason` | string(500) | sim | null | – | |
| `downloaded_at` | timestampTz | sim | null | – | Primeiro download |
| `downloaded_by` | foreignUuid | sim | null | `->index()` → users nullOnDelete | |
| `created_by` / `updated_by` | foreignUuid | sim | null | `->index()` → users nullOnDelete | Quem solicitou |
| `created_at` / `updated_at` / `deleted_at` | timestampTz | sim | – | – | |

**Uniques parciais (literais SQL, NÃO importar enum na migration):**

```php
if ($this->supportsPartialIndexes()) {
    // Literals must match CnabFileStatus::activeValues() (guarded by test)
    DB::statement(
        'CREATE UNIQUE INDEX cnab_files_settlement_active_unique ON cnab_files (payment_settlement_id) WHERE status IN (\'queued\', \'generating\', \'generated\') AND deleted_at IS NULL'
    );
    // No deleted_at on purpose: a file sequence is never reused, even for trashed files
    DB::statement(
        'CREATE UNIQUE INDEX cnab_files_config_sequence_unique ON cnab_files (cnab_config_id, file_sequence) WHERE file_sequence IS NOT NULL'
    );
}
// down():
// if ($this->supportsPartialIndexes()) {
//     DB::statement('DROP INDEX IF EXISTS cnab_files_settlement_active_unique');
//     DB::statement('DROP INDEX IF EXISTS cnab_files_config_sequence_unique');
// }
// Schema::dropIfExists('cnab_files');
```

Novo status "ativo" no futuro exige migration que recria o índice. **Sem** índice em `disk`, `path`, `filename`, `checksum`, `config_snapshot`, `generated_at`.

**Casts:** `status` → `CnabFileStatus::class`; `layout` → `CnabLayout::class`; `config_snapshot` → `array`; `total_amount` → `decimal:2`; `file_sequence`, `size`, `records_count`, `items_count` → `integer`; `started_at`, `generated_at`, `superseded_at`, `downloaded_at` → `datetime`.
**Fillable:** todas as colunas exceto `id` e timestamps.
**Relationships:** `settlement(): BelongsTo` (`->withTrashed()`), `config(): BelongsTo` (`CnabConfig`, FK `cnab_config_id`, **`->withTrashed()`**), `items(): HasMany` (`CnabFileItem`), `creator()`, `downloader()`, `superseder()`.
**Helpers:** `isDownloadable(): bool` (`generated` + `path` preenchido), `isActive(): bool` (`status->isActive()`), `isRetryable(): bool` (`failed` + baixa `draft`).

### 3.8 `CnabFileItem` (append-only)

```
Migration: create_cnab_file_items_table
Model: App\Models\CnabFileItem (final)
Traits: HasFactory, HasUuid — SEM SoftDeletes, SEM HasBlameable (escrito pelo job)
OBRIGATÓRIO no model:
  public $timestamps = false;
  public const UPDATED_AT = null;
  // created_at vem de useCurrent()
```

| Campo | Tipo | Nullable | Default | Índice / FK | Descrição |
|-------|------|:--------:|---------|-------------|-----------|
| `id` | uuid | não | – | PK | |
| `cnab_file_id` | foreignUuid | não | – | `->index()` → `cnab_files` **cascade** | `// applies to hard/force delete only` (filha sem SoftDeletes) |
| `payment_settlement_item_id` | foreignUuid | não | – | `->index()` → `payment_settlement_items` **restrict** | |
| `payment_type` | string(20) | não | – | – | `CnabPaymentType` |
| `payment_form_code` | string(2) | sim | null | – | Forma no lote (`30`, `31`, `01`, `41`, `45`); null se inválido |
| `batch_number` | unsignedSmallInteger | sim | null | – | Lote no arquivo |
| `record_sequence` | unsignedInteger | sim | null | – | Sequencial do primeiro segmento do item no lote |
| `reference` | string(20) | não | – | unique composto | "Seu número" (#5) |
| `amount` | decimal(10,2) | não | – | – | Snapshot (#5) |
| `is_valid` | boolean | não | true | **sem índice** | |
| `validation_errors` | json | sim | null | – | Lista `{code, field, params}` |
| `created_at` | timestampTz | não | `useCurrent()` | – | Sem `updated_at` |

**Uniques Blueprint (permitidos aqui: sem SoftDeletes e sem predicado):**

```php
$table->unique(['cnab_file_id', 'payment_settlement_item_id']);
$table->unique(['cnab_file_id', 'reference']);
$table->timestampTz('created_at')->useCurrent();
```

O `->index()` da FK `cnab_file_id` fica redundante com os uniques, mas é **mantido** pela convenção (toda FK com índice explícito).

**`reference` (DBA #5):** `strtoupper(substr(str_replace('-', '', $paymentRequest->id), -20))`. Os **últimos** 20 hex, porque `Str::orderedUuid()` tem timestamp nos 12 primeiros hex (os primeiros 20 teriam ~28 bits aleatórios; os últimos 20 têm ~74).

**Casts:** `payment_type` → `CnabPaymentType::class`; `amount` → `decimal:2`; `is_valid` → `boolean`; `validation_errors` → `array`; `created_at` → `datetime`.
**Relationships:** `file(): BelongsTo` (FK `cnab_file_id`), `settlementItem(): BelongsTo` (FK `payment_settlement_item_id`).

### 3.9 Hooks em `PaymentRequest` (existente; **sem colunas novas**)

```php
public function settlementItems(): HasMany            // PaymentSettlementItem
public function activeSettlementItem(): HasOne        // ->whereNull('released_at')
public function scopeEligibleForSettlement(Builder $query): Builder
    // status = launched + whereDoesntHave('settlementItems', fn ($q) => $q->whereNull('released_at'))
    // soft delete já excluído pelo scope global
public function isInDraftSettlement(): bool
    // activeSettlementItem com settlement.status === Draft
```

`isEditableBy(User)`: acrescentar **antes** das regras de Operador: `if (! $user->isAdm() && $this->isInDraftSettlement()) { return false; }`.

**Sem índice novo em `payment_requests`** (DBA: ~25 mil linhas/ano; reavaliar `(branch_id, status, due_date)` acima de ~200 mil linhas ou `EXPLAIN ANALYZE` da tela de elegíveis > ~50 ms).

### 3.10 Índices (resumo)

| Tabela | Criar | NÃO criar |
|--------|-------|-----------|
| `payment_settlements` | `status`; `(branch_id, settlement_date)`; FKs | `settlement_date` sozinho, `total_amount`, `items_count`, `notes` |
| `payment_settlement_items` | parcial `payment_request_id WHERE released_at IS NULL`; FKs (inclui `payment_request_id` simples) | `amount`, `released_at` sozinho |
| `cnab_configs` | parcial `branch_bank_account_id WHERE deleted_at IS NULL`; `is_active`; FKs | `layout`, `agreement_code`, `wallet_code` |
| `cnab_files` | `status`; parcial ativo por baixa; parcial `(cnab_config_id, file_sequence)`; FKs | `checksum`, `filename`, `config_snapshot`, `generated_at` |
| `cnab_file_items` | unique `(cnab_file_id, payment_settlement_item_id)`; unique `(cnab_file_id, reference)`; FKs | `reference` sozinho, `is_valid` |
| `payment_requests` | **nada** | – |

---

## 4. Enums

Todos em `App\Enums`, `string`-backed, `implements HasColor, HasIcon, HasLabel` (`Filament\Support\Contracts\*`), labels via `__('enums.{grupo}.{valor}')`. Ícones em string `heroicon-o-*` dentro de `getIcon()` (padrão `PaymentRequestStatus`).

### 4.1 `PaymentSettlementStatus`

| Case | Valor | Cor | Ícone | Label pt_BR |
|------|-------|-----|-------|-------------|
| `Draft` | `draft` | `warning` | `heroicon-o-pencil-square` | Rascunho |
| `Settled` | `settled` | `success` | `heroicon-o-check-badge` | Baixada |
| `Cancelled` | `cancelled` | `gray` | `heroicon-o-x-circle` | Cancelada |

Métodos: `canTransitionTo(self $target): bool` (`Draft → Settled|Cancelled`; demais `false`), `allowedTransitions(): list<self>` (mesmo padrão de `PaymentRequestStatus`), `isTerminal(): bool` (`Settled`, `Cancelled`).

### 4.2 `CnabFileStatus`

| Case | Valor | Cor | Ícone | Label pt_BR |
|------|-------|-----|-------|-------------|
| `Queued` | `queued` | `gray` | `heroicon-o-clock` | Na fila |
| `Generating` | `generating` | `info` | `heroicon-o-arrow-path` | Gerando |
| `Generated` | `generated` | `success` | `heroicon-o-document-check` | Gerado |
| `Failed` | `failed` | `danger` | `heroicon-o-exclamation-triangle` | Falhou |
| `Superseded` | `superseded` | `gray` | `heroicon-o-archive-box-x-mark` | Substituído |

Métodos: `canTransitionTo()` (`Queued → Generating|Failed`; `Generating → Generated|Failed`; `Generated → Superseded`; terminais `false`), `isTerminal()` (`Failed`, `Superseded`), `isActive()` (`Queued|Generating|Generated`), `isInProgress()` (`Queued|Generating`), `static activeValues(): list<string>` → `['queued', 'generating', 'generated']` (usado em queries e `currentCnabFile`; **não** na migration).

### 4.3 `CnabLayout`

| Case | Valor | Label pt_BR | Extra |
|------|-------|-------------|-------|
| `Itau240` | `itau_240` | Itaú — CNAB 240 (SISPAG) | `bankCode(): string` → `'341'` |

`HasLabel` obrigatório. `getColor()` → `'gray'`; `getIcon()` → `'heroicon-o-building-library'` (tipo sem estado; mantém a interface para badge consistente).

### 4.4 `CnabPaymentType`

| Case | Valor | Derivação | Cor | Ícone | Label pt_BR |
|------|-------|-----------|-----|-------|-------------|
| `Boleto` | `boleto` | `payment_method = boleto` | `info` | `heroicon-o-document-text` | Boleto |
| `Transfer` | `transfer` | `deposit` + `deposit_type = transfer` | `primary` | `heroicon-o-arrows-right-left` | Transferência (TED/crédito) |
| `PixKey` | `pix_key` | `deposit` + `deposit_type = pix` + `pix_key` preenchida | `success` | `heroicon-o-bolt` | PIX (chave) |

`static fromPaymentRequest(PaymentRequest $pr): ?self`: null = inelegível para CNAB (PIX só QR). Se tiver chave **e** QR, usa a chave.

### 4.5 Tamanhos (conferidos pela DBA)

| Coluna | Maior valor | Tamanho |
|--------|-------------|---------|
| `payment_settlements.status` | `cancelled` (9) | string(20) |
| `cnab_files.status` | `generating` / `superseded` (10) | string(20) |
| `cnab_configs.layout` / `cnab_files.layout` | `itau_240` (8) | string(30) |
| `cnab_file_items.payment_type` | `transfer` / `pix_key` (8) | string(20) |

---

## 5. Exceções de domínio e códigos de validação

Ambas `final`, estendem `App\Exceptions\BusinessException`. `message` técnico em inglês (log); `userMessage` via `__()`, **sem** paths internos nem dados bancários completos. Seguir o formato de construção das exceções existentes (ex. `AttachmentException`).

### 5.1 `App\Exceptions\PaymentSettlementException`

| Factory | Quando | Chave `userMessage` |
|---------|--------|---------------------|
| `emptySelection()` | Nenhuma PR selecionada | `payment_settlements.errors.empty_selection` |
| `mixedBranches()` | PRs de filiais diferentes | `payment_settlements.errors.mixed_branches` |
| `paymentRequestNotEligible(int $count)` | PR não `Launched`, trashed ou fora do escopo | `payment_settlements.errors.not_eligible` (`:count`) |
| `paymentRequestAlreadyInSettlement()` | Item ativo existente / `UniqueConstraintViolationException` | `payment_settlements.errors.already_in_settlement` |
| `bankAccountNotAllowed()` | Conta não pertence à filial | `payment_settlements.errors.bank_account_not_allowed` |
| `bankAccountInactive()` | Conta inativa, soft-deleted ou sem `bank_id` | `payment_settlements.errors.bank_account_inactive` |
| `tooManyItems(int $max)` | Excede `rjet.cnab.max_items` | `payment_settlements.errors.too_many_items` (`:max`) |
| `totalAmountOverflow()` | Soma não cabe em `decimal(10,2)` (> `99999999.99`) | `payment_settlements.errors.total_amount_overflow` |
| `notDraft(string $status)` | Operação exige `draft` | `payment_settlements.errors.not_draft` |
| `settlementDateInFuture()` | Confirmar com data > hoje | `payment_settlements.errors.settlement_date_in_future` |
| `cnabGenerationInProgress()` | Confirmar/cancelar/remover item com arquivo `queued`/`generating` | `payment_settlements.errors.cnab_in_progress` |
| `cnabFileAlreadyGenerated()` | Remover item com arquivo `generated` ativo | `payment_settlements.errors.cnab_already_generated` |
| `itemsChangedSinceSelection(int $count)` | Confirmar com PR não `Launched`, trashed ou `net_amount ≠ amount` | `payment_settlements.errors.items_changed` (`:count`) |
| `cannotDeleteActive()` | Delete de baixa não `cancelled` | `payment_settlements.errors.cannot_delete_active` |
| `unauthorized()` | Belt no Service | `payment_settlements.errors.unauthorized` |

### 5.2 `App\Exceptions\CnabException`

| Factory | Quando | Chave `userMessage` |
|---------|--------|---------------------|
| `configNotFound()` | Conta sem `CnabConfig` viva | `cnab_files.errors.config_not_found` |
| `configInactive()` | Config desativada | `cnab_files.errors.config_inactive` |
| `configAlreadyExists()` | Segunda config viva para a conta | `cnab_configs.errors.already_exists` |
| `configLockedAfterIssue()` | Mudar conta/layout/sequência após emitir arquivo | `cnab_configs.errors.locked_after_issue` |
| `layoutNotSupported(string $layout)` | Layout sem adapter no resolver | `cnab_configs.errors.layout_not_supported` |
| `bankMismatch(string $expected, string $actual)` | Banco da conta ≠ banco do layout | `cnab_configs.errors.bank_mismatch` (`:expected`, `:actual`) |
| `paymentDateInPast()` | Gerar CNAB com `settlement_date < hoje` | `cnab_files.errors.payment_date_in_past` |
| `settlementNotDraft()` | Gerar CNAB em baixa não `draft` | `cnab_files.errors.settlement_not_draft` |
| `generationAlreadyActive()` | Arquivo ativo existente / unique parcial | `cnab_files.errors.generation_already_active` |
| `remittanceInvalid(int $errorCount)` | Dry-run reprovado | `cnab_files.errors.remittance_invalid` (`:count`) |
| `fileNotRetryable()` | Retry em arquivo não `failed` ou baixa não `draft` | `cnab_files.errors.not_retryable` |
| `fileNotDownloadable()` | Download de arquivo não `generated` | `cnab_files.errors.not_downloadable` |
| `fileMissing()` | Path ausente no disco | `cnab_files.errors.file_missing` |
| `fileIntegrityCheckFailed()` | Checksum divergente (+ log `critical`) | `cnab_files.errors.integrity_failed` |
| `storageWriteFailed()` | `Storage::put` falhou (infra → retry) | `cnab_files.errors.storage_failed` |
| `fileSequenceExhausted()` | Próximo NSA > 999999 | `cnab_files.errors.file_sequence_exhausted` |
| `fileSequenceBelowIssued(int $max)` | Adm grava `last_file_sequence` abaixo do maior NSA emitido para a conta | `cnab_configs.errors.sequence_below_issued` (`:max`) |
| `configHasActiveGeneration()` | Soft delete/desativação de config com arquivo `queued`/`generating` | `cnab_configs.errors.has_active_generation` |

### 5.3 Estender `App\Exceptions\PaymentRequestException`

| Factory | Quando | Chave |
|---------|--------|-------|
| `settlementRequired()` | `transitionStatus(..., Settled)` sem baixa `draft` contendo a PR ativa | `payment_requests.errors.settlement_required` |

### 5.4 Guards de `BranchBankAccount` (exceção existente do domínio de conta, ou `BusinessException` do mesmo padrão de `BankService::ensureDeletable`)

| Guard | Chave |
|-------|-------|
| Excluir conta com `CnabConfig` viva | `branch_bank_accounts.errors.has_cnab_config` |
| Mudar `branch_id` de conta usada por baixa ou config | `branch_bank_accounts.errors.branch_locked` |

### 5.5 Códigos de `CnabValidationError` (não são exceções)

Códigos estáveis, traduzidos em `cnab_files.validation.{code}`:

| Camada | Códigos |
|--------|---------|
| Config | `branch_document_invalid`, `account_data_incomplete`, `bank_code_mismatch` (config inativa/layout não suportado viram exceção antes do pipeline) |
| Item (comum) | `payment_request_unavailable`, `amount_changed`, `amount_not_positive`, `payment_date_in_past` |
| Item boleto | `missing_barcode`, `invalid_barcode`, `utility_bill_not_supported` |
| Item PIX | `pix_qr_code_not_supported`, `missing_pix_key`, `invalid_pix_key` |
| Item transferência | `missing_transfer_data`, `invalid_holder_document`, `missing_beneficiary_name`, `beneficiary_bank_missing` |
| Estrutural (arquivo) | `line_length_invalid`, `non_ascii_character`, `record_order_invalid`, `sequence_gap`, `batch_trailer_mismatch`, `file_trailer_mismatch`, `total_amount_mismatch`, `bank_code_mismatch` |

---

## 6. Camadas de aplicação

### 6.1 DTOs (flat `App\DTOs`, `final readonly class`)

| DTO | Campos | Uso |
|-----|--------|-----|
| `PaymentSettlementData` | `string $branchId`, `string $branchBankAccountId`, `CarbonImmutable $settlementDate`, `list<string> $paymentRequestIds`, `?string $notes` | Criação |
| `EligiblePaymentFilterData` | `?string $branchId`, `?CarbonImmutable $dueFrom`, `?CarbonImmutable $dueUntil`, `?PaymentMethod $paymentMethod` | Query de elegíveis |
| `CnabRemittanceData` | `CnabLayout $layout`, `int $fileSequence`, `CarbonImmutable $generatedAt`, `CarbonImmutable $paymentDate`, `array{document: string, name: string} $company`, `array{bank_code: string, agency: string, agency_digit: ?string, account_number: string, account_digit: ?string} $debitAccount`, `array{agreement_code: ?string, wallet_code: ?string, payment_type_code: string, line_ending: string} $config`, `list<CnabRemittanceItemData> $items` | Entrada do adapter |
| `CnabRemittanceItemData` | `string $settlementItemId`, `string $reference`, `CnabPaymentType $paymentType`, `string $amount`, `CarbonImmutable $dueDate`, `?string $beneficiaryName`, `?string $beneficiaryDocument`, `?string $beneficiaryBankCode`, `?string $agency`, `?string $agencyDigit`, `?string $accountNumber`, `?string $accountDigit`, `?AccountType $accountType`, `?PixKeyType $pixKeyType`, `?string $pixKey`, `?string $barcode` (44) | Um pagamento |
| `CnabRemittanceResult` | `string $content`, `int $recordsCount`, `int $batchesCount`, `array<string, array{batch_number: int, record_sequence: int, payment_form_code: string}> $placements`, `string $totalAmount` | Saída do adapter |
| `CnabValidationError` | `string $code`, `?string $field`, `array $params`, `?string $settlementItemId` | Erro de config, item ou estrutural |
| `CnabValidationReport` | `list<CnabValidationError> $configErrors`, `array<string, list<CnabValidationError>> $itemErrors`, `list<CnabValidationError> $structuralErrors`; métodos `isValid(): bool`, `errorCount(): int` | Dry-run e gate do job |

### 6.2 Services (flat `App\Services`)

**Regra:** Filament e Actions **não** falam com `Storage` nem com adapters diretamente; tudo passa pelos Services.

#### `PaymentSettlementService`

| Método | Comportamento |
|--------|---------------|
| `eligibleQuery(EligiblePaymentFilterData $filter, User $user): Builder` | `PaymentRequest::query()->visibleTo($user)->eligibleForSettlement()` + filtros (`forBranch`, `dueBetween`, `payment_method`) + `with(['supplier', 'bankDetails.bank', 'branch'])` |
| `create(PaymentSettlementData $data, User $actor): PaymentSettlement` | Passos abaixo |
| `releaseItem(PaymentSettlementItem $item, User $actor): void` | Baixa `draft`; sem arquivo `queued/generating` (`cnabGenerationInProgress`) nem `generated` ativo (`cnabFileAlreadyGenerated`); `released_at/by`; `recalculateTotals` |
| `confirm(PaymentSettlement $settlement, User $actor): PaymentSettlement` | Passos abaixo |
| `cancel(PaymentSettlement $settlement, User $actor, string $reason): PaymentSettlement` | Transação + `lockForUpdate`; exige `draft` e sem arquivo em andamento; `released_at/by` em todos os itens ativos; `CnabFileService::supersedeForCancellation($settlement, $actor)`; `status = cancelled`, `cancelled_at/by`, `cancellation_reason`; após commit `PaymentSettlementCancelled` |
| `recalculateTotals(PaymentSettlement $settlement): void` | `items_count` = ativos; `total_amount` = `bcadd` dos `amount` ativos; guard de overflow |
| `delete(PaymentSettlement $settlement, User $actor): void` | Só `cancelled` (`cannotDeleteActive`); soft delete (observer soft-deleta arquivos) |
| `forceDelete(PaymentSettlement $settlement, User $actor): void` | Só `cancelled`; **ordem DBA #1** (§14.2) |

**`create` passo a passo:**
1. `paymentRequestIds` vazio → `emptySelection()`; `count > config('rjet.cnab.max_items')` → `tooManyItems`.
2. Transação. `PaymentRequest::query()->whereKey($ids)->lockForUpdate()->get()` (com `visibleTo($actor)`).
3. Quantidade encontrada ≠ solicitada, ou alguma não `Launched`, ou alguma com item ativo → `paymentRequestNotEligible($count)` / `paymentRequestAlreadyInSettlement()`.
4. `branch_id` distintos > 1 → `mixedBranches()`; filial ≠ `$data->branchId` → `mixedBranches()`.
5. Conta: existe, não trashed, `is_active`, `bank_id` não nulo → senão `bankAccountInactive()`; `branch_id` da conta = filial → senão `bankAccountNotAllowed()`.
6. Cria `PaymentSettlement(draft)` com `branch_id`, `branch_bank_account_id`, `settlement_date`, `notes`.
7. Cria itens (`amount = pr.net_amount`) e soma com `bcadd`; overflow → `totalAmountOverflow()`; grava `items_count`/`total_amount`.
8. `catch (UniqueConstraintViolationException)` → `paymentRequestAlreadyInSettlement()` (rollback total).
9. Após commit: `PaymentSettlementCreated`.

**`confirm` passo a passo:**
1. Transação; recarrega a baixa com `lockForUpdate`.
2. Não `draft` → `notDraft`; `settlement_date > hoje` → `settlementDateInFuture`; `hasActiveCnabGeneration()` → `cnabGenerationInProgress`.
3. Carrega itens ativos com `paymentRequest` (`withTrashed`), bloqueia as PRs com `lockForUpdate`; conta itens com PR trashed, não `Launched` ou `bccomp(net_amount, amount, 2) !== 0` → se > 0, `itemsChangedSinceSelection($n)` (**nenhuma** PR muda).
4. Para cada item ativo: `PaymentRequestService::transitionStatus($pr, PaymentRequestStatus::Settled, $actor, notes: __('payment_settlements.messages.history_note', ['date' => ..., 'id' => ...]), settlement: $settlement)`.
5. `status = settled`, `settled_at = now()`, `settled_by`.
6. Após commit: `PaymentRequestStatusChanged` (por PR, já `ShouldDispatchAfterCommit`) + `PaymentSettlementConfirmed`.

#### `CnabConfigService`

| Método | Comportamento |
|--------|---------------|
| `create(array $data, User $actor): CnabConfig` | Conta ativa com `bank_id`; `layout->bankCode() === account.bank.code` senão `bankMismatch`; sem config viva para a conta senão `configAlreadyExists` (e mapear `UniqueConstraintViolationException`); **seed de NSA:** `last_file_sequence = max(informado, maxIssuedSequenceForAccount(account))`; se informado < máximo → `fileSequenceBelowIssued($max)`; `adapter->validateConfig()` para feedback imediato (erros → `BusinessException` com a lista traduzida) |
| `update(CnabConfig $config, array $data, User $actor): CnabConfig` | Se `hasIssuedFiles()` e mudou `branch_bank_account_id`, `layout` ou `last_file_sequence` → `configLockedAfterIssue`; `last_file_sequence` < `maxIssuedSequenceForAccount` → `fileSequenceBelowIssued`; `> 999999` → validação; demais regras de `create` |
| `deactivate(CnabConfig $config, User $actor): void` | `is_active = false`; bloqueado com arquivo `queued/generating` (`configHasActiveGeneration`). **Só pausa; não libera o unique** |
| `delete(CnabConfig $config, User $actor): void` | Soft delete (caminho de substituição); bloqueado com arquivo `queued/generating` |
| `maxIssuedSequenceForAccount(string $branchBankAccountId): int` | `CnabFile::withTrashed()->whereIn('cnab_config_id', CnabConfig::withTrashed()->where('branch_bank_account_id', $id)->select('id'))->max('file_sequence') ?? 0` |

#### `CnabRemittanceService` (puro, sem persistência)

| Método | Comportamento |
|--------|---------------|
| `buildData(PaymentSettlement $s, CnabConfig $c, int $fileSequence): CnabRemittanceData` | Mapeia PR + `bankDetails` + `supplier`; `BoletoBarcode::fromDigitableLine` quando só houver linha digitável; chave PIX em vez de QR; `reference` pelos últimos 20 hex; ordem determinística (forma → vencimento → id da PR) |
| `validate(PaymentSettlement $s): CnabValidationReport` | **Dry-run**: config (`adapter->validateConfig`) + itens (regras comuns + `adapter->validateItem`) + `build` com NSA provisório `last_file_sequence + 1` + `CnabRemittanceValidator`. Não persiste, não consome NSA |
| `render(CnabRemittanceData $data): CnabRemittanceResult` | `adapter->build($data)` |

Regras comuns por item: PR `Launched`, não trashed, `net_amount == amount` (`bccomp`); `amount > 0`; `settlement_date >= hoje`; `CnabPaymentType::fromPaymentRequest()` não nulo (senão `pix_qr_code_not_supported`).

#### `CnabFileService`

| Método | Comportamento |
|--------|---------------|
| `request(PaymentSettlement $s, User $actor): CnabFile` | `draft` (`settlementNotDraft`); `settlement_date >= hoje` (`paymentDateInPast`); config viva (`configNotFound`) e ativa (`configInactive`); sem arquivo ativo (`generationAlreadyActive`); cria `CnabFile(queued)` com `layout` da config; `UniqueConstraintViolationException` → `generationAlreadyActive`; após commit `CnabFileGenerationRequested` |
| `generate(CnabFile $file): void` | Chamado pelo job (§6.4) |
| `recoverStuck(bool $dryRun = false): array{requeued: int, failed: int}` | Reconciliador (rev. 2026-09-27). Contrato em §11.3 |
| `markFailed(CnabFile $file, string $userReason, ?CnabValidationReport $report = null): void` | `status = failed`, `failure_reason`; se report, persiste `cnab_file_items` com `is_valid=false` e `validation_errors`; `CnabFileGenerationFailed` após commit |
| `retry(CnabFile $file, User $actor): CnabFile` | `isRetryable()` senão `fileNotRetryable`; `request($file->settlement, $actor)` |
| `regenerate(CnabFile $file, User $actor, string $reason): CnabFile` | Adm; `generated` + baixa `draft`; transação: `superseded` (`superseded_at/by`, `supersede_reason`) + `request()` (novo NSA) |
| `supersedeForCancellation(PaymentSettlement $s, User $actor): void` | Arquivo `generated` → `superseded` com motivo `cancellation` |
| `download(CnabFile $file, User $actor): StreamedResponse` | `isDownloadable()` senão `fileNotDownloadable`; `Storage::disk($file->disk)->exists` senão `fileMissing`; `hash('sha256', conteúdo) === checksum` senão `fileIntegrityCheckFailed` + `Log::critical`; primeira vez grava `downloaded_at/by`; `CnabFileDownloaded` (usuário, IP); `Storage::disk()->download($path, $filename)` |

### 6.3 Actions de domínio

Todas `final`, `__invoke(...)` tipado, **Policy check no início** (`Gate::forUser($actor)->authorize(...)`), depois delegam ao Service. Mesmo padrão de `App\Actions\Attachment\*`.

| Action | Authorize | Chama |
|--------|-----------|-------|
| `App\Actions\Settlement\CreatePaymentSettlementAction` | `create`, `PaymentSettlement::class` | `PaymentSettlementService::create` |
| `App\Actions\Settlement\ReleasePaymentSettlementItemAction` | `update`, `$settlement` | `releaseItem` |
| `App\Actions\Settlement\ConfirmPaymentSettlementAction` | `confirm`, `$settlement` | `confirm` |
| `App\Actions\Settlement\CancelPaymentSettlementAction` | `cancel`, `$settlement` | `cancel` |
| `App\Actions\Cnab\ValidateCnabRemittanceAction` | `generateCnab`, `$settlement` | `CnabRemittanceService::validate` → `CnabValidationReport` |
| `App\Actions\Cnab\RequestCnabFileGenerationAction` | `generateCnab`, `$settlement` | `CnabFileService::request` |
| `App\Actions\Cnab\RetryCnabFileGenerationAction` | `retry`, `$file` | `retry` |
| `App\Actions\Cnab\RegenerateCnabFileAction` | `regenerate`, `$file` (Adm) | `regenerate` |
| `App\Actions\Cnab\DownloadCnabFileAction` | `download`, `$file` | `download` |
| `App\Actions\Cnab\RecoverStuckCnabFilesAction` (rev. 2026-09-27) | — (sistema, chamado pelo agendador; padrão `EscalateOverdueApprovalsAction`) | `CnabFileService::recoverStuck(bool $dryRun)` (§11.3) |

### 6.4 Job `App\Jobs\Cnab\GenerateCnabFileJob`

| Atributo | Valor |
|----------|-------|
| Interfaces/traits | `implements ShouldQueue`; `use Queueable, SerializesModels` (igual `RenameAttachmentBatchJob`) |
| Construtor | `public function __construct(public CnabFile $file) { $this->onConnection(config('rjet.cnab.queue.connection'))->onQueue(config('rjet.cnab.queue.name')); }`. Todo dispatch (listener `QueueCnabFileGeneration`, reconciliador) herda conexão e fila sem repetir nomes |
| Conexão / fila | ~~`default`~~ → `rjet.cnab.queue.connection` (`cnab_database` / `cnab_redis`; `sync` no phpunit), fila `cnab`, `retry_after` 240 (rev. 2026-09-27) |
| `$tries` | `10` (antes `3`). Cada `release` do `WithoutOverlapping` conta como tentativa: 6 releases (lock de 180 s ÷ 30 s) + 3 exceções + 1 sucesso = 10 |
| `$maxExceptions` | `3` (mantém "3 falhas reais" como limite) |
| `$timeout` | `120` (inalterado) |
| `$backoff` | `[10, 30, 60]` |
| `middleware()` | `[(new WithoutOverlapping((string) $this->file->payment_settlement_id))->releaseAfter(30)->expireAfter($this->timeout + 60)]`. **Sem `dontRelease()`**: na conexão default, a reentrega após crash era descartada sem `failed()` e o arquivo ficava em `generating` para sempre |
| `handle(CnabFileService $service)` | `$service->generate($this->file)` |
| `failed(?Throwable $e)` | Recarrega; se ainda em andamento (`queued|generating`; `markFailed` já recusa `generated`) → `markFailed` com `BusinessException::getUserMessage()` ou `__('cnab_files.messages.generation_interrupted')`; `Log::error` com `cnab_file_id`, `exception`, `exception_class`. NSA atribuído **permanece consumido** (P-F7-NSA) |

**`CnabFileService::generate(CnabFile $file)`, passo a passo:**

1. Recarrega com `lockForUpdate`. Se status ∉ {`queued`, `generating`} → **return** (idempotente).
2. `status = generating`, `started_at = now()`. **Sempre atualiza `updated_at` ao entrar**, inclusive quando o arquivo já está em `generating` com `started_at` preenchido (hoje o `forceFill` sem mudança não salva, e uma execução resgatada pareceria parada ao reconciliador). Rev. 2026-09-27.
3. **Gate dry-run:** `CnabRemittanceService::validate($settlement)`. Se inválido: persiste `cnab_file_items` com erros (`is_valid=false`), `markFailed(__('cnab_files.errors.remittance_invalid', ...), $report)`, dispara `CnabFileGenerationFailed` e **retorna sem lançar** (erro determinístico não gasta retries; NSA **não** consumido).
4. Transação com `lockForUpdate` na `CnabConfig`: se `file->file_sequence` é null → `next = last_file_sequence + 1`; `next > 999999` → `fileSequenceExhausted()`; grava `file_sequence = next` e `config.last_file_sequence = next`. Se já tinha `file_sequence` (retry após crash) → **reusa**.
5. `buildData` + `render()` → `CnabRemittanceValidator` de novo sobre o conteúdo final (defesa em profundidade); erro aqui → `markFailed` (sem relançar).
6. `Storage::disk(config('rjet.cnab.disk'))->put($path, $content)` com `$path = cnab/{branch_uuid}/{YYYY}/{MM}/{cnab_file_uuid}.rem`, visibility `private`. Falhou → `CnabException::storageWriteFailed()` (infra → retry).
7. Transação: grava `cnab_file_items` (placements), `disk`, `path`, `size`, `checksum` (SHA-256), `records_count`, `items_count`, `total_amount`, `config_snapshot`, `filename` (`adapter->fileName()`), `generated_at`, `status = generated`. **Antes de gravar, recarrega o `CnabFile` com `lockForUpdate` e só grava `generated` se o status ainda for `generating`.** Senão (ex.: reconciliador marcou `failed`), apaga o arquivo recém-gravado (mesma compensação do passo 9) e retorna **sem** `CnabFileGenerated`. Rev. 2026-09-27.
8. Após o commit: `CnabFileGenerated`.
9. **Compensação:** se o passo 7 falhar após o `put`, apaga o arquivo recém-gravado antes de relançar.

### 6.5 Integrations (`App\Integrations\Cnab\`, sem HTTP)

| Classe | Papel / assinaturas |
|--------|---------------------|
| `CnabRemittanceAdapter` (interface) | `layout(): CnabLayout`; `supportsBankCode(string $code): bool`; `validateConfig(CnabConfig $config, BranchBankAccount $account, Branch $branch): list<CnabValidationError>`; `validateItem(CnabRemittanceItemData $item, CarbonImmutable $paymentDate): list<CnabValidationError>`; `paymentFormCode(CnabRemittanceItemData $item, string $debitBankCode): string`; `build(CnabRemittanceData $data): CnabRemittanceResult`; `fileName(CnabRemittanceData $data): string` |
| `CnabAdapterResolver` | `for(CnabLayout $layout): CnabRemittanceAdapter`; layout fora do mapa → `CnabException::layoutNotSupported` |
| `CnabRemittanceValidator` | `validate(string $content, CnabRemittanceData $data, CnabRemittanceResult $result): list<CnabValidationError>`. Agnóstico de banco para 240: toda linha com **240** chars; só ASCII imprimível; separador `rjet.cnab.line_ending`; ordem `0` → (`1` → `3`… → `5`)+ → `9`; banco (pos. 1–3) igual em todas as linhas; lotes contínuos 1..n; sequencial contínuo 1..m no lote; trailer de lote (qtd + somatório) e de arquivo (lotes + registros) batem; somatório total == soma dos `amount` válidos (bcmath); NSA do header == `file_sequence` |
| `FixedWidthFormatter` | Helpers puros: `numeric(string|int $value, int $length)`, `alpha(?string $value, int $length)` (`Str::ascii` + uppercase + remove controle/quebras + trunca/pad à direita), `date(CarbonInterface $date)` (`DDMMAAAA`), `amountInCents(string $decimal, int $length)` via bcmath, `blank(int $length)` |
| `Itau\Itau240RemittanceAdapter` | Implementação SISPAG: agrupa por forma de pagamento (um lote por forma), registros 0 / 1 / 3 (A, B, J, J-52) / 5 / 9, trailers, `fileName()` |
| `Itau\Itau240Layout` | Constantes de posição/tamanho por campo e registro, com referência à versão do manual (P-F7-SPEC). **Único lugar com "bytes"** |
| `App\Support\BoletoBarcode` | `fromDigitableLine(string $line): string` (47 → 44, valida DVs dos campos e DV geral); `isUtilityBill(string $code): bool` (inicia com `8` / 48 dígitos); `bankCode(string $barcode): string` |

**Mapeamento de formas do MVP (referência; confirmar em P-F7-SPEC/HOMOLOG):**

| `CnabPaymentType` | Segmentos | Forma de pagamento |
|-------------------|-----------|--------------------|
| `boleto` | J (+ J-52) | `30` boleto Itaú (barcode inicia `341`) / `31` outros bancos |
| `transfer` | A + B | `01` crédito em conta Itaú (favorecido `341`) / `41` TED |
| `pix_key` | A + B (chave no B) | `45` PIX transferência |

**Nome do arquivo (Itaú MVP, P-F7-FILENAME):** `{bank_code}_{branch_document}_{YYYYMMDD settlement_date}_{NSA:06}.rem` (ex. `341_12345678000190_20260928_000007.rem`).

**Bind** em `AppServiceProvider::register()`: `CnabAdapterResolver` como singleton com mapa `[CnabLayout::Itau240->value => Itau240RemittanceAdapter::class]`.

### 6.6 Form Requests

Não se aplicam (sem HTTP próprio). Validação de UI no schema Filament; asserts de negócio nos Services.

### 6.7 Config `config/rjet.php` → chave `cnab`

| Chave | Env | Default |
|-------|-----|---------|
| `cnab.disk` | `RJET_CNAB_DISK` → fallback `FILESYSTEM_DISK` | `local` |
| `cnab.directory` | – | `cnab` |
| `cnab.max_items` | `RJET_CNAB_MAX_ITEMS` | `500` (limite também da baixa) |
| `cnab.line_ending` | – | `"\r\n"` |
| `cnab.queue.connection` | `RJET_CNAB_QUEUE_CONNECTION` | `cnab_database` (produção e `.env.example`: `cnab_redis`; `phpunit.xml`: `sync`) |
| `cnab.queue.name` | `RJET_CNAB_QUEUE` | `cnab` |
| `cnab.recovery.margin_seconds` | – | `60` |
| `cnab.recovery.max_age_minutes` | – | `20` |

Mesmo padrão de `rjet.imports.disk`. Adicionar `RJET_CNAB_DISK=` e `RJET_CNAB_MAX_ITEMS=` ao `.env.example`.

**Conexões novas em `config/queue.php` (rev. 2026-09-27):**

| Conexão | Driver | Backend | `queue` | `retry_after` |
|---------|--------|---------|---------|---------------|
| `cnab_database` | `database` | mesma tabela/conexão da default (`jobs`, `DB_QUEUE_CONNECTION`) | `env('RJET_CNAB_QUEUE', 'cnab')` | `(int) env('RJET_CNAB_QUEUE_RETRY_AFTER', 240)` |
| `cnab_redis` | `redis` | mesma conexão Redis da default (`REDIS_QUEUE_CONNECTION`) | `env('RJET_CNAB_QUEUE', 'cnab')` | `(int) env('RJET_CNAB_QUEUE_RETRY_AFTER', 240)` |

- `retry_after` tem **fonte única** na conexão: o reconciliador lê `config('queue.connections.'.config('rjet.cnab.queue.connection').'.retry_after')` (fallback 240 se nulo, ex. `sync`). Não duplicar em `rjet.cnab`.
- `.env.example`: `RJET_CNAB_QUEUE_CONNECTION=cnab_redis`, `RJET_CNAB_QUEUE=cnab`, `RJET_CNAB_QUEUE_RETRY_AFTER=240`.
- `phpunit.xml`: `<env name="RJET_CNAB_QUEUE_CONNECTION" value="sync"/>`.

---

## 7. Filament Resource: CnabConfig

### 7.1 Scaffold e Resource

```
Resource: CnabConfigResource
  Command: php artisan make:filament-resource CnabConfig --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\CnabConfigs\CnabConfigResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview
  SoftDeletes: getRecordRouteBindingEloquentQuery() (withoutGlobalScopes SoftDeletingScope)
  Icon: Heroicon::OutlinedDocumentText
  Navigation:
    Group: __('navigation.groups.settings')
    Sort: 30   (entre ApprovalRule = 20 e ImportTemplate = 40)
    Label: __('cnab_configs.navigation_label')
  Labels: getModelLabel → __('cnab_configs.label'); getPluralModelLabel → __('cnab_configs.plural')
  Visibility: canViewAny → can('viewAny', CnabConfig::class) (Operador + Adm); escrita só Adm (Policy)
  getEloquentQuery: with(['branchBankAccount.branch', 'branchBankAccount.bank', 'updater'])
  getRecordRouteBindingEloquentQuery: + with(['branchBankAccount.branch', 'branchBankAccount.bank', 'creator', 'updater'])
  getRelations: [CnabFilesRelationManager::class]
  getPages:
    index  → ListCnabConfigs::route('/')
    create → CreateCnabConfig::route('/create')
    view   → ViewCnabConfig::route('/{record}')
    edit   → EditCnabConfig::route('/{record}/edit')
```

### 7.2 Form — `Schemas/CnabConfigForm.php`

```
Imports: Filament\Schemas\Components\Utilities\Get, Filament\Schemas\Components\Utilities\Set
Schema: ->columns(1)

Section: __('cnab_configs.sections.account')
  Component: Filament\Schemas\Components\Section
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Config: ->columns(2)

  Field: branch_id (virtual)
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required
    Config: ->label(__('cnab_configs.fields.branch'))
            ->options(fn (): array => Branch::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
            ->searchable() ->preload() ->native(false)
            ->live()
            ->afterStateUpdated(fn (Set $set) => $set('branch_bank_account_id', null))
            ->afterStateHydrated(fn (Select $component, ?CnabConfig $record) => $component->state($record?->branchBankAccount?->branch_id))
            ->dehydrated(false)
            ->disabled(fn (?CnabConfig $record): bool => $record?->hasIssuedFiles() ?? false)

  Field: branch_bank_account_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required; exists na filial escolhida, conta ativa, bank_id not null (Service revalida)
    Config: ->label(__('cnab_configs.fields.branch_bank_account'))
            ->options(fn (Get $get): array => BranchBankAccount::query()
                ->where('branch_id', $get('branch_id'))->where('is_active', true)->whereNotNull('bank_id')
                ->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->bank_code} — Ag {$a->agency} / CC {$a->account_number}-{$a->account_digit}"])->all())
            ->searchable() ->native(false)
            ->disabled(fn (Get $get, ?CnabConfig $record): bool => blank($get('branch_id')) || ($record?->hasIssuedFiles() ?? false))
            ->dehydrated()   // dehydrated mesmo desabilitado na edição, Service ignora se não mudou

  Field: layout
    Component: Filament\Forms\Components\Select
    Validation: required; Rule::enum(CnabLayout::class)
    Config: ->label(__('cnab_configs.fields.layout'))
            ->options(CnabLayout::class) ->default(CnabLayout::Itau240) ->native(false)
            ->disabled(fn (?CnabConfig $record): bool => $record?->hasIssuedFiles() ?? false)
            ->helperText(__('cnab_configs.help.layout'))

Section: __('cnab_configs.sections.parameters')
  Component: Filament\Schemas\Components\Section
  Config: ->columns(2)

  Field: company_name
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: nullable; max:30
    Config: ->maxLength(30) ->helperText(__('cnab_configs.help.company_name_fallback'))

  Field: agreement_code
    Component: Filament\Forms\Components\TextInput
    Validation: nullable; max:20; regex:/^[A-Za-z0-9]+$/
    Config: ->maxLength(20)

  Field: wallet_code
    Component: Filament\Forms\Components\TextInput
    Validation: nullable; max:10
    Config: ->maxLength(10)

  Field: payment_type_code
    Component: Filament\Forms\Components\TextInput
    Validation: required; digits:2
    Config: ->default('20') ->maxLength(2) ->helperText(__('cnab_configs.help.payment_type_code'))

  Field: last_file_sequence
    Component: Filament\Forms\Components\TextInput
    Validation: required; integer; min:0; max:999999
    Config: ->numeric() ->default(0) ->minValue(0) ->maxValue(999999)
            ->disabled(fn (?CnabConfig $record): bool => $record?->hasIssuedFiles() ?? false)
            ->helperText(__('cnab_configs.help.last_file_sequence'))

  Field: is_active
    Component: Filament\Forms\Components\Toggle
    Docs: https://filamentphp.com/docs/5.x/forms/toggle
    Config: ->default(true) ->helperText(__('cnab_configs.help.is_active'))
```

### 7.3 Infolist — `Schemas/CnabConfigInfolist.php`

```
Schema: ->columns(1)

Section: __('cnab_configs.sections.account')  ->columns(2)
  Entry: branchBankAccount.branch.name
    Component: Filament\Infolists\Components\TextEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/text-entry
    Config: ->label(__('cnab_configs.fields.branch'))
  Entry: branchBankAccount
    Component: TextEntry
    Config: ->label(__('cnab_configs.fields.branch_bank_account'))
            ->formatStateUsing(fn (CnabConfig $record) => "{bank_code} — Ag {agency} / CC {account_number}-{account_digit}")
  Entry: layout
    Component: TextEntry  Config: ->badge()

Section: __('cnab_configs.sections.parameters')  ->columns(2)
  Entry: company_name / agreement_code / wallet_code / payment_type_code
    Component: TextEntry  Config: ->placeholder('—')
  Entry: last_file_sequence
    Component: TextEntry
  Entry: is_active
    Component: Filament\Infolists\Components\IconEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/icon-entry
    Config: ->boolean()

Section: __('common.sections.audit')  ->columns(2)
  Entry: creator.name   → TextEntry ->label(__('common.fields.created_by')) ->placeholder('—')
  Entry: created_at     → TextEntry ->dateTime('d/m/Y H:i')
  Entry: updater.name   → TextEntry ->label(__('common.fields.updated_by')) ->placeholder('—')
  Entry: updated_at     → TextEntry ->dateTime('d/m/Y H:i')
```

### 7.4 Table — `Tables/CnabConfigsTable.php`

```
modifyQueryUsing: with(['branchBankAccount.branch', 'updater'])
defaultSort: updated_at desc

Column: branchBankAccount.branch.name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label(__('cnab_configs.fields.branch')) ->searchable() ->sortable()
Column: branchBankAccount.bank_code
  Component: TextColumn  Config: ->label(__('cnab_configs.fields.bank_code'))
Column: account
  Component: TextColumn
  Config: ->label(__('cnab_configs.fields.account')) ->state(fn (CnabConfig $r) => "Ag {agency} / CC {account_number}-{account_digit}")
Column: layout
  Component: TextColumn  Config: ->badge()
Column: last_file_sequence
  Component: TextColumn  Config: ->label(__('cnab_configs.fields.last_file_sequence'))
Column: is_active
  Component: Filament\Tables\Columns\IconColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/icon
  Config: ->boolean()
Column: updater.name / updated_at
  Component: TextColumn  Config: ->toggleable(isToggledHiddenByDefault: true); updated_at ->dateTime('d/m/Y H:i')

Filter: branch
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label(__('cnab_configs.filters.branch'))
          ->options(Branch::pluck('name', 'id'))
          ->query(fn (Builder $q, array $data) => filled($data['value']) ? $q->whereHas('branchBankAccount', fn ($a) => $a->where('branch_id', $data['value'])) : $q)
Filter: layout
  Component: SelectFilter  Config: ->options(CnabLayout::class)
Filter: is_active
  Component: Filament\Tables\Filters\TernaryFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary
Filter: trashed
  Component: Filament\Tables\Filters\TrashedFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/trashed
  Config: ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)

recordActions:
  Filament\Actions\ViewAction
  Filament\Actions\EditAction       — visível se can('update', $record)
  Filament\Actions\DeleteAction     — visível se can('delete', $record); ->using(fn ($record) => app(CnabConfigService::class)->delete($record, $user))
                                      catch BusinessException → Notification danger + halt
                                      ->modalDescription(__('cnab_configs.messages.delete_replaces'))
  Filament\Actions\RestoreAction    — visível se can('restore', $record); restaurar falha no unique se já houver outra viva → mapear para configAlreadyExists

toolbarActions:
  Filament\Actions\BulkActionGroup → [Filament\Actions\DeleteBulkAction (Adm; cada registro via CnabConfigService::delete)]
```

### 7.5 Relation manager — `CnabFilesRelationManager` (somente leitura)

```
Command: php artisan make:filament-relation-manager CnabConfigResource cnabFiles file_sequence --panel=admin --no-interaction
Location: App\Filament\Resources\CnabConfigs\RelationManagers\CnabFilesRelationManager (final)
Docs: https://filamentphp.com/docs/5.x/resources/managing-relationships
Relationship: cnabFiles
isReadOnly(): true
modifyQueryUsing: with(['settlement'])
Columns:
  file_sequence            → TextColumn ->sortable()
  status                   → TextColumn ->badge()
  settlement.settlement_date → TextColumn ->date('d/m/Y') ->url(fn (CnabFile $r) => PaymentSettlementResource::getUrl('view', ['record' => $r->payment_settlement_id]))
  items_count              → TextColumn
  total_amount             → TextColumn ->money('BRL')
  generated_at             → TextColumn ->dateTime('d/m/Y H:i') ->placeholder('—')
headerActions: []   recordActions: []   toolbarActions: []
defaultSort: created_at desc
```

### 7.6 Pages

| Page | Base | Notas |
|------|------|-------|
| `ListCnabConfigs` | `ListRecords` | Header `Filament\Actions\CreateAction` (visível se `can('create')`) |
| `CreateCnabConfig` | `CreateRecord` | `handleRecordCreation(array $data)` → `app(CnabConfigService::class)->create($data, $user)`; `catch BusinessException` → `Notification::danger($e->getUserMessage())` + `$this->halt()` |
| `EditCnabConfig` | `EditRecord` | `handleRecordUpdate($record, $data)` → `CnabConfigService::update`; mesmo tratamento; header `ViewAction`, `DeleteAction` (Adm) |
| `ViewCnabConfig` | `ViewRecord` | Header `EditAction` (Adm) |

---

## 8. Filament Resource: PaymentSettlement

### 8.1 Scaffold e Resource

```
Resource: PaymentSettlementResource
  Command: php artisan make:filament-resource PaymentSettlement --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\PaymentSettlements\PaymentSettlementResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview
  SoftDeletes: getRecordRouteBindingEloquentQuery()
  Icon: Heroicon::OutlinedCheckBadge
  Navigation:
    Group: __('navigation.groups.operations')
    Sort: 20   (após PaymentRequest = 1, ImportBatch = 15, AttachmentBatch = 16)
    Label: __('payment_settlements.navigation_label')   // "Baixas"
  Labels: __('payment_settlements.label') / __('payment_settlements.plural')
  Visibility: canViewAny → can('viewAny', PaymentSettlement::class) (Operador + Adm); Cliente excluído
  getEloquentQuery: with(['branch', 'branchBankAccount.bank', 'currentCnabFile', 'creator']) + visibleTo($user)
  getRecordRouteBindingEloquentQuery: withoutGlobalScopes([SoftDeletingScope::class])
      ->with(['branch', 'branchBankAccount.bank', 'currentCnabFile', 'latestCnabFile', 'creator', 'settler', 'canceller'])
  getRelations: [ItemsRelationManager::class, CnabFilesRelationManager::class]
  getPages (ordem importa: rota fixa antes do wildcard):
    index  → ListPaymentSettlements::route('/')
    create → CreatePaymentSettlement::route('/create')
    view   → ViewPaymentSettlement::route('/{record}')
    (sem edit)
  form(): delega para PaymentSettlementForm::configure (não usado por página Create/Edit; mantido por convenção)
```

### 8.2 Schema do modal — `Schemas/PaymentSettlementForm.php`

Expõe `public static function components(): array` (usado pela bulk action) e `configure(Schema $schema): Schema` (`->components(self::components())`).

```
Imports: Filament\Schemas\Components\Utilities\Get
Contexto: $records = Collection<PaymentRequest> selecionados (injetado na BulkAction)

Field: settlement_date
  Component: Filament\Forms\Components\DatePicker
  Docs: https://filamentphp.com/docs/5.x/forms/date-time-picker
  Validation: required; date
  Config: ->label(__('payment_settlements.fields.settlement_date'))
          ->native(false) ->displayFormat('d/m/Y')
          ->default(fn () => today('America/Sao_Paulo'))
          ->helperText(__('payment_settlements.help.settlement_date'))
          ->live()

Field: branch_bank_account_id
  Component: Filament\Forms\Components\Select
  Docs: https://filamentphp.com/docs/5.x/forms/select
  Validation: required
  Config: ->label(__('payment_settlements.fields.branch_bank_account'))
          ->options(fn (Collection $records): array => filial única ? contas is_active + bank_id not null da filial, label "{bank_code} — Ag {agency} / CC {account_number}-{account_digit}" : [])
          ->default(fn (Collection $records) => conta is_default ativa da filial única)
          ->native(false) ->live()
          ->hint(fn (Get $get): ?string => blank($get('branch_bank_account_id')) ? null
                 : (CnabConfig::active()->forAccount($get('branch_bank_account_id'))->exists()
                     ? __('payment_settlements.hints.cnab_available')
                     : __('payment_settlements.hints.cnab_unavailable')))
          ->hintColor(fn (Get $get) => ... 'success' | 'warning')

Field: notes
  Component: Filament\Forms\Components\Textarea
  Docs: https://filamentphp.com/docs/5.x/forms/textarea
  Validation: nullable; max:1000
  Config: ->rows(3) ->maxLength(1000)
```

Seleção com filiais misturadas: o Select fica sem opções e a bulk action mostra `__('payment_settlements.errors.mixed_branches')` como `modalDescription`; o Service rejeita de qualquer forma (`mixedBranches`).

### 8.3 Infolist — `Schemas/PaymentSettlementInfolist.php`

```
Schema: ->columns(1)

Section: __('payment_settlements.sections.summary')  ->columns(2)
  Entry: status              → Filament\Infolists\Components\TextEntry ->badge()
  Entry: branch.name         → TextEntry ->label(__('payment_settlements.fields.branch'))
  Entry: branchBankAccount   → TextEntry ->formatStateUsing("{bank_code} — Ag {agency} / CC {account_number}-{account_digit}")
  Entry: settlement_date     → TextEntry ->date('d/m/Y')
  Entry: items_count         → TextEntry
  Entry: total_amount        → TextEntry ->money('BRL')
  Entry: notes               → TextEntry ->placeholder('—') ->columnSpanFull()

Section: __('payment_settlements.sections.cnab')  ->columns(2)
  Entry: currentCnabFile.status
    Component: TextEntry
    Config: ->badge() ->placeholder(__('payment_settlements.messages.no_cnab'))
  Entry: currentCnabFile.file_sequence  → TextEntry ->placeholder('—')
  Entry: currentCnabFile.filename       → TextEntry ->placeholder('—')
  Entry: currentCnabFile.generated_at   → TextEntry ->dateTime('d/m/Y H:i') ->placeholder('—')
  Entry: latestCnabFile.failure_reason
    Component: TextEntry
    Config: ->visible(fn (PaymentSettlement $r) => $r->latestCnabFile?->status === CnabFileStatus::Failed) ->color('danger') ->columnSpanFull()
  Entry: cnab_hint (state calculado)
    Component: TextEntry
    Config: ->state(fn ($r) => $r->cnabConfig() ? null : __('payment_settlements.hints.cnab_unavailable')) ->visible(isDraft && sem config)

Section: __('common.sections.audit')  ->columns(2)
  Entry: creator.name / created_at / settler.name / settled_at / canceller.name / cancelled_at
    Component: TextEntry  Config: datas ->dateTime('d/m/Y H:i'); ->placeholder('—')
  Entry: cancellation_reason → TextEntry ->visible(status === Cancelled) ->columnSpanFull()
```

### 8.4 Página de seleção — `Pages/CreatePaymentSettlement.php` (RF030 + RF031)

**Não** é `CreateRecord`. Página de Resource sem record, com tabela Filament.

```
Page: CreatePaymentSettlement
  Command: (substitui o arquivo gerado pelo scaffold; se preferir recriar:)
           php artisan make:filament-page CreatePaymentSettlement --resource=PaymentSettlementResource --type=custom --panel=admin --no-interaction
  Location: App\Filament\Resources\PaymentSettlements\Pages\CreatePaymentSettlement (final)
  Docs: https://filamentphp.com/docs/5.x/resources/custom-pages · https://filamentphp.com/docs/5.x/tables/overview
  Base: Filament\Resources\Pages\Page
  Implements: Filament\Tables\Contracts\HasTable
  Uses: Filament\Tables\Concerns\InteractsWithTable
  Resource: protected static string $resource = PaymentSettlementResource::class;
  View: a view padrão de página de Resource custom com {{ $this->table }}
        (resources/views/filament/resources/payment-settlements/pages/create-payment-settlement.blade.php:
         <x-filament-panels::page> {{ $this->table }} </x-filament-panels::page>)
  Title: __('payment_settlements.pages.create.title')   // "Nova baixa"
  Register:
    Key: create
    In: PaymentSettlementResource::getPages()
    Route pattern: /create   (antes de /{record})
  Authorization: canAccess() → can('create', PaymentSettlement::class); mount() → abort_unless(..., 403)
  Content: table(Table $table): Table → EligiblePaymentRequestsTable::configure($table)
  ListPaymentSettlements header: CreateAction ->url(PaymentSettlementResource::getUrl('create')) ->label(__('payment_settlements.actions.create'))
```

**Tabela — `Tables/EligiblePaymentRequestsTable.php`**

```
query: app(PaymentSettlementService::class)->eligibleQuery(new EligiblePaymentFilterData(null, null, null, null), Filament::auth()->user())
       (base = PaymentRequest::visibleTo($user)->eligibleForSettlement()->with(['supplier', 'bankDetails.bank', 'branch']);
        filtros aplicados pelos Filters abaixo)
defaultSort: due_date asc
selectable: sim (checkbox + "selecionar todos os filtrados" nativo)
emptyStateHeading: __('payment_settlements.messages.no_eligible')

Column: branch.name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label(__('payment_settlements.fields.branch')) ->sortable()
Column: supplier.name
  Component: TextColumn  Config: ->searchable() ->label(__('payment_requests.fields.supplier'))
Column: payment_method
  Component: TextColumn  Config: ->badge()
Column: bankDetails.deposit_type
  Component: TextColumn  Config: ->badge() ->placeholder('—')
Column: due_date
  Component: TextColumn  Config: ->date('d/m/Y') ->sortable()
Column: net_amount
  Component: TextColumn
  Config: ->money('BRL') ->sortable()
          ->summarize(Filament\Tables\Columns\Summarizers\Sum::make()->money('BRL')->label(__('payment_settlements.fields.total_amount')))
  Docs: https://filamentphp.com/docs/5.x/tables/summaries
Column: status
  Component: TextColumn  Config: ->badge()   (sempre Launched; informativo)

Filter: branch
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->label(__('payment_settlements.filters.branch')) ->relationship('branch', 'name') ->searchable() ->preload()
Filter: due_date
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Config: ->schema([DatePicker::make('due_from'), DatePicker::make('due_until')])
          ->query(fn (Builder $q, array $data) => $q->dueBetween($data['due_from'] ?? null, $data['due_until'] ?? null))   // scope existente
          ->indicateUsing(...) com __('payment_settlements.filters.due_from/due_until')
Filter: payment_method
  Component: SelectFilter  Config: ->options(PaymentMethod::class)

recordActions: []   (sem ações por linha)
toolbarActions:
  Filament\Actions\BulkActionGroup::make([CreatePaymentSettlementBulkAction::make()])
```

**Bulk action — `Actions/CreatePaymentSettlementBulkAction.php`**

```
Component: Filament\Actions\BulkAction
Docs: https://filamentphp.com/docs/5.x/actions/bulk-actions
Name: 'createSettlement'
Label: __('payment_settlements.actions.settle_selected')   // "Baixar selecionados"
Icon: Heroicon::OutlinedCheckBadge
Location: toolbar da EligiblePaymentRequestsTable
Visibility: can('create', PaymentSettlement::class)
Authorization: domain Action CreatePaymentSettlementAction (authorize create) + abort_unless belt
Modal: ->modalHeading(__('payment_settlements.actions.settle_selected'))
       ->modalDescription(fn (Collection $records) => __('payment_settlements.messages.selection_summary', ['count' => ..., 'total' => bcadd ...]))
       ->schema(PaymentSettlementForm::components())
Behavior:
  1. $branchIds = $records->pluck('branch_id')->unique(); se count ≠ 1 → Notification danger mixed_branches + halt
  2. new PaymentSettlementData(branchId: único, branchBankAccountId: $data['branch_bank_account_id'],
        settlementDate: CarbonImmutable::parse($data['settlement_date'], 'America/Sao_Paulo'),
        paymentRequestIds: $records->modelKeys(), notes: $data['notes'] ?? null)
  3. $settlement = app(CreatePaymentSettlementAction::class)($data, $user)
  4. catch BusinessException → Notification::danger($e->getUserMessage()) + $action->halt()
  5. Notification::success(__('payment_settlements.messages.created'))
  6. redirect(PaymentSettlementResource::getUrl('view', ['record' => $settlement]))
  ->deselectRecordsAfterCompletion()
```

### 8.5 Lista — `Tables/PaymentSettlementsTable.php`

```
modifyQueryUsing: with(['branch', 'branchBankAccount', 'currentCnabFile', 'creator'])
defaultSort: settlement_date desc

Column: settlement_date     → Filament\Tables\Columns\TextColumn ->date('d/m/Y') ->sortable()
Column: branch.name         → TextColumn ->searchable() ->sortable()
Column: branchBankAccount.bank_code → TextColumn ->label(__('payment_settlements.fields.bank_code'))
Column: status              → TextColumn ->badge() ->sortable()
Column: items_count         → TextColumn
Column: total_amount        → TextColumn ->money('BRL') ->sortable()
Column: currentCnabFile.status → TextColumn ->badge() ->label(__('payment_settlements.fields.cnab_status')) ->placeholder(__('payment_settlements.messages.no_cnab_short'))
Column: creator.name        → TextColumn ->label(__('common.fields.created_by')) ->toggleable()
Column: created_at          → TextColumn ->dateTime('d/m/Y H:i') ->sortable() ->toggleable(isToggledHiddenByDefault: true)

Filter: status
  Component: Filament\Tables\Filters\SelectFilter  Config: ->options(PaymentSettlementStatus::class)
Filter: branch
  Component: SelectFilter  Config: ->relationship('branch', 'name') ->searchable() ->preload()
Filter: settlement_date
  Component: Filament\Tables\Filters\Filter
  Config: ->schema([DatePicker settlement_from, DatePicker settlement_until]) ->query(whereDate >= / <=)
Filter: trashed
  Component: Filament\Tables\Filters\TrashedFilter  Config: ->visible(isAdm)

recordActions:
  Filament\Actions\ViewAction
  Filament\Actions\DeleteAction   — visible: can('delete', $record) (Adm + cancelled); ->using(PaymentSettlementService::delete)
  Filament\Actions\RestoreAction  — visible: can('restore', $record) (Adm)
toolbarActions:
  Filament\Actions\BulkActionGroup → [Filament\Actions\DeleteBulkAction (Adm; ignora/nega não-cancelled via Policy)]
```

### 8.6 View — `Pages/ViewPaymentSettlement.php`

```
Base: Filament\Resources\Pages\ViewRecord (final)
getHeaderActions(): [
  ValidateCnabRemittanceAction::make(),
  GenerateCnabFileAction::make(),
  DownloadCnabFileAction::make()        (alvo: $record->currentCnabFile),
  RetryCnabFileGenerationAction::make() (alvo: $record->latestCnabFile),
  ConfirmPaymentSettlementAction::make(),
  CancelPaymentSettlementAction::make(),
]
getPollingInterval(): ?string
  → '5s' se $this->record->currentCnabFile?->status?->isInProgress() (queued|generating); senão null
  (espelhar ViewImportBatch::getPollingInterval)
```

### 8.7 Actions Filament (detalhe)

Todas em `App\Filament\Resources\PaymentSettlements\Actions\`, `final class X { public static function make(): Action }`, `Component: Filament\Actions\Action`, Docs https://filamentphp.com/docs/5.x/actions/overview. Padrão de erro: `catch BusinessException → Notification::make()->title($e->getUserMessage())->danger()->send(); $action->halt();`. Belt: `abort_unless(can(...), 403)` no início do `->action()`.

| Action | Label / ícone / cor | Location | Visibility | Authorization | Behavior |
|--------|---------------------|----------|------------|---------------|----------|
| `ValidateCnabRemittanceAction` | `payment_settlements.actions.validate_cnab` / `Heroicon::OutlinedShieldCheck` / `gray` | View header | `draft` + `cnabConfig()` ativa + `can('generateCnab')` | `generateCnab` | 1. Chama `App\Actions\Cnab\ValidateCnabRemittanceAction` 2. Abre modal `->modalContent(view('filament.cnab.validation-report', ['report' => $report]))` com `<x-filament::section>`/`<x-filament::badge>`: erros de config, por item (fornecedor + mensagem traduzida `cnab_files.validation.*`) e estruturais; ou "Remessa válida" 3. `->modalSubmitAction(false)` 4. Não persiste |
| `GenerateCnabFileAction` | `payment_settlements.actions.generate_cnab` / `Heroicon::OutlinedDocumentArrowDown` / `primary` | View header | `draft` + config ativa + `currentCnabFile === null` + `settlement_date >= hoje` + `can('generateCnab')` | `generateCnab` | 1. `requiresConfirmation()` 2. `RequestCnabFileGenerationAction` 3. Notification success `cnab_files.messages.queued` 4. `$this->refreshFormData`/redirect para View (liga o polling) |
| `DownloadCnabFileAction` | `cnab_files.actions.download` / `Heroicon::OutlinedArrowDownTray` / `success` | View header + CnabFiles RM | arquivo `isDownloadable()` + `can('download', $file)` | `download` | `return app(App\Actions\Cnab\DownloadCnabFileAction::class)($file, $user);` (stream). `BusinessException` → danger |
| `RetryCnabFileGenerationAction` | `cnab_files.actions.retry` / `Heroicon::OutlinedArrowPath` / `warning` | View header + CnabFiles RM | arquivo `failed` + baixa `draft` + sem arquivo ativo + `can('retry', $file)` | `retry` | `requiresConfirmation()` → `RetryCnabFileGenerationAction` → toast `cnab_files.messages.retry_queued` |
| `RegenerateCnabFileAction` | `cnab_files.actions.regenerate` / `Heroicon::OutlinedArrowPathRoundedSquare` / `danger` | CnabFiles RM | arquivo `generated` + baixa `draft` + `can('regenerate', $file)` (Adm) | `regenerate` | Modal com `Textarea reason` (required, max:500) + `modalDescription(__('cnab_files.messages.regenerate_warning'))` (risco de pagamento duplicado) → `RegenerateCnabFileAction` → toast |
| `ConfirmPaymentSettlementAction` | `payment_settlements.actions.confirm` / `Heroicon::OutlinedCheckCircle` / `success` | View header | `draft` + `settlement_date <= hoje` + `! hasActiveCnabGeneration()` + `can('confirm')` | `confirm` | `requiresConfirmation()`; `modalDescription` = `messages.confirm_without_cnab` se `! hasGeneratedCnabFile()`, senão `messages.confirm_description` → `ConfirmPaymentSettlementAction` → toast `messages.confirmed` → refresh |
| `CancelPaymentSettlementAction` | `payment_settlements.actions.cancel` / `Heroicon::OutlinedXCircle` / `danger` | View header | `draft` + `! hasActiveCnabGeneration()` + `can('cancel')` | `cancel` | Modal `Textarea reason` (required, max:500); aviso `messages.cancel_with_generated_file` se `hasGeneratedCnabFile()` → `CancelPaymentSettlementAction` → toast `messages.cancelled` |
| `ReleasePaymentSettlementItemAction` | `payment_settlements.actions.release_item` / `Heroicon::OutlinedMinusCircle` / `danger` | Items RM (linha) | item ativo + baixa `draft` + `currentCnabFile === null` + `can('update', $settlement)` | `update` | `requiresConfirmation()` → `ReleasePaymentSettlementItemAction` → toast `messages.item_released` |
| `ViewCnabValidationErrorsAction` | `cnab_files.actions.view_errors` / `Heroicon::OutlinedExclamationTriangle` / `gray` | CnabFiles RM | arquivo `failed` com itens `is_valid = false` | `view` do arquivo | Modal read-only listando `items()->where('is_valid', false)->with('settlementItem.paymentRequest.supplier')` e cada `validation_errors[].code` traduzido |

### 8.8 Relation managers

#### `ItemsRelationManager`

```
Command: php artisan make:filament-relation-manager PaymentSettlementResource items id --panel=admin --no-interaction
Location: App\Filament\Resources\PaymentSettlements\RelationManagers\ItemsRelationManager (final)
Docs: https://filamentphp.com/docs/5.x/resources/managing-relationships
Relationship: items
Title: __('payment_settlements.sections.items')
modifyQueryUsing: with(['paymentRequest.supplier', 'paymentRequest.bankDetails.bank'])
defaultSort: created_at asc

Columns:
  paymentRequest.supplier.name   → TextColumn ->searchable()
  paymentRequest.payment_method  → TextColumn ->badge()
  paymentRequest.due_date        → TextColumn ->date('d/m/Y')
  amount                         → TextColumn ->money('BRL') ->summarize(Sum::make()->money('BRL'))
  paymentRequest.status          → TextColumn ->badge()
  released_at                    → TextColumn ->dateTime('d/m/Y H:i') ->placeholder(__('payment_settlements.messages.item_active'))
  link PR                        → TextColumn ->state(__('payment_settlements.actions.open_request')) ->url(fn ($r) => PaymentRequestResource::getUrl('view', ['record' => $r->payment_request_id]))
Filters: TernaryFilter 'released' (ativos / liberados) via whereNull/whereNotNull('released_at')
headerActions: []
recordActions: [ReleasePaymentSettlementItemAction::make()]
toolbarActions: []
```

#### `CnabFilesRelationManager`

```
Command: php artisan make:filament-relation-manager PaymentSettlementResource cnabFiles file_sequence --panel=admin --no-interaction
Location: App\Filament\Resources\PaymentSettlements\RelationManagers\CnabFilesRelationManager (final)
Relationship: cnabFiles
Title: __('payment_settlements.sections.cnab_files')
modifyQueryUsing: with(['creator'])
defaultSort: created_at desc

Columns:
  file_sequence   → TextColumn ->placeholder('—')
  status          → TextColumn ->badge()
  items_count     → TextColumn
  total_amount    → TextColumn ->money('BRL')
  creator.name    → TextColumn ->label(__('cnab_files.fields.requested_by'))
  generated_at    → TextColumn ->dateTime('d/m/Y H:i') ->placeholder('—')
  downloaded_at   → TextColumn ->dateTime('d/m/Y H:i') ->placeholder('—')
  failure_reason  → TextColumn ->limit(60) ->tooltip(fn ($r) => $r->failure_reason) ->placeholder('—')
headerActions: []
recordActions: [DownloadCnabFileAction::make(), RetryCnabFileGenerationAction::make(), RegenerateCnabFileAction::make(), ViewCnabValidationErrorsAction::make()]
toolbarActions: []
```

> Nenhum `path` ou `disk` aparece em coluna, entry ou notificação.

### 8.9 Pages (resumo)

| Page | Base | Notas |
|------|------|-------|
| `ListPaymentSettlements` | `ListRecords` | Header `CreateAction` com URL da página de seleção |
| `CreatePaymentSettlement` | `Page` + `HasTable` | §8.4 |
| `ViewPaymentSettlement` | `ViewRecord` | §8.6 (header actions + polling 5s) |
| **Sem** `EditPaymentSettlement` | – | Apagar o arquivo do scaffold |

---

## 9. Authorization / Policies

Todas `final`, registradas em `AppServiceProvider::POLICIES`. Papéis pelos helpers existentes do `User` (`isAdm()`, `isOperador()`, `isCliente()` ou equivalentes em uso nas policies F5/F6; espelhar `ImportBatchPolicy`). **Cliente: `false` em tudo.**

### 9.1 `PaymentSettlementPolicy`

| Método | Regra |
|--------|-------|
| `viewAny` / `view` | Operador ou Adm |
| `create` | Operador ou Adm |
| `update` (remover item) | Operador ou Adm **e** `status = draft` |
| `confirm` | Operador ou Adm **e** `draft` |
| `cancel` | Operador ou Adm **e** `draft` |
| `generateCnab` | Operador ou Adm **e** `draft` |
| `delete` | Adm **e** `status = cancelled` |
| `deleteAny` | Adm |
| `restore` | Adm |
| `forceDelete` | Adm **e** `cancelled` |

### 9.2 `CnabConfigPolicy`

| Método | Regra |
|--------|-------|
| `viewAny` / `view` | Operador ou Adm |
| `create` / `update` | Adm |
| `delete` / `deleteAny` / `restore` / `forceDelete` | Adm |

### 9.3 `CnabFilePolicy`

| Método | Regra |
|--------|-------|
| `viewAny` / `view` | Operador ou Adm |
| `create` | `false` (criação só via `generateCnab` da baixa) |
| `download` | Operador ou Adm **e** `status = generated` |
| `retry` | Operador ou Adm **e** `failed` **e** baixa `draft` |
| `regenerate` | **Adm** **e** `generated` **e** baixa `draft` |
| `update` / `delete` / `forceDelete` / `restore` | `false` (só via cascade do Service) |

### 9.4 Camadas de defesa

1. `canViewAny` do Resource + `scopeVisibleTo` (Cliente `1 = 0`).
2. `visible()` de cada Action Filament.
3. `abort_unless(can(...), 403)` dentro do `->action()`.
4. `Gate::authorize` na Action de domínio.
5. Asserts de estado no Service (belt; `PaymentSettlementException::unauthorized` se chegar sem permissão).

### 9.5 `PaymentRequest` durante baixa `draft`

`isEditableBy()` retorna `false` para **não-Adm** quando `isInDraftSettlement()`. Adm continua editando (e o dry-run/confirmação detecta `amount_changed`). Após cancelamento ou remoção do item, o Operador volta a editar.

---

## 10. State transitions

### 10.1 `PaymentSettlementStatus`

```
draft ──(ConfirmPaymentSettlementAction: settlement_date <= hoje, sem CNAB queued/generating, PRs íntegras)──> settled
draft ──(CancelPaymentSettlementAction: motivo obrigatório, sem CNAB queued/generating)──> cancelled
settled, cancelled: terminais
```

Service valida `canTransitionTo()`; senão `notDraft`.

### 10.2 `CnabFileStatus`

```
queued ──(job inicia)──> generating
queued ──(failed() do job antes de iniciar)──> failed
generating ──(dry-run ok + disco ok)──> generated
generating ──(dry-run reprovado | infra após 3 tentativas)──> failed
generated ──(Regenerar Adm | cancelamento da baixa)──> superseded
failed, superseded: terminais (Retry cria NOVO CnabFile; nunca reabre)
```

### 10.3 `PaymentRequestStatus` (existente; só o caminho para `Settled` muda)

```
Requested ──(gate aprovação F4)──> Launched ──(PaymentSettlementService::confirm)──> Settled
                                     │
                                     └─ enquanto em baixa draft: sem edição para não-Adm
```

- **Guard:** `PaymentRequestService::transitionStatus($pr, Settled, $actor, $notes, ?PaymentSettlement $settlement)` exige `$settlement` em `draft` com item ativo para a PR; senão `PaymentRequestException::settlementRequired()`.
- **`TransitionStatusAction`** (Filament PR): filtrar `Settled` de `allowedTransitions()`; se a lista ficar vazia (PR `Launched`), a action fica invisível.
- Histórico: `payment_request_status_history` com `notes` = `__('payment_settlements.messages.history_note', ['date' => d/m/Y, 'id' => short id])`.

---

## 11. Events / Listeners / Notifications / Queue

Todas as classes `final`. Payload mínimo (o model). Disparo **no Service, após o commit** (`DB::afterCommit` ou evento `ShouldDispatchAfterCommit`). Listeners por **auto-discovery** (sem `Event::listen`).

| Event | Payload | Quando | Listener(s) | Fila |
|-------|---------|--------|-------------|------|
| `App\Events\Settlement\PaymentSettlementCreated` | `public PaymentSettlement $settlement` | Baixa `draft` criada | `App\Listeners\Settlement\LogPaymentSettlementActivity::handleCreated` (log estruturado) | Não |
| `App\Events\Settlement\PaymentSettlementConfirmed` | `PaymentSettlement` | → `settled` | `LogPaymentSettlementActivity` | Não |
| `App\Events\Settlement\PaymentSettlementCancelled` | `PaymentSettlement` | → `cancelled` | `LogPaymentSettlementActivity` | Não |
| `App\Events\PaymentRequest\PaymentRequestStatusChanged` (existente) | – | Cada PR `Launched → Settled` | `LogPaymentRequestActivity` (existente) | Não. **Passa a `ShouldDispatchAfterCommit`** |
| `App\Events\Cnab\CnabFileGenerationRequested` | `public CnabFile $file` | `CnabFile(queued)` criado | `App\Listeners\Cnab\QueueCnabFileGeneration` (sync fino) → `GenerateCnabFileJob::dispatch($file)` | Job em fila |
| `App\Events\Cnab\CnabFileGenerated` | `CnabFile` | Job → `generated` | `NotifyCnabFileGenerated` (`ShouldQueue`) → `CnabFileGeneratedNotification` ao `creator`; `LogCnabFileActivity` | Sim (notify); log sync |
| `App\Events\Cnab\CnabFileGenerationFailed` | `CnabFile` | → `failed` | `NotifyCnabFileGenerationFailed` (`ShouldQueue`) → `CnabFileGenerationFailedNotification`; `LogCnabFileActivity` | Sim (notify) |
| `App\Events\Cnab\CnabFileDownloaded` | `CnabFile $file`, `User $user`, `?string $ip` | Cada download autorizado | `LogCnabFileActivity` (usuário, IP, `file_sequence`, checksum) | Não |

> Se um listener tiver mais de um `handleX`, o auto-discovery só pega métodos `handle*` com o evento tipado. Conferir com `php artisan event:list` que cada evento aparece **uma vez**.

### 11.1 Notifications (`App\Notifications`, `ShouldQueue`, `via(): ['mail', 'database']`, padrão `AttachmentBatchRenamedNotification`)

| Notification | Conteúdo | URL |
|--------------|----------|-----|
| `CnabFileGeneratedNotification(CnabFile $file)` | Título `notifications.cnab_file_generated.title`; corpo com NSA, quantidade e valor total (`money BRL`) | `PaymentSettlementResource::getUrl('view', ['record' => $file->payment_settlement_id])`. **Nunca** link direto ao arquivo |
| `CnabFileGenerationFailedNotification(CnabFile $file)` | `failure_reason` seguro | URL da baixa |

Database payload no formato Filament (`Notification::make()->...->getDatabaseMessage()`), igual às notificações F5/F6.

### 11.2 Queue

| Job | Conexão / fila | Tries / maxExceptions | Timeout | Backoff | Overlap |
|-----|----------------|-----------------------|---------|---------|---------|
| `App\Jobs\Cnab\GenerateCnabFileJob` | ~~default~~ → `rjet.cnab.queue.connection` (`cnab_database` / `cnab_redis`) / `cnab`, `retry_after` 240 | 10 / 3 | 120 s | `[10, 30, 60]` | `WithoutOverlapping(payment_settlement_id)->releaseAfter(30)->expireAfter(180)` (~~`dontRelease()`~~) |

**Invariantes de tempo (rev. 2026-09-27):** `timeout 120 < lock 180 < retry_after 240`, com `120 < worker --timeout 150 < 240`. Worker morto em t0: o lock expira em t0+180 e a conexão CNAB reentrega em t0+240; a reentrega pega o lock e `generate()` retoma no mesmo `CnabFile`/NSA. Demais jobs continuam na conexão default (`retry_after` 90), sem mudança.

**Workers (obrigatório):**

| Ambiente | Comando | Observação |
|----------|---------|------------|
| Produção (Redis) | `[program:queue-worker-cnab]` em `docker/supervisor/supervisord-prod.conf`: `php /var/www/html/artisan queue:work cnab_redis --queue=cnab --sleep=3 --timeout=150 --max-time=3600`, `numprocs=1`, `stopwaitsecs=160` | `stopwaitsecs` > 150 para o deploy não matar geração em curso. Requer `pcntl` |
| Produção (driver database) | Mesmo bloco trocando para `queue:work cnab_database --queue=cnab ...` | — |
| Dev (`composer dev`) | `queue:listen --queue=default,cnab` | Aceitável **só em dev** (mesmo backend; crash de worker não é cenário de dev) |

- **Proibido** consumir a fila `cnab` pela conexão default em produção: vale o `retry_after` 90 e o bug volta.
- O worker default atual continua como está.

**Agendador (obrigatório em produção):** `schedule:work` como programa do supervisor **ou** cron com `php artisan schedule:run` a cada minuto. O supervisor atual não tem nenhum dos dois; sem ele o reconciliador (e o `approvals:escalate-sla` já existente) nunca roda.

### 11.3 Reconciliador `cnab:recover-stuck-files` (rev. 2026-09-27)

Rede de segurança, não o caminho principal (a reentrega da própria conexão CNAB resolve a maioria dos crashes).

| Peça | Contrato |
|------|----------|
| Command | `App\Console\Commands\CnabRecoverStuckFilesCommand`, `final`, signature `cnab:recover-stuck-files {--dry-run : Count stuck CNAB files without changing them}`. Espelha `ApprovalsEscalateSlaCommand`: injeta a Action no `handle()`, imprime contagens, `self::SUCCESS` |
| Action | `App\Actions\Cnab\RecoverStuckCnabFilesAction`, `final`, `__invoke(bool $dryRun = false): array{requeued: int, failed: int}` → `CnabFileService::recoverStuck($dryRun)` |
| Agenda | `routes/console.php`: `Schedule::command('cnab:recover-stuck-files')->everyFiveMinutes()->withoutOverlapping();` |

**`CnabFileService::recoverStuck(bool $dryRun = false)`:**

1. **Limiar de parado:** `$retryAfter = config('queue.connections.'.config('rjet.cnab.queue.connection').'.retry_after') ?? 240`; `$staleBefore = now()->subSeconds($retryAfter + config('rjet.cnab.recovery.margin_seconds'))` (= 300 s). **Teto:** `$maxAgeBefore = now()->subMinutes(config('rjet.cnab.recovery.max_age_minutes'))` (= 20 min).
2. **Consulta:** `CnabFile::query()->whereIn('status', CnabFileStatus::inProgressValues())->where('updated_at', '<=', $staleBefore)` (SoftDeletes exclui trashed). Usar `inProgressValues()` (`queued`, `generating`), **não** `activeValues()`.
3. **Iteração:** `->chunkById(100, ...)` na ordem de id. **Não** combinar com `orderBy('updated_at')`: `chunkById` pagina por id, e outra ordenação pula registros. A ordem não muda o resultado, porque cada arquivo é revalidado depois do lock.
4. **Por arquivo, em `DB::transaction`:** `lockForUpdate` e **recarregar pelo model** (`CnabFile::query()->whereKey($id)->lockForUpdate()->first()`, escopo de soft delete ativo). Pular se `null`, se `! $file->status->isInProgress()` ou se `updated_at > $staleBefore` (o job tocou o arquivo entretanto).
   - **Teto** (`created_at <= $maxAgeBefore`): `markFailed($file, __('cnab_files.messages.generation_interrupted'))` → `CnabFileGenerationFailed` → notificação existente; o Retry aparece na UI. `Log::warning` com `cnab_file_id`.
   - **Senão:** `CnabFile::query()->whereKey($file->id)->update(['updated_at' => now()])` (update direto: sem observers/blameable, preserva `updated_by`) e, **após o commit** (`DB::afterCommit`), `GenerateCnabFileJob::dispatch($file)` no **mesmo** `CnabFile`. `Log::info`. O toque em `updated_at` limita a um reenfileiramento por arquivo a cada 5 min.
5. **`--dry-run`:** mesma seleção e mesma classificação (teto × reenfileirar), sem lock de escrita, sem update, sem dispatch, sem `markFailed`; retorna as contagens.

**Não faz:** não cria outro `CnabFile`, não atribui nem altera NSA, não toca `generated`/`failed`/`superseded` nem soft-deleted, não altera a baixa, não chama `retry()`, não remove jobs da fila, não força liberação de lock.

**Sem coluna nova e sem índice novo** (DBA 2026-09-27): teto por `created_at` + throttle por `updated_at` dispensam contador de tentativas; o índice `status` existente em `cnab_files` basta neste volume (~70 pagamentos/dia). Reavaliar índice só acima de ~100 mil linhas em `cnab_files` ou se o `EXPLAIN` mostrar seq scan relevante. **Não alterar migration.**

---

## 12. Traduções (i18n)

Arquivos (pt_BR **e** en; en espelha as mesmas chaves):

| Arquivo | Ação |
|---------|------|
| `lang/{locale}/payment_settlements.php` | **criar** |
| `lang/{locale}/cnab_configs.php` | **criar** |
| `lang/{locale}/cnab_files.php` | **criar** (inclui `validation.*` e `errors.*`) |
| `lang/{locale}/enums.php` | **estender** (4 grupos) |
| `lang/{locale}/notifications.php` | **estender** |
| `lang/{locale}/payment_requests.php` | **estender** (`errors.settlement_required`, `fields.settlement_*`) |
| `lang/{locale}/branch_bank_accounts.php` | **estender** (`errors.has_cnab_config`, `errors.branch_locked`) |
| `lang/{locale}/navigation.php` | sem mudança (grupos `operations` e `settings` já existem) |

### 12.1 `payment_settlements.php` (pt_BR)

```php
return [
    'label' => 'Baixa',
    'plural' => 'Baixas',
    'navigation_label' => 'Baixas',

    'pages' => [
        'create' => ['title' => 'Nova baixa'],
    ],

    'fields' => [
        'branch' => 'Filial',
        'branch_bank_account' => 'Conta pagadora',
        'bank_code' => 'Banco',
        'status' => 'Status',
        'settlement_date' => 'Data de baixa',
        'items_count' => 'Pagamentos',
        'total_amount' => 'Valor total',
        'notes' => 'Observações',
        'cnab_status' => 'Remessa CNAB',
        'settled_at' => 'Confirmada em',
        'settled_by' => 'Confirmada por',
        'cancelled_at' => 'Cancelada em',
        'cancelled_by' => 'Cancelada por',
        'cancellation_reason' => 'Motivo do cancelamento',
        'amount' => 'Valor',
        'released_at' => 'Liberado em',
        'reason' => 'Motivo',
    ],

    'sections' => [
        'summary' => 'Resumo da baixa',
        'cnab' => 'Remessa CNAB',
        'items' => 'Pagamentos da baixa',
        'cnab_files' => 'Arquivos de remessa',
    ],

    'filters' => [
        'branch' => 'Filial',
        'due_from' => 'Vencimento de',
        'due_until' => 'Vencimento até',
        'settlement_from' => 'Baixa de',
        'settlement_until' => 'Baixa até',
        'payment_method' => 'Forma de pagamento',
        'released' => 'Situação do item',
    ],

    'actions' => [
        'create' => 'Nova baixa',
        'settle_selected' => 'Baixar selecionados',
        'validate_cnab' => 'Validar remessa',
        'generate_cnab' => 'Gerar CNAB',
        'confirm' => 'Confirmar baixa',
        'cancel' => 'Cancelar baixa',
        'release_item' => 'Remover da baixa',
        'open_request' => 'Abrir solicitação',
    ],

    'help' => [
        'settlement_date' => 'Data do pagamento no banco. Para gerar CNAB, deve ser hoje ou futura; para confirmar, hoje ou passada.',
    ],

    'hints' => [
        'cnab_available' => 'CNAB disponível para esta conta.',
        'cnab_unavailable' => 'Sem configuração CNAB ativa: baixa manual.',
    ],

    'messages' => [
        'created' => 'Baixa registrada.',
        'selection_summary' => ':count pagamento(s) selecionado(s), total :total.',
        'no_eligible' => 'Nenhuma solicitação lançada disponível para baixa.',
        'no_cnab' => 'Nenhuma remessa gerada para esta baixa.',
        'no_cnab_short' => 'Sem remessa',
        'confirm_description' => 'Todas as solicitações desta baixa passarão para "Pagamento efetivado".',
        'confirm_without_cnab' => 'Nenhuma remessa gerada para esta baixa. Confirme apenas se o pagamento já foi feito no banco.',
        'confirmed' => 'Baixa confirmada.',
        'cancel_with_generated_file' => 'Existe um arquivo gerado. Ele pode já ter sido enviado ao banco.',
        'cancelled' => 'Baixa cancelada.',
        'item_released' => 'Pagamento removido da baixa.',
        'item_active' => 'Ativo',
        'history_note' => 'Baixa de :date (ref. :id)',
    ],

    'errors' => [
        'empty_selection' => 'Selecione ao menos uma solicitação.',
        'mixed_branches' => 'Selecione solicitações de uma única filial.',
        'not_eligible' => ':count solicitação(ões) não está(ão) mais disponível(is) para baixa.',
        'already_in_settlement' => 'Uma ou mais solicitações já estão em outra baixa.',
        'bank_account_not_allowed' => 'A conta pagadora não pertence à filial.',
        'bank_account_inactive' => 'A conta pagadora está inativa ou sem banco.',
        'too_many_items' => 'Uma baixa aceita no máximo :max pagamentos.',
        'total_amount_overflow' => 'O valor total excede o limite permitido.',
        'not_draft' => 'Esta baixa não está em rascunho.',
        'settlement_date_in_future' => 'Não é possível confirmar uma baixa com data futura.',
        'cnab_in_progress' => 'Há uma remessa CNAB em geração. Aguarde a conclusão.',
        'cnab_already_generated' => 'Já existe remessa gerada. Cancele a baixa ou regenere o arquivo.',
        'items_changed' => ':count pagamento(s) mudou(aram) desde a seleção. Remova-os ou cancele a baixa.',
        'cannot_delete_active' => 'Só é possível excluir baixas canceladas.',
        'unauthorized' => 'Você não tem permissão para esta operação na baixa.',
    ],
];
```

### 12.2 `cnab_configs.php` (pt_BR)

```php
return [
    'label' => 'Configuração CNAB',
    'plural' => 'Configurações CNAB',
    'navigation_label' => 'Configurações CNAB',

    'fields' => [
        'branch' => 'Filial',
        'branch_bank_account' => 'Conta bancária',
        'bank_code' => 'Banco',
        'account' => 'Agência / Conta',
        'layout' => 'Layout',
        'company_name' => 'Nome da empresa no arquivo',
        'agreement_code' => 'Convênio',
        'wallet_code' => 'Carteira',
        'payment_type_code' => 'Tipo de pagamento',
        'last_file_sequence' => 'Último NSA',
        'is_active' => 'Ativa',
    ],

    'sections' => [
        'account' => 'Conta',
        'parameters' => 'Parâmetros da remessa',
        'files' => 'Arquivos emitidos',
    ],

    'filters' => [
        'branch' => 'Filial',
        'layout' => 'Layout',
        'is_active' => 'Ativa',
    ],

    'help' => [
        'layout' => 'Deve corresponder ao banco da conta.',
        'company_name_fallback' => 'Até 30 caracteres. Vazio usa a razão social da filial.',
        'payment_type_code' => '20 = pagamento a fornecedores.',
        'last_file_sequence' => 'Número sequencial do último arquivo enviado. Travado após a primeira emissão.',
        'is_active' => 'Desativar pausa a geração. Para substituir a configuração, exclua-a e crie outra.',
    ],

    'messages' => [
        'created' => 'Configuração CNAB criada.',
        'updated' => 'Configuração CNAB atualizada.',
        'deleted' => 'Configuração CNAB excluída.',
        'delete_replaces' => 'Excluir libera a conta para uma nova configuração, que continua a numeração de arquivos.',
    ],

    'errors' => [
        'already_exists' => 'Esta conta já tem uma configuração CNAB.',
        'locked_after_issue' => 'Conta, layout e sequência não podem mudar após a emissão de arquivos.',
        'layout_not_supported' => 'Layout CNAB não suportado.',
        'bank_mismatch' => 'O layout exige o banco :expected, mas a conta é do banco :actual.',
        'sequence_below_issued' => 'O último NSA não pode ser menor que :max, já emitido para esta conta.',
        'has_active_generation' => 'Há remessa em geração com esta configuração.',
    ],
];
```

### 12.3 `cnab_files.php` (pt_BR)

```php
return [
    'label' => 'Arquivo CNAB',
    'plural' => 'Arquivos CNAB',

    'fields' => [
        'file_sequence' => 'NSA',
        'status' => 'Status',
        'filename' => 'Arquivo',
        'items_count' => 'Pagamentos',
        'records_count' => 'Linhas',
        'total_amount' => 'Valor total',
        'failure_reason' => 'Motivo da falha',
        'requested_by' => 'Solicitado por',
        'generated_at' => 'Gerado em',
        'downloaded_at' => 'Primeiro download',
        'supersede_reason' => 'Motivo da substituição',
    ],

    'actions' => [
        'download' => 'Baixar arquivo',
        'retry' => 'Tentar novamente',
        'regenerate' => 'Regenerar',
        'view_errors' => 'Ver erros',
    ],

    'messages' => [
        'queued' => 'Geração da remessa enfileirada.',
        'retry_queued' => 'Nova tentativa enfileirada.',
        'regenerated' => 'Arquivo substituído. Nova geração enfileirada.',
        'regenerate_warning' => 'O arquivo atual será invalidado. Se ele já foi enviado ao banco, há risco de pagamento duplicado.',
        'valid' => 'Remessa válida.',
        'generation_interrupted' => 'A geração foi interrompida. Tente novamente.',
        'cancellation' => 'Baixa cancelada',
    ],

    'report' => [
        'config_errors' => 'Configuração',
        'item_errors' => 'Pagamentos',
        'structural_errors' => 'Estrutura do arquivo',
    ],

    'errors' => [
        'config_not_found' => 'A conta pagadora não tem configuração CNAB.',
        'config_inactive' => 'A configuração CNAB desta conta está desativada.',
        'payment_date_in_past' => 'A data de baixa já passou. Cancele e recrie a baixa ou confirme sem CNAB.',
        'settlement_not_draft' => 'Só é possível gerar CNAB para baixas em rascunho.',
        'generation_already_active' => 'Já existe uma remessa ativa para esta baixa.',
        'remittance_invalid' => 'Remessa reprovada na validação: :count erro(s).',
        'not_retryable' => 'Este arquivo não pode ser gerado novamente.',
        'not_downloadable' => 'Este arquivo não está disponível para download.',
        'file_missing' => 'O arquivo não foi encontrado. Peça a um administrador para regenerar.',
        'integrity_failed' => 'O arquivo não passou na verificação de integridade. Peça a um administrador para regenerar.',
        'storage_failed' => 'Falha ao gravar o arquivo.',
        'file_sequence_exhausted' => 'A numeração de arquivos (NSA) desta conta chegou ao limite.',
    ],

    'validation' => [
        'payment_request_unavailable' => 'A solicitação não está mais lançada ou foi excluída.',
        'amount_changed' => 'O valor da solicitação mudou desde a seleção.',
        'amount_not_positive' => 'O valor deve ser maior que zero.',
        'payment_date_in_past' => 'A data de pagamento já passou.',
        'missing_barcode' => 'Boleto sem código de barras ou linha digitável.',
        'invalid_barcode' => 'Código de barras ou linha digitável inválido.',
        'utility_bill_not_supported' => 'Contas de consumo e tributos não são suportados no CNAB.',
        'pix_qr_code_not_supported' => 'PIX por QR Code não é suportado no CNAB. Informe a chave PIX.',
        'missing_pix_key' => 'Chave PIX ausente.',
        'invalid_pix_key' => 'Chave PIX inválida para o tipo informado.',
        'missing_transfer_data' => 'Dados bancários do favorecido incompletos.',
        'invalid_holder_document' => 'CPF/CNPJ do favorecido inválido.',
        'missing_beneficiary_name' => 'Nome do favorecido ausente.',
        'beneficiary_bank_missing' => 'Banco do favorecido ausente.',
        'branch_document_invalid' => 'CNPJ da filial inválido.',
        'account_data_incomplete' => 'Dados da conta pagadora incompletos.',
        'line_length_invalid' => 'Linha :line com tamanho diferente de 240.',
        'non_ascii_character' => 'Caractere inválido na linha :line.',
        'record_order_invalid' => 'Ordem de registros inválida na linha :line.',
        'sequence_gap' => 'Sequência de registros com lacuna na linha :line.',
        'batch_trailer_mismatch' => 'Trailer do lote :batch não confere.',
        'file_trailer_mismatch' => 'Trailer do arquivo não confere.',
        'total_amount_mismatch' => 'Somatório do arquivo diferente do total dos pagamentos.',
        'bank_code_mismatch' => 'Código do banco diferente do esperado.',
    ],
];
```

### 12.4 `enums.php` (adições pt_BR)

```php
'payment_settlement_status' => [
    'draft' => 'Rascunho',
    'settled' => 'Baixada',
    'cancelled' => 'Cancelada',
],
'cnab_file_status' => [
    'queued' => 'Na fila',
    'generating' => 'Gerando',
    'generated' => 'Gerado',
    'failed' => 'Falhou',
    'superseded' => 'Substituído',
],
'cnab_layout' => [
    'itau_240' => 'Itaú — CNAB 240 (SISPAG)',
],
'cnab_payment_type' => [
    'boleto' => 'Boleto',
    'transfer' => 'Transferência (TED/crédito)',
    'pix_key' => 'PIX (chave)',
],
```

### 12.5 `notifications.php` (adições pt_BR)

```php
'cnab_file_generated' => [
    'title' => 'Remessa CNAB pronta',
    'body' => 'Arquivo NSA :sequence com :count pagamento(s), total :total, disponível para download.',
    'action' => 'Abrir baixa',
],
'cnab_file_generation_failed' => [
    'title' => 'Falha na remessa CNAB',
    'body' => ':reason',
    'action' => 'Abrir baixa',
],
```

### 12.6 Outras extensões (pt_BR)

```php
// payment_requests.php
'errors' => [ /* existentes… */ 'settlement_required' => 'A baixa da solicitação só pode ser feita pela tela de baixas.' ],
'fields' => [ /* existentes… */ 'settlement_date' => 'Data de baixa', 'settlement_account' => 'Conta pagadora', 'settlement' => 'Baixa' ],

// branch_bank_accounts.php
'errors' => [
    /* existentes… */
    'has_cnab_config' => 'Esta conta tem configuração CNAB ativa. Exclua a configuração antes.',
    'branch_locked' => 'A filial desta conta não pode mudar porque ela já foi usada em baixas ou configurações CNAB.',
],
```

**en:** mesmas chaves em `lang/en/*` (ex. `'label' => 'Settlement'`, `'plural' => 'Settlements'`, `'actions.settle_selected' => 'Settle selected'`, `'cnab_configs.label' => 'CNAB configuration'`, `'cnab_files.validation.*'` traduzidos). Nenhuma chave pode existir só em um locale.

---

## 13. API REST

> **Não nesta fase.** O DRF não pede consumo externo de baixas ou remessas, e a restrição DRF §2.4 ("sem integração bancária via API") afasta endpoints de remessa. O MVP é o painel Filament interno. Não criar rotas `api/v1/*`, API Resources, Sanctum/Passport nem Swagger para `PaymentSettlement`, `CnabConfig` ou `CnabFile`. Reavaliar só se surgir consumidor externo (ex. ERP do cliente consultando baixas).

---

## 14. Soft deletes, cascade e guards

### 14.1 Matriz

| Entidade | SoftDeletes | Regra |
|----------|:-----------:|-------|
| `PaymentSettlement` | Sim | Delete só Adm e só `cancelled`. `settled` nunca é excluída via UI |
| `PaymentSettlementItem` | **Não** | Ciclo via `released_at`; o unique parcial depende disso |
| `CnabConfig` | Sim | Soft delete = **substituição** (libera a conta). Desativar não libera. Bloqueado com arquivo `queued`/`generating`. ForceDelete bloqueado pelo restrict de `cnab_files` (inclusive soft-deleted) |
| `CnabFile` | Sim | Sem delete na UI; soft só em cascata da baixa |
| `CnabFileItem` | **Não** | Append-only, hard-owned |

**Regra crítica:** `cascadeOnDelete` / `restrictOnDelete` / `nullOnDelete` só valem para hard/forceDelete. Soft delete **nunca** dispara FK.

### 14.2 Eventos de exclusão

| Evento | FK | Aplicação |
|--------|----|-----------|
| Soft delete **baixa** (cancelada) | nada | `PaymentSettlementObserver::deleted` soft-deleta os `cnab_files` (já `superseded`/`failed`) **por model** |
| **ForceDelete baixa** (Adm, só `cancelled`) | itens de baixa cascade; `cnab_files` **restrict bloqueia** | **Ordem fechada (DBA #1)**, dentro de `DB::transaction`: **(1)** `$settlement->cnabFiles()->withTrashed()->get()->each->forceDelete();` (`withTrashed` obrigatório: os arquivos já foram soft-deletados junto com a baixa e o scope global os esconderia; `each` obrigatório: dispara `CnabFileObserver::forceDeleted`; os `cnab_file_items` saem por cascade). **(2)** `$settlement->forceDelete();` (os `payment_settlement_items` saem por cascade, já sem `cnab_file_items` apontando para eles). Ordem resultante: `cnab_file_items → cnab_files → payment_settlement_items → payment_settlements`. **Nunca** `CnabFile::query()->...->forceDelete()` (query builder não dispara observer e deixa o `.rem` órfão) |
| Arquivo físico no forceDelete | – | `CnabFileObserver::forceDeleted` agenda `Storage::disk($file->disk)->delete($file->path)` **após o commit** (`DB::afterCommit(...)`), para não perder o `.rem` se a transação reverter; tolerar arquivo ausente |
| Soft delete **PR** (Adm) com item ativo em `draft` | nada | Dry-run acusa `payment_request_unavailable`; confirmar lança `itemsChangedSinceSelection` |
| ForceDelete **PR** | `payment_settlement_items.payment_request_id` restrict **bloqueia** | Intencional |
| Soft delete **BranchBankAccount** | nada | **Guard novo:** bloquear se houver `CnabConfig` viva (`branch_bank_accounts.errors.has_cnab_config`). Baixas existentes leem a conta com `withTrashed` |
| ForceDelete **BranchBankAccount** | restrict (settlements, configs) **bloqueia** | Intencional |
| Update `BranchBankAccount.branch_id` | – | **Guard novo (DBA #3):** bloquear se a conta for referenciada por qualquer `PaymentSettlement` (withTrashed) ou `CnabConfig` (withTrashed) → `branch_bank_accounts.errors.branch_locked` |
| Restore **baixa** | – | Sem restore de conteúdo no MVP além do registro; se implementado, `PaymentSettlementObserver::restored` restaura os `cnab_files` com o mesmo `deleted_at` |
| Restore **CnabConfig** | – | Falha no unique parcial se já houver outra viva → mapear `UniqueConstraintViolationException` para `configAlreadyExists` |

### 14.3 Guards de negócio (resumo)

| Guard | Onde | Exceção |
|-------|------|---------|
| PR em baixa `draft` não editável por não-Adm | `PaymentRequest::isEditableBy()` | – (Policy nega) |
| `Settled` só via baixa | `PaymentRequestService::transitionStatus()` | `PaymentRequestException::settlementRequired` |
| Conta com config viva não pode ser excluída | `BranchBankAccountService`/Policy | `has_cnab_config` |
| `branch_id` de conta referenciada imutável | `BranchBankAccountService::update` | `branch_locked` |
| Config travada após emissão | `CnabConfigService::update` | `configLockedAfterIssue` |
| NSA não retrocede | `CnabConfigService::create/update` | `fileSequenceBelowIssued` |
| NSA não passa de 999999 | `CnabFileService::generate` | `fileSequenceExhausted` |

**Pruning:** adiado (retenção financeira; alinhado a F3/F6).

---

## 15. Factories e seeders

| Factory | Definition | States |
|---------|------------|--------|
| `PaymentSettlementFactory` | `branch_id` + `branch_bank_account_id` de conta **da mesma filial** (`recycle`), `status = draft`, `settlement_date = today('America/Sao_Paulo')`, `items_count = 0`, `total_amount = '0.00'` | `draft()`, `settled()` (`settled_at/by`), `cancelled()` (`cancelled_at/by/reason`), `forBranch(Branch)`, `forAccount(BranchBankAccount)`, `dated(string $date)`, `withItems(int $n = 3)` (`afterCreating`: cria `$n` PRs `launched()` da filial + itens com `amount = net_amount` + `recalculateTotals`) |
| `PaymentSettlementItemFactory` | `payment_settlement_id`, `payment_request_id` (**sempre nova** PR `launched()` da filial da baixa, para não colidir no unique parcial), `amount = net_amount` da PR | `released()` (`released_at/by`), `forPaymentRequest(PaymentRequest)` |
| `CnabConfigFactory` | conta `BranchBankAccount::factory()->itau()` **nova** por config (o unique parcial por conta impede reuso), `layout = itau_240`, `payment_type_code = '20'`, `last_file_sequence = 0`, `is_active = true` | `itau240()`, `inactive()`, `forAccount(BranchBankAccount)`, `withSequence(int $n)`, `trashed()` (para testar substituição + herança de NSA) |
| `CnabFileFactory` | `payment_settlement_id` + `cnab_config_id` da **mesma conta** da baixa (`recycle`), `status = queued`, `layout = itau_240`, `file_sequence = null` | `queued()`, `generating()`, `generated()` (`file_sequence` por `sequence()` crescente por config, `disk`, `path`, `filename`, `checksum`, `size`, `generated_at`; o teste grava o conteúdo com `Storage::fake`), `failed()` (`failure_reason`), `superseded()` (`superseded_at/by/reason`). **Só um** arquivo por baixa em `queued`/`generating`/`generated` |
| `CnabFileItemFactory` | `cnab_file_id`, `payment_settlement_item_id` da **mesma baixa** do arquivo (`recycle`), `payment_type = transfer`, `reference` = `strtoupper(substr(str_replace('-', '', $pr->id), -20))` (**últimos** 20 hex; único no arquivo), `amount`, `is_valid = true` | `invalid(string $code)` (`is_valid = false`, `validation_errors = [['code' => $code, 'field' => null, 'params' => []]]`), `boleto()`, `pixKey()` |
| `BranchBankAccountFactory` (existente, **estender**) | – | `itau()`: `bank_code = '341'`, `bank_id` de `Bank::query()->firstOrCreate(['code' => '341'], ['name' => 'Itaú Unibanco', 'ispb' => '60701190', 'is_active' => true])` (o `banks_code_unique` é parcial; `Bank::factory()` repetido quebraria), agência 4 dígitos, conta e DV |
| `PaymentRequestBankDetailsFactory` (existente) | já tem `boleto()`, `pix()`, `pixQrCode()`, `pixBoth()`, `transfer()` | **Estender** `boleto()` com linha digitável **válida** (DVs corretos) para golden files; novo `utilityBill()` (arrecadação 48 dígitos iniciando com `8`) |
| `PaymentRequestFactory` (existente) | já tem `launched()`, `settled()`, `boleto()`, `depositPix()`, `depositPixQrCode()`, `depositTransfer()`, `forBranch()` | Opcional `inDraftSettlement()` |

**Seeders:**

- `CnabConfigSeeder` (**apenas dev**, chamado pelo `DevelopmentSeeder`): idempotente (`firstOrCreate` por `branch_bank_account_id`) para a conta Itaú padrão da filial demo, se existir. Nunca em produção.
- Sem seeder de baixas ou arquivos (dados transacionais; testes usam factories).

---

## 16. Tests (Pest)

Docs: https://filamentphp.com/docs/5.x/testing/overview · Skill `testing-best-practices` antes de escrever.
Ambiente: `docker compose exec app php artisan test --compact --filter=...`. Helpers: `Storage::fake`, `Queue::fake`, `Event::fake`, `Notification::fake`, `$this->travelTo(...)` com Carbon em `America/Sao_Paulo`. Testes de Resource via `Livewire::test(Page::class)` (padrão dos testes F5/F6).

### 16.1 Baixa (RF030/RF031)

| # | Arquivo | Caso |
|---|---------|------|
| T01 | `PaymentSettlementEligibilityTest` | `eligibleQuery` retorna só `Launched`; respeita filial, vencimento e forma; exclui trashed; exclui PR com item ativo; **inclui** PR cujo item foi liberado |
| T02 | `PaymentSettlementServiceTest` | Cria `draft` com snapshot de `amount`, `items_count`, `total_amount` (bcmath) e `PaymentSettlementCreated`; rejeita seleção vazia, filiais misturadas, conta de outra filial, conta inativa/sem banco, PR não elegível, excesso de itens |
| T03 | `PaymentSettlementServiceTest` | PR já em baixa `draft` → `paymentRequestAlreadyInSettlement` (unique parcial) |
| T04 | `PaymentSettlementServiceTest` | **Confirmar:** PRs → `Settled` + linhas em `payment_request_status_history` + `PaymentRequestStatusChanged` por PR após commit; `settled_at/by`; data futura → exceção; arquivo `queued/generating` → exceção; PR trashed ou valor alterado → exceção **sem nenhuma PR alterada** |
| T05 | `PaymentSettlementServiceTest` | **Cancelar:** itens com `released_at`, arquivo `generated` → `superseded`, PRs elegíveis de novo; bloqueado com arquivo em andamento |
| T06 | `PaymentSettlementServiceTest` | **Remover item:** recalcula totais; bloqueado com arquivo ativo/gerado |
| T07 | `PaymentRequestStatusTransitionTest` (**atualizar**) | `Launched → Settled` sem baixa → `settlementRequired`; com baixa `draft` contendo a PR → ok |
| T08 | `PaymentRequestEditabilityInSettlementTest` | Operador não edita PR em baixa `draft`; Adm edita; após cancelamento, Operador volta a editar |

### 16.2 Authorization

| # | Arquivo | Caso |
|---|---------|------|
| T09 | `PaymentSettlementAuthorizationTest` | Matriz §9: Cliente nega tudo (viewAny, create, confirm, cancel, generateCnab, download, config); Operador opera; Adm deleta só `cancelled`; `regenerate` só Adm; `CnabConfig` escrita só Adm, Operador só lê; `CnabFilePolicy::delete` sempre `false` |

### 16.3 Config CNAB (RF032)

| # | Arquivo | Caso |
|---|---------|------|
| T10 | `CnabConfigServiceTest` | Única por conta; banco ≠ `341` para `itau_240` → `bankMismatch`; blameable preenchido; conta/layout/`last_file_sequence` travados após emissão (`configLockedAfterIssue`) |
| T11 | `CnabConfigServiceTest` | **Seed de NSA (DBA #2):** config A emite NSA 7 → soft delete A → `create` B para a mesma conta → `B.last_file_sequence = 7`; `update` B para 5 → `fileSequenceBelowIssued(7)` |
| T12 | `CnabConfigServiceTest` | **Desativar não libera (DBA #2):** config inativa + `create` na mesma conta → `configAlreadyExists` |

### 16.4 Dry-run, adapter e validador (RF033)

| # | Arquivo | Caso |
|---|---------|------|
| T13 | `CnabRemittanceDryRunTest` | Baixa válida → relatório ok, **nenhum** arquivo no disco, `last_file_sequence` inalterado; erros esperados para PIX só QR, arrecadação, transferência incompleta, documento inválido, valor alterado, data passada, PR excluída, CNPJ da filial inválido |
| T14 | `CnabRemittanceValidatorTest` (unit) | Linha ≠ 240 → `line_length_invalid`; não-ASCII; ordem de registros; lacuna de sequência; trailer de lote/arquivo divergente; somatório divergente |
| T15 | `Itau240RemittanceAdapterTest` | Golden files (`tests/Fixtures/cnab/itau240/*.rem`): boleto Itaú, boleto outro banco, TED, crédito Itaú, PIX chave → conteúdo idêntico; um lote por forma; `fileName()` na convenção |
| T16 | `BoletoBarcodeTest` (unit) | Linha digitável 47 → barcode 44; DVs inválidos rejeitados; arrecadação detectada |
| T17 | `CnabRemittanceDryRunTest` | **`reference` = últimos 20 hex (DBA #5):** para PR com UUID conhecido, `reference === strtoupper(substr(str_replace('-', '', $id), -20))`; duas PRs criadas em sequência (orderedUuid) têm references diferentes |

### 16.5 Job (RF033)

| # | Arquivo | Caso |
|---|---------|------|
| T18 | `GenerateCnabFileJobTest` | Sucesso: `generated`, arquivo em `Storage::fake` com nome e checksum, `cnab_file_items` com lote/sequência/forma, NSA incrementado **uma vez**, `CnabFileGenerated`, notificação ao solicitante |
| T19 | `GenerateCnabFileJobTest` | Dry-run reprovado: `failed`, itens com `validation_errors`, **sem** arquivo, NSA **não** consumido, job não relança (sem retry), `CnabFileGenerationFailed` |
| T20 | `GenerateCnabFileJobTest` | Falha de storage: exceção → `failed()` marca `failed` com mensagem genérica; Retry cria novo `CnabFile(queued)` |
| T21 | `GenerateCnabFileJobTest` | Idempotência: segundo `request` com arquivo ativo → `generationAlreadyActive`; job em arquivo `generated` → no-op; retry com `file_sequence` já atribuído reusa o mesmo NSA |
| T22 | `GenerateCnabFileJobTest` | `last_file_sequence = 999999` → `fileSequenceExhausted`, arquivo `failed` |
| T35 | `GenerateCnabFileJobTest` | **Conexão (rev. 2026-09-27):** com `config(['rjet.cnab.queue.connection' => 'cnab_database'])` + `Queue::fake()`, o dispatch do listener sai em `cnab_database` / fila `cnab` (`Queue::assertPushedOn('cnab', ...)` e `$job->connection`); `tries = 10`, `maxExceptions = 3`; `middleware()` tem `WithoutOverlapping` com `releaseAfter = 30` e `expiresAfter = 180` |
| T36 | `GenerateCnabFileJobTest` | **`releaseAfter` não descarta:** lock da baixa ocupado (`Cache::lock` do `WithoutOverlapping`) → job liberado de volta à fila (não deletado, `failed()` não chamado, arquivo segue `queued`); após liberar o lock, nova execução gera `generated` |
| T37 | `GenerateCnabFileJobTest` | **Reentrada e passo final:** arquivo já em `generating` com `started_at` e `file_sequence` → `generate()` atualiza `updated_at` e reusa o mesmo NSA (`last_file_sequence` inalterado); arquivo virou `failed` antes do passo 7 → não grava `generated`, apaga o `.rem` recém-gravado do `Storage::fake`, **sem** `CnabFileGenerated` |

### 16.5.1 Reconciliador — `CnabRecoverStuckFilesCommandTest` (rev. 2026-09-27)

| # | Caso |
|---|------|
| T38 | Arquivo `generating` com `updated_at` há > 300 s e `created_at` há < 20 min → `Queue::fake`: `GenerateCnabFileJob` reenfileirado com o **mesmo** `CnabFile` na conexão CNAB; `updated_at` tocado; `file_sequence` e `last_file_sequence` inalterados; nenhum `CnabFile` novo |
| T39 | Parado e `created_at` há > 20 min → `failed` com `failure_reason` = `cnab_files.messages.generation_interrupted`; `CnabFileGenerationFailed` disparado; nenhum job reenfileirado |
| T40 | Ignorados: `generating` recente (< 300 s); `generated`, `failed`, `superseded`; arquivo parado soft-deleted. Nada muda, nenhum job |
| T41 | `--dry-run` → saída com as contagens; nenhum update, nenhum job, nenhum `markFailed` |

Sem teste de sincronia de literais de índice para o reconciliador: não há índice novo (DBA 2026-09-27).

### 16.6 Download (RF034)

| # | Arquivo | Caso |
|---|---------|------|
| T23 | `CnabFileDownloadTest` | Operador/Adm baixam (stream, `Content-Disposition` com `filename`); Cliente 403; `superseded`/`failed` não baixáveis; arquivo ausente → erro amigável; checksum divergente → erro; primeiro download grava `downloaded_at/by`; `CnabFileDownloaded` a cada download |

### 16.7 Schema / DBA — `SettlementSchemaConstraintsTest`

| # | Caso |
|---|------|
| T24 | Unique parcial de item ativo por PR: dois ativos falham; item liberado libera a PR |
| T25 | Config viva por conta: inativa **não** libera a vaga; soft delete libera |
| T26 | Arquivo ativo por baixa: segundo `queued/generating/generated` falha; `failed`/`superseded` convivem |
| T27 | **NSA sem `deleted_at` (DBA #2):** arquivo com `(config, file_sequence = 5)` soft-deletado; inserir outro arquivo `(config, 5)` → `UniqueConstraintViolationException` |
| T28 | Uniques `(cnab_file_id, payment_settlement_item_id)` e `(cnab_file_id, reference)` |
| T29 | **Literais do índice = enum (DBA #4):** ler o SQL do índice `cnab_files_settlement_active_unique` (sqlite: `SELECT sql FROM sqlite_master WHERE name = ?`; pgsql: `pg_indexes.indexdef`), extrair os literais do `IN (...)` e comparar (ordenado) com `CnabFileStatus::activeValues()` |
| T30 | **ForceDelete withTrashed (DBA #1):** baixa `cancelled` com arquivo `superseded` gravado em `Storage::fake`; soft delete da baixa (arquivo soft-deletado pelo observer); `PaymentSettlementService::forceDelete` → baixa, itens, arquivos e itens de arquivo removidos do banco **e** `.rem` removido do disco |
| T31 | `cnab_file_items` sem `updated_at`; model não tenta gravar `updated_at` |

### 16.8 Filament

| # | Arquivo | Caso |
|---|---------|------|
| T32 | `PaymentSettlementResourceTest` | Lista e View renderizam para Operador/Adm; Cliente 403; página de seleção lista só elegíveis; bulk action cria a baixa e redireciona para a View; filiais misturadas → notificação de erro e nada criado; View mostra/esconde actions por estado; `TransitionStatusAction` da PR não oferece `Settled` |
| T33 | `CnabConfigResourceTest` | Create/Edit por Adm; Operador vê lista/view sem botões de escrita; select de contas filtrado pela filial; campos travados após emissão |

### 16.9 Unit (enums)

| # | Arquivo | Caso |
|---|---------|------|
| T34 | `SettlementEnumsTest` | Matrizes `canTransitionTo` de `PaymentSettlementStatus` e `CnabFileStatus`; `activeValues()`; `CnabLayout::Itau240->bankCode() === '341'`; `CnabPaymentType::fromPaymentRequest` (PIX só QR → null; chave + QR → `PixKey`) |

---

## 17. Estimativa

| Componente | Complexidade | Tempo estimado |
|------------|--------------|----------------|
| Enums + exceções + traduções (pt_BR/en) | Média | 1h30 |
| Migrations + models + factories + hooks em PR | Média–Alta | 2h |
| `BoletoBarcode` + `FixedWidthFormatter` + `CnabRemittanceValidator` | Média | 2h |
| `Itau240RemittanceAdapter` + `Itau240Layout` + golden files | Alta | 4h (depende de P-F7-SPEC) |
| Services (4) + Actions de domínio | Alta | 4h |
| Job + events + listeners + notifications | Média | 1h30 |
| Observers + policies + guards de conta | Média | 1h |
| `CnabConfigResource` | Média | 1h30 |
| `PaymentSettlementResource` (página de seleção, View, actions, RMs, polling) | Alta | 3h30 |
| Testes Pest (§16) | Alta | 5h |
| Pint + ajustes | Baixa | 30 min |
| **Total** | – | **~26–28 h** (sem homologação bancária) |

---

## 18. Notas, checklist e pendências

### 18.1 Checklist pré-implementação

- [ ] Ler este blueprint inteiro; em dúvida de schema, arquitetura §21
- [ ] Enums §4 + `enums.php` (pt_BR/en)
- [ ] Traduções §12 (todos os arquivos, os dois locales)
- [ ] Config `rjet.cnab.*` + `.env.example` (inclui `cnab.queue.*`/`cnab.recovery.*`, ver bloco "Fila CNAB" abaixo)
- [ ] Migrations §3 na ordem; 4 uniques parciais com `supportsPartialIndexes()` + `DROP INDEX IF EXISTS` antes do drop; literais de status no predicado; NSA sem `deleted_at`; comentários de desnormalização; FKs com `->index()`; uniques Blueprint só em `cnab_file_items`; sem índice simples em `settlement_date` nem em `cnab_configs.layout`; sem `after()`/`check()`/`$table->enum()`
- [ ] Models + factories; `CnabFileItem` com `$timestamps = false` + `UPDATED_AT = null`; `CnabFile::config()` withTrashed
- [ ] Hooks em `PaymentRequest` (sem colunas novas)
- [ ] `PaymentSettlementException`, `CnabException`, `PaymentRequestException::settlementRequired`
- [ ] `BoletoBarcode`, `App\Integrations\Cnab\*` + bind do resolver
- [ ] Services §6.2 (seed/guard de NSA; ordem de forceDelete); Actions §6.3; Job §6.4
- [ ] Events/Listeners/Notifications §11; `PaymentRequestStatusChanged` com `ShouldDispatchAfterCommit`; `php artisan event:list` sem duplicidade
- [ ] Observers registrados (`PaymentSettlementObserver`, `CnabFileObserver`)
- [ ] Policies §9 registradas; guards de `BranchBankAccount` (§14)
- [ ] `transitionStatus(..., ?PaymentSettlement)` + `TransitionStatusAction` sem `Settled` + teste existente atualizado
- [ ] Filament §7 e §8 (pasta plural, `final`, Resource limpo, infolist, `recordActions`/`toolbarActions`, `Heroicon` enum, `->live()`, labels `__()`, sem Edit na baixa, página de seleção Page + HasTable)
- [ ] Testes §16 (inclui T11, T12, T17, T27, T29, T30 da DBA; T35–T41 da rev. 2026-09-27)
- [ ] `vendor/bin/pint --dirty --format agent`

**Fila CNAB e reconciliador (rev. 2026-09-27, §6.4, §6.7, §11.2, §11.3):**

- [ ] `config/queue.php`: conexões `cnab_database` e `cnab_redis` (fila `cnab`, `retry_after` 240 via `RJET_CNAB_QUEUE_RETRY_AFTER`)
- [ ] `config/rjet.php`: `cnab.queue.connection`, `cnab.queue.name`, `cnab.recovery.margin_seconds` (60), `cnab.recovery.max_age_minutes` (20)
- [ ] `.env.example`: `RJET_CNAB_QUEUE_CONNECTION=cnab_redis`, `RJET_CNAB_QUEUE=cnab`, `RJET_CNAB_QUEUE_RETRY_AFTER=240`
- [ ] `phpunit.xml`: `<env name="RJET_CNAB_QUEUE_CONNECTION" value="sync"/>`
- [ ] Job: `onConnection`/`onQueue` no construtor, `$tries = 10`, `$maxExceptions = 3`, `releaseAfter(30)`, sem `dontRelease()`; os dois ajustes de `generate()` (passos 2 e 7)
- [ ] Command `cnab:recover-stuck-files` + `RecoverStuckCnabFilesAction` + `CnabFileService::recoverStuck()`; agenda `everyFiveMinutes()->withoutOverlapping()` em `routes/console.php`; `chunkById(100)` **sem** `orderBy('updated_at')`; revalidação após `lockForUpdate`
- [ ] Supervisor de produção: programa `queue-worker-cnab` (`queue:work cnab_redis --queue=cnab --sleep=3 --timeout=150 --max-time=3600`, `numprocs=1`), com **`stopwaitsecs=160`**
- [ ] Nenhum worker de produção consome a fila `cnab` pela conexão default
- [ ] Agendador em produção: `schedule:work` no supervisor ou cron `schedule:run` (hoje não existe)
- [ ] Extensão `pcntl` instalada na imagem de produção (necessária para `--timeout`)
- [ ] `composer dev`: `queue:listen --queue=default,cnab` (só dev)
- [ ] **Não** alterar migration de `cnab_files` nem criar índice novo (DBA 2026-09-27)

### 18.2 Notas para o implementer (DBA §21, obrigatórias)

1. Copiar `supportsPartialIndexes()` de `create_approvals_table`. Índices parciais via `DB::statement`; `down()` com `DROP INDEX IF EXISTS` **antes** do `dropIfExists`.
2. Nomes dos índices parciais: `payment_settlement_items_active_request_unique`, `cnab_configs_account_unique`, `cnab_files_settlement_active_unique`, `cnab_files_config_sequence_unique`.
3. Predicado de arquivo ativo com literais `'queued', 'generating', 'generated'`. **Não** interpolar `CnabFileStatus::activeValues()` na migration.
4. `cnab_file_items`: `$table->unique(['cnab_file_id', 'payment_settlement_item_id'])`, `$table->unique(['cnab_file_id', 'reference'])`, `$table->timestampTz('created_at')->useCurrent()`.
5. Comentários de desnormalização em inglês (§3.3) e comentário de cascade nas FKs cascade.
6. Sem `->index()` em `settlement_date` nem em `cnab_configs.layout`; `status` com `->index()`; composto `$table->index(['branch_id', 'settlement_date'])`.
7. ForceDelete da baixa: transação + `cnabFiles()->withTrashed()->get()->each->forceDelete()` + `forceDelete()` da baixa; físico após o commit.
8. `CnabConfigService::create`: `last_file_sequence` = maior `file_sequence` dos `cnab_files` cujas configs (withTrashed) têm o mesmo `branch_bank_account_id`; `update` rejeita valor menor (`fileSequenceBelowIssued`).
9. Atribuição de NSA: `lockForUpdate` na config; `> 999999` → `fileSequenceExhausted`.
10. `reference` = `strtoupper(substr(str_replace('-', '', $paymentRequest->id), -20))`.
11. Capturar `UniqueConstraintViolationException` nos creates de item de baixa, de `CnabFile` e de `CnabConfig` e mapear para as exceções de domínio.
12. `payment_requests`: nenhuma coluna e nenhum índice novo.

### 18.3 Decisões de UI fechadas neste blueprint (além da arquitetura)

| # | Decisão |
|---|---------|
| U1 | Página de seleção é `Filament\Resources\Pages\Page` + `HasTable` na rota `create`; o `CreateRecord` do scaffold é substituído |
| U2 | Bulk action usa `->schema(PaymentSettlementForm::components())`; seleção multi-filial é bloqueada na action e no Service |
| U3 | Relatório de dry-run em modal com view Blade simples (`x-filament::section` / `x-filament::badge`), sem Livewire |
| U4 | Polling `5s` na View só enquanto `currentCnabFile` ∈ {`queued`, `generating`} |
| U5 | `latestCnabFile` (inclui `failed`) alimenta Retry e `failure_reason`; `currentCnabFile` (só ativos) alimenta Download e status |
| U6 | `Schema::columns(1)` + Section `columns(2)` em Form e Infolist |
| U7 | Nenhum `path`/`disk` visível na UI nem nas notificações |
| U8 | Widgets: nenhum |

### 18.4 Pendências (dependem da RJET/Itaú; **não bloqueiam o schema**)

| ID | Tema | Impacto no plano |
|----|------|------------------|
| P-F7-SPEC | Versão do manual SISPAG CNAB 240 Itaú (posições, formas, finalidade TED, J-52) | O layout byte a byte fica em `Itau240Layout`/adapter, **não** neste plano. Códigos de forma de §6.5 são referência |
| P-F7-HOMOLOG | Acesso a homologação/validador Itaú (R09) | Sem isso, os golden files validam só a estrutura, não a aceitação bancária |
| P-F7-FILENAME | Convenção de nome "acordada" (RF034) | Default `{bank}_{cnpj}_{data}_{NSA}.rem`; ajustável só em `adapter->fileName()` |
| P-F7-NSA | Itaú exige NSA contíguo? | Se exigir, reavaliar consumo de NSA em falha de infra (hoje fica consumido) |
| P-F7-CLIENT-VIEW | Cliente vê baixas das suas filiais? | MVP: não; `scopeVisibleTo` já permite abrir depois |
| P-F7-SOD | Confirmação exige aprovador (`can_approve`)? | MVP: não; hook via ability `confirm` |
| P-F7-TRIBUTOS | Segmentos O/N e PIX QR | Fora do MVP; inelegíveis para CNAB, baixa manual permitida |

---

## 19. Próximos passos

1. Encaminhar ao **`implementer`**, nesta ordem: enums + traduções → config → migrations → models/factories → exceções → `BoletoBarcode` + `FixedWidthFormatter` + `CnabRemittanceValidator` → `Itau240RemittanceAdapter` (golden files) → Services → Actions → Events/Listeners/Notifications → Job → Observers → Policies + guards → hooks em `PaymentRequest`/`PaymentRequestService`/`TransitionStatusAction` → Filament → testes §16 → Pint.
2. Após a implementação: `tester` cobre §16; `reviewer` e `security` conforme o pipeline do projeto.
3. Homologação (R09): gerar arquivos de exemplo (boleto, TED, PIX) e validar no ambiente do Itaú antes de produção.
4. Não iniciar a Fase 8 nesta entrega.

---

*Fim do Filament Blueprint — Fase 7. Pronto para `implementer`.*
