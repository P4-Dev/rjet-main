# Arquitetura: Fase 5 — Lote de solicitações (importação via planilha)

> **Escopo:** RF025–RF026
> **Fora de escopo:** Fases 6–8 (lote de anexos, CNAB, dashboard). Apenas hooks.
> **Stack:** Laravel 12 · Filament 5 · PHP 8.4 · Pest 4 · Livewire 4 · PostgreSQL · Redis
> **Fonte:** DRF + arquiteturas F1–F4
> **Autor:** architect (subagent)
> **Data:** 2026-09-26 · **Atualizado:** 2026-09-26 (revisão DBA)
> **Status:** Revisão **`dba` concluída** — **Aprovado com ressalvas** (já incorporadas). Blueprint em `.ai/blueprints/fase-5-lote-solicitacoes.md`. Pronto para `implementer`.

---

## 1. Contexto

As Fases 1–4 entregaram o núcleo financeiro e o gate de aprovação. A **Fase 5** adiciona **cadastro de templates de importação** (mapeamento coluna → campo) e **importação em lote via planilha**, gerando `PaymentRequest`s com relatório de erros por linha, sem reinventar status, policies de visibilidade ou workflow.

| Capacidade já existente | Origem |
|-------------------------|--------|
| `PaymentRequest` + `PaymentRequestStatus` (`Requested` → `Launched` → `Settled`) | F3 |
| `PaymentRequestService::create()` + `PaymentRequestData` + bank details + asserts | F3 |
| `PaymentRequestCreated` → `RoutePaymentRequestOnCreated` → `ApprovalService::route()` | F4 |
| Policies / `scopeVisibleTo` / filial por usuário | F1–F3 |
| `ValidCpf` / `ValidCnpj` / `ValidCpfOrCnpj` | F2–F3 |
| Resolução fornecedor / CC / apropriação / forma de pagamento (UI + services) | F2–F3 |

### 1.1 Estado real do código (2026-09-26)

| Artefato | Status |
|----------|--------|
| Models `ImportTemplate`, `ImportBatch`, mappings, erros | **Não existem** |
| Jobs em `app/Jobs/` | **Nenhum** (primeiro job do módulo) |
| Events `PaymentRequestBatchImported` | **Não existe** (DRF §6 TBD F5) |
| Biblioteca de planilha em `composer.json` (require direto) | **Não** |
| `openspout/openspout` ^4 e `league/csv` ^9 | **Presentes no vendor** como dependência transitiva de `filament/actions` |
| `PaymentRequestService::create` | Existe; dispara `PaymentRequestCreated`; exige boleto com anexo |
| Listener `RoutePaymentRequestOnCreated` | Existe (sync); usa `Auth` ou fallback `created_by` |
| Painel Filament | Único `admin`; groups: `registrations`, `operations`, `settings`, `reports`, `main` |
| `.ai/rules/` | **Ausente** nesta cópia do repo — convenções tomadas de PROJECT.md + `.ai/docs` + arquiteturas F1–F4 |

### 1.2 Preferências de projeto aplicadas

- Documento em **pt-BR**; classes/namespaces em **inglês**.
- `agrupar_por_dominio`: Jobs, Events, Actions, Listeners **agrupados**; Services/DTOs/Models **flat**.
- Comentários no código (quando houver): mínimo, em inglês.

---

## 2. Requisitos

### 2.1 Funcionais (Fase 5)

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| RF025 | Adm cadastra template mapeando colunas → campos de `PaymentRequest` | `ImportTemplate` + versão + mappings |
| RF026 | Usuário autorizado envia planilha; validação linha a linha; cria PRs; relatório de erros | `ImportBatch` + job + erros |

**Regras de negócio:**

| RN | Conteúdo | Decisão nesta arquitetura |
|----|----------|---------------------------|
| RN025.1 | Metadados: nome, empresa/filial alvo opcional, formato aceito | §4.1 Models |
| RN025.2 | Mapeamento configurável e versionável | §3.3 versionamento (template SoftDeletes; versões imutáveis; mappings hard-owned) |
| RN026.1 | Parcial **ou** bloqueio total — DRF deixa para implementação | **Modo parcial** (§3.2) |
| RN026.2 | Evento `PaymentRequestBatchImported` | §8 |
| RN026.3 | Validações CPF/CNPJ, monetários, filial do usuário | §13 fluxos |

**Critérios de aceite (DRF):**

RF025:

- [ ] Adm cria template associando cada coluna obrigatória a um campo do domínio.
- [ ] Template pode ser selecionado no fluxo de importação.
- [ ] Campos obrigatórios do domínio mapeados ou com default.

RF026:

- [ ] Upload com template selecionado processa registros.
- [ ] Relatório lista linhas com erro e motivo.
- [ ] Solicitações válidas criadas com status inicial e filial correta.
- [ ] Job assíncrono para planilhas grandes (RNF009).

### 2.2 Não-funcionais aplicáveis

| RNF | Aplicação na F5 |
|-----|-----------------|
| RNF001 | UUID, `timestampsTz` / `softDeletesTz`, `created_by`/`updated_by` onde aplicável |
| RNF002 | Monetários `decimal(10,2)` via `PaymentRequestService` (bcmath) |
| RNF003 | Status/formatos como `string` + Enum PHP |
| RNF006 | Policies: template = Adm; import = Operador/Adm; escopo de filial nas linhas |
| RNF007 | CPF/CNPJ via Rules existentes na resolução de fornecedor / holder |
| RNF009 | `ProcessImportBatchJob` em fila |
| RNF010 | `PaymentRequestBatchImported` + reuso de `PaymentRequestCreated` |
| RNF011 | ~70 pagamentos/dia, lotes moderados — sem motor genérico de ETL |
| RNF012 | Planilha em disco private (local/S3); sem URL pública |

### 2.3 API REST

> **Assunção (igual F1–F4): NÃO.** Painel Filament interno BPO. Sem Sanctum/Passport/Swagger nesta fase.

### 2.4 Fora de escopo (hooks apenas)

| Item | Fase | Hook na F5 |
|------|------|------------|
| Lote de anexos / nomenclatura | 6 | PRs criadas sem anexos; boleto em lote **falha na linha** (anexo obrigatório F3); F6 pode associar anexos depois |
| CNAB / baixa | 7 | PRs seguem fluxo normal após aprovação/lançamento |
| Dashboard / Excel analítico | 8 | Event `PaymentRequestBatchImported` disponível para métricas |
| Criação de fornecedor “on the fly” pela planilha | — | **Não** — linha falha se documento não existir |
| API REST | — | Assunção NÃO |

**Hook F4 honrado:** cada PR criada via `PaymentRequestService::create()` dispara `PaymentRequestCreated` → `RoutePaymentRequestOnCreated` → `ApprovalService::route()`. **Não** chamar `route()` de novo no job de import (evita `alreadyPending`).

---

## 3. Decisões de Design

### 3.1 Normalização e entidades compartilhadas

| Candidato | Decisão | Justificativa |
|-----------|---------|---------------|
| **ImportTemplate** | **Dedicado** `import_templates` | Core de configuração Adm; FK Company/Branch; não morph |
| **ImportTemplateVersion** | **Dedicado** `import_template_versions` | Versionamento imutável referenciado pelo lote (auditoria) |
| **ImportTemplateMapping** | **Dedicado** `import_template_mappings` | Filho 1:N da versão; **hard-owned** (sem SoftDeletes); estrutura específica (coluna → campo) |
| **ImportBatch** | **Dedicado** `import_batches` | Core operacional; FK crítica para template version + arquivo |
| **ImportBatchError** | **Dedicado** `import_batch_errors` | Relatório append-only por linha; não morph `notes` |
| **Arquivo da planilha** | Campos em `import_batches` (`disk`, `path`, …) | **Não** reutilizar morph `attachments` (tipos/MIME/ciclo de vida diferentes do anexo de PR) |
| **Vínculo PR ↔ lote** | Coluna nullable `payment_requests.import_batch_id` | Rastreabilidade; FK `nullOnDelete` (forceDelete do batch zera vínculo; soft do batch mantém FK) |

**Desnormalizações intencionais** (comentário obrigatório na migration):

1. `import_batches.mappings_snapshot` (JSON) — cópia dos mappings da versão no momento do upload; relatório auditável mesmo se a versão for soft-deleted depois. **Sem índice GIN** no MVP (payload só lido no process/View).
2. Contadores `total_rows` / `success_count` / `error_count` no batch — cache de agregação para UI (evita `COUNT` em `import_batch_errors` / PRs a cada listagem).

**Morph rejeitado** para template/lote/erros/arquivo: entidades core, FKs críticas, queries por status/versão, estrutura específica. Volume ~70 pagamentos/dia — sem motor genérico.

### 3.2 Decisão RN026.1 — Modo parcial (escolhido)

**Escolha: modo parcial** — linhas válidas geram `PaymentRequest`; inválidas vão para `import_batch_errors`; o lote termina `Completed` (com ou sem erros) ou `Failed` só em falha estrutural.

| Resultado do lote | Quando |
|-------------------|--------|
| `Completed` | Arquivo legível + template ok + ≥1 linha processada (mesmo que todas errem) |
| `Completed` com `success_count > 0` | Critério de aceite: “solicitações válidas criadas” + “relatório lista erros” |
| `Failed` | Arquivo ilegível / MIME inválido / template incompatível / zero linhas de dados / exceção não recuperável no job |
| **Não** existe “rollback de todo o lote” por 1 linha ruim | Alinhado ao aceite “relatório lista linhas com erro” |

**Trade-off rejeitado — bloqueio total (all-or-nothing):**

| Prós | Contras |
|------|---------|
| Consistência “tudo ou nada” | Usuário corrige 1 célula e reprocessa 200 linhas; conflito com critério de relatório parcial; complicação de transação única grande |

**Transação:** cada linha válida = **uma** transação via `PaymentRequestService::create` (já transacional). Não envolver o lote inteiro em uma única DB transaction.

### 3.3 Decisão RN025.2 — Versionamento do template

**Modelo concreto:**

```
ImportTemplate (lógico, SoftDeletes)
  └── hasMany ImportTemplateVersion (imutável após publish; SoftDeletes só se nunca referenciada por batch)
        └── hasMany ImportTemplateMapping (hard-owned; SEM SoftDeletes; SEM updated_at)
ImportBatch ──belongsTo──> ImportTemplateVersion  (FK restrictOnDelete — imutável)
```

**Decisão fechada (DBA):** não existe “versão draft” editável. Create = publica v1 atomicamente. Alterar mappings = `publishVersion()` cria vN+1 com **novas** rows de mapping. Mappings de versão publicada **não** têm SoftDeletes — são hard-owned pela version (`cascadeOnDelete` só no forceDelete da version).

| Regra | Comportamento |
|-------|---------------|
| Criar template | Cria `ImportTemplate` + `ImportTemplateVersion` v1 (`is_current=true`) + mappings |
| Editar metadados (nome, alvo, formato, is_active) | Update no `ImportTemplate` (não bumpa versão) |
| Alterar mappings | `ImportTemplateService::publishVersion()`: marca versão atual `is_current=false`; cria vN+1 imutável com novos mappings (insert; nunca update in-place) |
| Soft delete template | Soft-delete `ImportTemplate` apenas; **não** cascade soft nas versions (`cascadeOnDelete` **não** cobre soft). Select de import filtra template trashed/`is_active`. Batches antigos continuam com FK para a version |
| Soft delete version | Permitido **só** se `batches()->doesntExist()` (`versionInUse`); senão bloquear. ForceDelete version: `cascadeOnDelete` hard nas mappings; `restrictOnDelete` do batch impede se houver lote |
| Select no import | Só versões `is_current=true` de templates `is_active` e não trashed |

**Justificativa:** o lote precisa de referência **estável** ao mapeamento usado (relatório + auditoria). Soft delete sozinho sem versão faria o batch “ver” mappings já alterados. Alternativa rejeitada: só JSON snapshot no batch sem tabela de versão — perde reuso/UI de diff e select histórico.

### 3.4 Decisão — Biblioteca de planilha

**Estado:** `composer.json` **não** declara lib de planilha. Já instalados via Filament: `openspout/openspout` e `league/csv`.

| Opção | Veredito |
|-------|----------|
| **A — Promover `openspout/openspout` a require direto** | **Recomendado** — reader streaming (xlsx/csv/ods), leve, já no lock; ideal para RNF009 |
| B — Usar só transitivo sem declarar | Rejeitado — quebra se Filament remover a dep |
| C — `maatwebsite/excel` / PhpSpreadsheet | Rejeitado para MVP — mais pesado; nova dep maior |

> **Decisão que exige aprovação de dependência:** adicionar `"openspout/openspout": "^4.23"` em `require` (já resolvido no lock). Sem aprovação, implementer **não** altera `composer.json`.

Wrapper interno sugerido: `App\Integrations\Spreadsheet\SpreadsheetReader` (namespace Integrations agrupado) — isola OpenSpout da Application layer.

### 3.5 Decisão — Sync vs queue / tamanho

| Aspecto | Escolha |
|---------|---------|
| Processamento | **Sempre** `ProcessImportBatchJob` após persistir o batch + arquivo (caminho único; RNF009) |
| Sync imediato | Apenas validação de upload + MIME + existência do template (request HTTP) |
| Limite arquivo | `config('rjet.imports.max_kilobytes')` default **5120** (5 MB) |
| Limite linhas | `config('rjet.imports.max_rows')` default **500** (acima → erro estrutural no job) |
| Timeout job | `$timeout = 120`; `$tries = 3`; `WithoutOverlapping` por `import_batch_id` |
| Fila | `default` (Redis); sem fila dedicada no MVP (RNF011) |

**Trade-off:** sync para lotes minúsculos daria feedback instantâneo, mas duplicaria código e edge cases Auth/UI. Volume ~70/dia torna queue sempre aceitável.

### 3.6 Decisão — Quem importa / quem configura template

| Papel | Template CRUD | Importar planilha |
|-------|---------------|-------------------|
| **Adm** | Sim | Sim |
| **Operador** | Não | Sim (DRF §2.3) |
| **Cliente** | Não | **Não** (DRF lista import só no Operador; Cliente cria PR unitária) |

### 3.7 Decisão — Campos mapeáveis e resolução

Campos obrigatórios de domínio (via `PaymentRequestService::create` / F3), cobertos por **mapping** ou **default** (template version / valor fixo na coluna de mapping):

| Campo domínio | Como vem da planilha | Obrigatório |
|---------------|----------------------|-------------|
| `branch_id` | Coluna (CNPJ filial / código) **ou** default `ImportTemplate.branch_id` | Sim |
| `supplier_id` | Coluna CPF/CNPJ → lookup `suppliers.document` | Sim |
| `cost_center_id` | Coluna `code` no escopo da filial | Sim |
| `appropriation_id` | Coluna `code` na company da filial | Se `Company.is_appropriation_required` |
| `payment_method` | Coluna enum **ou** default **ou** `Supplier.default_payment_method` | Sim |
| `gross_amount` | Coluna monetária | Sim (`> 0`) |
| `discount_amount` | Coluna ou default `0` | Sim (default 0) |
| `due_date` | Coluna data | Sim |
| `notes` | Coluna opcional | Não |
| Bank details | Colunas condicionais conforme método | Conforme F3 |

**Resolução (sempre no Service de import, não no Form):**

1. Normalizar documento (só dígitos) → `ValidCpf`/`ValidCnpj` conforme tamanho → `Supplier::where('document', $digits)->active()`.
2. Filial: se template tem `branch_id`, usar (e validar linha não contradiz, se vier coluna); senão resolver por CNPJ da branch ativa; depois `PaymentRequestService::resolveBranchFor($actor, $branchId)`.
3. Centro de custo: `CostCenter` ativo com `code` + `branch_id`.
4. Apropriação: `Appropriation` ativo com `code` + `company_id` da branch.
5. Monetários: parse `1.234,56` / `1234.56` → string decimal; `assertAmounts`.
6. Bank: reutilizar asserts de `PaymentRequestService` montando `PaymentRequestBankDetailsData`.

**Boleto em lote:** linha com `payment_method = boleto` **falha** com motivo “anexo de boleto obrigatório — use cadastro individual ou lote de anexos (F6)”. Não relaxar `assertBoletoHasAttachment` na F5.

**Duplicidade:** não há unique de negócio em `payment_requests` hoje. F5 **não** inventa bloqueio global. Opcional: se no **mesmo batch** houver chave igual (`supplier_document` + `gross_amount` + `due_date` + `branch_id`), segunda linha → erro “possível duplicata no lote” (não consulta histórico global).

### 3.8 Decisão — Formato aceito

Enum `ImportFileFormat`: `Csv`, `Xlsx` (OpenSpout; ODS opcional futuro). Template declara um formato; upload deve bater com o template (senão Failed estrutural).

Header da planilha: **primeira linha = nomes de colunas**; mappings usam `source_column` = nome do header (trim, case-sensitive conforme gravado no template). Alternativa rejeitada: só letras A/B/C — frágil quando usuário reordena colunas.

---

## 4. Models

> Convenções F1–F4: PK UUID; FKs com `->index()` explícito (Postgres); `timestampsTz` / `softDeletesTz`; blameable `nullOnDelete`; status/format/target = `string` (**nunca** `$table->enum()`); unique parciais via `DB::statement` + helper `supportsPartialIndexes()` (pgsql+sqlite) + `DROP INDEX IF EXISTS` no `down()` — **nunca** `->unique()` no Blueprint quando SoftDeletes.

### 4.1 `ImportTemplate`

**Tabela:** `import_templates`

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | uuid PK | HasUuid |
| `name` | string(120) | **sem** `->unique()` no Blueprint; unique parcial `WHERE deleted_at IS NULL` |
| `company_id` | foreignUuid nullable + index | alvo opcional RN025.1; `nullOnDelete` |
| `branch_id` | foreignUuid nullable + index | alvo opcional; deve pertencer à company se ambos setados; `nullOnDelete` |
| `accepted_format` | string(10) | Enum `ImportFileFormat` (`csv`/`xlsx`); **sem** índice (filtro raro) |
| `is_active` | boolean default true | **sem** índice (baixa cardinalidade / filtro admin) |
| `created_by` / `updated_by` | foreignUuid nullable + index | HasBlameable; `nullOnDelete` |
| timestampsTz / softDeletesTz | | |

**Traits:** `HasFactory`, `HasUuid`, `HasBlameable`, `SoftDeletes`  
**Casts:** `accepted_format` → Enum; `is_active` → bool  
**Relações:** `company()`, `branch()`, `versions()` hasMany  
**Scopes:** `scopeActive`, `scopeVisibleTo` (Adm vê todos; Operador não CRUD — só select de ativos)

### 4.2 `ImportTemplateVersion`

**Tabela:** `import_template_versions`

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | uuid PK | |
| `import_template_id` | foreignUuid + index | `cascadeOnDelete` (**hard/force** do template); soft do parent **não** apaga versions |
| `version` | unsignedInteger | 1, 2, 3…; **unique full** `(import_template_id, version)` — número nunca reutilizado |
| `is_current` | boolean default false | no máx. um current por template (unique parcial) |
| `published_at` | timestampTz | |
| `created_by` | foreignUuid nullable + index | quem publicou; `nullOnDelete` |
| `created_at` | timestampTz | **sem** `updated_at` (imutável) |
| softDeletesTz | | só se `batches()->doesntExist()`; senão bloquear no Service |

**Model:** `public const UPDATED_AT = null;` (e `$timestamps = false` **ou** só `CREATED_AT` — espelhar `PaymentRequestStatusHistory` / reassignments: preferir `public $timestamps = false` + preencher `created_at`/`published_at` no Service).

**Uniques:**

| Índice | Tipo | SQL (conceito) |
|--------|------|----------------|
| `(import_template_id, version)` | Unique **full** | Número de versão imutável; nunca reusar após soft-delete |
| um `is_current` por template | Unique **parcial** | `ON (import_template_id) WHERE is_current = true AND deleted_at IS NULL` via `supportsPartialIndexes()` |

**Relações:** `template()`, `mappings()`, `batches()`  
**Relação batches:** `belongsTo` no batch usa `restrictOnDelete` — versions referenciadas **não** podem sumir no forceDelete.

### 4.3 `ImportTemplateMapping`

**Tabela:** `import_template_mappings`

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | uuid PK | |
| `import_template_version_id` | foreignUuid + index | `cascadeOnDelete` (hard da version) |
| `source_column` | string(120) **nullable** | header da planilha; **null** = mapping só-default |
| `target_field` | string(60) | Enum `ImportTargetField` (maior value ~19 chars; 60 com folga) |
| `default_value` | string(255) nullable | usado se célula vazia / mapping só-default |
| `sort_order` | unsignedInteger default 0 | **sem** índice (N mappings por versão ≪ 1000) |
| `created_at` | timestampTz | **sem** `updated_at`; **sem** SoftDeletes |

**Decisão fechada (DBA):** mappings de versão publicada **não** têm SoftDeletes — hard-owned pela version. Alteração = nova version + novos inserts.

**Uniques:**

| Índice | Tipo | Nota |
|--------|------|------|
| `(import_template_version_id, target_field)` | Unique full | Um target por versão (sem soft → `->unique()` no Blueprint **OK**) |
| `(import_template_version_id, source_column)` | Unique full | **Postgres:** `NULL` é distinto — vários mappings só-default (`source_column IS NULL`) no mesmo version **coexistem**. Documentar no teste. |

**Model:** `public const UPDATED_AT = null;` + `$timestamps = false` (só `created_at`).

### 4.4 `ImportBatch`

**Tabela:** `import_batches`

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | uuid PK | |
| `import_template_version_id` | foreignUuid + index | **`restrictOnDelete`** — version imutável / auditoria |
| `status` | string(20) + index | Enum `ImportBatchStatus` |
| `disk` | string(50) | |
| `path` | string(500) | path privado no disk |
| `original_filename` | string(255) | |
| `mime_type` | string(100) nullable | |
| `size` | unsignedInteger | bytes |
| `mappings_snapshot` | json | desnormalização §3.1; **sem** GIN |
| `total_rows` | unsignedInteger default 0 | desnormalizado |
| `success_count` | unsignedInteger default 0 | desnormalizado |
| `error_count` | unsignedInteger default 0 | desnormalizado |
| `failure_reason` | text nullable | só Failed estrutural |
| `started_at` / `finished_at` | timestampTz nullable | |
| `created_by` / `updated_by` | foreignUuid nullable + index | importer = created_by; `nullOnDelete` |
| timestampsTz / softDeletesTz | | |

**Índices além de FKs/status:** composto `(created_by, created_at)` para listagem do importador.  
**Traits:** HasFactory, HasUuid, HasBlameable, SoftDeletes  
**Casts:** status Enum; mappings_snapshot array; dates  
**Scopes:** `scopeVisibleTo` — Operador/Adm veem todos; Cliente **não** acessa Resource  
**Relações:** `templateVersion()`, `errors()`, `paymentRequests()`, `creator()`

### 4.5 `ImportBatchError`

**Tabela:** `import_batch_errors`

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | uuid PK | |
| `import_batch_id` | foreignUuid + index | `cascadeOnDelete` (**hard** do batch); soft do batch **não** apaga errors |
| `row_number` | unsignedInteger | 1-based na planilha (dados; header = 0 não grava) |
| `target_field` | string(60) nullable | |
| `message` | string(500) | mensagem user-facing (i18n key resolvida) |
| `raw_values` | json nullable | células da linha (auditoria) |
| `created_at` | timestampTz | **sem** updated_at; **sem** SoftDeletes |

**Model:** append-only — `public $timestamps = false;` + `UPDATED_AT = null`.  
**Índice:** `(import_batch_id, row_number)` para RelationManager ordenado.

### 4.6 Extensão `PaymentRequest`

- Migration alter: coluna nullable `import_batch_id` → `import_batches`, `->index()`, **`nullOnDelete`**.
- **Sem** `after()` (Postgres ignora; padrão F4).
- **Não** altera colunas/FKs/índices existentes de F3/F4 (`branch_id`, `status`, approvals, bank_details, etc.).
- Relação `importBatch(): BelongsTo` (nullable).
- Fillable + factory state `fromImportBatch`.
- Soft-delete da PR **não** remove/altera o batch; soft-delete do batch **mantém** `import_batch_id` na PR (rastreio); forceDelete do batch → FK zera (`nullOnDelete`).

---

## 5. Relacionamentos

```
ImportTemplate ──hasMany──> ImportTemplateVersion
ImportTemplate ──belongsTo─> Company (opcional)
ImportTemplate ──belongsTo─> Branch (opcional)

ImportTemplateVersion ──belongsTo─> ImportTemplate
ImportTemplateVersion ──hasMany───> ImportTemplateMapping
ImportTemplateVersion ──hasMany───> ImportBatch

ImportBatch ──belongsTo─> ImportTemplateVersion
ImportBatch ──hasMany───> ImportBatchError
ImportBatch ──hasMany───> PaymentRequest

PaymentRequest ──belongsTo─> ImportBatch (nullable)
PaymentRequest ──(F3/F4 inalterado)──> Branch, Supplier, Approval, …
```

---

## 6. Enums

Todos: `string` no banco; PHP backed enum; Filament `HasLabel`, `HasColor`, `HasIcon`; labels via `__('enums.*')`.

### 6.1 `ImportFileFormat`

| Case | Value | Label (pt) |
|------|-------|------------|
| Csv | `csv` | CSV |
| Xlsx | `xlsx` | Excel (XLSX) |

### 6.2 `ImportBatchStatus`

| Case | Value | Cor sugerida | Transições |
|------|-------|--------------|------------|
| Pending | `pending` | gray | → Processing, Failed |
| Processing | `processing` | info | → Completed, Failed |
| Completed | `completed` | success | — |
| Failed | `failed` | danger | — |

### 6.3 `ImportTargetField`

Catálogo fechado (extensível depois sem migration de enum SQL):

**Cabeçalho PR:** `branch_document`, `supplier_document`, `cost_center_code`, `appropriation_code`, `payment_method`, `gross_amount`, `discount_amount`, `due_date`, `notes`

**Bank / depósito:** `deposit_type`, `digitable_line`, `pix_key_type`, `pix_key`, `pix_qr_code`, `holder_name`, `holder_document`, `bank_code`, `agency`, `agency_digit`, `account_number`, `account_digit`, `account_type`

Validação de template na publish: exige mapping **ou** default para: `supplier_document`, `cost_center_code`, `gross_amount`, `due_date`, e `branch_document` **ou** `ImportTemplate.branch_id`. `payment_method` mapping/default **ou** fallback supplier. `appropriation_code` se alguma company alvo exige (se template sem company, validação na **linha**).

---

## 7. Exceções de domínio

**Classe:** `App\Exceptions\ImportException extends BusinessException`

Factory methods (mensagem interna EN + `userMessage` via `__()`):

| Method | Uso |
|--------|-----|
| `templateInactive()` | Template soft-deleted / `is_active=false` |
| `templateMissingRequiredMappings()` | Publish/import sem campos obrigatórios |
| `incompatibleFormat()` | Extensão/MIME ≠ `accepted_format` |
| `unreadableSpreadsheet()` | OpenSpout não lê |
| `rowLimitExceeded(int $max)` | > max_rows |
| `emptySpreadsheet()` | Sem linhas de dados |
| `batchNotPending()` | Reprocessamento inválido |
| `unauthorizedImport()` | Cliente / sem policy |
| `branchNotAllowed(string $id)` | Espelha RN026.3 (ou reusa `PaymentRequestException`) |
| `supplierNotFound(string $document)` | Documento ok mas sem cadastro |
| `invalidDocument(string $document)` | CPF/CNPJ inválido |
| `costCenterNotFound(string $code)` | |
| `appropriationRequired()` / `appropriationNotFound()` | |
| `boletoNotSupportedInBatch()` | |
| `duplicateRowInBatch(int $row)` | Duplicata intra-lote |
| `versionImmutable()` | Tentativa de editar mappings de versão published |
| `versionInUse()` | Soft-delete version com batches |

Erros **por linha** preferem gravar `ImportBatchError` em vez de estourar exception (exceto estruturais). `PaymentRequestException` / `ValidationException` capturados por linha → mensagem em `ImportBatchError`.

---

## 8. Events / Listeners / Queue

### 8.1 `PaymentRequestBatchImported`

- **Namespace:** `App\Events\PaymentRequest\PaymentRequestBatchImported`
- **Gatilho:** fim bem-sucedido do processamento (`Completed`), **após** commit dos contadores — inclusive se `success_count = 0` (relatório só erros). **Não** dispara em `Failed` estrutural.
- **Payload:** `ImportBatch $batch` (mínimo).
- **Fila do event:** sync dispatch; listeners pesados `ShouldQueue`.

| Listener | Namespace | Fila | Efeito |
|----------|-----------|------|--------|
| `LogPaymentRequestBatchImported` | `App\Listeners\PaymentRequest\` | Não (ou sim se log remoto) | Log estruturado: batch id, counts, actor |
| `NotifyImportBatchCompleted` | `App\Listeners\PaymentRequest\` | **Sim** | Notifica `created_by` (solicitante/importador): mail + database (padrão F4) |

**Notification:** `App\Notifications\PaymentRequestBatchImportedNotification` implements `ShouldQueue` — canais `mail` + `database`; payload: counts, link Filament View ImportBatch.

### 8.2 Reuso F3/F4 (por PR)

Cada create → `PaymentRequestCreated` → route aprovação + log (já existentes). Job **não** autentica Auth::login; `created_by` preenchido → listener de route usa fallback.

---

## 9. Camadas (DTO / Service / Action / Job)

### 9.1 DTOs (flat `App\DTOs`)

| DTO | Uso |
|-----|-----|
| `ImportTemplateData` | Create/update metadados |
| `ImportTemplateMappingData` | Uma linha de mapping (publish) |
| `ImportBatchData` | Metadados pós-upload |
| `ImportRowData` | Linha normalizada → alimenta `PaymentRequestData` |

Reutilizar `PaymentRequestData` / `PaymentRequestBankDetailsData` — **não** duplicar.

### 9.2 Services (flat `App\Services`)

| Service | Responsabilidades |
|---------|-------------------|
| `ImportTemplateService` | create, update metadata, `publishVersion(mappings)`, soft delete, assert required mappings |
| `ImportBatchService` | `start(UploadedFile, version, actor)` → store file, snapshot, status Pending, dispatch job; `process(ImportBatch)` → ler planilha, loop linhas, create PR / error; `markFailed`; contadores |
| `ImportRowResolver` (ou métodos privados no Service) | Resolve supplier/branch/CC/appropriation/bank a partir de células + defaults |

**Regra:** `ImportBatchService::process` **só** cria PR via `PaymentRequestService::create` (nunca `Model::create` direto).

### 9.3 Actions (`App\Actions\Import\`)

| Action | Uso |
|--------|-----|
| `StartImportBatchAction` | Policy + `ImportBatchService::start` |
| `PublishImportTemplateVersionAction` | Policy Adm + publish |
| `DownloadImportBatchErrorsAction` (opcional) | Export CSV do relatório — MVP pode ser só table Filament |

### 9.4 Job (`App\Jobs\PaymentRequest\ProcessImportBatchJob`)

- `ShouldQueue`, SerializesModels, `WithoutOverlapping($batch->id)`
- `handle`: `ImportBatchService::process`
- `failed`: status `Failed` + `failure_reason` + log
- Idempotência: se status já `Completed`/`Failed`, no-op; se `Processing` reentrante, só continuar se `success_count=0` e sem PRs (senão Failed “reprocessamento inseguro”) — **MVP: tries com overlapping; não reprocessar Completed**

### 9.5 Integration

`App\Integrations\Spreadsheet\OpenSpoutSpreadsheetReader` — `rows(string $absoluteOrDiskPath): Generator` yield `['row' => int, 'values' => assoc by header]`.

---

## 10. Performance

| Tema | Abordagem F5 |
|------|--------------|
| Cache | **Não** cachear templates (RNF011; admin altera pouco; select Filament query simples) |
| Índices (só WHERE/ORDER BY real) | `import_batches.status`; `(created_by, created_at)`; `import_batch_errors(import_batch_id, row_number)`; FKs com `->index()`; uniques §4 / §21 |
| **Não** indexar | `is_active`, `accepted_format`, `sort_order`, `mappings_snapshot` (sem GIN), contadores |
| Contadores desnormalizados | `total_rows` / `success_count` / `error_count` — atualizados no job; comentário na migration |
| Eager load | View batch: `templateVersion.template`, `errors` paginado; List: `templateVersion.template`, `creator` |
| Chunk | Generator OpenSpout — **não** carregar planilha inteira em memória |
| N+1 | Pré-carregar maps de suppliers/cost centers por códigos únicos do lote (opcional se lote > 100; não obrigatório no MVP) |
| Volume | ~70 pagamentos/dia; teto 500 linhas/lote — sem over-indexing |

---

## 11. File storage

| Aspecto | Decisão |
|---------|---------|
| Disco | `config('rjet.imports.disk')` default = `config('rjet.attachments.disk')` / `FILESYSTEM_DISK` |
| Path | `imports/{Y}/{m}/{batch_uuid}/{hash}.{ext}` |
| Visibilidade | **private** |
| Validação upload | MIME/ext: csv, xlsx; max KB; nome original sanitizado |
| Download | Só via Filament Action autenticada (`Storage::disk()->temporaryUrl` ou stream response) |
| Retenção | Soft-delete batch **não** apaga arquivo imediatamente; Prune opcional futuro (90 dias) — **não** na F5 |
| ForceDelete batch | Apagar arquivo do disk (Observer) |

**Não** usar morph Attachment.

Config sugerida em `config/rjet.php`:

```
imports.max_kilobytes, imports.max_rows, imports.disk, imports.directory,
imports.accepted_extensions = [csv, xlsx]
```

---

## 12. Soft deletes e ciclo de vida

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
| Soft-delete batch | Só `import_batches.deleted_at`; errors e PRs intactos; UI esconde via parent trashed |
| ForceDelete batch | Hard delete errors (FK cascade) + apagar arquivo (Observer) + `payment_requests.import_batch_id` → null |
| ForceDelete version com batches | **Bloqueado** por `restrictOnDelete` no batch |
| Soft-delete version com batches | **Bloqueado** no Service (`versionInUse`) |

**Pruning:** fora do MVP F5 (retenção arquivo/batch futura 90 dias).

---

## 13. Filament Resources (Blueprint obrigatório)

Painel: `admin`. Classes `final`. Resource limpo. Table: `recordActions` / `toolbarActions`. Ícones: `Heroicon` enum. Infolist sempre.

### 13.1 Resource: `ImportTemplateResource`

```
Resource: ImportTemplateResource
  Command: php artisan make:filament-resource ImportTemplate --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\ImportTemplates\ImportTemplateResource
  Structure:
    - ImportTemplateResource.php (final, limpo — só delegates)
    - Schemas/ImportTemplateForm.php
    - Schemas/ImportTemplateInfolist.php
    - Tables/ImportTemplatesTable.php
    - Pages/ (Create, Edit, List, View)
    - RelationManagers/ImportTemplateVersionsRelationManager.php (read-only histórico)
    - Actions/PublishImportTemplateVersionAction.php
  SoftDeletes: getRecordRouteBindingEloquentQuery()
  Icon: Heroicon::OutlinedTableCells
  Navigation:
    Group: __('navigation.groups.settings')
    Sort: 40
    Label: __('import_templates.navigation_label')
  Visibility: só Adm (Policy viewAny)
  Form:
    Field: name
      Component: Filament\Forms\Components\TextInput
      Validation: required, max:120
    Field: company_id
      Component: Filament\Forms\Components\Select
      Validation: nullable, exists:companies,id
      Config: relationship searchable preload; live → filtra branches
    Field: branch_id
      Component: Filament\Forms\Components\Select
      Validation: nullable, exists:branches,id
      Config: options scoped by company_id; helperText alvo default da importação
    Field: accepted_format
      Component: Filament\Forms\Components\Select
      Validation: required
      Config: options ImportFileFormat
    Field: is_active
      Component: Filament\Forms\Components\Toggle
      Validation: boolean
      Config: default true
    Field: mappings (Repeater) — apenas Create / antes do 1º publish
      Component: Filament\Forms\Components\Repeater
      Config: source_column, target_field (Select ImportTargetField), default_value; min 1
  Infolist:
    Entry: name → TextEntry
    Entry: company.name / branch.name → TextEntry
    Entry: accepted_format → TextEntry badge
    Entry: is_active → IconEntry
    Entry: currentVersion.version → TextEntry
    Entry: created_at / updated_at → TextEntry datetime
  Table:
    Column: name → TextColumn searchable sortable
    Column: company.name → TextColumn toggleable
    Column: branch.name → TextColumn toggleable
    Column: accepted_format → TextColumn badge
    Column: currentVersion.version → TextColumn
    Column: is_active → IconColumn boolean
    Filter: is_active → TernaryFilter
    Filter: TrashedFilter
  RelationManagers:
    - ImportTemplateVersionsRelationManager (hasMany versions; view mappings read-only)
  RecordActions: [View, Edit, Delete, PublishImportTemplateVersionAction]
  ToolbarActions: [BulkActionGroup → [DeleteBulk, RestoreBulk, ForceDeleteBulk]]
```

**Publish action:** modal com Repeater de mappings → `PublishImportTemplateVersionAction` → nova versão current.

### 13.2 Resource: `ImportBatchResource`

```
Resource: ImportBatchResource
  Command: php artisan make:filament-resource ImportBatch --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\ImportBatches\ImportBatchResource
  Structure:
    - ImportBatchResource.php (final, limpo)
    - Schemas/ImportBatchForm.php          # create = upload
    - Schemas/ImportBatchInfolist.php
    - Tables/ImportBatchesTable.php
    - Pages/ (Create, List, View) — Edit: omitir ou só Adm soft metadata (preferir sem Edit)
    - RelationManagers/ImportBatchErrorsRelationManager.php
    - RelationManagers/PaymentRequestsRelationManager.php (hasMany)
    - Actions/DownloadImportSpreadsheetAction.php
    - Actions/RetryImportBatchAction.php   # só Failed estrutural sem PRs — opcional MVP
  SoftDeletes: getRecordRouteBindingEloquentQuery()
  Icon: Heroicon::OutlinedArrowUpTray
  Navigation:
    Group: __('navigation.groups.operations')
    Sort: 15
    Label: __('import_batches.navigation_label')
  Visibility: Operador + Adm (não Cliente)
  Form (Create):
    Field: import_template_version_id
      Component: Filament\Forms\Components\Select
      Validation: required
      Config: options = current versions of active templates; label "Template (vN) · format"
    Field: spreadsheet
      Component: Filament\Forms\Components\FileUpload
      Validation: required, extensions csv/xlsx, maxSize config
      Config: disk local staging ou store via Service após submit; storeFiles false + handle em create
  Infolist:
    Entry: status → TextEntry badge (Enum color)
    Entry: templateVersion.template.name + version → TextEntry
    Entry: original_filename → TextEntry
    Entry: total_rows / success_count / error_count → TextEntry
    Entry: failure_reason → TextEntry visible if Failed
    Entry: started_at / finished_at → TextEntry
    Entry: creator.name → TextEntry
  Table:
    Column: created_at → TextColumn sortable
    Column: status → TextColumn badge
    Column: templateVersion.template.name → TextColumn
    Column: success_count / error_count → TextColumn
    Column: creator.name → TextColumn
    Filter: status → SelectFilter
    Filter: created_at → Filter date range
    Filter: TrashedFilter (Adm)
  RelationManagers:
    - ImportBatchErrorsRelationManager
        Columns: row_number, target_field, message
        recordActions: [] (read-only)
        toolbarActions: []
    - PaymentRequestsRelationManager
        Columns: id (link), branch, supplier, net_amount, status, due_date
  RecordActions: [View, DownloadImportSpreadsheetAction, Delete (Adm)]
  ToolbarActions: [BulkActionGroup → [DeleteBulk]]  # Adm
```

**Create page:** após `StartImportBatchAction`, redirect View com polling/refresh (Livewire `wire:poll` leve ou botão “Atualizar”) até status terminal.

### 13.3 Policies

| Policy | viewAny / view | create | update | delete |
|--------|----------------|--------|--------|--------|
| `ImportTemplatePolicy` | Adm | Adm | Adm | Adm |
| `ImportBatchPolicy` | Operador, Adm | Operador, Adm | — / Adm restore | Adm |

Registrar em `AppServiceProvider` morph map **não** necessário (sem morph). Gate::policy.

### 13.4 Widgets

Nenhum obrigatório na F5.

### 13.5 Livewire custom

Não necessário — Filament FileUpload + RelationManagers bastam. Polling opcional na View.

---

## 14. Fluxos

### 14.1 Fluxo principal — template (RF025)

1. Adm cria `ImportTemplate` (metadados + mappings iniciais) → v1 current.
2. Adm pode republicar mappings → v2 current; v1 permanece para batches antigos.
3. Template aparece no select de import se `is_active` e não trashed.

### 14.2 Fluxo principal — importação (RF026)

1. Operador/Adm abre Create ImportBatch; escolhe template (versão current); envia arquivo.
2. `StartImportBatchAction`: valida policy, MIME/formato vs template, grava arquivo, snapshot mappings, status `Pending`, dispatch `ProcessImportBatchJob`.
3. Job → `Processing` → lê header → para cada linha:
   - Resolve campos / valida CPF-CNPJ / filial / monetário / bank.
   - Sucesso → `PaymentRequestService::create` → status `Requested` + event → route aprovação.
   - Falha → `ImportBatchError`.
4. Atualiza contadores → `Completed` → `PaymentRequestBatchImported` → notify + log.
5. Usuário vê relatório de erros + RelationManager de PRs criadas.

### 14.3 Fluxos alternativos

| Cenário | Comportamento |
|---------|---------------|
| Arquivo ilegível / MIME errado | Upload rejeitado **ou** batch `Failed` + `failure_reason` |
| Template sem campos obrigatórios | Bloqueia publish; se version legada inconsistente, `Failed` estrutural |
| Zero linhas de dados | `Failed` / `emptySpreadsheet` |
| Linha: CPF/CNPJ inválido | Erro na linha (RNF007) |
| Linha: fornecedor inexistente | Erro na linha |
| Linha: filial fora do escopo do usuário | Erro na linha (`resolveBranchFor`) |
| Linha: CC/apropriação inválidos | Erro na linha |
| Linha: boleto | Erro `boletoNotSupportedInBatch` |
| Linha: discount > gross | Erro (assertAmounts) |
| Duplicata intra-lote | Erro na 2ª linha |
| Nenhuma regra de aprovação | PR criada; route loga warning (F4); UI “sem regra” — **não** é erro de import |
| Job exception | `Failed`; tries; `failed()` marca batch |
| Cliente tenta acessar Resource | 403 Policy |

---

## 15. Integrações

- **Nenhuma API externa.**
- Reuso interno: `PaymentRequestService`, `ApprovalService` (via event), Rules CPF/CNPJ, OpenSpout (após aprovação de dep).
- Storage S3/local conforme ambiente (RNF012).

---

## 16. Considerações

### 16.1 Segurança

- Policies por role; Cliente sem import.
- Arquivo private; download autenticado.
- Validação server-side em toda linha (não confiar só no Form).
- Snapshot + FK de versão impedem “troca de mapping” pós-fato.
- `raw_values` no erro: mesmo dado que o usuário enviou; acesso só Operador/Adm.

### 16.2 Performance

- Streaming OpenSpout; limite 500 linhas; queue; sem cache prematuro.

### 16.3 Escalabilidade

- Se lotes crescerem (F7/F8), fila dedicada `imports` e raise `max_rows` sem mudar schema.
- F6 pode ligar anexos às PRs do batch via `import_batch_id`.

### 16.4 Trade-offs e riscos

| Tema | Trade-off | Mitigação |
|------|-----------|-----------|
| Dep OpenSpout | Exige aprovação | Já no lock via Filament; promover a direto |
| Sem boleto em lote | Menos cobertura MVP | Mensagem clara; F6 / create unitário |
| Sem Cliente import | Menos self-service | Alinha DRF; Cliente usa form unitário |
| Always queue | Delay segundos | Poll na View; volume baixo |
| Duplicata só intra-lote | Pode reimportar igual | Aceitável; unique global seria falso positivo operacional |

---

## 17. Factories e seeders

| Factory | States úteis |
|---------|--------------|
| `ImportTemplateFactory` | `inactive`, `withCompany`, `withBranch` |
| `ImportTemplateVersionFactory` | `current`, `forTemplate` |
| `ImportTemplateMappingFactory` | `forField(ImportTargetField)` |
| `ImportBatchFactory` | `pending`, `processing`, `completed`, `failed`, `withFile` (Storage::fake) |
| `ImportBatchErrorFactory` | `forRow(int)` |

**Seeder (dev):** `ImportTemplateSeeder` — 1 template Deposit/Pix ilustrativo com mappings mínimos + v1 (não produção obrigatória).

Toda Model nova com `--factory` (e seeder do template).

---

## 18. Testes planejados (Pest) — mapeados a aceite

### Feature — RF025

- Adm cria template + v1 com mappings obrigatórios → OK.
- Operador/Cliente 403 no CRUD template.
- Publish v2: `is_current` migra; v1 permanece; batch antigo aponta v1.
- Publish sem `supplier_document` / branch → `templateMissingRequiredMappings`.
- Soft-delete template remove do select de import; batch histórico View OK.

### Feature — RF026

- Upload válido + job → PRs `Requested` com filial correta + `import_batch_id`.
- Linha inválida + linha válida → `success_count=1`, `error_count=1`, PR só da válida (modo parcial).
- Relatório RelationManager / errors contém `row_number` + message.
- `ProcessImportBatchJob` dispatched (`Queue::fake`) e processa (`Bus::dispatchSync` em teste).
- CPF/CNPJ inválido → erro linha; válido inexistente → `supplierNotFound`.
- Cliente branch: Operador importa OK; se testar ator Cliente no start → 403.
- Filial fora do escopo do ator (simular Operador? Operador sees all — usar cenário com branch inativa) / branch inativa → erro linha.
- Boleto na linha → erro batch-specific; zero PR boleto.
- Arquivo xlsx com template csv → Failed estrutural.
- Event `PaymentRequestBatchImported` + Notification fake ao Completed.
- Cada PR criada dispara route (Approval Pending **ou** log sem regra) — `Event::fake` parcial / assert Approval count.
- Soft-delete / forceDelete batch + limpeza arquivo (`Storage::fake`).

### Unit

- Parse monetário / data.
- `ImportTargetField` required set vs defaults.
- Idempotência job em batch Completed.

---

## 19. Matriz de rastreabilidade

| Requisito | Seção |
|-----------|-------|
| RF025 / RN025.1–2 | §2, §3.3, §4.1–4.3, §13.1 |
| RF026 / RN026.1–3 | §3.2, §3.7, §4.4–4.5, §14, §18 |
| RNF001–003, 006–007, 009–012 | §2.2, §6–12 |
| Hook F4 `ApprovalService::route` | §2.4, §8.2, §9.2 |
| Critérios de aceite | §2.1, §18 |
| API REST NÃO | §2.3 |
| Fora de escopo F6–F8 | §2.4 |

---

## 20. Assunções documentadas

| # | Assunção |
|---|----------|
| A1 | Sem API REST (padrão F1–F4) |
| A2 | Modo **parcial** (RN026.1) |
| A3 | Versionamento via `ImportTemplateVersion` imutável + SoftDeletes no template lógico; mappings hard-owned (sem SoftDeletes) |
| A4 | OpenSpout como reader — **requer aprovação** para require direto |
| A5 | Processamento **sempre** em job |
| A6 | Cliente **não** importa; Adm configura template; Operador/Adm importam |
| A7 | Boleto **não** suportado no lote F5 |
| A8 | Sem criação automática de Supplier |
| A9 | Duplicidade só detectada intra-lote |
| A10 | Header na linha 1; `source_column` = nome do header |
| A11 | Route de aprovação só via `PaymentRequestCreated` (não chamar `route()` no import) |
| A12 | Arquivo da planilha **não** é morph Attachment |
| A13 | max 5 MB / 500 linhas (configurável) |

---

## 21. Schema / migrations (consolidado pós-revisão DBA)

Ordem de migrations:

1. `create_import_templates_table`
2. `create_import_template_versions_table` (+ unique full version + unique parcial `is_current`)
3. `create_import_template_mappings_table` (+ uniques full target / source_column)
4. `create_import_batches_table`
5. `create_import_batch_errors_table`
6. `add_import_batch_id_to_payment_requests_table`

**Regras transversais:**

- Helper privado `supportsPartialIndexes(): bool` = `in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)` — padrão F1–F4.
- Unique parcial: **nunca** `->unique()` no Blueprint quando SoftDeletes; só `DB::statement` + `DROP INDEX IF EXISTS` no `down()`.
- Monetários **não** nas tabelas de import (só nas PRs via `PaymentRequestService`).
- Status / format / target_field = `string` — **`$table->enum()` PROIBIDO**.
- Tamanhos confirmados: `accepted_format` 10 · `status` 20 · `target_field` 60 · `message` 500 · `path` 500.

### 21.1 `import_templates`

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
// down: DROP INDEX IF EXISTS import_templates_name_unique;
```

### 21.2 `import_template_versions`

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

### 21.3 `import_template_mappings`

```php
Schema::create('import_template_mappings', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('import_template_version_id')->index()
        ->constrained('import_template_versions')->cascadeOnDelete();
    $table->string('source_column', 120)->nullable(); // null = default-only mapping
    $table->string('target_field', 60); // ImportTargetField
    $table->string('default_value', 255)->nullable();
    $table->unsignedInteger('sort_order')->default(0); // sem índice (volume baixo)
    $table->timestampTz('created_at')->useCurrent(); // sem updated_at; sem softDeletes

    $table->unique(['import_template_version_id', 'target_field']);
    // Postgres: NULL is distinct — multiple (version_id, NULL) source_column rows allowed
    $table->unique(['import_template_version_id', 'source_column']);
});
```

### 21.4 `import_batches`

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

### 21.5 `import_batch_errors`

```php
Schema::create('import_batch_errors', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('import_batch_id')->index()
        ->constrained('import_batches')->cascadeOnDelete(); // hard only
    $table->unsignedInteger('row_number');
    $table->string('target_field', 60)->nullable();
    $table->string('message', 500);
    $table->json('raw_values')->nullable();
    $table->timestampTz('created_at')->useCurrent(); // append-only

    $table->index(['import_batch_id', 'row_number']);
});
```

### 21.6 Alter `payment_requests`

```php
Schema::table('payment_requests', function (Blueprint $table): void {
    // No after() — Postgres ignores column order (F4 DBA)
    $table->foreignUuid('import_batch_id')->nullable()->index()
        ->constrained('import_batches')->nullOnDelete();
});
```

**Conflitos F3/F4:** coluna nova apenas; não mexe em `status`, bank_details, approvals, attachments, history.

### 21.7 Matriz FK (forceDelete)

| FK | On delete | Motivo |
|----|-----------|--------|
| templates.company_id / branch_id | `nullOnDelete` | Alvo opcional |
| versions.import_template_id | `cascadeOnDelete` | Filho hard-owned; soft template **não** cascata |
| mappings.import_template_version_id | `cascadeOnDelete` | Hard-owned |
| batches.import_template_version_id | `restrictOnDelete` | Auditoria — version não some com lotes |
| errors.import_batch_id | `cascadeOnDelete` | Hard-owned append-only |
| payment_requests.import_batch_id | `nullOnDelete` | PR sobrevive ao force do lote |
| `*_by` → users | `nullOnDelete` | Padrão blameable |

---

## 22. Checklist de implementação

- [ ] Aprovar dep `openspout/openspout` em `composer.json`
- [ ] Config `rjet.imports.*`
- [ ] Enums `ImportFileFormat`, `ImportBatchStatus`, `ImportTargetField` + i18n
- [ ] Migrations §21 (uniques parciais name/`is_current`; mappings sem SoftDeletes; alter PR sem `after()`)
- [ ] Models + Factories (`UPDATED_AT = null` em Version/Mapping/Error) + `import_batch_id` em PaymentRequest
- [ ] `ImportException`
- [ ] DTOs + `ImportTemplateService` + `ImportBatchService` + SpreadsheetReader
- [ ] Actions `Import/*`
- [ ] Job `ProcessImportBatchJob`
- [ ] Event + Listeners + Notification
- [ ] Policies + Filament Resources Blueprint §13
- [ ] Seeder opcional
- [ ] Pest §18
- [ ] Pint + checklists `.ai/checklists.md` (Model, Migration, Job, Event, Policy, Factory)

---

## 23. Próximos passos / Handoff

1. Revisar com stakeholders (especialmente A2 parcial, A6 Cliente, A7 boleto, A4 OpenSpout).
2. ~~Encaminhar a `dba` para revisar schema §4 / §21~~ → **`dba` concluído** (2026-09-26) — ver §24; schema incorporado.
3. ~~`/blueprint` (Filament)~~ → gravado em [.ai/blueprints/fase-5-lote-solicitacoes.md](../blueprints/fase-5-lote-solicitacoes.md) (2026-09-26). Próximo: **`implementer`** na ordem do checklist §22.
4. Tester: cobrir §18 com Pest.
5. Não iniciar F6 até PRs de lote terem `import_batch_id` estável (hook anexos).

---

## 24. Revisão DBA

> **Revisor:** dba (subagent) · **Data:** 2026-09-26 · **Driver:** PostgreSQL  
> **Escopo:** schema Fase 5 (§4 / §3.1 / §10 / §12 / §21): `import_templates`, `import_template_versions`, `import_template_mappings`, `import_batches`, `import_batch_errors`, `payment_requests.import_batch_id`; FKs/cascades; SoftDeletes; uniques parciais; índices; alinhamento F1–F4.  
> Guidelines: `database.md`, `enums.md`, `soft-deletes.md`, `performance.md`, `PROJECT.md`. Padrão de saída alinhado a F4 §25.  
> Inspeção via migrations/Models reais (`payment_requests`, `approvals`, `payment_request_bank_details`, `payment_request_status_history`, helper `supportsPartialIndexes()`).

### Veredito

**Aprovado com ressalvas.** Modelagem em 3NF (snapshot JSON + contadores como desnormalizações documentadas), entidades dedicadas corretas (não morph), enums como `string` + cast PHP, SoftDeletes coerentes com auditoria de lote/versão, FKs explícitas (`restrict` na version do batch; `nullOnDelete` no vínculo PR). Nenhum achado exige rejeição ou redesenho. Correções abaixo já incorporadas em §3, §4, §10, §12 e §21.

### O que está correto

| Item | Avaliação |
|------|-----------|
| Entidades dedicadas (template/version/mapping/batch/errors); arquivo inline no batch; sem morph Attachment | ✅ |
| `payment_requests.import_batch_id` nullable + `nullOnDelete`; sem conflito com colunas F3/F4 | ✅ |
| Status/format/target como `string`; sem `$table->enum()`; sem float monetário nas tabelas de import | ✅ |
| `import_template_version_id` no batch → `restrictOnDelete` | ✅ |
| Contadores + `mappings_snapshot` desnormalizados com justificativa | ✅ |
| `ImportBatchError` append-only (`created_at` only, sem SoftDeletes) | ✅ |
| Volume ~70/dia / teto 500 linhas — sem cache de templates; sem GIN no JSON | ✅ |
| Ordem migrations templates → versions → mappings → batches → errors → alter PR | ✅ |
| Tamanhos `accepted_format(10)` / `status(20)` / `target_field(60)` / `message(500)` / `path(500)` | ✅ batem com enums e padrão projeto |

### Achados (Crítico / Importante / Sugestão)

| # | Nível | Achado | Impacto | Correção aplicada |
|---|-------|--------|---------|-------------------|
| 1 | **Crítico** | SoftDeletes em `ImportTemplateMapping` ambíguo (“só em draft” vs versão published imutável); unique parcial com `deleted_at` sem draft real. | Implementer cria SoftDeletes + uniques parciais desnecessários; risco de editar mapping in-place. | §3.3/§4.3/§12: **sem SoftDeletes** em mappings; hard-owned pela version; uniques **full**; create/publish = inserts. |
| 2 | **Crítico** | Unique parcial `is_current` / `name` sem explicitar helper `supportsPartialIndexes()` + `DROP INDEX IF EXISTS` no `down()`. | Divergência F1–F4; `down()` incompleto; SQLite de teste. | §21: helper + snippets `up`/`down`; proibição de `->unique()` no Blueprint para SoftDeletes. |
| 3 | Importante | `ImportTemplateVersion` sem `updated_at` não documentava `UPDATED_AT = null` / `$timestamps = false`. | Model geraria `updated_at` inexistente. | §4.2/§21.2: padrão history/reassignment. |
| 4 | Importante | Unique `(version_id, source_column)` com `source_column` nullable sem documentar semântica NULL do Postgres. | Dúvida se mappings só-default quebram unique. | §4.3/§21.3: NULL distinto — múltiplos defaults OK; cobrir em teste. |
| 5 | Importante | Soft vs `cascadeOnDelete`: texto original misturava expectativas de cascade soft em versions/errors. | Observer/Service errado; versions sumindo ou errors órfãos mal tratados. | §12 matriz explícita; soft template/batch **não** cascata; force batch cascata errors + null PR. |
| 6 | Importante | Índices implícitos em `is_active` / `sort_order` / possível GIN em JSON. | Over-indexing (performance.md / RNF011). | §10: lista do que **não** indexar; sem GIN. |
| 7 | Sugestão | Unique `(import_template_id, version)` como parcial vs full. | Reuso de número após soft-delete confundiria auditoria. | Unique **full** — número nunca reutilizado. |
| 8 | Sugestão | Alter PR com eventual `after()`. | Ruído Postgres (F4 #3). | §21.6: **sem** `after()`. |

### Checklist DBA (resumo)

| Área | Resultado |
|------|-----------|
| Normalização 3NF | ✅ (snapshot / contadores documentados) |
| Morph vs dedicado | ✅ todos dedicados; arquivo no batch |
| Campos (`is_`, `_at`, `_by`, `sort_order`, decimal só em PR) | ✅ |
| Enums (`string`, sem `$table->enum()`) | ✅ |
| UUID + timestampsTz + softDeletesTz | ✅ (Version/Mapping/Error sem `updated_at`; Mapping/Error sem soft) |
| Unique parcial name / is_current | ✅ (com guard F1) |
| Unique mappings + NULL source_column | ✅ (Postgres NULL distinct) |
| FK index explícito (Postgres) | ✅ |
| Cascade soft vs FK onDelete | ✅ documentado §12 / §21.7 |
| Append-only ImportBatchError | ✅ |
| Índices só WHERE/ORDER BY | ✅ sem GIN / sem is_active / sem sort_order |
| Conflitos Fases 3–4 | ✅ só adiciona `import_batch_id` |

### Decisões DBA confirmadas (2026-09-26)

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | SoftDeletes em mappings? | **Não** — hard-owned pela version |
| 2 | Versão “draft” editável? | **Não** — create/publish sempre imutável |
| 3 | Unique parcial `is_current` / `name`? | Helper `supportsPartialIndexes()` + DROP no `down()` |
| 4 | Unique `(template_id, version)`? | **Full** (nunca reutilizar número) |
| 5 | `source_column` NULL + unique? | OK no Postgres (NULL distinto); documentar + testar |
| 6 | Índice GIN em `mappings_snapshot`? | **Não** no MVP |
| 7 | Indexar `is_active` / `sort_order` / `accepted_format`? | **Não** |
| 8 | `import_batch_id` onDelete? | **`nullOnDelete`** |
| 9 | Version referenciada por batch? | FK **`restrictOnDelete`** + Service `versionInUse` no soft |
| 10 | `after()` no alter PR? | **Não** |

### Ajustes já aplicados no doc

- Header Status → revisão DBA concluída; pronto para `/blueprint` ou `implementer`
- §3.1 / §3.3 / §4 / §10 / §12 alinhados
- §21 expandido com snippets + matriz FK
- §23 handoff: dba marcado ✅
- RN025.2 texto alinhado (sem soft delete em mapping)

### Notas para implementer

1. Copiar helper `supportsPartialIndexes()` de `create_payment_request_bank_details_table` (ou `approvals`).
2. Models `ImportTemplateVersion`, `ImportTemplateMapping`, `ImportBatchError`: `public const UPDATED_AT = null;` (+ `$timestamps = false` onde só há `created_at`).
3. Soft-delete template **não** soft-deleta versions; select de import filtra via template.
4. Soft-delete version: guardar `versionInUse` se `batches()->exists()`; force bloqueado por `restrictOnDelete`.
5. ForceDelete batch: Observer apaga arquivo do disk; errors saem via FK cascade; PRs ficam com `import_batch_id` null.
6. Publish version: transação — `is_current=false` na anterior + insert vN+1 + insert mappings (nunca update mapping).
7. Testes Pest: unique parcial name/current; múltiplos mappings com `source_column` null; soft batch mantém errors; force batch remove errors + null PR; restrict impede force version com batch; alter PR não quebra F3/F4.
8. Não criar `$table->enum()`; não indexar JSON; não monetário nas tabelas de import.
