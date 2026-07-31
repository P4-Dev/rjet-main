# Arquitetura: Fase 3 — Solicitação de pagamento (core) e OCR

> **Escopo:** RF012–RF018 (PaymentRequest, formulário condicional, PaymentRequestBankDetails, anexos morph S3, valor líquido, OCR local de boleto, listagem com escopo).
> **Fora de escopo:** Fases 4–8 (ApprovalRule/workflow, lote de solicitações, lote de anexos, CNAB, dashboard/relatórios). Antecipar apenas hooks (enum completo, events, `canTransitionTo`).
> **Stack:** Laravel 12 · Filament 5 · PHP 8.4 · Pest 4 · Livewire 4 · PostgreSQL · Redis (cache/filas) · S3 (produção).
> **Fonte:** [.ai/requisitos/drf-financeiro-v1.md](../requisitos/drf-financeiro-v1.md) · alinhamento [.ai/arquitetura/fase-1-base.md](fase-1-base.md) · [.ai/arquitetura/fase-2-cadastros-gerenciais.md](fase-2-cadastros-gerenciais.md)
> **Autor:** architect (subagent · Cursor Grok 4.5 High)
> **Data:** 2026-07-20 · **Atualizado:** 2026-07-29 (decisões BA §20 + modalidades depósito + **revisão DBA §25**)
> **Status:** Pendências BA **fechadas**; revisão **`dba` concluída em 2026-07-29** (Aprovado com ressalvas) — pronto para `/blueprint` ou `implementer`

---

## 1. Contexto

As Fases 1–2 entregaram hierarquia `Company → Branch → BranchBankAccount`, usuários multi-filial, Policies/`scopeVisibleTo`, cadastros gerenciais (`CostCenter`, `Appropriation`, `Supplier` + override `paymentMethodFor(Company)`, `Bank`) e morph `addresses`/`contacts`.

A **Fase 3** introduz o **núcleo do domínio financeiro**: a solicitação de pagamento como objeto central, com:

| Capacidade | Papel |
|------------|--------|
| **PaymentRequest** | Cabeçalho da solicitação + ciclo `Requested → Launched → Settled` |
| **Histórico de status** | Trilha auditável dedicada (`payment_request_status_history`) |
| **PaymentRequestBankDetails** | Dados de liquidação (boleto / Pix / transferência) |
| **Attachment (morph)** | PDF/imagens em disco private (S3 em produção) |
| **OCR local (PDF)** | Extrai linha digitável, valor e vencimento ao anexar boleto em PDF |
| **Escopo RF018** | Cliente = filiais vinculadas; Operador/Adm = todas empresas |

**Estado real do código (2026-07-20):** Models/Policies/Resources das Fases 1–2 existem. `Supplier::paymentMethodFor()` já implementado. Morph map só `'supplier'`. **Não há** stubs de `PaymentRequest`, `Attachment`, `HasAttachments`, OCR, `DepositType`, `PixKeyType` ou `PaymentRequestStatus`.

Painel único `admin`. Navigation group já previsto: `__('navigation.groups.operations')` (“Operações”).

---

## 2. Requisitos

### 2.1 Funcionais (Fase 3)

| RF | Descrição | Entidade/Artefato principal |
|----|-----------|------------------------------|
| RF012 | CRUD PaymentRequest + status + histórico + filial auto/multi | `PaymentRequest`, `PaymentRequestStatusHistory` |
| RF013 | Form 3 blocos; condicional `PaymentMethod` / `DepositType` / `PixKeyType`; sugestão RF010 | Form Filament + enums |
| RF014 | Dados bancários dedicados da solicitação | `PaymentRequestBankDetails` |
| RF015 | Anexos morph S3 private; PDF + imagens; sem XML NF-e | `Attachment` + `HasAttachments` |
| RF016 | Valor líquido = bruto − descontos (`decimal(10,2)`) | Service + live form |
| RF017 | OCR local ao anexar boleto; falha não bloqueia; revisão manual | `BoletoOcr` Integration + Action (PDF-only) |
| RF018 | Listagem + filtros + escopo por perfil | `scopeVisibleTo` + Table filters |

### 2.2 Não-funcionais aplicáveis

- **RNF001:** UUID, `timestampsTz`/`softDeletesTz`, `created_by`/`updated_by` (exceto histórico — ver §10).
- **RNF002:** Monetários `decimal(10,2)` — nunca float.
- **RNF003:** status/tipo `string` + Enum PHP.
- **RNF004:** Anexos polimórficos.
- **RNF005:** `payment_request_status_history`.
- **RNF006:** Policies + escopo; URLs assinadas/private para anexos; sem mascaramento LGPD fornecedores.
- **RNF007:** Validação CPF/CNPJ onde aplicável (chave Pix CPF; `holder_document` em Transfer → CPF **ou** CNPJ via `ValidCpf`/`ValidCnpj`).
- **RNF010:** Events `PaymentRequestCreated`, `PaymentRequestStatusChanged` (DRF §6).
- **RNF011:** ~70 pagamentos/dia — sem otimização prematura.
- **RNF012:** S3 private em produção; disco local/compatível em dev.

### 2.3 API REST

> **Assunção (igual Fases 1–2): NÃO.** Painel Filament interno BPO; DRF não exige API mobile/terceiros na Fase 3. Sem Sanctum/Passport/Swagger. Reavaliar se surgir portal externo.

### 2.4 Fora de escopo (hooks apenas)

| Item | Fase | Hook na F3 |
|------|------|------------|
| ApprovalRule / alçadas / SLA | 4 | Enum status já tem Launched/Settled; **não** criar `ApprovalStatus` nem regras |
| Importação planilha / lote solicitações | 5 | Campos do PaymentRequest estáveis para mapeamento futuro |
| Lote de anexos + nomenclatura / conciliação multi-arquivo | 6 | Morph `attachments` reutilizável; sem classificação/renomeação; F3 só exige ≥1 anexo em Boleto |
| CNAB / baixa lote | 7 | `PaymentRequestBankDetails` (Pix chave/QR + Transferência conta) como fonte de liquidação |
| Dashboard / Excel | 8 | Events de status para invalidação futura de cache |

---

## 3. Decisões de Design

### 3.1 Normalização e entidades compartilhadas

**Matriz morph vs dedicado (`database.md`):**

| Candidato | Decisão Fase 3 | Justificativa |
|-----------|----------------|---------------|
| **Anexos (`attachments`)** | **Morph + `HasAttachments`** | RNF004; estrutura idêntica; auxiliar; Fase 6 reusa o mesmo morph; `database.md` lista anexos como candidato morph clássico |
| **Histórico de status** | **Dedicado** `payment_request_status_history` | RNF005; core de auditoria; FK crítica; estrutura específica de transição; **não** morph genérico de “activities” |
| **Dados bancários da solicitação** | **Dedicado** `payment_request_bank_details` | RF014 explícito; core de liquidação/CNAB; FK 1:1; estrutura diverge de `branch_bank_accounts` (boleto/Pix vs conta da filial) |
| **PaymentRequest** | **Dedicado** | Entidade core do domínio |
| **Flag obrigatoriedade de apropriação** | Coluna em **`companies`** | Configuração por empresa (multi-cliente BPO; alinhado a RN003.2 / F2) — ver §3.15 |
| **Endereços/contatos** | **Não alterar** | Já na F2; solicitação não exige endereço próprio |
| **Notas morph** | **Não criar** | `notes` text no cabeçalho (BA confirmou) |

**Desnormalizações intencionais:**

1. **`payment_requests.net_amount`** — persistido (não só calculado na UI). Motivo: relatórios/CNAB/filtros leem o valor liquidado sem recalcular; validação no Service garante `net = gross − discount`. Comentário obrigatório na migration.
2. **`payment_requests.has_attachments`** — boolean cache (`database.md`). Sync via Observer ao criar/soft-delete attachment. Evita `exists` em listagens. Volume baixo, mas alinhado à convenção do projeto.
3. **Snapshot em bank details (boleto):** `digitable_line` / valor/vencimento preenchidos por OCR são **dados da liquidação daquela solicitação**, não FK para boleto externo — correto em 3NF como atributos do detalhe.

> **Não** desnormalizar `company_id` em `payment_requests`: empresa vem de `branch.company` (filtros via `whereHas`). RNF011 não justifica cópia.

### 3.2 Decisão 1 — Filial automática / multi-filial (RN012.3)

| Cenário | Comportamento |
|---------|---------------|
| Cliente com **1** filial vinculada | `branch_id` pré-preenchido (hidden ou disabled) |
| Cliente com **N** filiais | Select obrigatório limitado a `user.branches` (respeitar `is_default` como default) |
| Operador/Adm | Select de qualquer filial ativa (searchable, label `company / branch`) |

Resolução no **Service** (não só no Form): se Cliente e `branch_id` omitido → usar filial `is_default` ou a única vinculada; se inválida/fora do escopo → `PaymentRequestException::branchNotAllowed()`.

### 3.3 Decisão 2 — Status, transições e edição (BA 2026-07-29)

Enum completo conforme DRF 5.3:

| De | Para | Quem na F3 |
|----|------|------------|
| *(create)* | `Requested` | C, O, A |
| `Requested` | `Launched` | **O, A** (transição operacional manual) |
| `Launched` | `Settled` | **O, A** |
| Qualquer | *(voltar)* | **Não** na F3 (sem regressão) |

- Cliente **não** altera status.
- Toda transição passa por `PaymentRequestService::transitionStatus()` → valida `canTransitionTo` → grava histórico → dispara `PaymentRequestStatusChanged`.
- **Fase 4** inserirá aprovações **antes** de Launched. Hook: `canTransitionTo` no enum — F4 só estreita regras.

**Matriz de edição de dados** (valores, bank details, anexos) — BA #3:

| Role | `Requested` | `Launched` | `Settled` |
|------|:-----------:|:----------:|:---------:|
| Cliente | ✅ (filial vinculada) | ❌ | ❌ |
| Operador | ✅ | ✅ | ❌ |
| Adm | ✅ | ✅ | ✅ |

Helper sugerido: `PaymentRequest::isEditableBy(User): bool` centraliza a matriz acima (Policy + Form `disabled`).

**Exclusão (soft delete)** — BA #2: **somente Adm**, em **qualquer status**. Cliente e Operador: `delete` negado. Restore/forceDelete: só Adm.

### 3.4 Decisão 3 — Formulário em três blocos (RF013)

**Assunção de nomenclatura dos blocos** (DRF não nomeia):

| Bloco | Conteúdo |
|-------|----------|
| **1. Identificação** | Filial, fornecedor, centro de custo, apropriação, observações |
| **2. Valores** | Valor bruto, descontos, valor líquido (readonly calculado), vencimento |
| **3. Pagamento e comprovantes** | Forma de pagamento + campos condicionais de liquidação + anexos |

**Lógica condicional:**

```
payment_method = Boleto
  → bank_details: digitable_line (obrig.), barcode (opc.)
  → ≥1 attachment OBRIGATÓRIO (BA #4); conciliação multi-arquivo = Fase 6

payment_method = Deposit
  → deposit_type = Pix | Transfer
     (TED/DOC/conta = mesmo case Transfer — NÃO existe DepositType::Ted)

  → Pix: exigir PELO MENOS UM caminho:
       (a) pix_key_type + pix_key  OU
       (b) pix_qr_code (text)
       Ambos preenchidos: PERMITIDO; CNAB F7 escolhe fonte depois.
       Sem DepositType::PixQr; sem OCR/leitura de QR na F3.

  → Transferência (TED/DOC/conta) — pacote obrigatório da modalidade:
       holder_document (CPF ou CNPJ; ValidCpf/ValidCnpj), bank_id, agency,
       account_number, account_digit, account_type (corrente|poupança)
       agency_digit: nullable no schema; required se o banco/uso exigir
       holder_name: opcional (recomendado)
```

**Sugestão RF010:** ao selecionar `supplier_id` (+ branch já definida), `live()` chama `supplier->paymentMethodFor($branch->company)` e sugere `payment_method` (usuário pode alterar).

### 3.5 Decisão 4 — Campos do cabeçalho (base DRF + BA)

| Campo | Base / Decisão |
|-------|----------------|
| `branch_id` | RN012.3 |
| `supplier_id` | RF018 filtro; RF013.4 / RF010 |
| `cost_center_id` | RF008; RF025 — **required** |
| `appropriation_id` | FK **sempre nullable no schema**; obrigatoriedade **dinâmica** via `Company.is_appropriation_required` (§3.15) |
| `payment_method` | RN013.1 |
| `status` | RF012 |
| `gross_amount`, `discount_amount`, `net_amount` | RF016 |
| `due_date` | RF017 OCR; RF018 filtro; RF025 |
| `notes` | **Confirmado BA #5** — `text` nullable |
| `has_attachments` | Convenção + listagem |

**Não inventar:** número externo/protocolo, prioridade, centro de custo múltiplo, moeda (BRL implícito), `requested_by` separado de `created_by`.

### 3.6 Decisão 5 — PaymentRequestBankDetails (1:1 dedicado)

- Tabela `payment_request_bank_details` com `payment_request_id` **unique** (parcial `WHERE deleted_at IS NULL`).
- Relação: `PaymentRequest::bankDetails(): HasOne`.
- Campos condicionais **nullable no schema**; obrigatoriedade na validação Application/Filament conforme `payment_method` + `deposit_type`.
- `deposit_type`, dados Pix/Transfer/boleto **vivem aqui** (liquidação), não no cabeçalho — RF014.
- `payment_method` permanece no cabeçalho (classificação + filtros).

#### Sem modalidade TED separada (BA 2026-07-29)

`DepositType` = **somente** `Pix` | `Transfer`.  
**TED não é case/enum** — é o fluxo de negócio da **Transferência** (TED/DOC/conta corrente ou poupança). Label i18n sugerido: “Transferência (TED/DOC/conta)”.

#### Transferência (`deposit_type = Transfer`) — pacote da modalidade

| Campo | Obrigatoriedade F3 | Nota |
|-------|--------------------|------|
| `holder_document` | **Required** | CPF **ou** CNPJ do favorecido; só dígitos. Validar: 11 dígitos → `ValidCpf`; 14 → `ValidCnpj` (reusa rules F2). Sem coluna `person_type` no bank_details — detectar por length; UX opcional: Select virtual `PersonType` no Form (padrão Supplier) |
| `bank_id` | **Required** | |
| `agency` | **Required** | |
| `agency_digit` | Condicional | Nullable no schema; required se usado pelo banco |
| `account_number` | **Required** | |
| `account_digit` | **Required** | |
| `account_type` | **Required** | `AccountType` Checking/Savings |
| `holder_name` | **Opcional** (recomendado) | Nullable no schema |

> Não chamar esses campos de “extras TED opcionais” — são o **pacote obrigatório** da modalidade Transfer (exceto `holder_name`).
> **R2 fechado:** `holder_document` aceita PF (CPF) e PJ (CNPJ).

#### Pix (`deposit_type = Pix`) — chave **ou** QR Code

| Campo | Uso |
|-------|-----|
| `pix_key_type` + `pix_key` | Caminho A — chave Pix |
| `pix_qr_code` (text, nullable) | Caminho B — payload/copia-e-cola do QR |

**Regra:** quando Pix, exigir **(pix_key_type + pix_key) OU pix_qr_code** (não-vazio).  
**Ambos preenchidos:** **permitir**; usuário revisa; CNAB F7 decide fonte.  
**Não** criar `DepositType::PixQr`. UX opcional: Toggle/Radio virtual `PixInputMode: Key | QrCode` no Form (não é coluna).  
**OCR/leitura de QR em imagem:** fora de escopo F3.

### 3.7 Decisão 6 — Anexos morph (RF015)

Criar agora (Fase 2 adiou):

- Model `Attachment`, trait `HasAttachments`, tabela `attachments`.
- `uuidMorphs('attachable')` + SoftDeletes + blameable + metadados de arquivo.
- Disco: `config('filesystems.default')` — produção `s3`, dev `local`/`private`.
- Visibility **private**; download via Action Filament autenticada / `temporaryUrl` (S3) ou stream controller.
- MIME: `application/pdf`, `image/jpeg`, `image/png`, `image/webp` (RN015.1).
- **XML rejeitado.**
- Tamanho máximo: **confirmado BA #8** — `rjet.attachments.max_kilobytes = 10240` (10 MB).
- Path: `attachments/{attachable_type}/{attachable_id}/{hash}` via `store()`.
- Morph map: adicionar `'payment_request' => PaymentRequest::class`.

**Boleto (BA #4):** quando `payment_method = Boleto`, validar **≥1 attachment** no create/update (Form + Service). Conciliação/classificação de múltiplos arquivos = **Fase 6** (hook: morph já existe).

**Tipo/classificação:** `type` string(20) nullable com enum `AttachmentType` mínimo (`Boleto`, `Other`) para disparar OCR em PDF de boleto; classificação rica na F6.

### 3.8 Decisão 7 — OCR local (RF017) — BA #6 PDF-only

| Aspecto | Decisão |
|---------|---------|
| Cloud OCR | **Proibido** (seção 1.2 / 2.4 DRF) |
| Stack F3 | **PDF text parser** + validação de linha digitável brasileira — **sem Tesseract** |
| Imagens | Upload permitido (RF015); OCR **não** extrai de imagem → warning + preenchimento manual |
| Momento | Após upload bem-sucedido (anexo tipo Boleto / payment_method=Boleto **e** MIME PDF) |
| Sync vs queue | **Síncrono na Action** (volume ~70/dia). Job futuro opcional se latência > ~5s |
| Falha | **Não** reverte upload; Notification warning; campos manuais |
| Sucesso | Preenche `digitable_line`, `gross_amount`/`due_date` (se vazios ou com confirmação) — usuário revisa (RN017.2) |
| Camada | `App\Integrations\Ocr\BoletoOcrClient` + `LocalBoletoOcrClient` (PDF-first); Action `ExtractBoletoDataAction` |

**Implementação candidata:** `smalot/pdfparser` (ou equivalente) para extrair texto; parser/validador PHP de linha digitável (módulo 10/11; 47/48 dígitos). Sem binário Tesseract no container. Feature flag `rjet.ocr.enabled` + `NullBoletoOcrClient` para testes.

### 3.9 Decisão 8 — Cálculo do valor líquido (RF016)

- Fórmula: `net_amount = gross_amount − discount_amount`.
- Domínio: `PaymentRequestService::calculateNetAmount(string $gross, string $discount): string` com **bcmath** (`bcsub` scale 2) — nunca float.
- UI: `gross_amount` / `discount_amount` com `->live(onBlur: true)` → `Set('net_amount', ...)`.
- Persistência: Service recalcula e sobrescreve `net_amount` no create/update (fonte da verdade no servidor).
- Validação: `discount_amount >= 0`, `gross_amount > 0`, `discount_amount <= gross_amount`.

### 3.10 Decisão 9 — Escopo de visibilidade (RF018 + padrão F1)

Reutilizar pattern Fase 1 — **Query Scope, não Global Scope**:

```
PaymentRequest::scopeVisibleTo(Builder, User):
  - Adm/Operador (seesAllBranches) → sem filtro
  - Cliente → whereIn('branch_id', user->branches()->pluck('id'))
    OU whereHas('branch.users', ...)
```

Resource: `getEloquentQuery()` aplica `visibleTo(auth()->user())`.
Policy `view`/`update`/`delete`: além do role, Cliente só se `branch` vinculada (defense in depth vs URL direta — critério RF018).

Filtros Table (dentro do escopo): filial, empresa (`whereHas branch.company`), status, período (`created_at`), vencimento (`due_date`), fornecedor.

Ordenação padrão: `created_at desc` (mais recentes primeiro).

### 3.11 Decisão 10 — Policies (matriz — BA #2/#3)

| Ability | Cliente | Operador | Adm |
|---------|:-------:|:--------:|:---:|
| viewAny | ✅ (escopo) | ✅ | ✅ |
| view | ✅ se filial vinculada | ✅ | ✅ |
| create | ✅ | ✅ | ✅ |
| update | ✅ se `Requested` + filial vinculada | ✅ se `Requested` ou `Launched` | ✅ **qualquer status** |
| delete (soft) | ❌ | ❌ | ✅ **qualquer status** |
| restore / forceDelete | ❌ | ❌ | ✅ |
| transitionStatus | ❌ | ✅ | ✅ |
| manageAttachments | igual update | igual update | ✅ |

`AttachmentPolicy`: autorizar via parent `PaymentRequest` (mesma matriz update/view).

Cadastros gerenciais: **inalterados** (Cliente 403). `CompanyResource`: Adm edita `is_appropriation_required` (§3.15).

### 3.12 Decisão 11 — Events (DRF §6)

| Evento | Quando | Listener F3 | Fila |
|--------|--------|-------------|------|
| `PaymentRequestCreated` | Após create no Service | Log estruturado opcional; stub para notificações F4+ | Sync / TBD |
| `PaymentRequestStatusChanged` | Após transition | Log; stub invalidação dashboard F8 | Sync |

- Namespace: `App\Events\PaymentRequest\` (agrupar_por_dominio).
- Classes `final`, payload = Model (+ `from`/`to` no StatusChanged).
- Dispatch **após** commit da transaction.
- Events de aprovação/lote/CNAB: **não** criar na F3.

### 3.13 Decisão 12 — Soft delete / integridade

| Entidade | SoftDeletes | Regra |
|----------|:-----------:|-------|
| PaymentRequest | ✅ | Soft-delete **só Adm**; cascade soft: bankDetails + attachments; histórico **mantém** linhas |
| PaymentRequestBankDetails | ✅ | Cascade com parent |
| Attachment | ✅ | Soft; **arquivo físico** só remove no `forceDeleted` (Observer) — morph **sem** FK DB |
| PaymentRequestStatusHistory | ❌ | Trilha de auditoria imutável (hard insert only; sem update/delete de negócio). Soft do parent **não** apaga history; forceDelete do parent → hard delete via `cascadeOnDelete` da FK |

**Guards (Service — nunca evento `deleting` no Model que quebre cascata de Company):**

- `SupplierService::delete`: **bloquear** se existir PaymentRequest não trashed (fecha pergunta F2 #5).
- `BranchService::delete`: **bloquear** se PaymentRequest não trashed (além de bank accounts) — exclusão direta; teardown Company **não** passa por este Service.
- `BankService::ensureDeletable`: **bloquear** se existir `payment_request_bank_details` **não trashed** com `bank_id` (além de `branch_bank_accounts` — padrão F2).
- `CostCenter` / `Appropriation`: bloquear soft-delete se referenciados por PaymentRequest ativo.
- `Company` teardown: **cascade** soft PaymentRequests (consistente F1) — ver ordem abaixo.

> **Nota DBA:** `cascadeOnDelete` nas FKs cobre apenas **hard/forceDelete**. Soft-delete **sempre** via Observer/Service em lote (`->delete()` na query). Morph `attachments` **não tem FK** — cascade soft/force **obrigatoriamente** Eloquent, senão arquivos órfãos no storage.

**`CompanyObserver` (estender cascade F1/F2) — ordem soft-delete (bulk via query):**

1. Soft-delete `PaymentRequest` de todas as branches da company (`whereIn branch_id` ou por branch) → dispara `PaymentRequestObserver` (bankDetails + attachments)
2. Soft-delete `supplier_company_payment_methods`
3. Soft-delete `appropriations`
4. Por cada branch: soft-delete `costCenters`, depois `bankAccounts`
5. Soft-delete `branches`

Restore simétrico com threshold (`restoring` / `deleted_at >= threshold`), incluindo PaymentRequests → bankDetails → attachments.

**`forceDeleted` (mesma ordem, `withTrashed()->forceDelete()` via Eloquent):**

1. ForceDelete PaymentRequests (Eloquent) → Observer forceDelete bankDetails + **attachments** (limpa storage) → history some via FK `cascadeOnDelete`
2. overrides → appropriations → costCenters + bankAccounts → branches

> **Crítico:** **nunca** depender só do `cascadeOnDelete` de `payment_requests.branch_id` no forceDelete de Branch/Company para limpar anexos — morph não cascateia no banco; Observer deve rodar **antes** do forceDelete das branches.

**`PaymentRequestObserver`:**

| Evento | Ação |
|--------|------|
| `deleted` (soft) | Soft-delete `bankDetails` + `attachments` em lote; **não** tocar history |
| `restored` | Restore bankDetails + attachments com threshold |
| `forceDeleted` | ForceDelete bankDetails + attachments (`withTrashed`); history já removido pela FK ao DELETE do parent — se forceDelete Eloquent do parent ocorrer primeiro, garantir attachments **antes** (`forceDeleting`) para limpar storage |

### 3.14 Decisão 13 — API / Jobs / Scheduling / Livewire custom

- API: não (§2.3).
- Jobs: nenhum obrigatório na MVP (OCR sync). Opcional futuro `ProcessBoletoOcrJob` em `App\Jobs\PaymentRequest\`.
- Scheduling: não.
- Livewire custom: **não** — Form Filament reativo basta. Se OCR async no futuro, island/polling.

### 3.15 Decisão 14 — Obrigatoriedade de apropriação por Company (BA #1)

**Problema:** alguns clientes querem que a RJET preencha a apropriação **depois** da abertura; outros exigem na criação.

**Locus escolhido: `companies.is_appropriation_required`** (boolean, default `false`).

| Alternativa | Veredito | Motivo |
|-------------|:--------:|--------|
| **Por Company (escolhida)** | ✅ | Multi-empresa BPO; alinhado a “configurações por empresa” (RN003.2 / F2 Appropriation por company) |
| Config global `.env` | ❌ | Não diferencia Altitude vs Glow |
| Por Branch | ❌ | Apropriação já é por empresa; duplicaria config |

**Comportamento:**

1. Migration alter em `companies`: `$table->boolean('is_appropriation_required')->default(false);` (+ cast boolean no Model). **Sem** `->index()` — flag de config lida via Company já carregada; baixa cardinalidade (performance.md: não indexar boolean 50/50 / filtro raro).
2. `CompanyResource` (Adm): Toggle no form/infolist/table.
3. Form PaymentRequest: `appropriation_id` → `required` se `Get branch → company.is_appropriation_required`; senão nullable. `live()` ao mudar branch.
4. `PaymentRequestService`: mesma regra server-side → `PaymentRequestException::appropriationRequired()` se bypass.
5. Schema: `payment_requests.appropriation_id` permanece **nullable** (FK); a obrigatoriedade é de aplicação, não de NOT NULL.

---

## 4. Models

Todos domínio (exceto History): `final`, `declare(strict_types=1)`, `HasFactory`, `HasUuid`, `SoftDeletes`, `HasBlameable`, `casts()` método. PK UUID. `timestampsTz` / `softDeletesTz`. FKs com `->index()` explícito.

### 4.1 `PaymentRequest`

- **Tabela:** `payment_requests`
- **Campos:**
  - `id` uuid PK
  - `branch_id` foreignUuid → `branches`, index, **`cascadeOnDelete`** (hard/forceDelete; soft via Observer/Service — padrão F1; **não** `restrictOnDelete`)
  - `supplier_id` foreignUuid → `suppliers`, index, `restrictOnDelete`
  - `cost_center_id` foreignUuid → `cost_centers`, index, `restrictOnDelete` — **required** na app
  - `appropriation_id` foreignUuid → `appropriations`, index, **nullable**, `nullOnDelete` — required dinâmico §3.15
  - `payment_method` string(20) index → `PaymentMethod`
  - `status` string(20) index default `requested` → `PaymentRequestStatus`
  - `gross_amount` decimal(10,2)
  - `discount_amount` decimal(10,2) default 0
  - `net_amount` decimal(10,2) — desnormalizado documentado
  - `due_date` date index
  - `notes` text nullable — **confirmado BA #5**
  - `has_attachments` boolean default false index
  - `created_by` / `updated_by` + timestampsTz + softDeletesTz
- **Casts:** enums; decimals como `decimal:2`; `due_date` → `date`; `has_attachments` → boolean
- **Traits:** `HasFactory, HasUuid, SoftDeletes, HasBlameable, HasAttachments`
- **Relacionamentos:** `branch()`, `supplier()`, `costCenter()`, `appropriation()`, `bankDetails(): HasOne`, `statusHistory(): HasMany`, `attachments()` via trait
- **Scopes:** `scopeVisibleTo(User)`, `scopeStatus(...)`, `scopeDueBetween(...)`, `scopeForBranch(...)`
- **Helpers:** `isEditableBy(User): bool`, `company(): ?Company` via branch, `requiresAppropriation(): bool`
- **Observer:** `PaymentRequestObserver` — cascade soft/force bankDetails + attachments (§3.13); history imutável no soft

### 4.2 `PaymentRequestBankDetails`

- **Tabela:** `payment_request_bank_details`
- **Campos:**
  - `id` uuid PK
  - `payment_request_id` foreignUuid → `payment_requests`, index, `cascadeOnDelete` — **sem** `->unique()` no Blueprint
  - `deposit_type` string(20) nullable index → `DepositType` (`pix` \| `transfer` only)
  - `pix_key_type` string(20) nullable → `PixKeyType`
  - `pix_key` string(100) nullable
  - `pix_qr_code` text nullable — copia-e-cola / payload QR (Pix caminho B); **sem índice** (text; sem filtro WHERE)
  - `digitable_line` string(54) nullable — linha digitável (até 48 dígitos + formatação)
  - `barcode` string(48) nullable
  - `bank_id` foreignUuid nullable → `banks`, index, `nullOnDelete`
  - `agency` string(10) nullable
  - `agency_digit` string(2) nullable
  - `account_number` string(20) nullable
  - `account_digit` string(2) nullable
  - `account_type` string(20) nullable → `AccountType`
  - `holder_name` string(150) nullable
  - `holder_document` string(20) nullable — **somente dígitos** (sem máscara), igual `Supplier.document` F2; na app Transfer = required (CPF 11 ou CNPJ 14)
  - autoria + timestampsTz + softDeletesTz
- **Índice único parcial:** `(payment_request_id) WHERE deleted_at IS NULL` via `DB::statement` + `supportsPartialIndexes()` (pgsql + sqlite) — **nunca** `->unique()` no Blueprint
- **Relacionamentos:** `paymentRequest()`, `bank()`
- **Validação app (Service/Form):** conforme §3.6 (Pix chave OR QR; Transfer pacote obrigatório)

### 4.3 `PaymentRequestStatusHistory`

- **Tabela:** `payment_request_status_history`
- **Campos:**
  - `id` uuid PK
  - `payment_request_id` foreignUuid → `payment_requests`, index, `cascadeOnDelete`
  - `from_status` string(20) nullable — null na criação
  - `to_status` string(20) index
  - `changed_by` foreignUuid → `users`, index, `nullOnDelete`
  - `notes` text nullable
  - `created_at` timestampTz — **sem** `updated_at`; **sem** SoftDeletes
- **Índice composto:** `['payment_request_id', 'created_at']` — timeline por solicitação (`database.md` §4)
- **Model:** sem SoftDeletes; sem HasBlameable (usa `changed_by`); pode usar HasUuid
- **Append-only** no Service — soft-delete do parent **mantém** linhas; forceDelete do parent remove via FK

### 4.4 `Attachment`

- **Tabela:** `attachments`
- **Campos:**
  - `id` uuid PK
  - `uuidMorphs('attachable')` — `attachable_type`, `attachable_id` + índice
  - `type` string(20) nullable index → `AttachmentType` (opcional F3)
  - `disk` string(30)
  - `path` string
  - `original_name` string
  - `mime_type` string(100)
  - `size` unsignedInteger — bytes
  - `sort_order` unsignedInteger default 0 index — convenção `database.md`
  - autoria + timestampsTz + softDeletesTz
- **Índice:** `['attachable_type', 'attachable_id', 'type']` (além do índice de `uuidMorphs` — padrão addresses/contacts F2)
- **Traits:** HasFactory, HasUuid, SoftDeletes, HasBlameable
- **Observer:** `forceDeleted` → `Storage::disk($disk)->delete($path)`; soft delete **não** apaga arquivo; `saved`/`deleted` sync `has_attachments` no parent
- **Trait `HasAttachments`:** `attachments(): MorphMany`, sync `has_attachments` no parent se método existir

### 4.5 Alterações em Models existentes

| Model | Alteração |
|-------|-----------|
| **`Company`** | `is_appropriation_required` boolean default false (**sem** index); cast; Toggle no Resource |
| `Branch` | `paymentRequests(): HasMany`; `BranchService` guarda PaymentRequest ativo |
| `Supplier` | `paymentRequests(): HasMany`; guard delete |
| `CostCenter` | `paymentRequests(): HasMany`; guard delete |
| `Appropriation` | `paymentRequests(): HasMany`; guard delete |
| `Bank` / `BankService` | Guard: bloquear se `payment_request_bank_details` não trashed referencia `bank_id` |
| `CompanyObserver` | Cascade soft/force PaymentRequests **antes** de appropriations/branches (§3.13) |
| `PaymentRequestObserver` | Cascade bankDetails + attachments; history intocado no soft |
| `AttachmentObserver` | forceDeleted limpa storage; sync `has_attachments` |
| `AppServiceProvider` morph map | `+ 'payment_request' => PaymentRequest::class` |

---

## 5. Relacionamentos

```
Company ──hasMany──> Branch
Company.is_appropriation_required ──(config)──> obrigatoriedade de PaymentRequest.appropriation_id

Branch  ──hasMany──> PaymentRequest
Branch  ──hasMany──> CostCenter

PaymentRequest ──belongsTo──> Branch
PaymentRequest ──belongsTo──> Supplier
PaymentRequest ──belongsTo──> CostCenter
PaymentRequest ──belongsTo──> Appropriation (nullable; required dinâmico)
PaymentRequest ──hasOne─────> PaymentRequestBankDetails
PaymentRequest ──hasMany────> PaymentRequestStatusHistory
PaymentRequest ──morphMany──> Attachment (HasAttachments)

PaymentRequestBankDetails ──belongsTo──> PaymentRequest
PaymentRequestBankDetails ──belongsTo──> Bank (nullable)

Supplier ──hasMany──> PaymentRequest
CostCenter ──hasMany──> PaymentRequest

[domínio] ──created_by/updated_by──> User
History.changed_by ──belongsTo──> User
```

---

## 6. Enums

### 6.1 `PaymentRequestStatus` (novo)

| Case | value | Label | Color | Icon |
|------|-------|-------|-------|------|
| `Requested` | `requested` | Solicitada | `warning` | `heroicon-o-clock` |
| `Launched` | `launched` | Lançada | `info` | `heroicon-o-paper-airplane` |
| `Settled` | `settled` | Liquidada | `success` | `heroicon-o-check-circle` |

- `HasLabel`, `HasColor`, `HasIcon`
- `canTransitionTo()` / `allowedTransitions()` conforme §3.3

### 6.2 `DepositType` (novo — adiado da F2)

| Case | value | Label (pt_BR sugerido) |
|------|-------|------------------------|
| `Pix` | `pix` | Pix |
| `Transfer` | `transfer` | Transferência (TED/DOC/conta) |

- **Somente estes 2 cases.** Não existe `Ted`, `Doc` nem `PixQr`.
- TED/DOC = descrição de negócio do case `Transfer`.

### 6.3 `PixKeyType` (novo — adiado da F2)

| Case | value | Label |
|------|-------|-------|
| `Random` | `random` | Chave aleatória |
| `Cpf` | `cpf` | CPF |
| `Phone` | `phone` | Telefone |
| `Email` | `email` | E-mail |

Validação de `pix_key` por tipo (Service/Rule): CPF → `ValidCpf`; Email → email; Phone → dígitos; Random → UUID/EVP format.

### 6.4 `AttachmentType` (recomendado mínimo)

| Case | value | Uso |
|------|-------|-----|
| `Boleto` | `boleto` | Dispara OCR |
| `Other` | `other` | Demais comprovantes |

### 6.5 Reutilizar

`PaymentMethod`, `AccountType`, `UserRole` — existentes.

Traduções: `lang/pt_BR/enums.php` + `en`.

---

## 7. Exceções de Domínio

Base: `BusinessException` (Fase 1).

- **`PaymentRequestException`:**
  - `branchNotAllowed()`
  - `invalidStatusTransition(from, to)`
  - `cannotEditInStatus(status)` — Cliente/Operador fora da matriz §3.3
  - `appropriationRequired()` — company exige e campo vazio
  - `boletoAttachmentRequired()` — Boleto sem ≥1 anexo
  - `invalidNetAmount()` / `discountExceedsGross()`
  - `incompleteBankDetails(paymentMethod)`
  - `pixDetailsIncomplete()` — Pix sem chave completa e sem `pix_qr_code`
  - `transferDetailsIncomplete()` — Transfer sem pacote obrigatório (§3.6)
  - `invalidHolderDocument()` — `holder_document` não é CPF/CNPJ válido (length ≠ 11/14 ou rule falhou)
- **`AttachmentException`:**
  - `invalidMimeType()`
  - `fileTooLarge()`
- **`SupplierException`:** estender `cannotDeleteWithPaymentRequests()`
- **`BranchException`:** estender `cannotDeleteWithPaymentRequests()`
- **`BankException`:** estender guard (ou reusar `cannotDeleteWithAccounts`) se `payment_request_bank_details` não trashed referencia o banco
- **`CostCenterException` / `AppropriationException`:** `cannotDeleteWithPaymentRequests()` (ou equivalente)

> Delete: Policy nega C/O — Adm pode soft-delete em qualquer status.

Filament: `try/catch` + `Notification::danger($e->getUserMessage())`.

---

## 8. Camadas de Aplicação

### 8.1 DTOs (flat — `agrupar_por_dominio.dtos: false`)

| DTO | Uso |
|-----|-----|
| `PaymentRequestData` | create/update tipado (incl. bank details nested ou DTO filho) |
| `PaymentRequestBankDetailsData` | liquidação |
| `BoletoOcrResult` | `digitableLine`, `amount`, `dueDate`, `wasSuccessful`, `message` |

### 8.2 Services (flat)

| Service | Responsabilidade |
|---------|------------------|
| `PaymentRequestService` | create/update; net; branch RN012.3; appropriation dinâmica; boleto ≥1 anexo; bank details; transitionStatus; delete (só se Policy Adm) |
| `AttachmentService` | store (validação MIME/size 10 MB), soft/force delete, sync `has_attachments` |
| Estender `SupplierService` / `BranchService` | guards §3.13 |

### 8.3 Actions (`App\Actions\PaymentRequest\`)

| Action | Uso |
|--------|-----|
| `ExtractBoletoDataAction` | Orquestra Integration OCR → retorna `BoletoOcrResult` |
| `ResolveSupplierPaymentMethodAction` | Wrapper Filament para `supplier->paymentMethodFor(company)` |
| `ApplyOcrResultToFormAction` | (opcional) mapeia resultado → Set() dos campos |

### 8.4 Integrations (`App\Integrations\Ocr\`)

| Classe | Papel |
|--------|-------|
| `BoletoOcrClient` (interface) | `extract(string $disk, string $path, string $mime): BoletoOcrResult` |
| `LocalBoletoOcrClient` | **PDF text only** + parser linha digitável; se não-PDF → `wasSuccessful=false` + mensagem amigável |
| `NullBoletoOcrClient` | Testes / feature flag off |

### 8.5 Events / Listeners

- `App\Events\PaymentRequest\PaymentRequestCreated`
- `App\Events\PaymentRequest\PaymentRequestStatusChanged` (props: request, from, to, user)
- Listeners: opcional `LogPaymentRequestActivity` sync; placeholders documentados para F4/F8

### 8.6 Observers

- `AttachmentObserver` — `forceDeleted` limpa storage; `saved`/`deleted`/`restored` atualiza `has_attachments`
- `PaymentRequestObserver` — cascade soft/force bankDetails + attachments (§3.13); history intocado no soft
- Estender `CompanyObserver` — cascade PaymentRequests **antes** de appropriations/branches (§3.13)
- Estender `BankService::ensureDeletable` — também `payment_request_bank_details` não trashed
- **Não** colocar guards de transição no Model `updating`

---

## 9. Performance

- **Índices:** FKs (explícitos); `status`; `due_date`; `payment_method`; `has_attachments`; morph; unique parcial bank_details; history `(payment_request_id, created_at)`.
- **Não criar:** índice em `is_appropriation_required`; índice em `pix_qr_code` (text); composto extra `(status, created_at)` no volume RNF011 (~70/dia) — FKs + `status` + `due_date` bastam.
- **Eager load Table:** `with(['branch.company', 'supplier', 'costCenter'])`; `withCount('attachments')` opcional (ou confiar em `has_attachments`).
- **Cache:** não (RNF011). Event StatusChanged documenta hook F8.
- `preventLazyLoading` já ativo.

---

## 10. Soft Deletes & Data Lifecycle

- Conforme §3.13 (ordem CompanyObserver + PaymentRequestObserver + morph storage).
- History: append-only, sem SoftDeletes; soft do parent mantém linhas; force do parent remove via FK.
- Unique parciais: `supportsPartialIndexes()` — padrão F1/F2; **nunca** `->unique()` no Blueprint da coluna `payment_request_id` em bank_details.
- Filament: `--soft-deletes`, TrashedFilter (Adm), `getRecordRouteBindingEloquentQuery()`.
- Pruning: **adiado** na F3 (retenção financeira / compliance — avaliar ≥365 dias em fase futura). Command opcional `attachments:clean-orphans` (volume baixo).

---

## 11. File Storage

| Item | Valor |
|------|-------|
| Disco produção | `s3` (private) |
| Disco dev | `local` (private root) via `FILESYSTEM_DISK` |
| MIME | pdf, jpeg, jpg, png, webp |
| max size | **`rjet.attachments.max_kilobytes = 10240`** (confirmado BA #8) |
| Nome | hash (`store()`), preservar `original_name` na coluna |
| Download | Policy + `temporaryUrl` (S3) ou stream; nunca URL pública permanente |
| Scan vírus | Fora de escopo F3 (usuários internos autenticados) |

---

## 12. Filament Resources (Blueprint — OBRIGATÓRIO)

**Localização:** `App\Filament\Resources\{Models}\` (igual F1/F2).

```bash
php artisan make:filament-resource PaymentRequest --generate --soft-deletes --view --panel=admin --no-interaction
```

Navigation: Group `__('navigation.groups.operations')`, Sort: 1.
Attachment **sem** Resource standalone — RelationManager + FileUpload no form.

---

### Resource: `PaymentRequestResource`

```
Command: php artisan make:filament-resource PaymentRequest --generate --soft-deletes --view --panel=admin --no-interaction
Location: App\Filament\Resources\PaymentRequests\PaymentRequestResource
Structure (pasta PLURAL: PaymentRequests/):
  - PaymentRequestResource.php (final, LIMPO — só delegates)
  - Schemas/PaymentRequestForm.php (final)
  - Schemas/PaymentRequestInfolist.php (final) — SEMPRE
  - Tables/PaymentRequestsTable.php (final)
  - Pages/ CreatePaymentRequest, EditPaymentRequest, ListPaymentRequests, ViewPaymentRequest
  - RelationManagers/
      AttachmentsRelationManager.php
      StatusHistoriesRelationManager.php (readonly)
  - Actions/
      TransitionStatusAction.php
      ExtractBoletoOcrAction.php (header/form)
SoftDeletes: getRecordRouteBindingEloquentQuery()
getEloquentQuery(): parent + visibleTo(auth user)
Icon: Heroicon::OutlinedBanknotes
Navigation:
  Group: __('navigation.groups.operations')
  Sort: 1
Policy: PaymentRequestPolicy (§3.11)

Form (Imports: Get, Set):
  Section block_identification:  # Bloco 1
    Field branch_id
      Component: Filament\Forms\Components\Select
      Validation: required; exists; Cliente → in user branches
      Config: relationship/searchable/preload; live();
            default: user defaultBranch; visible/disabled conforme §3.2
    Field supplier_id
      Component: Filament\Forms\Components\Select
      Validation: required
      Config: relationship active suppliers; searchable; live();
            afterStateUpdated: ResolveSupplierPaymentMethodAction → set payment_method
    Field cost_center_id
      Component: Filament\Forms\Components\Select
      Validation: required
      Config: options scoped by branch_id (Get); searchable
    Field appropriation_id
      Component: Filament\Forms\Components\Select
      Validation: required IF branch.company.is_appropriation_required; else nullable
      Config: options scoped by branch.company_id; live com branch_id
    Field notes
      Component: Filament\Forms\Components\Textarea
      Validation: nullable max 5000

  Section block_amounts:  # Bloco 2
    Field gross_amount
      Component: Filament\Forms\Components\TextInput
      Validation: required, numeric, min 0.01
      Config: numeric, live(onBlur), prefix R$
            afterStateUpdated: Set net via calculateNetAmount
    Field discount_amount
      Component: Filament\Forms\Components\TextInput
      Validation: required, numeric, min 0, lte gross
      Config: default 0, live(onBlur), Set net
    Field net_amount
      Component: Filament\Forms\Components\TextInput
      Validation: required
      Config: readOnly/dehydrated(true), prefix R$
    Field due_date
      Component: Filament\Forms\Components\DatePicker
      Validation: required, date

  Section block_payment:  # Bloco 3
    Field payment_method
      Component: Filament\Forms\Components\Select
      Validation: required
      Config: options(PaymentMethod::class), live(), native(false)
    # Campos de PaymentRequestBankDetails (relationship ou dehydrate no Service):
    Field deposit_type
      Component: Filament\Forms\Components\Select
      Validation: required_if payment_method=deposit
      Config: visible(Get payment_method === Deposit), options(DepositType::class), live();
            labels: Pix | Transferência (TED/DOC/conta)
    # UX opcional (virtual, não coluna): Radio pix_input_mode Key|QrCode
    Field pix_key_type / pix_key
      visible: Deposit && Pix (e modo Key se usar Radio virtual)
      Validation: required_without pix_qr_code (junto com type); rules por PixKeyType
    Field pix_qr_code
      Component: Filament\Forms\Components\Textarea
      visible: Deposit && Pix (e modo Qr se usar Radio virtual)
      Validation: required_without:pix_key; nullable text
      Config: hint “Cole o código Pix / QR”; sem OCR de imagem na F3
    Field digitable_line / barcode
      visible: Boleto; digitable_line required_if Boleto
    Field holder_document
      Component: Filament\Forms\Components\TextInput
      visible: Deposit && Transfer
      Validation: required; strip non-digits; rule:
        strlen===11 → ValidCpf; strlen===14 → ValidCnpj; else invalid
      Config: (opcional) Select PersonType virtual live → escolhe rule como SupplierForm;
              sem persistir person_type em bank_details
    Field bank_id, agency, account_number, account_digit, account_type
      visible: Deposit && Transfer; REQUIRED
    Field agency_digit
      visible: Transfer; nullable / required conforme uso
    Field holder_name
      visible: Transfer; OPCIONAL (recomendado)
    Field attachments (create) OU RelationManager (edit)
      Component: Filament\Forms\Components\FileUpload
      Validation: required_if payment_method=Boleto (min 1)
      Config: multiple, disk(default), directory attachments/..., visibility private,
              acceptedFileTypes([pdf, jpeg, png, webp]), maxSize(10240),
              afterStateUpdated: se Boleto+PDF → ExtractBoletoDataAction;
              se imagem → Notification warning (OCR não aplica)

Infolist:
  Entries: branch (company+name), supplier, costCenter, appropriation, payment_method(badge),
           status(badge), gross/discount/net, due_date, notes, bank details Repeatable/Fieldset,
           attachments list, statusHistory RepeatableEntry, audit (created_by, timestamps)

Table:
  Column branch.company.name → TextColumn | toggleable, sortable via join/relationship
  Column branch.name → TextColumn | searchable, sortable
  Column supplier.name → TextColumn | searchable
  Column status → TextColumn->badge()
  Column payment_method → TextColumn->badge()
  Column net_amount → TextColumn | money('BRL'), sortable
  Column due_date → TextColumn | date, sortable
  Column created_at → TextColumn | dateTime, sortable, default sort desc
  Filter branch_id → SelectFilter
  Filter company → SelectFilter whereHas branch.company
  Filter status → SelectFilter options(PaymentRequestStatus)
  Filter supplier_id → SelectFilter searchable
  Filter due_date → Filter date range
  Filter created_at → Filter date range
  Filter TrashedFilter (Adm)
  modifyQueryUsing: ->with(['branch.company','supplier','costCenter'])->visibleTo(user)
RecordActions: [ActionGroup → View, Edit, TransitionStatusAction, Delete (visible: só Adm)]
ToolbarActions: [BulkActionGroup → DeleteBulk (Adm), RestoreBulk, ForceDeleteBulk]
HeaderActions (List): CreateAction
```

**Atualização `CompanyResource` (Fase 1):** Toggle `is_appropriation_required` (default false; label i18n “Exigir apropriação na solicitação”).

**RelationManagers:**

- `AttachmentsRelationManager` — upload/download/delete; OCR action em anexo tipo Boleto
- `StatusHistoriesRelationManager` — somente leitura (sem create)

**Widgets:** nenhum na F3.

---

## 13. Fluxos

### 13.1 Fluxo principal — Criar solicitação

1. Usuário autenticado abre Create PaymentRequest.
2. Sistema resolve/pré-seleciona filial (RN012.3).
3. Usuário preenche Bloco 1; ao escolher fornecedor, sistema sugere `payment_method` via `paymentMethodFor`; apropriação required se company flag.
4. Usuário informa valores; UI atualiza líquido; Service valida na gravação.
5. Usuário escolhe método; Form exibe campos condicionais (Bloco 3); Boleto exige ≥1 anexo.
6. Usuário anexa PDF/imagem; se boleto **PDF**, OCR tenta preencher; se imagem → warning (OCR não aplica); falha OCR → warning.
7. Service em transaction: cria PaymentRequest (`Requested`), bank details, history (null→Requested), attachments; dispatch `PaymentRequestCreated`.
8. Redirect View/Edit.

### 13.2 Fluxo — Transição de status (Operador/Adm)

1. Action seleciona próximo status permitido.
2. Service valida `canTransitionTo` + Policy.
3. Atualiza status; insere history; dispatch `PaymentRequestStatusChanged`.

### 13.3 Fluxos alternativos

- Cliente tenta ver solicitação de outra filial → Policy 403 / query vazia.
- Cliente/Operador tenta delete → 403.
- Operador edita Settled → `cannotEditInStatus`.
- Company exige apropriação e campo vazio → `appropriationRequired`.
- Boleto sem anexo → `boletoAttachmentRequired`.
- OCR falha / imagem → anexo permanece; campos manuais.
- MIME inválido / XML → validação rejeita upload.
- Soft-delete Supplier com requests → `SupplierException`.
- Discount > gross → validação form + Service.

---

## 14. Integrações

- **OCR PDF-only** (§3.8) — única integração nova; **sem Tesseract**.
- **S3** — storage; credenciais via `.env`.
- Sem APIs bancárias na F3.

**Dependências:** parser PDF (`smalot/pdfparser` ou equivalente no spike); feature flag `rjet.ocr.enabled` (Null client quando off). Sem binário Tesseract no container.

---

## 15. Segurança

- Policies §3.11 + scope §3.10 (RF018).
- Anexos private; authorize download.
- Sem exposição de paths crus ao Cliente.
- Validação MIME real (`mimes` + `mimetypes`).
- Blameable + history `changed_by`.
- Sem API pública.
- Pix/CPF: validar formato; sem mascaramento LGPD (R12) mas acesso restrito por Policy.

---

## 16. Factories & Seeders

**Factories:**

- `PaymentRequestFactory` — states: `requested()`, `launched()`, `settled()`, `boleto()`, `depositPix()`, `depositTransfer()`, `forBranch(Branch)`, `withBoletoAttachment()`, `forCompanyRequiringAppropriation()`
- `PaymentRequestBankDetailsFactory` — states por método
- `PaymentRequestStatusHistoryFactory`
- `AttachmentFactory` — com `Storage::fake`; state `boleto()`
- `CompanyFactory` — state `requiresAppropriation()`

**Seeders (idempotentes, após F1/F2):**

- `PaymentRequestSeeder` — poucas requests demo; 1 company com flag true, 1 false
- Não seedar OCR real

---

## 17. i18n

Arquivos: `payment_requests.php`, `payment_request_bank_details.php`, `attachments.php`, `payment_request_status_history.php`; enums; `errors.php`; navigation `operations` já existe.

---

## 18. Schema consolidado (blueprint para DBA — **versão aprovada**)

> Unique parciais: envolver cada `DB::statement` em `if ($this->supportsPartialIndexes())` (pgsql + sqlite), com `DROP INDEX IF EXISTS` no `down()` — igual Fase 1/F2. Colunas **sem** `->unique()` no Blueprint.
> Correções DBA 2026-07-29 incorporadas (§25).

```php
// ALTER companies (Fase 3)
Schema::table('companies', function (Blueprint $table) {
    // Sem index: flag de config lida via Company; não filtro de listagem
    $table->boolean('is_appropriation_required')->default(false);
});

// payment_requests
Schema::create('payment_requests', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('branch_id')->index()->constrained('branches')->cascadeOnDelete(); // hard/force; soft via Observer
    $table->foreignUuid('supplier_id')->index()->constrained('suppliers')->restrictOnDelete();
    $table->foreignUuid('cost_center_id')->index()->constrained('cost_centers')->restrictOnDelete();
    $table->foreignUuid('appropriation_id')->nullable()->index()->constrained('appropriations')->nullOnDelete();
    $table->string('payment_method', 20)->index();
    $table->string('status', 20)->default('requested')->index();
    $table->decimal('gross_amount', 10, 2);
    $table->decimal('discount_amount', 10, 2)->default(0);
    // Desnormalizado: líquido persistido para listagem/CNAB; fonte de verdade recalculada no Service
    $table->decimal('net_amount', 10, 2);
    $table->date('due_date')->index();
    $table->text('notes')->nullable();
    // Desnormalizado: cache de existência de anexos (Observer)
    $table->boolean('has_attachments')->default(false)->index();
    $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->softDeletesTz();
});

// payment_request_bank_details
Schema::create('payment_request_bank_details', function (Blueprint $table) {
    $table->uuid('id')->primary();
    // NÃO ->unique() — unique parcial abaixo
    $table->foreignUuid('payment_request_id')->index()->constrained('payment_requests')->cascadeOnDelete();
    $table->string('deposit_type', 20)->nullable()->index();
    $table->string('pix_key_type', 20)->nullable();
    $table->string('pix_key', 100)->nullable();
    // Caminho B Pix (copia-e-cola / payload QR); sem índice (text)
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
    $table->string('holder_document', 20)->nullable(); // dígitos only (CPF 11 / CNPJ 14)
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

// payment_request_status_history — SEM softDeletes / updated_at
Schema::create('payment_request_status_history', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('payment_request_id')->index()->constrained('payment_requests')->cascadeOnDelete();
    $table->string('from_status', 20)->nullable();
    $table->string('to_status', 20)->index();
    $table->foreignUuid('changed_by')->nullable()->index()->constrained('users')->nullOnDelete();
    $table->text('notes')->nullable();
    $table->timestampTz('created_at')->useCurrent();
    $table->index(['payment_request_id', 'created_at']); // timeline
});

// attachments
Schema::create('attachments', function (Blueprint $table) {
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

### Ordem de migrations

1. `add_is_appropriation_required_to_companies_table`
2. `create_payment_requests_table`
3. `create_payment_request_bank_details_table` (+ unique parcial com `supportsPartialIndexes()`)
4. `create_payment_request_status_history_table`
5. `create_attachments_table`
6. (código) config `rjet.php` (`attachments.max_kilobytes=10240`, `ocr.enabled`) + morph map + Observers

---

## 19. Assunções documentadas

| # | Assunção |
|---|----------|
| A1 | Sem API REST na Fase 3 |
| A2 | Três blocos: Identificação / Valores / Pagamento+comprovantes |
| A3 | `appropriation_id` nullable no DB; required dinâmico via `Company.is_appropriation_required` (default false) |
| A4 | `cost_center_id` always required |
| A5 | Transições Requested→Launched→Settled manuais O/A (sem ApprovalRule) |
| A6 | Edição: C=Requested; O=Requested+Launched; A=qualquer status |
| A7 | Soft-delete PaymentRequest: **somente Adm**, qualquer status |
| A8 | Boleto ⇒ ≥1 attachment obrigatório |
| A9 | `notes` text nullable confirmado |
| A10 | OCR = PDF parser only; sem Tesseract; imagens → manual |
| A11 | Max anexo 10 MB confirmado |
| A12 | `DepositType` = Pix \| Transfer only; TED = Transfer (sem enum Ted) |
| A13 | History sem SoftDeletes; sem `company_id` no PaymentRequest; `created_by` = solicitante |
| A14 | Guards Supplier/Branch/CostCenter/Appropriation/Bank com PaymentRequest (ou bank_details) ativo |
| A15 | AttachmentType mínimo (Boleto/Other) |
| A16 | Pix: (key_type+key) OR pix_qr_code; ambos OK; Transfer: holder_document(CPF\|CNPJ)+bank+agency+account+digit+account_type required; holder_name opcional |
| A17 | Unique parciais com guard `supportsPartialIndexes()` (pgsql + sqlite); morph attachments cascade via Eloquent |
| A18 | `is_appropriation_required` sem índice; `pix_qr_code` text sem índice; history índice `(payment_request_id, created_at)` |

---

## 20. Decisões BA fechadas (2026-07-29)

| # | Tema | Decisão oficial |
|---|------|-----------------|
| 1 | Apropriação | Flag **`companies.is_appropriation_required`** (default `false`); Form+Service respeitam; migration alter na F3 |
| 2 | Exclusão | **Somente Adm** soft-delete; C/O negados; Adm em **qualquer status** |
| 3 | Edição | **Adm** qualquer status; **Operador** Requested+Launched; **Cliente** só Requested; Settled só Adm |
| 4 | Anexo boleto | **Obrigatório** ≥1 quando `payment_method=Boleto`; multi-arquivo/conciliação = F6 |
| 5 | `notes` | **Sim**, text nullable |
| 6 | OCR | **PDF-only** (parser + linha digitável); **sem Tesseract**; imagem → warning + manual |
| 7 | Transferência / TED | TED **não** é modalidade; = `DepositType::Transfer`. Pacote: `holder_document` (**CPF ou CNPJ**), banco, agência, conta+dígito, `account_type`. `holder_name` opcional |
| 8 | Tamanho anexo | **10 MB** (`max_kilobytes=10240`) |
| 9 | Pix chave ou QR | Campo `pix_qr_code`; regra ≥1 caminho (chave completa OU QR); ambos permitidos; sem enum PixQr; sem OCR de QR na F3 |

### 20.2 Residuais

| # | Status | Decisão |
|---|--------|---------|
| R1 | ✅ Fechado | Campos Transfer = pacote da modalidade (não “extras opcionais”) |
| R2 | ✅ Fechado (2026-07-29) | `holder_document` = **CPF ou CNPJ**; `ValidCpf`/`ValidCnpj` por length 11/14 (ou Select virtual `PersonType`) |

---

## 21. Testes planejados (Pest)

- Unit: `calculateNetAmount`; `PaymentRequestStatus::canTransitionTo`; parser linha digitável; `paymentMethodFor`; `requiresAppropriation` / `isEditableBy`
- Feature/Service: create com history; appropriation required vs optional por company; boleto sem anexo falha; transition + event; branch auto Cliente; **delete 403 C/O, ok Adm**; update Settled só Adm; bank details Transfer
- Pix só chave; Pix só QR; Pix ambos; Pix nenhum → falha
- Transfer completo vs faltando holder_document/account_type → falha
- holder_document CPF válido (11) e CNPJ válido (14); rejeitar length inválido / dígitos inválidos
- Storage::fake: upload MIME reject; OCR PDF mock; imagem sem OCR; forceDelete remove arquivo
- Cascade Company soft → PaymentRequests + bankDetails + attachments; forceDelete limpa storage; history mantém no soft do PR
- Livewire Filament: List filtros; Create form condicional; Toggle company; Policy Cliente vs Operador
- Attachment morph + `has_attachments` sync
- Unique parcial bank_details 1:1; Bank guard com bank_details; holder_document só dígitos

---

## 22. Trade-offs e riscos

| Tema | Trade-off | Mitigação |
|------|-----------|-----------|
| OCR só PDF | Imagens de boleto sem auto-fill | Warning explícito; upload ainda válido |
| Flag apropriação por company | Coluna extra vs config global | Isolamento multi-tenant BPO |
| Adm edita Settled | Risco de alterar liquidado | Audit blameable + history de status |
| net_amount desnormalizado | Drift se update bypass Service | Só mutar via Service; teste invariante |
| Pix chave e/ou QR | Ambos permitidos; CNAB F7 escolhe fonte | Documentar; testes dos 3 cenários |
| Transfer = TED/conta | Sem enum Ted evita proliferação | Label i18n “Transferência (TED/DOC/conta)” |

Riscos DRF: R08 (dados bancários) — validação rigorosa antecipa CNAB; R12 — Policies.

---

## 23. Checklist de implementação

- [ ] Enums DepositType, PixKeyType, PaymentRequestStatus, AttachmentType + i18n
- [ ] Migration `is_appropriation_required` em `companies` + Toggle CompanyResource
- [ ] Migrations PaymentRequest / BankDetails (incl. `pix_qr_code`) / History / Attachments + unique parciais
- [ ] Coluna `pix_qr_code` + validação Pix (chave OR QR)
- [ ] Validação Transfer pacote completo (holder_document CPF|CNPJ + account_type etc.)
- [ ] Models + HasAttachments + morph map + helpers edição/apropriação
- [ ] Observers (Attachment, PaymentRequest cascade, Company cascade ordem §3.13)
- [ ] Exceptions + Services + DTOs (regras BA)
- [ ] Integrations OCR **PDF-only** + Actions
- [ ] Events (+ listeners mínimos)
- [ ] Policies (delete só Adm; update por matriz status)
- [ ] Guards Supplier/Branch/CostCenter/Appropriation/**Bank** (bank_details)
- [ ] Filament PaymentRequestResource Blueprint §12
- [ ] config `rjet.attachments.max_kilobytes=10240` + `rjet.ocr`
- [ ] Factories/Seeders (company requiresAppropriation)
- [ ] Pint + Pest §21
- [ ] Checklists `.ai/checklists.md` (Model, Migration, Policy, Event, Factory, File storage)

---

## 24. Próximos passos / Handoff

1. ~~**`dba`** — revisar schema §18~~ ✅ **Concluído em 2026-07-29** — ver [Revisão DBA](#25-revisão-dba).
2. ~~Pendências BA~~ ✅ (R1+R2 fechados). Sem residual BA aberto.
3. **`/blueprint`** (opcional) — detalhar Form reativo Filament v5.
4. **Spike OCR PDF** curto (escolher parser + validar linha digitável) — **sem** Tesseract.
5. **`implementer` / `/feature`** — ordem sugerida:
   1. Enums + config + i18n
   2. Migration companies flag + demais migrations (unique parcial com guard)
   3. Models/traits/observers/morph map (`PaymentRequestObserver`, `AttachmentObserver`, estender `CompanyObserver`)
   4. Exceptions + PaymentRequestService + AttachmentService + guards Bank/Supplier/Branch/CostCenter/Appropriation
   5. OCR PDF Integration + Actions
   6. Events
   7. Policies
   8. Filament PaymentRequest + Company Toggle
   9. Factories/Seeders
   10. Pint + testes
6. **`tester`** — cobertura §21 + cascade Company→PaymentRequest→attachments (storage no forceDelete) + unique parcial bank_details + Bank guard com bank_details + holder_document dígitos.
7. **`reviewer`** — validar correções DBA aplicadas na implementação.

> Checklists: `.ai/checklists.md` · Filament: `.ai/skills/filament/SKILL.md` · file-storage / events / enums / soft-deletes.

---

## 25. Revisão DBA

> **Revisor:** dba (subagent) · **Data:** 2026-07-29 · **Driver:** PostgreSQL
> **Escopo:** schema proposto Fase 3 (`companies.is_appropriation_required`, `payment_requests`, `payment_request_bank_details` 1:1 + `pix_qr_code`, `payment_request_status_history` append-only, morph `attachments`, cascades Company/Branch/Supplier/CostCenter/Appropriation/Bank, unique parciais, desnormalizações `net_amount`/`has_attachments`). Alinhado a Fases 1–2 implementadas e a `database.md` / `enums.md` / `soft-deletes.md` / `performance.md` / `file-storage.md`.
> MCP Boost `database-schema` indisponível neste ambiente — inspeção via migrations/Models reais (`companies`, `CompanyObserver`, `BankService`, morph `addresses`/`contacts`).

### Veredito

**Aprovado com ressalvas.** A modelagem está em 3NF (desnormalizações documentadas), morph vs dedicado está correto (attachments morph; history e bank_details dedicados), enums como `string` + cast PHP, SoftDeletes + unique parciais no padrão F1/F2, e history append-only sem SoftDeletes é coerente com auditoria. Nenhum achado exige rejeição ou redesenho de entidades. Correções abaixo já incorporadas em §3.13–§3.15, §4, §8.6, §9–§10 e §18.

### Achados (Crítico / Importante / Sugestão)

| # | Nível | Achado | Impacto | Correção aplicada |
|---|-------|--------|---------|-------------------|
| 1 | **Crítico** | Cascade Company/PaymentRequest incompleto: ordem vs branches ambígua; morph `attachments` **sem FK** — forceDelete via só `cascadeOnDelete` de `branch_id` **não** dispara `AttachmentObserver` → arquivos órfãos no S3/local. | Storage órfão; bankDetails/attachments inconsistentes no teardown. | §3.13/§4.5/§8.6: ordem soft+force explícita; PaymentRequests **antes** de appropriations/branches; forceDelete **Eloquent** para limpar storage; `PaymentRequestObserver` documentado. |
| 2 | **Crítico** | §4.1 deixava `branch_id` como `restrictOnDelete` **ou** `cascadeOnDelete` — ambíguo para o implementer. | Migration divergente; conflito com padrão F1 e com guard de Branch. | **`cascadeOnDelete`** no hard/force (igual F1); soft via Observer; guard de PaymentRequest só em `BranchService` (exclusão direta). §4.1 + §18 alinhados. |
| 3 | **Crítico** | Unique parcial 1:1 em bank_details só como comentário em §18 — sem `supportsPartialIndexes()` + `DB::statement` + proibição de `->unique()` no Blueprint. | Migrations quebram em SQLite de testes ou criam unique full-table incompatível com SoftDeletes. | §10/§18: padrão F1/F2 obrigatório com snippet completo. |
| 4 | Importante | `BankService::ensureDeletable` hoje só olha `branch_bank_accounts`; F3 adiciona `payment_request_bank_details.bank_id` sem guard. | Soft-delete de Bank com solicitações Transfer ativas → referência “morta” / CNAB quebrado. | §3.13/§4.5/§7: estender guard para bank_details **não trashed**. |
| 5 | Importante | History sem índice composto `(payment_request_id, created_at)` — `database.md` recomenda para timeline. | Listagem de trilha por solicitação menos eficiente (volume baixo, mas alinhamento). | §4.3/§18: `$table->index(['payment_request_id', 'created_at'])`. |
| 6 | Importante | `holder_document` sem norma explícita de persistência (máscara vs dígitos) no blueprint — risco igual F2 `document`. | Duplicatas lógicas / ValidCpf/Cnpj falhando com máscara. | §4.2/§18: **somente dígitos** (comentário + validação app). |
| 7 | Importante | `PaymentRequestObserver` / sync `has_attachments` / history no soft vs force pouco especificado além de uma linha. | Implementer omite cascade morph ou apaga history no soft por engano. | §3.13 tabela de eventos; history **mantém** no soft; force limpa via FK + attachments via Observer. |
| 8 | Sugestão | Indexar `is_appropriation_required`? | Custo sem ganho — flag lida via Company já hidratada. | **Não** indexar (§3.15/§9/§18). |
| 9 | Sugestão | Indexar `pix_qr_code` (text)? | Índice em text de payload QR inútil para WHERE de listagem. | **Não** indexar — confirmado. |
| 10 | Sugestão | Índice composto `(status, created_at)` em `payment_requests`? | RNF011 ~70/dia — over-index. | **Não** criar; FKs + `status` + `due_date` bastam (§9). |
| 11 | Sugestão | `sort_order` em attachments sem `->index()`. | Fora da convenção `database.md` (leve). | `->index()` adicionado (§4.4/§18). |

### Checklist DBA (resumo)

| Área | Resultado |
|------|-----------|
| Normalização 3NF | ✅ (`net_amount`, `has_attachments` documentados; sem `company_id` desnormalizado) |
| Morph vs dedicado | ✅ attachments morph; history dedicado; bank_details dedicado 1:1 |
| Campos (`is_`/`has_`, `_at`, `_by`, decimal(10,2)) | ✅ |
| Enums (`string`, sem `$table->enum()`) | ✅ |
| UUID + timestampsTz + softDeletesTz | ✅ (history: só `created_at` tz — intencional) |
| Unique parciais + SoftDeletes | ✅ (com guard) |
| FK index explícito (Postgres) | ✅ |
| Cascade soft vs `cascadeOnDelete` + morph storage | ✅ documentado |
| History append-only | ✅ sem SoftDeletes; soft parent mantém; force remove via FK |
| `pix_qr_code` text / `holder_document` dígitos | ✅ |
| `is_appropriation_required` | ✅ default false, sem index |
| Conflitos Fases 1–2 | ✅ estende CompanyObserver/BankService sem quebrar colunas |

### Decisões DBA confirmadas (2026-07-29)

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | `branch_id`: restrict vs cascade? | **`cascadeOnDelete`** (hard); soft via Observer; guard só em BranchService |
| 2 | Indexar `is_appropriation_required`? | **Não** |
| 3 | Indexar `pix_qr_code`? | **Não** |
| 4 | Composto `(status, created_at)` em payment_requests? | **Não** nesta fase (RNF011) |
| 5 | History no soft-delete do parent? | **Manter** linhas; force → FK cascade |
| 6 | Bank delete com bank_details? | **Bloquear** se não trashed |
| 7 | Pruning PaymentRequest/Attachment? | **Adiado** (retenção financeira) |

### Notas para implementer

1. Migrations: copiar helper `supportsPartialIndexes()` das migrations F1 (`companies` / unique document).
2. `CompanyObserver` atual (`app/Observers/CompanyObserver.php`) **não** conhece PaymentRequests — estender na ordem §3.13 **antes** do passo appropriations/branches.
3. `forceDeleting` em PaymentRequest (ou Observer) deve forceDelete attachments **antes** do DELETE do parent para garantir limpeza de storage (morph sem FK).
4. Normalizar `holder_document` para dígitos no Service/DTO (mesmo padrão Supplier).
5. Testes Pest obrigatórios: unique parcial 1:1 bank_details; cascade Company soft+force com assertMissing no Storage; Bank guard com bank_details; history permanece após soft-delete do PR.