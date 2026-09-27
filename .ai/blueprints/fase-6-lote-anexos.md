# Filament Blueprint: Fase 6 — Lote de anexos (nomenclatura padronizada)

> **Tipo:** Plano de implementação (Filament Blueprint v5). **NÃO é código de produção.**
> **Escopo:** RF027–RF029 — `AttachmentBatch` (upload múltiplo), classificação ordenada (`ClassifyAttachmentBatch` + Livewire), histórico append-only, job de nomenclatura `YYYYMMDD_HHMMSS_SEQ.ext`, rebind morph para `PaymentRequest`, events `AttachmentBatchClassified` / `AttachmentRenamed` / `AttachmentBatchRenamed`.
> **Fonte de requisitos:** [.ai/arquitetura/fase-6-lote-anexos.md](../arquitetura/fase-6-lote-anexos.md) (revisão DBA §24 — **Aprovado com ressalvas**, já incorporadas em §21). Schema = **§21 pós-DBA**, não o rascunho original.
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
7. [Filament Resource: AttachmentBatch](#7-filament-resource-attachmentbatch)
8. [Livewire: ClassifyAttachmentBatch](#8-livewire-classifyattachmentbatch)
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

**Docs (backup):** https://filamentphp.com/docs/5.x/forms/overview · https://filamentphp.com/docs/5.x/tables/overview · https://filamentphp.com/docs/5.x/actions/overview · https://filamentphp.com/docs/5.x/infolists/overview · https://filamentphp.com/docs/5.x/schemas/overview · https://filamentphp.com/docs/5.x/resources/overview · https://livewire.laravel.com/docs/4.x/sorting

### 0.2 Estrutura obrigatória do Resource (pasta PLURAL, Resource LIMPO)

```
app/Filament/Resources/AttachmentBatches/          ← PLURAL
├── AttachmentBatchResource.php                    ← final, LIMPO: só delegates
├── Schemas/AttachmentBatchForm.php                ← final (Create = upload múltiplo)
├── Schemas/AttachmentBatchInfolist.php            ← final (SEMPRE)
├── Tables/AttachmentBatchesTable.php              ← final, nome PLURAL
├── Pages/ListAttachmentBatches.php
├── Pages/CreateAttachmentBatch.php
├── Pages/ViewAttachmentBatch.php                  ← SEM Edit
├── Pages/ClassifyAttachmentBatch.php              ← página custom (embed Livewire)
├── RelationManagers/ItemsRelationManager.php
├── RelationManagers/ClassificationsRelationManager.php
└── Actions/
    ├── ConcludeAttachmentBatchClassificationAction.php
    ├── RetryFailedAttachmentBatchRenamesAction.php
    └── DownloadAttachmentBatchItemAction.php
```

**VALIDAÇÃO:** se `*Resource.php` contiver `TextInput`, `TextColumn`, `Section`, `Select`, `Toggle` ou qualquer componente de form/table/layout diretamente, **está errado**. Todas as classes são `final` e têm `declare(strict_types=1);`.

**Comando Artisan (SEMPRE usar, NUNCA criar Resource na mão):**

```bash
php artisan make:filament-resource AttachmentBatch --generate --soft-deletes --view --panel=admin --no-interaction
```

Após scaffold: **remover** `EditAttachmentBatch` de `getPages()` (não registrar Edit). Adicionar página `ClassifyAttachmentBatch` manualmente.

### 0.3 Layout — largura efetiva das colunas (crítico)

| Colunas do Form | Colunas da Section | Largura efetiva | OK? |
|---|---|---|---|
| 2 | 2 | 25% | **NÃO** |
| **1** | **2** | **50%** | **Sim ← padrão desta fase** |
| 1 | 1 | 100% | Sim (campos largos / FileUpload) |

**Decisão desta fase:** `Schema::columns(1)` no Form e no Infolist; cada `Section` com `->columns(2)` (FileUpload → `columnSpanFull`).

### 0.4 i18n (obrigatório)

Nenhuma string de UI hardcoded. Todo label/heading/placeholder/helperText/notificação usa `__()`. Campos comuns em `common.php`; específicos em `attachment_batches.php`, erros novos em `attachments.php`, enums em `enums.php`. Arquivos em **`lang/pt_BR/`** e **`lang/en/`** (ambos obrigatórios).

### 0.5 Padrões do projeto (F1–F5 já implementados — seguir sem desviar)

- Models de domínio: `final`, `declare(strict_types=1)`, traits `HasFactory, HasUuid, SoftDeletes, HasBlameable` (quando aplicável), `casts()` como **método**, PK `uuid`.
- Migrations: `timestampsTz()` + `softDeletesTz()` onde aplicável; FK com `->index()` explícito (Postgres); **enum sempre `string`**, nunca `$table->enum()`.
- Unique parcial: **nunca** `$table->unique()` na coluna quando SoftDeletes. Sempre `DB::statement` dentro de `if ($this->supportsPartialIndexes())`, com `DROP INDEX IF EXISTS` no `down()`. Helper privado:
  ```php
  private function supportsPartialIndexes(): bool
  {
      return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
  }
  ```
  Copiar de `database/migrations/2026_07_31_101029_create_payment_request_bank_details_table.php` (ou `approvals` / F5 import).
- Policies: registrar na constante `AppServiceProvider::POLICIES`.
- Guards de exclusão: método no Service + Action Filament capturando `BusinessException` → `Notification::danger($e->getUserMessage())` → `$action->halt()`.
- `Model::preventLazyLoading()` ativo fora de produção → **eager loading obrigatório**.
- Events/Listeners/Actions/Jobs: **agrupar por domínio** `Attachment` (`App\Events\Attachment\`, `App\Listeners\Attachment\`, `App\Actions\Attachment\`, `App\Jobs\Attachment\`). Services/DTOs/Exceptions/Models/Policies: **flat** (`agrupar_por_dominio` em PROJECT.md).
- Comentários de código em **inglês** (mínimos). Documentação deste blueprint em **pt-BR**.
- Ambiente Docker (`PROJECT.md`): prefixar artisan/pest/pint/composer com `docker compose exec app`.

### 0.6 Decisões FECHADAS — NÃO reabrir / NÃO inventar (inclui ressalvas DBA)

| # | Decisão |
|---|----------|
| 1 | **Sem** API REST |
| 2 | Resource único `AttachmentBatchResource`; Create = upload múltiplo; **sem** Edit genérica |
| 3 | Classificação em `Pages/ClassifyAttachmentBatch` + Livewire `App\Livewire\Attachment\ClassifyAttachmentBatch` (**incluído**, não opcional) |
| 4 | Unique parcial `attachment_batch_items.attachment_id WHERE deleted_at IS NULL` via `supportsPartialIndexes()`; **nunca** `$table->unique()` no Blueprint quando SoftDeletes; `DROP INDEX IF EXISTS` no `down()` |
| 5 | SoftDeletes **não** cascateia via `cascadeOnDelete`; cascade soft = Observer/Service; FK cascade só no **forceDelete** onde a filha não tem soft delete (classifications) |
| 6 | `attachment_batch_item_classifications` append-only: só `classified_at`; **sem** SoftDeletes; **sem** `updated_at`; Model `UPDATED_AT = null` + `$timestamps = false` |
| 7 | **Sem** CHECK SQL de destino; validação no Service (`AttachmentException::invalidDestination()`) |
| 8 | Índice composto `(attachment_batch_id, sort_order)`; **sem** índice solo em `sort_order` nem em `standardized_name` |
| 9 | Tamanhos: lote `status` string(**30**), item `status` string(**20**), `destination_type` string(**30**), `standardized_name` string(**255**), `operational_label` string(**120**), `rename_error` string(**500**) |
| 10 | Alter `attachments` **sem** `after()` |
| 11 | Contadores desnormalizados com comentário inglês na migration (`// Denormalized: UI cache — source of truth is items()`) |
| 12 | FKs com `->index()` explícito (Postgres) |
| 13 | Rebind morph para PaymentRequest: **um único** `Attachment`; dual sync `has_attachments` (batch no-op se sem coluna + PR) |
| 14 | Nomenclatura `YYYYMMDD_HHMMSS_SEQ.ext`; timezone `America/Sao_Paulo`; SEQ pelo `sort_order` (1-based, zero-pad 3); colisão `_2`…`_99` |
| 15 | Destinos: `payment_request` \| `supplier` \| `operational_category` (label livre) |
| 16 | Job **um por lote** `RenameAttachmentBatchJob`; `AttachmentBatchRenamed` **só** em sucesso total (`renamed`) |
| 17 | Cliente **sem** `viewAny`; delete/restore/forceDelete **só** Adm; classify = Operador/Adm |
| 18 | Ícone `Heroicon::OutlinedPaperClip`; group `operations`; sort **16** |
| 19 | Widgets: **nenhum** nesta fase |
| 20 | Reabrir lote já `classified`: MVP **não** reabre |
| 21 | Sem `HasAttachments` em Supplier nesta fase; destino Supplier/OperationalCategory → Attachment **permanece** no batch |
| 22 | Reusar `config('rjet.attachments.*')` — MIME, max KB, max files, disk, staging (não criar segundo limite) |

### 0.7 Estado real do código a estender (inspecionado 2026-09-26)

| Artefato | Estado |
|----------|--------|
| `App\Models\Attachment` | Existe — UUID, SoftDeletes, HasBlameable, morph `attachable`; **sem** `standardized_name` |
| `HasAttachments` | Existe — só em `PaymentRequest`; no-op se coluna `has_attachments` ausente |
| `AttachmentService` | Existe — `storeUploadedFile`, `storeManyFromPaths`, MIME/size, staging, orphan cleanup |
| `AttachmentsRelationManager` (PR) | Existe — FileUpload staging, `storeFiles(false)`, `reorderable('sort_order')` |
| `AttachmentException` | Existe — `invalidMimeType`, `fileTooLarge`, `fileNotFound` — **estender** |
| Config `rjet.attachments.*` | Existe — disk, directory, max_kilobytes (10240), max_files (10), MIME PDF/JPEG/PNG/WebP |
| Models `AttachmentBatch` / Item / Classification | **Não existem** |
| Events / Job de rename | **Não existem** |
| Morph map | `supplier`, `payment_request`, `user` — **sem** `attachment_batch` |
| Painel Filament | Único `admin`; `ImportBatchResource` sort 15 em `operations` |
| `App\Livewire\*` | Pasta vazia / inexistente — primeiro componente Livewire custom do domínio |
| `AppServiceProvider::POLICIES` | Estender com AttachmentBatch (+ Item se necessário) |

---

## 1. Visão Geral

A Fase 6 entrega o fluxo operacional de **lote de anexos** reutilizando o morph F3 e o padrão de lote F5:

| Entrega | Artefato |
|---------|----------|
| Upload múltiplo (RF027) | `AttachmentBatchResource` Create + `AttachmentBatchService::createFromUploads` |
| Classificação ordenada (RF028) | Página Classify + Livewire + histórico append-only |
| Nomenclatura (RF029) | `RenameAttachmentBatchJob` + `standardized_name` + rebind PR |
| Relatório / UI | View + RelationManagers (itens + histórico) + Actions (conclude, retry, download) |

**Fora de escopo (não implementar):** Fases 7–8 (baixa/CNAB/dashboard), API REST, redesign PR/alçadas/ImportBatch, morph `HasAttachments` em Supplier, OCR automático extra na F6, Widgets, reabrir lote já classified.

**Assunções:** §0.6 (fechadas). Hooks F3/F5 honrados: 1 arquivo = 1 `Attachment`; após destino PR, rebind da mesma linha; ImportBatch **não** é reutilizado.

---

## 2. Commands (ordem de execução)

> Ambiente Docker (`PROJECT.md`): prefixar tudo com `docker compose exec app`.

```bash
# ── 2.1 Enums ─────────────────────────────────────────────────────────────────
php artisan make:class Enums/AttachmentBatchStatus --no-interaction
php artisan make:class Enums/AttachmentBatchItemStatus --no-interaction
php artisan make:class Enums/AttachmentBatchDestinationType --no-interaction
# Trocar `final class` por `enum …: string implements HasLabel, HasColor, HasIcon`

# ── 2.2 Migrations (ORDEM OBRIGATÓRIA) ────────────────────────────────────────
php artisan make:migration add_standardized_name_to_attachments_table --table=attachments --no-interaction
php artisan make:migration create_attachment_batches_table --create=attachment_batches --no-interaction
php artisan make:migration create_attachment_batch_items_table --create=attachment_batch_items --no-interaction
php artisan make:migration create_attachment_batch_item_classifications_table --create=attachment_batch_item_classifications --no-interaction

# ── 2.3 Models + Factories ────────────────────────────────────────────────────
php artisan make:model AttachmentBatch --factory --no-interaction
php artisan make:model AttachmentBatchItem --factory --no-interaction
php artisan make:model AttachmentBatchItemClassification --factory --no-interaction
# ESTENDER (não recriar): Attachment (+ standardized_name fillable + displayName accessor + factory states)

# ── 2.4 Exceptions / DTOs / Services ──────────────────────────────────────────
# ESTENDER: App\Exceptions\AttachmentException (novos factory methods)
php artisan make:class DTOs/AttachmentBatchData --no-interaction
php artisan make:class DTOs/AttachmentBatchItemClassificationData --no-interaction
php artisan make:class DTOs/AttachmentNamingResult --no-interaction
php artisan make:class Services/AttachmentBatchService --no-interaction
php artisan make:class Services/AttachmentBatchClassificationService --no-interaction
php artisan make:class Services/AttachmentBatchNamingService --no-interaction
# ESTENDER: AttachmentService — moveAndUpdatePath, mimeToExtension map, helpers de rebind sync

# ── 2.5 Actions (domínio Attachment) ──────────────────────────────────────────
php artisan make:class Actions/Attachment/CreateAttachmentBatchAction --no-interaction
php artisan make:class Actions/Attachment/ClassifyAttachmentBatchItemAction --no-interaction
php artisan make:class Actions/Attachment/ReorderAttachmentBatchItemsAction --no-interaction
php artisan make:class Actions/Attachment/ConcludeAttachmentBatchClassificationAction --no-interaction
php artisan make:class Actions/Attachment/RetryFailedAttachmentBatchRenamesAction --no-interaction

# ── 2.6 Job / Events / Listeners ──────────────────────────────────────────────
php artisan make:job Attachment/RenameAttachmentBatchJob --no-interaction
php artisan make:event Attachment/AttachmentBatchClassified --no-interaction
php artisan make:event Attachment/AttachmentRenamed --no-interaction
php artisan make:event Attachment/AttachmentBatchRenamed --no-interaction
php artisan make:listener Attachment/QueueAttachmentBatchRename --no-interaction
php artisan make:listener Attachment/LogAttachmentRenamed --no-interaction
php artisan make:listener Attachment/NotifyAttachmentBatchRenamed --no-interaction
# Opcional: Notification AttachmentBatchRenamedNotification (mail+database) — listener ShouldQueue
# Registrar Event→Listeners em AppServiceProvider (padrão F5)

# ── 2.7 Observer (soft cascade batch → items; attachments só se ainda attachable=batch)
php artisan make:observer AttachmentBatchObserver --model=AttachmentBatch --no-interaction
# Registrar em AppServiceProvider

# ── 2.8 Policies ──────────────────────────────────────────────────────────────
php artisan make:policy AttachmentBatchPolicy --model=AttachmentBatch --no-interaction
php artisan make:policy AttachmentBatchItemPolicy --model=AttachmentBatchItem --no-interaction
# Registrar em AppServiceProvider::POLICIES
# ESTENDER AttachmentPolicy::view quando attachable é AttachmentBatch → can('view', $attachable)

# ── 2.9 Morph map ─────────────────────────────────────────────────────────────
# Em AppServiceProvider::boot Relation::enforceMorphMap adicionar:
#   'attachment_batch' => AttachmentBatch::class,

# ── 2.10 Filament Resource + Relation Managers + Livewire ─────────────────────
php artisan make:filament-resource AttachmentBatch --generate --soft-deletes --view --panel=admin --no-interaction
# Remover Edit de getPages; adicionar ClassifyAttachmentBatch
php artisan make:filament-relation-manager AttachmentBatchResource items sort_order --generate --soft-deletes --panel=admin --no-interaction
php artisan make:filament-relation-manager AttachmentBatchResource classifications classified_at --generate --panel=admin --no-interaction
# Classifications: SEM --soft-deletes (model append-only)
# Depois: limpar Resource, mover Form/Table/Infolist, criar Actions Filament em Actions/
php artisan make:livewire Attachment/ClassifyAttachmentBatch --no-interaction
# View Blade: resources/views/livewire/attachment/classify-attachment-batch.blade.php
# Página Filament: Pages/ClassifyAttachmentBatch.php (embed Livewire::make / @livewire)

# ── 2.11 Testes ───────────────────────────────────────────────────────────────
php artisan make:test --pest --unit AttachmentBatchStatusTest --no-interaction
php artisan make:test --pest --unit AttachmentNamingTest --no-interaction
php artisan make:test --pest AttachmentBatchServiceTest --no-interaction
php artisan make:test --pest AttachmentBatchClassificationTest --no-interaction
php artisan make:test --pest AttachmentBatchNamingTest --no-interaction
php artisan make:test --pest AttachmentBatchResourceTest --no-interaction
php artisan make:test --pest AttachmentBatchAuthorizationTest --no-interaction
php artisan make:test --pest AttachmentBatchSchemaConstraintsTest --no-interaction
php artisan make:test --pest RenameAttachmentBatchJobTest --no-interaction

# ── 2.12 Qualidade ────────────────────────────────────────────────────────────
php artisan migrate
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=AttachmentBatch
```

---

## 3. Models e Migrations

> Schema **DBA-aprovado** (arquitetura §21 / §24). Sem `after()`. Sem índice solo em `sort_order` / `standardized_name`. Sem `$table->enum()`. Sem CHECK SQL multi-coluna.

### 3.1 Ordem de migrations

1. `add_standardized_name_to_attachments_table` (alter — **sem** `after()`)
2. `create_attachment_batches_table`
3. `create_attachment_batch_items_table` (+ unique parcial `attachment_id`)
4. `create_attachment_batch_item_classifications_table`

**`down()` inverso:** drop classifications → DROP INDEX parcial + drop items → drop batches → dropColumn `standardized_name`.

### 3.2 Alter `Attachment` (existente)

```
Migration: add_standardized_name_to_attachments_table
Model: App\Models\Attachment (ESTENDER)
```

```php
Schema::table('attachments', function (Blueprint $table): void {
    // No after() — Postgres ignores column order (F4/F5 DBA)
    $table->string('standardized_name', 255)->nullable();
    // No unique, no index in MVP — display/accessor only
});
// down: $table->dropColumn('standardized_name');
```

**Fillable:** adicionar `standardized_name`  
**Accessor:** `displayName(): string` → `$this->standardized_name ?? $this->original_name`  
**Regra:** `original_name` **nunca** sobrescrito.

### 3.3 Model `AttachmentBatch` + migration

```
Migration: create_attachment_batches_table
Model: App\Models\AttachmentBatch
Traits: HasFactory, HasUuid, SoftDeletes, HasBlameable, HasAttachments
SoftDeletes: SIM
```

```php
Schema::create('attachment_batches', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('status', 30)->default('pending_classification')->index(); // AttachmentBatchStatus
    // Denormalized counters for Filament list — source of truth is attachment_batch_items
    $table->unsignedInteger('items_count')->default(0);
    $table->unsignedInteger('classified_count')->default(0);
    $table->unsignedInteger('renamed_count')->default(0);
    $table->unsignedInteger('failed_count')->default(0);
    $table->text('failure_reason')->nullable();
    $table->timestampTz('classified_at')->nullable();
    $table->timestampTz('renaming_started_at')->nullable();
    $table->timestampTz('renamed_at')->nullable();
    $table->timestampTz('naming_generated_at')->nullable();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();

    $table->index(['created_by', 'created_at']);
});
```

**Casts:** `status` → `AttachmentBatchStatus::class`; timestamps datetime  
**Fillable:** status, counters, failure_reason, classified_at, renaming_started_at, renamed_at, naming_generated_at  
**Relations:**
- `items(): HasMany AttachmentBatchItem` ordered by `sort_order`
- `attachments(): MorphMany` via `HasAttachments`
- `creator(): BelongsTo` (`created_by`)
- `classifications(): HasManyThrough` → via items (para RM)

**Scopes:**
- `scopeVisibleTo(User)` — Operador/Adm veem tudo; Cliente → `whereRaw('1 = 0')` (igual ImportBatch)

**Helpers:** `isPendingClassification()`, `isTerminal()`, `allItemsClassified(): bool`, `status->isTerminal()`

**Cascade soft:** Observer/Service soft-deleta **items**; Attachments só se ainda `attachable` = batch. `cascadeOnDelete` na FK do item **não** dispara no soft delete do batch.

### 3.4 Model `AttachmentBatchItem` + migration

```
Migration: create_attachment_batch_items_table
Model: App\Models\AttachmentBatchItem
Traits: HasFactory, HasUuid, SoftDeletes, HasBlameable
SoftDeletes: SIM
```

```php
Schema::create('attachment_batch_items', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('attachment_batch_id')->index()
        ->constrained('attachment_batches')->cascadeOnDelete(); // hard/force only
    $table->foreignUuid('attachment_id')->index()
        ->constrained('attachments')->restrictOnDelete(); // no ->unique() — partial below
    $table->unsignedInteger('sort_order')->default(0); // no standalone index
    $table->string('status', 20)->default('pending')->index(); // AttachmentBatchItemStatus
    $table->string('destination_type', 30)->nullable()->index(); // AttachmentBatchDestinationType
    $table->foreignUuid('payment_request_id')->nullable()->index()
        ->constrained('payment_requests')->nullOnDelete();
    $table->foreignUuid('supplier_id')->nullable()->index()
        ->constrained('suppliers')->nullOnDelete();
    $table->string('operational_label', 120)->nullable();
    $table->foreignUuid('classified_by')->nullable()->index()
        ->constrained('users')->nullOnDelete();
    $table->timestampTz('classified_at')->nullable();
    $table->string('rename_error', 500)->nullable();
    $table->timestampTz('renamed_at')->nullable();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();

    // Listagem: WHERE batch_id = ? ORDER BY sort_order
    $table->index(['attachment_batch_id', 'sort_order']);
});

if ($this->supportsPartialIndexes()) {
    DB::statement(
        'CREATE UNIQUE INDEX attachment_batch_items_attachment_id_unique
         ON attachment_batch_items (attachment_id)
         WHERE deleted_at IS NULL'
    );
}
// down:
// if ($this->supportsPartialIndexes()) {
//     DB::statement('DROP INDEX IF EXISTS attachment_batch_items_attachment_id_unique');
// }
// Schema::dropIfExists('attachment_batch_items');
```

**Casts:** status → `AttachmentBatchItemStatus`; destination_type → `AttachmentBatchDestinationType`; timestamps  
**Relations:** `batch()`, `attachment()`, `paymentRequest()`, `supplier()`, `classifications(): HasMany`, `classifiedBy()`  
**Integridade destino (Service only):** exatamente um alvo coerente com `destination_type` — sem CHECK SQL.

### 3.5 Model `AttachmentBatchItemClassification` + migration

```
Migration: create_attachment_batch_item_classifications_table
Model: App\Models\AttachmentBatchItemClassification
Traits: HasFactory, HasUuid — SEM SoftDeletes, SEM HasBlameable
SoftDeletes: NÃO
OBRIGATÓRIO:
  public $timestamps = false;
  public const UPDATED_AT = null;
  // Service preenche classified_at
```

```php
Schema::create('attachment_batch_item_classifications', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('attachment_batch_item_id')->index()
        ->constrained('attachment_batch_items')->cascadeOnDelete(); // OK: child has no SoftDeletes
    $table->foreignUuid('user_id')->nullable()->index()
        ->constrained('users')->nullOnDelete();
    $table->string('destination_type', 30); // snapshot
    $table->foreignUuid('payment_request_id')->nullable()->index()
        ->constrained('payment_requests')->nullOnDelete(); // snapshot FK
    $table->foreignUuid('supplier_id')->nullable()->index()
        ->constrained('suppliers')->nullOnDelete(); // snapshot FK
    $table->string('operational_label', 120)->nullable();
    $table->timestampTz('classified_at'); // append-only event time — no updated_at, no SoftDeletes

    $table->index(['attachment_batch_item_id', 'classified_at']);
});
```

### 3.6 Morph map

Em `AppServiceProvider` (já tem `supplier`, `payment_request`, `user`):

```php
Relation::enforceMorphMap([
    'supplier' => Supplier::class,
    'payment_request' => PaymentRequest::class,
    'user' => User::class,
    'attachment_batch' => AttachmentBatch::class,
]);
```

**Não** recriar tabela `attachments`.

### 3.7 Índices — resumo (NÃO indexar)

| Indexar | NÃO indexar |
|---------|-------------|
| batch `status`; `(created_by, created_at)` | Contadores |
| `(attachment_batch_id, sort_order)`; item `status`; `destination_type`; unique parcial `attachment_id` | `sort_order` sozinho |
| `(attachment_batch_item_id, classified_at)` | `standardized_name`; `failure_reason`; `rename_error`; `operational_label` |
| Toda FK com `->index()` | |

---

## 4. Enums

> Consulte padrão F1–F5: `HasLabel`, `HasColor`, `HasIcon`; labels via `__('enums.*')`.

### 4.1 `AttachmentBatchStatus`

```php
namespace App\Enums;

enum AttachmentBatchStatus: string implements HasLabel, HasColor, HasIcon
{
    case PendingClassification = 'pending_classification';
    case Classified = 'classified';
    case Renaming = 'renaming';
    case Renamed = 'renamed';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';

    // getLabel() → __('enums.attachment_batch_status.'.$this->value)
    // getColor(): pending_classification=warning, classified=info, renaming=primary,
    //             renamed=success, partially_failed=warning, failed=danger
    // getIcon(): Heroicon enum cases apropriados
    // canTransitionTo(self $to): bool — matriz §10
    // isTerminal(): renamed | partially_failed | failed
}
```

### 4.2 `AttachmentBatchItemStatus`

```php
enum AttachmentBatchItemStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case Classified = 'classified';
    case Renamed = 'renamed';
    case Failed = 'failed';
}
```

### 4.3 `AttachmentBatchDestinationType`

```php
enum AttachmentBatchDestinationType: string implements HasLabel, HasColor, HasIcon
{
    case PaymentRequest = 'payment_request';
    case Supplier = 'supplier';
    case OperationalCategory = 'operational_category';
}
```

### 4.4 `AttachmentType`

**Sem mudança** na F6.

---

## 5. Exceções de Domínio

**Estender** `App\Exceptions\AttachmentException` (já extends `BusinessException`):

| Factory | Quando | `userMessage` |
|---------|--------|---------------|
| `invalidMimeType` / `fileTooLarge` / `fileNotFound` | Já existem | existentes |
| `batchClassificationIncomplete()` | Conclude com item pending | `__('attachments.errors.batch_classification_incomplete')` |
| `batchNotClassifiable(string $status)` | Status ≠ pending_classification | tradução |
| `invalidDestination()` | Enum/FK inconsistente | tradução |
| `namingCollisionUnresolved(string $base)` | >99 sufixos | tradução **sem** path |
| `storageMoveFailed(string $attachmentId)` | `Storage::move` false | tradução sem path interno |
| `batchEmpty()` | Upload zero arquivos | tradução |
| `unauthorizedBatchOperation()` | Policy fail no Service | tradução |

Log `message` em inglês com contexto; UI só `getUserMessage()`.

---

## 6. Camadas de Aplicação

### 6.1 DTOs (flat `App\DTOs`)

| DTO | Campos principais |
|-----|-------------------|
| `AttachmentBatchData` | metadados pós-create (id, status, items_count) |
| `AttachmentBatchItemClassificationData` | `destination_type`, `payment_request_id?`, `supplier_id?`, `operational_label?`, `sort_order?` |
| `AttachmentNamingResult` | `standardized_name`, `path`, `collisionSuffix` |

### 6.2 Services (flat `App\Services`)

| Service | Responsabilidades |
|---------|-------------------|
| `AttachmentService` (**estender**) | `moveAndUpdatePath`, mapa mime→ext (`application/pdf`→`pdf`, `image/jpeg`→`jpg`, …), assert MIME/size (já existe); helpers de sync dual `has_attachments` pós-rebind |
| `AttachmentBatchService` | `createFromUploads(array $files, User $actor): AttachmentBatch`; sync contadores; soft/force delete orquestrado |
| `AttachmentBatchClassificationService` | `classifyItem(...)`, `reorder(array $orderedIds)`, `conclude(AttachmentBatch)` → event; assert destino |
| `AttachmentBatchNamingService` | `renameBatch`, `renameItem`, geração nome, colisão, `rebindToPaymentRequestIfNeeded` |

**Regra:** Filament/Actions/Livewire **não** falam com Storage diretamente — passam pelos Services.

**CreateFromUploads (resumo):**
1. Assert `count($files) > 0` senão `batchEmpty()`.
2. Validar cada arquivo via `AttachmentService` (MIME/size).
3. Criar batch `pending_classification`.
4. Para cada arquivo (índice = sort_order): `storeUploadedFile($batch, $file)` → Attachment morph→batch; criar `AttachmentBatchItem` (`pending`, `sort_order`).
5. Sync contadores (`items_count = N`).
6. Relocate staging → `attachments/attachment_batch/{uuid}/` (padrão F3 `directoryFor`).

**Assert destino (Service):**
- `payment_request` → `payment_request_id` required; `supplier_id` e `operational_label` null.
- `supplier` → `supplier_id` required; demais null.
- `operational_category` → `operational_label` required (max 120); FKs null.
- Caso contrário → `invalidDestination()`.

**Rebind PR (após rename ok):**
1. Move path para `attachments/payment_request/{prUuid}/{standardized_name}`.
2. Update morph `attachable` → PaymentRequest (mesma linha).
3. Sync `has_attachments` na PR **e** no-op no batch (sem coluna).

### 6.3 Actions (`App\Actions\Attachment\`)

Todas `final`, `__invoke(...)`, tipadas. Policy check no início.

| Action | Chama |
|--------|-------|
| `CreateAttachmentBatchAction` | Policy create + `AttachmentBatchService::createFromUploads` |
| `ClassifyAttachmentBatchItemAction` | Policy classify + ClassificationService + history insert |
| `ReorderAttachmentBatchItemsAction` | Policy + reorder |
| `ConcludeAttachmentBatchClassificationAction` | Assert completo + status classified + event |
| `RetryFailedAttachmentBatchRenamesAction` | Re-dispatch job (só itens `failed`; batch `partially_failed` ou `failed` recuperável) |

### 6.4 Form Requests

Opcional — validação principal no Filament + asserts no Service (padrão F3/F5).

### 6.5 Config

**Reusar** `config('rjet.attachments.*')` — **não** criar chave paralela na F6.

| Chave | Uso F6 |
|-------|--------|
| `disk` | FileUpload + Storage |
| `directory` | base `attachments/` |
| `max_kilobytes` | FileUpload `maxSize` + Service |
| `max_files` | FileUpload `maxFiles` |
| `accepted_mime_types` | FileUpload + Service |
| `staging_ttl_hours` | orphan cleanup (já existe) |

---

## 7. Filament Resource: AttachmentBatch

### 7.1 Scaffold

```
Resource: AttachmentBatchResource
  Command: php artisan make:filament-resource AttachmentBatch --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\AttachmentBatches\AttachmentBatchResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview
  SoftDeletes: getRecordRouteBindingEloquentQuery()
  Icon: Heroicon::OutlinedPaperClip
  Navigation:
    Group: __('navigation.groups.operations')
    Sort: 16
    Label: __('attachment_batches.navigation_label')
  Visibility: Operador + Adm (Policy viewAny); Cliente excluído
  Pages: List, Create, View, Classify — SEM Edit
  recordTitleAttribute: id (ou created_at formatado) — preferir id curto / status + data
```

**Resource principal (espelhar `ImportBatchResource`):**

```php
public static function canViewAny(): bool
{
    return Filament::auth()->user()?->can('viewAny', AttachmentBatch::class) ?? false;
}

public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery()->with(['creator']);
    $user = Filament::auth()->user();

    return $user !== null ? $query->visibleTo($user) : $query;
}

public static function getRecordRouteBindingEloquentQuery(): Builder
{
    return parent::getRecordRouteBindingEloquentQuery()
        ->withoutGlobalScopes([SoftDeletingScope::class])
        ->with(['creator', 'items.attachment', 'items.paymentRequest', 'items.supplier']);
}

public static function getPages(): array
{
    return [
        'index' => ListAttachmentBatches::route('/'),
        'create' => CreateAttachmentBatch::route('/create'),
        'view' => ViewAttachmentBatch::route('/{record}'),
        'classify' => ClassifyAttachmentBatch::route('/{record}/classify'),
    ];
}

public static function getRelations(): array
{
    return [
        ItemsRelationManager::class,
        ClassificationsRelationManager::class,
    ];
}
```

**Eager load List:** `creator`. **View:** `items.attachment`, `items.paymentRequest`, `items.supplier`, `creator`.

### 7.2 Form — `Schemas/AttachmentBatchForm.php` (Create = upload múltiplo)

```
Schema columns(1)

Section: __('attachment_batches.sections.upload')
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Config: ->columns(2)

  Field: files
    Component: Filament\Forms\Components\FileUpload
    Docs: https://filamentphp.com/docs/5.x/forms/file-upload
    Validation: required; multiple; acceptedFileTypes config MIME; maxSize max_kilobytes; maxFiles max_files
    Config:
      ->label(__('attachment_batches.fields.files'))
      ->multiple()
      ->disk(fn (): string => (string) config('rjet.attachments.disk'))
      ->directory(fn (): string => app(AttachmentService::class)->stagingDirectoryFor(Filament::auth()->user()))
      ->visibility('private')
      ->acceptedFileTypes(config('rjet.attachments.accepted_mime_types'))
      ->maxSize(fn (): int => (int) config('rjet.attachments.max_kilobytes'))
      ->maxFiles(fn (): int => (int) config('rjet.attachments.max_files'))
      ->storeFiles(false)  // Service persiste após submit
      ->required()
      ->columnSpanFull()
```

**Create page:** após submit → `CreateAttachmentBatchAction` → redirect `ViewAttachmentBatch`.

Espelhar padrão `AttachmentsRelationManager` / `ImportBatchForm` (`storeFiles(false)`, disco/MIME/size de config).

### 7.3 Infolist — `Schemas/AttachmentBatchInfolist.php`

```
Schema columns(1)

Section: __('attachment_batches.sections.status')
  Config: ->columns(2)

  Entry: status
    Component: Filament\Infolists\Components\TextEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/text-entry
    Config: ->badge()

  Entry: items_count
    Component: TextEntry
    Config: ->label(__('attachment_batches.fields.items_count'))

  Entry: classified_count
    Component: TextEntry

  Entry: renamed_count
    Component: TextEntry

  Entry: failed_count
    Component: TextEntry

  Entry: failure_reason
    Component: TextEntry
    Config: ->visible(fn (AttachmentBatch $r): bool =>
        in_array($r->status, [AttachmentBatchStatus::Failed, AttachmentBatchStatus::PartiallyFailed], true))
      ->columnSpanFull()

  Entry: classified_at / renaming_started_at / renamed_at / naming_generated_at
    Component: TextEntry
    Config: ->dateTime('d/m/Y H:i')->placeholder('—')

  Entry: creator.name
    Component: TextEntry
    Config: ->label(__('common.fields.created_by'))

  Entry: created_at
    Component: TextEntry
    Config: ->dateTime('d/m/Y H:i')
```

### 7.4 Table — `Tables/AttachmentBatchesTable.php`

```
modifyQueryUsing: with(['creator'])

Column: created_at
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->dateTime('d/m/Y H:i')->sortable()

Column: status
  Component: TextColumn
  Config: ->badge()->sortable()

Column: items_count
  Component: TextColumn
  Config: ->sortable()

Column: classified_count
  Component: TextColumn
  Config: ->sortable()

Column: renamed_count
  Component: TextColumn
  Config: ->sortable()

Column: failed_count
  Component: TextColumn
  Config: ->sortable()->toggleable()

Column: creator.name
  Component: TextColumn
  Config: ->label(__('common.fields.created_by'))->toggleable()

Filter: status
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->options(AttachmentBatchStatus::class)

Filter: created_at
  Component: Filament\Tables\Filters\Filter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
  Config: DatePicker created_from / created_until (padrão ImportBatchesTable)

Filter: trashed
  Component: Filament\Tables\Filters\TrashedFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/trashed
  Config: ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)

recordActions:
  Action: ViewAction
    Component: Filament\Actions\ViewAction

  Action: Classify (url)
    Component: Filament\Actions\Action
    Docs: https://filamentphp.com/docs/5.x/actions/overview
    Location: table row + View header
    Visibility: status === PendingClassification && can('classify', $record)
    Authorization: classify Policy
    Behavior: redirect AttachmentBatchResource::getUrl('classify', ['record' => $record])
    Icon: Heroicon::OutlinedTag (ou equivalente)
    Label: __('attachment_batches.actions.classify')

  Action: ConcludeAttachmentBatchClassificationAction
    Component: Filament\Actions\Action
    Location: View header (+ opcional table se útil)
    Visibility: status === PendingClassification && allItemsClassified()
    Authorization: classify
    Behavior:
      1. requiresConfirmation()
      2. ConcludeAttachmentBatchClassificationAction domain
      3. Notification success
      4. Catch BusinessException → danger + halt

  Action: RetryFailedAttachmentBatchRenamesAction
    Component: Filament\Actions\Action
    Location: View header
    Visibility: status in [PartiallyFailed, Failed] && failed_count > 0 (Failed só se houver itens failed recuperáveis)
    Authorization: classify (Operador/Adm)
    Behavior: re-dispatch RenameAttachmentBatchJob; Notification

  Action: DeleteAction
    Component: Filament\Actions\DeleteAction
    Visibility: Adm only
    Behavior: soft delete via Service/Observer cascade soft items

toolbarActions:
  BulkActionGroup → DeleteBulkAction (Adm)
```

**defaultSort:** `created_at` desc.

### 7.5 RelationManagers

#### Items — `ItemsRelationManager`

```
Command: php artisan make:filament-relation-manager AttachmentBatchResource items sort_order --generate --soft-deletes --panel=admin --no-interaction
Location: App\Filament\Resources\AttachmentBatches\RelationManagers\ItemsRelationManager
Docs: https://filamentphp.com/docs/5.x/resources/managing-relationships
Relationship: items

Read-only na View (classificação é na página Classify):
  headerActions: []
  SEM Create/Edit de destino aqui

Columns:
  sort_order → TextColumn sortable
  attachment.displayName (ou getStateUsing standardized_name ?? original_name) → TextColumn searchable
  attachment.mime_type → TextColumn toggleable
  status → TextColumn badge
  destination_type → TextColumn badge nullable
  paymentRequest.id / reference → TextColumn toggleable
  supplier.name → TextColumn toggleable
  operational_label → TextColumn toggleable
  classified_at → TextColumn dateTime toggleable
  rename_error → TextColumn toggleable (visível se failed)

recordActions:
  DownloadAttachmentBatchItemAction
  (opcional) Action view classifications modal

toolbarActions: []  # sem bulk delete de itens na View MVP (Adm soft via Service se necessário)
defaultSort: sort_order asc
reorderable: NÃO na View — reorder só no Livewire Classify enquanto pending_classification
TrashedFilter: visible Adm
```

#### Classifications — `ClassificationsRelationManager`

```
Command: php artisan make:filament-relation-manager AttachmentBatchResource classifications classified_at --generate --panel=admin --no-interaction
SEM --soft-deletes

Relationship: classifications (HasManyThrough AttachmentBatchItemClassification)
Read-only histórico append-only

Columns:
  classified_at → TextColumn sortable dateTime
  user.name → TextColumn
  attachmentBatchItem.sort_order → TextColumn
  destination_type → TextColumn badge
  paymentRequest.id → TextColumn toggleable
  supplier.name → TextColumn toggleable
  operational_label → TextColumn toggleable

headerActions: []
recordActions: []
toolbarActions: []
defaultSort: classified_at desc
```

### 7.6 Pages

| Page | Notas |
|------|-------|
| `ListAttachmentBatches` | Header `CreateAction` (canCreate Policy) |
| `CreateAttachmentBatch` | `CreateAttachmentBatchAction`; redirect View |
| `ViewAttachmentBatch` | Header: Classify (se pending), Conclude, Retry, Delete (Adm); **poll** enquanto `renaming` / não terminal (espelhar `ViewImportBatch::getPollingInterval` → `'5s'`) |
| `ClassifyAttachmentBatch` | Custom page; authorize `classify`; só se `pending_classification`; embed Livewire §8; header: Conclude + back to View |
| **Sem** `EditAttachmentBatch` | Remover do scaffold |

### 7.7 Actions Filament (detalhe)

#### `ConcludeAttachmentBatchClassificationAction`

```
Component: Filament\Actions\Action
Docs: https://filamentphp.com/docs/5.x/actions/overview
Location: View header + Classify page header
Visibility: status === PendingClassification
Authorization: $user->can('classify', $record)
Behavior:
  1. requiresConfirmation(); modalHeading/i18n
  2. app(ConcludeAttachmentBatchClassificationAction::class)($record, $user)
  3. Notification::success(__('attachment_batches.messages.classification_concluded'))
  4. catch BusinessException → Notification::danger($e->getUserMessage()); $action->halt()
  5. redirect View (job será enfileirado pelo listener)
```

#### `RetryFailedAttachmentBatchRenamesAction`

```
Component: Filament\Actions\Action
Location: View header
Visibility: status PartiallyFailed|Failed com itens failed
Authorization: classify
Behavior: RetryFailedAttachmentBatchRenamesAction domain → re-dispatch job; Notification
```

#### `DownloadAttachmentBatchItemAction`

```
Component: Filament\Actions\Action
Location: Items RM recordActions
Visibility: Operador/Adm + arquivo existe
Authorization: view batch / view attachment
Behavior:
  1. Stream ou temporaryUrl do disk private
  2. Nome de download = attachment.displayName() (standardized_name ?? original_name)
  3. Nunca URL pública permanente
```

---

## 8. Livewire: ClassifyAttachmentBatch

> **Decisão de UI fechada neste blueprint:** Livewire **incluído** (não opcional). RelationManager `reorderable` sozinho **não** cobre preview PDF/imagem + destino condicional + drag-and-drop com feedback para dezenas de arquivos.

### 8.1 Artefatos

| Artefato | Path |
|----------|------|
| Filament Page | `App\Filament\Resources\AttachmentBatches\Pages\ClassifyAttachmentBatch` |
| Livewire | `App\Livewire\Attachment\ClassifyAttachmentBatch` |
| View | `resources/views/livewire/attachment/classify-attachment-batch.blade.php` |

Docs Livewire sort: https://livewire.laravel.com/docs/4.x/sorting · Filament custom pages: https://filamentphp.com/docs/5.x/resources/custom-pages

### 8.2 Página Filament

```
extends Page / ViewRecord-like
static $resource = AttachmentBatchResource::class
mount: abort_unless can('classify') && status PendingClassification
getHeaderActions: ConcludeAttachmentBatchClassificationAction, Action back to View
content: Livewire component com :batch-id="$record->id"
```

### 8.3 Componente Livewire — comportamento

| Feature | Técnica | Descrição |
|---------|---------|-----------|
| Lista itens | eager `items.attachment`, `paymentRequest`, `supplier` | Ordenados por `sort_order` |
| Preview | Island / painel lateral | Imagem (`isImage`) ou PDF embed/link; `#[Lazy]` no preview pesado se necessário |
| Destino | Select + campos condicionais | `destination_type` → PR searchable / Supplier searchable / TextInput label |
| Salvar item | Action domain | `ClassifyAttachmentBatchItemAction` → histórico + item `classified` |
| Reorder | `wire:sort` | `ReorderAttachmentBatchItemsAction` com array de IDs |
| Concluir | Header Filament Action | `ConcludeAttachmentBatchClassificationAction` |

**Campos por item (Livewire form / Filament Forms embutidos — preferir Forms Components se a página Filament hostar schema; senão Alpine+wire:model tipado):**

```
Field: destination_type
  Component: Filament\Forms\Components\Select
  Imports: Filament\Schemas\Components\Utilities\Get, Set
  Config: ->options(AttachmentBatchDestinationType::class)->live()->required()->native(false)

Field: payment_request_id
  Component: Filament\Forms\Components\Select
  Config: ->relationship / searchable options de PaymentRequest
          ->visible(fn (Get $get): bool => $get('destination_type') === AttachmentBatchDestinationType::PaymentRequest->value)
          ->required(fn (Get $get): bool => …)
  Authorization ao classificar: ator pode view a PR alvo

Field: supplier_id
  Component: Filament\Forms\Components\Select
  Config: searchable Suppliers; visible/required quando destination = supplier

Field: operational_label
  Component: Filament\Forms\Components\TextInput
  Config: ->maxLength(120); visible/required quando operational_category
```

**Islands (opcional MVP):** lista vs preview para não re-renderizar tudo.

**Bloqueio:** se batch sair de `pending_classification`, redirect View + Notification.

---

## 9. Authorization / Policies

Docs: https://filamentphp.com/docs/5.x/security/authorization

Registrar em `AppServiceProvider::POLICIES`:

```
AttachmentBatch::class => AttachmentBatchPolicy::class
AttachmentBatchItem::class => AttachmentBatchItemPolicy::class
```

### 9.1 `AttachmentBatchPolicy` (espelhar `ImportBatchPolicy` + classify)

| Método | Regra |
|--------|-------|
| viewAny | `isOperador()` **ou** `isAdm()` |
| view | Operador **ou** Adm |
| create | Operador **ou** Adm |
| update | `false` (sem Edit genérica) — classify é ability custom |
| classify | Operador **ou** Adm |
| delete | `isAdm()` |
| restore | `isAdm()` |
| forceDelete | `isAdm()` |
| deleteAny / forceDeleteAny / restoreAny | `isAdm()` |

**Cliente:** todos `false` / 403.

### 9.2 `AttachmentBatchItemPolicy`

| Método | Regra |
|--------|-------|
| view | herdada: pode view o batch pai |
| update / classify | Operador/Adm + batch `pending_classification` |
| delete | Adm |

### 9.3 `AttachmentPolicy` (estender)

Quando `attachable` é `AttachmentBatch`, `view`/`download` via `$user->can('view', $attachable)`.

---

## 10. State Transitions

### 10.1 Lote — `AttachmentBatchStatus`

```
pending_classification → classified | failed
classified → renaming
renaming → renamed | partially_failed | failed
```

| De | Para | Quem / quando |
|----|------|---------------|
| PendingClassification | Classified | ConcludeAction (todos itens classified) |
| PendingClassification | Failed | Falha estrutural (batch vazio pós-create edge, etc.) |
| Classified | Renaming | Job no início |
| Renaming | Renamed | Todos itens renamed |
| Renaming | PartiallyFailed | ≥1 renamed e ≥1 failed |
| Renaming | Failed | Zero renomeáveis / exceção estrutural / `failed()` do job |
| Renamed / PartiallyFailed / Failed | * | Terminais; **não** reabrir classificação (MVP) |

Service valida `canTransitionTo`; senão exception.

**Itens** podem ser reclassificados **antes** de conclude (cada save = nova linha de histórico).

### 10.2 Item — `AttachmentBatchItemStatus`

```
pending → classified → renamed
                └──→ failed (rename) → renamed (retry ok)
```

**Bloqueio RF028:** Conclude exige **todos** `classified`; senão `batchClassificationIncomplete()`; status do lote **permanece** `pending_classification`; sem event/job.

---

## 11. Events / Notifications / Queue

Namespaces: `App\Events\Attachment\`, `App\Listeners\Attachment\`, `App\Jobs\Attachment\`.

### 11.1 `AttachmentBatchClassified`

```
Payload: public AttachmentBatch $batch
Dispatch: sync após commit de status = classified
Listener: QueueAttachmentBatchRename (sync fino) → RenameAttachmentBatchJob::dispatch($batch)
```

### 11.2 `AttachmentRenamed`

```
Payload: public Attachment $attachment, public AttachmentBatchItem $item
Dispatch: por item após move+DB ok
Listener: LogAttachmentRenamed (sync) — log estruturado; NÃO recria Attachment
```

### 11.3 `AttachmentBatchRenamed`

```
Payload: public AttachmentBatch $batch
Dispatch: SOMENTE quando job termina em status renamed (sucesso total)
NÃO dispara em partially_failed
Listener opcional: NotifyAttachmentBatchRenamed (ShouldQueue) → created_by (mail + database)
```

### 11.4 Job `RenameAttachmentBatchJob`

| Atributo | Valor |
|----------|-------|
| Namespace | `App\Jobs\Attachment\RenameAttachmentBatchJob` |
| Queue | default |
| Tries | 3 |
| Backoff | `[10, 30, 60]` |
| Timeout | 300s |
| Overlap | `WithoutOverlapping($batch->id)` |
| handle | `AttachmentBatchNamingService::renameBatch($batch)` |
| failed | se ainda `renaming` → marcar `failed` + `failure_reason` |
| Idempotência | se já `renamed` → no-op; se `partially_failed` → só itens `failed` |

**Fluxo rename (resumo):**
1. Fixar `naming_generated_at = now()` (timezone app = `America/Sao_Paulo`).
2. Iterar itens por `sort_order` asc.
3. Gerar `YYYYMMDD_HHMMSS_SEQ.ext` (SEQ 001…; padding dinâmico se >999).
4. Extensão via mime map; fallback pathinfo original.
5. Colisão path/DB → `_2`…`_99`; esgotou → item `failed` (`namingCollisionUnresolved`), continua.
6. `Storage::move` + update `path`/`standardized_name` mesma transação DB; falha → item `failed`.
7. Se destino PR → rebind + dual sync.
8. Disparar `AttachmentRenamed` por sucesso.
9. Fechar status lote; se `renamed` → `AttachmentBatchRenamed`.

Registrar `Event::listen` no `AppServiceProvider` (padrão F5).

---

## 12. Traduções (i18n)

Arquivos a criar/estender (pt_BR **e** en):

- `lang/{locale}/attachment_batches.php` (**criar**)
- `lang/{locale}/attachments.php` (**estender** errors + `standardized_name` / display)
- `lang/{locale}/enums.php` (**estender**)

### 12.1 `attachment_batches.php` (pt_BR — espelhar en)

```php
return [
    'label' => 'Lote de anexos',
    'plural' => 'Lotes de anexos',
    'navigation_label' => 'Lote de anexos',

    'fields' => [
        'files' => 'Arquivos',
        'status' => 'Status',
        'items_count' => 'Itens',
        'classified_count' => 'Classificados',
        'renamed_count' => 'Renomeados',
        'failed_count' => 'Falhas',
        'failure_reason' => 'Motivo da falha',
        'classified_at' => 'Classificado em',
        'renaming_started_at' => 'Início da nomenclatura',
        'renamed_at' => 'Nomenclatura concluída em',
        'naming_generated_at' => 'Prefixo de data/hora',
        'sort_order' => 'Ordem',
        'destination_type' => 'Destino',
        'payment_request_id' => 'Solicitação de pagamento',
        'supplier_id' => 'Fornecedor',
        'operational_label' => 'Categoria operacional',
        'rename_error' => 'Erro de nomenclatura',
        'display_name' => 'Nome',
        'mime_type' => 'Tipo MIME',
    ],

    'sections' => [
        'upload' => 'Upload de arquivos',
        'status' => 'Status do lote',
        'items' => 'Itens',
        'classifications' => 'Histórico de classificação',
        'classify' => 'Classificar anexos',
        'preview' => 'Pré-visualização',
    ],

    'actions' => [
        'classify' => 'Classificar',
        'conclude_classification' => 'Concluir classificação',
        'retry_failed_renames' => 'Retentar falhas de nomenclatura',
        'download' => 'Baixar',
        'save_classification' => 'Salvar classificação',
    ],

    'messages' => [
        'created' => 'Lote de anexos criado.',
        'classification_saved' => 'Classificação salva.',
        'classification_concluded' => 'Classificação concluída. Nomenclatura enfileirada.',
        'reordered' => 'Ordem atualizada.',
        'retry_queued' => 'Retentativa de nomenclatura enfileirada.',
        'renamed' => 'Nomenclatura do lote concluída.',
        'partially_failed' => 'Nomenclatura concluída com falhas parciais.',
    ],

    'hints' => [
        'upload' => 'PDF, JPEG, PNG ou WebP. Mesmos limites dos anexos da solicitação.',
        'operational_label' => 'Rótulo livre operacional (sem catálogo nesta fase).',
        'seq_order' => 'A ordem define o SEQ da nomenclatura (001, 002, …).',
    ],
];
```

### 12.2 Extensões em `attachments.php` → `errors`

```php
'errors' => [
    // existentes…
    'batch_classification_incomplete' => 'Classifique todos os itens antes de concluir.',
    'batch_not_classifiable' => 'Este lote não está pendente de classificação.',
    'invalid_destination' => 'Destino de classificação inválido ou incompleto.',
    'naming_collision_unresolved' => 'Não foi possível gerar um nome único para o arquivo.',
    'storage_move_failed' => 'Falha ao mover o arquivo no armazenamento.',
    'batch_empty' => 'Envie ao menos um arquivo para criar o lote.',
    'unauthorized_batch_operation' => 'Você não tem permissão para esta operação no lote de anexos.',
],

'fields' => [
    // existentes…
    'standardized_name' => 'Nome padronizado',
],
```

### 12.3 Adds em `enums.php`

```php
'attachment_batch_status' => [
    'pending_classification' => 'Pendente de classificação',
    'classified' => 'Classificado',
    'renaming' => 'Renomeando',
    'renamed' => 'Renomeado',
    'partially_failed' => 'Falhas parciais',
    'failed' => 'Falhou',
],

'attachment_batch_item_status' => [
    'pending' => 'Pendente',
    'classified' => 'Classificado',
    'renamed' => 'Renomeado',
    'failed' => 'Falhou',
],

'attachment_batch_destination_type' => [
    'payment_request' => 'Solicitação de pagamento',
    'supplier' => 'Fornecedor',
    'operational_category' => 'Categoria operacional',
],
```

**Regra:** todos os labels no Form, Table, Actions e Livewire usam `__()`. Espelhar chaves em `lang/en/`.

---

## 13. API REST

> **Não aplicável nesta fase.** Assunção igual F1–F5: painel Filament interno BPO. Sem Sanctum/Passport/Swagger/endpoints. Não criar rotas `api/v1/*` para lotes de anexos.

---

## 14. Soft deletes, cascade e guards

> Matriz DBA (arquitetura §11 / §21.5 / §24) — copiar e obedecer.

| Entidade | SoftDeletes | Notas |
|----------|:-----------:|-------|
| AttachmentBatch | Sim | Contadores congelados; cascade soft de items **só** via app |
| AttachmentBatchItem | Sim | Unique parcial `attachment_id` |
| AttachmentBatchItemClassification | **Não** | Append-only; hard-owned pelo item |
| Attachment | Sim (F3) | Arquivo físico só no `forceDeleted` (Observer F3) |

**Regra crítica:** `cascadeOnDelete` / `restrictOnDelete` / `nullOnDelete` aplicam-se a **hard/forceDelete**. Soft-delete **nunca** dispara FK cascade.

| Evento | FK | Aplicação |
|--------|-----|-----------|
| Soft delete **batch** | Nada | Observer/Service: soft-deleta **items**; Attachments ainda no batch → soft-deleta; Attachments já em PR → **não** toca |
| Soft delete **item** | Nada | Classifications **permanecem**; Attachment: se attachable=batch → soft; se PR → **mantém** |
| Soft delete **Attachment** | Nada (restrict não dispara) | Evitar via Service se item ativo referencia |
| ForceDelete **batch** | Items cascade hard | Classifications saem com item; Attachments: lógica app (batch vs PR) + Observer storage |
| ForceDelete **item** | Classifications cascade (OK — filha sem SoftDeletes) | Attachment: batch → force; PR → não |
| ForceDelete **Attachment** | `restrictOnDelete` bloqueia se item ainda existe | Force item/batch antes |

**Rebind / `has_attachments`:** um único Attachment; após rebind sync dual (PR + batch no-op). Soft delete do Attachment na PR continua atualizando `payment_requests.has_attachments` (F3).

**Pruning:** adiado (retenção financeira), igual F3.

---

## 15. Factories & Seeders

| Factory | States úteis |
|---------|--------------|
| `AttachmentBatchFactory` | `pendingClassification()`, `classified()`, `renaming()`, `renamed()`, `partiallyFailed()`, `failed()` |
| `AttachmentBatchItemFactory` | `pending()`, `classified()`, `forPaymentRequest()`, `forSupplier()`, `operationalCategory()`, `renamed()`, `failed()` |
| `AttachmentBatchItemClassificationFactory` | definition mínima (`classified_at` = now) |
| `AttachmentFactory` | Estender `withStandardizedName()`, `forBatch(AttachmentBatch)` |

**Seeder:** não obrigatório em produção; demo opcional com 1 lote pending.

Toda Model nova com `--factory`.

---

## 16. Tests (Pest)

Docs: https://filamentphp.com/docs/5.x/testing/overview  
Skill: `testing-best-practices` antes de escrever.

> Ambiente: `docker compose exec app php artisan test --compact --filter=AttachmentBatch`  
> Helpers: `Storage::fake`, `Queue::fake`, `Event::fake`

### 16.1 Feature — RF027 (upload)

| # | Caso |
|---|------|
| T01 | Upload rejeita MIME inválido (ex. XML / octet-stream) |
| T02 | Upload rejeita arquivo > `max_kilobytes` |
| T03 | Create batch cria N attachments morph→batch + items + status `pending_classification` + contadores |
| T04 | Create com zero arquivos → `batchEmpty` / validação |

### 16.2 Feature — RF028 (classificação)

| # | Caso |
|---|------|
| T05 | Reorder altera `sort_order` e SEQ resultante no rename |
| T06 | Classify grava destino + linha de histórico; reclassify adiciona **nova** linha |
| T07 | Destino inconsistente → `invalidDestination` |
| T08 | Conclude incompleto lança / não muda status / não despacha job |
| T09 | Conclude completo → event `AttachmentBatchClassified` + job enfileirado |

### 16.3 Feature — RF029 (nomenclatura)

| # | Caso |
|---|------|
| T10 | Nomes `YYYYMMDD_HHMMSS_SEQ.ext` com SEQ pela ordem; timezone SP; `original_name` preservado |
| T11 | Colisão simula `exists` → sufixo `_2` |
| T12 | Destino PR: após rename, `attachable` é PR; **um** Attachment; path sob `payment_request/{id}/`; `has_attachments` sync |
| T13 | Destino Supplier/OperationalCategory: Attachment permanece no batch |
| T14 | Falha de move marca item `failed` e batch `partially_failed` |
| T15 | Job idempotente se batch já `renamed` |
| T16 | Retry reprocessa só itens `failed` |
| T17 | `AttachmentBatchRenamed` só em sucesso total (não em partially_failed) |

### 16.4 Authorization / Filament

| # | Caso |
|---|------|
| T18 | Policy: Cliente não `viewAny` / não `classify` |
| T19 | Operador classifica e cria; Adm também; delete só Adm |
| T20 | AttachmentBatchResource: list/create Operador; Cliente forbidden |
| T21 | Classify page 403 se não Operador/Adm ou status ≠ pending |

### 16.5 Schema / DBA notes — `AttachmentBatchSchemaConstraintsTest`

| # | Caso |
|---|------|
| T22 | Unique parcial `attachment_id` — dois ativos falham; soft-deleted item libera reuse do mesmo attachment_id |
| T23 | Soft batch **não** apaga Attachment já rebound na PR |
| T24 | Soft item mantém classifications; force item remove classifications (cascade) |
| T25 | Classifications **sem** `updated_at` / Model não tenta gravar updated_at |
| T26 | Alter `standardized_name` sem quebrar morph F3; sem unique/índice obrigatório |
| T27 | Lote `status` string(30) aceita `pending_classification` (23) sem truncar |

### 16.6 Unit

| # | Caso |
|---|------|
| T28 | `AttachmentBatchStatus::canTransitionTo` matriz |
| T29 | Mapa mime→ext |
| T30 | Gerador de nome com Carbon freeze (`America/Sao_Paulo`) |

---

## 17. Estimativa

| Componente | Complexidade | Tempo estimado |
|------------|--------------|----------------|
| Enums + Exception + i18n | Baixa | 45 min |
| Migrations + Models + Factories + morph map | Média–Alta | 1h45 |
| AttachmentService extend + Batch/Classification/Naming Services | Alta | 3h |
| Actions domain | Média | 1h |
| Job + Events + Listeners (+ notify opcional) | Média | 1h |
| Observer soft cascade + Policies | Média | 45 min |
| AttachmentBatchResource (Form/Table/Infolist/RMs/Actions/poll) | Alta | 2h |
| Página Classify + Livewire (preview, destino, sort) | Alta | 2h30 |
| Testes Pest (§16) | Alta | 2h30 |
| Pint + ajustes | Baixa | 30 min |
| **Total** | — | **~15–17 h** |

---

## 18. Notas e checklist pré-implementação

### 18.1 Checklist

- [ ] Ler este blueprint inteiro + arquitetura §21/§24 se dúvida de schema
- [ ] Enums + i18n (`attachment_batches`, `attachments.errors`, `enums`)
- [ ] Migrations §3 (alter sem `after()`; unique parcial `attachment_id`; classifications append-only)
- [ ] Models + `UPDATED_AT = null` em Classification + `HasAttachments` no Batch + `displayName` no Attachment
- [ ] Morph map `attachment_batch`
- [ ] Estender `AttachmentException` + Services + Actions
- [ ] Events/Listeners/Job (`WithoutOverlapping`, tries 3, timeout 300)
- [ ] Observer soft cascade
- [ ] Policies + `AppServiceProvider::POLICIES` + estender `AttachmentPolicy`
- [ ] Filament Resource §7 (pasta plural, final, limpo, infolist, recordActions/toolbarActions, Heroicon enum, `->live()`, sort 16, group operations)
- [ ] Livewire Classify §8
- [ ] Pest §16 (inclui T22–T27 DBA)
- [ ] Pint: `vendor/bin/pint --dirty --format agent`
- [ ] Checklists `.ai/checklists.md` (Model, Migration, Job, Event, Policy, Factory)

### 18.2 Notas para implementer (DBA §24 — obrigatórias)

1. Copiar helper `supportsPartialIndexes()` de migrations F1–F5.
2. Unique parcial: `CREATE UNIQUE INDEX … WHERE deleted_at IS NULL` + `DROP INDEX IF EXISTS` no `down()` — **nunca** `$table->unique('attachment_id')`.
3. Model `AttachmentBatchItemClassification`: `public $timestamps = false;` + `public const UPDATED_AT = null;` — preencher `classified_at` no Service.
4. Soft-delete batch → soft items; Attachments só se `attachable` = batch; **nunca** soft-deletar Attachment já rebound à PR.
5. ForceDelete Attachment com item presente → `restrictOnDelete` bloqueia; force na ordem batch→items.
6. Alter `standardized_name`: **sem** `after()`; **sem** unique/índice.
7. Destino coerente: assert no Service — **sem** CHECK SQL.
8. Pós-rebind: sync `has_attachments` na PR (e no-op no batch sem coluna) — **um único** Attachment.
9. Contadores: comentário inglês na migration; fonte de verdade = `items()`.
10. Índice composto `(attachment_batch_id, sort_order)` apenas; **não** indexar `sort_order` sozinho nem `standardized_name`.
11. Tamanhos string: lote status **30**, item status **20**, destination **30**, standardized_name **255**, operational_label **120**, rename_error **500**.
12. FKs com `->index()` explícito.
13. Não criar `$table->enum()`.
14. Espelhar Resource `ImportBatchResource` (canViewAny, visibleTo, poll, sem Edit).
15. FileUpload: `storeFiles(false)`, disk/MIME/size/maxFiles de `config('rjet.attachments.*')`.

### 18.3 Widgets

**Nenhum** nesta fase.

### 18.4 Decisões de UI fechadas neste blueprint (além da arquitetura)

| # | Decisão |
|---|----------|
| U1 | Livewire Classify **obrigatório** (não rebaixado a opcional) |
| U2 | Items RM na View é **read-only** para classificação; reorder só no Livewire |
| U3 | Classifications RM via `HasManyThrough` no batch (histórico global do lote) |
| U4 | Polling View `5s` enquanto status não terminal (padrão ImportBatch) |
| U5 | Download usa `displayName()` (`standardized_name ?? original_name`) |
| U6 | Campos destino condicionais com `Get` + `->live()` (nunca `->reactive()`) |
| U7 | `Schema::columns(1)` + Section `columns(2)` (padrão F5) |
| U8 | Navigation sort **16**, ícone `Heroicon::OutlinedPaperClip`, group operations |

---

## 19. Próximos passos

1. Revisar este blueprint (especialmente U1 Livewire, rebind PR, unique parcial).
2. Encaminhar ao **`implementer`** na ordem do §2 / checklist §18.
3. Após implementação: `tester` cobre §16; `reviewer` / `security` conforme pipeline do projeto.
4. Não iniciar F7/F8 nesta entrega.

---

## Apêndice A — Formato obrigatório Blueprint (lembrete ao implementer)

Todo Field/Column/Filter/Action no plano acima inclui (quando aplicável):

- **Field:** Component (namespace completo), Docs (URL), Validation, Config  
- **Column:** Component, Docs, Config  
- **Action:** Component, Docs, Location, Visibility, Authorization, Behavior  
- **Filter:** Component, Docs, Config  
- **Reactive:** Imports `Get`/`Set`, usar `->live()` **nunca** `->reactive()`

Referência oficial: `vendor/filament/blueprint/resources/markdown/planning/overview.md` (já incorporada neste documento).  
Padrão de código real: `ImportBatchResource`, `AttachmentsRelationManager`, `AttachmentService`, `config('rjet.attachments.*')`.

---

## Apêndice B — Nomenclatura (referência rápida)

| Aspecto | Valor |
|---------|-------|
| Padrão | `{YYYYMMDD}_{HHMMSS}_{SEQ}.{ext}` |
| Fonte data/hora | `naming_generated_at` no **início** do job (`America/Sao_Paulo`) |
| SEQ | 1-based por `sort_order`; zero-pad 3; dinâmico se >999 |
| Extensão | mime map; fallback `pathinfo(original_name)` lowercased |
| Colisão | `_2` … `_99` antes da extensão; depois `namingCollisionUnresolved` |
| Disco | `config('rjet.attachments.disk')` private |
| `original_name` | imutável |

---

*Fim do Filament Blueprint — Fase 6. Pronto para `implementer`.*
