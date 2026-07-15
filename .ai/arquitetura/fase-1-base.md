# Arquitetura: Fase 1 — Base do Módulo Financeiro RJET

> **Escopo:** RF001–RF007 (autenticação, perfis, usuários multi-filial, empresas, filiais, contas bancárias, policies e visibilidade).
> **Fora de escopo:** Fases 2–8, exceto pontos de extensibilidade explicitamente antecipados (FK stub de `Bank`).
> **Stack:** Laravel 12 · Filament 5 · PHP 8.4 · Pest 4 · Livewire 4 · PostgreSQL · Redis (cache/filas).
> **Fonte:** [.ai/requisitos/drf-financeiro-v1.md](../requisitos/drf-financeiro-v1.md)
> **Autor:** architect (subagent)
> **Data:** 2026-07-13
> **Status:** Revisado pelo `dba` em 2026-07-13 — **aprovado com ressalvas**; decisões DBA confirmadas (Form+Service; `timestampsTz`); pronto para implementação

---

## 1. Contexto

A RJET presta **BPO de pagamentos**. A Fase 1 estabelece o alicerce do domínio sobre o qual todas as fases posteriores (cadastros gerenciais, solicitações, workflow, CNAB, relatórios) serão construídas. Ela precisa entregar:

- **Hierarquia de domínio** `Company → Branch → BranchBankAccount` (RNF001).
- **Modelo de usuário customizado** com perfil (`UserRole`), permissão de aprovador (`can_approve`) e vínculo **N:N com filiais** (RF002, RF007).
- **Autorização por perfil e visibilidade por filial** via Policies (RF006).
- Painel Filament interno (`admin`) como única interface de acesso na Fase 1.

O estado atual é praticamente greenfield: existe apenas o painel `admin` (`AdminPanelProvider`) e o `User` padrão do Laravel (PK `bigint`, sem perfil/filiais/UUID/SoftDeletes). **Não há Enums, Policies, Resources, traits de UUID nem migrations de domínio.** Isso favorece decisões limpas, sem migração de dados legada.

---

## 2. Requisitos

### 2.1 Funcionais (Fase 1)

| RF | Descrição | Entidade/Artefato principal |
|----|-----------|------------------------------|
| RF001 | Autenticação/sessão padrão Filament; 2FA não obrigatório | `User` + `canAccessPanel()` |
| RF002 | CRUD de usuários com perfil, `can_approve`, N:N filiais | `UserResource`, pivot `branch_user` |
| RF003 | CRUD de empresas/clientes | `Company` + `CompanyResource` |
| RF004 | CRUD de filiais (CNPJ, razão social, SoftDeletes) | `Branch` + `BranchResource` |
| RF005 | CRUD de contas bancárias da filial (Bank em Fase 2) | `BranchBankAccount` (RelationManager) |
| RF006 | Autorização por perfil + visibilidade + aprovador | Policies + `scopeVisibleTo` |
| RF007 | Modelo `User` customizado (multi-filial, role, auditoria) | `User` (migrado para UUID) |

### 2.2 Não-funcionais aplicáveis

- **RNF001:** PK UUID, timestamps, SoftDeletes, `created_by`/`updated_by` em toda entidade de domínio.
- **RNF003:** status/tipo como `string` + Enum PHP (aqui: `UserRole`).
- **RNF006:** controle de acesso por Policies e escopo por perfil/filial.
- **RNF011:** volume ~70 pagamentos/dia — sem otimização prematura.

---

## 3. Decisões de Design

### 3.1 Normalização e entidades compartilhadas

**Aplicação da matriz morph vs dedicado (`database.md`):**

| Candidato | Decisão Fase 1 | Justificativa |
|-----------|----------------|---------------|
| **Endereços (`addresses`)** | **Adiar** (não criar) | Nenhum RF da Fase 1 referencia campos de endereço em `Company`/`Branch`. Criar tabela morph agora seria especulativo (YAGNI). O padrão morph + trait `HasAddresses` está pronto para adoção na **Fase 2** (Fornecedores naturalmente demandam endereço). |
| **Contatos (`contacts`)** | **Adiar** | Idem. `Branch` já carrega `legal_name`; contatos entram com Fornecedor/Empresa quando houver requisito. |
| **Contas bancárias da filial** | **Dedicado** (`branch_bank_accounts`) | É **core do domínio** (integridade crítica, alto acoplamento com CNAB/baixa nas fases 7). FK constraint obrigatória para `branch`. Estrutura não é compartilhada com outra entidade. Nunca candidata a morph. |
| **Vínculo usuário↔filial** | **Pivot dedicado** (`branch_user`) | N:N clássico com atributo extra (`is_default`). |

**Desnormalizações intencionais:** nenhuma na Fase 1. `BranchBankAccount.bank_code`/`bank_name` (ver 3.5) **não** é desnormalização — é a fonte primária do dado enquanto `banks` não existe.

### 3.2 Decisão 1 — PK do `User`: **migrar para UUID**

**Recomendação: migrar `User` para UUID.** Justificativa:

- RNF001 exige UUID como PK para entidades de domínio; `User` é core (é referenciado por `created_by`/`updated_by` em **todas** as tabelas).
- `database.md` padroniza autoria como `foreignUuid('created_by')->constrained('users')`. Manter `users` em `bigint` **quebraria** esse padrão em todo o schema (incompatibilidade de tipo na FK).
- O projeto é greenfield (sem dados). O custo de migração é **zero** — basta reescrever a migration `create_users_table`.

**Ajustes na migration base (0001_01_01_000000):**
- `users.id` → `$table->uuid('id')->primary()`.
- `sessions.user_id` → `$table->foreignUuid('user_id')->nullable()->index()` (hoje é `foreignId` bigint, sem constraint — apenas indexado; trocar o tipo para `uuid` evita mismatch). **Sem** `constrained()` — mantém o padrão atual (tabela efêmera, `soft-deletes.md` §2).
- `password_reset_tokens` permanece (chaveado por `email`).
- `cache`/`jobs` não são afetados.

> **Nota DBA:** self-referencing FK (`created_by`/`updated_by` → `users.id`) dentro do **mesmo** `Schema::create('users', ...)` funciona normalmente em PostgreSQL — a constraint é resolvida contra a definição completa da tabela ao final do DDL, sem problema de "ordem de criação". Não é necessário um `Schema::table()` separado após o `Schema::create()`.

**Geração de UUID:** trait `HasUuid` usando `Str::orderedUuid()` (UUID ordenado/COMB) em vez de `Str::uuid()` (v4 puro) — reduz fragmentação de índice B-tree no PostgreSQL para tabelas com muita escrita.

### 3.3 Decisão 2 — Pivot `user ↔ branch`

- **Nome:** `branch_user` (convenção `database.md`: modelos em ordem alfabética, singular → `branch` < `user`).
- **PK:** `uuid('id')->primary()` (consistência com o restante do schema).
- **Campos extras:** `is_default` (boolean) — marca a filial "padrão" de um usuário multi-filial (útil para pré-selecionar filial na solicitação da Fase 3, RN012.3).
- **SoftDeletes no pivot? NÃO.** `soft-deletes.md` classifica pivots simples como hard delete, gerenciados via `sync()`/`detach()`. O histórico de vínculos, se necessário no futuro, será derivado de auditoria, não do pivot.
- **Timestamps:** sim (`withTimestamps()` no relationship) — barato e útil para rastrear quando o vínculo foi criado.
- **Constraint:** `unique(['branch_id', 'user_id'])`.

### 3.4 Decisão 3 — Campos de `Company` na Fase 1

**Incluir agora:** `name`, `legal_name` (nullable), `document` (**CNPJ do grupo, obrigatório**, `ValidCnpj` + unique parcial), `is_active`, autoria + timestamps + SoftDeletes.

**Adiar (com justificativa):**
- **SLA de aprovação (`approval_sla_hours`)** → **adiar para Fase 4 (RF024).** É uma coluna nullable trivial de adicionar depois (migration de uma linha em L12). Antecipá-la agora criaria campo órfão sem consumidor, violando "não inventar requisitos fora da Fase 1". A arquitetura **documenta** que `Company` é a âncora do SLA por empresa (RN024.1) — o design já suporta a extensão sem refatoração.
- **Override de forma de pagamento por fornecedor (RN003.2)** → Fase 2 (depende de `Supplier`).

> **Confirmado (2026-07-13):** `Company.document` é **obrigatório**.

### 3.5 Decisão 4 — `BranchBankAccount` sem `Bank` (Fase 2)

**Abordagem híbrida recomendada** (usabilidade imediata + upgrade limpo):

1. **Captura manual agora:** `bank_code` (`string(3)`, código COMPE/BACEN, ex.: `341` Itaú) + `bank_name` (`string`). Permite operar a Fase 1 sem depender do cadastro de bancos.
2. **Stub para Fase 2:** `bank_id` como `uuid('bank_id')->nullable()` **sem** `constrained()` (a tabela `banks` ainda não existe — constrainá-la falharia na migration).
3. **Fase 2:** migration adiciona a FK (`$table->foreign('bank_id')->references('id')->on('banks')->nullOnDelete()`) e um comando/seed faz o *backfill* casando `bank_code` → `banks.code`.

**Por que não só `bank_id` nullable sem constraint?** Um `uuid` órfão sem dado legível força JOIN futuro e deixa a conta ilegível na Fase 1. `bank_code`+`bank_name` são autossuficientes hoje e viram fonte de verdade para o backfill.

> **Confirmado (2026-07-13):** criar stub `bank_id` uuid nullable **agora**, sem FK. Comentário obrigatório na migration; constraint entra na Fase 2.

### 3.6 Decisão 5 — Matriz de Policies (CRUD por perfil)

Base normativa: RN006.1–RN006.4 e seção 2.3. **Cadastros gerenciais são exclusivos de Adm** (RN006.4); Operador tem leitura cross-empresa; Cliente não acessa cadastros na Fase 1 (só ganhará ação real na Fase 3 com solicitações).

| Ability | `Company` | `Branch` | `BranchBankAccount` | `User` |
|---------|:--------:|:--------:|:-------------------:|:------:|
| viewAny | O, A | O, A | O, A | A |
| view | O, A | O, A¹ | O, A | A |
| create | A | A | A | A |
| update | A | A | A | A |
| delete (soft) | A | A | A | A² |
| restore | A | A | A | A |
| forceDelete | A | A | A | A |

Legenda: **C** = Cliente · **O** = Operador · **A** = Adm. Ausência = negado (403).

- ¹ `Branch.view` para Cliente é **negado na Fase 1** (Cliente não gerencia cadastros). A visibilidade de filiais vinculadas ao Cliente materializa-se na **Fase 3** via escopo de `PaymentRequest`, não via `BranchResource`.
- ² **Guardrails de negócio no `UserPolicy`/`UserService`:** Adm **não pode** (a) excluir/desativar a si mesmo; (b) excluir/desativar o **último Adm ativo**. Violação lança `UserException` (ver §7).
- **`can_approve` não é ability de policy** — é atributo do usuário. Sua verificação (aprovar solicitação) só existe a partir da Fase 4; a Fase 1 apenas **persiste** e **exibe** a flag.

Cliente autentica normalmente (RF001) e cai no Dashboard; na Fase 1 ele simplesmente não tem cadastros para operar.

### 3.7 Decisão 6 — Estratégia de escopo de visibilidade

**Recomendação: Query Scope reutilizável (`scopeVisibleTo`) + override de `getEloquentQuery()` no Resource.** **Não** usar Global Scope.

| Abordagem | Veredito | Motivo |
|-----------|:--------:|--------|
| **Global Scope** | ❌ | Afeta a aplicação inteira (seeders, jobs, console, futuros relatórios). Difícil de desligar; propenso a surpresas. Excessivo para o volume atual. |
| **Filament `modifyQueryUsing`** por tabela | ⚠️ parcial | Funciona, mas espalha regra de visibilidade em cada Table; difícil de reusar em API/relatórios futuros. |
| **Query Scope `scopeVisibleTo(User)` + `getEloquentQuery()`** | ✅ | Regra de visibilidade centralizada no Model, reutilizável em Filament, API e jobs. Explícita (chamada onde faz sentido). Casa com o padrão de Policies. |

Assinatura conceitual: `scopeVisibleTo(Builder $q, User $user)`:
- Adm/Operador → sem filtro (veem tudo).
- Cliente → `whereHas('users', fn ($q) => $q->whereKey($user->id))` (filiais vinculadas).

Na Fase 1 esse escopo é aplicável a `Branch`/`BranchBankAccount`, mas como Cliente não acessa esses Resources, o efeito prático é "Operador/Adm veem tudo". **O pattern é definido agora** para que a Fase 3 (`PaymentRequest`) o reaproveite sem retrabalho. A autorização (403) fica nas **Policies**; a filtragem de linhas (o que aparece na lista) fica no **scope**.

### 3.8 Decisão 9 — Events da Fase 1: **adiar**

A tabela de eventos de negócio do DRF (seção 6) **não contém nenhum evento da Fase 1** — todos são de pagamento/aprovação/anexo (Fase 3+). Portanto:

- **Não introduzir** `UserCreated`/`CompanyCreated`/etc. na Fase 1 (seriam eventos sem listener/efeito colateral real — ruído).
- **Auditoria de autoria** (`created_by`/`updated_by`) é **lifecycle do Model** → resolvida por **Observer/trait `HasBlameable`**, não por Event (conforme matriz de `events.md`: side-effect simples → Observer).
- Reavaliar na Fase 3/4, quando o workflow demandar eventos com listeners (notificações, cache de dashboard).

### 3.9 Decisão 10 — API REST: **não na Fase 1**

> **Pergunta obrigatória:** "Este recurso precisa de API REST para consumo externo (mobile, integrações, terceiros)?"

**Assunção documentada: NÃO.** O DRF descreve um **painel Filament interno** para operação de BPO (seção 2.1); não há menção a app mobile, integração de terceiros ou M2M na Fase 1. Toda interação ocorre via Filament autenticado por sessão. Portanto, **não** criar controllers/resources/rotas de API, Sanctum/Passport nem Swagger nesta fase. Se surgir necessidade (ex.: portal externo para Clientes), avaliar **Sanctum** (SPA/próprio) — decisão em `.ai/docs/api.md`.

---

## 4. Models

Todos os Models: `final`, `declare(strict_types=1)`, traits `HasFactory, HasUuid, SoftDeletes`, `casts()` como método, autoria via `HasBlameable`.

### 4.1 `User` (migrado)

- **Tabela:** `users`
- **Campos:**
  - `id` — `uuid` PK
  - `name` — `string(150)`
  - `email` — `string`, **sem** `->unique()` na coluna → `->index()` simples + índice único parcial (`WHERE deleted_at IS NULL`, PostgreSQL, via `DB::statement`) — ver [Revisão DBA](#revisão-dba)
  - `email_verified_at` — `timestamp` nullable
  - `password` — `string` (cast `hashed`)
  - `role` — `string(20)` default `'cliente'`, index → cast `UserRole`
  - `can_approve` — `boolean` default `false`, index
  - `is_active` — `boolean` default `true`, index
  - `remember_token`
  - `created_by` / `updated_by` — `foreignUuid` nullable → `users` (self, `->index()` **explícito** + `nullOnDelete`) — **PostgreSQL não indexa FK automaticamente** (diferente de MySQL/InnoDB); sem `->index()` explícito a coluna fica sem índice
  - `timestamps` + `softDeletes`
- **Casts:** `role => UserRole::class`, `can_approve => 'boolean'`, `is_active => 'boolean'`, `email_verified_at => 'datetime'`, `password => 'hashed'`.
- **Traits:** `HasFactory, HasUuid, SoftDeletes, Notifiable, HasBlameable`.
- **Contratos Filament:** implementar `FilamentUser` → `canAccessPanel(Panel $panel): bool { return $this->is_active; }` (RF001; todos os perfis usam o mesmo painel `admin`).
- **Relacionamentos:**
  - `branches(): BelongsToMany` → `Branch` via `branch_user`, `->withPivot('is_default')->withTimestamps()`.
  - `defaultBranch(): BelongsToMany` (ou accessor) → filial com `is_default = true`.
- **Scopes:** `scopeActive`, `scopeApprovers` (`where('can_approve', true)`), `scopeByRole(UserRole)`.
- **Helpers:** `isAdm(): bool`, `isOperador(): bool`, `isCliente(): bool`, `canApprove(): bool`.

### 4.2 `Company`

- **Tabela:** `companies`
- **Campos:** `id` uuid PK · `name` string(150) · `legal_name` string(200) nullable · `document` string(20) **obrigatório**, **sem** `->unique()` na coluna → índice único parcial (`WHERE deleted_at IS NULL`, via `DB::statement`), ValidCnpj · `is_active` boolean default true index · `created_by`/`updated_by` foreignUuid nullable, **`->index()` explícito** (Postgres não indexa FK automaticamente) · timestamps · softDeletes.
- **Casts:** `is_active => 'boolean'`.
- **Relacionamentos:** `branches(): HasMany` → `Branch`.
- **Scopes:** `scopeActive`.
- **SoftDeletes cascade:** ao soft-deletar `Company`, cascatear soft delete para `branches` e respectivas `bankAccounts` (teardown administrativo da hierarquia). A **exclusão direta** de `Branch` com contas é bloqueada (ver §4.3 / §7). **Importante:** a cascata deve ser feita via query de relacionamento em lote (`$company->branches()->delete()`, `$branch->bankAccounts()->delete()`), **nunca** instância-a-instância — ver nota crítica em [Revisão DBA](#revisão-dba) sobre a interação entre esta cascata e o guard de bloqueio de `Branch`.

### 4.3 `Branch`

- **Tabela:** `branches`
- **Campos:** `id` uuid PK · `company_id` foreignUuid → `companies`, **`->index()` explícito** (`cascadeOnDelete` na migration serve apenas para integridade em **hard delete/forceDelete**; cascade lógico de soft delete via Observer — ver abaixo) · `name` string(150) · `legal_name` string(200) **obrigatório** (diverge de `Company.legal_name`, que é nullable — decisão intencional: cada filial é a entidade legal detentora do próprio CNPJ/razão social, enquanto `Company` pode ser só o nome do grupo) · `document` string(20) — **CNPJ**, **sem** `->unique()` na coluna → índice único parcial (`WHERE deleted_at IS NULL`) · `is_active` boolean default true index · `created_by`/`updated_by` foreignUuid nullable, **`->index()` explícito** · timestamps · softDeletes.
- **Casts:** `is_active => 'boolean'`.
- **Relacionamentos:**
  - `company(): BelongsTo`
  - `bankAccounts(): HasMany` → `BranchBankAccount`
  - `users(): BelongsToMany` → `User` via `branch_user` (`withPivot('is_default')->withTimestamps()`)
- **Scopes:** `scopeActive`, `scopeVisibleTo(Builder, User)` (§3.7).
- **Exclusão com contas:** **bloquear** soft delete direto se existirem `bankAccounts` não deletados → `BranchException::cannotDeleteWithBankAccounts()`. Contas devem ser removidas/desativadas antes. (Cascade de contas só ocorre via teardown de `Company`.)
  - **Crítico (DBA):** este guard **deve viver na camada de Service** (`BranchService::delete()`) ou ser chamado explicitamente pela Action de exclusão do `BranchResource` — **nunca** como evento Eloquent `deleting` no Model `Branch`. Se implementado como evento de Model, ele também interceptaria a cascata disparada pelo teardown de `Company` (que soft-deleta `branches` em lote via `$company->branches()->delete()`), tornando **impossível** excluir uma `Company` cujas filiais tenham contas bancárias — quebrando a Decisão de Cascade de §4.2. A cascata da `Company` deve rodar via query de relacionamento em lote (bypassa eventos de Model por linha) e nesta ordem: 1) soft-delete das `bankAccounts` de cada `branch`, 2) soft-delete das `branches`.
- **Validação:** CNPJ obrigatório com Rule custom `ValidCnpj` (RNF007).

### 4.4 `BranchBankAccount`

- **Tabela:** `branch_bank_accounts`
- **Campos:** `id` uuid PK · `branch_id` foreignUuid → `branches`, **`->index()` explícito** (`cascadeOnDelete`) · `bank_id` **uuid nullable, sem FK** (stub Fase 2 — confirmado; comentar na migration: *"STUB Fase 2: referência a `banks.id`. Sem `constrained()` pois a tabela `banks` ainda não existe. Constraint + backfill via `bank_code` entram na Fase 2."*) · `bank_code` string(3) index · `bank_name` string · `agency` string(10) · `agency_digit` string(2) nullable · `account_number` string(20) · `account_digit` string(2) nullable · `account_type` string(20) nullable → cast `AccountType` (incluído na Fase 1) · `holder_name` string nullable · `is_default` boolean default false index · `is_active` boolean default true index · `created_by`/`updated_by` foreignUuid nullable, **`->index()` explícito** · timestamps · softDeletes.
- **Casts:** `account_type => AccountType::class`, `is_default => 'boolean'`, `is_active => 'boolean'`.
- **Relacionamentos:** `branch(): BelongsTo`. (`bank(): BelongsTo` → comentado/stub até Fase 2.)
- **Scopes:** `scopeActive`, `scopeDefault`.
- **Regra:** no máximo uma conta `is_default = true` por filial. Garantida na aplicação (`Service`/Observer ao salvar) **e reforçada no banco** com índice único parcial (defesa em profundidade contra concorrência): `CREATE UNIQUE INDEX branch_bank_accounts_branch_default_unique ON branch_bank_accounts (branch_id) WHERE is_default = true AND deleted_at IS NULL`.

### 4.5 Pivot `branch_user` (sem Model dedicado)

- `id` uuid PK · `branch_id` foreignUuid (cascade) · `user_id` foreignUuid (cascade), **`->index()` explícito** · `is_default` boolean default false · timestamps · `unique(['branch_id','user_id'])` (cobre buscas por `branch_id` isolado via leftmost-prefix; `user_id` isolado precisa do índice explícito acima).
- Gerenciado via `sync()`/`attach()` no `UserResource`.
- **Regra adicional (DBA):** no máximo uma filial `is_default = true` por usuário — reforçar com índice único parcial: `CREATE UNIQUE INDEX branch_user_user_default_unique ON branch_user (user_id) WHERE is_default = true` (sem filtro de `deleted_at` — pivot não tem `softDeletes`).

---

## 5. Relacionamentos

```
Company ──hasMany──────────> Branch
Branch  ──belongsTo─────────> Company
Branch  ──hasMany──────────> BranchBankAccount
Branch  ──belongsToMany────> User            (pivot: branch_user, is_default)
User    ──belongsToMany────> Branch          (pivot: branch_user, is_default)
BranchBankAccount ──belongsTo──> Branch
BranchBankAccount ──(belongsTo)─> Bank        (STUB nullable — Fase 2)
[User|Company|Branch|BranchBankAccount] ──created_by/updated_by──> User
```

---

## 6. Enums

Enums obrigatórios na Fase 1 (`enums.md` — `HasLabel`, `HasColor`, `HasIcon`, backed `string`, labels via `__()`):

### 6.1 `UserRole` (`app/Enums/UserRole.php`)

| Case | value | Label (pt_BR) | Color | Icon (Heroicon string) |
|------|-------|---------------|-------|------------------------|
| `Cliente` | `cliente` | Cliente | `gray` | `heroicon-o-user` |
| `Operador` | `operador` | Operador | `info` | `heroicon-o-user-group` |
| `Adm` | `adm` | Administrador | `success` | `heroicon-o-shield-check` |

- Sem `canTransitionTo()` (papel é atributo, não estado — DRF 5.1: transições N/A).
- Helper opcional `canManageRegistrations(): bool` (true só para `Adm`) e `seesAllBranches(): bool` (true para `Operador`/`Adm`) para uso nas Policies/scopes.
- Traduções em `lang/pt_BR/enums.php` e `lang/en/enums.php` (chave `user_role.*`).

### 6.2 `AccountType` (`app/Enums/AccountType.php`) — confirmado na Fase 1

| Case | value | Label (pt_BR) | Color | Icon |
|------|-------|---------------|-------|------|
| `Checking` | `checking` | Conta corrente | `info` | `heroicon-o-banknotes` |
| `Savings` | `savings` | Conta poupança | `success` | `heroicon-o-archive-box` |

- Campo `account_type` nullable (conta pode ser cadastrada sem tipo na Fase 1, mas o enum já existe para Select Filament e CNAB futuro).
- Traduções: `account_type.*`.

> `can_approve` **não é enum** — é `boolean`. `PersonType`, `PaymentRequestStatus`, etc. são de fases posteriores e **não** entram agora.

---

## 7. Exceções de Domínio

Hierarquia conforme `error-handling.md`: `BusinessException` (base, user-facing) → exceções de domínio com static factory methods.

- **`BusinessException`** (base) — criar em `app/Exceptions/` (ainda não existe no projeto).
- **`UserException extends BusinessException`:**
  - `cannotDeleteSelf()` — Adm tentando excluir a própria conta.
  - `cannotDeleteLastActiveAdmin()` — proteção contra sistema sem administrador.
  - `getUserMessage()` traduzido (`lang/*/users.php`).
- **`BranchException extends BusinessException`:**
  - `cannotDeleteWithBankAccounts()` — **obrigatório** (confirmado 2026-07-13). Impede soft delete direto de filial que ainda possui contas bancárias não deletadas. Mensagem via `getUserMessage()`.

Uso em Filament: `try/catch` na Action/Page + `Notification::make()->danger()` com `getUserMessage()` (padrão de `error-handling.md` §8).

---

## 8. Camadas de Aplicação

Seguindo `architecture.md` e `PROJECT.md` (usar DTOs, Services, Actions, Form Requests, Policies; agrupamento por domínio: `actions`, `events`, `listeners`, `jobs`, `integrations` = true; `services`, `dtos`, `models`, `policies` = flat).

### 8.1 Services (flat)

- **`UserService`** — `create`/`update`/`deactivate`/`delete` com `sync()` de filiais, aplicação dos guardrails (último Adm, self-delete), `DB::transaction`.
- **`BranchService`** — **obrigatório** (não mais opcional pós-revisão DBA): concentra o guard `cannotDeleteWithBankAccounts()` antes de chamar `$branch->delete()`. Este guard **não pode** ser um evento Eloquent no Model `Branch`, pois bloquearia a cascata de exclusão de `Company` (ver §4.3, [Revisão DBA](#revisão-dba) #2). Também garante unicidade de conta `is_default` na aplicação (complementar ao índice único parcial do banco).
- `Company`/`BranchBankAccount` CRUD simples podem ser resolvidos direto pelo Filament (sem Service) na Fase 1; introduzir Service quando surgir regra de negócio.

### 8.2 DTOs (flat) — opcional na Fase 1

- `UserData` (se `UserService.create` for chamado fora do Filament). Para CRUD puramente Filament, o DTO é opcional; recomendo introduzir só onde há lógica além do CRUD (ex.: `UserService`).

### 8.3 Observers / Traits de lifecycle

- **`HasBlameable`** (trait) + **`BlameableObserver`** — seta `created_by` no `creating` e `updated_by` no `saving` a partir de `auth()->id()`, com guarda para contexto sem autenticação (seeders/console → não sobrescreve). Aplicado a `Company`, `Branch`, `BranchBankAccount`, `User`.
- **Cascade de `Company` (customizado — NÃO usar o trait genérico `CascadesSoftDeletes` "as-is"):** o trait genérico de `soft-deletes.md` §6 assume `$model->{$relationship}()->delete()` para uma lista simples de relationships, o que soft-deletaria `branches` mas **não** desceria automaticamente para `bankAccounts` de cada filial. Implementar um `CompanyObserver::deleted()` explícito com 2 passos, **nesta ordem**:
  1. Para cada `branch` não deletada da company: `$branch->bankAccounts()->delete()` (bulk, via query de relacionamento — bypassa evento `deleting` do Model `Branch`).
  2. Em seguida: `$company->branches()->delete()` (bulk).
  - Esta ordem/abordagem é **crítica**: como o guard de bloqueio (`BranchException::cannotDeleteWithBankAccounts`) vive na camada de Service (ver §4.3), a cascata via query de relacionamento nunca passa por ele — não há conflito.
  - **Restore simétrico:** `CompanyObserver::restored()` deve restaurar `branches` com `deleted_at >= $company->updated_at`, e em seguida, para cada `branch` restaurada, restaurar suas `bankAccounts` com `deleted_at >= $branch->updated_at` (heurística de 2 níveis, análoga à de `soft-deletes.md` §6, porém replicada em cascata).
  - Em `Branch`, **não** cascatear `bankAccounts` no soft delete direto — exclusão com contas é **bloqueada** (`BranchException`), exceto quando disparada pela cascata de `Company` acima.
- **`HasUuid`** (trait) — `app/Models/Concerns/HasUuid.php` (criar; usa `Str::orderedUuid()`, `getIncrementing()=false`, `getKeyType()='string'`).

### 8.4 Jobs / Actions

- **Nenhum Job** na Fase 1 (sem processamento assíncrono; RNF009 é Fase 5/7).
- **Actions** só se necessário (ex.: `ToggleUserApproverAction` como Filament Action, mas cabe inline). Nada obrigatório.

---

## 9. Performance

Volume-alvo baixo (~70 pagamentos/dia; RNF011) — sem otimização prematura.

- **Índices** (via migrations):
  > **Correção DBA:** PostgreSQL **não** cria índice automático em colunas de FK (diferente de MySQL/InnoDB, que indexa a FK a nível de storage engine). `constrained()` no Laravel só adiciona a constraint, não o índice. Todo `*_id`/`*_by` abaixo precisa de `->index()` **explícito**.
  - `users`: `role`, `can_approve`, `is_active` (index); `created_by`, `updated_by` (index explícito, FK self); `email` índice único parcial (`WHERE deleted_at IS NULL`).
  - `companies`: `is_active`; `created_by`, `updated_by` (index explícito); `document` índice único parcial.
  - `branches`: `company_id` (**index explícito**, não é automático), `is_active`; `created_by`, `updated_by` (index explícito); `document` índice único parcial.
  - `branch_bank_accounts`: `branch_id` (**index explícito**), `bank_code`, `is_default`, `is_active`; `created_by`, `updated_by` (index explícito); índice único parcial `(branch_id) WHERE is_default = true AND deleted_at IS NULL`.
  - `branch_user`: `unique(branch_id, user_id)`, `user_id` (index explícito); índice único parcial `(user_id) WHERE is_default = true`.
- **Eager loading:** nas Tables via `$table->modifyQueryUsing(fn ($q) => $q->with([...]))` — `BranchResource` carrega `company`; `UserResource` carrega `branches`. Ativar `Model::preventLazyLoading(! app()->isProduction())` no `AppServiceProvider` (dev).
- **Cache:** não necessário na Fase 1. Se as Policies consultarem filiais do usuário com frequência, cachear `user->branches->pluck('id')` por request (memoização) — opcional.
- **Paginação:** padrão Filament (15/página, `PROJECT.md`).

---

## 10. Soft Deletes & Data Lifecycle

- **SoftDeletes:** `User`, `Company`, `Branch`, `BranchBankAccount` (todas entidades de domínio — `soft-deletes.md` §2).
- **Hard delete:** pivot `branch_user`, `sessions`, `password_reset_tokens`, `cache`, `jobs`.
- **Cascade manual:**
  - `Company` soft delete → soft-deleta `branches` **e** `bankAccounts` das filiais (teardown administrativo), **nesta ordem**: `bankAccounts` de cada filial primeiro, depois as `branches` — via query de relacionamento em lote (bypassa eventos de Model por linha). Ver detalhamento em §8.3.
  - `Branch` soft delete direto (via `BranchService`/Action do Resource) → **bloqueado** se houver `bankAccounts` não deletados (`BranchException::cannotDeleteWithBankAccounts()`). Contas devem ser removidas antes. **O guard vive na camada de Service, não como evento Eloquent no Model** — caso contrário bloquearia também a cascata de `Company` acima (ver nota crítica em §4.3 / [Revisão DBA](#revisão-dba)).
- **Unique + SoftDeletes (PostgreSQL):** índices **parciais** `WHERE deleted_at IS NULL` para `users.email`, `companies.document` e `branches.document` (via `DB::statement` na migration, cf. `soft-deletes.md` §10 — solução preferida para pgsql). **Importante:** as colunas correspondentes NÃO devem ter `->unique()` no nível de coluna do Blueprint — apenas `->index()` simples; o índice único real é criado via `DB::statement` após o `Schema::create`.
- **Unique parcial adicional (regras de negócio, não apenas soft delete):** `branch_bank_accounts (branch_id) WHERE is_default = true AND deleted_at IS NULL` (1 conta padrão por filial) e `branch_user (user_id) WHERE is_default = true` (1 filial padrão por usuário) — defesa em profundidade complementar à validação de Service/Observer.
- **Pruning:** não configurar na Fase 1 (dados de cadastro têm retenção longa por compliance). Reavaliar quando houver política formal de retenção.
- **Filament:** `TrashedFilter`, `RestoreAction`, `ForceDeleteAction` e `getRecordRouteBindingEloquentQuery()` (com `withoutGlobalScopes([SoftDeletingScope::class])`) em cada Resource (gerados via `--soft-deletes`).

---

## 11. Filament Resources (Formato Blueprint — OBRIGATÓRIO)

### Decisão de localização/namespace (alinhamento PROJECT.md × skill)

`PROJECT.md` define `filament_resources: "app/Filament/Resources"` e o `AdminPanelProvider` já faz `discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')`.

**Recomendação confirmada (2026-07-13): manter `App\Filament\Resources` (flat discover), adotando a estrutura de subpasta plural + classes separadas da skill dentro dele.** Ou seja, `App\Filament\Resources\Companies\CompanyResource`, etc. Isso:
- Respeita `PROJECT.md` (que sobrepõe o segmento `{Panel}` genérico da skill).
- Cumpre integralmente as regras estruturais da skill (Resource limpo, `Schemas/`+`Tables/`+`Infolist`, pasta plural, `final`, `Heroicon` enum, `->recordActions()/->toolbarActions()`).
- Evita mexer no painel (app de painel único).

> **Ao gerar via artisan** (`--panel=admin`), confirmar que o resource cai em `App\Filament\Resources\{Models}`. Se o gerador insistir em `App\Filament\Admin\Resources`, atualizar o `discoverResources` do `AdminPanelProvider` e ajustar `PROJECT.md`.

**Comando padrão (todos):**
```bash
php artisan make:filament-resource {Model} --generate --soft-deletes --view --panel=admin --no-interaction
```

---

### Resource: `CompanyResource`

```
Location: App\Filament\Resources\Companies\CompanyResource
Structure (pasta PLURAL: Companies/):
  - CompanyResource.php            (final, LIMPO — só delegates)
  - Schemas/CompanyForm.php        (final)
  - Schemas/CompanyInfolist.php    (final) — SEMPRE
  - Tables/CompaniesTable.php      (final, PLURAL)
  - Pages/ (CreateCompany, EditCompany, ListCompanies, ViewCompany)
  - RelationManagers/BranchesRelationManager.php
SoftDeletes: getRecordRouteBindingEloquentQuery() (withoutGlobalScopes SoftDeletingScope)
Icon: Heroicon::OutlinedBuildingOffice2
Navigation:
  Group: __('navigation.groups.registrations')   # "Cadastros"
  Sort: 1
Policy: CompanyPolicy (viewAny/view: Operador+Adm; create/update/delete/restore/forceDelete: Adm)
Form:
  Section "company_info":
    Field name        → Forms\Components\TextInput  | required, maxLength(150)
    Field legal_name  → Forms\Components\TextInput  | maxLength(200)
    Field document    → Forms\Components\TextInput  | required, rule(ValidCnpj), unique(ignoreRecord, whereNull deleted_at)
    Field is_active   → Forms\Components\Toggle     | default(true)
Infolist:
    Entry name / legal_name / document (TextEntry)
    Entry is_active → IconEntry->boolean()
    Entry created_at/updated_at → TextEntry->dateTime('d/m/Y H:i')
Table:
    Column name        → TextColumn | searchable, sortable
    Column document    → TextColumn | searchable, toggleable
    Column branches_count → TextColumn->counts('branches') | sortable
    Column is_active   → IconColumn->boolean() | sortable
    Column created_at  → TextColumn->dateTime | toggleable(hidden), sortable
    Filter TrashedFilter
    Filter is_active   → TernaryFilter
RelationManagers: [BranchesRelationManager]
RecordActions: [ActionGroup → View, Edit, Delete]   (via ->recordActions())
ToolbarActions: [BulkActionGroup → DeleteBulk, RestoreBulk, ForceDeleteBulk]  (via ->toolbarActions())
modifyQueryUsing: ->withCount('branches')
```

---

### Resource: `BranchResource`

```
Location: App\Filament\Resources\Branches\BranchResource
Structure (pasta PLURAL: Branches/):
  - BranchResource.php
  - Schemas/BranchForm.php
  - Schemas/BranchInfolist.php     — SEMPRE
  - Tables/BranchesTable.php
  - Pages/ (CreateBranch, EditBranch, ListBranches, ViewBranch)
  - RelationManagers/BankAccountsRelationManager.php
SoftDeletes: getRecordRouteBindingEloquentQuery()
Icon: Heroicon::OutlinedBuildingStorefront
Navigation:
  Group: __('navigation.groups.registrations')   # "Cadastros"
  Sort: 2
Policy: BranchPolicy (viewAny/view: Operador+Adm; create/update/delete/restore/forceDelete: Adm)
EloquentQuery (lista): getEloquentQuery()->visibleTo(auth user)  # escopo §3.7
Form:
  Section "branch_info":
    Field company_id  → Select | relationship('company','name'), searchable, preload, required
    Field name        → TextInput | required, maxLength(150)
    Field legal_name  → TextInput | required, maxLength(200)
    Field document    → TextInput | required, rule(ValidCnpj), unique(ignoreRecord, whereNull deleted_at)
    Field is_active   → Toggle | default(true)
Infolist:
    Section branch_info: company.name, name, legal_name, document, is_active(IconEntry)
    Section audit: created_at, updated_at (TextEntry dateTime)
Table:
    Column company.name → TextColumn | searchable, sortable
    Column name         → TextColumn | searchable, sortable
    Column document     → TextColumn | searchable
    Column bank_accounts_count → TextColumn->counts('bankAccounts')
    Column is_active    → IconColumn->boolean() | sortable
    Filter company      → SelectFilter->relationship('company','name'), searchable, preload
    Filter is_active    → TernaryFilter
    Filter TrashedFilter
    modifyQueryUsing: ->with('company')->withCount('bankAccounts')
RelationManagers: [BankAccountsRelationManager]
RecordActions: [ActionGroup → View, Edit, Delete]
ToolbarActions: [BulkActionGroup → DeleteBulk, RestoreBulk, ForceDeleteBulk]
```

**`BankAccountsRelationManager`** (gerar via `make:filament-relation-manager BranchResource bankAccounts bank_name --generate --soft-deletes --panel=admin --no-interaction`):
```
Fields: bank_code (TextInput,3) · bank_name (TextInput,required) · agency (+agency_digit)
        · account_number (+account_digit) · account_type (Select → AccountType::class, nullable)
        · holder_name (TextInput nullable) · is_default (Toggle) · is_active (Toggle)
Table columns: bank_name, bank_code, agency, account_number, is_default(Icon), is_active(Icon)
recordActions: [Edit, Delete]  ·  headerActions: [Create]  ·  toolbarActions: [BulkActionGroup → DeleteBulk]
Regra: ao marcar is_default, desmarcar as demais contas da filial (Service/Observer).
```

> `BranchBankAccount` **não** terá Resource standalone na Fase 1 — é subcadastro da filial (RF005 "subcadastro da filial"), atendido pelo RelationManager.

---

### Resource: `UserResource`

```
Location: App\Filament\Resources\Users\UserResource
Structure (pasta PLURAL: Users/):
  - UserResource.php
  - Schemas/UserForm.php
  - Schemas/UserInfolist.php       — SEMPRE
  - Tables/UsersTable.php
  - Pages/ (CreateUser, EditUser, ListUsers, ViewUser)
SoftDeletes: getRecordRouteBindingEloquentQuery()
Icon: Heroicon::OutlinedUsers
Navigation:
  Group: __('navigation.groups.settings')   # "Configurações"
  Sort: 1
Policy: UserPolicy (TODAS as abilities: Adm only) + guardrails (last-admin, self-delete)
Form:
  Section "user_info":
    Field name       → TextInput | required, maxLength(150)
    Field email      → TextInput->email() | required, unique(ignoreRecord, whereNull deleted_at)
    Field password   → TextInput->password() | required on create, dehydrated(fn($s)=>filled), hashed, revealable
    Field role       → Select->options(UserRole::class) | required, native(false), live
    Field can_approve→ Toggle | visible(role ∈ {Operador,Adm}), default(false)   # RN002.3
    Field is_active  → Toggle | default(true)
  Section "branches":
    Field branches   → Select->relationship('branches','name')->multiple() | preload, searchable  # RN002.2 N:N
    Field default_branch (pivot is_default) → Select | options = filiais selecionadas, nullable
      # gravar via mutateRelationshipDataBeforeSave / afterSave sync com is_default
Infolist:
    name, email, role(badge), can_approve(IconEntry), is_active(IconEntry)
    branches → RepeatableEntry/TextColumn list (name + is_default)
Table:
    Column name       → TextColumn | searchable, sortable
    Column email      → TextColumn | searchable
    Column role       → TextColumn->badge() | sortable            # HasColor/HasIcon do UserRole
    Column can_approve→ IconColumn->boolean()
    Column branches.name → TextColumn->badge()->limitList(3)      # filiais vinculadas
    Column is_active  → IconColumn->boolean() | sortable
    Filter role       → SelectFilter->options(UserRole::class)
    Filter can_approve→ TernaryFilter
    Filter is_active  → TernaryFilter
    Filter TrashedFilter
    modifyQueryUsing: ->with('branches')
RecordActions: [ActionGroup → View, Edit, Delete(guarded)]
ToolbarActions: [BulkActionGroup → DeleteBulk, RestoreBulk, ForceDeleteBulk]
```

> **`is_default` no N:N:** na Fase 1, recomendo a abordagem simples — `Select multiple` para `branches` + um `Select default_branch` (opções = filiais selecionadas). No `afterSave`, faz `sync` do pivot marcando `is_default` só na filial escolhida. Alternativa mais rica (Repeater sobre o pivot) é possível, mas *overkill* para a Fase 1.

**Widgets:** nenhum obrigatório na Fase 1 (Dashboard do DRF é Fase 8). O `AccountWidget`/`FilamentInfoWidget` já existentes podem permanecer.

**Componentes Livewire custom:** nenhum — Filament puro cobre 100% dos cadastros. Sem islands/Alpine custom.

---

## 12. Fluxos

### Fluxo principal — Bootstrap do ambiente

1. Adm autentica no painel `admin` (RF001).
2. Adm cadastra **Company** (RF003).
3. Adm cadastra **Branch** vinculada à Company, com CNPJ validado (RF004); cadastra **contas bancárias** da filial via RelationManager (RF005).
4. Adm cadastra **usuários** (RF002): define `role`, `can_approve` (se Operador/Adm) e vincula **filiais** (N:N).
5. Operador acessa e **visualiza** empresas/filiais/contas (leitura cross-empresa).
6. Cliente autentica → Dashboard (sem cadastros a operar na Fase 1).

### Fluxos alternativos / edge cases

- **Adm tenta excluir a si mesmo** → `UserException::cannotDeleteSelf()` → Notification danger (403 lógico).
- **Adm tenta excluir o último Adm ativo** → `UserException::cannotDeleteLastActiveAdmin()`.
- **CNPJ inválido/duplicado** (mesmo com registro soft-deleted) → validação `ValidCnpj` + unique parcial → erro inline no form.
- **Cliente acessa URL direta de Company/Branch/User** → `Policy` retorna 403 (RF006 critério de aceite).
- **Exclusão de Branch com contas** → `BranchException::cannotDeleteWithBankAccounts()` → Notification danger; contas devem ser removidas antes.
- **Exclusão de Company** → cascade soft delete de `branches` e `bankAccounts` (teardown administrativo).
- **Marcar 2ª conta como padrão** → desmarca a anterior (garante 1 default por filial).

---

## 13. Segurança

- **Autenticação:** sessão Filament padrão; sem 2FA (RN001.2). `canAccessPanel()` bloqueia usuários inativos.
- **Autorização:** Policies por Model (§3.6) + escopo de visibilidade (§3.7). Toda ação sensível negada retorna 403 (RF006).
- **Dados bancários (RF005/RNF006):** protegidos por controle de acesso (Operador/Adm); sem mascaramento LGPD (decisão RJET R12). Não expor via API (não há API na F1).
- **Blameable:** `created_by`/`updated_by` para trilha de autoria em todas as entidades.
- **Guardrails de negócio:** proteção contra remoção do último administrador e auto-exclusão.
- **Unique parcial:** evita reuso de e-mail/CNPJ de registros ativos, permitindo reaproveitar documentos de registros soft-deleted (fluxo de recriação via Service, se necessário).

---

## 14. Factories & Seeders

Locale `pt_BR`, `fake()` (nunca `$this->faker`), states, idempotência (`factories-seeders.md`).

**Factories:**
- `UserFactory` — states: `adm()`, `operador()`, `cliente()`, `approver()` (`can_approve=true`), `inactive()`. State `withBranches(int|array)` (attach via `afterCreating`).
- `CompanyFactory` — `name = fake()->company()`, `document = fake()->cnpj(false)`, `is_active=true`; state `inactive()`, `withBranches(int)`.
- `BranchFactory` — `company_id => Company::factory()`, `document = fake()->cnpj(false)`, `legal_name`, `is_active`; state `withBankAccounts(int)`.
- `BranchBankAccountFactory` — `branch_id => Branch::factory()`, `bank_code = fake()->randomElement(['341','237','001','033','104'])`, `bank_name`, `agency`, `account_number`, `is_default=false`, `is_active=true`; state `default()`.

**Seeders (idempotentes, ordem: companies → branches → users → vínculos):**
- `CompanySeeder` — `firstOrCreate` "Altitude", "Glow"; cada uma com 2 filiais (via factory ou firstOrCreate por CNPJ).
- `UserSeeder` — `updateOrCreate` por email:
  - `admin@rjet.com` → `role=Adm`
  - `operador@rjet.com` → `role=Operador`, `can_approve=true`
  - `cliente@rjet.com` → `role=Cliente`, vinculado a **≥2 filiais** de empresas diferentes (multi-filial; uma marcada `is_default`)
  - senha padrão `password` (ambiente local).
- `DatabaseSeeder` orquestra; `DevelopmentSeeder` (só `local`/`testing`) gera volume via factories.

**Cenário mínimo atendido:** 1 Adm, 1 Operador com `can_approve`, 1 Cliente multi-filial, 2 Companies (Altitude/Glow) com filiais e contas bancárias.

---

## 15. i18n

- `lang/pt_BR/` e `lang/en/`: `common.php`, `navigation.php` (grupos: `registrations`="Cadastros", `settings`="Configurações"), `enums.php` (`user_role.*`), `companies.php`, `branches.php`, `branch_bank_accounts.php`, `users.php`, `errors.php`.
- Todos os labels/mensagens Filament via `__()` (skill Filament + `localization.md`).

---

## 16. Decisões confirmadas (2026-07-13)

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | `Company.document` | **Obrigatório** (CNPJ + `ValidCnpj` + unique parcial) |
| 2 | Localização dos Resources | **`App\Filament\Resources`** (flat + subpastas plurais) |
| 3 | Stub `bank_id` | **Criar agora** (uuid nullable, sem FK; constraint na Fase 2) |
| 4 | Validação de CNPJ | **Rule custom `ValidCnpj`** (sem pacote novo) |
| 5 | Exclusão de `Branch` com contas | **Bloquear** (`BranchException::cannotDeleteWithBankAccounts`) |
| 6 | `account_type` | **Incluir na Fase 1** (enum `AccountType`, campo nullable) |
| 7 | Cliente no painel na Fase 1 | **Ok** — autentica (`is_active`); sem cadastros até Fase 3 |
| 8 | CHECK `can_approve` no banco | **Não** — Form + Service bastam |
| 9 | `timestampsTz` / `softDeletesTz` | **Sim** — convenção padrão do projeto (`PROJECT.md` / `database.md`) |

---

## Revisão DBA

> **Revisor:** dba (subagent) · **Data:** 2026-07-13 · **Driver:** PostgreSQL
> **Escopo:** schema/modelagem de dados de `users`, `companies`, `branches`, `branch_bank_accounts`, `branch_user` e ajuste em `sessions`. Decisões de negócio (§16) não foram reabertas.

### Veredito

**Aprovado com ressalvas.** A modelagem de domínio (hierarquia `Company → Branch → BranchBankAccount`, pivot `branch_user`, migração de `users` para UUID) está correta, normalizada (3NF) e alinhada aos padrões do projeto (`database.md`, `enums.md`, `soft-deletes.md`). Os problemas encontrados são **de detalhe de implementação** (índices ausentes por causa de uma suposição incorreta sobre PostgreSQL, e uma interação não resolvida entre a cascata de exclusão de `Company` e o guard de bloqueio de `Branch`) — nenhum deles exige redesenho de tabelas ou reabertura de decisões de negócio. Todas as correções já foram incorporadas nas seções §3–§10 acima; esta seção consolida o veredito, o schema final e a ordem de migrations para o `implementer`.

### Achados (Crítico / Importante / Sugestão)

| # | Nível | Achado | Impacto | Correção aplicada |
|---|-------|--------|---------|--------------------|
| 1 | **Crítico** | O documento original assumia índice automático em FK (`"company_id (auto)"`, §9). **PostgreSQL não indexa FK automaticamente** — isso é comportamento do storage engine InnoDB do MySQL, não do Postgres nem do Laravel. | Sem correção, `company_id`, `branch_id`, `created_by`, `updated_by` ficariam **sem índice**, degradando joins/cascades mesmo em baixo volume (RNF011 não isenta FKs usadas em lookups de cascade/guard). | `->index()` explícito adicionado em toda coluna de FK (§4.1–§4.5, §9). |
| 2 | **Crítico** | Guard `BranchException::cannotDeleteWithBankAccounts()` (§4.3/§7) e cascade de `Company` (§4.2) são **mutuamente incompatíveis** se o guard for implementado como evento Eloquent `deleting` no Model `Branch`: a cascata de `Company` ficaria permanentemente bloqueada sempre que qualquer filial tivesse conta bancária — o que é o caso comum, não a excepção. | Sem correção: exclusão de `Company` nunca funcionaria em cenário real. | Guard movido explicitamente para a camada de **Service** (`BranchService`/Action do Resource), nunca no Model. Cascata de `Company` especificada com ordem exata (bankAccounts → branches) via query de relacionamento em lote, que bypassa eventos de Model por linha (§4.2, §4.3, §8.3, §10). |
| 3 | **Crítico** | `email` (`users`) e `document` (`companies`, `branches`) apareciam como "unique parcial" sem deixar explícito que a coluna **não** deve usar `->unique()` do Blueprint (isso criaria constraint full-table incompatível com soft deletes, conforme `soft-deletes.md` §10). | Risco de o `implementer` aplicar `->unique()` + índice parcial redundante/conflitante, ou de a migration falhar. | Explicitado em §4.1/§4.2/§4.3/§10: coluna com `->index()` simples; unicidade real via `DB::statement(...)` **após** o `Schema::create`. |
| 4 | Importante | Regra "1 conta padrão por filial" e "1 filial padrão por usuário" só existiam como validação de aplicação (Service/Observer), sem reforço no banco. | Race condition (dois requests simultâneos) pode gerar 2 registros `is_default = true` para a mesma filial/usuário. | Adicionados índices únicos parciais: `branch_bank_accounts (branch_id) WHERE is_default AND deleted_at IS NULL` e `branch_user (user_id) WHERE is_default` (§4.4, §4.5, §10). |
| 5 | Importante | Restore em cascata de `Company` (§8.3) citava apenas `branches`, sem especificar o segundo nível (`bankAccounts` de cada filial restaurada). | `Company::restore()` deixaria `branches` visíveis mas suas contas bancárias continuariam soft-deleted (dado inconsistente). | Heurística de 2 níveis especificada explicitamente em §8.3 (`deleted_at >= updated_at` do pai imediato, aplicada recursivamente). |
| 6 | Importante | Ordem de execução das migrations não estava explícita (dependências de FK entre `users → companies → branches → branch_bank_accounts → branch_user`). | Risco de o `implementer` criar migrations fora de ordem e falhar por FK inexistente. | Checklist de ordem abaixo. |
| 7 | Sugestão | Self-referencing FK (`created_by`/`updated_by` → `users.id`) dentro do mesmo `Schema::create('users', ...)` é válido em PostgreSQL — não há problema de "ordem de criação" (a constraint resolve contra a tabela completa ao final do DDL). | Nenhum — apenas esclarecimento para não gerar hesitação no `implementer`. | Nota adicionada em §3.2. |
| 8 | Sugestão | Índices simples em booleanos de baixa cardinalidade (`is_active`, `can_approve`) em tabelas pequenas (Fase 1 = dezenas de registros) são estatisticamente prematuros pela regra geral de `performance.md` §3 ("não indexar boolean 50/50 em tabela pequena"). | Nenhum impacto de correção — custo do índice é desprezível. | Mantido por consistência com a convenção explícita e mais específica de `database.md` §5 ("sempre indexar `is_active`/`is_default`"), que prevalece sobre a heurística geral de `performance.md`. Não bloqueante. |
| 9 | Sugestão | `Company.legal_name` nullable vs `Branch.legal_name` obrigatório é uma divergência que poderia parecer inconsistência. | Nenhum — decisão de negócio válida (grupo vs entidade legal com CNPJ próprio). | Justificativa explicitada inline em §4.3 para não gerar dúvida na implementação. |
| 10 | Sugestão | CHECK constraint opcional `can_approve = false OR role IN ('operador','adm')` em `users`, como defesa extra no banco além da regra de UI (RN002.3). | Nenhum se omitido — regra já coberta na Form (`visible()`) e pode ser coberta no Service. | **Confirmado (2026-07-13):** Form + Service bastam — **sem CHECK** no banco. |
| 11 | Sugestão | `timestamps()`/`softDeletes()` usam `timestamp` (sem timezone); o projeto tem timezone de negócio explícito (`America/Sao_Paulo`). `timestampsTz()`/`softDeletesTz()` seriam mais seguros. | Nenhum na Fase 1 se omitido, mas ambiguidade de fuso em dados futuros. | **Confirmado (2026-07-13):** convenção padrão do projeto. Blueprint abaixo e `PROJECT.md`/`database.md` atualizados para `timestampsTz()` / `softDeletesTz()`. |

### Schema Final Consolidado (blueprint por tabela)

```php
// ── users (rewrite de 0001_01_01_000000_create_users_table.php) ──────────────
Schema::create('users', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name', 150);
    $table->string('email')->index();               // NÃO ->unique() — ver índice parcial abaixo
    $table->timestampTz('email_verified_at')->nullable();
    $table->string('password');
    $table->string('role', 20)->default('cliente')->index();   // cast UserRole
    $table->boolean('can_approve')->default(false)->index();
    $table->boolean('is_active')->default(true)->index();
    $table->rememberToken();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});

DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');

// password_reset_tokens: sem alteração (chaveado por email)

Schema::create('sessions', function (Blueprint $table) {
    $table->string('id')->primary();
    $table->foreignUuid('user_id')->nullable()->index();   // sem constrained() — tabela efêmera
    $table->string('ip_address', 45)->nullable();
    $table->text('user_agent')->nullable();
    $table->longText('payload');
    $table->integer('last_activity')->index();
});

// down(): DB::statement('DROP INDEX IF EXISTS users_email_unique'); antes de dropIfExists('users')

// ── companies ──────────────────────────────────────────────────────────────
Schema::create('companies', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name', 150);
    $table->string('legal_name', 200)->nullable();
    $table->string('document', 20);                  // CNPJ do grupo — obrigatório, NÃO ->unique()
    $table->boolean('is_active')->default(true)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});

DB::statement('CREATE UNIQUE INDEX companies_document_unique ON companies (document) WHERE deleted_at IS NULL');

// ── branches ───────────────────────────────────────────────────────────────
Schema::create('branches', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('company_id')->index()->constrained('companies')->cascadeOnDelete(); // cascade = integridade em forceDelete
    $table->string('name', 150);
    $table->string('legal_name', 200);                // obrigatório (diverge de Company, ver nota §4.3)
    $table->string('document', 20);                   // CNPJ da filial, NÃO ->unique()
    $table->boolean('is_active')->default(true)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});

DB::statement('CREATE UNIQUE INDEX branches_document_unique ON branches (document) WHERE deleted_at IS NULL');

// ── branch_bank_accounts ───────────────────────────────────────────────────
Schema::create('branch_bank_accounts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('branch_id')->index()->constrained('branches')->cascadeOnDelete();
    // STUB Fase 2: referência a `banks.id`. Sem constrained() pois `banks` ainda não existe.
    // Constraint + backfill (via bank_code) entram na Fase 2.
    $table->uuid('bank_id')->nullable();
    $table->string('bank_code', 3)->index();
    $table->string('bank_name');
    $table->string('agency', 10);
    $table->string('agency_digit', 2)->nullable();
    $table->string('account_number', 20);
    $table->string('account_digit', 2)->nullable();
    $table->string('account_type', 20)->nullable();   // cast AccountType
    $table->string('holder_name')->nullable();
    $table->boolean('is_default')->default(false)->index();
    $table->boolean('is_active')->default(true)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});

DB::statement(<<<'SQL'
    CREATE UNIQUE INDEX branch_bank_accounts_branch_default_unique
    ON branch_bank_accounts (branch_id) WHERE is_default = true AND deleted_at IS NULL
SQL);

// ── branch_user (pivot) ────────────────────────────────────────────────────
Schema::create('branch_user', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
    $table->foreignUuid('user_id')->index()->constrained('users')->cascadeOnDelete();
    $table->boolean('is_default')->default(false);
    $table->timestampsTz();

    $table->unique(['branch_id', 'user_id']);
});

DB::statement(<<<'SQL'
    CREATE UNIQUE INDEX branch_user_user_default_unique
    ON branch_user (user_id) WHERE is_default = true
SQL);
```

> Todos os `down()` correspondentes devem fazer `DROP INDEX IF EXISTS ...` (para os índices criados via `DB::statement`) **antes** do `Schema::dropIfExists(...)` — embora `dropIfExists` já remova índices junto com a tabela, o `DROP INDEX` explícito documenta a reversão simetricamente e evita erro caso a ordem dos `down()` mude no futuro.

### Checklist de Migrations — Ordem de Execução

1. **Reescrever** `0001_01_01_000000_create_users_table.php` — `users` (UUID) + `sessions.user_id` (UUID). Mesma migration base (greenfield, nenhuma tabela em produção ainda).
2. `..._create_companies_table.php` — depende de `users` (FK `created_by`/`updated_by`).
3. `..._create_branches_table.php` — depende de `companies` (FK `company_id`) e `users`.
4. `..._create_branch_bank_accounts_table.php` — depende de `branches` e `users`.
5. `..._create_branch_user_table.php` — depende de `branches` e `users` (não depende de `companies` diretamente).

Cada migration inclui seu próprio índice único parcial via `DB::statement` **dentro do mesmo arquivo** (não criar uma migration separada só para índices — mantém schema e constraints coesos e simplifica o `down()`).

### Decisões DBA confirmadas (2026-07-13)

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | CHECK `can_approve` no banco? | **Não** — Form + Service bastam |
| 2 | `timestampsTz()` / `softDeletesTz()` como padrão do projeto? | **Sim** — registrado em `PROJECT.md` e `.ai/docs/database.md`; blueprint acima atualizado |

---

## 17. Próximos passos / Handoff

1. ~~**`dba`** — revisar o schema proposto~~ ✅ **Concluído em 2026-07-13** — ver [Revisão DBA](#revisão-dba). Schema aprovado com ressalvas; correções e decisões DBA já incorporadas.
2. **`/blueprint`** — detalhar o plano Filament dos 3 Resources + RelationManagers (se o fluxo do projeto usar esse comando).
3. **`/feature`** (ou `implementer`) — implementar na ordem:
   1. Trait `HasUuid` + `HasBlameable` + Observer de autoria.
   2. Enums `UserRole` + `AccountType` + traduções.
   3. Rule `ValidCnpj`.
   4. Reescrever migration `users` (UUID) + migrations `companies`, `branches`, `branch_bank_accounts`, `branch_user` + índices (explícitos em FK) + índices únicos parciais — **seguir exatamente o blueprint e a ordem de execução da [Revisão DBA](#revisão-dba)**.
   5. Models + relationships + scopes + casts.
   6. `BusinessException`/`UserException`/`BranchException`.
   7. `CompanyObserver` com cascade de 2 níveis (soft delete e restore) — ver §8.3 e nota crítica #2 da Revisão DBA. **Não** implementar o guard de `Branch` como evento de Model.
   8. Policies (`Company`, `Branch`, `BranchBankAccount`, `User`) + registro.
   9. `UserService` (guardrails + sync filiais) e `BranchService` com o guard `cannotDeleteWithBankAccounts()` **na camada de Service** (nunca como evento Eloquent no Model `Branch`).
   10. Factories + Seeders.
   11. Filament Resources (via artisan `--generate --soft-deletes --view --panel=admin`) + RelationManagers + i18n.
   12. `AppServiceProvider`: `Model::preventLazyLoading(!isProduction())`.
4. **`tester`** — Pest: soft delete/restore/cascade Company (2 níveis: branches + bankAccounts), bloqueio de exclusão de Branch com contas (via Service, não via Model), cascata de Company não deve ser bloqueada mesmo com contas existentes, unicidade de `is_default` (bank account e branch_user) sob concorrência, unique com soft delete, guardrails de Adm, autorização por perfil (403), visibilidade, enums, Livewire tests dos Resources.
5. **`vendor/bin/pint --dirty`** ao final de cada lote de PHP.

> Consultar `.ai/docs/soft-deletes.md` (cascade/unique/pgsql), `.ai/docs/enums.md`, `.ai/checklists.md` (Model, Migration, Filament Resource, Enum, Factory, Test) e `.ai/skills/filament/SKILL.md` (estrutura obrigatória) durante a implementação.
