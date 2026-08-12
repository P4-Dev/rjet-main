# Filament Blueprint: Fase 4 — Workflow com alçadas

> **Tipo:** Plano de implementação (Filament Blueprint v5). **NÃO é código de produção.**
> **Escopo:** RF019–RF024 — `ApprovalRule` (CRUD Adm), `Approval` + `ApprovalStatus` (append-only), roteamento automático, Approve/Reject/Resubmit, SLA dias úteis + escalação (sininho), reassign aprovador inativo + `ApprovalReassignment`, fingerprint material (`hasApprovedForLaunch`), extensões Company/PaymentRequest, Notifications.
> **Fonte de requisitos:** [.ai/arquitetura/fase-4-workflow-alcadas.md](../arquitetura/fase-4-workflow-alcadas.md) (decisões BA §20 fechadas · revisão DBA §25 aprovada com ressalvas).
> **Stack:** Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL · Redis (cache/filas).
> **Autor:** blueprint (Cursor Grok 4.5 High) · **Data:** 2026-08-12
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
7. [Filament Resource: ApprovalRule](#7-filament-resource-approvalrule)
8. [Update: CompanyResource (SLA)](#8-update-companyresource-sla)
9. [Update: PaymentRequestResource](#9-update-paymentrequestresource)
10. [Authorization / Policies](#10-authorization--policies)
11. [State Transitions](#11-state-transitions)
12. [Events / Notifications / Schedule](#12-events--notifications--schedule)
13. [Traduções (i18n)](#13-traduções-i18n)
14. [API REST](#14-api-rest)
15. [Soft deletes, cascade e guards](#15-soft-deletes-cascade-e-guards)
16. [Factories & Seeders](#16-factories--seeders)
17. [Tests (Pest)](#17-tests-pest)
18. [Estimativa](#18-estimativa)
19. [Notas e checklist pré-implementação](#19-notas-e-checklist-pré-implementação)
20. [Próximos passos](#20-próximos-passos)

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

### 0.2 Estrutura obrigatória do Resource (pasta PLURAL, Resource LIMPO)

```
app/Filament/Resources/ApprovalRules/            ← PLURAL
├── ApprovalRuleResource.php                     ← final, LIMPO: só delegates
├── Schemas/ApprovalRuleForm.php                 ← final
├── Schemas/ApprovalRuleInfolist.php             ← final (SEMPRE)
├── Tables/ApprovalRulesTable.php                ← final, nome PLURAL
├── Pages/{Create,Edit,List,View}ApprovalRule.php
└── (sem RelationManagers nesta fase)

app/Filament/Resources/PaymentRequests/          ← ESTENDER (já existe F3)
├── Actions/{Approve,Reject,Resubmit,SendForApproval}PaymentRequestAction.php
├── RelationManagers/ApprovalsRelationManager.php
├── Pages/ListPaymentRequests.php                ← tabs
├── Tables/PaymentRequestsTable.php              ← badge + filters
├── Schemas/PaymentRequestInfolist.php           ← section Aprovação
└── PaymentRequestResource.php                   ← registrar RM + eager
```

**VALIDAÇÃO:** se `*Resource.php` contiver `TextInput`, `TextColumn`, `Section`, `Select`, `Toggle` ou qualquer componente de form/table/layout diretamente, **está errado**. Todas as classes são `final` e têm `declare(strict_types=1);`.

**Comando Artisan (SEMPRE usar, NUNCA criar Resource na mão):**

```bash
php artisan make:filament-resource ApprovalRule --generate --soft-deletes --view --panel=admin --no-interaction
php artisan make:filament-relation-manager PaymentRequestResource approvals status --generate --panel=admin --no-interaction
```

### 0.3 Layout — largura efetiva das colunas (crítico)

| Colunas do Form | Colunas da Section | Largura efetiva | OK? |
|---|---|---|---|
| 2 | 2 | 25% | **NÃO** |
| **1** | **2** | **50%** | **Sim ← padrão desta fase** |
| 1 | 1 | 100% | Sim (campos largos) |

**Decisão desta fase:** `Schema::columns(1)` no Form e no Infolist; cada `Section` com `->columns(2)`.

### 0.4 i18n (obrigatório)

Nenhuma string de UI hardcoded. Todo label/heading/placeholder/helperText/notificação usa `__()`. Campos comuns em `common.php`; específicos em `approval_rules.php`, `approvals.php`, `payment_requests.php`, `companies.php`, `notifications.php`, `enums.php`. Arquivos em **`lang/pt_BR/`** e **`lang/en/`** (ambos obrigatórios).

### 0.5 Padrões do projeto (F1–F3 já implementados — seguir sem desviar)

- Models de domínio: `final`, `declare(strict_types=1)`, traits `HasFactory, HasUuid, SoftDeletes, HasBlameable` (quando aplicável), `casts()` como **método**, PK `uuid`.
- Migrations: `timestampsTz()` + `softDeletesTz()` onde aplicável; FK com `->index()` explícito; **enum sempre `string`**, nunca `$table->enum()`.
- Unique parcial: **nunca** `->unique()` na coluna. Sempre `DB::statement` dentro de `if ($this->supportsPartialIndexes())`, com `DROP INDEX IF EXISTS` no `down()`. Helper privado:
  ```php
  private function supportsPartialIndexes(): bool
  {
      return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
  }
  ```
  Copiar de `database/migrations/2026_07_31_101029_create_payment_request_bank_details_table.php`.
- Policies: registrar na constante `AppServiceProvider::POLICIES`.
- Guards de exclusão: método no Service + Action Filament capturando `BusinessException` → `Notification::danger($e->getUserMessage())` → `$action->halt()`.
- Visibilidade PR: **query scope** `scopeVisibleTo` + `getEloquentQuery()` no Resource (já existe).
- `Model::preventLazyLoading()` ativo fora de produção → **eager loading obrigatório**.
- Events/Listeners/Actions: **agrupar por domínio** (`App\Events\Approval\`, `App\Listeners\Approval\`, `App\Actions\Approval\`). Services/DTOs/Exceptions: **flat**.
- Comentários de código em **inglês** (mínimos). Documentação deste blueprint em **pt-BR**.
- Ambiente Docker (`PROJECT.md`): prefixar artisan/pest/pint com `docker compose exec app`.

### 0.6 Decisões FECHADAS — NÃO reabrir / NÃO inventar

| # | Decisão |
|---|----------|
| 1 | PR permanece `Requested`; UI deriva de `Approval` (badge/tabs) |
| 2 | Approve = gate; **não** auto-Launched |
| 3 | **Sem** bypass Adm para lançar sem Approved válido |
| 4 | Sempre `route()` no create (Cliente **ou** interno) |
| 5 | SLA: `companies.approval_sla_business_days` default **2**, Mon–Fri, **sem feriados** F4 |
| 6 | Escalação: **database/sininho only**; `escalated_at` once; **sem** reassign no breach |
| 7 | Aprovador inativo → reassign Adm + `approval_reassignments` |
| 8 | Fingerprint material: amount, supplier, bank details, boleto attachment, branch — **NÃO** cost_center / appropriation / notes |
| 9 | Unique parcial Pending; Service enforce `alreadyPending` |
| 10 | **Não** criar `approval_sla_hours` |
| 11 | **Sem** `ApprovalResource` CRUD — fila via tabs/filters na List de PaymentRequest |
| 12 | **Sem** API REST |

### 0.7 Estado real do código a estender (inspecionado 2026-08-12)

| Artefato | Estado |
|----------|--------|
| `PaymentRequestResource` | Existe; Relations: Attachments + StatusHistories; Actions: Transition/Delete/OCR/Download |
| `ListPaymentRequests` | Sem tabs ainda |
| `CompanyForm` | Tem `is_appropriation_required`; **sem** SLA |
| `PaymentRequestService::transitionStatus` | **Sem** gate de aprovação |
| `PaymentRequest::isEditableBy` | Matriz F3 (Adm always; O Requested/Launched; C Requested+branch) — **estreitar** Pending |
| `UserService::deactivate` | Soft-inactivate; **sem** reassign hook |
| `User::scopeApprovers` / `canApprove()` | Existem |
| `AdminPanelProvider` | **Sem** `->databaseNotifications()` — **adicionar** |
| `routes/console.php` | Só `inspire` — **adicionar** schedule SLA |
| `AppServiceProvider` | Events F3 + POLICIES — estender |
| Navigation groups | `main`, `registrations`, `operations`, `reports`, `settings` (já em lang) |

---

## 1. Visão Geral

A Fase 4 entrega o workflow de alçadas **antes** de `Launched`:

| Entrega | Artefato |
|---------|----------|
| ApprovalRule CRUD Adm | `ApprovalRuleResource` (settings) |
| Approval + ApprovalStatus | Model append-only + `ApprovalsRelationManager` na PR |
| Roteamento automático | Listener `RoutePaymentRequestOnCreated` → `ApprovalService::route` |
| Approve / Reject / Resubmit / Send | Actions em `PaymentRequest` (View/Edit header + table) |
| SLA dias úteis | `companies.approval_sla_business_days` + `BusinessDays` + `approvals:escalate-sla` |
| Escalação SLA | Event + Notification **database only** (sininho) |
| Reassign aprovador inativo | `ApprovalReassignment` + hook `UserService::deactivate` |
| Fingerprint material | `hasApprovedForLaunch()` gate em `transitionStatus(Launched)` |
| Extensões | CompanyResource SLA; PaymentRequestResource tabs/filters/badge/actions |
| Notifications | Assigned/Rejected/Approved mail+db; SLA db only; Reassigned db (+ mail alinhado Assigned) |

**Fora de escopo (não implementar):** API REST, `ApprovalResource` CRUD, Jobs extras além de `ShouldQueue` nas Notifications, feriados BR no SLA, multi-nível sequencial na mesma PR, Widgets, bypass Adm, `approval_sla_hours`, auto-Launched.

---

## 2. Commands (ordem de execução)

> Ambiente Docker (`PROJECT.md`): prefixar tudo com `docker compose exec app`.

```bash
# ── 2.1 Enum ──────────────────────────────────────────────────────────────────
php artisan make:class Enums/ApprovalStatus --no-interaction
# Trocar `final class` por `enum ApprovalStatus: string implements HasLabel, HasColor, HasIcon`

# ── 2.2 Migrations (ORDEM OBRIGATÓRIA) ────────────────────────────────────────
php artisan make:migration add_approval_sla_business_days_to_companies_table --table=companies --no-interaction
php artisan make:migration create_approval_rules_table --create=approval_rules --no-interaction
php artisan make:migration create_approvals_table --create=approvals --no-interaction
php artisan make:migration create_approval_reassignments_table --create=approval_reassignments --no-interaction

# ── 2.3 Models + Factories ────────────────────────────────────────────────────
php artisan make:model ApprovalRule --factory --no-interaction
php artisan make:model Approval --factory --no-interaction
php artisan make:model ApprovalReassignment --factory --no-interaction
php artisan make:class Support/BusinessDays --no-interaction

# ── 2.4 Exceptions / DTOs / Services ──────────────────────────────────────────
php artisan make:class Exceptions/ApprovalException --no-interaction
php artisan make:class Exceptions/ApprovalRuleException --no-interaction
php artisan make:class DTOs/ApprovalRuleData --no-interaction
php artisan make:class Services/ApprovalRuleService --no-interaction
php artisan make:class Services/ApprovalService --no-interaction
# ESTENDER (não recriar): PaymentRequestService, UserService, Company, PaymentRequest, User, Branch

# ── 2.5 Actions (agrupar por domínio) ─────────────────────────────────────────
php artisan make:class Actions/Approval/RoutePaymentRequestForApprovalAction --no-interaction
php artisan make:class Actions/Approval/ApprovePaymentRequestAction --no-interaction
php artisan make:class Actions/Approval/RejectPaymentRequestAction --no-interaction
php artisan make:class Actions/Approval/ResubmitPaymentRequestForApprovalAction --no-interaction
php artisan make:class Actions/Approval/ReassignApprovalAction --no-interaction
php artisan make:class Actions/Approval/EscalateOverdueApprovalsAction --no-interaction

# ── 2.6 Events / Listeners / Notifications ────────────────────────────────────
php artisan make:event Approval/ApprovalAssigned --no-interaction
php artisan make:event Approval/ApprovalReassigned --no-interaction
php artisan make:event Approval/ApprovalSlaBreached --no-interaction
php artisan make:event PaymentRequest/PaymentRequestApproved --no-interaction
php artisan make:event PaymentRequest/PaymentRequestRejected --no-interaction
php artisan make:listener Approval/RoutePaymentRequestOnCreated --no-interaction
php artisan make:listener Approval/SendApprovalAssignedNotification --no-interaction
php artisan make:listener Approval/SendApprovalReassignedNotification --no-interaction
php artisan make:listener Approval/SendApprovalSlaBreachedNotifications --no-interaction
php artisan make:listener PaymentRequest/SendPaymentRequestApprovedNotification --no-interaction
php artisan make:listener PaymentRequest/SendPaymentRequestRejectedNotification --no-interaction
php artisan make:notification ApprovalAssignedNotification --no-interaction
php artisan make:notification ApprovalReassignedNotification --no-interaction
php artisan make:notification ApprovalSlaBreachedNotification --no-interaction
php artisan make:notification PaymentRequestApprovedNotification --no-interaction
php artisan make:notification PaymentRequestRejectedNotification --no-interaction

# ── 2.7 Command ───────────────────────────────────────────────────────────────
php artisan make:command ApprovalsEscalateSlaCommand --no-interaction
# Signature: approvals:escalate-sla {--dry-run}
# Registrar schedule em routes/console.php

# ── 2.8 Policies ──────────────────────────────────────────────────────────────
php artisan make:policy ApprovalRulePolicy --model=ApprovalRule --no-interaction
php artisan make:policy ApprovalPolicy --model=Approval --no-interaction
# ESTENDER PaymentRequestPolicy (approve, reject, resubmitForApproval)

# ── 2.9 Filament Resource + Relation Manager ──────────────────────────────────
php artisan make:filament-resource ApprovalRule --generate --soft-deletes --view --panel=admin --no-interaction
php artisan make:filament-relation-manager PaymentRequestResource approvals status --generate --panel=admin --no-interaction
# Depois: limpar Resource, mover Form/Table/Infolist, criar Actions Filament manuais em PaymentRequests/Actions/

# ── 2.10 Seeder ───────────────────────────────────────────────────────────────
php artisan make:seeder ApprovalRuleSeeder --no-interaction

# ── 2.11 Testes ───────────────────────────────────────────────────────────────
php artisan make:test --pest --unit ApprovalStatusTest --no-interaction
php artisan make:test --pest --unit BusinessDaysTest --no-interaction
php artisan make:test --pest --unit MaterialFingerprintTest --no-interaction
php artisan make:test --pest ApprovalServiceTest --no-interaction
php artisan make:test --pest ApprovalRuleServiceTest --no-interaction
php artisan make:test --pest ApprovalSlaEscalationTest --no-interaction
php artisan make:test --pest ApprovalReassignmentTest --no-interaction
php artisan make:test --pest ApprovalAuthorizationTest --no-interaction
php artisan make:test --pest ApprovalRuleResourceTest --no-interaction
php artisan make:test --pest PaymentRequestApprovalActionsTest --no-interaction

# ── 2.12 Qualidade ────────────────────────────────────────────────────────────
php artisan migrate
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=Approval
```

**Painel Filament (manual, sem make):** em `AdminPanelProvider::panel()`, adicionar `->databaseNotifications()` (sininho).

---

## 3. Models e Migrations

> Schema **DBA-aprovado** (§18 arquitetura). Sem `after()`. Sem índices em snapshots/`material_fingerprint`/`due_at` isolado. Sem `approval_sla_hours`.

### 3.1 Alter `companies` — `approval_sla_business_days`

```
Migration: add_approval_sla_business_days_to_companies_table
  Table: companies
  Add: approval_sla_business_days (unsignedInteger, default 2, NOT NULL)
  Index: NENHUM
  after(): NÃO usar
```

```php
// up()
Schema::table('companies', function (Blueprint $table): void {
    $table->unsignedInteger('approval_sla_business_days')->default(2);
});

// down()
Schema::table('companies', function (Blueprint $table): void {
    $table->dropColumn('approval_sla_business_days');
});
```

**Model `Company` — alterações:**
- `$fillable`: adicionar `approval_sla_business_days`
- `casts()`: `'approval_sla_business_days' => 'integer'`
- **Não** criar relação direta com Approval

### 3.2 Model `ApprovalRule` + migration

```
Migration: create_approval_rules_table
```

```php
Schema::create('approval_rules', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('branch_id')->index()->constrained('branches')->cascadeOnDelete();
    $table->decimal('min_amount', 10, 2);
    $table->decimal('max_amount', 10, 2)->nullable(); // null = open-ended (+∞)
    $table->foreignUuid('approver_user_id')->index()->constrained('users')->restrictOnDelete();
    $table->boolean('is_active')->default(true)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
    $table->index(['branch_id', 'is_active']);
    // Sem unique (branch, min, max) — overlaps intencionais
});
```

**Model `ApprovalRule`:**

```
Namespace: App\Models\ApprovalRule
Traits: HasFactory, HasUuid, SoftDeletes, HasBlameable
SoftDeletes: SIM
Casts:
  min_amount => decimal:2
  max_amount => decimal:2
  is_active => boolean
Fillable: branch_id, min_amount, max_amount, approver_user_id, is_active
Relations:
  branch(): BelongsTo Branch
  approver(): BelongsTo User (foreignKey: approver_user_id)
Scopes:
  scopeActive(Builder): where is_active true
  scopeForBranch(Builder, string $branchId): where branch_id
```

### 3.3 Model `Approval` + migration (+ unique parcial)

```
Migration: create_approvals_table
```

```php
Schema::create('approvals', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('payment_request_id')->index()->constrained('payment_requests')->cascadeOnDelete();
    $table->foreignUuid('approval_rule_id')->nullable()->index()->constrained('approval_rules')->nullOnDelete();
    $table->foreignUuid('approver_user_id')->index()->constrained('users')->restrictOnDelete();
    $table->string('status', 20)->default('pending')->index();
    $table->text('reason')->nullable();
    $table->decimal('amount_snapshot', 10, 2);
    // Audit snapshots — SEM FK / SEM índice
    $table->uuid('branch_id_snapshot');
    $table->uuid('supplier_id_snapshot');
    // SHA-256 hex — SEM índice (comparação em PHP)
    $table->string('material_fingerprint', 64);
    $table->timestampTz('assigned_at');
    $table->timestampTz('due_at'); // coberto pelo composto (status, due_at)
    $table->timestampTz('decided_at')->nullable();
    $table->foreignUuid('decided_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampTz('escalated_at')->nullable(); // SEM índice próprio
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    // NO softDeletes — trilha append-only
    $table->index(['payment_request_id', 'status']);
    $table->index(['status', 'due_at']);
});

if ($this->supportsPartialIndexes()) {
    DB::statement(
        'CREATE UNIQUE INDEX approvals_payment_request_pending_unique ON approvals (payment_request_id) WHERE status = \'pending\''
    );
}

// down():
if ($this->supportsPartialIndexes()) {
    DB::statement('DROP INDEX IF EXISTS approvals_payment_request_pending_unique');
}
Schema::dropIfExists('approvals');

private function supportsPartialIndexes(): bool
{
    return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
}
```

**Model `Approval`:**

```
Namespace: App\Models\Approval
Traits: HasFactory, HasUuid, HasBlameable — SEM SoftDeletes
Casts:
  status => ApprovalStatus::class
  amount_snapshot => decimal:2
  assigned_at, due_at, decided_at, escalated_at => datetime
Fillable: payment_request_id, approval_rule_id, approver_user_id, status, reason,
  amount_snapshot, branch_id_snapshot, supplier_id_snapshot, material_fingerprint,
  assigned_at, due_at, decided_at, decided_by, escalated_at
Relations:
  paymentRequest(): BelongsTo
  approvalRule(): BelongsTo (nullable)
  approver(): BelongsTo User
  decidedBy(): BelongsTo User
  reassignments(): HasMany ApprovalReassignment
Scopes:
  scopePending
  scopeOverdue: pending + due_at < now() + escalated_at null
               + whereHas('paymentRequest')  ← EXCLUI PR soft-deleted
  scopeForApprover(User)
  scopeWithInactiveApprover: whereHas approver is_active=false
Helpers:
  isPending(): bool
```

### 3.4 Model `ApprovalReassignment` + migration

```
Migration: create_approval_reassignments_table
```

```php
Schema::create('approval_reassignments', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('approval_id')->index()->constrained('approvals')->cascadeOnDelete();
    $table->foreignUuid('from_approver_user_id')->index()->constrained('users')->restrictOnDelete();
    $table->foreignUuid('to_approver_user_id')->index()->constrained('users')->restrictOnDelete();
    $table->string('reason', 255)->nullable();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampTz('created_at')->useCurrent();
    // NO updated_at — append-only
    // NO softDeletes
});
```

**Model `ApprovalReassignment`:**

```
Namespace: App\Models\ApprovalReassignment
Traits: HasFactory, HasUuid — SEM SoftDeletes, SEM HasBlameable full
OBRIGATÓRIO:
  public const UPDATED_AT = null;
  // Preferível também: public $timestamps = false; e set created_at no create
  // OU timestamps só created_at — espelhar PaymentRequestStatusHistory:
  //   public $timestamps = false;
  //   public const UPDATED_AT = null;
  //   fillable inclui created_at; setar created_at = now() no Service
Fillable: approval_id, from_approver_user_id, to_approver_user_id, reason, created_by, created_at
Casts: created_at => datetime
Relations: approval(), fromApprover(), toApprover(), createdBy()
```

> **Ressalva DBA:** `const UPDATED_AT = null` é obrigatório. Padrão preferido = espelhar `PaymentRequestStatusHistory` (`$timestamps = false` + setar `created_at` no create).

### 3.5 Extensões em Models existentes

**`PaymentRequest`:**

```php
public function approvals(): HasMany { ... }

public function currentPendingApproval(): ?Approval
{
    return $this->approvals()
        ->where('status', ApprovalStatus::Pending)
        ->latest('assigned_at')
        ->first();
}

public function latestApproval(): ?Approval
{
    return $this->approvals()->latest('assigned_at')->latest('created_at')->first();
}

public function isAwaitingApproval(): bool
{
    return $this->currentPendingApproval() !== null;
}

public function isReturnedToRequester(): bool
{
    $latest = $this->latestApproval();

    return $this->status === PaymentRequestStatus::Requested
        && $latest?->status === ApprovalStatus::Rejected
        && $this->currentPendingApproval() === null;
}

public function hasApprovedForLaunch(): bool
{
    $latest = $this->latestApproval();

    return $latest !== null
        && $latest->status === ApprovalStatus::Approved
        && $latest->material_fingerprint === $this->currentMaterialFingerprint();
}

public function currentMaterialFingerprint(): string
{
    // Ver §6.8 — SHA-256 canônico
}

public function approvalState(): string // virtual UI helper
{
    // awaiting | returned | approved_ready | no_rule | none
}

/**
 * Estreitar isEditableBy (matriz F4 §3.9):
 * - Requested + Pending Approval: somente Adm
 * - Requested + último Rejected (devolvida): Cliente (filial) / Operador / Adm
 * - Requested + último Approved (pronta): Cliente ❌; Operador ✅; Adm ✅
 * - demais status: matriz F3 inalterada
 */
public function isEditableBy(User $user): bool
{
    if ($user->isAdm()) {
        return true;
    }

    if ($this->status === PaymentRequestStatus::Requested && $this->isAwaitingApproval()) {
        return false; // C e O bloqueados enquanto Pending
    }

    if ($user->isOperador()) {
        return in_array($this->status, [
            PaymentRequestStatus::Requested,
            PaymentRequestStatus::Launched,
        ], true);
    }

    // Cliente
    if ($this->status !== PaymentRequestStatus::Requested) {
        return false;
    }

    if ($this->hasApprovedForLaunch()) {
        return false; // Cliente não edita após aprovado válido
    }

    return $user->branches()->whereKey($this->branch_id)->exists();
}
```

**`User`:**

```php
public function approvalRules(): HasMany // como approver
public function approvals(): HasMany // pendências (approver_user_id)
// Já existe: scopeApprovers, canApprove(), isAdm/isOperador/isCliente
```

**`Branch`:**

```php
public function approvalRules(): HasMany
// Guard: BranchService::delete — bloquear se ApprovalRule ativa (não trashed)
// Soft Branch → soft ApprovalRules via Observer/guard (cascadeOnDelete NÃO cobre soft)
```

### 3.6 Ordem de migrations

1. `add_approval_sla_business_days_to_companies_table`
2. `create_approval_rules_table`
3. `create_approvals_table` (+ unique parcial + helper + `down()` DROP)
4. `create_approval_reassignments_table`

### 3.7 Sem alteração de schema em

`payment_request_status_history`, `attachments`, `payment_request_bank_details` — fingerprint lê bank_details/attachments em runtime.

---

## 4. Enums

### 4.1 `ApprovalStatus` (novo)

```
File: app/Enums/ApprovalStatus.php
implements HasLabel, HasColor, HasIcon
```

| Case | Value | Label i18n | Color | Icon |
|------|-------|------------|-------|------|
| Pending | `pending` | Pendente | `warning` | `heroicon-o-clock` |
| Approved | `approved` | Aprovada | `success` | `heroicon-o-check-circle` |
| Rejected | `rejected` | Rejeitada | `danger` | `heroicon-o-x-circle` |

```php
public function getLabel(): string
{
    return match ($this) {
        self::Pending => __('enums.approval_status.pending'),
        self::Approved => __('enums.approval_status.approved'),
        self::Rejected => __('enums.approval_status.rejected'),
    };
}

public function getColor(): string|array|null
{
    return match ($this) {
        self::Pending => 'warning',
        self::Approved => 'success',
        self::Rejected => 'danger',
    };
}

public function getIcon(): string
{
    return match ($this) {
        self::Pending => 'heroicon-o-clock',
        self::Approved => 'heroicon-o-check-circle',
        self::Rejected => 'heroicon-o-x-circle',
    };
}

public function canTransitionTo(self $target): bool
{
    return match ($this) {
        self::Pending => in_array($target, [self::Approved, self::Rejected], true),
        self::Approved, self::Rejected => false,
    };
}
```

**Nota:** Escalação SLA **não** muda status (permanece Pending).

### 4.2 `PaymentRequestStatus` (existente)

- **Não adicionar cases**
- `canTransitionTo` **inalterado**
- Gate de negócio **só** no Service/Policy (`hasApprovedForLaunch`)

---

## 5. Exceções de Domínio

### 5.1 `ApprovalException` extends `BusinessException`

| Factory estático | Quando | userMessage key |
|------------------|--------|-----------------|
| `noMatchingRule()` | Route sem faixa ativa | `approvals.errors.no_matching_rule` |
| `approvalRequired()` | Launch sem Approved válido | `payment_requests.errors.approval_required` |
| `notPending()` | Approve/reject ≠ Pending | `approvals.errors.not_pending` |
| `unauthorizedApprover()` | Sem can_approve ou não designado/Adm | `approvals.errors.unauthorized_approver` |
| `reasonRequired()` | Reject sem motivo (≥5) | `approvals.errors.reason_required` |
| `alreadyPending()` | Route com Pending existente | `approvals.errors.already_pending` |
| `cannotResubmit()` | Resubmit inválido | `approvals.errors.cannot_resubmit` |
| `slaNotConfigured()` | `approval_sla_business_days` ≤ 0 | `approvals.errors.sla_not_configured` |
| `fingerprintMismatch()` | opcional UX | `approvals.errors.fingerprint_mismatch` |

Padrão de factory (espelhar F3):

```php
public static function approvalRequired(): self
{
    return new self(
        'Launch requires a valid approved approval with matching material fingerprint.',
        __('payment_requests.errors.approval_required'),
    );
}
```

### 5.2 `ApprovalRuleException` extends `BusinessException`

| Factory | Quando |
|---------|--------|
| `invalidAmountRange()` | max < min |
| `approverNotEligible()` | sem can_approve / role Cliente / inativo |
| `branchRequired()` | branch inválida |

---

## 6. Camadas de Aplicação

### 6.1 DTO `ApprovalRuleData` (final readonly)

```
Namespace: App\DTOs\ApprovalRuleData
Methods: fromArray(array): self, fromModel(ApprovalRule): self, toArray(): array
Fields: branchId, minAmount, maxAmount (?string/decimal), approverUserId, isActive
```

### 6.2 `ApprovalRuleService` (final)

| Método | Steps |
|--------|-------|
| `create(ApprovalRuleData, User $actor): ApprovalRule` | assertRange; assertApproverEligible; create; return fresh |
| `update(ApprovalRule, ApprovalRuleData, User): ApprovalRule` | idem asserts; update |
| `delete(ApprovalRule): void` | soft delete |
| `activate` / `deactivate` | toggle is_active |
| `assertApproverEligible(User $approver): void` | can_approve && role ∈ {Operador,Adm} && is_active; else approverNotEligible |
| `assertRange(min, max): void` | min ≥ 0; se max filled → max ≥ min |
| `findOverlapping(Branch\|id, min, max, ?excludeId): Collection` | rules ativas mesma branch com overlap de faixa — para warning UI |

### 6.3 `ApprovalService` (final) — núcleo

#### `resolveRule(PaymentRequest $pr): ApprovalRule`

Matching (copiar critério):

```
is_active = true AND deleted_at IS NULL
AND branch_id = PR.branch_id
AND min_amount <= net_amount
AND (max_amount IS NULL OR net_amount <= max_amount)
```

Desempate (ORDER BY):

1. `min_amount DESC`
2. `max_amount ASC NULLS LAST` (null = +∞ perde para teto finito)
3. `created_at DESC`
4. `id DESC`

Se vazio → throw `noMatchingRule()`.

#### `route(PaymentRequest $pr, User $actor): Approval`

Steps concretos:

1. Se `currentPendingApproval()` ≠ null → `alreadyPending()`.
2. `$rule = resolveRule($pr)` (ou catch → rethrow).
3. Validar SLA company: `$days = $pr->branch->company->approval_sla_business_days`; se ≤ 0 → `slaNotConfigured()`.
4. `$assignedAt = now()`; `$dueAt = BusinessDays::add($assignedAt, $days)`.
5. `$fingerprint = $pr->currentMaterialFingerprint()`.
6. DB::transaction + `lockForUpdate` leve na PR se necessário:
   - create Approval: status Pending, snapshots (amount=net, branch_id, supplier_id), fingerprint, rule_id, approver_user_id, assigned_at, due_at, blameable.
7. Após commit: `Event::dispatch(new ApprovalAssigned($approval))`.
8. return `$approval->fresh([...])`.

#### `approve(Approval $approval, User $actor, ?string $notes = null): Approval`

1. Assert Pending; senão `notPending()`.
2. Assert actor autorizado: `can_approve` && (é approver **ou** Adm); senão `unauthorizedApprover()`.
3. Assert PR visível ao actor.
4. Transaction: status Approved; decided_at=now(); decided_by=actor; reason=notes opcional; refresh fingerprint opcional (confirmar igual ao route — se divergiu no meio, preferir falhar ou re-snapshot documentado; **decisão:** no approve, **não** recalcular fingerprint — gate usa o do route; se PR mudou materialmente durante Pending, Adm editou — fingerprint do Approval permanece; launch exigirá match com fingerprint atual → pode precisar Resubmit. OK).
5. Após commit: `PaymentRequestApproved($pr, $approval, $actor)`.

#### `reject(Approval $approval, User $actor, string $reason): Approval`

1. Assert Pending + auth (igual approve).
2. Se `mb_strlen(trim($reason)) < 5` → `reasonRequired()`.
3. Transaction: Rejected; reason; decided_at/by.
4. Após commit: `PaymentRequestRejected(...)`.

#### `resubmit(PaymentRequest $pr, User $actor): Approval`

1. Assert status Requested.
2. Assert sem Pending.
3. Assert (`isReturnedToRequester()` **OU** último Approved com fingerprint divergente **OU** sem Approval / no_rule após correção de regras).
4. Chamar `route($pr, $actor)`.

#### `escalateOverdue(bool $dryRun = false): int`

1. Query `Approval::query()->overdue()->with(['paymentRequest.branch.company', 'approver'])->limit(500)`.
2. `whereHas('paymentRequest')` já no scope (exclui soft-deleted).
3. Para cada: se dryRun, count++; else `escalate($approval)`.
4. return count.

#### `escalate(Approval $approval): void`

1. Se não Pending ou escalated_at filled → return (idempotente).
2. Update escalated_at = now().
3. Dispatch `ApprovalSlaBreached($approval)`.
4. **Não** reassign; **não** mudar status; **não** mail.

#### `reassign(Approval $approval, User $to, User $actor, ?string $reason = null): Approval`

1. Assert Pending.
2. Assert `$to` elegível (Adm ativo — ou can_approve ativo conforme caller).
3. Transaction: from = current approver; update approver_user_id; create ApprovalReassignment (from, to, reason, created_by, created_at=now()).
4. Após commit: `ApprovalReassigned($approval, $reassignment)`.

#### `reassignFromInactiveApprover(User $inactive, User $actor): int`

1. Pending onde approver_user_id = inactive.
2. `$to = User::query()->where('role', Adm)->active()->orderBy('created_at')->first()` (determinístico).
3. Se null → log + skip (não deve ocorrer se guard last admin).
4. Para cada: `reassign(..., reason: 'approver_inactive')`.
5. return count.

### 6.4 Estender `PaymentRequestService`

**`transitionStatus`:** no início (após validar canTransitionTo), **antes** do update:

```php
if ($to === PaymentRequestStatus::Launched && ! $request->hasApprovedForLaunch()) {
    throw ApprovalException::approvalRequired();
}
```

**`update` / bank details / attachments:** não auto-route. Fingerprint diverge naturalmente → launch bloqueado até Resubmit.

**Não** chamar `route()` dentro do create do Service — Listener desacopla.

### 6.5 Hook `UserService::deactivate`

Após `$user->update(['is_active' => false])`:

```php
app(ApprovalService::class)->reassignFromInactiveApprover($user, Auth::user() ?? $user);
```

Também considerar `update` que seta `is_active=false` via form — centralizar em `deactivate` e fazer Filament/UserResource usar o Service (já deve).

**Force-delete User:** FK `restrict` + guard se Approvals Pending / rules ativas — bloquear com exception.

### 6.6 Actions de domínio (`App\Actions\Approval\`)

| Action | Delega para | Uso |
|--------|-------------|-----|
| `RoutePaymentRequestForApprovalAction` | `ApprovalService::route` | Listener + SendForApproval Filament |
| `ApprovePaymentRequestAction` | `approve` | Filament |
| `RejectPaymentRequestAction` | `reject` | Filament |
| `ResubmitPaymentRequestForApprovalAction` | `resubmit` | Filament |
| `ReassignApprovalAction` | `reassign` | UserService / futuro Adm UI |
| `EscalateOverdueApprovalsAction` | `escalateOverdue` | Command |

Todas `final`, `__invoke(...)`, tipadas.

### 6.7 `BusinessDays`

```
Namespace: App\Support\BusinessDays
final class
```

```php
public static function add(\DateTimeInterface $from, int $businessDays): CarbonInterface
{
    // Se businessDays < 1 → tratar como erro no caller (slaNotConfigured)
    // Avança N dias úteis Mon–Fri a partir de $from (não conta o dia atual se já passou? )
    // Contrato: a partir de $from, adiciona N dias úteis futuros.
    // Ex.: sexta + 1 → segunda; sexta + 2 → terça.
    // Ignora sáb/dom; NÃO considera feriados (F4).
}
```

### 6.8 Fingerprint material (`currentMaterialFingerprint`)

Canonical JSON (ksort keys) → `hash('sha256', $json)`:

```
Inclui:
  net_amount (string decimal normalizado, 2 casas)
  branch_id
  supplier_id
  payment_method (value)
  bank_details: campos relevantes persistidos (digitable_line, barcode, pix_*, holder_*, bank_id, agency*, account*, account_type, deposit_type) — nulls omitidos ou null explícito de forma estável
  boleto_attachments: lista ordenada de {id, content_hash|checksum|path+size} dos attachments tipo Boleto não trashed (se payment_method = Boleto)

EXCLUI:
  cost_center_id, appropriation_id, notes, timestamps, status, has_attachments flag bruto
```

Persistir no `route()` em `material_fingerprint` (64 hex).

`hasApprovedForLaunch()` = último Approval por `assigned_at`/`created_at` é Approved **E** fingerprint igual.

---

## 7. Filament Resource: ApprovalRule

### 7.1 Scaffold

```
Resource: ApprovalRuleResource
  Command: php artisan make:filament-resource ApprovalRule --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\ApprovalRules\
  SoftDeletes: getRecordRouteBindingEloquentQuery() sem SoftDeletingScope
  Icon: Heroicon::OutlinedScale
  Navigation:
    Group: __('navigation.groups.settings')
    Sort: 20
    Label: __('approval_rules.navigation_label') / getModelLabel / getPluralModelLabel
  Visibility: canViewAny → só Adm (Policy)
  recordTitleAttribute: opcional — exibir faixa formatada ou id
```

**Eager load em `getEloquentQuery` / route binding:** `branch.company`, `approver`.

### 7.2 Form — `Schemas/ApprovalRuleForm.php`

```
Schema columns(1)

Section: __('approval_rules.sections.rule')
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Config: ->columns(2)

  Field: branch_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required, exists:branches,id
    Config:
      ->label(__('approval_rules.fields.branch_id'))
      ->relationship(
          name: 'branch',
          titleAttribute: 'name',
          modifyQueryUsing: fn (Builder $q): Builder => $q->with('company')->orderBy('name'),
      )
      ->getOptionLabelFromRecordUsing(
          fn (Branch $b): string => "{$b->company?->name} / {$b->name}"
      )
      ->searchable()
      ->preload()
      ->required()
      ->live()
    Imports: Branch, Builder
    Reactive: live para warning de overlap (opcional)

  Field: min_amount
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: required, numeric, min:0, decimal:0,2
    Config:
      ->label(__('approval_rules.fields.min_amount'))
      ->numeric()
      ->prefix('R$')
      ->required()
      ->minValue(0)
      ->step(0.01)
      ->live(onBlur: true)

  Field: max_amount
    Component: Filament\Forms\Components\TextInput
    Docs: https://filamentphp.com/docs/5.x/forms/text-input
    Validation: nullable, numeric, gte:min_amount, decimal:0,2
    Config:
      ->label(__('approval_rules.fields.max_amount'))
      ->numeric()
      ->prefix('R$')
      ->minValue(0)
      ->step(0.01)
      ->helperText(__('approval_rules.hints.max_amount_null'))
      ->nullable()

  Field: approver_user_id
    Component: Filament\Forms\Components\Select
    Docs: https://filamentphp.com/docs/5.x/forms/select
    Validation: required, exists:users,id
    Config:
      ->label(__('approval_rules.fields.approver_user_id'))
      ->options(fn (): array => User::query()
          ->approvers()
          ->active()
          ->whereIn('role', [UserRole::Operador, UserRole::Adm])
          ->orderBy('name')
          ->pluck('name', 'id')
          ->all())
      ->searchable()
      ->preload()
      ->required()
    Nota: Cliente NUNCA aparece. Não exige vínculo N:N com a filial (A9).

  Field: is_active
    Component: Filament\Forms\Components\Toggle
    Docs: https://filamentphp.com/docs/5.x/forms/toggle
    Validation: boolean
    Config:
      ->label(__('common.fields.is_active'))
      ->default(true)
```

**Overlap warning (opcional, Create/Edit pages):** após mutate/before create, se `findOverlapping` não vazio → `Notification::warning(__('approval_rules.messages.overlap_warning'))` — **não** bloqueia (A8).

**Persistência:** Pages Create/Edit devem chamar `ApprovalRuleService` (não `$record->save()` direto se o projeto já usa Services nas pages F2/F3 — seguir padrão existente do recurso Company/Branch; se F3 usa Service nas pages, espelhar).

### 7.3 Infolist — `Schemas/ApprovalRuleInfolist.php`

```
Section: __('approval_rules.sections.rule')
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Config: ->columns(2)

  Entry: branch (company/name)
    Component: Filament\Infolists\Components\TextEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/text-entry
    Config: ->label(...)->formatStateUsing / state path branch.name + company

  Entry: min_amount
    Component: TextEntry
    Config: ->money('BRL')

  Entry: max_amount
    Component: TextEntry
    Config: ->money('BRL')->placeholder('∞')

  Entry: approver.name
    Component: TextEntry

  Entry: is_active
    Component: Filament\Infolists\Components\IconEntry
    Docs: https://filamentphp.com/docs/5.x/infolists/icon-entry
    Config: ->boolean()

  Entry: created_at / updated_at
    Component: TextEntry
    Config: ->dateTime('d/m/Y H:i')
```

### 7.4 Table — `Tables/ApprovalRulesTable.php`

```
Table Docs: https://filamentphp.com/docs/5.x/tables/overview

Column: branch.company.name
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config: ->label(__('approval_rules.fields.company'))->searchable()->sortable()

Column: branch.name
  Component: TextColumn
  Config: ->label(__('approval_rules.fields.branch_id'))->searchable()->sortable()

Column: min_amount
  Component: TextColumn
  Config: ->money('BRL')->sortable()

Column: max_amount
  Component: TextColumn
  Config: ->money('BRL')->sortable()->placeholder('∞')

Column: approver.name
  Component: TextColumn
  Config: ->label(__('approval_rules.fields.approver_user_id'))->searchable()

Column: is_active
  Component: Filament\Tables\Columns\IconColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/icon
  Config: ->boolean()

Column: created_at
  Component: TextColumn
  Config: ->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true)

Filter: branch_id
  Component: Filament\Tables\Filters\SelectFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters/select
  Config: ->relationship('branch', 'name')->searchable()->preload()

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
  Action: DeleteAction
    Component: Filament\Actions\DeleteAction
    Behavior: prefer Service delete; catch BusinessException → danger + halt

toolbarActions:
  BulkActionGroup → DeleteBulkAction, ForceDeleteBulkAction, RestoreBulkAction
  Docs: https://filamentphp.com/docs/5.x/actions/overview

defaultSort: created_at desc
```

### 7.5 Pages

- `ListApprovalRules` — CreateAction header
- `CreateApprovalRule` / `EditApprovalRule` / `ViewApprovalRule` — padrão F3
- Header View: Edit + Delete
- Header Edit: View + Delete

### 7.6 Authorization no Resource

```php
public static function canViewAny(): bool
{
    return Filament::auth()->user()?->can('viewAny', ApprovalRule::class) ?? false;
}
```

---

## 8. Update: CompanyResource (SLA)

### 8.1 Form — adicionar em `CompanyForm` (mesma Section ou Section SLA)

```
Field: approval_sla_business_days
  Component: Filament\Forms\Components\TextInput
  Docs: https://filamentphp.com/docs/5.x/forms/text-input
  Validation: required, integer, min:1, max:365
  Config:
    ->label(__('companies.fields.approval_sla_business_days'))
    ->numeric()
    ->integer()
    ->minValue(1)
    ->maxValue(365)
    ->default(2)
    ->suffix(__('companies.suffixes.business_days'))
    ->helperText(__('companies.hints.approval_sla_business_days'))
    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
    ->required(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
```

**Nota:** mudança de SLA na Company **não** recalcula Approvals Pending (`due_at` é snapshot).

### 8.2 Infolist

```
Entry: approval_sla_business_days
  Component: TextEntry
  Config:
    ->label(__('companies.fields.approval_sla_business_days'))
    ->suffix(' ' . __('companies.suffixes.business_days'))
    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
```

### 8.3 Table (opcional)

```
Column: approval_sla_business_days
  Component: TextColumn
  Config: ->label(...)->sortable()->toggleable(isToggledHiddenByDefault: true)
```

---

## 9. Update: PaymentRequestResource

### 9.1 Resource principal

Registrar RelationManager:

```php
public static function getRelations(): array
{
    return [
        AttachmentsRelationManager::class,
        StatusHistoriesRelationManager::class,
        ApprovalsRelationManager::class, // NOVO
    ];
}
```

Eager load adicional no route binding / List:

```
approvals (latest), current pending, approver
```

Ex.: `->with(['approvals' => fn ($q) => $q->latest('assigned_at'), ...])` — evitar N+1 no badge.

### 9.2 List tabs — `Pages/ListPaymentRequests.php`

```
Docs: https://filamentphp.com/docs/5.x/resources/listing#customizing-the-tabs-query

use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;

public function getTabs(): array
{
    $user = Filament::auth()->user();

    $tabs = [
        'all' => Tab::make(__('payment_requests.tabs.all')),
    ];

    if ($user?->canApprove()) {
        $tabs['awaiting_my_approval'] = Tab::make(__('payment_requests.tabs.awaiting_my_approval'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingApprovalFor($user))
            ->badge(fn (): int => PaymentRequest::query()
                ->visibleTo($user)
                ->awaitingApprovalFor($user)
                ->count())
            ->badgeColor('warning');
    }

    if ($user?->isAdm()) {
        $tabs['all_pending_approvals'] = Tab::make(__('payment_requests.tabs.all_pending_approvals'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingAnyApproval())
            ->badge(fn (): int => PaymentRequest::query()->awaitingAnyApproval()->count())
            ->badgeColor('warning');
    }

    $tabs['returned'] = Tab::make(__('payment_requests.tabs.returned'))
        ->modifyQueryUsing(fn (Builder $query): Builder => $query->returnedToRequester());

    return $tabs;
}
```

**Scopes obrigatórios no Model `PaymentRequest` (usar nas tabs — evita SQL frágil):**

```php
public function scopeAwaitingApprovalFor(Builder $query, User $user): Builder
{
    return $query->whereHas('approvals', function (Builder $q) use ($user): void {
        $q->where('status', ApprovalStatus::Pending)
            ->where('approver_user_id', $user->getKey());
    });
}

public function scopeAwaitingAnyApproval(Builder $query): Builder
{
    return $query->whereHas(
        'approvals',
        fn (Builder $q): Builder => $q->where('status', ApprovalStatus::Pending),
    );
}

public function scopeReturnedToRequester(Builder $query): Builder
{
    // Requested + sem Pending + o Approval mais recente (por assigned_at) é Rejected
    return $query
        ->where('status', PaymentRequestStatus::Requested)
        ->whereDoesntHave(
            'approvals',
            fn (Builder $q): Builder => $q->where('status', ApprovalStatus::Pending),
        )
        ->whereHas('approvals', function (Builder $q): void {
            $q->where('status', ApprovalStatus::Rejected)
                ->whereRaw('approvals.assigned_at = (
                    SELECT MAX(a2.assigned_at) FROM approvals a2
                    WHERE a2.payment_request_id = payment_requests.id
                )');
        });
}
```

Tabs devem chamar: `->awaitingApprovalFor($user)`, `->awaitingAnyApproval()`, `->returnedToRequester()`.

### 9.3 Table — badge + filters

```
Column: approval_state (virtual)
  Component: Filament\Tables\Columns\TextColumn
  Docs: https://filamentphp.com/docs/5.x/tables/columns/text
  Config:
    ->label(__('payment_requests.fields.approval_state'))
    ->badge()
    ->state(fn (PaymentRequest $record): string => $record->approvalState())
    ->formatStateUsing(fn (string $state): string => __("payment_requests.approval_states.{$state}"))
    ->color(fn (string $state): string => match ($state) {
        'awaiting' => 'warning',
        'returned' => 'danger',
        'approved_ready' => 'success',
        'no_rule' => 'gray',
        default => 'gray',
    })

Filter: awaiting_my_approval
  Component: Filament\Tables\Filters\Filter / TernaryFilter
  Docs: https://filamentphp.com/docs/5.x/tables/filters
  Config: query whereHas Pending + approver = auth; visible se canApprove

Filter: approval_status
  Component: SelectFilter
  Config:
    ->label(__('payment_requests.filters.approval_status'))
    ->options(ApprovalStatus::class)
    ->query(fn (Builder $q, array $data) => ...) // filtrar pelo ÚLTIMO approval
```

### 9.4 Infolist — Section Aprovação

```
Section: __('payment_requests.sections.approval')
  Docs: https://filamentphp.com/docs/5.x/schemas/sections
  Config: ->columns(2)->collapsible()

  Entry: approval_state badge
  Entry: current pending approver.name
  Entry: due_at (se Pending)
  Entry: escalated_at (se filled)
  Entry: latest reason (se Rejected)
  Entry: hasApprovedForLaunch (IconEntry boolean) — útil para O/A
```

### 9.5 Actions Filament (`PaymentRequests/Actions/`)

#### ApprovePaymentRequestAction

```
Action: ApprovePaymentRequestAction
  Component: Filament\Actions\Action
  Docs: https://filamentphp.com/docs/5.x/actions/overview
  Location: ViewPaymentRequest + EditPaymentRequest header; opcional recordActions table
  Visibility:
    fn (PaymentRequest $record): bool =>
      $record->currentPendingApproval() !== null
      && (Filament::auth()->user()?->can('approve', $record) ?? false)
  Authorization: PaymentRequestPolicy::approve
  Config:
    ->label(__('payment_requests.actions.approve'))
    ->icon(Heroicon::OutlinedCheckCircle)
    ->color('success')
    ->requiresConfirmation()
    ->modalHeading(__('payment_requests.actions.approve'))
    ->modalDescription(__('payment_requests.messages.confirm_approve'))
  Behavior:
    1. abort_unless can approve
    2. try ApprovalService::approve(currentPendingApproval, user)
    3. catch BusinessException → danger toast + halt
    4. success toast __('payment_requests.messages.approved')
    5. $this->refreshFormData / redirect refresh
```

#### RejectPaymentRequestAction

```
Action: RejectPaymentRequestAction
  Component: Filament\Actions\Action
  Docs: https://filamentphp.com/docs/5.x/actions/overview
  Visibility: can('reject') && Pending
  Config:
    ->label(__('payment_requests.actions.reject'))
    ->icon(Heroicon::OutlinedXCircle)
    ->color('danger')
    ->requiresConfirmation()
    ->schema([
        Textarea::make('reason')
          ->label(__('approvals.fields.reason'))
          ->required()
          ->minLength(5)
          ->rows(3),
    ])
  Behavior: ApprovalService::reject(..., $data['reason']) → toast
```

#### ResubmitForApprovalAction

```
Action: ResubmitForApprovalAction
  Component: Filament\Actions\Action
  Visibility:
    can('resubmitForApproval') && (
      isReturnedToRequester()
      OR (latest Approved && !hasApprovedForLaunch())
      OR (Requested && !Pending && !hasApprovedForLaunch())
    )
  Config:
    ->label(__('payment_requests.actions.resubmit'))
    ->icon(Heroicon::OutlinedArrowPath)
    ->color('warning')
    ->requiresConfirmation()
  Behavior: ApprovalService::resubmit → toast
    catch noMatchingRule → warning toast (PR intacta)
```

#### SendForApprovalAction (manual se route falhou no create)

```
Action: SendForApprovalAction
  Visibility:
    status Requested
    && !Pending
    && !hasApprovedForLaunch()
    && can update (ou ability dedicada)
  Config:
    ->label(__('payment_requests.actions.send_for_approval'))
    ->icon(Heroicon::OutlinedPaperAirplane)
  Behavior: RoutePaymentRequestForApprovalAction / ApprovalService::route
    catch noMatchingRule → danger/warning
```

#### TransitionStatusAction (existente)

Manter. Service agora lança `approvalRequired` → toast danger já tratado via `BusinessException`.

### 9.6 Header actions — View / Edit

Ordem sugerida:

```
EditAction,
ApprovePaymentRequestAction::make(),
RejectPaymentRequestAction::make(),
ResubmitForApprovalAction::make(),
SendForApprovalAction::make(),
TransitionStatusAction::make(),
DeletePaymentRequestAction::make(),
```

### 9.7 RelationManager `ApprovalsRelationManager`

```
Command: php artisan make:filament-relation-manager PaymentRequestResource approvals status --generate --panel=admin --no-interaction
Relationship: approvals
Read-only: SEM CreateAction / EditAction / DeleteAction

Table columns:
  Column status → TextColumn badge (ApprovalStatus)
  Column approver.name → TextColumn
  Column amount_snapshot → TextColumn money
  Column assigned_at → dateTime
  Column due_at → dateTime
  Column decided_at → dateTime
  Column decidedBy.name → TextColumn
  Column reason → TextColumn wrap / limit
  Column escalated_at → dateTime placeholder('—')
  Column material_fingerprint → TextColumn toggleable hidden (debug Adm)

defaultSort: assigned_at desc

Opcional: nested / second table ou View modal mostrando reassignments:
  fromApprover → toApprover, reason, created_at
  (pode ser TextColumn state via formatStateUsing contando reassignments,
   ou RelationManager separado só se necessário — preferir coluna
   "Reatribuições" com tooltip / ViewAction modal read-only)

Authorization: view se PaymentRequestPolicy::view
```

**Sem ApprovalResource CRUD.**

---

## 10. Authorization / Policies

### 10.1 `ApprovalRulePolicy`

| Ability | Cliente | Operador | Adm |
|---------|:-------:|:--------:|:---:|
| viewAny / view / create / update / delete / restore / forceDelete | ❌ | ❌ | ✅ |

### 10.2 `ApprovalPolicy`

| Ability | Regra |
|---------|--------|
| viewAny | false (sem Resource) |
| view | user vê o PaymentRequest pai |
| create/update/delete | **false** (append-only via Service) |

### 10.3 Estender `PaymentRequestPolicy`

```php
public function approve(User $user, PaymentRequest $paymentRequest): bool
{
    if (! $user->canApprove() || ! $paymentRequest->isVisibleTo($user)) {
        return false;
    }
    $pending = $paymentRequest->currentPendingApproval();
    if ($pending === null) {
        return false;
    }
    return $user->isAdm() || $pending->approver_user_id === $user->getKey();
}

public function reject(User $user, PaymentRequest $paymentRequest): bool
{
    return $this->approve($user, $paymentRequest); // mesma regra
}

public function resubmitForApproval(User $user, PaymentRequest $paymentRequest): bool
{
    return $this->update($user, $paymentRequest)
        && $paymentRequest->status === PaymentRequestStatus::Requested;
}
```

**`update` / `isEditableBy`:** aplicar matriz §3.9 (Pending bloqueia C/O; Adm pode).

**`transitionStatus`:** manter O/A; gate Approved fica no Service (defense in depth). Policy não precisa checar fingerprint (Service lança).

### 10.4 Registrar em `AppServiceProvider::POLICIES`

```php
ApprovalRule::class => ApprovalRulePolicy::class,
Approval::class => ApprovalPolicy::class,
```

### 10.5 Matriz resumo ações PR

| Ability | Cliente | Operador | Operador+can_approve | Adm | Adm+can_approve |
|---------|:-------:|:--------:|:--------------------:|:---:|:---------------:|
| Ver Approvals RO | ✅ | ✅ | ✅ | ✅ | ✅ |
| Fila minhas pendências | ❌ | ❌ | ✅ próprias | — | ✅ todas |
| approve/reject designado | ❌ | ❌ | ✅ se assignee | ❌* | ✅ qualquer |
| resubmit | ✅ se editável | ✅ | ✅ | ✅ | ✅ |
| Launch | ❌ | ✅+gate | ✅+gate | ✅+gate | ✅+gate |
| Config SLA | ❌ | ❌ | ❌ | ✅ | ✅ |

\* Adm **sem** `can_approve` não aprova (A14).

---

## 11. State Transitions

### 11.1 ApprovalStatus

```
Pending → Approved   (Action: Approve)
Pending → Rejected   (Action: Reject, requires reason ≥5)
Approved → ∅
Rejected → ∅
```

Escalação SLA: Pending permanece Pending; só preenche `escalated_at`.

### 11.2 PaymentRequestStatus + gate

```
Requested → Launched  (TransitionStatusAction)
  Service: exige hasApprovedForLaunch()
  Senão: ApprovalException::approvalRequired()

Launched → Settled    (inalterado F3)
```

Approve **não** muda status da PR.

### 11.3 Ciclo de vida UI (derivado)

```
create → route → Aguardando (Pending)
reject → Devolvida
resubmit → Aguardando
approve → Aprovada (pronta p/ lançar)
O/A launch → Launched
```

---

## 12. Events / Notifications / Schedule

### 12.1 Events

| Event | Namespace | Payload | Quando |
|-------|-----------|---------|--------|
| `ApprovalAssigned` | `App\Events\Approval\` | Approval | após route/reassign create cycle |
| `ApprovalReassigned` | `App\Events\Approval\` | Approval, ApprovalReassignment | após reassign |
| `ApprovalSlaBreached` | `App\Events\Approval\` | Approval | escalate |
| `PaymentRequestApproved` | `App\Events\PaymentRequest\` | PR, Approval, User | approve |
| `PaymentRequestRejected` | `App\Events\PaymentRequest\` | PR, Approval, User | reject |
| `PaymentRequestCreated` | existente F3 | PR | + novo listener route |

Classes `final`, past tense. Dispatch **após** commit da transaction.

### 12.2 Listeners

| Listener | Event | ShouldQueue | Comportamento |
|----------|-------|:-----------:|---------------|
| `RoutePaymentRequestOnCreated` | PaymentRequestCreated | ❌ sync | try route; catch noMatchingRule → Log::warning (não rethrow fatal); toast só se no request Filament |
| `SendApprovalAssignedNotification` | ApprovalAssigned | ✅ | notify approver mail+db |
| `SendApprovalReassignedNotification` | ApprovalReassigned | ✅ | notify novo approver db (+ mail alinhado Assigned) |
| `SendPaymentRequestRejectedNotification` | PaymentRequestRejected | ✅ | notify created_by mail+db |
| `SendPaymentRequestApprovedNotification` | PaymentRequestApproved | ✅ | notify created_by mail+db |
| `SendApprovalSlaBreachedNotifications` | ApprovalSlaBreached | ✅ | notify approver + todos Adm ativos — **database only** |

Registrar em `AppServiceProvider::boot` (padrão F3) **ou** `Event::listen` / discovery — seguir padrão atual do projeto (manual no provider).

**Importante:** manter `LogPaymentRequestActivity` existente; **adicionar** `RoutePaymentRequestOnCreated` sem remover o log.

### 12.3 Notifications (`App\Notifications\`)

Todas `final` + `ShouldQueue` + `Queueable`.

| Notification | via() | Destinatário |
|--------------|-------|--------------|
| `ApprovalAssignedNotification` | `mail`, `database` | approver |
| `ApprovalReassignedNotification` | `database` (+ `mail` opcional alinhado Assigned — **recomendado mail+db** como Assigned) | novo approver |
| `PaymentRequestRejectedNotification` | `mail`, `database` | PR.created_by |
| `PaymentRequestApprovedNotification` | `mail`, `database` | PR.created_by |
| `ApprovalSlaBreachedNotification` | **`database` only** | approver + Adms |

`toMail` / `toArray` / `toDatabase` com strings via `__('notifications.*')`.
Link Filament para a View da PR quando possível (`PaymentRequestResource::getUrl('view', ['record' => $id])`).

### 12.4 Database notifications (sininho) — OBRIGATÓRIO

Em `app/Providers/Filament/AdminPanelProvider.php`:

```php
return $panel
    // ...existing...
    ->databaseNotifications();
```

Docs: https://filamentphp.com/docs/5.x/notifications/database-notifications

Sem isso, canal `database` não aparece no sininho do painel.

### 12.5 Command + Schedule

```
Class: App\Console\Commands\ApprovalsEscalateSlaCommand
Signature: approvals:escalate-sla {--dry-run}
Handle: return app(EscalateOverdueApprovalsAction::class)(dryRun: $this->option('dry-run'))
        ou ApprovalService::escalateOverdue
```

`routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('approvals:escalate-sla')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
    // ->onOneServer(); // se multi-node
```

### 12.6 Jobs

**Não** criar Jobs dedicados nesta fase. `ShouldQueue` nas Notifications basta.

---

## 13. Traduções (i18n)

### 13.1 Arquivos a criar / estender

| Arquivo | Ação |
|---------|------|
| `lang/pt_BR/approval_rules.php` | criar |
| `lang/en/approval_rules.php` | criar |
| `lang/pt_BR/approvals.php` | criar |
| `lang/en/approvals.php` | criar |
| `lang/pt_BR/notifications.php` | criar |
| `lang/en/notifications.php` | criar |
| `lang/pt_BR/enums.php` | adicionar `approval_status` |
| `lang/en/enums.php` | idem |
| `lang/pt_BR/companies.php` | fields/hints/suffixes SLA |
| `lang/en/companies.php` | idem |
| `lang/pt_BR/payment_requests.php` | actions, tabs, approval_states, filters, errors |
| `lang/en/payment_requests.php` | idem |
| `lang/pt_BR/navigation.php` | groups já existem — sem novo group |
| `lang/en/navigation.php` | idem |

### 13.2 `approval_rules.php` (pt_BR) — estrutura

```php
return [
    'label' => 'Regra de alçada',
    'plural' => 'Regras de alçada',
    'navigation_label' => 'Alçadas',

    'fields' => [
        'company' => 'Empresa',
        'branch_id' => 'Filial',
        'min_amount' => 'Valor mínimo',
        'max_amount' => 'Valor máximo',
        'approver_user_id' => 'Aprovador',
    ],

    'sections' => [
        'rule' => 'Faixa e aprovador',
    ],

    'hints' => [
        'max_amount_null' => 'Deixe em branco para faixa sem teto (ilimitado).',
    ],

    'messages' => [
        'overlap_warning' => 'Existe outra regra ativa com faixa sobreposta nesta filial. O desempate em runtime escolherá a mais específica.',
        'created' => 'Regra de alçada criada.',
        'updated' => 'Regra de alçada atualizada.',
        'deleted' => 'Regra de alçada excluída.',
    ],

    'errors' => [
        'invalid_amount_range' => 'O valor máximo deve ser maior ou igual ao mínimo.',
        'approver_not_eligible' => 'O aprovador deve estar ativo, com permissão de aprovar e perfil Operador ou Administrador.',
        'branch_required' => 'Selecione uma filial válida.',
    ],
];
```

### 13.3 `approvals.php` (pt_BR)

```php
return [
    'label' => 'Aprovação',
    'plural' => 'Aprovações',

    'fields' => [
        'status' => 'Status',
        'approver' => 'Aprovador',
        'reason' => 'Motivo',
        'amount_snapshot' => 'Valor (snapshot)',
        'assigned_at' => 'Atribuída em',
        'due_at' => 'Prazo SLA',
        'decided_at' => 'Decidida em',
        'escalated_at' => 'Escalada em',
        'reassignments' => 'Reatribuições',
    ],

    'errors' => [
        'no_matching_rule' => 'Nenhuma regra de alçada ativa corresponde a esta solicitação. Cadastre uma faixa ou ajuste o valor/filial.',
        'not_pending' => 'Esta aprovação não está pendente.',
        'unauthorized_approver' => 'Você não tem permissão para decidir esta aprovação.',
        'reason_required' => 'Informe o motivo da rejeição (mínimo de 5 caracteres).',
        'already_pending' => 'Já existe uma aprovação pendente para esta solicitação.',
        'cannot_resubmit' => 'Não é possível reenviar esta solicitação para aprovação no estado atual.',
        'sla_not_configured' => 'O SLA de aprovação da empresa é inválido.',
        'fingerprint_mismatch' => 'Os dados materiais mudaram após a aprovação. Reenvie para aprovação.',
    ],
];
```

### 13.4 Adds em `payment_requests.php`

```php
'fields' => [
    // ...existentes...
    'approval_state' => 'Aprovação',
],

'sections' => [
    // ...existentes...
    'approval' => 'Aprovação',
],

'tabs' => [
    'all' => 'Todas',
    'awaiting_my_approval' => 'Minhas pendências',
    'all_pending_approvals' => 'Aguardando aprovação',
    'returned' => 'Devolvidas',
],

'approval_states' => [
    'awaiting' => 'Aguardando aprovação',
    'returned' => 'Devolvida',
    'approved_ready' => 'Aprovada (pronta para lançar)',
    'no_rule' => 'Sem regra de alçada',
    'none' => '—',
],

'filters' => [
    // ...existentes...
    'approval_status' => 'Status da aprovação',
    'awaiting_my_approval' => 'Pendentes para mim',
],

'actions' => [
    // ...existentes...
    'approve' => 'Aprovar',
    'reject' => 'Rejeitar',
    'resubmit' => 'Reenviar para aprovação',
    'send_for_approval' => 'Enviar para aprovação',
],

'messages' => [
    // ...existentes...
    'confirm_approve' => 'Confirma a aprovação desta solicitação?',
    'approved' => 'Solicitação aprovada.',
    'rejected' => 'Solicitação rejeitada e devolvida ao solicitante.',
    'resubmitted' => 'Solicitação reenviada para aprovação.',
    'sent_for_approval' => 'Solicitação enviada para aprovação.',
    'routed_no_rule' => 'Solicitação criada, mas nenhuma regra de alçada corresponde. Configure uma faixa ou use Enviar para aprovação.',
],

'errors' => [
    // ...existentes...
    'approval_required' => 'É necessário uma aprovação válida (sem alterações materiais) antes de lançar.',
],
```

### 13.5 Adds em `companies.php`

```php
'fields' => [
    'approval_sla_business_days' => 'SLA de aprovação (dias úteis)',
],
'hints' => [
    'approval_sla_business_days' => 'Prazo em dias úteis (segunda a sexta) para o aprovador decidir. Não recalcula aprovações já pendentes.',
],
'suffixes' => [
    'business_days' => 'dias úteis',
],
```

### 13.6 `enums.php` add

```php
'approval_status' => [
    'pending' => 'Pendente',
    'approved' => 'Aprovada',
    'rejected' => 'Rejeitada',
],
```

### 13.7 `notifications.php` (pt_BR)

```php
return [
    'approval_assigned' => [
        'subject' => 'Nova solicitação aguardando sua aprovação',
        'body' => 'Há uma solicitação de pagamento pendente de aprovação.',
    ],
    'approval_reassigned' => [
        'subject' => 'Aprovação reatribuída a você',
        'body' => 'Uma aprovação pendente foi reatribuída ao seu usuário.',
    ],
    'payment_request_approved' => [
        'subject' => 'Solicitação aprovada',
        'body' => 'Sua solicitação de pagamento foi aprovada.',
    ],
    'payment_request_rejected' => [
        'subject' => 'Solicitação devolvida',
        'body' => 'Sua solicitação de pagamento foi rejeitada. Verifique o motivo e reenvie.',
    ],
    'approval_sla_breached' => [
        'title' => 'SLA de aprovação estourado',
        'body' => 'Uma aprovação pendente ultrapassou o prazo de SLA.',
    ],
];
```

Espelhar chaves em `lang/en/*` com textos em inglês.

---

## 14. API REST

> **NÃO APLICÁVEL** nesta fase (A1). Sem Sanctum/Passport/Swagger/endpoints. Painel Filament interno BPO apenas.

---

## 15. Soft deletes, cascade e guards

| Entidade | SoftDeletes | Notas |
|----------|:-----------:|-------|
| ApprovalRule | ✅ | Lookups ignoram trashed; preferir `is_active=false` |
| Approval | ❌ | Soft PR **mantém** Approvals; forceDelete PR → cascade Approvals (+ reassignments) |
| ApprovalReassignment | ❌ | Append-only; cascade com Approval |
| Company / Branch | ✅ | Soft Branch → soft ApprovalRules via Observer/guard |
| PaymentRequest | ✅ | Soft: Approvals permanecem; filas usam `whereHas('paymentRequest')` |

**Guards:**

1. **BranchService::delete:** bloquear se ApprovalRule não trashed **OU** soft-cascate rules.
2. **UserService::deactivate:** reassign Pending → Adm; force-delete User bloqueado por `restrictOnDelete` + guard se Pending/rules.
3. **Company teardown:** soft ApprovalRules das branches junto cascade existente.
4. **Filas/SLA:** sempre excluir parent soft-deleted via `whereHas('paymentRequest')`.

**Pruning:** não na F4.

---

## 16. Factories & Seeders

### 16.1 Factories

**`ApprovalRuleFactory`**

- Defaults: min 0, max 5000, approver `User::factory()->operador()` com `can_approve=true`, branch, is_active true
- States: `inactive()`, `openEnded()` (max null), `forBranch(Branch)`, `forApprover(User)`, `range(float $min, ?float $max)`

**`ApprovalFactory`**

- States: `pending()`, `approved()`, `rejected()`, `overdue()` (due_at past, escalated null), `escalated()`
- Associa PR + rule + approver; preenche snapshots + fingerprint dummy estável

**`ApprovalReassignmentFactory`**

- from/to users, reason `approver_inactive`, created_at now

**Estender:**

- `CompanyFactory`: state `withApprovalSla(int $days = 2)`
- `PaymentRequestFactory`: `awaitingApproval()`, `returned()`, `approvedPendingLaunch()` via `afterCreating`
- `UserFactory`: já tem approver — garantir state `approver()` seta `can_approve`

### 16.2 Seeder `ApprovalRuleSeeder`

Baseline BA #6 — por filial demo (Altitude/Glow ou seeders F1 existentes):

| Faixa | min | max | Aprovador |
|-------|-----|-----|-----------|
| Baixa | 0 | 5000 | Operador A |
| Média | 5000.01 | 50000 | Operador B / Adm |
| Alta | 50001 | null | Adm |

Companies: garantir `approval_sla_business_days = 2`.

Chamar a partir do `DatabaseSeeder` em ambiente local/demo (seguir padrão F3).

---

## 17. Tests (Pest)

> Ativar skill pest-testing na implementação. Usar factories + `RefreshDatabase` / padrão do projeto. `Notification::fake()` / `Event::fake()` / `Queue::fake()` conforme caso.

### 17.1 Unit

```
tests/Unit/ApprovalStatusTest.php
  - canTransitionTo Pending→Approved/Rejected true; terminais false

tests/Unit/BusinessDaysTest.php
  - sexta + 1 = segunda
  - sexta + 2 = terça
  - não conta sáb/dom
  - feriado BR NÃO é pulado (documentar expectativa F4)

tests/Unit/MaterialFingerprintTest.php
  - mudança net_amount / supplier / branch / bank_details / boleto attachment → hash muda
  - mudança cost_center / appropriation / notes → hash IGUAL
  - hasApprovedForLaunch true só com Approved + match
```

### 17.2 Feature — Services

```
tests/Feature/ApprovalServiceTest.php
  - route após create (Cliente) cria Pending + dispara ApprovalAssigned
  - route após create (Operador) idem (BA #5)
  - route sem regra → noMatchingRule; PR permanece Requested sem Pending
  - segundo route com Pending → alreadyPending
  - approve → Approved + PaymentRequestApproved; PR ainda Requested; launch OK
  - reject sem reason → reasonRequired; com reason OK + event
  - resubmit após Reject cria 2º Approval Pending; histórico preserva Rejected
  - resubmit após edit material (fingerprint diverge)
  - edit só notes/cost_center após Approved → launch ainda OK
  - transitionStatus(Launched) sem Approved → approvalRequired
  - Adm sem Approved → bloqueado (BA #3)
  - unique parcial: 2 Pending mesma PR falha no DB (pgsql/sqlite)

tests/Feature/ApprovalRuleServiceTest.php
  - approver Cliente / sem can_approve / inativo → approverNotEligible
  - max < min → invalidAmountRange
  - findOverlapping detecta overlap

tests/Feature/ApprovalSlaEscalationTest.php
  - escalateOverdue marca escalated_at e dispara event UMA vez
  - segunda run não re-dispara
  - Notification::assertSentTo → via apenas database (SLA)
  - soft-deleted PR não entra na query overdue

tests/Feature/ApprovalReassignmentTest.php
  - deactivate approver → reassign para Adm mais antigo; cria ApprovalReassignment
  - ApprovalReassigned disparado; novo approver notificado
  - force delete User com Pending bloqueado (restrict/guard)
```

### 17.3 Feature — Authorization

```
tests/Feature/ApprovalAuthorizationTest.php
  - Cliente não approve/reject
  - assignee com can_approve approve OK
  - Adm sem can_approve negado
  - Adm com can_approve aprova qualquer Pending
  - ApprovalRule CRUD só Adm
  - Cliente vê Approvals RO da PR visível
```

### 17.4 Feature — Filament

```
tests/Feature/Filament/ApprovalRuleResourceTest.php
  - Adm acessa List/Create
  - Operador/Cliente 403 / forbidden

tests/Feature/Filament/PaymentRequestApprovalActionsTest.php
  - Approve/Reject/Resubmit actions visíveis conforme policy
  - Reject exige reason
  - List tab awaiting_my_approval filtra
  - Company form SLA visível Adm
  - Transition para Launched sem Approved mostra erro (toast/exception)
```

### 17.5 Cascade

```
  - soft PR mantém Approvals
  - forceDelete PR remove Approvals + reassignments
  - soft Branch soft-deleta/rules guard conforme implementação
```

---

## 18. Estimativa

| Componente | Complexidade | Tempo estimado |
|------------|--------------|----------------|
| Enum + i18n base + BusinessDays | Baixa | 30 min |
| Migrations (4) + Models + extensões | Média | 1h 30 min |
| Exceptions + DTOs + ApprovalRuleService | Média | 45 min |
| ApprovalService (route/approve/reject/resubmit/escalate/reassign) + fingerprint | Alta | 3h |
| Estender PaymentRequestService + isEditableBy + UserService hook | Média | 1h |
| Events + Listeners + Notifications + AdminPanelProvider bell | Média | 1h 30 min |
| Command + Schedule | Baixa | 20 min |
| Policies | Média | 40 min |
| ApprovalRuleResource Filament completo | Média | 1h 30 min |
| Company SLA + PaymentRequest tabs/filters/badge/actions/RM | Alta | 2h 30 min |
| Factories + Seeder | Baixa | 40 min |
| Testes Pest (§17) | Alta | 3h |
| Pint + ajustes manuais | Baixa | 30 min |
| **Total** | — | **~17–18h** |

---

## 19. Notas e checklist pré-implementação

### 19.1 Ressalvas DBA §25 (OBRIGATÓRIO — copiar para o implementer)

1. **Copiar helper `supportsPartialIndexes()`** das migrations F1/F3 (`pgsql` + `sqlite`); unique parcial Pending com `DROP INDEX IF EXISTS` no `down()`. **Nunca** `->unique()` no Blueprint nem `SchemaGrammar::supportsPartialIndexes()` solto.
2. **`ApprovalReassignment`:** `public const UPDATED_AT = null;` (espelhar `PaymentRequestStatusHistory`).
3. **Filas / SLA:** `whereHas('paymentRequest')` para **excluir PR soft-deleted**; eager `with(['paymentRequest.branch.company', 'approver'])`.
4. **Soft Branch → soft rules** via Observer/guard — `cascadeOnDelete` **não** cobre soft-delete.
5. **Force User** bloqueado por `restrictOnDelete` + guard se Approvals Pending / rules ativas.
6. **Não criar `approval_sla_hours`.**
7. **Sem índice** em snapshots / `material_fingerprint` / `due_at` isolado / `escalated_at` / `approval_sla_business_days`.
8. **Sem `after()`** no alter `companies`.
9. Soft PR mantém Approvals; force PR remove Approvals + reassignments (FK cascade).

### 19.2 Checklist pré-código

- [ ] Ler §0 deste blueprint (namespaces, Resource limpo, live(), Heroicon enum)
- [ ] Confirmar decisões §0.6 — não reabrir BA
- [ ] `AdminPanelProvider` → `databaseNotifications()`
- [ ] Schedule em `routes/console.php`
- [ ] Events/Listeners/Actions em pastas de domínio
- [ ] Gate Launched no Service + fingerprint
- [ ] Sem ApprovalResource CRUD
- [ ] Sem API REST
- [ ] i18n pt_BR + en completos
- [ ] Testes §17 + Pint

### 19.3 Riscos a mitigar nos testes

| Risco | Mitigação |
|-------|-----------|
| Unique parcial esquecido no SQLite | helper supportsPartialIndexes + teste |
| Notify falha reverte Approval | fake queue; assert estado Intact |
| SLA loop | escalated_at once |
| Fingerprint flaky (ordem JSON) | ksort + normalização decimal |
| Tab SQL “último Rejected” ambígua | scope no Model testado |

---

## 20. Próximos passos

1. **Revisar** este blueprint (humano / reviewer leve).
2. Executar **`/feature`** ou acionar **`implementer`** seguindo a ordem do §2 e handoff arquitetura:
   1. Enum + i18n + BusinessDays + migration companies SLA  
   2. Migrations rules/approvals/reassignments  
   3. Models + helpers + fingerprint  
   4. Exceptions + Services + hooks  
   5. Events/Listeners/Notifications + sininho  
   6. Actions + Command/Schedule  
   7. Policies  
   8. Filament  
   9. Factories/Seeders  
   10. Pint + Pest  
3. **`tester`** — cobertura §17.  
4. **`reviewer`** — gate launch, fingerprint, idempotência SLA, reassign, isolation notify + ressalvas DBA.

---

*Fim do Filament Blueprint — Fase 4. Documento autocontido para o `implementer`.*
