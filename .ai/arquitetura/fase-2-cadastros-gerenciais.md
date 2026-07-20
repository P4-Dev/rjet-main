# Arquitetura: Fase 2 — Cadastros gerenciais

> **Escopo:** RF008–RF011 (centros de custo, apropriações, fornecedores com override de forma de pagamento, bancos) + fechamento do stub `Bank` da Fase 1 (`BranchBankAccount.bank_id`).
> **Fora de escopo:** Fases 3–8 (`PaymentRequest`, anexos, workflow, CNAB, relatórios). `DepositType` / `PixKeyType` ficam na Fase 3.
> **Stack:** Laravel 12 · Filament 5 · PHP 8.4 · Pest 4 · Livewire 4 · PostgreSQL · Redis (cache/filas).
> **Fonte:** [.ai/requisitos/drf-financeiro-v1.md](../requisitos/drf-financeiro-v1.md) · alinhamento [.ai/arquitetura/fase-1-base.md](fase-1-base.md)
> **Autor:** architect (subagent)
> **Data:** 2026-07-19
> **Status:** Revisado pelo `dba` em 2026-07-19 — **aprovado com ressalvas**; correções de schema incorporadas abaixo; pronto para implementação após validação das perguntas de negócio (§19) que o DBA não fecha sozinho

---

## 1. Contexto

A Fase 1 entregou a hierarquia `Company → Branch → BranchBankAccount`, usuários multi-filial, Policies e o stub `bank_id` em contas bancárias. A **Fase 2** completa os **cadastros gerenciais** usados como classificação e referência nas solicitações (Fase 3+) e no CNAB (Fase 7):

| Capacidade | Papel |
|------------|--------|
| **CostCenter** | Classificação gerencial por **filial** (RF008; gráficos RF036) |
| **Appropriation** | Natureza/rubrica gerencial (RF009) |
| **Supplier** | Fornecedor **global** + override de `PaymentMethod` por empresa (RF010) |
| **Bank** | Cadastro manual BACEN/COMPE; fecha stub da Fase 1 (RF011 / RF005) |

**Estado real do código (2026-07-19):** Models/migrations/Resources da Fase 1 existem (`Company`, `Branch`, `BranchBankAccount`, `User`). `BranchBankAccount.bank_id` é `uuid` nullable **sem** FK; `bank()` ainda não existe no Model. `ValidCnpj` existe; **não** há `ValidCpf`, `PersonType`, `PaymentMethod`, `Bank`, morph `addresses`/`contacts`.

Painel único `admin`; cadastros gerenciais exclusivos de **Adm** (RN006.4); Operador com leitura.

---

## 2. Requisitos

### 2.1 Funcionais (Fase 2)

| RF | Descrição | Entidade/Artefato principal |
|----|-----------|------------------------------|
| RF008 | CRUD centros de custo (escopo por filial) | `CostCenter` + `CostCenterResource` |
| RF009 | CRUD apropriações (natureza/rubrica) | `Appropriation` + `AppropriationResource` |
| RF010 | Fornecedor global + override pagamento por empresa | `Supplier` + `SupplierCompanyPaymentMethod` |
| RF011 | Bancos (cadastro manual, global) | `Bank` + `BankResource` |
| *(dep.)* | Fechar stub Fase 1: FK + backfill + UI Select | Migration + Command + RelationManager |

### 2.2 Não-funcionais aplicáveis

- **RNF001:** UUID, `timestampsTz`/`softDeletesTz`, `created_by`/`updated_by`.
- **RNF003:** status/tipo como `string` + Enum (`PersonType`, `PaymentMethod`).
- **RNF006:** Policies; sem mascaramento LGPD de fornecedores (R12).
- **RNF007:** CPF/CNPJ rigoroso conforme `PersonType`.
- **RNF011:** ~70 pagamentos/dia — sem otimização prematura.
- **RNF010:** eventos de domínio — **nenhum** da Fase 2 na tabela DRF §6 → adiar (igual Fase 1).

### 2.3 API REST

> **Assunção (igual Fase 1): NÃO.** Painel Filament interno BPO; DRF não exige API mobile/terceiros na Fase 2. Sem Sanctum/Passport/Swagger. Reavaliar se surgir portal externo.

---

## 3. Decisões de Design

### 3.1 Normalização e entidades compartilhadas

**Matriz morph vs dedicado (`database.md`):**

| Candidato | Decisão Fase 2 | Justificativa |
|-----------|----------------|---------------|
| **Endereços (`addresses`)** | **Morph + `HasAddresses`** | Estrutura idêntica; auxiliar; Fase 1 adiou explicitamente para Fornecedores. Só `Supplier` usa agora; `Company`/`Branch` podem adotar depois sem nova migration. |
| **Contatos (`contacts`)** | **Morph + `HasContacts`** | Idem. |
| **Override pagamento fornecedor×empresa** | **Dedicado** `supplier_company_payment_methods` | Core de RN010.2/RN010.3; FK crítica; query por `(supplier_id, company_id)` na Fase 3; **não** JSON/coluna empacotada. |
| **CostCenter / Appropriation / Bank / Supplier** | **Dedicados** | Entidades de domínio; SoftDeletes; FKs. |
| **Notas / anexos** | **Adiar** | Anexos = Fase 3+ (RNF004). |

**Desnormalizações intencionais:**

1. **`BranchBankAccount.bank_code` / `bank_name`** — mantidos após introduzir `Bank` (comentário obrigatório na migration de sync/FK). Fonte canônica de associação: `bank_id`. Os campos textuais são **cópia operacional sincronizada** (não histórico imutável de pagamento — a conta é cadastro, não lançamento).
   - **Regra de sync (DBA):** ao setar/alterar `bank_id`, copiar `banks.code` → `bank_code` e `banks.name` → `bank_name`. Ao atualizar `code`/`name` de um `Bank`, propagar para todas as `branch_bank_accounts` **não soft-deleted** com aquele `bank_id` (via `BankService` / Observer — bulk update).
   - **Não** permitir edição manual de `bank_code`/`bank_name` na UI após Select de Bank (readonly/dehydrated).
2. **Nenhuma** outra desnormalização.

> **Nota DBA:** morph `addresses`/`contacts` + tabela dedicada de override estão corretos pela matriz `database.md`. Override **nunca** deve ser JSON nem coluna em `suppliers`.

### 3.2 Decisão 1 — Escopo de `CostCenter`: **por filial**

RF008: *“escopo por filial conforme modelagem”*.

- `cost_centers.branch_id` → `branches` (**obrigatório**).
- Unicidade de `code`: **por filial** (índice único parcial `(branch_id, code) WHERE deleted_at IS NULL`).
- Relatório RF036 (“por filial e centro de custo”) casa naturalmente.

### 3.3 Decisão 2 — Escopo de `Appropriation`: **por empresa** *(assunção)*

RF009 não define escopo. **Assunção documentada:** `company_id` obrigatório.

| Alternativa | Prós | Contras |
|-------------|------|---------|
| **Por empresa (escolhida)** | Isola plano de contas Altitude vs Glow; alinhado a “configurações por empresa” (RN003.2) | Catálogo não compartilhado entre empresas |
| Global (como `Bank`) | Um cadastro só | Mistura rubricas de clientes distintos |
| Por filial | Simétrico a CostCenter | Provável duplicação desnecessária de natureza |

> **Nota DBA (2026-07-19):** do ponto de vista de **dados**, escopo por `company_id` é **saudável** (3NF, isolamento entre clientes BPO, unique `(company_id, code)` parcial, cascade natural no teardown de Company). Global misturaria catálogos; por filial tenderia a duplicar natureza sem ganho de integridade. O DBA **aprova a assunção como default de schema**; confirmação com RJET permanece pergunta de negócio (#1) — se mudar para global/filial, exige nova migration de FK antes de ir a produção com dados.

> **Pergunta aberta #1:** confirmar com RJET se apropriação é por empresa, global ou por filial.

### 3.4 Decisão 3 — `Supplier` global + override dedicado

Interpretação de RN010.2 / decisão de negócio #3 + RN010.3:

1. **Um** cadastro de fornecedor no sistema (unique de `document` parcial) — **não** `company_id` em `suppliers`.
2. **`default_payment_method`** no fornecedor = “padrão global” (critério de aceite RF010).
3. Override = linha em `supplier_company_payment_methods` para o par `(supplier_id, company_id)`.
4. Resolução (Fase 3, antecipada como helper):  
   `override?.payment_method ?? supplier.default_payment_method`.

**Por que não JSON / pivot “burro”?** Precisa de FK, SoftDeletes, Policy/RelationManager, unique parcial e Service tipado — tabela dedicada com Model.

**Nome:** Model `SupplierCompanyPaymentMethod` · tabela `supplier_company_payment_methods`.

### 3.5 Decisão 4 — Fechar stub `Bank` (dependência Fase 1)

Estado atual (migration + Model): `bank_id` uuid nullable sem constraint; sem `bank()`.

**Plano Fase 2:**

1. Criar `banks` (+ Resource, seeder de bancos comuns opcional).
2. Migration `Schema::table('branch_bank_accounts', ...)`:
   - **`->index()` em `bank_id`** — **obrigatório** (Fase 1 deixou stub **sem** índice; Postgres não indexa FK automaticamente).
   - `$table->foreign('bank_id')->references('id')->on('banks')->nullOnDelete();`  
     (`nullOnDelete` = integridade em **forceDelete** de Bank; soft-delete de Bank continua **bloqueado** no Service se houver contas não trashed).
3. Command `banks:backfill-branch-accounts`: casa `branch_bank_accounts.bank_code` → `banks.code` (ativos, `deleted_at IS NULL`), seta `bank_id`; reporta órfãos (`bank_id` permanece null).
4. Atualizar `BankAccountsRelationManager`: Select `bank_id` (searchable) → sync `bank_code`/`bank_name`; campos code/name **readonly/dehydrated**. Coluna `bank_id` permanece **nullable** no schema (órfãos pós-backfill); UI pode exigir Select em **novos** registros.
5. Model: `bank(): BelongsTo` (considerar `->withTrashed()` só se UI precisar exibir banco soft-deleted — default sem).

### 3.6 Decisão 5 — Morph `addresses` / `contacts` agora

Criar tabelas + traits + Models. **Consumidor Fase 2:** apenas `Supplier`.  
Não obrigar endereço/contato no form (nullable / RelationManagers opcionais) — **assunção:** útil operacionalmente; RF010 não lista campos de endereço explicitamente → **Pergunta aberta #2**.

> **Nota DBA:** morph aprovado. Obrigatório: `Relation::enforceMorphMap()` (`supplier` → `Supplier::class`; reservar chaves futuras `company`/`branch` só quando adotarem). Cascade soft/force delete de morphs no soft-delete de `Supplier` (Observer/Service — morph **não** tem FK nativa). Blueprint completo em §17.

### 3.7 Decisão 6 — Enums na Fase 2

| Enum | Introduzir? | Motivo |
|------|:-----------:|--------|
| `PersonType` (Pf, Pj) | ✅ | RF010 |
| `PaymentMethod` (Boleto, Deposit) | ✅ | RF010 + antecipação RN013.1 |
| `DepositType` / `PixKeyType` | ❌ | Só formulário da solicitação (Fase 3) |
| `AddressType` / `ContactType` | ✅ (string backed ou enum simples) | Morph addresses/contacts (`main`, `billing`, …) |

### 3.8 Decisão 7 — Policies (extensão RN006.4)

Cadastros gerenciais = **Adm** escreve; **Operador** lê; **Cliente** negado.

| Ability | CostCenter | Appropriation | Supplier | Bank | SupplierCompanyPaymentMethod¹ |
|---------|:----------:|:-------------:|:--------:|:----:|:-----------------------------:|
| viewAny / view | O, A | O, A | O, A | O, A | O, A |
| create / update / delete / restore / forceDelete | A | A | A | A | A |

¹ Via Policy do Model ou herdada do `SupplierPolicy` no RelationManager.

### 3.9 Decisão 8 — Events / Jobs / API

- **Events:** nenhum (DRF §6 sem eventos de cadastro). Autoria = `HasBlameable` (já Fase 1).
- **Jobs:** nenhum.
- **API:** não (§2.3).
- **Livewire custom / File storage / Scheduling:** não.

### 3.10 Decisão 9 — Soft delete e integridade referencial

| Entidade | SoftDeletes | Regra de exclusão |
|----------|:-----------:|-------------------|
| CostCenter | ✅ | Cascade quando `Branch` soft-delete (incl. teardown `Company`) |
| Appropriation | ✅ | Cascade quando `Company` soft-delete |
| Supplier | ✅ | **Bloquear** se existir `PaymentRequest` futura (Fase 3) — na F2 só soft-delete livre; documentar hook futuro. Cascade: overrides + addresses + contacts do supplier |
| Bank | ✅ | **Bloquear** se existir qualquer `branch_bank_accounts` **não soft-deleted** com `bank_id` (`BankException`) — independente de `is_active` |
| SupplierCompanyPaymentMethod | ✅ | SoftDeletes; unique parcial; cascade ao soft-delete de `Company` ou `Supplier` |
| Address / Contact | ✅ | Morph; cascade via parent (`Supplier`); órfãos limpos no `forceDeleted` do parent |

Guards de bloqueio vivem em **Service** (nunca evento `deleting` no Model que quebre cascata de `Company`) — padrão Fase 1.

> **Nota DBA:** `cascadeOnDelete` nas FKs cobre apenas **hard/forceDelete**. Soft-delete **sempre** via Observer/Service em lote (`->delete()` na query), nunca instância-a-instância que dispare guards. Unique parciais: **nunca** `->unique()` no Blueprint — só `DB::statement` com `WHERE deleted_at IS NULL`, guard `supportsPartialIndexes()` (pgsql + sqlite) como na Fase 1.

---

## 4. Models

Todos: `final`, `declare(strict_types=1)`, `HasFactory`, `HasUuid`, `SoftDeletes`, `HasBlameable`, `casts()` método. PK UUID. `timestampsTz` / `softDeletesTz`. FK com `->index()` explícito (PostgreSQL).

### 4.1 `CostCenter`

- **Tabela:** `cost_centers`
- **Campos:**
  - `id` uuid PK
  - `branch_id` foreignUuid → `branches`, index, `cascadeOnDelete` (hard/forceDelete; soft via Observer)
  - `code` string(30) — **sem** `->unique()` na coluna
  - `name` string(150)
  - `description` string(500) nullable
  - `is_active` boolean default true, index
  - `sort_order` unsignedInteger default 0, index
  - `created_by` / `updated_by` foreignUuid nullable, index, `nullOnDelete`
  - timestampsTz + softDeletesTz
- **Índice único parcial:** `(branch_id, code) WHERE deleted_at IS NULL` (via `DB::statement` + `supportsPartialIndexes()`)
- **Casts:** `is_active => boolean`
- **Relacionamentos:** `branch(): BelongsTo` · (Fase 3) `paymentRequests(): HasMany`
- **Scopes:** `scopeActive`, `scopeForBranch(Branch|string)`
- **Traits:** `HasFactory, HasUuid, SoftDeletes, HasBlameable`

### 4.2 `Appropriation`

- **Tabela:** `appropriations`
- **Campos:**
  - `id` uuid PK
  - `company_id` foreignUuid → `companies`, index, `cascadeOnDelete` *(assunção §3.3 — aprovada pelo DBA como default)*
  - `code` string(30) — **sem** `->unique()`
  - `name` string(150)
  - `description` string(500) nullable
  - `is_active` boolean default true, index
  - `sort_order` unsignedInteger default 0, index
  - autoria + timestampsTz + softDeletesTz
- **Índice único parcial:** `(company_id, code) WHERE deleted_at IS NULL`
- **Relacionamentos:** `company(): BelongsTo`
- **Scopes:** `scopeActive`, `scopeForCompany(...)`

### 4.3 `Supplier`

- **Tabela:** `suppliers`
- **Campos:**
  - `id` uuid PK
  - `person_type` string(20) index → cast `PersonType` *(DBA: alinhado a convenção status/tipo `string(20–30)`; values `pf`/`pj`)*
  - `document` string(20) — **sem** `->unique()` → índice único parcial `WHERE deleted_at IS NULL`; **armazenar só dígitos** (sem máscara), igual padrão esperado de `ValidCpf`/`ValidCnpj`
  - `name` string(150) — nome fantasia / nome civil
  - `legal_name` string(200) nullable — razão social (Pj)
  - `email` string nullable, index
  - `phone` string(20) nullable
  - `default_payment_method` string(20) **index** → cast `PaymentMethod` *(filtro na Table Filament)*
  - `notes` text nullable
  - `is_active` boolean default true, index
  - autoria + timestampsTz + softDeletesTz
- **Validação:** `PersonType::Pf` → `ValidCpf`; `Pj` → `ValidCnpj`; inconsistência tipo×documento → erro de form + `SupplierException` no Service se bypass.
- **Relacionamentos:**
  - `companyPaymentMethods(): HasMany` → `SupplierCompanyPaymentMethod`
  - `addresses()` / `contacts()` via traits
- **Helpers:** `paymentMethodFor(Company $company): PaymentMethod`
- **Scopes:** `scopeActive`, `scopePf`, `scopePj`
- **Traits:** `HasFactory, HasUuid, SoftDeletes, HasBlameable, HasAddresses, HasContacts`
- **Cascade soft-delete (SupplierObserver ou Service):** `companyPaymentMethods`, `addresses`, `contacts` — restore/forceDelete simétricos.

### 4.4 `SupplierCompanyPaymentMethod`

- **Tabela:** `supplier_company_payment_methods`
- **Campos:**
  - `id` uuid PK
  - `supplier_id` foreignUuid → `suppliers`, index, `cascadeOnDelete`
  - `company_id` foreignUuid → `companies`, index, `cascadeOnDelete`
  - `payment_method` string(20) **index** → `PaymentMethod`
  - autoria + timestampsTz + softDeletesTz
- **Índice único parcial:** `(supplier_id, company_id) WHERE deleted_at IS NULL`
- **Relacionamentos:** `supplier()`, `company()`
- **Não** é pivot Eloquent simples — Model dedicado (tem SoftDeletes + autoria). SoftDeletes justificado: não é pivot `sync()`/`detach()`; é preferência de negócio com auditoria.

### 4.5 `Bank`

- **Tabela:** `banks`
- **Campos:**
  - `id` uuid PK
  - `code` string(3) — COMPE/BACEN com zero à esquerda (ex.: `001`, `341`) — **sem** `->unique()` → unique parcial
  - `name` string(150)
  - `ispb` string(8) nullable — **aprovado DBA** (útil CNAB/SPI futuro); sem unique nesta fase (ISPBs raramente duplicados, mas volume baixo; unique parcial opcional se surgir requisito)
  - `is_active` boolean default true, index
  - autoria + timestampsTz + softDeletesTz
- **Relacionamentos:** `branchBankAccounts(): HasMany`
- **Scopes:** `scopeActive`
- **RN011:** cadastro manual; sem API/cron BACEN.
- **Sync:** ao alterar `code`/`name`, atualizar snapshots nas contas vinculadas não trashed (§3.1).

### 4.6 `Address` / `Contact` (morph)

Conforme `database.md` §3 + blueprint §17 (uuidMorphs, type string(20), campos BR, `is_default`, SoftDeletes, timestampsTz, índice composto type).  
Traits `HasAddresses` / `HasContacts` em `app/Models/Concerns/`.  
`enforceMorphMap`: no mínimo `'supplier' => Supplier::class`.

### 4.7 Alterações em Models Fase 1

**`BranchBankAccount`:**
- Adicionar `bank(): BelongsTo`
- Manter `bank_code` / `bank_name` (sync §3.1)
- Observer/Service: ao setar `bank_id`, copiar `code`/`name` do `Bank`

**`Branch`:**
- `costCenters(): HasMany`

**`Company`:**
- `appropriations(): HasMany`
- `supplierPaymentMethods(): HasMany` → overrides

**`CompanyObserver` (estender cascade Fase 1):**  
Ordem no soft-delete de Company (**bulk via query**, nunca Model events por linha):
1. Soft-delete `supplier_company_payment_methods` da company  
2. Soft-delete `appropriations` da company  
3. Por cada branch (get): soft-delete `costCenters`, depois `bankAccounts`  
4. Soft-delete `branches`  

Restore simétrico com threshold capturado em `restoring` (padrão Fase 1 já implementado — estender para appropriations, overrides, costCenters).

`forceDeleted`: forceDelete com `withTrashed()` na mesma ordem (overrides → appropriations → costCenters + bankAccounts por branch → branches).

**`BranchService::delete()`:** após guard de bank accounts, soft-delete `costCenters` em lote (ou bloquear — default cascade §19 #6).

---

## 5. Relacionamentos

```
Company ──hasMany──────────> Appropriation
Company ──hasMany──────────> SupplierCompanyPaymentMethod
Branch  ──hasMany──────────> CostCenter
CostCenter ──belongsTo─────> Branch
Appropriation ──belongsTo──> Company

Supplier ──hasMany─────────> SupplierCompanyPaymentMethod
SupplierCompanyPaymentMethod ──belongsTo──> Supplier
SupplierCompanyPaymentMethod ──belongsTo──> Company
Supplier ──morphMany───────> Address (HasAddresses)
Supplier ──morphMany───────> Contact (HasContacts)

Bank ──hasMany─────────────> BranchBankAccount
BranchBankAccount ──belongsTo──> Bank   (FK ativa na Fase 2)

[todos domínio] ──created_by/updated_by──> User
```

---

## 6. Enums

### 6.1 `PersonType`

| Case | value | Label | Color | Icon |
|------|-------|-------|-------|------|
| `Pf` | `pf` | Pessoa física | `gray` | `heroicon-o-user` |
| `Pj` | `pj` | Pessoa jurídica | `info` | `heroicon-o-building-office` |

### 6.2 `PaymentMethod`

| Case | value | Label | Color | Icon |
|------|-------|-------|-------|------|
| `Boleto` | `boleto` | Boleto | `warning` | `heroicon-o-document-text` |
| `Deposit` | `deposit` | Depósito | `success` | `heroicon-o-banknotes` |

### 6.3 Tipos morph (recomendado)

- `AddressType`: `Main`, `Billing`, `Shipping` (values `main`, `billing`, `shipping`)
- `ContactType`: `Main`, `Billing`, `Technical` (`main`, `billing`, `technical`)

Traduções: `lang/pt_BR/enums.php` + `en` (`person_type.*`, `payment_method.*`, …).  
Interfaces: `HasLabel`, `HasColor`, `HasIcon` (`enums.md`).

---

## 7. Exceções de Domínio

Base já prevista na Fase 1: `BusinessException`.

- **`SupplierException`:**
  - `documentInconsistentWithPersonType()`
  - `duplicateDocument()` (se Service detectar além do unique)
- **`BankException`:**
  - `cannotDeleteWithAccounts()` — soft-delete bloqueado com contas ativas
  - `codeAlreadyExists()` (opcional)
- **`CostCenterException` / `AppropriationException`:**
  - `duplicateCodeInScope()` (opcional; unique parcial + validação form bastam na maioria dos casos)

Uso Filament: `try/catch` + `Notification::danger(getUserMessage())`.

**Rules:** `ValidCpf` (nova, espelhando `ValidCnpj`); reutilizar `ValidCnpj`.

---

## 8. Camadas de Aplicação

### 8.1 Services (flat)

| Service | Responsabilidade |
|---------|------------------|
| `SupplierService` | create/update com validação PersonType×documento; sync overrides; `paymentMethodFor` |
| `BankService` | create/update; guard `cannotDeleteWithAccounts`; usado pelo backfill |
| `BranchBankAccountService` *(ou estender Observer)* | ao salvar com `bank_id`, sync `bank_code`/`bank_name` |
| CostCenter / Appropriation | CRUD Filament puro na F2; Service só se surgirem regras |

### 8.2 DTOs

Opcional: `SupplierData` se Service for chamado fora do Filament. Não obrigatório.

### 8.3 Actions / Jobs

- **Filament Action:** opcional `ResolveSupplierPaymentMethodAction` (mais útil na Fase 3).
- **Console Command:** `BanksBackfillBranchAccountsCommand` (`banks:backfill-branch-accounts`) — síncrono; volume baixo.
- **Jobs:** nenhum.

### 8.4 Observers

- Estender `CompanyObserver` (cascade §4.7).
- `Branch`: ao soft-delete via query da Company, cost centers já incluídos no observer da Company; exclusão direta de Branch (Service) deve soft-deletar `costCenters` **ou** bloquear se houver — **recomendação:** cascade soft-delete de `costCenters` no `BranchService::delete()` **depois** do guard de bank accounts (contas ainda bloqueiam; centros de custo acompanham a filial).
- `Bank` / `Supplier`: sem cascade destrutivo via Model events que conflitem com Company.

---

## 9. Performance

- **Índices:** todas as FKs com `->index()` explícito (Postgres); `is_active`; `person_type`; `default_payment_method`; `payment_method` (override); unique parciais para códigos/documentos; **`branch_bank_accounts.bank_id` index obrigatório** na migration de FK.
- **Eager load:** Tables com `with(['branch.company'])` em CostCenter; `with('company')` em Appropriation; Supplier com `withCount('companyPaymentMethods')`; Bank com `withCount('branchBankAccounts')`.
- **Cache:** não necessário (RNF011).
- **preventLazyLoading** já previsto na Fase 1.
- **Morph:** `uuidMorphs()` (já cria índice type+id); índice extra `[*able_type, *able_id, type]` conforme `database.md`.

---

## 10. Soft Deletes & Data Lifecycle

- SoftDeletes em todas as entidades §4.
- Unique parciais PostgreSQL via `DB::statement` + guard `supportsPartialIndexes()` (pgsql + sqlite nos testes) — padrão Fase 1 / `soft-deletes.md` §10. Colunas **sem** `->unique()` no Blueprint.
- Cascade Company estendido (§4.7); Supplier cascade morph + overrides; BranchService cascade cost centers.
- Pruning: não (cadastros de retenção longa).
- Filament: `--soft-deletes`, `TrashedFilter`, Restore/ForceDelete, `getRecordRouteBindingEloquentQuery()`.

---

## 11. Filament Resources (Blueprint — OBRIGATÓRIO)

**Localização:** `App\Filament\Resources\{Models}\` (igual Fase 1; `PROJECT.md`).

```bash
php artisan make:filament-resource {Model} --generate --soft-deletes --view --panel=admin --no-interaction
```

Navigation group: `__('navigation.groups.registrations')` (“Cadastros”).  
Sort sugerido: Companies 1, Branches 2, **CostCenters 3, Appropriations 4, Suppliers 5, Banks 6**.

---

### Resource: `CostCenterResource`

```
Command: php artisan make:filament-resource CostCenter --generate --soft-deletes --view --panel=admin --no-interaction
Location: App\Filament\Resources\CostCenters\CostCenterResource
Structure (pasta PLURAL: CostCenters/):
  - CostCenterResource.php (final, LIMPO)
  - Schemas/CostCenterForm.php
  - Schemas/CostCenterInfolist.php — SEMPRE
  - Tables/CostCentersTable.php
  - Pages/ (Create, Edit, List, View)
SoftDeletes: getRecordRouteBindingEloquentQuery()
Icon: Heroicon::OutlinedSquare3Stack3d
Navigation:
  Group: __('navigation.groups.registrations')
  Sort: 3
Policy: CostCenterPolicy (viewAny/view: O+A; mutate: A)
Form:
  Section cost_center_info:
    Field branch_id    → Select | relationship('branch','name'), searchable, preload, required
                         | getOptionLabelFromRecordUsing: "{$record->company->name} / {$record->name}"
    Field code         → TextInput | required, maxLength(30), unique(ignoreRecord, scope branch_id + whereNull deleted_at)
    Field name         → TextInput | required, maxLength(150)
    Field description  → Textarea | maxLength(500)
    Field sort_order   → TextInput->numeric() | default(0)
    Field is_active    → Toggle | default(true)
Infolist:
  branch (company.name + name), code, name, description, sort_order, is_active(IconEntry), audit
Table:
  Column branch.company.name → TextColumn | sortable, searchable, toggleable
  Column branch.name → TextColumn | searchable, sortable
  Column code / name → TextColumn | searchable, sortable
  Column is_active → IconColumn->boolean()
  Filter branch → SelectFilter relationship
  Filter company → SelectFilter (via whereHas branch.company) searchable
  Filter is_active → TernaryFilter
  Filter TrashedFilter
  modifyQueryUsing: ->with(['branch.company'])
RecordActions: [ActionGroup → View, Edit, Delete]
ToolbarActions: [BulkActionGroup → DeleteBulk, RestoreBulk, ForceDeleteBulk]
```

---

### Resource: `AppropriationResource`

```
Command: php artisan make:filament-resource Appropriation --generate --soft-deletes --view --panel=admin --no-interaction
Location: App\Filament\Resources\Appropriations\AppropriationResource
Structure: Appropriations/ (Form, Infolist, AppropriationsTable, Pages)
Icon: Heroicon::OutlinedTag
Navigation: Group registrations, Sort: 4
Policy: AppropriationPolicy (O+A view; A mutate)
Form:
  company_id → Select relationship company, required
  code → TextInput required, unique parcial por company
  name → TextInput required
  description → Textarea nullable
  sort_order → numeric default 0
  is_active → Toggle
Infolist / Table: espelhar CostCenter com company em vez de branch
modifyQueryUsing: ->with('company')
```

---

### Resource: `SupplierResource`

```
Command: php artisan make:filament-resource Supplier --generate --soft-deletes --view --panel=admin --no-interaction
Location: App\Filament\Resources\Suppliers\SupplierResource
Structure:
  - SupplierResource.php
  - Schemas/SupplierForm.php, SupplierInfolist.php
  - Tables/SuppliersTable.php
  - Pages/ …
  - RelationManagers/
      CompanyPaymentMethodsRelationManager.php
      AddressesRelationManager.php   (opcional se Repeater no form for preferido)
      ContactsRelationManager.php
Icon: Heroicon::OutlinedTruck
Navigation: Group registrations, Sort: 5
Policy: SupplierPolicy
Form:
  Section identity:
    person_type → Select options(PersonType::class) | required, live, native(false)
    document → TextInput | required, rule(ValidCpf|ValidCnpj conforme person_type), unique parcial
    name → TextInput required maxLength(150)
    legal_name → TextInput | visible(person_type=Pj), maxLength(200)
  Section contact_quick:  # atalhos; detalhe morph via RM
    email → TextInput->email() nullable
    phone → TextInput nullable
  Section payment:
    default_payment_method → Select options(PaymentMethod::class) | required
    notes → Textarea nullable
  Section flags:
    is_active → Toggle default true
Infolist: person_type(badge), document, name, legal_name, default_payment_method(badge),
          email, phone, is_active, overrides RepeatableEntry, addresses/contacts summaries
Table:
  name, document, person_type(badge), default_payment_method(badge),
  company_payment_methods_count, is_active
  Filters: person_type, payment_method, is_active, TrashedFilter
  modifyQueryUsing: ->withCount('companyPaymentMethods')
RelationManagers:
  - CompanyPaymentMethodsRelationManager
      Fields: company_id (Select), payment_method (Select PaymentMethod)
      unique (supplier, company)
  - AddressesRelationManager / ContactsRelationManager (morph)
RecordActions: [View, Edit, Delete]
ToolbarActions: [BulkActionGroup → Delete/Restore/ForceDelete]
```

---

### Resource: `BankResource`

```
Command: php artisan make:filament-resource Bank --generate --soft-deletes --view --panel=admin --no-interaction
Location: App\Filament\Resources\Banks\BankResource
Icon: Heroicon::OutlinedBuildingLibrary
Navigation: Group registrations, Sort: 6
Policy: BankPolicy + BankService guard on delete
Form:
  code → TextInput required maxLength(3) | unique parcial | hint COMPE
  name → TextInput required
  ispb → TextInput maxLength(8) nullable  # DBA: aprovado
  is_active → Toggle
Table: code, name, branch_bank_accounts_count, is_active
RecordActions: [View, Edit, Delete(guarded)]
```

---

### Atualização: `BankAccountsRelationManager` (Fase 1)

```
Field bank_id → Select | relationship('bank','name'), searchable, preload, required (ou nullable na transição)
  afterStateUpdated: preenche bank_code + bank_name a partir do Bank
Fields bank_code / bank_name → TextInput disabled/dehydrated(true) OU hidden (ainda gravados)
Restante inalterado (agency, account_*, account_type, holder_name, is_default, is_active)
```

> `BranchBankAccount` continua **sem** Resource standalone.

**Widgets / Livewire custom:** nenhum.

---

## 12. Fluxos

### Fluxo principal — Cadastros gerenciais

1. Adm autentica no painel `admin`.
2. Adm cadastra **Banks** (RF011); opcionalmente roda `banks:backfill-branch-accounts`.
3. Adm cadastra **CostCenters** por filial (RF008) e **Appropriations** por empresa (RF009).
4. Adm cadastra **Supplier** (PersonType + documento + `default_payment_method`) (RF010).
5. Adm, no RelationManager do fornecedor, cria overrides `company × payment_method` (ex.: Altitude=Boleto, Glow=Deposit).
6. Operador **consulta** (somente leitura) todos os cadastros.
7. (Fase 3) Solicitação chama `supplier->paymentMethodFor($branch->company)`.

### Fluxos alternativos

- Documento inválido / inconsistente com PersonType → validação inline.
- Soft-delete Bank com contas não trashed → `BankException::cannotDeleteWithAccounts()`.
- Soft-delete Company → cascade overrides, appropriations, cost centers (via branches), bank accounts, branches.
- Soft-delete Supplier → cascade overrides + addresses + contacts.
- Backfill: contas sem `banks.code` correspondente → log/report; `bank_id` permanece null até cadastro manual do banco.
- Cliente acessa URL de Resource → Policy 403.

---

## 13. Integrações

- **Nenhuma API externa** (RN011.1 — sem BACEN/cron).
- Seeder opcional com bancos BR comuns (001, 033, 104, 237, 341, …) para DX — **não** substitui cadastro manual Adm.

---

## 14. Segurança

- Policies §3.8; RN006.4.
- Sem mascaramento LGPD de fornecedores (RNF006 / R12); acesso restrito a O/A.
- Blameable em todas as entidades.
- Sem API pública na F2.

---

## 15. Factories & Seeders

**Factories** (locale `pt_BR`, `fake()`, states):

- `CostCenterFactory` — `branch_id`, `code`, `name`; states `inactive()`, `forBranch(Branch)`
- `AppropriationFactory` — `company_id`, `code`, `name`
- `SupplierFactory` — states `pf()`, `pj()`, `inactive()`, `withPaymentOverride(Company, PaymentMethod)`
- `SupplierCompanyPaymentMethodFactory`
- `BankFactory` — `code` (3 dígitos), `name`; state `inactive()`
- `AddressFactory` / `ContactFactory` (morph)

**Seeders (idempotentes):**

- `BankSeeder` — `firstOrCreate` por `code` (Itaú 341, BB 001, Bradesco 237, Caixa 104, Santander 033, …)
- `CostCenterSeeder` / `AppropriationSeeder` — poucos registros por Altitude/Glow e filiais
- `SupplierSeeder` — 1–2 fornecedores + override distinto Altitude vs Glow
- Orquestrar em `DatabaseSeeder` **após** Company/Branch seeders da Fase 1; rodar backfill após BankSeeder

---

## 16. i18n

Arquivos: `cost_centers.php`, `appropriations.php`, `suppliers.php`, `banks.php`, `addresses.php`, `contacts.php`, `supplier_company_payment_methods.php`; enums; `errors.php` (novas chaves); navigation já tem `registrations`.

---

## 17. Schema consolidado (blueprint para DBA — **versão aprovada**)

> Unique parciais: envolver cada `DB::statement` em `if ($this->supportsPartialIndexes())` (pgsql + sqlite), com `DROP INDEX IF EXISTS` no `down()` — igual Fase 1.

```php
// cost_centers
Schema::create('cost_centers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('branch_id')->index()->constrained('branches')->cascadeOnDelete();
    $table->string('code', 30); // NÃO ->unique()
    $table->string('name', 150);
    $table->string('description', 500)->nullable();
    $table->unsignedInteger('sort_order')->default(0)->index();
    $table->boolean('is_active')->default(true)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});
DB::statement('CREATE UNIQUE INDEX cost_centers_branch_code_unique ON cost_centers (branch_id, code) WHERE deleted_at IS NULL');

// appropriations
Schema::create('appropriations', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('company_id')->index()->constrained('companies')->cascadeOnDelete();
    $table->string('code', 30); // NÃO ->unique()
    $table->string('name', 150);
    $table->string('description', 500)->nullable();
    $table->unsignedInteger('sort_order')->default(0)->index();
    $table->boolean('is_active')->default(true)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});
DB::statement('CREATE UNIQUE INDEX appropriations_company_code_unique ON appropriations (company_id, code) WHERE deleted_at IS NULL');

// banks
Schema::create('banks', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('code', 3); // COMPE; NÃO ->unique()
    $table->string('name', 150);
    $table->string('ispb', 8)->nullable(); // DBA: aprovado nullable
    $table->boolean('is_active')->default(true)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});
DB::statement('CREATE UNIQUE INDEX banks_code_unique ON banks (code) WHERE deleted_at IS NULL');

// suppliers
Schema::create('suppliers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('person_type', 20)->index(); // cast PersonType
    $table->string('document', 20); // dígitos only; NÃO ->unique()
    $table->string('name', 150);
    $table->string('legal_name', 200)->nullable();
    $table->string('email')->nullable()->index();
    $table->string('phone', 20)->nullable();
    $table->string('default_payment_method', 20)->index(); // cast PaymentMethod
    $table->text('notes')->nullable();
    $table->boolean('is_active')->default(true)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});
DB::statement('CREATE UNIQUE INDEX suppliers_document_unique ON suppliers (document) WHERE deleted_at IS NULL');

// supplier_company_payment_methods
Schema::create('supplier_company_payment_methods', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('supplier_id')->index()->constrained('suppliers')->cascadeOnDelete();
    $table->foreignUuid('company_id')->index()->constrained('companies')->cascadeOnDelete();
    $table->string('payment_method', 20)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});
DB::statement(<<<'SQL'
    CREATE UNIQUE INDEX supplier_company_payment_methods_pair_unique
    ON supplier_company_payment_methods (supplier_id, company_id) WHERE deleted_at IS NULL
SQL);

// addresses (morph) — database.md §3
Schema::create('addresses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuidMorphs('addressable');
    $table->string('type', 20)->default('main'); // cast AddressType
    $table->string('street');
    $table->string('number', 20)->nullable();
    $table->string('complement', 100)->nullable();
    $table->string('neighborhood');
    $table->string('city');
    $table->string('state', 2);
    $table->string('zip_code', 10);
    $table->string('country', 2)->default('BR');
    $table->boolean('is_default')->default(false)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
    $table->index(['addressable_type', 'addressable_id', 'type']);
});

// contacts (morph) — database.md §3
Schema::create('contacts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuidMorphs('contactable');
    $table->string('type', 20)->default('main'); // cast ContactType
    $table->string('name');
    $table->string('email')->nullable()->index();
    $table->string('phone', 20)->nullable();
    $table->string('mobile', 20)->nullable();
    $table->string('position', 100)->nullable();
    $table->string('department', 100)->nullable();
    $table->boolean('is_default')->default(false)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
    $table->index(['contactable_type', 'contactable_id', 'type']);
});

// follow-up: fechar stub Fase 1
Schema::table('branch_bank_accounts', function (Blueprint $table) {
    $table->index('bank_id'); // ausente na Fase 1 — obrigatório
    $table->foreign('bank_id')->references('id')->on('banks')->nullOnDelete();
});
```

### Ordem de migrations sugerida

1. `create_banks_table`
2. `create_addresses_table` + `create_contacts_table` (independentes; podem ser 2 arquivos)
3. `create_cost_centers_table` (depends `branches`)
4. `create_appropriations_table` (depends `companies`)
5. `create_suppliers_table`
6. `create_supplier_company_payment_methods_table`
7. `add_bank_foreign_key_to_branch_bank_accounts_table` (**index + FK** — depende de `banks`)
8. (ops) `BankSeeder` → `banks:backfill-branch-accounts`

> `down()` da migration #7: dropar FK, dropar índice `bank_id` (se nomeado), nesta ordem.

---

## 18. Assunções documentadas

| # | Assunção |
|---|----------|
| A1 | Sem API REST na Fase 2 |
| A2 | Sem domain events / jobs na Fase 2 |
| A3 | `Appropriation` escopada por **Company** — **aprovada pelo DBA como default de schema** (confirmação RJET ainda aberta) |
| A4 | `Supplier` é catálogo **global** (sem `company_id`); override só de `PaymentMethod` |
| A5 | Morph addresses/contacts criados; uso imediato só em Supplier; campos não obrigatórios no create |
| A6 | `bank_code`/`bank_name` permanecem **sincronizados** a partir de `Bank` (cópia operacional, não snapshot histórico de pagamento) |
| A7 | `DepositType` / `PixKeyType` fora da F2 |
| A8 | Operador: somente leitura dos cadastros gerenciais |
| A9 | `banks.ispb` nullable incluso; unique parcial de ISPB adiado |
| A10 | Unique parciais com guard `supportsPartialIndexes()` (pgsql + sqlite) |

---

## 19. Perguntas abertas

| # | Pergunta | Impacto | Default se não houver resposta |
|---|----------|---------|--------------------------------|
| 1 | Appropriation: por empresa, global ou por filial? | Schema + Resource | **Por empresa** (A3) — DBA aprova default |
| 2 | Endereço/contato de Supplier são obrigatórios na F2? | Form/RM | **Opcionais**, morph criado |
| 3 | Incluir `banks.ispb`? | Coluna | **Sim, nullable** — **fechado pelo DBA** |
| 4 | `default_payment_method` obrigatório no Supplier? | Validação | **Sim** (necessário ao fallback RF010) |
| 5 | Exclusão de Supplier na F2: livre ou já bloquear “em uso”? | Service | **Livre na F2**; guard na F3 com PaymentRequest |
| 6 | CostCenter: exclusão de Branch com centros — cascade ou bloquear? | BranchService | **Cascade** soft-delete dos cost centers |

---

## 20. Revisão DBA

> **Revisor:** dba (subagent) · **Data:** 2026-07-19 · **Driver:** PostgreSQL  
> **Escopo:** schema proposto Fase 2 (`cost_centers`, `appropriations`, `banks`, `suppliers`, `supplier_company_payment_methods`, morph `addresses`/`contacts`, FK `branch_bank_accounts.bank_id`, cascade Company/Branch, unique parciais). Alinhado a Fase 1 implementada e a `database.md` / `enums.md` / `soft-deletes.md` / `performance.md`.  
> MCP Boost `database-schema` indisponível neste ambiente — inspeção via migrations/Models reais.

### Veredito

**Aprovado com ressalvas.** A modelagem está em 3NF, morph vs dedicado está correto, enums como `string` + cast PHP, SoftDeletes + unique parciais no padrão Fase 1, e o fechamento do stub `bank_id` é coerente. Nenhum achado exige rejeição ou redesenho de entidades. Correções abaixo já incorporadas em §3–§4 e §17.

### Achados (Crítico / Importante / Sugestão)

| # | Nível | Achado | Impacto | Correção aplicada |
|---|-------|--------|---------|-------------------|
| 1 | **Crítico** | `branch_bank_accounts.bank_id` na Fase 1 é uuid nullable **sem índice**. Doc F2 dizia “se ausente” de forma branda. | Joins/backfill/guards por `bank_id` sem índice; viola padrão Postgres da Fase 1. | Migration de FK **obrigatoriamente** cria `->index('bank_id')` antes/junto da FK (§3.5, §17). |
| 2 | **Crítico** | Cascade soft-delete de Company na F2 listava filhos novos, mas `forceDeleted` / morphs de Supplier / guard de Bank (“ativos”) estavam ambíguos; risco de órfãos morph e de `CompanyObserver` incompleto. | Dados órfãos em `addresses`/`contacts`; forceDelete inconsistente; guard de Bank frágil se só olhar `is_active`. | §3.10/§4.7: ordem completa soft+force; cascade Supplier→overrides+morphs; Bank bloqueia se houver qualquer conta **não trashed**. |
| 3 | **Crítico** | Unique parciais sem explicitar guard `supportsPartialIndexes()` + proibição de `->unique()` no Blueprint (testes SQLite). | Migrations quebram em SQLite ou criam unique full-table incompatível com SoftDeletes. | §10/§17: padrão Fase 1 obrigatório. |
| 4 | Importante | Snapshot `bank_code`/`bank_name` sem política de sync ao renomear Bank → risco de inconsistência com `bank_id`. | CNAB/UI mostram nome desatualizado. | §3.1: sync na atribuição **e** propagação bulk ao atualizar Bank. |
| 5 | Importante | `person_type` como `string(10)` abaixo da convenção `string(20–30)`; `default_payment_method` / override `payment_method` sem índice apesar de filtros Filament. | Inconsistência de convenção; filtros sem índice (baixo volume, mas alinhamento). | `person_type` → `string(20)`; índices em ambos os payment method fields. |
| 6 | Importante | Blueprint morph só “conforme database.md” — implementer poderia omitir blameable, índice type ou SoftDeletes. | Schema incompleto / divergente. | Blueprint completo `addresses`/`contacts` em §17 + blameable. |
| 7 | Importante | Escopo Appropriation por empresa era só assunção de negócio. | Mudança pós-dados exige migration dolorosa. | DBA valida saúde de dados do default; pergunta #1 permanece aberta para RJET. |
| 8 | Sugestão | `ispb` nullable sem unique. | Nenhum agora. | Mantido nullable; unique parcial adiado (A9). Pergunta #3 fechada pelo DBA. |
| 9 | Sugestão | `document` sem norma de armazenamento (máscara vs dígitos) conflita com unique parcial. | Duplicatas lógicas (`12.345…` vs `12345…`). | Persistência **somente dígitos** (§4.3). |
| 10 | Sugestão | Índice composto extra `(branch_id, is_active)` / `(company_id, is_active)` desnecessário no volume RNF011. | Custo de escrita sem ganho. | **Não** criar — FK + unique parcial + `is_active` bastam. |

### Checklist DBA (resumo)

| Área | Resultado |
|------|-----------|
| Normalização 3NF | ✅ (desnormalização bank snapshot documentada + sync) |
| Morph vs dedicado | ✅ addresses/contacts morph; override dedicado |
| Campos (`is_`, `sort_order`, `_at`, `_by`, decimal N/A) | ✅ |
| Enums (`string`, sem `$table->enum()`) | ✅ |
| UUID + timestampsTz + softDeletesTz | ✅ |
| Unique parciais + SoftDeletes | ✅ (com guard) |
| FK index explícito (Postgres) | ✅ (incl. `bank_id`) |
| Cascade soft vs `cascadeOnDelete` | ✅ documentado |
| Conflitos Fase 1 | ✅ stub fechado sem quebrar colunas existentes |
| Appropriation `company_id` | ✅ saudável como default |

### Decisões DBA confirmadas (2026-07-19)

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | `banks.ispb`? | **Sim, nullable** — sem unique nesta fase |
| 2 | Snapshot bank_* vs sync contínuo? | **Sync contínuo** (cadastro operacional) |
| 3 | Appropriation por empresa (dados)? | **Default aprovado**; RJET ainda confirma (#1) |
| 4 | Índices compostos extras is_active+FK? | **Não** nesta fase |

---

## 21. Próximos passos / Handoff

1. ~~**`dba`** — revisar schema (§17)~~ ✅ **Concluído em 2026-07-19** — ver [Revisão DBA](#20-revisão-dba).
2. Validar perguntas §19 **ainda abertas** com stakeholders RJET (#1, #2, #4, #5, #6 — #3 fechada).
3. **`/blueprint`** (opcional) — detalhar Forms Filament.
4. **`implementer` / `/feature`** — ordem sugerida:
   1. Enums `PersonType`, `PaymentMethod` (+ Address/Contact types) + i18n  
   2. `ValidCpf`  
   3. Migrations na ordem §17 (banks → morph → cost_centers → appropriations → suppliers → overrides → FK+index `bank_id`)  
   4. Models + traits HasAddresses/HasContacts + `enforceMorphMap`  
   5. Estender `CompanyObserver` (+ Supplier cascade morph/overrides)  
   6. Exceptions + Services (`SupplierService`, `BankService` com sync snapshot)  
   7. Command backfill  
   8. Policies  
   9. Factories/Seeders  
   10. Filament Resources + atualizar `BankAccountsRelationManager`  
   11. Pint + testes  
5. **`tester`** — Pest: unique parciais; PersonType×documento (dígitos); override resolution; Bank delete guard (qualquer conta não trashed); cascade Company (appropriations + cost centers + overrides); cascade Supplier morph; sync bank_code/name; backfill; Policies 403 Cliente; Livewire Resources.
6. **`reviewer`** — validar correções DBA aplicadas na implementação.

> Checklists: `.ai/checklists.md` · Filament: `.ai/skills/filament/SKILL.md` · Soft deletes / database / enums / factories-seeders.
