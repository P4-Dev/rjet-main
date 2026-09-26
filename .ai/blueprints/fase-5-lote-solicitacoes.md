# Filament Blueprint: Fase 5 — Lote de solicitações (importação via planilha)

> **Tipo:** Plano de implementação (Filament Blueprint v5). **NÃO é código de produção.**
> **Escopo:** RF025–RF026 — `ImportTemplate` (CRUD Adm + versionamento), `ImportTemplateVersion` + `ImportTemplateMapping`, `ImportBatch` (upload + job), `ImportBatchError` (relatório por linha), vínculo `payment_requests.import_batch_id`, reader OpenSpout, event `PaymentRequestBatchImported`.
> **Fonte de requisitos:** [.ai/arquitetura/fase-5-lote-solicitacoes.md](../arquitetura/fase-5-lote-solicitacoes.md) (revisão DBA §24 — **Aprovado com ressalvas**, já incorporadas).
> **Stack:** Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL · Redis (filas).
> **Autor:** blueprint · **Data:** 2026-09-26
> **Destinatário:** `implementer` — este documento é a **única** fonte que o implementador verá. Todas as regras relevantes foram copiadas para dentro dele.

---

## Índice

0. [Regras globais Filament v5](#0-regras-globais-filament-v5-leia-primeiro)
1. [Visão Geral](#1-visão-geral)
2. [Commands (ordem de execução)](#2-commands-ordem-de-execução)
3. [Models e Migrations](#3-models-e-migrations)
4. [Enums](#4-enums)
5. [Exceções de Domínio](#5-exceções-de-domínio)
6. [Camadas de Aplicação](#6-camadas-de-aplicação)
7. [Filament Resource: ImportTemplate](#7-filament-resource-importtemplate)
8. [Filament Resource: ImportBatch](#8-filament-resource-importbatch)
9. [Authorization / Policies](#9-authorization--policies)
10. [State Transitions](#10-state-transitions)
11. [Events / Notifications / Queue](#11-events--notifications--queue)
12. [Traduções (i18n)](#12-traduções-i18n)
13. [API REST](#13-api-rest)
14. [Soft deletes, cascade e guards](#14-soft-deletes-cascade-e-guards)
15. [Factories & Seeders](#15-factories--seeders)
16. [Tests (Pest)](#16-tests-pest)
17. [Estimativa](#17-estimativa)
18. [Notas e checklist pré-implementação](#18-notas-e-checklist-pré-implementação)
19. [Próximos passos](#19-próximos-passos)

---

## 0. Regras globais Filament v5 (LEIA PRIMEIRO)

### 0.1 Namespaces Filament v5 (obrigatórios — errar aqui quebra a implementação)

| Elemento | Namespace |
|----------|-----------|
| Form fields | `Filament\Forms\Components\{Component}` |
| Table columns | `Filament\Tables\Columns\{Column}` |
| Table filters | `Filament\Tables\Filters\{Filter}` |
| Table summarizers | `Filament\Tables\Columns\Summarizers\{Summarizer}` |
| **Todas** as Actions (row, header, bulk, page) | `Filament\Actions\{Action}` |
| Infolist entries | `Filament\Infolists\Components\{Entry}` |
| Layout (Section, Grid, Fieldset, Tabs, Flex) | `Filament\Schemas\Components\{Component}` |
| Actions dentro de schema | `Filament\Schemas\Components\Actions` |
| Reactive utilities | `Filament\Schemas\Components\Utilities\Get` e `...\Set` |
| List page tabs | `Filament\Resources\Pages\ListRecords\Tab` |
| Ícones | `Filament\Support\Icons\Heroicon` (**enum**, nunca string) |
| Schema container | `Filament\Schemas\Schema` |
| Table container | `Filament\Tables\Table` |
| Notificações toast | `Filament\Notifications\Notification` |
| Auth do painel | `Filament\Facades\Filament` → `Filament::auth()->user()` |

**Namespaces ERRADOS (não usar):**

| Errado | Certo |
|--------|-------|
| `Filament\Forms\Get` / `Filament\Forms\Set` | `Filament\Schemas\Components\Utilities\Get` / `Set` |
| `Filament\Tables\Actions\Action` | `Filament\Actions\Action` |
| `Filament\Forms\Components\Actions` | `Filament\Schemas\Components\Actions` |
| `Filament\Forms\Components\Section` | `Filament\Schemas\Components\Section` |

**Métodos ERRADOS:** `->reactive()` → usar **`->live()`**. `->actions()` → **`->recordActions()`**. `->bulkActions()` → **`->toolbarActions([BulkActionGroup::make([...])])`**.

**Componentes que não existem:** `Card` (use `Section`), `BadgeColumn` (use `TextColumn->badge()`), `BooleanColumn` (use `IconColumn->boolean()`), `DateColumn` (use `TextColumn->date()`), `MultiSelect` (use `Select->multiple()`).

**Docs (backup):** https://filamentphp.com/docs/5.x/forms/overview · https://filamentphp.com/docs/5.x/tables/overview · https://filamentphp.com/docs/5.x/actions/overview · https://filamentphp.com/docs/5.x/infolists/overview · https://filamentphp.com/docs/5.x/schemas/overview

### 0.2 Estrutura obrigatória do Resource (pasta PLURAL, Resource LIMPO)

```
app/Filament/Resources/ImportTemplates/          ← PLURAL
├── ImportTemplateResource.php                   ← final, LIMPO: só delegates
├── Schemas/ImportTemplateForm.php               ← final
├── Schemas/ImportTemplateInfolist.php           ← final (SEMPRE)
├── Tables/ImportTemplatesTable.php              ← final, nome PLURAL
├── Pages/{Create,Edit,List,View}ImportTemplate.php
├── RelationManagers/ImportTemplateVersionsRelationManager.php
└── Actions/PublishImportTemplateVersionAction.php

app/Filament/Resources/ImportBatches/            ← PLURAL
├── ImportBatchResource.php                      ← final, LIMPO
├── Schemas/ImportBatchForm.php                  ← final (Create = upload)
├── Schemas/ImportBatchInfolist.php              ← final (SEMPRE)
├── Tables/ImportBatchesTable.php                ← final
├── Pages/{Create,List,View}ImportBatch.php      ← SEM Edit
├── RelationManagers/ImportBatchErrorsRelationManager.php
├── RelationManagers/PaymentRequestsRelationManager.php
└── Actions/DownloadImportSpreadsheetAction.php
```

**VALIDAÇÃO:** se `*Resource.php` contiver `TextInput`, `TextColumn`, `Section`, `Select`, `Toggle` ou qualquer componente de form/table/layout diretamente, **está errado**. Todas as classes são `final` e têm `declare(strict_types=1);`.

**Comando Artisan (SEMPRE usar, NUNCA criar Resource na mão):**

```bash
php artisan make:filament-resource ImportTemplate --generate --soft-deletes --view --panel=admin --no-interaction
php artisan make:filament-resource ImportBatch --generate --soft-deletes --view --panel=admin --no-interaction
```

### 0.3 Layout — largura efetiva das colunas (crítico)

| Colunas do Form | Colunas da Section | Largura efetiva | OK? |
|---|---|---|---|
| 2 | 2 | 25% | **NÃO** |
| **1** | **2** | **50%** | **Sim ← padrão desta fase** |
| 1 | 1 | 100% | Sim (campos largos / Repeater) |

**Decisão desta fase:** `Schema::columns(1)` no Form e no Infolist; cada `Section` com `->columns(2)` (exceto Repeater de mappings → `columnSpanFull`).

### 0.4 i18n (obrigatório)

Nenhuma string de UI hardcoded. Todo label/heading/placeholder/helperText/notificação usa `__()`. Campos comuns em `common.php`; específicos em `import_templates.php`, `import_batches.php`, `enums.php`, `notifications.php`. Arquivos em **`lang/pt_BR/`** e **`lang/en/`** (ambos obrigatórios).

### 0.5 Padrões do projeto (F1–F4 já implementados — seguir sem desviar)

- Models de domínio: `final`, `declare(strict_types=1)`, traits `HasFactory, HasUuid, SoftDeletes, HasBlameable` (quando aplicável), `casts()` como **método**, PK `uuid`.
- Migrations: `timestampsTz()` + `softDeletesTz()` onde aplicável; FK com `->index()` explícito; **enum sempre `string`**, nunca `$table->enum()`.
- Unique parcial: **nunca** `->unique()` na coluna quando SoftDeletes. Sempre `DB::statement` dentro de `if ($this->supportsPartialIndexes())`, com `DROP INDEX IF EXISTS` no `down()`. Helper privado:
  ```php
  private function supportsPartialIndexes(): bool
  {
      return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
  }
  ```
  Copiar de `database/migrations/2026_07_31_101029_create_payment_request_bank_details_table.php` (ou `approvals`).
- Policies: registrar na constante `AppServiceProvider::POLICIES`.
- Guards de exclusão: método no Service + Action Filament capturando `BusinessException` → `Notification::danger($e->getUserMessage())` → `$action->halt()`.
- `Model::preventLazyLoading()` ativo fora de produção → **eager loading obrigatório**.
- Events/Listeners/Actions/Jobs/Integrations: **agrupar por domínio** (`App\Events\PaymentRequest\`, `App\Listeners\PaymentRequest\`, `App\Actions\Import\`, `App\Jobs\PaymentRequest\`, `App\Integrations\Spreadsheet\`). Services/DTOs/Exceptions/Models/Policies: **flat** (`agrupar_por_dominio` em PROJECT.md).
- Comentários de código em **inglês** (mínimos). Documentação deste blueprint em **pt-BR**.
- Ambiente Docker (`PROJECT.md`): prefixar artisan/pest/pint/composer com `docker compose exec app`.

### 0.6 Decisões FECHADAS — NÃO reabrir / NÃO inventar

| # | Decisão |
|---|----------|
| 1 | **Sem** API REST (assunção F1–F4) |
| 2 | **Modo parcial:** linhas válidas criam `PaymentRequest`; inválidas → `ImportBatchError`; `Failed` só em falha estrutural |
| 3 | **Sem** versão draft. Create publica v1. Alterar mappings = `publishVersion()` com novas rows |
| 4 | `ImportTemplateMapping` **SEM SoftDeletes**, hard-owned pela version, **sem** `updated_at` |
| 5 | `ImportTemplateVersion` e `ImportBatchError`: `UPDATED_AT = null` / `$timestamps = false` (espelhar `PaymentRequestStatusHistory`) |
| 6 | Uniques parciais (`name` ativo, um `is_current`) via `supportsPartialIndexes()` + `DB::statement` + `DROP INDEX IF EXISTS` no `down()`. Nunca `->unique()` no Blueprint Laravel quando SoftDeletes |
| 7 | Unique **full** `(import_template_id, version)` e uniques full de mapping. `source_column` nullable: no Postgres NULL é distinto — vários mappings só-default coexistem (documentar no teste) |
| 8 | `import_batches.import_template_version_id` → **`restrictOnDelete`**. `payment_requests.import_batch_id` nullable → **`nullOnDelete`**, index, **sem** `->after()` |
| 9 | **Sem** índice em `is_active`, `sort_order`, `accepted_format`; **sem** GIN em `mappings_snapshot` |
| 10 | OpenSpout já no lock via `filament/actions`; promover a `require` direto **exige aprovação** — passo bloqueante antes do reader; **não** rodar `composer require` sem aprovação |
| 11 | Adm CRUD de template; Operador+Adm importam; Cliente **não** |
| 12 | Boleto no lote → falha na linha (anexo obrigatório F3). Sem criar Supplier on the fly. Duplicata só intra-lote |
| 13 | Cada PR via `PaymentRequestService::create()`; **não** chamar `ApprovalService::route()` de novo no job |
| 14 | Job **sempre** (`ProcessImportBatchJob`). Limites config 5 MB / 500 linhas |
| 15 | Planilha **não** é morph Attachment |
| 16 | Widgets: **nenhum** nesta fase |

### 0.7 Estado real do código a estender (inspecionado 2026-09-26)

| Artefato | Estado |
|----------|--------|
| Models `ImportTemplate`, `ImportBatch`, mappings, erros | **Não existem** |
| Jobs em `app/Jobs/` | **Nenhum** (primeiro job do módulo) |
| Event `PaymentRequestBatchImported` | **Não existe** |
| `openspout/openspout` / `league/csv` | Presentes no vendor (transitivos de `filament/actions`); **não** em `require` direto |
| `PaymentRequestService::create` | Existe; dispara `PaymentRequestCreated`; exige boleto com anexo |
| Listener `RoutePaymentRequestOnCreated` | Existe (sync); usa `Auth` ou fallback `created_by` |
| Painel Filament | Único `admin`; groups: `registrations`, `operations`, `settings`, `reports`, `main` |
| `AppServiceProvider::POLICIES` | Estender com ImportTemplate + ImportBatch |
| Navigation groups | Já em `lang/*/navigation.php` |

---

## 1. Visão Geral

A Fase 5 entrega importação em lote via planilha **sem** reinventar status, policies de visibilidade ou workflow de aprovação:

| Entrega | Artefato |
|---------|----------|
| Template de importação (RF025) | `ImportTemplateResource` (settings) + versão imutável + mappings |
| Importação em lote (RF026) | `ImportBatchResource` (operations) + upload + job |
| Relatório de erros por linha | `ImportBatchError` + RelationManager read-only |
| PRs geradas | via `PaymentRequestService::create` + `import_batch_id` |
| Notificação de conclusão | `PaymentRequestBatchImported` → mail + database |
| Reader de planilha | `OpenSpoutSpreadsheetReader` (após aprovar dep) |

**Fora de escopo (não implementar):** Fases 6–8 (lote de anexos, CNAB, dashboard), API REST, criação de Supplier on the fly, boleto em lote, Widgets, sync sem job, morph Attachment para planilha, versão draft editável.

**Assunções (copiar da arquitetura §20):** A1–A13 fechadas em §0.6.

---

## 2. Commands (ordem de execução)

> Ambiente Docker (`PROJECT.md`): prefixar tudo com `docker compose exec app`.

```bash
# ── 2.0 BLOQUEANTE — dependência OpenSpout ────────────────────────────────────
# NÃO executar sem aprovação explícita do usuário/stakeholder:
#   composer require openspout/openspout:^4.23 --no-interaction
# Até aprovar: implementar Interfaces/DTOs/Services mockáveis; reader concreto fica atrás do gate.

# ── 2.1 Config ────────────────────────────────────────────────────────────────
# Editar config/rjet.php — adicionar chave `imports` (ver §6.8). Sem make.

# ── 2.2 Enums ─────────────────────────────────────────────────────────────────
php artisan make:class Enums/ImportFileFormat --no-interaction
php artisan make:class Enums/ImportBatchStatus --no-interaction
php artisan make:class Enums/ImportTargetField --no-interaction
# Trocar `final class` por `enum …: string implements HasLabel, HasColor, HasIcon`
# (ImportTargetField: HasLabel; HasColor/HasIcon opcionais — se não usar badge colorido, só HasLabel)

# ── 2.3 Migrations (ORDEM OBRIGATÓRIA) ────────────────────────────────────────
php artisan make:migration create_import_templates_table --create=import_templates --no-interaction
php artisan make:migration create_import_template_versions_table --create=import_template_versions --no-interaction
php artisan make:migration create_import_template_mappings_table --create=import_template_mappings --no-interaction
php artisan make:migration create_import_batches_table --create=import_batches --no-interaction
php artisan make:migration create_import_batch_errors_table --create=import_batch_errors --no-interaction
php artisan make:migration add_import_batch_id_to_payment_requests_table --table=payment_requests --no-interaction

# ── 2.4 Models + Factories ────────────────────────────────────────────────────
php artisan make:model ImportTemplate --factory --no-interaction
php artisan make:model ImportTemplateVersion --factory --no-interaction
php artisan make:model ImportTemplateMapping --factory --no-interaction
php artisan make:model ImportBatch --factory --no-interaction
php artisan make:model ImportBatchError --factory --no-interaction
# ESTENDER (não recriar): PaymentRequest (+ relação importBatch + fillable + factory state)

# ── 2.5 Exceptions / DTOs / Services / Integration ────────────────────────────
php artisan make:class Exceptions/ImportException --no-interaction
php artisan make:class DTOs/ImportTemplateData --no-interaction
php artisan make:class DTOs/ImportTemplateMappingData --no-interaction
php artisan make:class DTOs/ImportBatchData --no-interaction
php artisan make:class DTOs/ImportRowData --no-interaction
php artisan make:class Services/ImportTemplateService --no-interaction
php artisan make:class Services/ImportBatchService --no-interaction
php artisan make:class Integrations/Spreadsheet/SpreadsheetReader --no-interaction
# Interface ou abstract; implementação:
php artisan make:class Integrations/Spreadsheet/OpenSpoutSpreadsheetReader --no-interaction
# ESTENDER: PaymentRequestService — NÃO chamar route() de novo; só create já existente

# ── 2.6 Actions (agrupar por domínio Import) ──────────────────────────────────
php artisan make:class Actions/Import/StartImportBatchAction --no-interaction
php artisan make:class Actions/Import/PublishImportTemplateVersionAction --no-interaction
# Opcional MVP: DownloadImportBatchErrorsAction — preferir só table Filament

# ── 2.7 Job / Events / Listeners / Notifications ──────────────────────────────
php artisan make:job PaymentRequest/ProcessImportBatchJob --no-interaction
php artisan make:event PaymentRequest/PaymentRequestBatchImported --no-interaction
php artisan make:listener PaymentRequest/LogPaymentRequestBatchImported --no-interaction
php artisan make:listener PaymentRequest/NotifyImportBatchCompleted --no-interaction
php artisan make:notification PaymentRequestBatchImportedNotification --no-interaction
# Registrar Event→Listeners em AppServiceProvider (padrão F3/F4)

# ── 2.8 Observer (forceDelete batch → apagar arquivo) ─────────────────────────
php artisan make:observer ImportBatchObserver --model=ImportBatch --no-interaction
# Registrar em AppServiceProvider

# ── 2.9 Policies ──────────────────────────────────────────────────────────────
php artisan make:policy ImportTemplatePolicy --model=ImportTemplate --no-interaction
php artisan make:policy ImportBatchPolicy --model=ImportBatch --no-interaction
# Registrar em AppServiceProvider::POLICIES

# ── 2.10 Filament Resources + Relation Managers ───────────────────────────────
php artisan make:filament-resource ImportTemplate --generate --soft-deletes --view --panel=admin --no-interaction
php artisan make:filament-resource ImportBatch --generate --soft-deletes --view --panel=admin --no-interaction
# Remover página Edit do ImportBatch após scaffold (ou não registrar em getPages)
php artisan make:filament-relation-manager ImportTemplateResource versions version --generate --panel=admin --no-interaction
# Versions: SEM --soft-deletes no RM se preferir filtrar só non-trashed; model TEM SoftDeletes — usar --soft-deletes no RM se precisar TrashedFilter (histórico Adm). Preferência: read-only sem create/edit; TrashedFilter opcional.
php artisan make:filament-relation-manager ImportBatchResource errors row_number --generate --panel=admin --no-interaction
# Errors: SEM --soft-deletes (ImportBatchError não tem SoftDeletes)
php artisan make:filament-relation-manager ImportBatchResource paymentRequests id --generate --panel=admin --no-interaction
# Depois: limpar Resources, mover Form/Table/Infolist, criar Actions Filament em Actions/

# ── 2.11 Seeder ───────────────────────────────────────────────────────────────
php artisan make:seeder ImportTemplateSeeder --no-interaction

# ── 2.12 Testes ───────────────────────────────────────────────────────────────
php artisan make:test --pest --unit ImportBatchStatusTest --no-interaction
php artisan make:test --pest --unit ImportTargetFieldTest --no-interaction
php artisan make:test --pest --unit ImportRowParsingTest --no-interaction
php artisan make:test --pest ImportTemplateServiceTest --no-interaction
php artisan make:test --pest ImportBatchServiceTest --no-interaction
php artisan make:test --pest ImportTemplateResourceTest --no-interaction
php artisan make:test --pest ImportBatchResourceTest --no-interaction
php artisan make:test --pest ImportBatchAuthorizationTest --no-interaction
php artisan make:test --pest ImportSchemaConstraintsTest --no-interaction
php artisan make:test --pest ProcessImportBatchJobTest --no-interaction

# ── 2.13 Qualidade ────────────────────────────────────────────────────────────
php artisan migrate
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=Import
```

**Models avulsos sem SoftDeletes:** NÃO passar `--soft-deletes` se forem gerados como resources avulsos. Mapping / Error / Version **não** têm Filament Resource próprio — só via RelationManagers. Resources de Template e Batch **sim** usam `--soft-deletes`.

---

## 3. Models e Migrations

> Schema **DBA-aprovado** (arquitetura §21 / §24). Sem `after()`. Sem índices em `is_active` / `sort_order` / `accepted_format` / GIN JSON. Sem `$table->enum()`. Monetários **não** nas tabelas de import.

### 3.1 Ordem de migrations

1. `create_import_templates_table`
2. `create_import_template_versions_table` (+ unique full version + unique parcial `is_current`)
3. `create_import_template_mappings_table` (+ uniques full target / source_column)
4. `create_import_batches_table`
5. `create_import_batch_errors_table`
6. `add_import_batch_id_to_payment_requests_table`

### 3.2 Model `ImportTemplate` + migration

```
Migration: create_import_templates_table
Model: App\Models\ImportTemplate
Traits: HasFactory, HasUuid, HasBlameable, SoftDeletes
SoftDeletes: SIM
```

```php
Schema::create('import_templates', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('name', 120); // unique parcial abaixo — sem ->unique()
    $table->foreignUuid('company_id')->nullable()->index()->constrained('companies')->nullOnDelete();
    $table->foreignUuid('branch_id')->nullable()->index()->constrained('branches')->nullOnDelete();
    $table->string('accepted_format', 10); // ImportFileFormat — sem índice
    $table->boolean('is_active')->default(true); // sem índice (baixa cardinalidade)
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});

if ($this->supportsPartialIndexes()) {
    DB::statement(
        'CREATE UNIQUE INDEX import_templates_name_unique ON import_templates (name) WHERE deleted_at IS NULL'
    );
}
// down:
// if ($this->supportsPartialIndexes()) {
//     DB::statement('DROP INDEX IF EXISTS import_templates_name_unique');
// }
// Schema::dropIfExists('import_templates');
```

**Casts:** `accepted_format` → `ImportFileFormat::class`; `is_active` → `boolean`  
**Fillable:** `name`, `company_id`, `branch_id`, `accepted_format`, `is_active`  
**Relations:**
- `company(): BelongsTo`
- `branch(): BelongsTo`
- `versions(): HasMany ImportTemplateVersion`
- `currentVersion(): HasOne` — `versions()->where('is_current', true)->whereNull('deleted_at')`

**Scopes:**
- `scopeActive(Builder): where is_active true`
- `scopeVisibleTo(User)` — Adm vê todos; Operador **não** CRUD (só select de ativos no import via query no Form)

### 3.3 Model `ImportTemplateVersion` + migration

```
Migration: create_import_template_versions_table
Model: App\Models\ImportTemplateVersion
Traits: HasFactory, HasUuid, SoftDeletes — SEM HasBlameable full (só created_by)
SoftDeletes: SIM (cautela — ver §14)
OBRIGATÓRIO:
  public $timestamps = false;
  public const UPDATED_AT = null;
  // Preencher created_at / published_at no Service
```

```php
Schema::create('import_template_versions', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('import_template_id')->index()->constrained('import_templates')->cascadeOnDelete();
    $table->unsignedInteger('version');
    $table->boolean('is_current')->default(false);
    $table->timestampTz('published_at');
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampTz('created_at')->useCurrent(); // sem updated_at
    $table->softDeletesTz();

    // Full unique: version number never reused (incl. soft-deleted rows)
    $table->unique(['import_template_id', 'version']);
});

if ($this->supportsPartialIndexes()) {
    DB::statement(
        'CREATE UNIQUE INDEX import_template_versions_current_unique
         ON import_template_versions (import_template_id)
         WHERE is_current = true AND deleted_at IS NULL'
    );
}
// down: DROP INDEX IF EXISTS import_template_versions_current_unique;
```

**Casts:** `is_current` → `boolean`; `published_at`, `created_at` → `datetime`  
**Fillable:** `import_template_id`, `version`, `is_current`, `published_at`, `created_by`, `created_at`  
**Relations:**
- `template(): BelongsTo ImportTemplate`
- `mappings(): HasMany ImportTemplateMapping` (orderBy sort_order)
- `batches(): HasMany ImportBatch`

**Guards Service:** soft-delete só se `batches()->doesntExist()`; senão `ImportException::versionInUse()`.

### 3.4 Model `ImportTemplateMapping` + migration

```
Migration: create_import_template_mappings_table
Model: App\Models\ImportTemplateMapping
Traits: HasFactory, HasUuid — SEM SoftDeletes, SEM HasBlameable
OBRIGATÓRIO:
  public $timestamps = false;
  public const UPDATED_AT = null;
```

```php
Schema::create('import_template_mappings', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('import_template_version_id')->index()
        ->constrained('import_template_versions')->cascadeOnDelete();
    $table->string('source_column', 120)->nullable(); // null = default-only mapping
    $table->string('target_field', 60); // ImportTargetField
    $table->string('default_value', 255)->nullable();
    $table->unsignedInteger('sort_order')->default(0); // sem índice
    $table->timestampTz('created_at')->useCurrent(); // sem updated_at; sem softDeletes

    $table->unique(['import_template_version_id', 'target_field']);
    // Postgres: NULL is distinct — multiple (version_id, NULL) source_column rows allowed
    $table->unique(['import_template_version_id', 'source_column']);
});
```

**Casts:** `target_field` → `ImportTargetField::class`; `created_at` → `datetime`  
**Fillable:** `import_template_version_id`, `source_column`, `target_field`, `default_value`, `sort_order`, `created_at`  
**Relations:** `version(): BelongsTo ImportTemplateVersion`

> **Teste obrigatório:** inserir ≥2 mappings na mesma version com `source_column = null` e targets diferentes → OK no Postgres/SQLite.

### 3.5 Model `ImportBatch` + migration

```
Migration: create_import_batches_table
Model: App\Models\ImportBatch
Traits: HasFactory, HasUuid, HasBlameable, SoftDeletes
SoftDeletes: SIM
```

```php
Schema::create('import_batches', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('import_template_version_id')->index()
        ->constrained('import_template_versions')->restrictOnDelete();
    $table->string('status', 20)->default('pending')->index(); // ImportBatchStatus
    $table->string('disk', 50);
    $table->string('path', 500);
    $table->string('original_filename', 255);
    $table->string('mime_type', 100)->nullable();
    $table->unsignedInteger('size');
    // Denormalized: snapshot of mappings at upload time (audit); no GIN index in MVP
    $table->json('mappings_snapshot');
    // Denormalized counters for UI — updated by ImportBatchService::process
    $table->unsignedInteger('total_rows')->default(0);
    $table->unsignedInteger('success_count')->default(0);
    $table->unsignedInteger('error_count')->default(0);
    $table->text('failure_reason')->nullable();
    $table->timestampTz('started_at')->nullable();
    $table->timestampTz('finished_at')->nullable();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();

    $table->index(['created_by', 'created_at']);
});
```

**Casts:** `status` → `ImportBatchStatus::class`; `mappings_snapshot` → `array`; `started_at`, `finished_at` → `datetime`  
**Fillable:** todos os campos de domínio listados acima (exceto id)  
**Relations:**
- `templateVersion(): BelongsTo ImportTemplateVersion`
- `errors(): HasMany ImportBatchError`
- `paymentRequests(): HasMany PaymentRequest`
- `creator(): BelongsTo User` (created_by)

**Scopes:** `scopeVisibleTo(User)` — Operador/Adm veem todos; Cliente **não** acessa Resource (Policy nega).

**Helpers:** `isTerminal(): bool` — Completed | Failed; `isPending(): bool`; `isProcessing(): bool`.

### 3.6 Model `ImportBatchError` + migration

```
Migration: create_import_batch_errors_table
Model: App\Models\ImportBatchError
Traits: HasFactory, HasUuid — SEM SoftDeletes
OBRIGATÓRIO:
  public $timestamps = false;
  public const UPDATED_AT = null;
  // Append-only — set created_at no create
```

```php
Schema::create('import_batch_errors', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('import_batch_id')->index()
        ->constrained('import_batches')->cascadeOnDelete(); // hard only
    $table->unsignedInteger('row_number');
    $table->string('target_field', 60)->nullable();
    $table->string('message', 500);
    $table->json('raw_values')->nullable();
    $table->timestampTz('created_at')->useCurrent();

    $table->index(['import_batch_id', 'row_number']);
});
```

**Casts:** `raw_values` → `array`; `created_at` → `datetime`; `target_field` → `ImportTargetField::class` nullable (ou string se preferir message-only)  
**Fillable:** `import_batch_id`, `row_number`, `target_field`, `message`, `raw_values`, `created_at`  
**Relations:** `batch(): BelongsTo ImportBatch`

### 3.7 Extensão `PaymentRequest`

```
Migration: add_import_batch_id_to_payment_requests_table
```

```php
Schema::table('payment_requests', function (Blueprint $table): void {
    // No after() — Postgres ignores column order (F4 DBA)
    $table->foreignUuid('import_batch_id')->nullable()->index()
        ->constrained('import_batches')->nullOnDelete();
});
```

**Model `PaymentRequest` — alterações:**
- `$fillable`: adicionar `import_batch_id`
- Relação `importBatch(): BelongsTo` (nullable)
- Factory state `fromImportBatch(ImportBatch $batch)`
- Soft-delete da PR **não** remove/altera o batch
- Soft-delete do batch **mantém** `import_batch_id` na PR
- ForceDelete do batch → FK zera (`nullOnDelete`)

**Conflitos F3/F4:** coluna nova apenas; **não** mexe em `status`, bank_details, approvals, attachments, history.

### 3.8 Matriz FK (forceDelete)

| FK | On delete | Motivo |
|----|-----------|--------|
| templates.company_id / branch_id | `nullOnDelete` | Alvo opcional |
| versions.import_template_id | `cascadeOnDelete` | Filho hard-owned; soft template **não** cascata |
| mappings.import_template_version_id | `cascadeOnDelete` | Hard-owned |
| batches.import_template_version_id | `restrictOnDelete` | Auditoria — version não some com lotes |
| errors.import_batch_id | `cascadeOnDelete` | Hard-owned append-only |
| payment_requests.import_batch_id | `nullOnDelete` | PR sobrevive ao force do lote |
| `*_by` → users | `nullOnDelete` | Padrão blameable |

### 3.9 Helper `supportsPartialIndexes` (copiar em cada migration que precisar)

```php
private function supportsPartialIndexes(): bool
{
    return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
}
```

---

## 4. Enums

Todos: `string` no banco; PHP backed enum; Filament `HasLabel` (+ `HasColor` / `HasIcon` onde badge); labels via `__('enums.*')`.

Docs: https://filamentphp.com/docs/5.x/support/enums

### 4.1 `ImportFileFormat`

```
Namespace: App\Enums\ImportFileFormat
Backed: string
Implements: HasLabel, HasColor, HasIcon (opcional HasIcon)
```

| Case | Value | Label (pt) |
|------|-------|------------|
| Csv | `csv` | CSV |
| Xlsx | `xlsx` | Excel (XLSX) |

```php
public function getLabel(): ?string
{
    return __('enums.import_file_format.'.$this->value);
}
```

### 4.2 `ImportBatchStatus`

```
Namespace: App\Enums\ImportBatchStatus
Backed: string
Implements: HasLabel, HasColor, HasIcon
```

| Case | Value | Cor | Transições |
|------|-------|-----|------------|
| Pending | `pending` | gray | → Processing, Failed |
| Processing | `processing` | info | → Completed, Failed |
| Completed | `completed` | success | — (terminal) |
| Failed | `failed` | danger | — (terminal) |

**Método obrigatório:**

```php
public function canTransitionTo(self $to): bool
{
    return match ($this) {
        self::Pending => in_array($to, [self::Processing, self::Failed], true),
        self::Processing => in_array($to, [self::Completed, self::Failed], true),
        self::Completed, self::Failed => false,
    };
}

public function isTerminal(): bool
{
    return in_array($this, [self::Completed, self::Failed], true);
}
```

### 4.3 `ImportTargetField`

```
Namespace: App\Enums\ImportTargetField
Backed: string
Implements: HasLabel
```

**Cabeçalho PR:**

| Case | Value |
|------|-------|
| BranchDocument | `branch_document` |
| SupplierDocument | `supplier_document` |
| CostCenterCode | `cost_center_code` |
| AppropriationCode | `appropriation_code` |
| PaymentMethod | `payment_method` |
| GrossAmount | `gross_amount` |
| DiscountAmount | `discount_amount` |
| DueDate | `due_date` |
| Notes | `notes` |

**Bank / depósito:**

| Case | Value |
|------|-------|
| DepositType | `deposit_type` |
| DigitableLine | `digitable_line` |
| PixKeyType | `pix_key_type` |
| PixKey | `pix_key` |
| PixQrCode | `pix_qr_code` |
| HolderName | `holder_name` |
| HolderDocument | `holder_document` |
| BankCode | `bank_code` |
| Agency | `agency` |
| AgencyDigit | `agency_digit` |
| AccountNumber | `account_number` |
| AccountDigit | `account_digit` |
| AccountType | `account_type` |

**Validação de publish (Service):** exige mapping **ou** default para: `supplier_document`, `cost_center_code`, `gross_amount`, `due_date`, e (`branch_document` **ou** `ImportTemplate.branch_id`). `payment_method` mapping/default **ou** fallback supplier. `appropriation_code` se company alvo exige (se template sem company, validação na **linha**).

Helper estático sugerido: `ImportTargetField::requiredForPublish(?string $branchId, ?string $companyId): array`.

---

## 5. Exceções de Domínio

```
Namespace: App\Exceptions\ImportException
Extends: BusinessException
Padrão: mensagem interna EN + userMessage via __()
Espelhar: PaymentRequestException
```

| Method | Uso | Chave i18n (sugestão) |
|--------|-----|------------------------|
| `templateInactive()` | Template soft-deleted / `is_active=false` | `import_templates.errors.inactive` |
| `templateMissingRequiredMappings()` | Publish/import sem campos obrigatórios | `import_templates.errors.missing_required_mappings` |
| `incompatibleFormat()` | Extensão/MIME ≠ `accepted_format` | `import_batches.errors.incompatible_format` |
| `unreadableSpreadsheet()` | OpenSpout não lê | `import_batches.errors.unreadable` |
| `rowLimitExceeded(int $max)` | > max_rows | `import_batches.errors.row_limit_exceeded` |
| `emptySpreadsheet()` | Sem linhas de dados | `import_batches.errors.empty` |
| `batchNotPending()` | Reprocessamento inválido | `import_batches.errors.not_pending` |
| `unauthorizedImport()` | Cliente / sem policy | `import_batches.errors.unauthorized` |
| `branchNotAllowed(string $id)` | RN026.3 (ou reusa `PaymentRequestException`) | — |
| `supplierNotFound(string $document)` | Documento ok sem cadastro | `import_batches.errors.supplier_not_found` |
| `invalidDocument(string $document)` | CPF/CNPJ inválido | `import_batches.errors.invalid_document` |
| `costCenterNotFound(string $code)` | | `import_batches.errors.cost_center_not_found` |
| `appropriationRequired()` / `appropriationNotFound()` | | `import_batches.errors.appropriation_*` |
| `boletoNotSupportedInBatch()` | | `import_batches.errors.boleto_not_supported` |
| `duplicateRowInBatch(int $row)` | Duplicata intra-lote | `import_batches.errors.duplicate_row` |
| `versionImmutable()` | Editar mappings de versão published | `import_templates.errors.version_immutable` |
| `versionInUse()` | Soft-delete version com batches | `import_templates.errors.version_in_use` |

**Regra:** erros **por linha** preferem gravar `ImportBatchError` (capturar `PaymentRequestException` / `ValidationException` / `ImportException` recuperável) em vez de estourar. Exceptions estruturais → `markFailed` + status `Failed`.

---

## 6. Camadas de Aplicação

> Namespaces conforme `agrupar_por_dominio` em PROJECT.md: Jobs/Events/Actions/Listeners/Integrations **agrupados**; Services/DTOs/Models **flat**.

### 6.1 DTOs (flat `App\DTOs`)

| DTO | Campos principais | Uso |
|-----|-------------------|-----|
| `ImportTemplateData` | name, companyId?, branchId?, acceptedFormat, isActive, mappings: list\<ImportTemplateMappingData\> | create |
| `ImportTemplateMappingData` | sourceColumn?, targetField, defaultValue?, sortOrder | publish / create |
| `ImportBatchData` | importTemplateVersionId, disk, path, originalFilename, mimeType, size, mappingsSnapshot | pós-upload |
| `ImportRowData` | rowNumber, cells (assoc), resolved defaults | alimenta `PaymentRequestData` |

Reutilizar `PaymentRequestData` / `PaymentRequestBankDetailsData` — **não** duplicar.

Cada DTO: `final`, `fromArray`, `toArray` (padrão F3/F4).

### 6.2 `ImportTemplateService` (final, flat `App\Services`)

| Método | Steps |
|--------|-------|
| `create(ImportTemplateData, User $actor): ImportTemplate` | assert required mappings; transaction: create template + version v1 `is_current=true` + insert mappings; return fresh |
| `updateMetadata(ImportTemplate, ImportTemplateData, User): ImportTemplate` | Update nome/company/branch/format/is_active — **não** bumpa versão |
| `publishVersion(ImportTemplate, array $mappings, User): ImportTemplateVersion` | assert required; transaction: current `is_current=false`; insert vN+1 + mappings (**nunca** update in-place); return |
| `delete(ImportTemplate): void` | soft delete template apenas — **não** cascade soft versions |
| `softDeleteVersion(ImportTemplateVersion): void` | se `batches()->exists()` → `versionInUse()`; senão soft |
| `assertRequiredMappings(array $mappings, ImportTemplate $template): void` | §4.3 regras |

### 6.3 `ImportBatchService` (final, flat `App\Services`)

| Método | Steps |
|--------|-------|
| `start(UploadedFile $file, ImportTemplateVersion $version, User $actor): ImportBatch` | Policy já no Action; validar template active + format MIME/ext vs `accepted_format`; store file path `imports/{Y}/{m}/{batch_uuid}/{hash}.{ext}` disk private; snapshot mappings JSON; status Pending; dispatch `ProcessImportBatchJob`; return |
| `process(ImportBatch $batch): void` | Se Completed/Failed → no-op; se Processing reentrante com PRs → Failed “unsafe”; set Processing + started_at; OpenSpout generator; header = row 1; loop data rows; por linha: resolve → create PR ou error; atualizar contadores; Completed + finished_at; dispatch `PaymentRequestBatchImported` (**mesmo se success_count=0**); **não** dispara em Failed |
| `markFailed(ImportBatch, string $reason): void` | status Failed; failure_reason; finished_at |
| `resolveRow(...): PaymentRequestData` | Ver §6.4 |
| `recordError(ImportBatch, int $row, ?ImportTargetField, string $message, ?array $raw): void` | insert ImportBatchError |

**Regra crítica:** `process` **só** cria PR via `PaymentRequestService::create(...)` — nunca `PaymentRequest::create` direto. **Não** chamar `ApprovalService::route()`.

**Transação:** cada linha válida = **uma** transação (já no create). Não envolver o lote inteiro em uma DB transaction.

### 6.4 Resolução de linha (dentro do Service)

1. Normalizar documento (só dígitos) → `ValidCpf`/`ValidCnpj` → `Supplier::where('document', $digits)->active()`.
2. Filial: se template tem `branch_id`, usar (validar linha não contradiz); senão resolver por CNPJ da branch ativa; depois `PaymentRequestService::resolveBranchFor($actor, $branchId)`.
3. Centro de custo: `CostCenter` ativo com `code` + `branch_id`.
4. Apropriação: `Appropriation` ativo com `code` + `company_id` da branch.
5. Monetários: parse `1.234,56` / `1234.56` → string decimal; `assertAmounts`.
6. Bank: montar `PaymentRequestBankDetailsData` + asserts F3.
7. **Boleto:** se `payment_method = boleto` → erro linha `boletoNotSupportedInBatch` — **não** relaxar `assertBoletoHasAttachment`.
8. **Duplicata intra-lote:** chave `supplier_document + gross_amount + due_date + branch_id` — 2ª ocorrência → erro; **não** consulta histórico global.
9. Sem criar Supplier on the fly.

Actor do create = `created_by` do batch (importer). Job **não** faz `Auth::login`.

### 6.5 Actions de domínio (`App\Actions\Import\`)

| Action | Delega para | Uso |
|--------|-------------|-----|
| `StartImportBatchAction` | `ImportBatchService::start` | Create page Filament |
| `PublishImportTemplateVersionAction` | `ImportTemplateService::publishVersion` | Filament Action + Service |

Todas `final`, `__invoke(...)`, tipadas. Policy check no início (ou confiar no Filament Policy + double-check no Service).

### 6.6 Job `ProcessImportBatchJob`

```
Namespace: App\Jobs\PaymentRequest\ProcessImportBatchJob
Implements: ShouldQueue
Traits: Queueable, SerializesModels (padrão Laravel)
```

| Prop | Valor |
|------|-------|
| `$tries` | 3 |
| `$timeout` | 120 |
| Middleware | `WithoutOverlapping((string) $this->batch->id)` (ou id no constructor) |
| Queue | `default` (Redis) |

**`handle(ImportBatchService $service): void`:** `$service->process($this->batch)`.

**`failed(?Throwable $e): void`:** `markFailed` + log.

**Idempotência:** Completed/Failed → no-op; Processing com PRs existentes → Failed “reprocessamento inseguro” (MVP: overlapping + não reprocessar Completed).

Docs: https://laravel.com/docs/12.x/queues

### 6.7 Integration Spreadsheet

```
Namespace: App\Integrations\Spreadsheet\
  SpreadsheetReader (interface)
    rows(string $absolutePath): Generator  // yield ['row' => int, 'values' => array assoc by header]
  OpenSpoutSpreadsheetReader implements SpreadsheetReader
```

Bind interface → implementação no `AppServiceProvider` **após** dep aprovada.

Header: **primeira linha = nomes de colunas**; `source_column` = nome do header (trim, case-sensitive conforme template).

### 6.8 Config `config/rjet.php`

```php
'imports' => [
    'disk' => env('RJET_IMPORTS_DISK', env('FILESYSTEM_DISK', 'local')),
    'directory' => 'imports',
    'max_kilobytes' => (int) env('RJET_IMPORTS_MAX_KILOBYTES', 5120), // 5 MB
    'max_rows' => (int) env('RJET_IMPORTS_MAX_ROWS', 500),
    'accepted_extensions' => ['csv', 'xlsx'],
],
```

Path arquivo: `imports/{Y}/{m}/{batch_uuid}/{hash}.{ext}` — **private**. Download só via Action autenticada.

### 6.9 Observer `ImportBatchObserver`

- `forceDeleted`: apagar arquivo do disk (`Storage::disk($batch->disk)->delete($batch->path)`).
- Soft delete: **não** apaga arquivo na F5.

---

## 7. Filament Resource: ImportTemplate

### 7.1 Scaffold

```
Resource: ImportTemplateResource
  Command: php artisan make:filament-resource ImportTemplate --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\ImportTemplates\ImportTemplateResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview
  SoftDeletes: getRecordRouteBindingEloquentQuery() sem SoftDeletingScope
  Icon: Heroicon::OutlinedTableCells
  Navigation:
    Group: __('navigation.groups.settings')
    Sort: 40
    Label: __('import_templates.navigation_label') / getModelLabel / getPluralModelLabel
  Visibility: canViewAny → só Adm (Policy)
  recordTitleAttribute: name
```

**Eager load:** `company`, `branch`, `currentVersion` (e `versions` no View).

**Estrutura de pastas:** §0.2 — Resource limpo, classes `final`.

### 7.2 Form — `Schemas/ImportTemplateForm.php`

```
Schema columns(1)
Docs: https://filamentphp.com/docs/5.x/schemas/overview

Section: __('import_templates.sections.general')
  Component: Filament\Schemas\Components\Section
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Config: ->columns(2)

  Field: name
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required, max:120
    Config:
      ->label(__('import_templates.fields.name'))
      ->required()
      ->maxLength(120)

  Field: company_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: nullable, exists:companies,id
    Config:
      ->label(__('import_templates.fields.company_id'))
      ->relationship('company', 'name')
      ->searchable()
      ->preload()
      ->nullable()
      ->live()
      ->afterStateUpdated(fn (Set $set): mixed => $set('branch_id', null))
    Imports:
      - Filament\Schemas\Components\Utilities\Get
      - Filament\Schemas\Components\Utilities\Set
    Reactive: when changed, reset branch_id to null

  Field: branch_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: nullable, exists:branches,id
    Config:
      ->label(__('import_templates.fields.branch_id'))
      ->options(fn (Get $get): array => Branch::query()
          ->when($get('company_id'), fn ($q, $id) => $q->where('company_id', $id))
          ->orderBy('name')
          ->pluck('name', 'id')
          ->all())
      ->searchable()
      ->preload()
      ->nullable()
      ->helperText(__('import_templates.hints.branch_default'))
    Imports: Get, Branch
    Reactive: options depend on company_id field

  Field: accepted_format
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required
    Config:
      ->label(__('import_templates.fields.accepted_format'))
      ->options(ImportFileFormat::class)
      ->required()
      ->native(false)

  Field: is_active
    Component: Filament\Forms\Components\Toggle
    Docs: https://filamentphp.com/docs/5.x/forms/toggle
    Validation: boolean
    Config:
      ->label(__('common.fields.is_active'))
      ->default(true)

Section: __('import_templates.sections.mappings')  — VISÍVEL SÓ NO CREATE
  Config: ->columns(1)->visibleOn('create')  // ou Pages\CreateImportTemplate only

  Field: mappings
    Component: Filament\Forms\Components\Repeater
    Docs: https://filamentphp.com/docs/5.x/forms/repeater
    Validation: required, min:1; cada item: target_field required; source_column nullable; default_value nullable
    Config:
      ->label(__('import_templates.fields.mappings'))
      ->schema([
          TextInput::make('source_column')
            ->label(__('import_templates.fields.source_column'))
            ->maxLength(120)
            ->nullable(),
          Select::make('target_field')
            ->label(__('import_templates.fields.target_field'))
            ->options(ImportTargetField::class)
            ->required()
            ->native(false),
          TextInput::make('default_value')
            ->label(__('import_templates.fields.default_value'))
            ->maxLength(255)
            ->nullable(),
      ])
      ->orderColumn('sort_order')  // se suportado; senão índice do repeater
      ->minItems(1)
      ->columnSpanFull()
      ->addActionLabel(__('import_templates.actions.add_mapping'))
```

**Edit:** **sem** Repeater de mappings — alterar mappings só via `PublishImportTemplateVersionAction`. Metadados editáveis no Edit.

**Persistência Create:** Page chama `ImportTemplateService::create` (não save direto solto).

### 7.3 Infolist — `Schemas/ImportTemplateInfolist.php`

```
Section: __('import_templates.sections.general')
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Config: ->columns(2)

  Entry: name
    Component: Filament\Infolists\Components\TextEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/text-entry

  Entry: company.name
    Component: TextEntry
    Config: ->placeholder('—')

  Entry: branch.name
    Component: TextEntry
    Config: ->placeholder('—')

  Entry: accepted_format
    Component: TextEntry
    Config: ->badge()

  Entry: is_active
    Component: Filament\Infolists\Components\IconEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/icon-entry
    Config: ->boolean()

  Entry: currentVersion.version
    Component: TextEntry
    Config: ->label(__('import_templates.fields.current_version'))

  Entry: created_at / updated_at
    Component: TextEntry
    Config: ->dateTime('d/m/Y H:i')
```

### 7.4 Table — `Tables/ImportTemplatesTable.php`

```
Table Docs: https://filamentphp.com/docs/5.x/tables/overview

Column: name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->searchable()->sortable()

Column: company.name
  Component: TextColumn
  Config: ->toggleable()->placeholder('—')

Column: branch.name
  Component: TextColumn
  Config: ->toggleable()->placeholder('—')

Column: accepted_format
  Component: TextColumn
  Config: ->badge()

Column: currentVersion.version
  Component: TextColumn
  Config: ->label(__('import_templates.fields.current_version'))

Column: is_active
  Component: Filament\Tables\Columns\IconColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/icon
  Config: ->boolean()

Column: created_at
  Component: TextColumn
  Config: ->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true)

Filter: is_active
  Component: Filament\Tables\Filters\TernaryFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary
  Config: ->label(__('common.fields.is_active'))

Filter: trashed
  Component: Filament\Tables\Filters\TrashedFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/trashed

recordActions:
  Action: ViewAction
    Component: Filament\Actions\ViewAction
    Docs: https://filamentphp.com/docs/5.x/actions/view
  Action: EditAction
    Component: Filament\Actions\EditAction
    Docs: https://filamentphp.com/docs/5.x/actions/edit
  Action: PublishImportTemplateVersionAction
    Component: Filament\Actions\Action
    Docs: https://filamentphp.com/docs/5.x/actions/overview
    Location: table row + View header
    Visibility: Adm + template not trashed
    Authorization: update Policy
    Behavior:
      1. Modal com Repeater de mappings (mesmo schema do create)
      2. Chamar PublishImportTemplateVersionAction / Service::publishVersion
      3. Notification success
      4. Catch BusinessException → danger + halt
  Action: DeleteAction
    Component: Filament\Actions\DeleteAction
    Behavior: Service soft delete; catch BusinessException → danger + halt

toolbarActions:
  BulkActionGroup → DeleteBulkAction, ForceDeleteBulkAction, RestoreBulkAction
  Docs: https://filamentphp.com/docs/5.x/actions/overview

defaultSort: name asc
```

### 7.5 RelationManager — Versions (read-only histórico)

```
Command: php artisan make:filament-relation-manager ImportTemplateResource versions version --generate --panel=admin --no-interaction
Location: App\Filament\Resources\ImportTemplates\RelationManagers\ImportTemplateVersionsRelationManager
Docs: https://filamentphp.com/docs/5.x/resources/managing-relationships

Relationship: versions
Read-only: headerActions [] ; recordActions [] (ou só ViewMappings modal se útil)
  SEM Create/Edit/Delete na UI (publish é Action no parent)

Columns:
  version → TextColumn sortable
  is_current → IconColumn boolean
  published_at → TextColumn dateTime
  created_by / creator.name → TextColumn
  mappings_count → TextColumn counts('mappings') opcional

Infolist/modal opcional: listar mappings (source_column, target_field, default_value) read-only
```

### 7.6 Pages

- `ListImportTemplates` — CreateAction header
- `CreateImportTemplate` — Service create + mappings
- `EditImportTemplate` — só metadados
- `ViewImportTemplate` — header: Edit, Publish, Delete

### 7.7 Authorization no Resource

```php
public static function canViewAny(): bool
{
    return Filament::auth()->user()?->can('viewAny', ImportTemplate::class) ?? false;
}
```

---

## 8. Filament Resource: ImportBatch

### 8.1 Scaffold

```
Resource: ImportBatchResource
  Command: php artisan make:filament-resource ImportBatch --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\ImportBatches\ImportBatchResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview
  SoftDeletes: getRecordRouteBindingEloquentQuery()
  Icon: Heroicon::OutlinedArrowUpTray
  Navigation:
    Group: __('navigation.groups.operations')
    Sort: 15
    Label: __('import_batches.navigation_label')
  Visibility: Operador + Adm (não Cliente)
  Pages: List, Create, View — SEM Edit (remover de getPages após scaffold)
```

**Eager load List/View:** `templateVersion.template`, `creator`; View também errors paginado via RM.

### 8.2 Form — `Schemas/ImportBatchForm.php` (Create = upload)

```
Schema columns(1)

Section: __('import_batches.sections.upload')
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Config: ->columns(2)

  Field: import_template_version_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required, exists:import_template_versions,id
    Config:
      ->label(__('import_batches.fields.import_template_version_id'))
      ->options(fn (): array => ImportTemplateVersion::query()
          ->where('is_current', true)
          ->whereNull('deleted_at')
          ->whereHas('template', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
          ->with('template')
          ->get()
          ->mapWithKeys(fn ($v) => [
              $v->id => sprintf('%s (v%d) · %s', $v->template->name, $v->version, $v->template->accepted_format->getLabel()),
          ])
          ->all())
      ->searchable()
      ->preload()
      ->required()
      ->native(false)

  Field: spreadsheet
    Component: Filament\Forms\Components\FileUpload
    Docs: https://filamentphp.com/docs/5.x/forms/file-upload
    Validation: required, extensions:csv,xlsx, maxSize:config('rjet.imports.max_kilobytes')
    Config:
      ->label(__('import_batches.fields.spreadsheet'))
      ->acceptedFileTypes([
          'text/csv',
          'text/plain',
          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'application/vnd.ms-excel',
      ])
      ->maxSize(config('rjet.imports.max_kilobytes'))
      ->storeFiles(false)  // Service persiste após submit
      ->required()
      ->columnSpanFull()
```

**Create page:** após submit → `StartImportBatchAction` → redirect `ViewImportBatch` com polling até status terminal.

**Polling (View):** `wire:poll.5s` condicional enquanto `!$record->status->isTerminal()`, ou botão “Atualizar”. Preferir poll leve no Infolist page.

### 8.3 Infolist — `Schemas/ImportBatchInfolist.php`

```
Section: __('import_batches.sections.status')
  Config: ->columns(2)

  Entry: status
    Component: Filament\Infolists\Components\TextEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/text-entry
    Config: ->badge()

  Entry: templateVersion.template.name (+ version)
    Component: TextEntry
    Config: ->formatStateUsing / getStateUsing

  Entry: original_filename
    Component: TextEntry

  Entry: total_rows / success_count / error_count
    Component: TextEntry
    Config: labels via __('import_batches.fields.*')

  Entry: failure_reason
    Component: TextEntry
    Config: ->visible(fn (ImportBatch $r): bool => $r->status === ImportBatchStatus::Failed)

  Entry: started_at / finished_at
    Component: TextEntry
    Config: ->dateTime('d/m/Y H:i')

  Entry: creator.name
    Component: TextEntry
    Config: ->label(__('common.fields.created_by'))
```

### 8.4 Table — `Tables/ImportBatchesTable.php`

```
Column: created_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->dateTime('d/m/Y H:i')->sortable()

Column: status
  Component: TextColumn
  Config: ->badge()->sortable()

Column: templateVersion.template.name
  Component: TextColumn
  Config: ->label(__('import_batches.fields.template'))

Column: success_count
  Component: TextColumn
  Config: ->sortable()

Column: error_count
  Component: TextColumn
  Config: ->sortable()

Column: creator.name
  Component: TextColumn
  Config: ->label(__('common.fields.created_by'))->toggleable()

Filter: status
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->options(ImportBatchStatus::class)

Filter: created_at
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Config: DatePicker from/until (padrão F3)

Filter: trashed
  Component: Filament\Tables\Filters\TrashedFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/trashed
  Config: visible só Adm

recordActions:
  Action: ViewAction
    Component: Filament\Actions\ViewAction
  Action: DownloadImportSpreadsheetAction
    Component: Filament\Actions\Action
    Docs: https://filamentphp.com/docs/5.x/actions/overview
    Location: table row + View header
    Visibility: Operador/Adm + arquivo existe
    Authorization: view Policy
    Behavior:
      1. Stream response ou temporaryUrl do disk private
      2. Nome = original_filename
  Action: DeleteAction
    Component: Filament\Actions\DeleteAction
    Visibility: Adm only
    Behavior: soft delete

toolbarActions:
  BulkActionGroup → DeleteBulkAction (Adm)
```

**RetryImportBatchAction:** opcional MVP — só Failed estrutural sem PRs; se não implementar, omitir.

### 8.5 RelationManagers

#### Errors (read-only)

```
Command: php artisan make:filament-relation-manager ImportBatchResource errors row_number --generate --panel=admin --no-interaction
SEM --soft-deletes

Columns:
  row_number → TextColumn sortable
  target_field → TextColumn badge nullable
  message → TextColumn wrap
  created_at → TextColumn toggleable hidden

recordActions: []
headerActions: []
toolbarActions: []
defaultSort: row_number asc
```

#### PaymentRequests

```
Command: php artisan make:filament-relation-manager ImportBatchResource paymentRequests id --generate --panel=admin --no-interaction

Columns:
  id → TextColumn (link para PaymentRequestResource view se possível)
  branch.name → TextColumn
  supplier.name → TextColumn
  net_amount → TextColumn money BRL
  status → TextColumn badge
  due_date → TextColumn date

recordActions: ViewAction (url para PR) opcional
headerActions: []  (não criar PR daqui)
toolbarActions: []
```

### 8.6 Pages

- `ListImportBatches` — CreateAction (canCreate Policy)
- `CreateImportBatch` — `StartImportBatchAction`; redirect View
- `ViewImportBatch` — header: Download, Delete (Adm); poll até terminal
- **Sem** `EditImportBatch`

---

## 9. Authorization / Policies

Docs: https://filamentphp.com/docs/5.x/security/authorization

Registrar em `AppServiceProvider::POLICIES`:

```
ImportTemplate::class => ImportTemplatePolicy::class
ImportBatch::class => ImportBatchPolicy::class
```

### 9.1 `ImportTemplatePolicy`

| Método | Regra |
|--------|-------|
| viewAny | user `isAdm()` |
| view | user `isAdm()` |
| create | user `isAdm()` |
| update | user `isAdm()` |
| delete | user `isAdm()` |
| restore | user `isAdm()` |
| forceDelete | user `isAdm()` |
| deleteAny / forceDeleteAny / restoreAny | user `isAdm()` |

### 9.2 `ImportBatchPolicy`

| Método | Regra |
|--------|-------|
| viewAny | user `isOperador()` **ou** `isAdm()` |
| view | user `isOperador()` **ou** `isAdm()` |
| create | user `isOperador()` **ou** `isAdm()` |
| update | `false` (sem Edit) — ou só Adm restore metadata se necessário |
| delete | user `isAdm()` |
| restore | user `isAdm()` |
| forceDelete | user `isAdm()` |
| deleteAny / etc. | user `isAdm()` |

**Cliente:** todos os métodos retornam `false` / 403.

---

## 10. State Transitions

### `ImportBatchStatus`

```
pending ──► processing ──► completed
   │              │
   └──────────────┴──► failed
```

| De | Para | Quem / quando |
|----|------|---------------|
| Pending | Processing | Job no início de `process` |
| Pending | Failed | Validação estrutural pré-loop (MIME, empty, etc.) ou `failed()` |
| Processing | Completed | Fim do loop (com ou sem erros de linha) |
| Processing | Failed | Exceção não recuperável / row limit / unsafe reentry |
| Completed / Failed | * | **Bloqueado** (`canTransitionTo` = false) |

Service deve validar `canTransitionTo` antes de update; senão `batchNotPending` / invalid transition.

**Não** existe rollback all-or-nothing por linha ruim (modo parcial).

---

## 11. Events / Notifications / Queue

### 11.1 `PaymentRequestBatchImported`

```
Namespace: App\Events\PaymentRequest\PaymentRequestBatchImported
Payload: public ImportBatch $batch
Dispatch: sync após commit dos contadores + status Completed
NÃO dispara em Failed
Dispara mesmo se success_count = 0
```

| Listener | Namespace | ShouldQueue | Efeito |
|----------|-----------|-------------|--------|
| `LogPaymentRequestBatchImported` | `App\Listeners\PaymentRequest\` | Não (ou sim se log remoto) | Log estruturado: batch id, counts, actor |
| `NotifyImportBatchCompleted` | `App\Listeners\PaymentRequest\` | **Sim** | Notifica `created_by` |

Registrar no `AppServiceProvider` EventService (padrão F4).

### 11.2 Notification

```
Namespace: App\Notifications\PaymentRequestBatchImportedNotification
Implements: ShouldQueue
Canais: mail, database
Payload: counts, link Filament View ImportBatch
```

### 11.3 Reuso F3/F4 (por PR)

Cada `PaymentRequestService::create` → `PaymentRequestCreated` → `RoutePaymentRequestOnCreated` → `ApprovalService::route()`. Job **não** chama `route()` de novo (evita `alreadyPending`).

### 11.4 Queue / Job (resumo)

| Job | Queue | Tries | Timeout | Overlap |
|-----|-------|-------|---------|---------|
| `ProcessImportBatchJob` | default | 3 | 120s | `WithoutOverlapping` por batch id |

---

## 12. Traduções (i18n)

Arquivos a criar (pt_BR **e** en):

- `lang/{locale}/import_templates.php`
- `lang/{locale}/import_batches.php`
- Extensões em `lang/{locale}/enums.php`
- Extensões em `lang/{locale}/notifications.php` (ou messages na notification via import_batches)

Campos comuns (`is_active`, `created_at`, `created_by`, `status`) → `common.php` / enums.

### 12.1 `import_templates.php` (pt_BR — espelhar en)

```php
return [
    'label' => 'Template de importação',
    'plural' => 'Templates de importação',
    'navigation_label' => 'Templates de importação',

    'fields' => [
        'name' => 'Nome',
        'company_id' => 'Empresa alvo',
        'branch_id' => 'Filial alvo',
        'accepted_format' => 'Formato aceito',
        'current_version' => 'Versão atual',
        'mappings' => 'Mapeamentos',
        'source_column' => 'Coluna da planilha',
        'target_field' => 'Campo destino',
        'default_value' => 'Valor padrão',
    ],

    'sections' => [
        'general' => 'Informações gerais',
        'mappings' => 'Mapeamento de colunas',
    ],

    'hints' => [
        'branch_default' => 'Filial padrão usada quando a planilha não traz coluna de filial.',
    ],

    'actions' => [
        'add_mapping' => 'Adicionar mapeamento',
        'publish_version' => 'Publicar nova versão',
    ],

    'messages' => [
        'created' => 'Template de importação criado.',
        'updated' => 'Template atualizado.',
        'deleted' => 'Template excluído.',
        'version_published' => 'Nova versão publicada.',
    ],

    'errors' => [
        'inactive' => 'Template inativo ou excluído.',
        'missing_required_mappings' => 'Mapeie ou defina default para todos os campos obrigatórios.',
        'version_immutable' => 'Não é possível editar mapeamentos de uma versão publicada. Publique uma nova versão.',
        'version_in_use' => 'Esta versão possui lotes vinculados e não pode ser excluída.',
    ],
];
```

### 12.2 `import_batches.php` (pt_BR)

```php
return [
    'label' => 'Lote de importação',
    'plural' => 'Lotes de importação',
    'navigation_label' => 'Importação em lote',

    'fields' => [
        'import_template_version_id' => 'Template',
        'template' => 'Template',
        'spreadsheet' => 'Planilha',
        'status' => 'Status',
        'original_filename' => 'Arquivo',
        'total_rows' => 'Linhas',
        'success_count' => 'Sucessos',
        'error_count' => 'Erros',
        'failure_reason' => 'Motivo da falha',
        'started_at' => 'Início',
        'finished_at' => 'Fim',
        'row_number' => 'Linha',
        'target_field' => 'Campo',
        'message' => 'Mensagem',
    ],

    'sections' => [
        'upload' => 'Upload',
        'status' => 'Processamento',
        'errors' => 'Erros por linha',
        'payment_requests' => 'Solicitações geradas',
    ],

    'actions' => [
        'download' => 'Baixar planilha',
        'refresh' => 'Atualizar status',
    ],

    'messages' => [
        'started' => 'Importação enfileirada.',
        'completed' => 'Importação concluída: :success sucesso(s), :errors erro(s).',
        'failed' => 'Importação falhou: :reason',
    ],

    'errors' => [
        'incompatible_format' => 'O arquivo não corresponde ao formato do template.',
        'unreadable' => 'Não foi possível ler a planilha.',
        'row_limit_exceeded' => 'A planilha excede o limite de :max linhas.',
        'empty' => 'A planilha não contém linhas de dados.',
        'not_pending' => 'Este lote não está pendente de processamento.',
        'unauthorized' => 'Você não tem permissão para importar.',
        'supplier_not_found' => 'Fornecedor não encontrado para o documento :document.',
        'invalid_document' => 'CPF/CNPJ inválido: :document.',
        'cost_center_not_found' => 'Centro de custo não encontrado: :code.',
        'appropriation_required' => 'Apropriação obrigatória para esta empresa.',
        'appropriation_not_found' => 'Apropriação não encontrada: :code.',
        'boleto_not_supported' => 'Boleto não suportado em lote — use cadastro individual ou lote de anexos (Fase 6).',
        'duplicate_row' => 'Possível duplicata no lote (linha :row).',
    ],
];
```

### 12.3 Adds em `enums.php`

```php
'import_file_format' => [
    'csv' => 'CSV',
    'xlsx' => 'Excel (XLSX)',
],

'import_batch_status' => [
    'pending' => 'Pendente',
    'processing' => 'Processando',
    'completed' => 'Concluído',
    'failed' => 'Falhou',
],

'import_target_field' => [
    'branch_document' => 'CNPJ da filial',
    'supplier_document' => 'CPF/CNPJ do fornecedor',
    'cost_center_code' => 'Código do centro de custo',
    'appropriation_code' => 'Código da apropriação',
    'payment_method' => 'Forma de pagamento',
    'gross_amount' => 'Valor bruto',
    'discount_amount' => 'Desconto',
    'due_date' => 'Vencimento',
    'notes' => 'Observações',
    'deposit_type' => 'Tipo de depósito',
    'digitable_line' => 'Linha digitável',
    'pix_key_type' => 'Tipo de chave Pix',
    'pix_key' => 'Chave Pix',
    'pix_qr_code' => 'QR Code Pix',
    'holder_name' => 'Nome do titular',
    'holder_document' => 'Documento do titular',
    'bank_code' => 'Código do banco',
    'agency' => 'Agência',
    'agency_digit' => 'Dígito da agência',
    'account_number' => 'Conta',
    'account_digit' => 'Dígito da conta',
    'account_type' => 'Tipo de conta',
],
```

**Regra:** todos os labels no Form, Table, Actions e Widgets usam `__()`. Espelhar chaves em `lang/en/`.

---

## 13. API REST

> **Não aplicável nesta fase.** Assunção igual F1–F4: painel Filament interno BPO. Sem Sanctum/Passport/Swagger/endpoints. Não criar rotas `api/v1/*` para import.

---

## 14. Soft deletes, cascade e guards

> Matriz DBA (arquitetura §12 / §24) — copiar e obedecer.

| Entidade | SoftDeletes | Notas |
|----------|:-----------:|-------|
| ImportTemplate | Sim | Esconde do select; versions/batches históricos preservados (sem cascade soft) |
| ImportTemplateVersion | Sim (cautela) | Bloquear se `batches()->exists()` (`versionInUse`); `cascadeOnDelete` do template só no **force** |
| ImportTemplateMapping | **Não** | Hard-owned pela version; forceDelete version → cascade hard |
| ImportBatch | Sim | Soft **não** apaga errors nem zera `payment_requests.import_batch_id` |
| ImportBatchError | **Não** | Append-only; removidos só no **forceDelete** do batch (`cascadeOnDelete`) |
| PaymentRequest | (F3) | Soft-delete PR **não** remove batch; forceDelete batch → `import_batch_id` null |

**Regra crítica:** `cascadeOnDelete` / `restrictOnDelete` / `nullOnDelete` aplicam-se a **hard/forceDelete**. Soft-delete **nunca** dispara FK cascade.

| Ação | Efeito |
|------|--------|
| Soft-delete template | Só `import_templates.deleted_at`; versions intactas |
| Soft-delete batch | Só `import_batches.deleted_at`; errors e PRs intactos |
| ForceDelete batch | Hard delete errors (FK cascade) + apagar arquivo (Observer) + `payment_requests.import_batch_id` → null |
| ForceDelete version com batches | **Bloqueado** por `restrictOnDelete` no batch |
| Soft-delete version com batches | **Bloqueado** no Service (`versionInUse`) |

**Pruning:** fora do MVP F5.

---

## 15. Factories & Seeders

| Factory | States úteis |
|---------|--------------|
| `ImportTemplateFactory` | `inactive`, `withCompany`, `withBranch` |
| `ImportTemplateVersionFactory` | `current`, `forTemplate` |
| `ImportTemplateMappingFactory` | `forField(ImportTargetField)`, `defaultOnly` (source_column null) |
| `ImportBatchFactory` | `pending`, `processing`, `completed`, `failed`, `withFile` (`Storage::fake`) |
| `ImportBatchErrorFactory` | `forRow(int)` |
| `PaymentRequestFactory` | state `fromImportBatch(ImportBatch)` |

**Seeder (dev):** `ImportTemplateSeeder` — 1 template Deposit/Pix ilustrativo com mappings mínimos + v1 (não obrigatório em produção). Registrar em `DatabaseSeeder` só se padrão F1–F4 permitir seeders de domínio em local.

Toda Model nova com factory.

---

## 16. Tests (Pest)

Docs: https://filamentphp.com/docs/5.x/testing/overview  
Skill: `testing-best-practices` antes de escrever.

> Ambiente: `docker compose exec app php artisan test --compact --filter=Import`

### 16.1 Feature — RF025 (template)

| # | Caso |
|---|------|
| T01 | Adm cria template + v1 com mappings obrigatórios → OK |
| T02 | Operador/Cliente 403 no CRUD template (livewire / policy) |
| T03 | Publish v2: `is_current` migra; v1 permanece; batch antigo aponta v1 |
| T04 | Publish sem `supplier_document` / branch → `templateMissingRequiredMappings` |
| T05 | Soft-delete template remove do select de import; batch histórico View OK |

### 16.2 Feature — RF026 (batch)

| # | Caso |
|---|------|
| T06 | Upload válido + job → PRs `Requested` com filial correta + `import_batch_id` |
| T07 | Linha inválida + linha válida → `success_count=1`, `error_count=1`, PR só da válida (modo parcial) |
| T08 | Relatório errors contém `row_number` + message |
| T09 | `ProcessImportBatchJob` dispatched (`Queue::fake`) e processa (`Bus::dispatchSync`) |
| T10 | CPF/CNPJ inválido → erro linha; válido inexistente → supplierNotFound |
| T11 | Cliente tenta start → 403 |
| T12 | Branch inativa / fora do escopo → erro linha |
| T13 | Boleto na linha → erro batch-specific; zero PR boleto |
| T14 | Arquivo xlsx com template csv → Failed estrutural |
| T15 | Event `PaymentRequestBatchImported` + Notification fake ao Completed |
| T16 | Cada PR criada dispara route (Approval Pending **ou** log sem regra) — assert Approval count / Event |
| T17 | Soft-delete / forceDelete batch + limpeza arquivo (`Storage::fake`) |

### 16.3 Schema / DBA notes (§24) — `ImportSchemaConstraintsTest`

| # | Caso |
|---|------|
| T18 | Unique parcial `name` — dois ativos com mesmo name falham; soft-deleted libera reuse |
| T19 | Unique parcial um `is_current` por template |
| T20 | Unique full `(template_id, version)` — não reutiliza número após soft-delete version |
| T21 | Múltiplos mappings com `source_column` null na mesma version → OK (Postgres NULL distinct) |
| T22 | Soft batch mantém errors; force batch remove errors + null `import_batch_id` nas PRs |
| T23 | `restrictOnDelete` impede forceDelete version com batch |
| T24 | Alter PR não quebra colunas F3/F4 |

### 16.4 Unit

| # | Caso |
|---|------|
| T25 | `ImportBatchStatus::canTransitionTo` matriz |
| T26 | Parse monetário / data |
| T27 | `ImportTargetField` required set vs defaults |
| T28 | Idempotência job em batch Completed |

### 16.5 Filament Resource tests

| # | Caso |
|---|------|
| T29 | ImportTemplateResource: list/create/edit visível Adm; hidden Operador |
| T30 | ImportBatchResource: create/list Operador; Cliente forbidden |
| T31 | Publish action modal / Download action autorizados |

**Authorization tests:** cobrir matriz §9.

**Validation (dataset):** name required max:120; accepted_format in enum; spreadsheet required; mappings min 1.

---

## 17. Estimativa

| Componente | Complexidade | Tempo estimado |
|------------|--------------|----------------|
| Config + Enums + Exception | Baixa | 30 min |
| Migrations + Models + Factories | Média | 1h30 |
| ImportTemplateService + publish | Média | 1h |
| ImportBatchService + row resolver | Alta | 2h30 |
| OpenSpout reader (após dep) | Média | 45 min |
| Job + Event + Listeners + Notification | Média | 45 min |
| Policies + Observer | Baixa | 30 min |
| ImportTemplateResource + RM + Publish | Média–Alta | 1h30 |
| ImportBatchResource + RMs + Download + poll | Alta | 1h30 |
| Seeder + i18n | Baixa | 30 min |
| Testes Pest (§16) | Alta | 2h30 |
| Pint + ajustes | Baixa | 30 min |
| **Total** | — | **~13–15 h** |

---

## 18. Notas e checklist pré-implementação

### 18.1 Passo bloqueante

- [ ] **Aprovar** promoção de `openspout/openspout:^4.23` a `require` direto em `composer.json` — **sem aprovação, não rodar `composer require`**
- [ ] Após aprovação: `docker compose exec app composer require openspout/openspout:^4.23 --no-interaction`

### 18.2 Checklist

- [ ] Ler este blueprint inteiro + arquitetura §21/§24 se dúvida de schema
- [ ] Config `rjet.imports.*`
- [ ] Enums + i18n (`import_templates`, `import_batches`, `enums`)
- [ ] Migrations §3 (uniques parciais name/`is_current`; mappings sem SoftDeletes; alter PR sem `after()`)
- [ ] Models + `UPDATED_AT = null` em Version/Mapping/Error + `import_batch_id` em PaymentRequest
- [ ] `ImportException` factory methods
- [ ] DTOs + Services + SpreadsheetReader
- [ ] Actions `Import/*`
- [ ] Job `ProcessImportBatchJob` (tries 3, timeout 120, WithoutOverlapping)
- [ ] Event + Listeners + Notification
- [ ] Observer forceDelete arquivo
- [ ] Policies + `AppServiceProvider::POLICIES`
- [ ] Filament Resources §7–§8 (pasta plural, final, limpo, infolist, recordActions/toolbarActions, Heroicon enum, `->live()`)
- [ ] Seeder opcional
- [ ] Pest §16 (inclui T18–T24 DBA)
- [ ] Pint: `vendor/bin/pint --dirty --format agent`
- [ ] Checklists `.ai/checklists.md` (Model, Migration, Job, Event, Policy, Factory)

### 18.3 Notas para implementer (DBA §24)

1. Copiar helper `supportsPartialIndexes()` de migrations F3/F4.
2. Models Version/Mapping/Error: `public const UPDATED_AT = null;` + `$timestamps = false`.
3. Soft-delete template **não** soft-deleta versions.
4. Soft-delete version: guard `versionInUse`; force bloqueado por `restrictOnDelete`.
5. ForceDelete batch: Observer apaga arquivo; errors via FK cascade; PRs `import_batch_id` null.
6. Publish version: transação — `is_current=false` + insert vN+1 + insert mappings (nunca update mapping).
7. Não criar `$table->enum()`; não indexar JSON; não monetário nas tabelas de import.
8. Cada PR via `PaymentRequestService::create`; **nunca** `ApprovalService::route()` no job.

### 18.4 Widgets

**Nenhum** nesta fase.

---

## 19. Próximos passos

1. Revisar este blueprint (especialmente A2 parcial, A6 Cliente, A7 boleto, A4 OpenSpout).
2. Obter aprovação da dep OpenSpout.
3. Encaminhar ao **`implementer`** na ordem do §2 / checklist §18.
4. Após implementação: `tester` cobre §16; `reviewer` / `security` conforme pipeline do projeto.
5. Não iniciar F6 até PRs de lote terem `import_batch_id` estável (hook anexos).

---

## Apêndice A — Formato obrigatório Blueprint (lembrete ao implementer)

Todo Field/Column/Filter/Action no plano acima inclui (quando aplicável):

- **Field:** Component (namespace completo), Docs (URL), Validation, Config  
- **Column:** Component, Docs, Config  
- **Action:** Component, Docs, Location, Visibility, Authorization, Behavior  
- **Filter:** Component, Docs, Config  
- **Reactive:** Imports `Get`/`Set`, usar `->live()` **nunca** `->reactive()`

Referência oficial: `vendor/filament/blueprint/resources/markdown/planning/overview.md` (já incorporada neste documento).

---

*Fim do Filament Blueprint — Fase 5. Pronto para `implementer`.*
