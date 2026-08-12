# Entrega Técnica — Fase 4: Workflow com alçadas

| Campo | Valor |
|-------|-------|
| **Fase** | 4 — Workflow com alçadas |
| **Data** | 2026-08-12 |
| **Status** | Entregue |
| **Stack** | Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL · Redis |
| **Requisitos** | RF019–RF024 |

---

## Resumo executivo

A Fase 4 entrega o **workflow de alçadas** antes do lançamento operacional (`Launched`): faixas de valor por filial (`ApprovalRule`), roteamento automático para `Approval` Pending, approve/reject/resubmit no painel, fingerprint material como gate de `Requested → Launched`, SLA em **dias úteis** por empresa e escalação por **sininho Filament** (canal `database`, sem reassign no breach). Aprovador inativo dispara reatribuição para Adm com trilha em `approval_reassignments`. Reviewer: **aprovado com ressalvas** (correções de `created_by` no route e N+1); tester: subset Approval* verde; gaps E2E (toast textual de launch, bell, smoke CRUD PR completo) adiados e não bloqueantes.

---

## Objetivos / RFs cobertos

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| **RF019** | Faixas de valor por filial + aprovador (`can_approve`); Adm CRUD; desempate documentado | `ApprovalRule`, `ApprovalRuleService`, `ApprovalRuleResource` |
| **RF020** | Roteamento automático → `Approval` Pending (create Cliente ou interno) | `ApprovalService::route`, `RoutePaymentRequestOnCreated` |
| **RF021** | Notificar aprovador; falha de notify não corrompe estado | `ApprovalAssignedNotification` (mail + database, `ShouldQueue`) |
| **RF022** | Rejected → devolução ao solicitante; reenvio explícito | `ApprovalService::reject` / `resubmit`, actions Filament |
| **RF023** | Trilha `Approval` + `ApprovalStatus` (Pending / Approved / Rejected) | Model `Approval`, `ApprovalsRelationManager` |
| **RF024** | SLA por Company + escalação + `ApprovalSlaBreached` | `approval_sla_business_days`, `approvals:escalate-sla`, sininho |

---

## O que foi entregue

### Domínio e persistência

- **Models:** `ApprovalRule` (SoftDeletes), `Approval` (append-only), `ApprovalReassignment` (append-only, sem `updated_at`); extensões em `Company`, `PaymentRequest`, `User`, `Branch`.
- **Enum:** `ApprovalStatus` (`Pending` → `Approved` | `Rejected`; escalação SLA **não** muda status).
- **Migrations:** `companies.approval_sla_business_days` (default 2); `approval_rules`; `approvals` (+ unique parcial Pending via `supportsPartialIndexes()`); `approval_reassignments`; `notifications` (canal database).
- **Helper:** `App\Support\BusinessDays::add` (Mon–Fri; feriados BR **não** na F4).
- **DTO:** `ApprovalRuleData`.
- **Exceções:** `ApprovalException`, `ApprovalRuleException`.
- **Services:** `ApprovalRuleService`, `ApprovalService` (route / approve / reject / resubmit / escalate / reassign); gate `hasApprovedForLaunch()` em `PaymentRequestService::transitionStatus(Launched)`; hook `UserService::deactivate` → `reassignFromInactiveApprover`.
- **Fingerprint material:** valor / branch / fornecedor / bank details / anexo boleto invalidam Approved; CC / apropriação / notes **não**.
- **Factories / seeder:** `ApprovalRuleFactory`, `ApprovalFactory`, `ApprovalReassignmentFactory`; `ApprovalRuleSeeder` (faixas 0–5k / 5k–50k / >50k por filial demo); `CompanyFactory` com SLA.

### Filament (painel admin)

- **`ApprovalRuleResource`** (settings, só Adm): Form / Table / Infolist / Pages CRUD + soft deletes.
- **`CompanyResource`:** campo `approval_sla_business_days` (Adm).
- **`PaymentRequestResource`:**
  - Tabs: Todas / Minhas pendências / Aguardando aprovação (Adm) / Devolvidas.
  - Badge `approval_state`, filtros, section Infolist de aprovação.
  - Actions: Approve, Reject (motivo ≥5), Resubmit, SendForApproval.
  - `ApprovalsRelationManager` (read-only).
- **Sininho:** `AdminPanelProvider` → `databaseNotifications()`.

### Autorização e regras de negócio

- **Policies:** `ApprovalRulePolicy` (Adm), `ApprovalPolicy` (view via PR); `PaymentRequestPolicy` estendido (`approve` / `reject` / `resubmitForApproval`).
- **Sem bypass Adm** para launch sem Approved válido; **sem** auto-launch (approve só libera gate).
- **Edição:** C/O bloqueados com Pending; Adm pode; Cliente não edita após Approved válido.
- **Decisões BA fechadas:** escalação = notificação interna; SLA dias úteis; sempre `route()` no create; matriz default/seeders; reassign só se aprovador inativo.

### Ops / comandos

- **`approvals:escalate-sla {--dry-run}`:** marca `escalated_at`, dispara `ApprovalSlaBreached` uma vez (idempotente).
- **Schedule** em `routes/console.php`: a cada 15 minutos, `withoutOverlapping()`.

### Events / Listeners / Notifications

| Evento | Listener | Canais | Fila |
|--------|----------|--------|------|
| `PaymentRequestCreated` | `RoutePaymentRequestOnCreated` (sync) + `LogPaymentRequestActivity` (F3) | — | Route sync |
| `ApprovalAssigned` | `SendApprovalAssignedNotification` | mail + database | Sim |
| `ApprovalReassigned` | `SendApprovalReassignedNotification` | mail + database | Sim |
| `PaymentRequestApproved` | `SendPaymentRequestApprovedNotification` | mail + database | Sim |
| `PaymentRequestRejected` | `SendPaymentRequestRejectedNotification` | mail + database | Sim |
| `ApprovalSlaBreached` | `SendApprovalSlaBreachedNotifications` (aprovador + Adms ativos) | **database only** | Sim |

Listeners de notificação: discovery automática; Notifications `final` + `ShouldQueue`.

### i18n

- `lang/{pt_BR,en}/approval_rules.php`, `approvals.php`, `notifications.php`.
- Extensões em `enums`, `companies`, `payment_requests`, `users`.

### Testes (Pest)

**58 casos** em **12 arquivos** dedicados à Fase 4 (contagem `it(` no repositório):

| Área | Arquivo(s) |
|------|------------|
| Service / roteamento / gate | `ApprovalServiceTest`, `ApprovalRuleServiceTest` |
| SLA | `ApprovalSlaEscalationTest` |
| Reassign / cascade | `ApprovalReassignmentTest`, `ApprovalCascadeTest` |
| Policies | `ApprovalAuthorizationTest` |
| Filament | `ApprovalRuleResourceTest`, `PaymentRequestApprovalActionsTest` |
| Unit | `ApprovalStatusTest`, `BusinessDaysTest`, `MaterialFingerprintTest`, `PaymentRequestEditabilityTest` |

Reviewer: aprovado com ressalvas (`created_by` no route; N+1). Tester: subset verde; gaps E2E adiados (ver riscos).

---

## Decisões relevantes

1. **Escalação SLA** = sininho Filament (`database`); **sem** mail e **sem** reassign no breach; `escalated_at` uma vez.
2. **SLA** = `approval_sla_business_days` (default 2), Mon–Fri; sem feriados na F4; `due_at` é snapshot no `route()`.
3. **Sem bypass Adm** e **sem auto-launch** — O/A lança depois com gate.
4. **Sempre passa por aprovação** (create Cliente ou interno).
5. **Matriz default** via `ApprovalRuleSeeder` (baseline BA #6 / R05).
6. **Aprovador inativo** → reassign Adm + `approval_reassignments` + `ApprovalReassigned`.
7. **Fingerprint material** invalida Approved para launch; campos menores preservam o gate.
8. **Approved** notifica solicitante via **mail + database**.
9. Enum `PaymentRequestStatus` **inalterado**; UI deriva de Approval (badge/tabs).

---

## Fora de escopo

| Item | Fase prevista |
|------|---------------|
| API REST / Sanctum / Swagger | — |
| `ApprovalResource` CRUD dedicado | — (fila via tabs/filters na PR) |
| Feriados BR no cálculo de SLA | — (hook futuro sem mudar coluna) |
| Multi-nível sequencial na mesma PR | — |
| Importação em lote / CNAB / dashboard | F5–F8 |
| Jobs dedicados além de `ShouldQueue` nas Notifications | — |

---

## Como validar

### 1. Ambiente e migrations

```bash
docker compose exec app php artisan migrate

# Opcional: regras demo
docker compose exec app php artisan db:seed --class=ApprovalRuleSeeder
```

### 2. Testes Pest (subset Fase 4)

```bash
docker compose exec app php artisan test --compact \
  tests/Feature/ApprovalServiceTest.php \
  tests/Feature/ApprovalRuleServiceTest.php \
  tests/Feature/ApprovalSlaEscalationTest.php \
  tests/Feature/ApprovalReassignmentTest.php \
  tests/Feature/ApprovalCascadeTest.php \
  tests/Feature/ApprovalAuthorizationTest.php \
  tests/Feature/Filament/ApprovalRuleResourceTest.php \
  tests/Feature/Filament/PaymentRequestApprovalActionsTest.php \
  tests/Unit/ApprovalStatusTest.php \
  tests/Unit/BusinessDaysTest.php \
  tests/Unit/MaterialFingerprintTest.php \
  tests/Unit/PaymentRequestEditabilityTest.php
```

Filtro rápido: `php artisan test --compact --filter=Approval`.

### 3. Comando e schedule SLA

```bash
# Simular
docker compose exec app php artisan approvals:escalate-sla --dry-run

# Executar
docker compose exec app php artisan approvals:escalate-sla
```

Schedule: `routes/console.php` — `approvals:escalate-sla` a cada 15 minutos (`withoutOverlapping`). Cron do host deve chamar `schedule:run`.

### 4. Smoke manual (Filament)

1. Adm: Configurações → **Alçadas** — CRUD de faixas; Empresa → SLA em dias úteis.
2. Criar solicitação (Cliente ou Operador) — verificar Pending + notificação ao aprovador (mail/fila + sininho).
3. Aprovar — PR permanece `Requested`; O/A lança para `Launched` só com fingerprint válido.
4. Rejeitar com motivo — tab **Devolvidas** + **Reenviar para aprovação**.
5. Editar valor/bank pós-Approved — launch bloqueado até resubmit; editar notes/CC — launch OK.
6. Desativar aprovador com Pending — reassign Adm + histórico.
7. Pending com `due_at` passado — comando SLA → sininho para aprovador + Adms (sem mail).

---

## Riscos / follow-ups conhecidos

- **Gaps E2E adiados (tester):** assert de toast textual no launch sem aprovação; bell database E2E no browser; smoke CRUD completo de PaymentRequest além das actions de aprovação — não bloqueantes para aceite da F4.
- **Feriados BR no SLA:** não implementados; em feriados o prazo “útil” pode parecer curto (comportamento documentado).
- **R05 / matriz real:** seeders são baseline; dados reais por filial podem ser ajustados operacionalmente antes do go-live.
- **Escalação só sininho:** depende de usuários ativos no painel e de worker de fila para listeners; mail não entra no breach (decisão BA).
- **Reviewer (ressalvas já tratadas no código):** `created_by` no route sem Auth; eager load para evitar N+1 em filas/tabs.

Nenhum bloqueante conhecido para uso interno via painel Filament.

---

## Referências

| Documento | Caminho |
|-----------|---------|
| Blueprint (plano de implementação) | [`.ai/blueprints/fase-4-workflow-alcadas.md`](../blueprints/fase-4-workflow-alcadas.md) |
| Arquitetura e decisões (BA §20, DBA §25) | [`.ai/arquitetura/fase-4-workflow-alcadas.md`](../arquitetura/fase-4-workflow-alcadas.md) |
| DRF v1 (RF019–RF024) | [`.ai/requisitos/drf-financeiro-v1.md`](../requisitos/drf-financeiro-v1.md) |
| Entrega Fase 3 | [`.ai/entregas/fase-3-solicitacao-ocr.md`](fase-3-solicitacao-ocr.md) |

---

## Histórico

| Versão | Data | Descrição |
|--------|------|-----------|
| 1.0 | 2026-08-12 | Documento de entrega da Fase 4 |
