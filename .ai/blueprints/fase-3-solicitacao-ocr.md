# Filament Blueprint: Fase 3 — Solicitação de Pagamento e OCR

> **Tipo:** Plano de implementação (Filament Blueprint v5). **NÃO é código de produção.**
> **Escopo:** RF012–RF018 — `PaymentRequest` (core), `PaymentRequestBankDetails` (1:1), `PaymentRequestStatusHistory` (append-only), `Attachment` (morph) + `HasAttachments`, OCR local **PDF-only** de boleto, escopo de visibilidade por perfil.
> **Fonte de requisitos:** [.ai/arquitetura/fase-3-solicitacao-ocr.md](../arquitetura/fase-3-solicitacao-ocr.md) (decisões BA §20 fechadas · revisão DBA §25 aprovada).
> **Stack:** Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL · Redis · S3 (produção).
> **Autor:** architect (subagent) · **Data:** 2026-07-29
> **Destinatário:** `implementer` — este documento é a **única** fonte que o implementador verá. Todas as regras relevantes das guidelines foram copiadas para dentro dele.

---

## Índice

1. [Visão Geral](#1-visão-geral)
2. [Commands (ordem de execução)](#2-commands-ordem-de-execução)
3. [Models e Migrations](#3-models-e-migrations)
4. [Enums](#4-enums)
5. [Exceções de Domínio](#5-exceções-de-domínio)
6. [Camadas de Aplicação](#6-camadas-de-aplicação)
7. [Filament Resource: PaymentRequest](#7-filament-resource-paymentrequest)
8. [Update: CompanyResource](#8-update-companyresource)
9. [Authorization / Policies](#9-authorization--policies)
10. [State Transitions](#10-state-transitions)
11. [Events / Jobs / Notifications](#11-events--jobs--notifications)
12. [Traduções (i18n)](#12-traduções-i18n)
13. [API REST](#13-api-rest)
14. [Soft deletes, cascade e storage](#14-soft-deletes-cascade-e-storage)
15. [Factories & Seeders](#15-factories--seeders)
16. [Tests (Pest)](#16-tests-pest)
17. [Estimativa](#17-estimativa)
18. [Notas e checklist pré-implementação](#18-notas-e-checklist-pré-implementação)
19. [Próximos passos](#19-próximos-passos)

---

## 0. Regras globais copiadas das guidelines (LEIA PRIMEIRO)

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
| Ícones | `Filament\Support\Icons\Heroicon` (**enum**, nunca string) |
| Schema container | `Filament\Schemas\Schema` |
| Table container | `Filament\Tables\Table` |
| Notificações | `Filament\Notifications\Notification` |
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

### 0.2 Estrutura obrigatória do Resource (pasta PLURAL, Resource LIMPO)

```
app/Filament/Resources/PaymentRequests/          ← PLURAL
├── PaymentRequestResource.php                   ← final, LIMPO: só delegates
├── Schemas/PaymentRequestForm.php               ← final
├── Schemas/PaymentRequestInfolist.php           ← final (SEMPRE, não é opcional)
├── Tables/PaymentRequestsTable.php              ← final, nome PLURAL
├── Pages/{Create,Edit,List,View}PaymentRequest.php
├── RelationManagers/{Attachments,StatusHistories}RelationManager.php
└── Actions/{TransitionStatus,ExtractBoletoOcr,DownloadAttachment,DeletePaymentRequest}Action.php
```

**VALIDAÇÃO:** se `PaymentRequestResource.php` contiver `TextInput`, `TextColumn`, `Section`, `Select`, `Toggle` ou qualquer componente de form/table/layout diretamente, **está errado**. Todas as classes são `final` e têm `declare(strict_types=1);`.

### 0.3 Layout — largura efetiva das colunas (crítico)

Colunas multiplicam ao aninhar. Cálculo obrigatório antes de definir `columns()`:

| Colunas do Form | Colunas da Section | Largura efetiva | OK? |
|---|---|---|---|
| 2 | 2 | 25% | **NÃO** |
| 2 | 1 (default) | 50% | Sim |
| **1** | **2** | **50%** | **Sim ← padrão desta fase** |
| 1 | 1 | 100% | Sim (campos largos) |

**Decisão desta fase:** `Schema::columns(1)` no Form e no Infolist; cada `Section` com `->columns(2)`. A Section de liquidação é **aninhada** dentro do Bloco 3, que tem `->columns(1)` → filha com `->columns(2)` = 50% efetivo. ✅

### 0.4 i18n (obrigatório)

Nenhuma string de UI hardcoded. Todo label/heading/placeholder/helperText/notificação usa `__()`. Campos comuns em `common.php`; específicos em `payment_requests.php` etc. Arquivos em **`lang/pt_BR/`** e **`lang/en/`** (ambos obrigatórios).

### 0.5 Padrões do projeto (F1/F2 já implementados — seguir sem desviar)

- Models de domínio: `final`, `declare(strict_types=1)`, traits `HasFactory, HasUuid, SoftDeletes, HasBlameable`, `casts()` como **método**, PK `uuid`.
- Migrations: `timestampsTz()` + `softDeletesTz()`; FK com `->index()` explícito; **enum sempre `string`**, nunca `$table->enum()`.
- Unique parcial: **nunca** `->unique()` na coluna. Sempre `DB::statement` dentro de `if ($this->supportsPartialIndexes())`, com `DROP INDEX IF EXISTS` no `down()`.
- Policies: leitura via `$user->role->seesAllBranches()`; escrita/exclusão via `$user->isAdm()`. Registrar na constante `AppServiceProvider::POLICIES`.
- Guards de exclusão: método `ensureDeletable()` no Service + `DeleteAction::make()->before(...)` no Filament, capturando `BusinessException` → `Notification::danger($e->getUserMessage())` → `$action->halt()`.
- Visibilidade: **query scope** `scopeVisibleTo(Builder, User)` (não global scope) + `getEloquentQuery()` no Resource — padrão idêntico a `Branch` / `BranchResource`.
- `Model::preventLazyLoading()` está ativo fora de produção → **eager loading obrigatório** em tabelas e infolists.

---

## 1. Visão Geral

A Fase 3 entrega o núcleo do domínio financeiro:

| Entrega | Descrição |
|---------|-----------|
| `PaymentRequest` | Cabeçalho da solicitação; ciclo `Requested → Launched → Settled`; filial automática/multi-filial; valor líquido persistido |
| `PaymentRequestBankDetails` | Dados de liquidação 1:1 (boleto · Pix chave/QR · transferência TED/DOC/conta) |
| `PaymentRequestStatusHistory` | Trilha append-only de transições (sem SoftDeletes, sem `updated_at`) |
| `Attachment` + `HasAttachments` | Anexos morph em disco **private** (S3 em produção); PDF/JPEG/PNG/WEBP; máx. 10 MB |
| OCR local | `LocalBoletoOcrClient` — **PDF text parser + validador de linha digitável**; **sem Tesseract**, **sem cloud**; síncrono; falha não bloqueia |
| Escopo RF018 | Cliente vê apenas filiais vinculadas; Operador/Adm veem tudo |
| `companies.is_appropriation_required` | Flag por empresa que torna `appropriation_id` obrigatório na solicitação |
| Filament | `PaymentRequestResource` (form 3 blocos reativo, table filtrada, infolist, 2 RelationManagers, actions custom) + Toggle novo em `CompanyResource` |

**Fora de escopo (não implementar):** API REST, Widgets, Jobs obrigatórios (OCR é síncrono), componentes Livewire custom, `ApprovalRule`/workflow (F4), lote (F5/F6), CNAB (F7), dashboard (F8), OCR de imagem/QR, pruning.

---

## 2. Commands (ordem de execução)

> Ambiente Docker (`PROJECT.md`): prefixar tudo com `docker compose exec app`.

```bash
# ── 2.1 Enums (make:class + editar) ────────────────────────────────────────────
php artisan make:class Enums/PaymentRequestStatus --no-interaction
php artisan make:class Enums/DepositType --no-interaction
php artisan make:class Enums/PixKeyType --no-interaction
php artisan make:class Enums/AttachmentType --no-interaction
# Trocar `final class X` por `enum X: string implements HasLabel, HasColor, HasIcon`

# ── 2.2 Config ────────────────────────────────────────────────────────────────
# Criar config/rjet.php manualmente (conteúdo em §6.7)

# ── 2.3 Migrations (ORDEM OBRIGATÓRIA) ────────────────────────────────────────
php artisan make:migration add_is_appropriation_required_to_companies_table --table=companies --no-interaction
php artisan make:migration create_payment_requests_table --create=payment_requests --no-interaction
php artisan make:migration create_payment_request_bank_details_table --create=payment_request_bank_details --no-interaction
php artisan make:migration create_payment_request_status_history_table --create=payment_request_status_history --no-interaction
php artisan make:migration create_attachments_table --create=attachments --no-interaction

# ── 2.4 Models + Factories ────────────────────────────────────────────────────
php artisan make:model PaymentRequest --factory --no-interaction
php artisan make:model PaymentRequestBankDetails --factory --no-interaction
php artisan make:model PaymentRequestStatusHistory --factory --no-interaction
php artisan make:model Attachment --factory --no-interaction
php artisan make:class Models/Concerns/HasAttachments --no-interaction   # trait: trocar `class` por `trait`

# ── 2.5 Observers ─────────────────────────────────────────────────────────────
php artisan make:observer PaymentRequestObserver --model=PaymentRequest --no-interaction
php artisan make:observer AttachmentObserver --model=Attachment --no-interaction
# CompanyObserver: ESTENDER o existente (não recriar)

# ── 2.6 Exceptions / DTOs / Services / Actions / Integrations / Events ────────
php artisan make:class Exceptions/PaymentRequestException --no-interaction
php artisan make:class Exceptions/AttachmentException --no-interaction
php artisan make:class DTOs/PaymentRequestData --no-interaction
php artisan make:class DTOs/PaymentRequestBankDetailsData --no-interaction
php artisan make:class DTOs/BoletoOcrResult --no-interaction
php artisan make:class Services/PaymentRequestService --no-interaction
php artisan make:class Services/AttachmentService --no-interaction
php artisan make:class Actions/PaymentRequest/ExtractBoletoDataAction --no-interaction
php artisan make:class Actions/PaymentRequest/ResolveSupplierPaymentMethodAction --no-interaction
php artisan make:class Integrations/Ocr/BoletoOcrClient --no-interaction   # interface: trocar `class` por `interface`
php artisan make:class Integrations/Ocr/LocalBoletoOcrClient --no-interaction
php artisan make:class Integrations/Ocr/NullBoletoOcrClient --no-interaction
php artisan make:class Rules/ValidCpfOrCnpj --no-interaction
php artisan make:class Rules/ValidDigitableLine --no-interaction
php artisan make:event PaymentRequest/PaymentRequestCreated --no-interaction
php artisan make:event PaymentRequest/PaymentRequestStatusChanged --no-interaction

# ── 2.7 Policies ──────────────────────────────────────────────────────────────
php artisan make:policy PaymentRequestPolicy --model=PaymentRequest --no-interaction
php artisan make:policy AttachmentPolicy --model=Attachment --no-interaction
php artisan make:policy PaymentRequestStatusHistoryPolicy --model=PaymentRequestStatusHistory --no-interaction

# ── 2.8 Filament Resource (SEMPRE via artisan, NUNCA manual) ─────────────────
php artisan make:filament-resource PaymentRequest --generate --soft-deletes --view --panel=admin --no-interaction

# ── 2.9 Relation Managers ─────────────────────────────────────────────────────
php artisan make:filament-relation-manager PaymentRequestResource attachments original_name --generate --soft-deletes --panel=admin --no-interaction
php artisan make:filament-relation-manager PaymentRequestResource statusHistories to_status --generate --panel=admin --no-interaction

# ── 2.10 Seeder ───────────────────────────────────────────────────────────────
php artisan make:seeder PaymentRequestSeeder --no-interaction

# ── 2.11 Testes ───────────────────────────────────────────────────────────────
php artisan make:test --pest --unit PaymentRequestStatusTest --no-interaction
php artisan make:test --pest --unit NetAmountCalculationTest --no-interaction
php artisan make:test --pest --unit DigitableLineParserTest --no-interaction
php artisan make:test --pest PaymentRequestServiceTest --no-interaction
php artisan make:test --pest PaymentRequestBankDetailsValidationTest --no-interaction
php artisan make:test --pest PaymentRequestStatusTransitionTest --no-interaction
php artisan make:test --pest AttachmentUploadTest --no-interaction
php artisan make:test --pest BoletoOcrTest --no-interaction
php artisan make:test --pest PaymentRequestCascadeTest --no-interaction
php artisan make:test --pest PaymentRequestAuthorizationTest --no-interaction
php artisan make:test --pest PaymentRequestResourceTest --no-interaction
php artisan make:test --pest PaymentRequestSchemaTest --no-interaction

# ── 2.12 Qualidade ────────────────────────────────────────────────────────────
php artisan migrate
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

> ⚠️ **Aprovação necessária antes de rodar:** `composer require smalot/pdfparser` (parser PDF do OCR). `AGENTS.md` proíbe alterar dependências sem aprovação. Ver §18.1.

---

## 3. Models e Migrations

### 3.1 Update Model existente: `Company`

```
Migration: add_is_appropriation_required_to_companies_table
  Table: companies
  Add: is_appropriation_required (boolean, default:false, NOT NULL)
  Index: NENHUM  ← decisão DBA #2: flag de configuração lida via Company já hidratada; baixa cardinalidade
```

```php
// up()
Schema::table('companies', function (Blueprint $table): void {
    $table->boolean('is_appropriation_required')->default(false);
});

// down()
Schema::table('companies', function (Blueprint $table): void {
    $table->dropColumn('is_appropriation_required');
});
```

**Model `Company` — alterações:**
- `casts()`: adicionar `'is_appropriation_required' => 'boolean'`.
- Novo relacionamento: **nenhum** (PaymentRequest chega via `branch`).
- `CompanyObserver`: estender cascade (§14.2).

### 3.2 Update Models existentes: relacionamentos inversos

| Model | Adicionar |
|-------|-----------|
| `Branch` | `paymentRequests(): HasMany` → `hasMany(PaymentRequest::class)` |
| `Supplier` | `paymentRequests(): HasMany` |
| `CostCenter` | `paymentRequests(): HasMany` |
| `Appropriation` | `paymentRequests(): HasMany` |
| `Bank` | `paymentRequestBankDetails(): HasMany` → `hasMany(PaymentRequestBankDetails::class)` |

### 3.3 Novo Model: `PaymentRequest`

```
Model: PaymentRequest
  Table: payment_requests
  Attributes:
    - id: uuid, primary
    - branch_id: uuid, foreign(branches.id), required, index, cascadeOnDelete
    - supplier_id: uuid, foreign(suppliers.id), required, index, restrictOnDelete
    - cost_center_id: uuid, foreign(cost_centers.id), required, index, restrictOnDelete
    - appropriation_id: uuid, foreign(appropriations.id), nullable, index, nullOnDelete
    - payment_method: string(20), required, index          → PaymentMethod
    - status: string(20), required, default:requested, index → PaymentRequestStatus
    - gross_amount: decimal(10,2), required
    - discount_amount: decimal(10,2), required, default:0
    - net_amount: decimal(10,2), required                   ← DESNORMALIZADO (documentar na migration)
    - due_date: date, required, index
    - notes: text, nullable
    - has_attachments: boolean, required, default:false, index ← DESNORMALIZADO (cache do Observer)
    - created_by: uuid, foreign(users.id), nullable, index, nullOnDelete
    - updated_by: uuid, foreign(users.id), nullable, index, nullOnDelete
    - created_at: timestampTz
    - updated_at: timestampTz
    - deleted_at: timestampTz, nullable
  Relationships:
    - belongsTo: Branch via branch_id
    - belongsTo: Supplier via supplier_id
    - belongsTo: CostCenter via cost_center_id
    - belongsTo: Appropriation via appropriation_id
    - hasOne: PaymentRequestBankDetails via payment_request_id   → bankDetails()
    - hasMany: PaymentRequestStatusHistory via payment_request_id → statusHistories()
    - morphMany: Attachment via attachable (trait HasAttachments) → attachments()
    - belongsTo: User via created_by (creator) / updated_by (editor) — via HasBlameable
  Traits:
    - HasFactory, HasUuid, SoftDeletes, HasBlameable, HasAttachments
  Observer:
    - #[ObservedBy(PaymentRequestObserver::class)]
```

**Migration (copiar literalmente — schema aprovado pelo DBA):**

```php
Schema::create('payment_requests', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('branch_id')->index()->constrained('branches')->cascadeOnDelete();
    $table->foreignUuid('supplier_id')->index()->constrained('suppliers')->restrictOnDelete();
    $table->foreignUuid('cost_center_id')->index()->constrained('cost_centers')->restrictOnDelete();
    $table->foreignUuid('appropriation_id')->nullable()->index()->constrained('appropriations')->nullOnDelete();
    $table->string('payment_method', 20)->index();
    $table->string('status', 20)->default('requested')->index();
    $table->decimal('gross_amount', 10, 2);
    $table->decimal('discount_amount', 10, 2)->default(0);
    // Denormalized: net amount is persisted for listings/CNAB; recalculated by PaymentRequestService.
    $table->decimal('net_amount', 10, 2);
    $table->date('due_date')->index();
    $table->text('notes')->nullable();
    // Denormalized: attachment existence cache, synced by AttachmentObserver.
    $table->boolean('has_attachments')->default(false)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});
```

**`casts()`:**

```php
protected function casts(): array
{
    return [
        'payment_method' => PaymentMethod::class,
        'status' => PaymentRequestStatus::class,
        'gross_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'due_date' => 'date',
        'has_attachments' => 'boolean',
    ];
}
```

**Scopes:**

| Scope | Assinatura | Comportamento |
|-------|-----------|---------------|
| `scopeVisibleTo` | `(Builder $query, User $user): Builder` | Se `$user->role->seesAllBranches()` → retorna `$query` sem filtro; senão `$query->whereHas('branch.users', fn (Builder $q) => $q->whereKey($user->getKey()))` — **mesmo padrão de `Branch::scopeVisibleTo`** |
| `scopeStatus` | `(Builder $query, PaymentRequestStatus $status): Builder` | `where('status', $status)` |
| `scopeDueBetween` | `(Builder $query, ?string $from, ?string $until): Builder` | `when($from, whereDate('due_date','>=',...))->when($until, whereDate('due_date','<=',...))` |
| `scopeForBranch` | `(Builder $query, Branch\|string $branch): Builder` | `where('branch_id', $branch instanceof Branch ? $branch->getKey() : $branch)` |

**Helpers (métodos públicos):**

| Método | Retorno | Regra |
|--------|---------|-------|
| `company()` | `?Company` | `$this->branch?->company` |
| `requiresAppropriation()` | `bool` | `$this->company()?->is_appropriation_required ?? false` |
| `isEditableBy(User $user)` | `bool` | Matriz §9.2 — Adm: sempre; Operador: `status` ∈ {Requested, Launched}; Cliente: `status === Requested` **E** filial vinculada (`$user->branches()->whereKey($this->branch_id)->exists()`) |
| `isVisibleTo(User $user)` | `bool` | `$user->role->seesAllBranches() \|\| $user->branches()->whereKey($this->branch_id)->exists()` |
| **`static requiresAppropriationForBranch(?string $branchId)`** | `bool` | Usado pelo Form reativo. Implementar com memoização por request: `once(fn () => Branch::query()->with('company')->find($branchId)?->company?->is_appropriation_required ?? false)`. Retorna `false` se `$branchId` for `null`/branch inexistente. |

### 3.4 Novo Model: `PaymentRequestBankDetails`

```
Model: PaymentRequestBankDetails
  Table: payment_request_bank_details
  Attributes:
    - id: uuid, primary
    - payment_request_id: uuid, foreign(payment_requests.id), required, index, cascadeOnDelete
      ⚠️ SEM ->unique() na coluna — unique parcial via DB::statement (abaixo)
    - deposit_type: string(20), nullable, index          → DepositType (pix | transfer)
    - pix_key_type: string(20), nullable                 → PixKeyType
    - pix_key: string(100), nullable
    - pix_qr_code: text, nullable                        ← SEM índice (decisão DBA #3)
    - digitable_line: string(54), nullable
    - barcode: string(48), nullable
    - bank_id: uuid, foreign(banks.id), nullable, index, nullOnDelete
    - agency: string(10), nullable
    - agency_digit: string(2), nullable
    - account_number: string(20), nullable
    - account_digit: string(2), nullable
    - account_type: string(20), nullable                  → AccountType
    - holder_name: string(150), nullable
    - holder_document: string(20), nullable               ← SOMENTE DÍGITOS (CPF 11 / CNPJ 14)
    - created_by / updated_by: uuid, nullable, index, nullOnDelete
    - created_at / updated_at: timestampTz
    - deleted_at: timestampTz, nullable
  Relationships:
    - belongsTo: PaymentRequest via payment_request_id
    - belongsTo: Bank via bank_id
  Traits:
    - HasFactory, HasUuid, SoftDeletes, HasBlameable
```

**Migration:**

```php
Schema::create('payment_request_bank_details', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    // NOT ->unique(): partial unique index below keeps 1:1 while allowing soft-deleted rows.
    $table->foreignUuid('payment_request_id')->index()->constrained('payment_requests')->cascadeOnDelete();
    $table->string('deposit_type', 20)->nullable()->index();
    $table->string('pix_key_type', 20)->nullable();
    $table->string('pix_key', 100)->nullable();
    $table->text('pix_qr_code')->nullable();
    $table->string('digitable_line', 54)->nullable();
    $table->string('barcode', 48)->nullable();
    $table->foreignUuid('bank_id')->nullable()->index()->constrained('banks')->nullOnDelete();
    $table->string('agency', 10)->nullable();
    $table->string('agency_digit', 2)->nullable();
    $table->string('account_number', 20)->nullable();
    $table->string('account_digit', 2)->nullable();
    $table->string('account_type', 20)->nullable();
    $table->string('holder_name', 150)->nullable();
    // Digits only (CPF 11 / CNPJ 14) — same convention as suppliers.document.
    $table->string('holder_document', 20)->nullable();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});

if ($this->supportsPartialIndexes()) {
    DB::statement(
        'CREATE UNIQUE INDEX payment_request_bank_details_request_unique ON payment_request_bank_details (payment_request_id) WHERE deleted_at IS NULL'
    );
}
```

```php
// down()
if ($this->supportsPartialIndexes()) {
    DB::statement('DROP INDEX IF EXISTS payment_request_bank_details_request_unique');
}
Schema::dropIfExists('payment_request_bank_details');

// Helper privado — COPIAR das migrations da Fase 1 (ex.: create_companies_table)
private function supportsPartialIndexes(): bool
{
    return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
}
```

**`casts()`:** `'deposit_type' => DepositType::class`, `'pix_key_type' => PixKeyType::class`, `'account_type' => AccountType::class`.

**Helpers:** `hasPixKey(): bool` (`filled(pix_key_type) && filled(pix_key)`), `hasPixQrCode(): bool`, `hasCompleteTransferData(): bool` (todos os campos obrigatórios do pacote Transfer preenchidos).

### 3.5 Novo Model: `PaymentRequestStatusHistory`

```
Model: PaymentRequestStatusHistory
  Table: payment_request_status_history      ← singular "history" por decisão RNF005
  Attributes:
    - id: uuid, primary
    - payment_request_id: uuid, foreign(payment_requests.id), required, index, cascadeOnDelete
    - from_status: string(20), nullable       → null na criação do registro
    - to_status: string(20), required, index
    - changed_by: uuid, foreign(users.id), nullable, index, nullOnDelete
    - notes: text, nullable
    - created_at: timestampTz, useCurrent
    ⚠️ SEM updated_at · SEM deleted_at · SEM SoftDeletes
  Composite index: [payment_request_id, created_at]   ← timeline (achado DBA #5)
  Relationships:
    - belongsTo: PaymentRequest via payment_request_id
    - belongsTo: User via changed_by  → changedBy()
  Traits:
    - HasFactory, HasUuid     (NÃO usar SoftDeletes; NÃO usar HasBlameable)
  Model config:
    - public $timestamps = false;  +  const UPDATED_AT = null;
    - casts: from_status/to_status => PaymentRequestStatus::class, created_at => 'datetime'
    - Append-only: gravação exclusivamente via PaymentRequestService
```

```php
Schema::create('payment_request_status_history', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('payment_request_id')->index()->constrained('payment_requests')->cascadeOnDelete();
    $table->string('from_status', 20)->nullable();
    $table->string('to_status', 20)->index();
    $table->foreignUuid('changed_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->text('notes')->nullable();
    $table->timestampTz('created_at')->useCurrent();
    $table->index(['payment_request_id', 'created_at']);
});
```

### 3.6 Novo Model: `Attachment` (morph)

```
Model: Attachment
  Table: attachments
  Attributes:
    - id: uuid, primary
    - attachable_type: string   (via uuidMorphs)
    - attachable_id: uuid       (via uuidMorphs)
    - type: string(20), nullable, index      → AttachmentType (boleto | other)
    - disk: string(30), required
    - path: string, required
    - original_name: string, required
    - mime_type: string(100), required
    - size: unsignedInteger, required        (bytes)
    - sort_order: unsignedInteger, required, default:0, index
    - created_by / updated_by: uuid, nullable, index, nullOnDelete
    - created_at / updated_at: timestampTz
    - deleted_at: timestampTz, nullable
  Composite index: [attachable_type, attachable_id, type]   ← padrão addresses/contacts (F2)
  Relationships:
    - morphTo: attachable
    - belongsTo: User via created_by / updated_by (HasBlameable)
  Traits:
    - HasFactory, HasUuid, SoftDeletes, HasBlameable
  Observer:
    - #[ObservedBy(AttachmentObserver::class)]
  Helpers:
    - isPdf(): bool               → $this->mime_type === 'application/pdf'
    - isImage(): bool             → str_starts_with($this->mime_type, 'image/')
    - temporaryUrl(int $minutes = 30): ?string
        S3: Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes))
        local: null (usar Storage::download via Action)
    - humanSize(): string         → number_format($this->size / 1024, 0).' KB'
```

```php
Schema::create('attachments', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->uuidMorphs('attachable');
    $table->string('type', 20)->nullable()->index();
    $table->string('disk', 30);
    $table->string('path');
    $table->string('original_name');
    $table->string('mime_type', 100);
    $table->unsignedInteger('size');
    $table->unsignedInteger('sort_order')->default(0)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
    $table->index(['attachable_type', 'attachable_id', 'type']);
});
```

### 3.7 Nova trait: `App\Models\Concerns\HasAttachments`

Espelhar `HasAddresses` (que só declara o `morphMany`), acrescentando o sync do cache:

```php
trait HasAttachments
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('sort_order');
    }

    public function syncHasAttachmentsFlag(): void
    {
        // Only models that carry the denormalized column react to this.
        if (! $this->isFillable('has_attachments') && ! array_key_exists('has_attachments', $this->getAttributes())) {
            return;
        }

        $this->forceFill(['has_attachments' => $this->attachments()->exists()])->saveQuietly();
    }
}
```

> `saveQuietly()` é obrigatório para não disparar `updated` do `BlameableObserver` nem loops de Observer.

### 3.8 Morph map

`AppServiceProvider::boot()` — `Relation::enforceMorphMap([...])` já existe com `'supplier'`. Adicionar:

```php
'payment_request' => PaymentRequest::class,
```

---

## 4. Enums

Todos em `app/Enums/`, `declare(strict_types=1)`, backed `string`, labels via `__('enums.*')`, contratos de `Filament\Support\Contracts`.

### 4.1 `PaymentRequestStatus` (novo)

```
Enum: PaymentRequestStatus
  Implements: HasLabel, HasColor, HasIcon
  Cases:
    - Requested = 'requested' : label __('enums.payment_request_status.requested'), color 'warning', icon 'heroicon-o-clock'
    - Launched  = 'launched'  : label __('enums.payment_request_status.launched'),  color 'info',    icon 'heroicon-o-paper-airplane'
    - Settled   = 'settled'   : label __('enums.payment_request_status.settled'),   color 'success', icon 'heroicon-o-check-circle'
  Methods:
    - canTransitionTo(self $target): bool
        Requested → [Launched]
        Launched  → [Settled]
        Settled   → []            (sem regressão na F3)
    - allowedTransitions(): array<self>
        array_values(array_filter(self::cases(), fn (self $case) => $this->canTransitionTo($case)))
```

> `getIcon()` retorna `string` (padrão dos enums F1/F2: `'heroicon-o-clock'`). Manter consistência — **não** trocar por `Heroicon` enum aqui, pois `AccountType`/`UserRole`/`PaymentMethod` já usam string e a mudança seria inconsistente. O enum `Heroicon` é obrigatório apenas em `$navigationIcon` e em `->icon()` de Actions novas.

### 4.2 `DepositType` (novo)

```
Enum: DepositType
  Implements: HasLabel, HasColor, HasIcon
  Cases:
    - Pix      = 'pix'      : label __('enums.deposit_type.pix'),      color 'success', icon 'heroicon-o-qr-code'
    - Transfer = 'transfer' : label __('enums.deposit_type.transfer'), color 'info',    icon 'heroicon-o-arrows-right-left'
```

⚠️ **Somente 2 cases.** Não existe `Ted`, `Doc` nem `PixQr`. TED/DOC/conta corrente/poupança = descrição de negócio de `Transfer` (label pt_BR: “Transferência (TED/DOC/conta)”).

### 4.3 `PixKeyType` (novo)

```
Enum: PixKeyType
  Implements: HasLabel, HasIcon      (HasColor opcional — usar 'gray' para todos ou omitir)
  Cases:
    - Random = 'random' : label __('enums.pix_key_type.random'), icon 'heroicon-o-key'
    - Cpf    = 'cpf'    : label __('enums.pix_key_type.cpf'),    icon 'heroicon-o-identification'
    - Phone  = 'phone'  : label __('enums.pix_key_type.phone'),  icon 'heroicon-o-device-phone-mobile'
    - Email  = 'email'  : label __('enums.pix_key_type.email'),  icon 'heroicon-o-envelope'
  Method:
    - validationRules(): array<int, string|ValidationRule>
        Cpf    → [new ValidCpf]
        Email  → ['email:rfc']
        Phone  → ['regex:/^\d{10,13}$/']
        Random → ['uuid']
```

### 4.4 `AttachmentType` (novo)

```
Enum: AttachmentType
  Implements: HasLabel, HasColor, HasIcon
  Cases:
    - Boleto = 'boleto' : label __('enums.attachment_type.boleto'), color 'warning', icon 'heroicon-o-document-text'
    - Other  = 'other'  : label __('enums.attachment_type.other'),  color 'gray',    icon 'heroicon-o-paper-clip'
```

### 4.5 Reutilizados (não recriar)

| Enum | Cases existentes |
|------|------------------|
| `PaymentMethod` | `Boleto = 'boleto'`, `Deposit = 'deposit'` |
| `AccountType` | `Checking = 'checking'`, `Savings = 'savings'` |
| `UserRole` | `Cliente`, `Operador`, `Adm` + `seesAllBranches()`, `canManageRegistrations()` |
| `PersonType` | `Pf`, `Pj` (opcional no Form como select virtual — **não persistir** em bank details) |

---

## 5. Exceções de Domínio

Base existente: `App\Exceptions\BusinessException` (construtor `(string $message, ?string $userMessage = null, int $code = 422, ?Throwable $previous = null)`, método `getUserMessage()`).

### 5.1 `PaymentRequestException extends BusinessException`

| Static factory | `userMessage` (chave i18n) |
|----------------|----------------------------|
| `branchNotAllowed(string $branchId)` | `payment_requests.errors.branch_not_allowed` |
| `invalidStatusTransition(string $from, string $to)` | `payment_requests.errors.invalid_status_transition` |
| `cannotEditInStatus(string $status)` | `payment_requests.errors.cannot_edit_in_status` |
| `appropriationRequired()` | `payment_requests.errors.appropriation_required` |
| `boletoAttachmentRequired()` | `payment_requests.errors.boleto_attachment_required` |
| `discountExceedsGross()` | `payment_requests.errors.discount_exceeds_gross` |
| `invalidNetAmount()` | `payment_requests.errors.invalid_net_amount` |
| `incompleteBankDetails(string $paymentMethod)` | `payment_requests.errors.incomplete_bank_details` |
| `pixDetailsIncomplete()` | `payment_requests.errors.pix_details_incomplete` |
| `transferDetailsIncomplete()` | `payment_requests.errors.transfer_details_incomplete` |
| `invalidHolderDocument()` | `payment_requests.errors.invalid_holder_document` |

### 5.2 `AttachmentException extends BusinessException`

| Static factory | `userMessage` |
|----------------|---------------|
| `invalidMimeType(string $mime)` | `attachments.errors.invalid_mime_type` |
| `fileTooLarge(int $maxKilobytes)` | `attachments.errors.file_too_large` (com `:max`) |
| `fileNotFound(string $path)` | `attachments.errors.file_not_found` |

### 5.3 Exceções existentes a estender

| Exception | Novo factory | `userMessage` |
|-----------|--------------|---------------|
| `SupplierException` | `cannotDeleteWithPaymentRequests(string $id)` | `suppliers.errors.cannot_delete_with_payment_requests` |
| `BranchException` | `cannotDeleteWithPaymentRequests(string $id)` | `branches.errors.cannot_delete_with_payment_requests` |
| `BankException` | `cannotDeleteWithPaymentRequestBankDetails(string $id)` | `banks.errors.cannot_delete_with_payment_request_bank_details` |
| **novo** `CostCenterException` | `cannotDeleteWithPaymentRequests(string $id)` | `cost_centers.errors.cannot_delete_with_payment_requests` |
| **novo** `AppropriationException` | `cannotDeleteWithPaymentRequests(string $id)` | `appropriations.errors.cannot_delete_with_payment_requests` |

---

## 6. Camadas de Aplicação

### 6.1 DTOs (`app/DTOs/` — flat, `agrupar_por_dominio.dtos: false`)

```
DTO: PaymentRequestData          (final readonly class, construtor com promoção de propriedades)
  branchId: ?string              (nullable — resolvido no Service para Cliente)
  supplierId: string
  costCenterId: string
  appropriationId: ?string
  paymentMethod: PaymentMethod
  grossAmount: string            (string para bcmath — NUNCA float)
  discountAmount: string
  dueDate: CarbonImmutable
  notes: ?string
  bankDetails: ?PaymentRequestBankDetailsData
  Métodos: static fromArray(array $data): self   |   toModelAttributes(): array

DTO: PaymentRequestBankDetailsData (final readonly class)
  depositType: ?DepositType
  pixKeyType: ?PixKeyType
  pixKey: ?string
  pixQrCode: ?string
  digitableLine: ?string
  barcode: ?string
  bankId: ?string
  agency: ?string
  agencyDigit: ?string
  accountNumber: ?string
  accountDigit: ?string
  accountType: ?AccountType
  holderName: ?string
  holderDocument: ?string        ← normalizar para dígitos no fromArray()
  Métodos: static fromArray(array $data): self   |   toModelAttributes(): array

DTO: BoletoOcrResult (final readonly class)
  wasSuccessful: bool
  digitableLine: ?string
  amount: ?string                (string decimal, ex.: '1234.56')
  dueDate: ?CarbonImmutable
  message: ?string               (mensagem i18n amigável para Notification)
  Métodos: static failed(string $message): self   |   static success(?string $digitableLine, ?string $amount, ?CarbonImmutable $dueDate): self
```

### 6.2 Service: `PaymentRequestService` (`app/Services/`)

| Método | Assinatura | Responsabilidade |
|--------|-----------|------------------|
| `calculateNetAmount` | `(string $gross, string $discount): string` | **bcmath**: `bcsub($gross ?: '0', $discount ?: '0', 2)`. Nunca float. |
| `create` | `(PaymentRequestData $data, User $actor): PaymentRequest` | `DB::transaction`: resolve filial (§6.2.1) → asserts → cria PR com `status = Requested` e `net_amount` recalculado → cria `bankDetails` → grava history `null → Requested` → **após commit** dispara `PaymentRequestCreated`. |
| `update` | `(PaymentRequest $request, PaymentRequestData $data, User $actor): PaymentRequest` | Assert `isEditableBy` → asserts → `update` do cabeçalho com `net_amount` recalculado → `updateOrCreate` de bankDetails. **Não** altera status. |
| `transitionStatus` | `(PaymentRequest $request, PaymentRequestStatus $to, User $actor, ?string $notes = null): PaymentRequest` | Assert `$request->status->canTransitionTo($to)` senão `invalidStatusTransition` → `DB::transaction`: update status + insere history (`from`, `to`, `changed_by`, `notes`) → após commit dispara `PaymentRequestStatusChanged($request, $from, $to, $actor)`. |
| `recordInitialStatus` | `(PaymentRequest $request, User $actor): void` | History `null → Requested`; idempotente (`firstOrCreate` por `payment_request_id` + `to_status` quando `from_status` é null). Usado pela página Filament Create. |
| `delete` | `(PaymentRequest $request): void` | Soft delete (cascade fica no Observer). Nenhum guard extra — Policy já restringe a Adm. |
| `resolveBranchFor` | `(User $user, ?string $branchId): string` | §6.2.1 |
| `assertAppropriation` | `(?string $branchId, ?string $appropriationId): void` | Se `PaymentRequest::requiresAppropriationForBranch($branchId)` e `blank($appropriationId)` → `appropriationRequired()` |
| `assertAmounts` | `(string $gross, string $discount): void` | `bccomp($gross,'0',2) <= 0` → `invalidNetAmount()`; `bccomp($discount,'0',2) < 0` → `invalidNetAmount()`; `bccomp($discount,$gross,2) > 0` → `discountExceedsGross()` |
| `assertBankDetails` | `(PaymentMethod $method, array $bankDetails): void` | §6.2.2 — **usado tanto pelo Service quanto pelas páginas Filament** |
| `assertBoletoHasAttachment` | `(PaymentMethod $method, int $attachmentCount): void` | Se `Boleto` e `$attachmentCount < 1` → `boletoAttachmentRequired()` |
| `assertEditable` | `(PaymentRequest $request, User $actor): void` | `! $request->isEditableBy($actor)` → `cannotEditInStatus($request->status->value)` |

#### 6.2.1 `resolveBranchFor` — RN012.3 (filial automática)

```
1. Se $user->role->seesAllBranches() (Operador/Adm):
     $branchId é obrigatório → se blank, lançar branchNotAllowed('')
     Validar que a branch existe e está ativa → senão branchNotAllowed($branchId)
2. Se Cliente:
     $allowed = $user->branches()->pluck('branches.id')
     a) $branchId informado:
          in_array($branchId, $allowed) ? retorna : branchNotAllowed($branchId)
     b) $branchId omitido:
          - filial com pivot is_default = true → retorna
          - senão, se $allowed tem exatamente 1 → retorna essa
          - senão → branchNotAllowed('')
```

#### 6.2.2 `assertBankDetails` — matriz de obrigatoriedade (server-side, fonte da verdade)

```
Se $method === PaymentMethod::Boleto:
    blank($bankDetails['digitable_line']) → incompleteBankDetails('boleto')

Se $method === PaymentMethod::Deposit:
    blank($bankDetails['deposit_type']) → incompleteBankDetails('deposit')

    DepositType::Pix:
        $hasKey = filled(pix_key_type) && filled(pix_key)
        $hasQr  = filled(pix_qr_code)
        (! $hasKey && ! $hasQr) → pixDetailsIncomplete()
        AMBOS preenchidos → PERMITIDO (CNAB da F7 escolhe a fonte)
        Se $hasKey: validar pix_key conforme PixKeyType::validationRules()

    DepositType::Transfer:
        Obrigatórios: holder_document, bank_id, agency, account_number, account_digit, account_type
        Qualquer um blank → transferDetailsIncomplete()
        holder_document: strlen(digits) === 11 → ValidCpf | === 14 → ValidCnpj | outro → invalidHolderDocument()
        Opcionais: agency_digit, holder_name
```

### 6.3 Service: `AttachmentService` (`app/Services/`)

| Método | Assinatura | Responsabilidade |
|--------|-----------|------------------|
| `storeUploadedFile` | `(Model $attachable, UploadedFile $file, ?AttachmentType $type = null, int $sortOrder = 0): Attachment` | Valida MIME contra `config('rjet.attachments.accepted_mime_types')` → `invalidMimeType`; valida tamanho contra `config('rjet.attachments.max_kilobytes')` → `fileTooLarge`; `$file->store($directory, $disk)` (nome com hash); cria `Attachment` com `disk`, `path`, `original_name` (`$file->getClientOriginalName()`), `mime_type`, `size`, `type`, `sort_order`. |
| `storeManyFromPaths` | `(Model $attachable, array<string> $paths, array<string,string> $originalNames, ?AttachmentType $defaultType = null): Collection` | Usado pela página Filament Create (arquivos **já** gravados pelo `FileUpload`). Para cada `$path`: `original_name` = `$originalNames[$path] ?? basename($path)`; `mime_type` = `Storage::disk($disk)->mimeType($path)`; `size` = `Storage::disk($disk)->size($path)`; `type` = `Boleto` se `mime === 'application/pdf'` e o PR for boleto, senão `Other`; `sort_order` = índice. |
| `delete` | `(Attachment $attachment): void` | Soft delete (arquivo físico permanece — removido só no `forceDeleted`). |
| `forceDelete` | `(Attachment $attachment): void` | `forceDelete()` — o `AttachmentObserver::forceDeleted` remove o arquivo. |
| `directoryFor` | `(Model $attachable): string` | `config('rjet.attachments.directory').'/'.$attachable->getMorphClass().'/'.$attachable->getKey()` |

**Diretório final:** `attachments/payment_request/{uuid}/{hash}.pdf`. **Visibility:** `private`. **Disco:** `config('rjet.attachments.disk')` (default = `config('filesystems.default')`).

### 6.4 Actions (`app/Actions/PaymentRequest/` — agrupamento por domínio obrigatório)

```
Action: ResolveSupplierPaymentMethodAction
  Assinatura: __invoke(string $supplierId, ?string $branchId): ?PaymentMethod
  Comportamento:
    - blank($branchId) → retorna $supplier->default_payment_method
    - senão → $supplier->paymentMethodFor($branch->company)   (já implementado na F2)
    - supplier/branch inexistente → null
  Uso: afterStateUpdated de supplier_id no Form (sugestão RF010; usuário pode alterar)

Action: ExtractBoletoDataAction
  Assinaturas:
    - fromUploadedFile(TemporaryUploadedFile|UploadedFile $file): BoletoOcrResult
    - fromAttachment(Attachment $attachment): BoletoOcrResult
  Comportamento:
    1. Se ! config('rjet.ocr.enabled') → BoletoOcrResult::failed(__('payment_requests.messages.ocr_disabled'))
    2. Se mime !== 'application/pdf' → BoletoOcrResult::failed(__('payment_requests.messages.ocr_image_not_supported'))
    3. Resolve caminho absoluto local:
         - UploadedFile/TemporaryUploadedFile → $file->getRealPath()
         - Attachment em disco local → Storage::disk($disk)->path($path)
         - Attachment em S3 → copiar para tmp: tempnam() + fwrite(Storage::readStream()) e apagar no finally
    4. Delega para BoletoOcrClient::extract($absolutePath, $mimeType)
    5. try/catch \Throwable → Log::warning(contexto estruturado) + BoletoOcrResult::failed(__('payment_requests.messages.ocr_failed'))
       ⚠️ NUNCA propagar exceção: falha de OCR não bloqueia upload nem submit (RF017)
```

> **Desvio documentado vs arquitetura §8.4:** a interface do client foi ajustada de `extract(string $disk, string $path, string $mime)` para `extract(string $absolutePath, string $mimeType)`, porque o OCR precisa rodar **no arquivo temporário do Livewire** (antes de o `FileUpload` persistir no disco final) e também em anexos já gravados em S3. A resolução de disco/stream fica em `ExtractBoletoDataAction`, mantendo o client puro e testável. Nenhuma decisão BA/DBA é afetada.

### 6.5 Integrations (`app/Integrations/Ocr/`)

```
Interface: BoletoOcrClient
  extract(string $absolutePath, string $mimeType): BoletoOcrResult

Class: LocalBoletoOcrClient implements BoletoOcrClient
  Dependência: smalot/pdfparser (aprovar antes — §18.1)
  Comportamento:
    1. mime !== 'application/pdf' → BoletoOcrResult::failed(__('payment_requests.messages.ocr_image_not_supported'))
    2. Extrai texto do PDF (Smalot\PdfParser\Parser::parseFile()->getText())
    3. Normaliza: remove espaços/pontos, mantém dígitos por bloco
    4. Localiza candidatos de linha digitável: sequências de 47 dígitos (título bancário)
       ou 48 dígitos (arrecadação/convênio) — regex sobre o texto normalizado
    5. Valida com ValidDigitableLine (mód. 10 nos campos + mód. 11 do DV geral)
    6. Do código de barras derivado extrai:
         - fator de vencimento (posições 6–9) → dueDate = 1997-10-07 + fator dias
           (fator 0 ou vazio → dueDate = null)
         - valor (posições 10–19) → amount = valor / 100 formatado com 2 decimais
    7. Sucesso → BoletoOcrResult::success($digitableLine, $amount, $dueDate)
       Nada encontrado → BoletoOcrResult::failed(__('payment_requests.messages.ocr_not_found'))
  ⚠️ SEM Tesseract, SEM binário externo, SEM chamada de rede.

Class: NullBoletoOcrClient implements BoletoOcrClient
  Sempre retorna BoletoOcrResult::failed(__('payment_requests.messages.ocr_disabled'))
  Usado quando config('rjet.ocr.driver') === 'null' e nos testes que não exercitam OCR.

Binding (AppServiceProvider::register()):
  $this->app->bind(BoletoOcrClient::class, fn () => match (config('rjet.ocr.driver')) {
      'null' => new NullBoletoOcrClient,
      default => new LocalBoletoOcrClient,
  });
```

### 6.6 Rules (`app/Rules/`)

| Rule | Comportamento |
|------|---------------|
| `ValidCpfOrCnpj` (novo) | `implements ValidationRule`. Normaliza para dígitos; `strlen === 11` → delega a `ValidCpf`; `=== 14` → delega a `ValidCnpj`; outro tamanho → `$fail(__('validation.custom.holder_document.invalid'))`. Reaproveitado no Form e no Service. |
| `ValidDigitableLine` (novo) | `implements ValidationRule`. Aceita 47 ou 48 dígitos após remover não-dígitos; valida DVs (mód. 10 por campo + mód. 11 geral para 47; mód. 10/11 conforme identificador para 48). Reutilizado pelo `LocalBoletoOcrClient`. Expor `public static function isValid(string $value): bool` para uso fora do contexto de validação. |
| `ValidCpf` / `ValidCnpj` | **Existentes (F2)** — reutilizar, não recriar. |

### 6.7 Config: `config/rjet.php` (novo arquivo)

```php
<?php

declare(strict_types=1);

return [
    'attachments' => [
        'disk' => env('RJET_ATTACHMENTS_DISK', env('FILESYSTEM_DISK', 'local')),
        'directory' => 'attachments',
        'max_kilobytes' => (int) env('RJET_ATTACHMENTS_MAX_KB', 10240), // 10 MB — decisão BA #8
        'max_files' => (int) env('RJET_ATTACHMENTS_MAX_FILES', 10),
        'accepted_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],

    'ocr' => [
        'enabled' => (bool) env('RJET_OCR_ENABLED', true),
        'driver' => env('RJET_OCR_DRIVER', 'local'), // local | null
    ],
];
```

Adicionar ao `.env.example`: `RJET_ATTACHMENTS_MAX_KB=10240`, `RJET_OCR_ENABLED=true`, `RJET_OCR_DRIVER=local`.

### 6.8 Observers

```
Observer: PaymentRequestObserver   (registrar via #[ObservedBy] no Model)
  deleted (soft):
    - $request->bankDetails()->delete()           // bulk soft delete
    - $request->attachments()->delete()           // bulk soft delete (morph — sem FK no banco)
    - NÃO tocar em statusHistories (trilha imutável — decisão DBA #5)
  restored:
    - threshold = $request->getOriginal('deleted_at') ?? $request->updated_at   (padrão CompanyObserver)
    - bankDetails/attachments onlyTrashed()->where('deleted_at','>=',$threshold)->restore()
  forceDeleting:                                  ← ANTES do DELETE do parent (crítico — achado DBA #1)
    - $request->attachments()->withTrashed()->get()->each(fn (Attachment $a) => $a->forceDelete())
      → dispara AttachmentObserver::forceDeleted → remove arquivo do storage
    - $request->bankDetails()->withTrashed()->forceDelete()
  ⚠️ statusHistories somem via FK cascadeOnDelete no DELETE do parent — não apagar manualmente.

Observer: AttachmentObserver
  created / deleted / restored / forceDeleted:
    - $attachment->attachable?->syncHasAttachmentsFlag()   (se o método existir na trait)
  forceDeleted (antes do sync):
    - Storage::disk($attachment->disk)->delete($attachment->path)   (ignorar falha se arquivo ausente)
  ⚠️ soft delete NÃO remove arquivo físico.

Observer: CompanyObserver  ← ESTENDER o existente
  Ordem em `deleted` (soft) — PaymentRequests ANTES de appropriations/branches:
    1. PaymentRequest::query()->whereIn('branch_id', $company->branches()->pluck('id'))->get()
         ->each->delete()      // via Eloquent (dispara PaymentRequestObserver → bankDetails + attachments)
    2. $company->supplierPaymentMethods()->delete()
    3. $company->appropriations()->delete()
    4. por branch: costCenters()->delete() e bankAccounts()->delete()
    5. $company->branches()->delete()
  Ordem em `restored` (com threshold, simétrica):
    PaymentRequests (onlyTrashed, deleted_at >= threshold) → restore → PaymentRequestObserver::restored
    ... demais passos existentes ...
  Ordem em `forceDeleted`:
    1. PaymentRequest::withTrashed()->whereIn('branch_id', ...)->get()->each->forceDelete()   ← Eloquent, NUNCA só FK
    2..5 = passos existentes
  ⚠️ Nunca confiar apenas em cascadeOnDelete de payment_requests.branch_id: morph attachments
     não tem FK e deixaria arquivos órfãos em S3/local.
```

### 6.9 Guards de exclusão nos Services existentes

| Service | Alteração |
|---------|-----------|
| `SupplierService::delete` | Adicionar `ensureDeletable(Supplier $supplier)`: `$supplier->paymentRequests()->exists()` → `SupplierException::cannotDeleteWithPaymentRequests()` |
| `BranchService::ensureDeletable` | Adicionar: `$branch->paymentRequests()->exists()` → `BranchException::cannotDeleteWithPaymentRequests()` (mantendo o guard de `bankAccounts`) |
| `BankService::ensureDeletable` | Adicionar: `$bank->paymentRequestBankDetails()->exists()` → `BankException::cannotDeleteWithPaymentRequestBankDetails()` (mantendo o guard de `branchBankAccounts`) |
| `CostCenterService` (criar se não existir) | `ensureDeletable`: `$costCenter->paymentRequests()->exists()` → `CostCenterException::cannotDeleteWithPaymentRequests()` |
| `AppropriationService` (criar se não existir) | idem com `AppropriationException` |

> Guards vivem **apenas** no Service (nunca no evento `deleting` do Model) para não quebrar o cascade do `CompanyObserver` — padrão já adotado em `BranchService`.

---

## 7. Filament Resource: PaymentRequest

```
Resource: PaymentRequestResource
  Command: php artisan make:filament-resource PaymentRequest --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\PaymentRequests\PaymentRequestResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview
  Structure (pasta PLURAL PaymentRequests/):
    - PaymentRequestResource.php            (final, LIMPO — só delegates)
    - Schemas/PaymentRequestForm.php        (final)
    - Schemas/PaymentRequestInfolist.php    (final — SEMPRE)
    - Tables/PaymentRequestsTable.php       (final, PLURAL)
    - Pages/CreatePaymentRequest.php, EditPaymentRequest.php, ListPaymentRequests.php, ViewPaymentRequest.php
    - RelationManagers/AttachmentsRelationManager.php, StatusHistoriesRelationManager.php
    - Actions/TransitionStatusAction.php, ExtractBoletoOcrAction.php, DownloadAttachmentAction.php, DeletePaymentRequestAction.php

  Model: App\Models\PaymentRequest
  Policy: App\Policies\PaymentRequestPolicy  (§9)
  RecordTitleAttribute: id
  GloballySearchableAttributes: NENHUM (não habilitar global search nesta fase)

  Navigation:
    Icon: Heroicon::OutlinedBanknotes           ← enum, NÃO string
    Group: __('navigation.groups.operations')
    Sort: 1
    Labels: getModelLabel() → __('payment_requests.label')
            getPluralModelLabel() → __('payment_requests.plural')

  Queries:
    getEloquentQuery(): Builder
      $query = parent::getEloquentQuery();
      $user = Filament::auth()->user();
      return $user !== null ? $query->visibleTo($user) : $query;
      ← mesmo padrão de BranchResource

    getRecordRouteBindingEloquentQuery(): Builder     ← --soft-deletes
      return parent::getRecordRouteBindingEloquentQuery()
          ->withoutGlobalScopes([SoftDeletingScope::class])
          ->with(['branch.company', 'supplier', 'costCenter', 'appropriation', 'bankDetails.bank']);
      (o eager load é obrigatório: preventLazyLoading está ativo)

  getRelations(): [AttachmentsRelationManager::class, StatusHistoriesRelationManager::class]
  getPages(): index / create / view / edit
```

### 7.1 Form — `Schemas/PaymentRequestForm.php`

```
Form:
  Columns: 1                      ← $schema->columns(1)
  Docs: https://filamentphp.com/docs/5.x/schemas/sections

Imports (obrigatórios):
  - Filament\Schemas\Schema
  - Filament\Schemas\Components\Section
  - Filament\Schemas\Components\Grid
  - Filament\Schemas\Components\Utilities\Get
  - Filament\Schemas\Components\Utilities\Set
  - Filament\Forms\Components\Select
  - Filament\Forms\Components\TextInput
  - Filament\Forms\Components\Textarea
  - Filament\Forms\Components\DatePicker
  - Filament\Forms\Components\FileUpload
  - Filament\Forms\Components\Hidden
  - Filament\Facades\Filament
  - Filament\Notifications\Notification
  - Livewire\Features\SupportFileUploads\TemporaryUploadedFile
  - App\Enums\{PaymentMethod, DepositType, PixKeyType, AccountType}
  - App\Models\{Branch, CostCenter, Appropriation, PaymentRequest}
  - App\Rules\{ValidCpfOrCnpj, ValidDigitableLine}
  - App\Services\PaymentRequestService
  - App\Actions\PaymentRequest\{ResolveSupplierPaymentMethodAction, ExtractBoletoDataAction}
  - Illuminate\Database\Eloquent\Builder
```

#### 7.1.0 Helpers privados da classe `PaymentRequestForm` (definir ANTES dos campos)

Os campos de liquidação vivem dentro de uma `Section` com `->relationship('bankDetails')`, cujo **state path é `bankDetails`**. Portanto, dentro dessa Section:

- irmãos → `$get('deposit_type')`
- campo do cabeçalho → **`$get('../payment_method')`** (o `../` sobe um nível) ou `$get('/payment_method')` (barra inicial = caminho absoluto). **Nunca** `$get('payment_method')` dentro da Section — resolveria para `bankDetails.payment_method` e retornaria `null`.

```php
private static function paymentMethod(Get $get): ?PaymentMethod
{
    // '../payment_method' works from inside the bankDetails section; '/payment_method' is the absolute form.
    return $get->enum('../payment_method', PaymentMethod::class, isNullable: true);
}

private static function depositType(Get $get): ?DepositType
{
    return $get->enum('deposit_type', DepositType::class, isNullable: true);
}

private static function isBoleto(Get $get): bool  { return self::paymentMethod($get) === PaymentMethod::Boleto; }
private static function isDeposit(Get $get): bool { return self::paymentMethod($get) === PaymentMethod::Deposit; }
private static function isPix(Get $get): bool     { return self::isDeposit($get) && self::depositType($get) === DepositType::Pix; }
private static function isTransfer(Get $get): bool{ return self::isDeposit($get) && self::depositType($get) === DepositType::Transfer; }

private static function recalculateNetAmount(Get $get, Set $set): void
{
    $set('net_amount', app(PaymentRequestService::class)->calculateNetAmount(
        (string) ($get('gross_amount') ?? '0'),
        (string) ($get('discount_amount') ?? '0'),
    ));
}

private static function onlyDigits(?string $state): ?string
{
    return $state === null ? null : preg_replace('/\D/', '', $state);
}
```

> `Get::enum(string $key, string $enumClass, bool $isNullable = false, bool $isAbsolute = false)` existe no Filament v5 instalado e trata tanto instância de enum (state hidratado do model) quanto string (state digitado pelo usuário). **Fallback** caso se prefira explícito (padrão `SupplierForm` da F2):
> `$state = $get('../payment_method'); $value = $state instanceof PaymentMethod ? $state->value : (string) $state;`

#### 7.1.1 Bloco 1 — Identificação

```
Section: identification
  Component: Filament\Schemas\Components\Section
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Heading: __('payment_requests.sections.identification')
  Icon: Heroicon::OutlinedIdentification
  Columns: 2                       ← 1 (form) × 2 = 50% de largura efetiva
  Fields:

  Field: branch_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required; exists:branches,id; Cliente só pode escolher filial vinculada (escopo aplicado na query + revalidado no Service)
    Config:
      ->label(__('payment_requests.fields.branch_id'))
      ->relationship(
          name: 'branch',
          titleAttribute: 'name',
          modifyQueryUsing: fn (Builder $query): Builder => $query
              ->active()
              ->visibleTo(Filament::auth()->user())
              ->with('company'),
      )
      ->getOptionLabelFromRecordUsing(fn (Branch $record): string => "{$record->company->name} / {$record->name}")
      ->searchable()
      ->preload()
      ->required()
      ->live()
      ->default(fn (): ?string => self::defaultBranchId())
      ->disabled(fn (): bool => self::shouldLockBranch())
      ->dehydrated(true)                     ← OBRIGATÓRIO: campos disabled não são dehydratados por padrão
      ->afterStateUpdated(function (Set $set): void {
          $set('cost_center_id', null);       // centros de custo pertencem à filial
          $set('appropriation_id', null);     // apropriações pertencem à empresa da filial
      })
    Reactive: ->live(); ao mudar, limpa cost_center_id e appropriation_id; dispara reavaliação
              das options de cost_center_id/appropriation_id e do required de appropriation_id
    Helpers estáticos da classe:
      defaultBranchId(): ?string
        $user = Filament::auth()->user();
        if ($user === null || $user->role->seesAllBranches()) { return null; }
        return $user->defaultBranch()->value('branches.id')
            ?? ($user->branches()->count() === 1 ? $user->branches()->value('branches.id') : null);
      shouldLockBranch(): bool
        $user = Filament::auth()->user();
        return $user !== null && $user->isCliente() && $user->branches()->count() === 1;

  Field: supplier_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required; exists:suppliers,id
    Config:
      ->label(__('payment_requests.fields.supplier_id'))
      ->relationship(
          name: 'supplier',
          titleAttribute: 'name',
          modifyQueryUsing: fn (Builder $query): Builder => $query->active()->orderBy('name'),
      )
      ->searchable()
      ->preload()
      ->required()
      ->live()
      ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
          if (blank($state)) { return; }
          $suggested = app(ResolveSupplierPaymentMethodAction::class)($state, $get('branch_id'));
          if ($suggested !== null) { $set('payment_method', $suggested->value); }
      })
    Reactive: ->live(); sugere payment_method via Supplier::paymentMethodFor(Company) (RF010) —
              sugestão, o usuário pode sobrescrever

  Field: cost_center_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required; exists:cost_centers,id
    Config:
      ->label(__('payment_requests.fields.cost_center_id'))
      ->options(fn (Get $get): array => blank($get('branch_id'))
          ? []
          : CostCenter::query()
              ->active()
              ->forBranch($get('branch_id'))
              ->orderBy('sort_order')
              ->orderBy('name')
              ->pluck('name', 'id')
              ->all())
      ->searchable()
      ->preload()
      ->required()
      ->disabled(fn (Get $get): bool => blank($get('branch_id')))
      ->dehydrated(true)
      ->helperText(fn (Get $get): ?string => blank($get('branch_id'))
          ? __('payment_requests.hints.select_branch_first')
          : null)
    Reactive: options dependem de branch_id (que é ->live())
    Nota: usar ->options(closure) e NÃO ->relationship(), porque o conjunto varia com branch_id;
          a coluna cost_center_id é real, então o save funciona normalmente. Na página Edit as
          options já contêm o registro atual (mesma filial), então o label resolve.

  Field: appropriation_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: nullable POR PADRÃO; required se branch.company.is_appropriation_required = true; exists:appropriations,id
    Config:
      ->label(__('payment_requests.fields.appropriation_id'))
      ->options(fn (Get $get): array => blank($get('branch_id'))
          ? []
          : Appropriation::query()
              ->active()
              ->forCompany(Branch::query()->whereKey($get('branch_id'))->value('company_id'))
              ->orderBy('sort_order')
              ->orderBy('name')
              ->pluck('name', 'id')
              ->all())
      ->searchable()
      ->preload()
      ->required(fn (Get $get): bool => PaymentRequest::requiresAppropriationForBranch($get('branch_id')))
      ->disabled(fn (Get $get): bool => blank($get('branch_id')))
      ->dehydrated(true)
      ->helperText(fn (Get $get): ?string => PaymentRequest::requiresAppropriationForBranch($get('branch_id'))
          ? __('payment_requests.hints.appropriation_required')
          : __('payment_requests.hints.appropriation_optional'))
    Reactive: required e options dependem de branch_id; regra espelhada server-side em
              PaymentRequestService::assertAppropriation()

  Field: notes
    Component: Filament\Forms\Components\Textarea
    Docs: https://filamentphp.com/docs/5.x/forms/textarea
    Validation: nullable; max:5000
    Config:
      ->label(__('common.fields.notes'))
      ->rows(3)
      ->maxLength(5000)
      ->columnSpanFull()
```

#### 7.1.2 Bloco 2 — Valores

```
Section: amounts
  Component: Filament\Schemas\Components\Section
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Heading: __('payment_requests.sections.amounts')
  Icon: Heroicon::OutlinedCurrencyDollar
  Columns: 2
  Fields:

  Field: gross_amount
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required; numeric; min:0.01
    Config:
      ->label(__('payment_requests.fields.gross_amount'))
      ->numeric()
      ->step(0.01)
      ->minValue(0.01)
      ->prefix('R$')
      ->required()
      ->live(onBlur: true)
      ->afterStateUpdated(fn (Get $get, Set $set): void => self::recalculateNetAmount($get, $set))
    Reactive: ->live(onBlur: true); recalcula net_amount = gross − discount

  Field: discount_amount
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required; numeric; min:0; lte:gross_amount
    Config:
      ->label(__('payment_requests.fields.discount_amount'))
      ->numeric()
      ->step(0.01)
      ->minValue(0)
      ->default(0)
      ->prefix('R$')
      ->required()
      ->lte('gross_amount')
      ->live(onBlur: true)
      ->afterStateUpdated(fn (Get $get, Set $set): void => self::recalculateNetAmount($get, $set))
    Reactive: ->live(onBlur: true); recalcula net_amount

  Field: net_amount
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required; numeric; min:0
    Config:
      ->label(__('payment_requests.fields.net_amount'))
      ->numeric()
      ->prefix('R$')
      ->required()
      ->readOnly()                 ← readOnly (NÃO disabled): permanece dehydratado
      ->dehydrated(true)
      ->helperText(__('payment_requests.hints.net_amount_auto'))
    Reactive: calculado como gross_amount − discount_amount (bcmath, escala 2); somente leitura.
              O servidor recalcula em mutateFormDataBeforeCreate/Save — o valor do form nunca é confiado.

  Field: due_date
    Component: Filament\Forms\Components\DatePicker
    Docs: https://filamentphp.com/docs/5.x/forms/date-time-picker
    Validation: required; date
    Config:
      ->label(__('payment_requests.fields.due_date'))
      ->required()
      ->native(false)
      ->displayFormat('d/m/Y')
      ->closeOnDateSelection()
```

#### 7.1.3 Bloco 3 — Pagamento e comprovantes

```
Section: payment
  Component: Filament\Schemas\Components\Section
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Heading: __('payment_requests.sections.payment')
  Icon: Heroicon::OutlinedCreditCard
  Columns: 1                      ← 1 para permitir a Section filha com Columns: 2 a 50%
  Children (nesta ordem):

  ┌─ Grid: 2 colunas (Filament\Schemas\Components\Grid — Docs: https://filamentphp.com/docs/5.x/schemas/layouts)
  │
  │  Field: payment_method
  │    Component: Filament\Forms\Components\Select
  │    Docs: https://filamentphp.com/docs/5.x/forms/select
  │    Validation: required; enum PaymentMethod
  │    Config:
  │      ->label(__('payment_requests.fields.payment_method'))
  │      ->options(PaymentMethod::class)
  │      ->required()
  │      ->native(false)
  │      ->live()
  │      ->afterStateUpdated(function (?string $state, Set $set): void {
  │          if ($state !== PaymentMethod::Boleto->value) {
  │              $set('bankDetails.digitable_line', null);
  │              $set('bankDetails.barcode', null);
  │          }
  │          if ($state !== PaymentMethod::Deposit->value) {
  │              $set('bankDetails.deposit_type', null);
  │              $set('bankDetails.pix_key_type', null);
  │              $set('bankDetails.pix_key', null);
  │              $set('bankDetails.pix_qr_code', null);
  │              $set('bankDetails.holder_document', null);
  │              $set('bankDetails.holder_name', null);
  │              $set('bankDetails.bank_id', null);
  │              $set('bankDetails.agency', null);
  │              $set('bankDetails.agency_digit', null);
  │              $set('bankDetails.account_number', null);
  │              $set('bankDetails.account_digit', null);
  │              $set('bankDetails.account_type', null);
  │          }
  │      })
  │    Reactive: ->live(); controla visible/required de TODOS os campos de liquidação e o
  │              required do FileUpload de anexos
  └─
```

##### Section de liquidação (aninhada, `->relationship('bankDetails')`)

```
Section: settlement
  Component: Filament\Schemas\Components\Section
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Heading: __('payment_requests.sections.settlement')
  Description: __('payment_requests.hints.settlement')
  Columns: 2                      ← parent Columns 1 × 2 = 50% efetivo ✅
  Relationship: ->relationship('bankDetails')      ← HasOne PaymentRequestBankDetails
  Visible: SEMPRE visível (NÃO usar ->visible())   ⚠️ ver aviso abaixo
```

> ⚠️ **Aviso crítico (comportamento do `EntanglesStateWithSingularRelationship`):** uma Section com `->relationship()` que fique **hidden** não é salva (`shouldSaveRelationshipsWhenHidden()` é `false` por padrão) e o registro relacionado existente pode ser **apagado**. Por isso a Section de liquidação é **sempre visível**; a condicionalidade fica em cada campo interno via `->visible(...)`. Ao chamar `->relationship()`, o Filament define `statePath('bankDetails')` e `dehydrated(false)` na Section — os campos internos **não** aparecem em `$data` de `mutateFormDataBeforeCreate/Save`; são persistidos pelo próprio Filament em `saveRelationships()`. As validações cruzadas server-side ficam em `beforeCreate()`/`beforeSave()` lendo o **raw state** (§7.4).

```
  ── Grupo Depósito ────────────────────────────────────────────────────────────

  Field: deposit_type
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required se payment_method = deposit; enum DepositType
    Config:
      ->label(__('payment_request_bank_details.fields.deposit_type'))
      ->options(DepositType::class)
      ->native(false)
      ->live()
      ->visible(fn (Get $get): bool => self::isDeposit($get))
      ->required(fn (Get $get): bool => self::isDeposit($get))
      ->afterStateUpdated(function (?string $state, Set $set): void {
          if ($state !== DepositType::Pix->value) {
              $set('pix_key_type', null); $set('pix_key', null); $set('pix_qr_code', null);
          }
          if ($state !== DepositType::Transfer->value) {
              $set('holder_document', null); $set('holder_name', null); $set('bank_id', null);
              $set('agency', null); $set('agency_digit', null);
              $set('account_number', null); $set('account_digit', null); $set('account_type', null);
          }
      })
    Reactive: visible/required dependem de ../payment_method; ->live() controla os grupos Pix e Transfer
    Nota: labels vindos do enum — 'Pix' e 'Transferência (TED/DOC/conta)'. NÃO existe case Ted/Doc/PixQr.

  ── Grupo Boleto (payment_method = boleto) ───────────────────────────────────

  Field: digitable_line
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required se payment_method = boleto; max:54; ValidDigitableLine
    Config:
      ->label(__('payment_request_bank_details.fields.digitable_line'))
      ->maxLength(54)
      ->visible(fn (Get $get): bool => self::isBoleto($get))
      ->required(fn (Get $get): bool => self::isBoleto($get))
      ->rule(new ValidDigitableLine)
      ->helperText(__('payment_requests.hints.digitable_line'))
      ->columnSpanFull()
    Reactive: visible/required dependem de ../payment_method; preenchido automaticamente pelo OCR
              quando um PDF de boleto é anexado (só se estiver vazio)

  Field: barcode
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: nullable; max:48
    Config:
      ->label(__('payment_request_bank_details.fields.barcode'))
      ->maxLength(48)
      ->visible(fn (Get $get): bool => self::isBoleto($get))
      ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null)
    Reactive: visible depende de ../payment_method

  ── Grupo Pix (deposit_type = pix) — (chave) OU (QR); ambos são permitidos ────

  Field: pix_key_type
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required se Pix E pix_qr_code vazio; enum PixKeyType
    Config:
      ->label(__('payment_request_bank_details.fields.pix_key_type'))
      ->options(PixKeyType::class)
      ->native(false)
      ->live()
      ->visible(fn (Get $get): bool => self::isPix($get))
      ->required(fn (Get $get): bool => self::isPix($get) && blank($get('pix_qr_code')))
      ->afterStateUpdated(fn (Set $set): mixed => $set('pix_key', null))
    Reactive: visible depende de ../payment_method + deposit_type; required depende de pix_qr_code;
              ->live() porque as rules de pix_key dependem do tipo escolhido

  Field: pix_key
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required se Pix E pix_qr_code vazio; max:100; rule dinâmica por PixKeyType
               (Cpf → ValidCpf | Email → email:rfc | Phone → regex:/^\d{10,13}$/ | Random → uuid)
    Config:
      ->label(__('payment_request_bank_details.fields.pix_key'))
      ->maxLength(100)
      ->visible(fn (Get $get): bool => self::isPix($get))
      ->required(fn (Get $get): bool => self::isPix($get) && blank($get('pix_qr_code')))
      ->rules(fn (Get $get): array => $get->enum('pix_key_type', PixKeyType::class, isNullable: true)?->validationRules() ?? [])
      ->dehydrateStateUsing(function (?string $state, Get $get): ?string {
          $type = $get->enum('pix_key_type', PixKeyType::class, isNullable: true);
          // CPF and phone keys are persisted as digits only, mirroring suppliers.document.
          return in_array($type, [PixKeyType::Cpf, PixKeyType::Phone], true)
              ? (self::onlyDigits($state) ?: null)
              : $state;
      })
      ->placeholder(fn (Get $get): ?string => match ($get->enum('pix_key_type', PixKeyType::class, isNullable: true)) {
          PixKeyType::Cpf => '000.000.000-00',
          PixKeyType::Phone => '11999999999',
          PixKeyType::Email => 'financeiro@exemplo.com.br',
          PixKeyType::Random => '00000000-0000-0000-0000-000000000000',
          default => null,
      })
    Reactive: visible depende de ../payment_method + deposit_type; required depende de pix_qr_code;
              rules/placeholder dependem de pix_key_type

  Field: pix_qr_code
    Component: Filament\Forms\Components\Textarea
    Docs: https://filamentphp.com/docs/5.x/forms/textarea
    Validation: required se Pix E (pix_key_type OU pix_key vazios); nullable
    Config:
      ->label(__('payment_request_bank_details.fields.pix_qr_code'))
      ->rows(3)
      ->visible(fn (Get $get): bool => self::isPix($get))
      ->required(fn (Get $get): bool => self::isPix($get) && (blank($get('pix_key_type')) || blank($get('pix_key'))))
      ->helperText(__('payment_requests.hints.pix_qr_code'))
      ->columnSpanFull()
    Reactive: visible depende de ../payment_method + deposit_type; required depende de pix_key_type/pix_key
    Nota (regra BA #9): quando Pix, exigir ao menos um caminho — (pix_key_type + pix_key) OU pix_qr_code.
          Preencher AMBOS é PERMITIDO (o CNAB da F7 escolhe a fonte). Não existe DepositType::PixQr.
          Sem OCR/leitura de QR de imagem nesta fase.

  ── Grupo Transferência (deposit_type = transfer) — pacote OBRIGATÓRIO ────────

  Field: holder_document
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required se Transfer; max:20; ValidCpfOrCnpj (11 dígitos → CPF, 14 → CNPJ)
    Config:
      ->label(__('payment_request_bank_details.fields.holder_document'))
      ->maxLength(20)
      ->visible(fn (Get $get): bool => self::isTransfer($get))
      ->required(fn (Get $get): bool => self::isTransfer($get))
      ->rule(new ValidCpfOrCnpj)
      ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null)
      ->helperText(__('payment_requests.hints.holder_document'))
    Reactive: visible/required dependem de ../payment_method + deposit_type
    Nota: persistir SOMENTE DÍGITOS (achado DBA #6). NÃO existe coluna person_type em
          payment_request_bank_details — o tipo é inferido pelo tamanho (11/14). Um Select
          virtual `PersonType` (padrão SupplierForm) é opcional e, se usado, NÃO deve ser
          dehydratado (->dehydrated(false)).

  Field: holder_name
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: nullable; max:150
    Config:
      ->label(__('payment_request_bank_details.fields.holder_name'))
      ->maxLength(150)
      ->visible(fn (Get $get): bool => self::isTransfer($get))
    Reactive: visible depende de ../payment_method + deposit_type
    Nota: OPCIONAL (recomendado) — decisão BA #7

  Field: bank_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required se Transfer; exists:banks,id
    Config:
      ->label(__('payment_request_bank_details.fields.bank_id'))
      ->relationship(
          name: 'bank',
          titleAttribute: 'name',
          modifyQueryUsing: fn (Builder $query): Builder => $query->active()->orderBy('name'),
      )
      ->searchable()
      ->preload()
      ->visible(fn (Get $get): bool => self::isTransfer($get))
      ->required(fn (Get $get): bool => self::isTransfer($get))
    Reactive: visible/required dependem de ../payment_method + deposit_type
    Nota: ->relationship() funciona aqui porque o schema filho da Section tem como model
          PaymentRequestBankDetails (o Filament troca o model do container). Se a tabela `banks`
          tiver coluna `code`, é permitido acrescentar
          ->getOptionLabelFromRecordUsing(fn (Bank $record): string => "{$record->code} - {$record->name}")

  Field: agency
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required se Transfer; max:10
    Config:
      ->label(__('payment_request_bank_details.fields.agency'))
      ->maxLength(10)
      ->visible(fn (Get $get): bool => self::isTransfer($get))
      ->required(fn (Get $get): bool => self::isTransfer($get))
      ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null)
    Reactive: visible/required dependem de ../payment_method + deposit_type

  Field: agency_digit
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: nullable; max:2
    Config:
      ->label(__('payment_request_bank_details.fields.agency_digit'))
      ->maxLength(2)
      ->visible(fn (Get $get): bool => self::isTransfer($get))
    Reactive: visible depende de ../payment_method + deposit_type
    Nota: nullable no schema; obrigatório apenas se o banco exigir (não validar como required na F3)

  Field: account_number
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required se Transfer; max:20
    Config:
      ->label(__('payment_request_bank_details.fields.account_number'))
      ->maxLength(20)
      ->visible(fn (Get $get): bool => self::isTransfer($get))
      ->required(fn (Get $get): bool => self::isTransfer($get))
      ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null)
    Reactive: visible/required dependem de ../payment_method + deposit_type

  Field: account_digit
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required se Transfer; max:2
    Config:
      ->label(__('payment_request_bank_details.fields.account_digit'))
      ->maxLength(2)
      ->visible(fn (Get $get): bool => self::isTransfer($get))
      ->required(fn (Get $get): bool => self::isTransfer($get))
    Reactive: visible/required dependem de ../payment_method + deposit_type

  Field: account_type
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required se Transfer; enum AccountType
    Config:
      ->label(__('payment_request_bank_details.fields.account_type'))
      ->options(AccountType::class)
      ->native(false)
      ->visible(fn (Get $get): bool => self::isTransfer($get))
      ->required(fn (Get $get): bool => self::isTransfer($get))
    Reactive: visible/required dependem de ../payment_method + deposit_type
```

##### Anexos (dentro do Bloco 3, após a Section de liquidação)

```
  Field: attachment_files            ← campo VIRTUAL (não é coluna); só na página Create
    Component: Filament\Forms\Components\FileUpload
    Docs: https://filamentphp.com/docs/5.x/forms/file-upload
    Validation: required se payment_method = boleto (mínimo 1 arquivo); mimetypes pdf/jpeg/png/webp; max 10240 KB
    Config:
      ->label(__('common.sections.attachments'))
      ->multiple()
      ->disk(fn (): string => (string) config('rjet.attachments.disk'))
      ->directory('attachments/payment_request')
      ->visibility('private')
      ->maxSize(fn (): int => (int) config('rjet.attachments.max_kilobytes'))
      ->maxFiles(fn (): int => (int) config('rjet.attachments.max_files'))
      ->minFiles(fn (Get $get): ?int => self::isBoleto($get) ? 1 : null)
      ->acceptedFileTypes(config('rjet.attachments.accepted_mime_types'))
      ->required(fn (Get $get): bool => self::isBoleto($get))
      ->downloadable()
      ->openable()
      ->previewable(false)
      ->reorderable()
      ->fileNamesStatePath('attachment_file_names')   ← preserva os nomes originais
      ->hiddenOn('edit')                              ← na Edit usa-se o AttachmentsRelationManager
      ->live()
      ->columnSpanFull()
      ->helperText(__('payment_requests.hints.attachments'))
      ->afterStateUpdated(function (?array $state, Get $get, Set $set): void {
          if (! self::isBoleto($get) || blank($state)) { return; }

          $file = collect($state)->last();

          if (! $file instanceof TemporaryUploadedFile) { return; }

          if ($file->getMimeType() !== 'application/pdf') {
              Notification::make()
                  ->title(__('payment_requests.messages.ocr_image_not_supported'))
                  ->warning()
                  ->send();

              return;
          }

          $result = app(ExtractBoletoDataAction::class)->fromUploadedFile($file);

          if (! $result->wasSuccessful) {
              Notification::make()
                  ->title(__('payment_requests.messages.ocr_failed'))
                  ->body($result->message)
                  ->warning()
                  ->send();

              return;
          }

          if (blank($get('bankDetails.digitable_line')) && filled($result->digitableLine)) {
              $set('bankDetails.digitable_line', $result->digitableLine);
          }

          if (blank($get('gross_amount')) && filled($result->amount)) {
              $set('gross_amount', $result->amount);
              self::recalculateNetAmount($get, $set);
          }

          if (blank($get('due_date')) && $result->dueDate !== null) {
              $set('due_date', $result->dueDate->toDateString());
          }

          Notification::make()
              ->title(__('payment_requests.messages.ocr_succeeded'))
              ->success()
              ->send();
      })
    Reactive: ->live(); required/minFiles dependem de payment_method; o OCR roda no
              afterStateUpdated sobre o TemporaryUploadedFile (o FileUpload só grava no disco
              final na dehidratação, no submit — portanto o state aqui contém o arquivo temporário)
    Notas obrigatórias:
      - Nunca sobrescrever valores já digitados pelo usuário (só preenche se blank) — RN017.2
      - OCR de imagem NÃO existe na F3: JPEG/PNG/WEBP são aceitos como anexo, mas geram warning
      - Falha de OCR NUNCA bloqueia o upload nem o submit

  Field: attachment_file_names        ← campo VIRTUAL de apoio ao fileNamesStatePath
    Component: Filament\Forms\Components\Hidden
    Docs: https://filamentphp.com/docs/5.x/forms/hidden
    Validation: nenhuma
    Config:
      ->dehydrated(true)
      ->hiddenOn('edit')
    Nota: recebe o mapa [path => nomeOriginal] gravado pelo FileUpload. É consumido em
          mutateFormDataBeforeCreate() para popular Attachment.original_name e depois descartado.
```

#### 7.1.4 Resumo das dependências reativas

| Campo gatilho | `->live()` | Afeta |
|---------------|-----------|-------|
| `branch_id` | `live()` | options de `cost_center_id` e `appropriation_id`; `required` de `appropriation_id`; limpa ambos |
| `supplier_id` | `live()` | sugere `payment_method` |
| `gross_amount` | `live(onBlur: true)` | `net_amount` |
| `discount_amount` | `live(onBlur: true)` | `net_amount` |
| `payment_method` | `live()` | visible/required de todo o bloco de liquidação; required/minFiles do FileUpload; limpa campos do método anterior |
| `bankDetails.deposit_type` | `live()` | visible/required dos grupos Pix e Transfer; limpa o grupo não escolhido |
| `bankDetails.pix_key_type` | `live()` | rules/placeholder de `pix_key`; limpa `pix_key` |
| `bankDetails.pix_qr_code` | — (leitura) | `required` de `pix_key_type`/`pix_key` |
| `bankDetails.pix_key` / `pix_key_type` | — (leitura) | `required` de `pix_qr_code` |
| `attachment_files` | `live()` | dispara OCR → pode preencher `bankDetails.digitable_line`, `gross_amount`, `due_date` |

### 7.2 Table — `Tables/PaymentRequestsTable.php`

```
Imports (obrigatórios):
  - Filament\Tables\Table
  - Filament\Tables\Columns\TextColumn
  - Filament\Tables\Columns\IconColumn
  - Filament\Tables\Columns\Summarizers\Sum
  - Filament\Tables\Filters\SelectFilter
  - Filament\Tables\Filters\TernaryFilter
  - Filament\Tables\Filters\TrashedFilter
  - Filament\Tables\Filters\Filter
  - Filament\Forms\Components\DatePicker          ← usado nos formulários dos filtros de período
  - Filament\Actions\ActionGroup
  - Filament\Actions\ViewAction
  - Filament\Actions\EditAction
  - Filament\Actions\RestoreAction
  - Filament\Actions\ForceDeleteAction
  - Filament\Actions\BulkActionGroup
  - Filament\Actions\DeleteBulkAction
  - Filament\Actions\RestoreBulkAction
  - Filament\Actions\ForceDeleteBulkAction
  - Filament\Facades\Filament
  - App\Enums\{PaymentRequestStatus, PaymentMethod}
  - App\Models\Company
  - App\Filament\Resources\PaymentRequests\Actions\{TransitionStatusAction, DeletePaymentRequestAction}
  - Illuminate\Database\Eloquent\Builder
  - Illuminate\Support\Carbon                     ← usado em indicateUsing()

Table:
  Docs: https://filamentphp.com/docs/5.x/tables/overview
  Config geral:
    ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch.company', 'supplier', 'costCenter']))
       ⚠️ visibleTo() NÃO vai aqui — já é aplicado em PaymentRequestResource::getEloquentQuery()
    ->defaultSort('created_at', 'desc')
    ->defaultPaginationPageOption(15)
    ->striped()

  Column: branch.company.name
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('payment_requests.fields.company')), ->searchable(), ->sortable(), ->toggleable()

  Column: branch.name
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('payment_requests.fields.branch_id')), ->searchable(), ->sortable()

  Column: supplier.name
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('payment_requests.fields.supplier_id')), ->searchable(), ->sortable()

  Column: cost_center.name  (usar 'costCenter.name')
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('payment_requests.fields.cost_center_id')), ->toggleable(isToggledHiddenByDefault: true)

  Column: status
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('common.fields.status')), ->badge(), ->sortable()
    Nota: cor/ícone vêm automaticamente de PaymentRequestStatus (HasColor + HasIcon) — não configurar manualmente

  Column: payment_method
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('payment_requests.fields.payment_method')), ->badge(), ->sortable()

  Column: net_amount
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('payment_requests.fields.net_amount')), ->money('BRL'), ->sortable(),
            ->summarize(Sum::make()->money('BRL')->label(__('payment_requests.fields.net_amount')))
    Import extra: Filament\Tables\Columns\Summarizers\Sum

  Column: gross_amount
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('payment_requests.fields.gross_amount')), ->money('BRL'), ->sortable(),
            ->toggleable(isToggledHiddenByDefault: true)

  Column: due_date
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('payment_requests.fields.due_date')), ->date('d/m/Y'), ->sortable()

  Column: has_attachments
    Component: Filament\Tables\Columns\IconColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/icon
    Config: ->label(__('payment_requests.fields.has_attachments')), ->boolean(), ->toggleable()

  Column: created_at
    Component: Filament\Tables\Columns\TextColumn
    Docs: https://filamentphp.com/docs/5.x/tables/columns/text
    Config: ->label(__('common.fields.created_at')), ->dateTime('d/m/Y H:i'), ->sortable(),
            ->toggleable(isToggledHiddenByDefault: true)

  ── Filters ──────────────────────────────────────────────────────────────────

  Filter: status
    Component: Filament\Tables\Filters\SelectFilter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/select
    Config: ->label(__('common.fields.status')), ->options(PaymentRequestStatus::class), ->multiple()

  Filter: payment_method
    Component: Filament\Tables\Filters\SelectFilter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/select
    Config: ->label(__('payment_requests.fields.payment_method')), ->options(PaymentMethod::class)

  Filter: branch
    Component: Filament\Tables\Filters\SelectFilter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/select
    Config: ->label(__('payment_requests.fields.branch_id')),
            ->relationship('branch', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->visibleTo(Filament::auth()->user())),
            ->searchable(), ->preload()

  Filter: company
    Component: Filament\Tables\Filters\SelectFilter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/select
    Config: ->label(__('payment_requests.fields.company')),
            ->options(fn (): array => Company::query()->active()->orderBy('name')->pluck('name', 'id')->all()),
            ->searchable(),
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['value'] ?? null,
                fn (Builder $query, string $companyId): Builder => $query->whereHas(
                    'branch',
                    fn (Builder $query): Builder => $query->where('company_id', $companyId),
                ),
            ))

  Filter: supplier
    Component: Filament\Tables\Filters\SelectFilter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/select
    Config: ->label(__('payment_requests.fields.supplier_id')), ->relationship('supplier', 'name'),
            ->searchable(), ->preload()

  Filter: due_date  (range)
    Component: Filament\Tables\Filters\Filter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
    Form: [ DatePicker::make('due_from')->label(__('payment_requests.filters.due_from'))->native(false),
            DatePicker::make('due_until')->label(__('payment_requests.filters.due_until'))->native(false) ]
    Config: ->schema([...])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['due_from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('due_date', '>=', $date))
                ->when($data['due_until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('due_date', '<=', $date)))
            ->indicateUsing(fn (array $data): array => array_filter([
                ($data['due_from'] ?? null) ? __('payment_requests.filters.due_from').': '.Carbon::parse($data['due_from'])->format('d/m/Y') : null,
                ($data['due_until'] ?? null) ? __('payment_requests.filters.due_until').': '.Carbon::parse($data['due_until'])->format('d/m/Y') : null,
            ]))
    Nota: em Filament v5 o formulário de um Filter é declarado com ->schema([...]) (o antigo ->form([...]) foi renomeado)

  Filter: created_at  (range)
    Component: Filament\Tables\Filters\Filter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/custom
    Form: [ DatePicker::make('created_from'), DatePicker::make('created_until') ] (labels via __('payment_requests.filters.*'))
    Config: mesmo padrão do filtro due_date, usando whereDate('created_at', ...)

  Filter: has_attachments
    Component: Filament\Tables\Filters\TernaryFilter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary
    Config: ->label(__('payment_requests.fields.has_attachments'))

  Filter: trashed
    Component: Filament\Tables\Filters\TrashedFilter
    Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary#filtering-soft-deletable-records
    Config: ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)

  ── Record Actions (->recordActions()) ───────────────────────────────────────

  ActionGroup:
    Component: Filament\Actions\ActionGroup
    Docs: https://filamentphp.com/docs/5.x/actions/grouping-actions
    Location: table row
    Actions:
      - ViewAction::make()                       (Filament\Actions\ViewAction)
      - EditAction::make()                       (Filament\Actions\EditAction)
      - TransitionStatusAction::make()           (§7.5.1)
      - DeletePaymentRequestAction::make()       (§7.5.4 — visível só Adm)
      - RestoreAction::make()                    (Filament\Actions\RestoreAction — visível só Adm)
      - ForceDeleteAction::make()                (Filament\Actions\ForceDeleteAction — visível só Adm)

  ── Toolbar Actions (->toolbarActions()) ─────────────────────────────────────

  BulkActionGroup:
    Component: Filament\Actions\BulkActionGroup
    Docs: https://filamentphp.com/docs/5.x/actions/overview
    Actions:
      - DeleteBulkAction::make()->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
      - RestoreBulkAction::make()->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
      - ForceDeleteBulkAction::make()->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
    Nota: toolbarActions() sobrepõe bulkActions() — SEMPRE envolver em BulkActionGroup

  ── Header Actions (na página List) ──────────────────────────────────────────
  CreateAction::make()   (declarada em ListPaymentRequests::getHeaderActions())
```

### 7.3 Infolist — `Schemas/PaymentRequestInfolist.php`

```
Infolist:
  Columns: 1                      ← $schema->columns(1); Sections com Columns: 2 → 50% efetivo
  Docs: https://filamentphp.com/docs/5.x/infolists/overview

Imports:
  - Filament\Infolists\Components\TextEntry
  - Filament\Infolists\Components\IconEntry
  - Filament\Infolists\Components\RepeatableEntry
  - Filament\Schemas\Components\Section
  - Filament\Schemas\Schema
  - Filament\Support\Enums\FontWeight
  - App\Enums\{PaymentMethod, DepositType}
  - App\Models\PaymentRequest

Section: identification  (Columns: 2, Heading __('payment_requests.sections.identification'))
  Entry: branch.company.name  → TextEntry | Docs: .../infolists/text-entry | ->label(__('payment_requests.fields.company'))
  Entry: branch.name          → TextEntry | ->label(__('payment_requests.fields.branch_id'))
  Entry: supplier.name        → TextEntry | ->label(__('payment_requests.fields.supplier_id'))
  Entry: costCenter.name      → TextEntry | ->label(__('payment_requests.fields.cost_center_id'))
  Entry: appropriation.name   → TextEntry | ->label(__('payment_requests.fields.appropriation_id')), ->placeholder('—')
  Entry: notes                → TextEntry | ->label(__('common.fields.notes')), ->columnSpanFull(), ->placeholder('—')

Section: amounts  (Columns: 2, Heading __('payment_requests.sections.amounts'))
  Entry: gross_amount    → TextEntry | ->money('BRL')
  Entry: discount_amount → TextEntry | ->money('BRL')
  Entry: net_amount      → TextEntry | ->money('BRL'), ->weight(FontWeight::Bold)   (import Filament\Support\Enums\FontWeight)
  Entry: due_date        → TextEntry | ->date('d/m/Y')

Section: payment  (Columns: 2, Heading __('payment_requests.sections.payment'))
  Entry: payment_method → TextEntry | ->badge()
  Entry: status         → TextEntry | ->badge()

Section: settlement  (Columns: 2, Heading __('payment_requests.sections.settlement'))
  Entry: bankDetails.deposit_type   → TextEntry | ->badge(), ->visible(fn (PaymentRequest $record): bool => $record->payment_method === PaymentMethod::Deposit)
  Entry: bankDetails.digitable_line → TextEntry | ->copyable(), ->columnSpanFull(), ->visible(fn (PaymentRequest $record): bool => $record->payment_method === PaymentMethod::Boleto)
  Entry: bankDetails.barcode        → TextEntry | ->copyable(), ->visible(... Boleto ...)
  Entry: bankDetails.pix_key_type   → TextEntry | ->badge(), ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Pix)
  Entry: bankDetails.pix_key        → TextEntry | ->copyable(), ->visible(... Pix ...)
  Entry: bankDetails.pix_qr_code    → TextEntry | ->copyable(), ->columnSpanFull(), ->visible(... Pix ...)
  Entry: bankDetails.holder_name      → TextEntry | ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Transfer)
  Entry: bankDetails.holder_document  → TextEntry | ->visible(... Transfer ...)
  Entry: bankDetails.bank.name        → TextEntry | ->visible(... Transfer ...)
  Entry: bankDetails.agency           → TextEntry | ->visible(... Transfer ...)
  Entry: bankDetails.account_number   → TextEntry | ->visible(... Transfer ...)
  Entry: bankDetails.account_type     → TextEntry | ->badge(), ->visible(... Transfer ...)

Section: attachments  (Columns: 1, Heading __('common.sections.attachments'), Collapsible: yes)
  Entry: attachments → RepeatableEntry
    Component: Filament\Infolists\Components\RepeatableEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/repeatable-entry
    Config: ->schema([...])->columns(4)->placeholder(__('payment_requests.messages.no_attachments'))
    Sub-entries:
      original_name → TextEntry
      type          → TextEntry | ->badge()
      mime_type     → TextEntry
      size          → TextEntry | ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 0, ',', '.').' KB')

Section: history  (Columns: 1, Heading __('common.sections.history'), Collapsible: yes)
  Entry: statusHistories → RepeatableEntry
    Config: ->schema([...])->columns(4)
    Sub-entries:
      from_status    → TextEntry | ->badge(), ->placeholder('—')
      to_status      → TextEntry | ->badge()
      changedBy.name → TextEntry | ->placeholder('—')
      created_at     → TextEntry | ->dateTime('d/m/Y H:i')

Section: audit  (Columns: 2, Heading __('common.sections.audit'), Collapsible: yes, Collapsed: yes)
  Entry: creator.name → TextEntry | ->label(__('common.fields.created_by')), ->placeholder('—')
  Entry: editor.name  → TextEntry | ->label(__('common.fields.updated_by')), ->placeholder('—')
  Entry: created_at   → TextEntry | ->dateTime('d/m/Y H:i')
  Entry: updated_at   → TextEntry | ->dateTime('d/m/Y H:i')
  Entry: deleted_at   → TextEntry | ->dateTime('d/m/Y H:i'), ->visible(fn (PaymentRequest $record): bool => $record->trashed())
```

> **Eager loading obrigatório:** `getRecordRouteBindingEloquentQuery()` já carrega `branch.company`, `supplier`, `costCenter`, `appropriation`, `bankDetails.bank`. Acrescentar `attachments` e `statusHistories.changedBy` **na página View** (`ViewPaymentRequest::resolveRecord()` ou via `$record->load(...)` em `mount`) para não violar `preventLazyLoading`.

### 7.4 Pages

```
Imports das Pages:
  - Filament\Resources\Pages\{ListRecords, CreateRecord, EditRecord, ViewRecord}
  - Filament\Actions\{CreateAction, EditAction, ViewAction, RestoreAction, ForceDeleteAction}
  - Filament\Facades\Filament
  - Filament\Notifications\Notification
  - App\Enums\{PaymentMethod, PaymentRequestStatus, AttachmentType}
  - App\Exceptions\BusinessException
  - App\Services\{PaymentRequestService, AttachmentService}
  - App\Filament\Resources\PaymentRequests\Actions\{TransitionStatusAction, DeletePaymentRequestAction}

Page: ListPaymentRequests  (extends Filament\Resources\Pages\ListRecords)
  getHeaderActions(): [ CreateAction::make() ]      (Filament\Actions\CreateAction)
  Sem widgets nesta fase.

Page: CreatePaymentRequest  (extends Filament\Resources\Pages\CreateRecord)
  Propriedades:
    protected array $uploadedAttachmentPaths = [];
    protected array $uploadedAttachmentNames = [];

  mutateFormDataBeforeCreate(array $data): array
    1. $this->uploadedAttachmentPaths = array_values((array) ($data['attachment_files'] ?? []));
    2. $this->uploadedAttachmentNames = (array) ($data['attachment_file_names'] ?? []);
    3. unset($data['attachment_files'], $data['attachment_file_names']);
    4. $data['branch_id'] = app(PaymentRequestService::class)
           ->resolveBranchFor(Filament::auth()->user(), $data['branch_id'] ?? null);
    5. $data['status'] = PaymentRequestStatus::Requested->value;
    6. $data['net_amount'] = app(PaymentRequestService::class)->calculateNetAmount(
           (string) $data['gross_amount'], (string) ($data['discount_amount'] ?? '0'));
    7. return $data;

  beforeCreate(): void          ← validações cruzadas server-side (fonte da verdade)
    try {
        $service = app(PaymentRequestService::class);
        $state = $this->form->getRawState();          // inclui a chave 'bankDetails' (a Section não é dehydratada)
        $method = PaymentMethod::from((string) $state['payment_method']);

        $service->assertAmounts((string) $state['gross_amount'], (string) ($state['discount_amount'] ?? '0'));
        $service->assertAppropriation($state['branch_id'] ?? null, $state['appropriation_id'] ?? null);
        $service->assertBankDetails($method, (array) ($state['bankDetails'] ?? []));
        $service->assertBoletoHasAttachment($method, count((array) ($state['attachment_files'] ?? [])));
    } catch (BusinessException $exception) {
        Notification::make()->title($exception->getUserMessage())->danger()->send();
        $this->halt();
    }

  afterCreate(): void
    1. app(AttachmentService::class)->storeManyFromPaths(
           $this->record,
           $this->uploadedAttachmentPaths,
           $this->uploadedAttachmentNames,
           $this->record->payment_method === PaymentMethod::Boleto ? AttachmentType::Boleto : AttachmentType::Other,
       );
    2. app(PaymentRequestService::class)->recordInitialStatus($this->record, Filament::auth()->user());
       // grava history null → Requested e dispara PaymentRequestCreated

  getRedirectUrl(): string → $this->getResource()::getUrl('view', ['record' => $this->record])

Page: EditPaymentRequest  (extends Filament\Resources\Pages\EditRecord)
  getHeaderActions(): [
      ViewAction::make(),
      TransitionStatusAction::make(),
      DeletePaymentRequestAction::make(),
      RestoreAction::make(),
      ForceDeleteAction::make(),
  ]

  mutateFormDataBeforeSave(array $data): array
    - $data['net_amount'] = calculateNetAmount(gross, discount)
    - Remover qualquer chave virtual remanescente (attachment_files / attachment_file_names)
    - NUNCA permitir alteração de 'status' por aqui (o campo não existe no form)

  beforeSave(): void
    - Mesmo bloco try/catch do beforeCreate, MAIS:
        app(PaymentRequestService::class)->assertEditable($this->record, Filament::auth()->user());
      e assertBoletoHasAttachment usando $this->record->attachments()->count()
        (na Edit os anexos são gerenciados pelo RelationManager, não pelo FileUpload)

Page: ViewPaymentRequest  (extends Filament\Resources\Pages\ViewRecord)
  getHeaderActions(): [
      EditAction::make(),
      TransitionStatusAction::make(),
      DeletePaymentRequestAction::make(),
  ]
  mount(): carregar relações do infolist → $this->record->load(['attachments', 'statusHistories.changedBy'])
```

### 7.5 Actions custom (`Actions/`, padrão `static function make(): Action`)

> **Padrão obrigatório:** classe `final` com um único método `public static function make(): Action`. **Não** estender `Action` nem usar `setUp()`. Namespace de todas: `Filament\Actions\Action`.

#### 7.5.1 `TransitionStatusAction`

```
Action: TransitionStatusAction
  Location: App\Filament\Resources\PaymentRequests\Actions\TransitionStatusAction
  Component: Filament\Actions\Action
  Docs: https://filamentphp.com/docs/5.x/actions/overview  +  https://filamentphp.com/docs/5.x/actions/modals
  Name: 'transitionStatus'
  Label: __('payment_requests.actions.transition_status')
  Icon: Heroicon::OutlinedArrowRightCircle
  Color: 'info'
  Location: table row + page header (Edit e View)
  Visibility: filled($record->status->allowedTransitions())
              AND Filament::auth()->user()?->can('transitionStatus', $record)
  Authorization: PaymentRequestPolicy::transitionStatus → Operador ou Adm (Cliente NUNCA)

  Modal:
    Heading: __('payment_requests.actions.transition_status')
    Description: __('payment_requests.messages.confirm_transition')
    SubmitLabel: __('common.actions.confirm')

    Field: to_status
      Component: Filament\Forms\Components\Select
      Docs: https://filamentphp.com/docs/5.x/forms/select
      Validation: required; in: valores retornados por allowedTransitions()
      Config: ->label(__('payment_requests.fields.next_status'))
              ->options(fn (PaymentRequest $record): array => collect($record->status->allowedTransitions())
                  ->mapWithKeys(fn (PaymentRequestStatus $status): array => [$status->value => $status->getLabel()])
                  ->all())
              ->required()
              ->native(false)

    Field: notes
      Component: Filament\Forms\Components\Textarea
      Docs: https://filamentphp.com/docs/5.x/forms/textarea
      Validation: nullable; max:1000
      Config: ->label(__('common.fields.notes'))->rows(3)

  Behavior:
    1. try { app(PaymentRequestService::class)->transitionStatus(
             $record, PaymentRequestStatus::from($data['to_status']),
             Filament::auth()->user(), $data['notes'] ?? null) }
    2. catch (BusinessException $e) → Notification::danger($e->getUserMessage()) + $action->halt()
    3. Sucesso → Notification::make()->title(__('payment_requests.messages.status_changed'))->success()->send()
  Notification: __('payment_requests.messages.status_changed')
```

#### 7.5.2 `ExtractBoletoOcrAction` (usada no AttachmentsRelationManager)

```
Action: ExtractBoletoOcrAction
  Location: App\Filament\Resources\PaymentRequests\Actions\ExtractBoletoOcrAction
  Component: Filament\Actions\Action
  Docs: https://filamentphp.com/docs/5.x/actions/overview
  Name: 'extractBoletoOcr'
  Label: __('payment_requests.actions.extract_ocr')
  Icon: Heroicon::OutlinedSparkles
  Color: 'gray'
  Location: row do AttachmentsRelationManager
  Visibility: $record->isPdf() AND $record->attachable?->payment_method === PaymentMethod::Boleto
              AND config('rjet.ocr.enabled')
  Authorization: PaymentRequestPolicy::update sobre o $record->attachable (via AttachmentPolicy::update)
  Confirmation: não (idempotente e não destrutivo)

  Behavior:
    1. $result = app(ExtractBoletoDataAction::class)->fromAttachment($record)
    2. Se ! $result->wasSuccessful → Notification::warning(title ocr_failed, body $result->message); return
    3. $paymentRequest = $record->attachable
       Preencher SOMENTE campos vazios (nunca sobrescrever revisão manual):
         - bankDetails.digitable_line se blank
         - gross_amount se blank (e recalcular net_amount via Service)
         - due_date se blank
       Persistir via PaymentRequestService (nunca ->update() direto no Model)
    4. Notification::success(__('payment_requests.messages.ocr_succeeded'))
    5. $this->dispatch('refresh-attachments') não é necessário (a action roda no próprio RelationManager)
  Notification: sucesso __('payment_requests.messages.ocr_succeeded') | falha __('payment_requests.messages.ocr_failed')
```

#### 7.5.3 `DownloadAttachmentAction`

```
Action: DownloadAttachmentAction
  Location: App\Filament\Resources\PaymentRequests\Actions\DownloadAttachmentAction
  Component: Filament\Actions\Action
  Docs: https://filamentphp.com/docs/5.x/actions/overview
  Name: 'download'
  Label: __('attachments.actions.download')
  Icon: Heroicon::OutlinedArrowDownTray
  Color: 'gray'
  Location: row do AttachmentsRelationManager
  Visibility: sempre
  Authorization: AttachmentPolicy::view (delega ao parent PaymentRequest)
  Behavior:
    - Disco com suporte a URL temporária (S3) → ->url(fn (Attachment $record): string => $record->temporaryUrl())->openUrlInNewTab()
    - Disco local → ->action(fn (Attachment $record) => Storage::disk($record->disk)->download($record->path, $record->original_name))
    - Arquivo ausente → Notification::danger(__('attachments.errors.file_not_found'))
  Nota de segurança: NUNCA expor URL pública permanente; o arquivo é private.
```

#### 7.5.4 `DeletePaymentRequestAction`

```
Action: DeletePaymentRequestAction
  Location: App\Filament\Resources\PaymentRequests\Actions\DeletePaymentRequestAction
  Component: Filament\Actions\DeleteAction    ← wrapper do DeleteAction (padrão DeleteBankAction / DeleteBranchAction da F2)
  Docs: https://filamentphp.com/docs/5.x/actions/overview
  Visibility: Filament::auth()->user()?->isAdm() ?? false
  Authorization: PaymentRequestPolicy::delete → apenas Adm, em QUALQUER status (decisão BA #2)
  Confirmation: padrão do DeleteAction (requiresConfirmation nativo)
  Behavior:
    return DeleteAction::make()
        ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
        ->using(function (PaymentRequest $record): void {
            app(PaymentRequestService::class)->delete($record);   // Observer faz o cascade soft
        });
  Notification: padrão do Filament
```

### 7.6 RelationManagers

```
Imports comuns aos dois RelationManagers:
  - Filament\Resources\RelationManagers\RelationManager
  - Filament\Schemas\Schema
  - Filament\Tables\Table
  - Filament\Tables\Columns\TextColumn
  - Filament\Facades\Filament
  - Illuminate\Database\Eloquent\Builder

Imports adicionais do AttachmentsRelationManager:
  - Filament\Forms\Components\FileUpload
  - Filament\Forms\Components\Select
  - Filament\Tables\Filters\SelectFilter
  - Filament\Tables\Filters\TrashedFilter
  - Filament\Actions\CreateAction
  - Filament\Actions\DeleteAction
  - Filament\Actions\BulkActionGroup
  - Filament\Actions\DeleteBulkAction
  - App\Enums\AttachmentType
  - App\Models\Attachment
  - App\Services\AttachmentService
  - App\Filament\Resources\PaymentRequests\Actions\{DownloadAttachmentAction, ExtractBoletoOcrAction}
```

#### 7.6.1 `AttachmentsRelationManager`

```
RelationManager: AttachmentsRelationManager
  Command: php artisan make:filament-relation-manager PaymentRequestResource attachments original_name --generate --soft-deletes --panel=admin --no-interaction
  Location: App\Filament\Resources\PaymentRequests\RelationManagers\AttachmentsRelationManager
  Docs: https://filamentphp.com/docs/5.x/resources/managing-relationships
  Relationship: attachments (morphMany Attachment)
  Title attribute: original_name
  Title: __('common.sections.attachments')
  Can create: sim (upload)      Can edit: não      Can delete: sim (conforme Policy)
  Can reorder: sim (sort_order)

  Form (modal de upload):
    Field: upload
      Component: Filament\Forms\Components\FileUpload
      Docs: https://filamentphp.com/docs/5.x/forms/file-upload
      Validation: required; mimetypes pdf/jpeg/png/webp; max config('rjet.attachments.max_kilobytes')
      Config: ->label(__('attachments.fields.file'))
              ->disk(fn (): string => (string) config('rjet.attachments.disk'))
              ->directory(fn (): string => 'attachments/payment_request')
              ->visibility('private')
              ->maxSize(fn (): int => (int) config('rjet.attachments.max_kilobytes'))
              ->acceptedFileTypes(config('rjet.attachments.accepted_mime_types'))
              ->required()
              ->storeFiles(true)
              ->columnSpanFull()
      Nota: o CreateAction usa ->using() para delegar ao AttachmentService, que preenche
            disk/path/original_name/mime_type/size/type/sort_order

    Field: type
      Component: Filament\Forms\Components\Select
      Docs: https://filamentphp.com/docs/5.x/forms/select
      Validation: nullable; enum AttachmentType
      Config: ->label(__('attachments.fields.type'))->options(AttachmentType::class)->native(false)
              ->default(fn (): string => AttachmentType::Other->value)

  Table:
    Column: original_name  → Filament\Tables\Columns\TextColumn | Docs: .../tables/columns/text | ->searchable(), ->sortable()
    Column: type           → TextColumn | ->badge()
    Column: mime_type      → TextColumn | ->toggleable()
    Column: size           → TextColumn | ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 0, ',', '.').' KB'), ->sortable()
    Column: created_at     → TextColumn | ->dateTime('d/m/Y H:i'), ->sortable()
    Filter: type           → Filament\Tables\Filters\SelectFilter | ->options(AttachmentType::class)
    Filter: trashed        → Filament\Tables\Filters\TrashedFilter | ->visible(só Adm)
    headerActions: [ CreateAction::make()->using(fn (array $data, RelationManager $livewire): Attachment =>
                        app(AttachmentService::class)->storeUploadedFile(...)) ]
    recordActions: [ DownloadAttachmentAction::make(), ExtractBoletoOcrAction::make(), DeleteAction::make() ]
    toolbarActions: [ BulkActionGroup::make([ DeleteBulkAction::make() ]) ]
    ->reorderable('sort_order')
    ->defaultSort('sort_order')
    ->modifyQueryUsing(fn (Builder $query): Builder => $query)   // sem eager load extra necessário
  Authorization: todas as ações via AttachmentPolicy (delegando ao parent PaymentRequest)
```

#### 7.6.2 `StatusHistoriesRelationManager` (somente leitura)

```
RelationManager: StatusHistoriesRelationManager
  Command: php artisan make:filament-relation-manager PaymentRequestResource statusHistories to_status --generate --panel=admin --no-interaction
  Location: App\Filament\Resources\PaymentRequests\RelationManagers\StatusHistoriesRelationManager
  Docs: https://filamentphp.com/docs/5.x/resources/managing-relationships
  Relationship: statusHistories (hasMany PaymentRequestStatusHistory)
  Title: __('common.sections.history')
  READONLY:
    - public function isReadOnly(): bool { return true; }
    - ->headerActions([])  ->recordActions([])  ->toolbarActions([])
    - NÃO gerar form() (sem create/edit)
    ⚠️ Trilha de auditoria append-only: nenhum caminho de UI pode criar/editar/excluir linhas

  Table:
    Column: from_status    → Filament\Tables\Columns\TextColumn | Docs: .../tables/columns/text | ->badge(), ->placeholder('—')
    Column: to_status      → TextColumn | ->badge()
    Column: changedBy.name → TextColumn | ->label(__('payment_request_status_history.fields.changed_by')), ->placeholder('—')
    Column: notes          → TextColumn | ->limit(60), ->tooltip(fn ($record) => $record->notes), ->toggleable()
    Column: created_at     → TextColumn | ->dateTime('d/m/Y H:i'), ->sortable()
    Filters: nenhum
    ->defaultSort('created_at', 'desc')
    ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('changedBy'))
  Authorization: PaymentRequestStatusHistoryPolicy → viewAny/view = quem vê o parent; create/update/delete = false para todos
```

---

## 8. Update: CompanyResource

> **Plano de UPDATE** — sem comando de scaffold. Apenas as alterações abaixo.

```
Resource: CompanyResource (existente)
  Location: App\Filament\Resources\Companies\CompanyResource
  Docs: https://filamentphp.com/docs/5.x/resources/overview

  ADD em Schemas/CompanyForm.php (na Section existente de informações da empresa,
  junto ao Toggle is_active já presente):

    Field: is_appropriation_required
      Component: Filament\Forms\Components\Toggle
      Docs: https://filamentphp.com/docs/5.x/forms/toggle
      Validation: boolean
      Config:
        ->label(__('companies.fields.is_appropriation_required'))
        ->helperText(__('companies.hints.is_appropriation_required'))
        ->default(false)
        ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
      Nota: apenas Adm gerencia cadastros gerenciais (CompanyPolicy já restringe create/update a isAdm)

  ADD em Schemas/CompanyInfolist.php (Section de informações da empresa):

    Entry: is_appropriation_required
      Component: Filament\Infolists\Components\IconEntry
      Docs: https://filamentphp.com/docs/5.x/infolists/icon-entry
      Config: ->label(__('companies.fields.is_appropriation_required')), ->boolean()

  ADD em Tables/CompaniesTable.php:

    Column: is_appropriation_required
      Component: Filament\Tables\Columns\IconColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/icon
      Config: ->label(__('companies.fields.is_appropriation_required')), ->boolean(),
              ->toggleable(isToggledHiddenByDefault: true)

    Filter: is_appropriation_required
      Component: Filament\Tables\Filters\TernaryFilter
      Docs: https://filamentphp.com/docs/5.x/tables/filters/ternary
      Config: ->label(__('companies.fields.is_appropriation_required'))

  ADD em lang/pt_BR/companies.php e lang/en/companies.php:
    fields.is_appropriation_required
    hints.is_appropriation_required
```

---

## 9. Authorization / Policies

### 9.1 `PaymentRequestPolicy` (`App\Policies\PaymentRequestPolicy`)

```
Resource: PaymentRequestResource
  Policy: App\Policies\PaymentRequestPolicy
  Docs: https://filamentphp.com/docs/5.x/resources/overview#authorization
  Registrar em: AppServiceProvider::POLICIES  →  PaymentRequest::class => PaymentRequestPolicy::class
  NÃO usar before() (nenhuma policy do projeto usa)

  Abilities:
    viewAny(User $user): bool
      → true para qualquer usuário autenticado (o escopo é aplicado por scopeVisibleTo no getEloquentQuery)

    view(User $user, PaymentRequest $record): bool
      → $user->role->seesAllBranches() (Operador/Adm) OR usuário está vinculado a $record->branch_id
        (defense in depth contra URL direta — critério RF018)

    create(User $user): bool
      → true para qualquer usuário autenticado (Cliente, Operador e Adm podem criar)

    update(User $user, PaymentRequest $record): bool
      → $record->isEditableBy($user), ou seja:
        Adm      → true em QUALQUER status
        Operador → status ∈ {Requested, Launched}
        Cliente  → status === Requested E filial vinculada

    delete(User $user, PaymentRequest $record): bool
      → $user->isAdm()  (QUALQUER status — decisão BA #2). Cliente e Operador: false

    deleteAny(User $user): bool        → $user->isAdm()
    restore(User $user, PaymentRequest $record): bool     → $user->isAdm()
    forceDelete(User $user, PaymentRequest $record): bool → $user->isAdm()

    transitionStatus(User $user, PaymentRequest $record): bool
      → ($user->isOperador() || $user->isAdm()) AND filled($record->status->allowedTransitions())
        Cliente: SEMPRE false (Cliente não altera status)

    manageAttachments(User $user, PaymentRequest $record): bool
      → mesma regra de update()
```

### 9.2 Matriz consolidada (referência para os testes)

| Ability | Cliente | Operador | Adm |
|---------|:-------:|:--------:|:---:|
| `viewAny` | ✅ (escopo aplicado) | ✅ | ✅ |
| `view` | ✅ só filial vinculada | ✅ | ✅ |
| `create` | ✅ | ✅ | ✅ |
| `update` | ✅ só `Requested` + filial vinculada | ✅ `Requested` e `Launched` | ✅ **qualquer status** |
| `delete` (soft) | ❌ | ❌ | ✅ **qualquer status** |
| `restore` / `forceDelete` | ❌ | ❌ | ✅ |
| `transitionStatus` | ❌ | ✅ | ✅ |
| `manageAttachments` | = `update` | = `update` | ✅ |

### 9.3 `AttachmentPolicy`

```
Policy: App\Policies\AttachmentPolicy
  Registrar em AppServiceProvider::POLICIES
  Estratégia: delegar ao parent via $attachment->attachable
  Abilities:
    viewAny(User $user): bool                       → true (autenticado)
    view(User $user, Attachment $a): bool           → $user->can('view', $a->attachable)
    create(User $user): bool                        → true (a autorização real é do parent, checada no RelationManager)
    update(User $user, Attachment $a): bool         → $user->can('manageAttachments', $a->attachable)
    delete(User $user, Attachment $a): bool         → $user->can('manageAttachments', $a->attachable)
    restore / forceDelete(User $user, Attachment $a): bool → $user->isAdm()
  Nota: se $a->attachable for null (órfão), retornar $user->isAdm()
```

### 9.4 `PaymentRequestStatusHistoryPolicy`

```
Policy: App\Policies\PaymentRequestStatusHistoryPolicy
  viewAny(User $user): bool → true
  view(User $user, PaymentRequestStatusHistory $h): bool → $user->can('view', $h->paymentRequest)
  create / update / delete / restore / forceDelete → SEMPRE false (trilha append-only gravada só pelo Service)
```

### 9.5 Visibilidade de campos

| Campo | Regra |
|-------|-------|
| `branch_id` (Form) | Cliente com 1 filial → `->disabled()` (+ `->dehydrated(true)`); Cliente com N filiais → Select limitado às filiais vinculadas; Operador/Adm → qualquer filial ativa |
| `TrashedFilter` (Table) | Visível apenas para Adm |
| Bulk actions de delete/restore/forceDelete | Visíveis apenas para Adm |
| `is_appropriation_required` (CompanyResource) | Visível apenas para Adm |

---

## 10. State Transitions

```
(create) ──────────────> Requested     por C, O, A (via CreatePaymentRequest)
Requested ─────────────> Launched      por O, A (TransitionStatusAction)
Launched  ─────────────> Settled       por O, A (TransitionStatusAction)
qualquer  ──(voltar)──X  NÃO PERMITIDO na Fase 3 (sem regressão)
```

| Regra | Implementação |
|-------|---------------|
| Transição válida | `PaymentRequestStatus::canTransitionTo()` |
| Único caminho de escrita | `PaymentRequestService::transitionStatus()` |
| Autorização | `PaymentRequestPolicy::transitionStatus` (Cliente nunca) |
| Trilha | Linha em `payment_request_status_history` a cada transição (inclusive a criação, com `from_status = null`) |
| Evento | `PaymentRequestStatusChanged` após commit |
| Proibições | **Não** colocar guard de transição em evento `updating` do Model; **não** expor `status` como campo do Form; **não** criar `ApprovalStatus` (é F4) |
| Hook F4 | Aprovações entram **antes** de `Launched`; a F4 apenas estreita `canTransitionTo` |

---

## 11. Events / Jobs / Notifications

### 11.1 Events (`app/Events/PaymentRequest/` — agrupamento por domínio obrigatório)

| Event | Payload | Quando | Fila |
|-------|---------|--------|------|
| `PaymentRequestCreated` | `public readonly PaymentRequest $paymentRequest` | Após commit do `create` (ou de `recordInitialStatus` no fluxo Filament) | Sync |
| `PaymentRequestStatusChanged` | `PaymentRequest $paymentRequest`, `PaymentRequestStatus $from`, `PaymentRequestStatus $to`, `?User $actor` | Após commit da transição | Sync |

- Classes `final`, `declare(strict_types=1)`, `use Dispatchable, SerializesModels;`.
- **Dispatch após commit:** usar `DB::afterCommit(fn () => Event::dispatch(...))` ou `event()` fora do closure da transaction.
- Listener mínimo (opcional): `App\Listeners\PaymentRequest\LogPaymentRequestActivity` — `Log::info` com contexto estruturado (`payment_request_id`, `status`, `actor_id`). Sync.
- **Não** criar eventos de aprovação/lote/CNAB (F4–F7). Documentar em comentário que `PaymentRequestStatusChanged` é o hook de invalidação de cache do dashboard da F8.

### 11.2 Jobs

**Nenhum Job obrigatório no MVP.** O OCR é **síncrono** dentro da Action (volume ~70/dia — RNF011). Se a latência medida passar de ~5s, criar `App\Jobs\PaymentRequest\ProcessBoletoOcrJob` (fila `high`) em fase posterior — **não** implementar agora.

### 11.3 Notifications

Nenhuma `Notification` de Laravel (mail/database) nesta fase. Somente `Filament\Notifications\Notification` in-app:

| Situação | Tipo | Chave |
|----------|------|-------|
| OCR concluído com sucesso | `success` | `payment_requests.messages.ocr_succeeded` |
| OCR falhou / linha não encontrada | `warning` | `payment_requests.messages.ocr_failed` |
| Anexo é imagem (OCR não aplica) | `warning` | `payment_requests.messages.ocr_image_not_supported` |
| OCR desabilitado por config | `warning` | `payment_requests.messages.ocr_disabled` |
| Status alterado | `success` | `payment_requests.messages.status_changed` |
| Regra de negócio violada | `danger` | `$exception->getUserMessage()` |

### 11.4 Scheduling

Nenhum. Command opcional `attachments:clean-orphans` fica **adiado** (volume baixo). Pruning **adiado** (retenção financeira/compliance).

---

## 12. Traduções (i18n)

Criar/atualizar em **`lang/pt_BR/`** e **`lang/en/`** (ambos obrigatórios; mesmas chaves).

### 12.1 Arquivos novos

| Arquivo | Chaves |
|---------|--------|
| `payment_requests.php` | `label`, `plural`, `fields.*`, `sections.*`, `hints.*`, `filters.*`, `actions.*`, `messages.*`, `errors.*` |
| `payment_request_bank_details.php` | `label`, `plural`, `fields.*` |
| `payment_request_status_history.php` | `label`, `plural`, `fields.*` |
| `attachments.php` | `label`, `plural`, `fields.*`, `actions.*`, `messages.*`, `errors.*` |

> `navigation.groups.operations` **já existe** em `lang/pt_BR/navigation.php` e `lang/en/navigation.php` — não criar nem duplicar.

### 12.2 `lang/pt_BR/payment_requests.php` (estrutura obrigatória)

```php
return [
    'label' => 'Solicitação de pagamento',
    'plural' => 'Solicitações de pagamento',

    'fields' => [
        'company' => 'Empresa',
        'branch_id' => 'Filial',
        'supplier_id' => 'Fornecedor',
        'cost_center_id' => 'Centro de custo',
        'appropriation_id' => 'Apropriação',
        'payment_method' => 'Forma de pagamento',
        'gross_amount' => 'Valor bruto',
        'discount_amount' => 'Descontos/deduções',
        'net_amount' => 'Valor líquido',
        'due_date' => 'Vencimento',
        'has_attachments' => 'Anexos',
        'attachments' => 'Anexos',
        'next_status' => 'Novo status',
    ],

    'sections' => [
        'identification' => 'Identificação',
        'amounts' => 'Valores',
        'payment' => 'Pagamento e comprovantes',
        'settlement' => 'Dados de liquidação',
    ],

    'hints' => [
        'select_branch_first' => 'Selecione a filial para carregar as opções.',
        'appropriation_required' => 'Esta empresa exige apropriação na solicitação.',
        'appropriation_optional' => 'Opcional para esta empresa; pode ser preenchida depois.',
        'net_amount_auto' => 'Calculado automaticamente: valor bruto menos descontos.',
        'digitable_line' => 'Preenchida automaticamente quando um boleto em PDF é anexado.',
        'pix_qr_code' => 'Cole aqui o código Pix copia-e-cola (payload do QR Code).',
        'holder_document' => 'CPF ou CNPJ do favorecido (somente números).',
        'settlement' => 'Os campos exibidos variam conforme a forma de pagamento escolhida.',
        'attachments' => 'PDF, JPEG, PNG ou WEBP. Máximo de 10 MB por arquivo. Obrigatório para boleto.',
    ],

    'filters' => [
        'due_from' => 'Vencimento de',
        'due_until' => 'Vencimento até',
        'created_from' => 'Criada de',
        'created_until' => 'Criada até',
    ],

    'actions' => [
        'transition_status' => 'Alterar status',
        'extract_ocr' => 'Ler boleto (OCR)',
    ],

    'messages' => [
        'confirm_transition' => 'Confirme o novo status desta solicitação.',
        'status_changed' => 'Status atualizado com sucesso!',
        'ocr_succeeded' => 'Dados do boleto extraídos com sucesso. Revise antes de salvar.',
        'ocr_failed' => 'Não foi possível ler o boleto automaticamente. Preencha os dados manualmente.',
        'ocr_image_not_supported' => 'Leitura automática disponível apenas para boletos em PDF. Preencha os dados manualmente.',
        'ocr_disabled' => 'Leitura automática de boleto está desabilitada.',
        'ocr_not_found' => 'Nenhuma linha digitável válida foi encontrada no PDF.',
        'no_attachments' => 'Nenhum anexo.',
    ],

    'errors' => [
        'branch_not_allowed' => 'Você não tem permissão para criar solicitações nesta filial.',
        'invalid_status_transition' => 'Esta transição de status não é permitida.',
        'cannot_edit_in_status' => 'Você não pode editar uma solicitação neste status.',
        'appropriation_required' => 'A apropriação é obrigatória para esta empresa.',
        'boleto_attachment_required' => 'Anexe pelo menos um arquivo do boleto.',
        'discount_exceeds_gross' => 'O desconto não pode ser maior que o valor bruto.',
        'invalid_net_amount' => 'Os valores informados são inválidos.',
        'incomplete_bank_details' => 'Os dados de liquidação estão incompletos.',
        'pix_details_incomplete' => 'Informe a chave Pix (tipo + chave) ou o código do QR Code.',
        'transfer_details_incomplete' => 'Preencha todos os dados obrigatórios da transferência.',
        'invalid_holder_document' => 'O CPF/CNPJ do favorecido é inválido.',
    ],
];
```

### 12.3 Adições em `lang/{pt_BR,en}/enums.php`

```php
'payment_request_status' => [
    'requested' => 'Solicitada',       // en: 'Requested'
    'launched' => 'Lançada',           // en: 'Launched'
    'settled' => 'Liquidada',          // en: 'Settled'
],
'deposit_type' => [
    'pix' => 'Pix',                                     // en: 'Pix'
    'transfer' => 'Transferência (TED/DOC/conta)',      // en: 'Bank transfer (wire/account)'
],
'pix_key_type' => [
    'random' => 'Chave aleatória',     // en: 'Random key'
    'cpf' => 'CPF',                    // en: 'Tax ID (CPF)'
    'phone' => 'Telefone',             // en: 'Phone'
    'email' => 'E-mail',               // en: 'Email'
],
'attachment_type' => [
    'boleto' => 'Boleto',              // en: 'Bank slip'
    'other' => 'Outro',                // en: 'Other'
],
```

### 12.4 Adições em arquivos existentes

| Arquivo | Chaves novas |
|---------|--------------|
| `common.php` | `sections.attachments` e `sections.history` (verificar; se ausentes, adicionar), `fields.notes`, `actions.confirm`, `fields.file` |
| `companies.php` | `fields.is_appropriation_required` = “Exigir apropriação na solicitação”; `hints.is_appropriation_required` = “Quando ativo, a apropriação passa a ser obrigatória ao criar solicitações desta empresa.” |
| `suppliers.php` | `errors.cannot_delete_with_payment_requests` |
| `branches.php` | `errors.cannot_delete_with_payment_requests` |
| `banks.php` | `errors.cannot_delete_with_payment_request_bank_details` |
| `cost_centers.php` | `errors.cannot_delete_with_payment_requests` |
| `appropriations.php` | `errors.cannot_delete_with_payment_requests` |
| `validation.php` | `custom.holder_document.invalid`, `custom.digitable_line.invalid` |
| `navigation.php` | `groups.operations` — **já existe**, não duplicar |

**Regra:** nenhum label hardcoded em Form, Table, Infolist, Filter, Action, Notification, Section ou heading de modal.

---

## 13. API REST

**NÃO APLICÁVEL.** Painel Filament interno de BPO; o DRF não exige API mobile/terceiros na Fase 3.

- Não instalar Sanctum/Passport, não criar rotas em `routes/api.php`, não criar API Resources, não configurar Swagger.
- Reavaliar apenas se surgir portal externo/integração (fase futura).

---

## 14. Soft deletes, cascade e storage

### 14.1 Matriz de soft delete

| Entidade | SoftDeletes | Regra |
|----------|:-----------:|-------|
| `PaymentRequest` | ✅ | Soft delete **só Adm**, qualquer status; cascade soft de `bankDetails` + `attachments`; **history preservado** |
| `PaymentRequestBankDetails` | ✅ | Cascade com o parent (soft, restore e force) |
| `Attachment` | ✅ | Soft não apaga arquivo; **arquivo removido só no `forceDeleted`** |
| `PaymentRequestStatusHistory` | ❌ | Trilha imutável. Soft do parent **mantém** as linhas; force do parent remove via FK `cascadeOnDelete` |

### 14.2 Ordem de cascade (crítico — achado DBA #1)

**`CompanyObserver::deleted` (soft):**
1. `PaymentRequest` de todas as branches da company (via Eloquent, `->each->delete()`) → dispara `PaymentRequestObserver` → `bankDetails` + `attachments`
2. `supplierPaymentMethods`
3. `appropriations`
4. Por branch: `costCenters`, depois `bankAccounts`
5. `branches`

**`CompanyObserver::forceDeleted`:** mesma ordem, com `withTrashed()->get()->each->forceDelete()` **via Eloquent** — jamais confiar apenas no `cascadeOnDelete` de `payment_requests.branch_id`, porque o morph `attachments` **não tem FK** e os arquivos ficariam órfãos no S3/local.

**`CompanyObserver::restored`:** simétrico, com threshold (`deleted_at >= threshold`), incluindo PaymentRequests → bankDetails → attachments.

### 14.3 Storage

| Item | Valor |
|------|-------|
| Disco produção | `s3` (private) |
| Disco dev | `local` (`storage/app/private`) via `FILESYSTEM_DISK` |
| Disco efetivo | `config('rjet.attachments.disk')` |
| Diretório | `attachments/payment_request/{payment_request_uuid}/` |
| Nome do arquivo | Hash gerado pelo `store()` / `FileUpload` — **nunca** confiar no nome original no path |
| `original_name` | Preservado em coluna (para download) |
| Visibility | `private` — sempre |
| MIME aceitos | `application/pdf`, `image/jpeg`, `image/png`, `image/webp` |
| MIME rejeitados | Todos os demais — **XML de NF-e é explicitamente rejeitado** |
| Tamanho máximo | 10240 KB (10 MB) — `config('rjet.attachments.max_kilobytes')` |
| Download | `Storage::download` (local) ou `temporaryUrl` de 30 min (S3), sempre após autorização por Policy |
| Validação | Combinar `mimes` + `mimetypes` (MIME real, não só extensão) |
| Antivírus | Fora de escopo (usuários internos autenticados) |
| Pruning | Adiado |

---

## 15. Factories & Seeders

### 15.1 Factories (`database/factories/`, Faker locale `pt_BR`)

```
Factory: PaymentRequestFactory
  definition():
    branch_id      → Branch::factory()
    supplier_id    → Supplier::factory()
    cost_center_id → CostCenter::factory()
    appropriation_id → null
    payment_method → PaymentMethod::Boleto
    status         → PaymentRequestStatus::Requested
    gross_amount   → fake()->randomFloat(2, 100, 10000)
    discount_amount→ 0
    net_amount     → = gross_amount (ajustado nos states que definem desconto)
    due_date       → fake()->dateTimeBetween('now', '+60 days')
    notes          → null
    has_attachments→ false
  States:
    requested() / launched() / settled()
    boleto()               → payment_method Boleto + has(PaymentRequestBankDetailsFactory->boleto(), 'bankDetails')
    depositPix()           → payment_method Deposit + bankDetails state pix()
    depositPixQrCode()     → payment_method Deposit + bankDetails state pixQrCode()
    depositTransfer()      → payment_method Deposit + bankDetails state transfer()
    forBranch(Branch $branch)
    forSupplier(Supplier $supplier)
    withDiscount(string $discount)   → recalcula net_amount com bcsub
    withBoletoAttachment()           → afterCreating: Attachment::factory()->boleto()->for($request, 'attachable')->create() + has_attachments = true
    forCompanyRequiringAppropriation() → cria Company com requiresAppropriation() + Branch + Appropriation e liga appropriation_id
  Usar recycle() para Branch/Company compartilhados quando aplicável.

Factory: PaymentRequestBankDetailsFactory
  definition(): todos os campos condicionais null; payment_request_id → PaymentRequest::factory()
  States:
    boleto()      → digitable_line com linha digitável VÁLIDA (constante fixa no teste, não fake aleatório), barcode
    pix()         → deposit_type Pix, pix_key_type Random, pix_key = fake()->uuid()
    pixCpf()      → deposit_type Pix, pix_key_type Cpf, pix_key = fake()->cpf(false)
    pixQrCode()   → deposit_type Pix, pix_qr_code = string longa de payload
    pixBoth()     → chave + QR (cenário permitido)
    transfer()    → deposit_type Transfer, holder_document = fake()->cnpj(false), bank_id → Bank::factory(),
                    agency, account_number, account_digit, account_type Checking, holder_name
    transferMissingHolderDocument() → transfer() sem holder_document (cenário de falha)

Factory: PaymentRequestStatusHistoryFactory
  definition(): payment_request_id → PaymentRequest::factory(); from_status null; to_status Requested; changed_by → User::factory()

Factory: AttachmentFactory
  definition():
    attachable_type/attachable_id → PaymentRequest::factory() (via ->for())
    type → AttachmentType::Other
    disk → 'local'
    path → 'attachments/payment_request/'.fake()->uuid().'/'.fake()->uuid().'.pdf'
    original_name → 'documento.pdf'
    mime_type → 'application/pdf'
    size → fake()->numberBetween(1024, 500000)
    sort_order → 0
  States:
    boleto()  → type Boleto, mime application/pdf
    image()   → mime image/png, original_name 'comprovante.png'
  Nota: testes que exercitam arquivo real usam Storage::fake() + UploadedFile::fake()

Factory: CompanyFactory (ESTENDER a existente)
  State novo: requiresAppropriation() → ['is_appropriation_required' => true]
```

### 15.2 Seeder

```
Seeder: PaymentRequestSeeder   (idempotente; roda DEPOIS dos seeders de F1/F2)
  1. Garantir 2 companies: uma com is_appropriation_required = true, outra false (updateOrCreate)
  2. Para cada company/branch: criar 3–5 PaymentRequest de demonstração cobrindo
     boleto, depositPix e depositTransfer, em status Requested/Launched/Settled
  3. Cada solicitação com history coerente (criação + transições aplicadas via Service)
  4. NÃO seedar arquivos reais nem executar OCR
  5. Registrar em DatabaseSeeder após os seeders da Fase 2
```

---

## 16. Tests (Pest)

> Diretórios existentes: `tests/Feature/{Models,Policies,Services,Filament}` e `tests/Unit`. `tests/Pest.php` já aplica `RefreshDatabase` em `Feature/`.
> Sempre `Storage::fake()` para anexos e binding de `NullBoletoOcrClient` (ou mock do `BoletoOcrClient`) quando o OCR não é o objeto do teste.

### 16.1 Unit

```
tests/Unit/PaymentRequestStatusTest.php
  - values dos cases são 'requested' | 'launched' | 'settled'
  - getLabel() retorna string não vazia para todos os cases
  - getColor() retorna cor válida (primary|info|danger|warning|success|gray) para todos os cases
  - canTransitionTo: Requested→Launched true | Requested→Settled false | Launched→Settled true
  - canTransitionTo: Launched→Requested false | Settled→qualquer false (sem regressão)
  - allowedTransitions(): Requested = [Launched] | Launched = [Settled] | Settled = []

tests/Unit/NetAmountCalculationTest.php  (dataset)
  - calculateNetAmount('1000.00','0.00') === '1000.00'
  - calculateNetAmount('1000.00','150.50') === '849.50'
  - calculateNetAmount('0.10','0.01') === '0.09'   (precisão bcmath, sem erro de float)
  - calculateNetAmount('1000.00','1000.00') === '0.00'
  - assertAmounts lança discountExceedsGross quando discount > gross
  - assertAmounts lança invalidNetAmount quando gross <= 0
  - assertAmounts lança invalidNetAmount quando discount < 0

tests/Unit/DigitableLineParserTest.php
  - ValidDigitableLine::isValid aceita linha digitável de 47 dígitos válida (constante conhecida)
  - aceita com máscara (pontos/espaços) — normaliza antes de validar
  - rejeita DV inválido
  - rejeita tamanho diferente de 47/48
  - LocalBoletoOcrClient extrai valor e vencimento corretos da linha digitável (fator de vencimento)
```

### 16.2 Feature — Services e domínio

```
tests/Feature/Services/PaymentRequestServiceTest.php
  - create() grava PaymentRequest com status Requested e net_amount = gross − discount
  - create() grava linha de history com from_status null e to_status requested
  - create() dispara PaymentRequestCreated (Event::fake)
  - create() persiste bankDetails 1:1
  - Cliente com 1 filial: create() sem branch_id resolve a filial vinculada
  - Cliente com N filiais: create() sem branch_id usa a filial is_default
  - Cliente com N filiais e sem is_default: create() sem branch_id lança branchNotAllowed
  - Cliente informando filial de outra empresa: lança branchNotAllowed
  - company com is_appropriation_required = true e appropriation_id null: lança appropriationRequired
  - company com is_appropriation_required = false e appropriation_id null: cria normalmente
  - payment_method Boleto sem anexo: lança boletoAttachmentRequired
  - payment_method Boleto com 1 anexo: cria normalmente
  - update() recalcula net_amount no servidor mesmo se o payload enviar valor divergente
  - delete() faz soft delete e mantém as linhas de history

tests/Feature/Services/PaymentRequestBankDetailsValidationTest.php
  - Pix apenas com chave (type + key): válido
  - Pix apenas com pix_qr_code: válido
  - Pix com chave E QR: válido (ambos são permitidos)
  - Pix sem chave e sem QR: lança pixDetailsIncomplete
  - Pix com pix_key_type Cpf e CPF inválido: falha de validação
  - Transfer completo: válido
  - Transfer sem holder_document: lança transferDetailsIncomplete
  - Transfer sem account_type: lança transferDetailsIncomplete
  - Transfer sem bank_id / agency / account_number / account_digit: lança transferDetailsIncomplete (dataset)
  - Transfer com agency_digit null: válido (campo opcional)
  - Transfer com holder_name null: válido (campo opcional)
  - holder_document com CPF válido (11 dígitos): aceito e persistido só com dígitos
  - holder_document com CNPJ válido (14 dígitos): aceito e persistido só com dígitos
  - holder_document com 12 dígitos: lança invalidHolderDocument
  - holder_document com CPF de dígitos verificadores inválidos: lança invalidHolderDocument
  - Boleto sem digitable_line: lança incompleteBankDetails
  - Índice único parcial: criar 2 bankDetails ativos para o mesmo payment_request falha;
    após soft delete do primeiro, o segundo é aceito

tests/Feature/Services/PaymentRequestStatusTransitionTest.php
  - transitionStatus Requested→Launched grava history e dispara PaymentRequestStatusChanged
  - transitionStatus Launched→Settled funciona
  - transitionStatus Requested→Settled lança invalidStatusTransition
  - transitionStatus Settled→Launched lança invalidStatusTransition (sem regressão)
  - cada transição cria exatamente 1 linha nova de history com changed_by correto
```

### 16.3 Feature — Anexos, OCR e storage

```
tests/Feature/AttachmentUploadTest.php
  - Storage::fake: storeUploadedFile grava o arquivo e cria Attachment com disk/path/original_name/mime_type/size
  - MIME não permitido (ex.: text/xml) lança invalidMimeType
  - arquivo acima de 10 MB lança fileTooLarge
  - has_attachments passa a true ao criar o primeiro anexo
  - has_attachments volta a false ao soft-deletar o último anexo
  - soft delete do Attachment NÃO remove o arquivo do storage (assertExists)
  - forceDelete do Attachment REMOVE o arquivo do storage (assertMissing)
  - trait HasAttachments: morphMany retorna apenas anexos do PaymentRequest correto
  - morph map: attachable_type é gravado como 'payment_request'

tests/Feature/BoletoOcrTest.php
  - LocalBoletoOcrClient com PDF de boleto (fixture) retorna wasSuccessful true + digitableLine/amount/dueDate
  - PDF sem linha digitável retorna wasSuccessful false com mensagem amigável
  - arquivo de imagem (image/png) retorna wasSuccessful false (OCR não aplica) e NÃO lança exceção
  - config rjet.ocr.enabled = false usa NullBoletoOcrClient e retorna falha silenciosa
  - exceção no parser é capturada: ExtractBoletoDataAction retorna failed e o upload permanece válido
  - ExtractBoletoOcrAction não sobrescreve digitable_line/gross_amount/due_date já preenchidos
```

### 16.4 Feature — Cascade e integridade

```
tests/Feature/Models/PaymentRequestCascadeTest.php
  - soft delete do PaymentRequest faz soft delete de bankDetails e attachments
  - soft delete do PaymentRequest MANTÉM as linhas de status history
  - restore do PaymentRequest restaura bankDetails e attachments (respeitando threshold)
  - forceDelete do PaymentRequest remove bankDetails, attachments e o arquivo do storage (assertMissing)
  - forceDelete do PaymentRequest remove as linhas de history (FK cascade)
  - soft delete da Company faz cascade nos PaymentRequests das suas branches, com bankDetails e attachments
  - forceDelete da Company remove os arquivos dos anexos do storage (assertMissing) — ordem Eloquent
  - restore da Company restaura PaymentRequests dentro do threshold
  - SupplierService::delete com PaymentRequest ativo lança SupplierException
  - BranchService::delete com PaymentRequest ativo lança BranchException
  - BankService::delete com payment_request_bank_details ativo lança BankException
  - CostCenter e Appropriation: delete com PaymentRequest ativo lança a exception correspondente
```

### 16.5 Feature — Authorization

```
tests/Feature/Policies/PaymentRequestAuthorizationTest.php
  - Cliente vê apenas solicitações das filiais vinculadas (scopeVisibleTo)
  - Operador e Adm veem solicitações de todas as empresas
  - Cliente recebe 403 ao acessar a View de solicitação de outra filial (URL direta)
  - Cliente pode atualizar solicitação Requested da própria filial
  - Cliente NÃO pode atualizar solicitação Launched (dataset com Launched e Settled)
  - Operador pode atualizar Requested e Launched
  - Operador NÃO pode atualizar Settled
  - Adm pode atualizar em qualquer status, inclusive Settled
  - Cliente NÃO pode excluir (delete = false em qualquer status)
  - Operador NÃO pode excluir (delete = false em qualquer status)
  - Adm pode excluir em qualquer status
  - restore/forceDelete: somente Adm
  - transitionStatus: Cliente false | Operador true | Adm true
  - AttachmentPolicy delega ao parent: Cliente de outra filial não pode ver/baixar anexo
  - PaymentRequestStatusHistoryPolicy: create/update/delete false para todos os perfis
```

### 16.6 Feature — Filament (Livewire)

```
tests/Feature/Filament/PaymentRequestResourceTest.php
  Authorization:
    - ListPaymentRequests carrega (assertOk) para Cliente, Operador e Adm
    - Cliente vê apenas seus registros: assertCanSeeTableRecords / assertCanNotSeeTableRecords
    - TrashedFilter visível para Adm e oculto para Cliente/Operador (assertTableFilterExists / visibilidade)
    - DeleteAction oculta para Cliente e Operador; visível para Adm (assertActionHidden / assertActionVisible)

  Create (form condicional — valor principal desta fase):
    - Boleto: digitable_line é obrigatória e os campos de Pix/Transfer estão ocultos
    - Boleto sem anexo: assertHasFormErrors(['attachment_files' => 'required'])
    - Deposit: deposit_type é obrigatório e os campos de boleto estão ocultos
    - Deposit + Pix: pix_key_type/pix_key visíveis; holder_document/bank_id ocultos
    - Deposit + Pix apenas com pix_qr_code: assertHasNoFormErrors
    - Deposit + Pix sem chave e sem QR: assertHasFormErrors nos campos de Pix
    - Deposit + Transfer: holder_document, bank_id, agency, account_number, account_digit, account_type
      visíveis e obrigatórios (assertHasFormErrors quando vazios)
    - Deposit + Transfer: agency_digit e holder_name não são obrigatórios
    - net_amount é atualizado ao preencher gross_amount e discount_amount (assertSchemaStateSet)
    - discount_amount > gross_amount: assertHasFormErrors(['discount_amount' => 'lte'])
    - company com is_appropriation_required = true: appropriation_id obrigatório
      (assertHasFormErrors(['appropriation_id' => 'required']))
    - company com is_appropriation_required = false: appropriation_id opcional (assertHasNoFormErrors)
    - Cliente com 1 filial: branch_id vem pré-preenchido e disabled, e ainda assim é persistido
    - selecionar supplier_id preenche payment_method com a sugestão de paymentMethodFor
      (assertSchemaStateSet)
    - options de cost_center_id ficam vazias antes de escolher a filial

  Filters:
    - filtro status retorna apenas solicitações do status escolhido
    - filtro branch retorna apenas solicitações da filial escolhida
    - filtro company (whereHas branch.company) retorna apenas solicitações da empresa escolhida
    - filtro due_date (range) respeita from/until
    - filtro trashed exibe registros soft-deleted (como Adm)

  Actions:
    - TransitionStatusAction oculta para Cliente e visível para Operador
    - TransitionStatusAction move Requested → Launched e cria linha de history
    - TransitionStatusAction oculta quando o status é Settled (sem transições disponíveis)

  RelationManagers:
    - AttachmentsRelationManager renderiza os anexos do registro
    - StatusHistoriesRelationManager renderiza as linhas de history e NÃO expõe create/edit/delete

tests/Feature/Filament/PaymentRequestSchemaTest.php
  - assertSchemaComponentExists('net_amount', ...) confirma readOnly
  - assertTableColumnExists('net_amount', ...) confirma sortable + money
  - CompanyResource: form contém o Toggle is_appropriation_required visível para Adm
  - CompanyResource: Adm salva is_appropriation_required = true e o valor persiste
```

### 16.7 Notas de execução

- Rodar por arquivo durante o desenvolvimento: `php artisan test --compact --filter=PaymentRequestServiceTest`.
- Fixture de PDF de boleto: `tests/Fixtures/boleto-sample.pdf` (PDF com camada de texto contendo linha digitável válida). Criar junto com os testes.
- Cobertura mínima do projeto: 80%.

---

## 17. Estimativa

| Componente | Complexidade | Tempo |
|------------|--------------|-------|
| Enums (4) + i18n de enums | Baixa | 40 min |
| `config/rjet.php` + `.env.example` | Baixa | 10 min |
| Migrations (5, com unique parcial) | Média | 1h |
| Models (4) + trait `HasAttachments` + helpers/scopes + morph map | Média | 1h30 |
| Observers (2 novos + estender `CompanyObserver`) | **Alta** (ordem de cascade + storage) | 1h30 |
| Exceptions (2 novas + 5 estendidas) | Baixa | 30 min |
| DTOs (3) | Baixa | 30 min |
| `PaymentRequestService` (incl. bcmath e todos os asserts) | **Alta** | 2h30 |
| `AttachmentService` | Média | 1h |
| Rules (`ValidCpfOrCnpj`, `ValidDigitableLine`) | Média | 1h |
| Integration OCR (interface + Local + Null) | **Alta** | 2h30 |
| Actions de domínio (2) | Média | 45 min |
| Events (2) + listener de log | Baixa | 30 min |
| Policies (3) + registro | Média | 1h |
| Guards nos Services existentes (5) | Média | 1h |
| **Filament Form (3 blocos + liquidação condicional + OCR)** | **Muito alta** | **3h30** |
| Filament Table (colunas + 9 filtros + actions) | Média | 1h30 |
| Filament Infolist | Média | 1h |
| Pages (4, com hooks de create/save) | Média | 1h30 |
| RelationManagers (2) | Média | 1h30 |
| Filament Actions custom (4) | Média | 1h30 |
| Update `CompanyResource` (Toggle + infolist + table + filtro) | Baixa | 30 min |
| Traduções (4 novos + 9 atualizações, pt_BR e en) | Média | 1h30 |
| Factories (4 + estender `CompanyFactory`) + Seeder | Média | 1h30 |
| Testes Pest (§16 — ~14 arquivos) | **Muito alta** | **6h** |
| Pint + PHPStan + ajustes | Baixa | 45 min |
| **Total** | — | **≈ 38h** (≈ 5 dias úteis) |

---

## 18. Notas e checklist pré-implementação

### 18.1 Bloqueios que exigem decisão humana ANTES de codar

| # | Item | Ação necessária |
|---|------|-----------------|
| 1 | **Dependência `smalot/pdfparser`** | `AGENTS.md`: “Do not change the application's dependencies without approval”. Pedir aprovação antes de `composer require smalot/pdfparser`. **Sem ela, o OCR não funciona** — alternativa temporária: implementar apenas `NullBoletoOcrClient` + `LocalBoletoOcrClient` marcado como não suportado, mantendo a interface estável. |
| 2 | **Fixture de PDF de boleto** | Necessário um PDF real (ou gerado) com camada de texto e linha digitável válida para `tests/Fixtures/boleto-sample.pdf`. |
| 3 | **`Bank.code`** | Confirmar se a tabela `banks` tem coluna `code`; se sim, usar `->getOptionLabelFromRecordUsing()` no Select `bank_id` para mostrar “código - nome”. |

### 18.2 Armadilhas técnicas (ordenadas por risco)

1. **Section com `->relationship()` + `->visible()`** → nunca esconder a Section de liquidação: se ficar hidden, o Filament **não salva** e pode **apagar** o registro relacionado. Condicionar campo por campo.
2. **`$get()` dentro da Section de liquidação** → o state path é `bankDetails`; usar `$get('../payment_method')` (ou `$get('/payment_method')`). `$get('payment_method')` retorna `null`.
3. **Campos `disabled` não são dehydratados** → sempre acompanhar de `->dehydrated(true)` em `branch_id`, `cost_center_id` e `appropriation_id`.
4. **`net_amount`** → usar `->readOnly()` (permanece dehydratado), **não** `->disabled()`. E recalcular sempre no servidor.
5. **bcmath, nunca float** → `calculateNetAmount` recebe e retorna `string`; usar `bcsub`/`bccomp` com escala 2.
6. **`->unique()` proibido** em `payment_request_bank_details.payment_request_id` → só índice parcial via `DB::statement` + `supportsPartialIndexes()`.
7. **`preventLazyLoading` ativo** → eager load obrigatório em table (`modifyQueryUsing`), infolist (`load()` na View) e RelationManagers.
8. **Morph sem FK** → cascade de `attachments` obrigatoriamente via Eloquent; `forceDelete` do parent deve remover anexos **antes** do DELETE, senão sobram arquivos órfãos no storage.
9. **History append-only** → soft delete do parent **não** apaga history; nenhum caminho de UI cria/edita/exclui linhas.
10. **`saveQuietly()`** no sync de `has_attachments` → evita loops de Observer e ruído no blameable.
11. **OCR nunca lança** → toda falha vira `BoletoOcrResult::failed()` + `Notification::warning`; o upload e o submit continuam válidos.
12. **OCR não sobrescreve** → só preenche campos vazios (RN017.2).
13. **`toolbarActions()` sobrescreve `bulkActions()`** → sempre envolver em `BulkActionGroup`.
14. **Filtro custom em v5** → o formulário do `Filter` usa `->schema([...])` (não `->form([...])`).

### 18.3 Checklist de implementação

- [ ] Aprovação da dependência `smalot/pdfparser`
- [ ] 4 Enums criados com `HasLabel/HasColor/HasIcon` + `canTransitionTo` em `PaymentRequestStatus` + traduções pt_BR/en
- [ ] `config/rjet.php` + variáveis no `.env.example`
- [ ] Migration `is_appropriation_required` em `companies` (**sem** índice) + cast no Model
- [ ] 4 migrations novas na ordem correta, com unique parcial guardado por `supportsPartialIndexes()`
- [ ] Models `PaymentRequest`, `PaymentRequestBankDetails`, `PaymentRequestStatusHistory`, `Attachment` + trait `HasAttachments`
- [ ] `PaymentRequest::scopeVisibleTo`, `isEditableBy`, `requiresAppropriation`, `requiresAppropriationForBranch`
- [ ] Morph map `'payment_request'` em `AppServiceProvider`
- [ ] Relacionamentos inversos em `Branch`, `Supplier`, `CostCenter`, `Appropriation`, `Bank`
- [ ] `PaymentRequestObserver` (soft/restore/forceDeleting) + `AttachmentObserver` (storage + sync)
- [ ] `CompanyObserver` estendido com PaymentRequests **antes** de appropriations/branches (soft, restore, force)
- [ ] Guards em `SupplierService`, `BranchService`, `BankService`, `CostCenterService`, `AppropriationService`
- [ ] Exceptions novas e estendidas + chaves de i18n
- [ ] DTOs (3) com normalização de `holder_document` para dígitos
- [ ] `PaymentRequestService` completo (net bcmath, branch RN012.3, appropriation dinâmica, boleto ≥1 anexo, bank details, transitionStatus, delete)
- [ ] `AttachmentService` (MIME/size, storeMany, force delete)
- [ ] Rules `ValidCpfOrCnpj` e `ValidDigitableLine`
- [ ] Integration OCR PDF-only (interface + Local + Null) + binding condicional
- [ ] Actions `ExtractBoletoDataAction` e `ResolveSupplierPaymentMethodAction`
- [ ] Events (2) + listener de log, dispatch após commit
- [ ] Policies (3) registradas em `AppServiceProvider::POLICIES`
- [ ] `PaymentRequestResource` gerado via artisan, com estrutura de pasta plural e Resource limpo
- [ ] `PaymentRequestForm` — 3 blocos, Form `columns(1)` + Sections `columns(2)`, todos os `visible/required` condicionais
- [ ] `PaymentRequestsTable` — colunas, 9 filtros, `recordActions`/`toolbarActions`
- [ ] `PaymentRequestInfolist` — todas as sections, incluindo anexos e history
- [ ] Pages com `mutateFormDataBeforeCreate`, `beforeCreate`, `afterCreate`, `beforeSave`, `mutateFormDataBeforeSave`
- [ ] 2 RelationManagers (Attachments completo; StatusHistories readonly)
- [ ] 4 Actions custom no padrão `static function make(): Action`
- [ ] `CompanyResource` atualizado (Toggle, IconEntry, IconColumn, TernaryFilter)
- [ ] Traduções pt_BR **e** en completas; zero string hardcoded
- [ ] Factories (4 + `requiresAppropriation` em `CompanyFactory`) e `PaymentRequestSeeder` idempotente
- [ ] Testes Pest de §16 passando
- [ ] `vendor/bin/pint --dirty --format agent` sem apontamentos
- [ ] `php artisan test --compact` verde

### 18.4 O que NÃO fazer

- Não criar API REST, Sanctum/Passport ou Swagger.
- Não criar Widgets nesta fase.
- Não criar Jobs (OCR é síncrono).
- Não criar componentes Livewire custom.
- Não criar `ApprovalStatus`, `ApprovalRule` ou qualquer artefato de workflow (F4).
- Não criar `DepositType::Ted`, `Doc` ou `PixQr`.
- Não adicionar coluna `person_type` em `payment_request_bank_details`.
- Não desnormalizar `company_id` em `payment_requests`.
- Não indexar `is_appropriation_required` nem `pix_qr_code`; não criar índice composto `(status, created_at)`.
- Não usar Tesseract, binário externo ou OCR em nuvem.
- Não implementar OCR de imagem nem leitura de QR Code.
- Não expor `status` como campo editável do Form.
- Não colocar guard de transição em evento `updating` do Model.
- Não implementar pruning nem `attachments:clean-orphans`.

---

## 19. Próximos passos

1. Resolver os bloqueios de §18.1 (dependência do parser PDF, fixture de boleto, coluna `code` em `banks`).
2. Executar `implementer` seguindo a ordem: Enums + config + i18n → migrations → models/traits/observers/morph map → exceptions + services + guards → OCR + actions → events → policies → Filament (Resource + Company Toggle) → factories/seeders → Pint.
3. Executar `tester` com o escopo de §16 (dar prioridade a: cascade com `assertMissing` no Storage, unique parcial 1:1, guards de Bank/Supplier/Branch, `holder_document` só dígitos, form condicional Pix/Transfer).
4. Executar `reviewer` validando as correções do DBA (§25 da arquitetura) e as armadilhas de §18.2.
5. Executar `security` para revisar Policies, escopo por filial, download de anexos private e validação de MIME.

> Referências do projeto: `.ai/skills/filament/SKILL.md` · `.ai/docs/{database,enums,events,error-handling,file-storage,localization,soft-deletes,factories-seeders,performance}.md` · `.ai/checklists.md` · arquitetura `.ai/arquitetura/fase-3-solicitacao-ocr.md`.
