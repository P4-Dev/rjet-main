# Arquitetura: Fase 6 — Lote de anexos (nomenclatura padronizada)

> **Escopo:** RF027–RF029  
> **Fora de escopo:** Fases 7–8 (baixa em lote, CNAB, dashboard, Excel). Apenas hooks de eventos. Não redesenhar `PaymentRequest`, workflow de alçadas nem importação de planilha (F5).  
> **Stack:** Laravel 12 · Filament 5 · PHP 8.4 · Pest 4 · Livewire 4 · PostgreSQL · Redis  
> **Fonte:** DRF + arquiteturas F1–F5 + código real de anexos (F3) e lote de importação (F5)  
> **Autor:** architect (subagent)  
> **Data:** 2026-09-26  
> **Status:** Revisão **`dba` concluída** — **Aprovado com ressalvas** (já incorporadas). Blueprint em `.ai/blueprints/fase-6-lote-anexos.md`. Pronto para `implementer`.

---

## 1. Contexto

As Fases 1–5 entregaram cadastros, solicitações com anexos morph (RF015), workflow e importação em lote via planilha. A **Fase 6** adiciona o fluxo operacional de **lote de anexos**: upload múltiplo, classificação ordenada por operadores internos e **nomenclatura padronizada** (`YYYYMMDD_HHMMSS_SEQ.ext`), sem duplicar o storage nem quebrar download/OCR da F3.

| Capacidade já existente | Origem |
|-------------------------|--------|
| `Attachment` morph (`attachable`) + `HasAttachments` + disco private | F3 |
| `AttachmentService` (MIME/size, staging, relocate, orphan cleanup) | F3 |
| `AttachmentType` (`Boleto`, `Other`), `AttachmentException`, `AttachmentPolicy`, `AttachmentObserver` | F3 |
| Config `rjet.attachments.*` + `RJET_ATTACHMENTS_MAX_KB` | F3 (P-ANEXO-SIZE) |
| Padrão de lote operacional (`ImportBatch` + job + Policy Operador/Adm) | F5 |
| Papéis `UserRole` + `isOperador()` / `isAdm()` / `isCliente()` | F1 |

### 1.1 Estado real do código (2026-09-26)

| Artefato | Status |
|----------|--------|
| `App\Models\Attachment` | **Existe** — UUID, SoftDeletes, HasBlameable, morph `attachable`, campos `disk`/`path`/`original_name`/`mime_type`/`size`/`sort_order`/`type`; **sem** coluna de nome padronizado |
| `HasAttachments` | **Existe** — só usado em `PaymentRequest` |
| `AttachmentService` | **Existe** — `storeUploadedFile`, `storeManyFromPaths`, MIME/size via `config('rjet.attachments.*')` |
| `AttachmentsRelationManager` (PR) | **Existe** — FileUpload staging, `reorderable('sort_order')` |
| Models `AttachmentBatch` / `AttachmentBatchItem` / histórico | **Não existem** |
| Events `AttachmentBatchClassified`, `AttachmentRenamed` | **Não existem** (DRF §6 TBD) |
| Job de rename de anexos | **Não existe** (existe `ProcessImportBatchJob` na F5) |
| Morph map (`AppServiceProvider`) | `supplier`, `payment_request`, `user` — **sem** `attachment_batch` |
| Painel Filament | Único `admin`; groups: `registrations`, `operations`, `settings`, `reports`, `main` |
| Grupo `operations` | `PaymentRequest` (sort 1), `ImportBatch` (sort 15) |
| Activity log Spatie / tabela genérica de activity | **Não** no domínio financeiro (histórico F3/F4 é dedicado por entidade) |
| `.ai/rules/` | Ausente nesta cópia — convenções de PROJECT.md + `.ai/docs` + F1–F5 |
| Blueprint Filament vendor | Pacote blueprint **não** presente no vendor desta cópia; estrutura obrigatória segue `.ai/skills/filament/SKILL.md` |

### 1.2 Preferências de projeto aplicadas

- Documento em **pt-BR**; classes, namespaces, tabelas e colunas em **inglês**.
- `agrupar_por_dominio`: Jobs, Events, Actions, Listeners **agrupados**; Services/DTOs/Models **flat**.
- Comentários no código (quando houver): mínimo, em inglês.
- Timezone de negócio: `America/Sao_Paulo` (`PROJECT.md`).

---

## 2. Requisitos

### 2.1 Funcionais (Fase 6)

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| RF027 | Upload múltiplo de PDF/imagens em lote rastreável | `AttachmentBatch` + itens + `Attachment` |
| RF028 | Classificação ordenada por Operador; histórico; bloqueio se incompleto | Itens + histórico + Action concluir |
| RF029 | Nomenclatura `YYYYMMDD_HHMMSS_SEQ.ext`; rename no storage; eventos | Job + `standardized_name` |

**Regras de negócio:**

| RN | Conteúdo | Decisão nesta arquitetura |
|----|----------|---------------------------|
| RN027.1 | Mesmas restrições RF015 (PDF/JPEG/PNG/WebP; S3/private) | Reuso `config('rjet.attachments.*')` — §3.8 |
| RN027.2 | Lote com identificador único | UUID do `AttachmentBatch` |
| RN028.1 | Ordem preservada para nomenclatura | `sort_order` no item → SEQ |
| RN028.2 | Evento `AttachmentBatchClassified` | §8 |
| RN029.1 | Unicidade no lote; colisão com sufixo | §3.4 |
| RN029.2 | Rename no storage; referência ao original | `original_name` imutável + `standardized_name` + `path` |
| RN029.3 | Evento `AttachmentRenamed` | **Por item** + evento de lote ao final — §3.5 / §8 |

**Critérios de aceite (DRF):**

RF027:

- [ ] Upload múltiplo aceita apenas tipos permitidos.
- [ ] Lote listado com status (pendente classificação, concluído, etc.).

RF028:

- [ ] Operador reordena e classifica itens do lote.
- [ ] Classificação incompleta impede geração de nomenclatura final.
- [ ] Histórico registra operador e timestamp de **cada** classificação.

RF029:

- [ ] Nomes no padrão `YYYYMMDD_HHMMSS_SEQ.ext`.
- [ ] Download/listagem exibe nomenclatura padronizada.
- [ ] Renomeação não corrompe vínculo com solicitação quando associada.

### 2.2 Não-funcionais aplicáveis

| RNF | Aplicação na F6 |
|-----|-----------------|
| RNF001 | UUID, `timestampsTz` / `softDeletesTz`, `created_by`/`updated_by` (HasBlameable) |
| RNF003 | Status/destino como `string` + Enum PHP |
| RNF004 | Morph `attachments` reutilizado para vínculo final com PR |
| RNF006 | Policies: lote = Operador/Adm; Cliente não classifica; disco private |
| RNF009 | Rename N arquivos S3 em **job** |
| RNF010 | `AttachmentBatchClassified`, `AttachmentRenamed` (+ lote) |
| RNF011 | ~70 pagamentos/dia — lotes moderados; sem motor genérico |
| RNF012 | Disco private (local/S3); sem URL pública |

### 2.3 API REST

> **Decisão fechada: NÃO nesta fase.** Produto é Filament interno BPO. Não há API REST de anexos no código. Sem Sanctum/Passport/Swagger na F6. Revisar só se surgir consumidor externo.

### 2.4 Fora de escopo (hooks apenas)

| Item | Fase | Hook na F6 |
|------|------|------------|
| Baixa em lote / CNAB | 7 | Eventos de rename disponíveis; sem tela de baixa |
| Dashboard / Excel | 8 | Contadores/status do lote listáveis depois |
| Redesign PR / alçadas / ImportBatch | — | **Não** |
| OCR de boleto | F3 | Se item → PR + tipo Boleto PDF, OCR continua via fluxo F3 existente após rebind (sem automação extra na F6) |
| API REST | — | Assunção NÃO |

**Hook F3 honrado:** um único registro `attachments` por arquivo; após classificação com destino `PaymentRequest`, o morph é **rebound** (não se cria segundo anexo). Download F3 permanece via `path` + Policy do attachable.

**Hook F5:** não reutilizar `ImportBatch` nem jobs de planilha; apenas o **padrão** (lote dedicado + status + job + Policy Operador/Adm + group `operations`).

---

## 3. Decisões de Design

### 3.1 Normalização e entidades compartilhadas

| Candidato | Decisão | Justificativa (matriz database.md) |
|-----------|---------|-------------------------------------|
| **AttachmentBatch** | **Dedicado** `attachment_batches` | Core operacional; FKs/status/contadores; queries por status — análogo a `ImportBatch` |
| **AttachmentBatchItem** | **Dedicado** `attachment_batch_items` | Estrutura específica (ordem, destino, status de rename); FK crítica ao batch e ao `Attachment` |
| **Histórico de classificação** | **Dedicado** `attachment_batch_item_classifications` | Append-only; DRF exige cada classificação; não SoftDeletes (como logs / `ImportBatchError`) |
| **Arquivo binário + metadados de storage** | **Reuso** morph `attachments` | Já é o padrão F3; estrutura idêntica; auxiliar; evita segundo storage |
| **Destino da classificação** | Enum + FKs nullable + label | Menor modelo que cobre PR / fornecedor / categoria operacional **sem** tabela especulativa de “categoria” |

**Rejeitado — reuso cego só de `attachments` como “lote”:** lote precisa de status agregado, conclusão de classificação, job de rename e histórico; misturar isso em `attachments` poluiria RF015 e o RelationManager da PR.

**Rejeitado — item só com path sem `Attachment`:** duplicaria colunas de storage e forçaria “promover” para `Attachment` depois, com risco de dois paths. Preferência: **1 arquivo = 1 `Attachment` desde o upload**.

**Fluxo de vínculo do morph:**

1. Upload → `Attachment.attachable` = `AttachmentBatch` (batch usa `HasAttachments`).
2. Classificação → metadados no **item** (destino).
3. Após rename bem-sucedido, se destino = `PaymentRequest` → **rebind** `attachable` para a PR + `Storage::move` para diretório da PR (`AttachmentService::directoryFor`), mesma linha `attachments`.
4. Se destino = `Supplier` ou `OperationalCategory` → `Attachment` **permanece** no batch (Supplier **não** tem `HasAttachments` hoje; inventar morph em Supplier estaria fora do escopo mínimo).

**Estratégia de destino (única, fechada):**

| Campo no item | Uso |
|---------------|-----|
| `destination_type` | Enum: `payment_request` \| `supplier` \| `operational_category` |
| `payment_request_id` | FK nullable — obrigatória se type = payment_request |
| `supplier_id` | FK nullable — obrigatória se type = supplier |
| `operational_label` | `string(120)` nullable — obrigatória se type = operational_category (rótulo livre operacional; **sem** model novo) |

**Integridade do destino (fechada — DBA 2026-09-26):** exatamente um alvo coerente com `destination_type` (PR **ou** Supplier **ou** `operational_label`). Validação **somente no Service/Action** (`AttachmentException::invalidDestination()`). O repositório **não** usa `CHECK` SQL multi-coluna em nenhuma migration existente — **não inventar** CHECK na F6.

**Desnormalizações intencionais** (comentário obrigatório em inglês na migration — `database.md`):

1. Contadores no batch: `items_count`, `classified_count`, `renamed_count`, `failed_count` — cache de UI; **não** são fonte de verdade (recalcular via `items()` se divergir).
2. `classified_by` / `classified_at` no **item** = última classificação (UI rápida); histórico completo na tabela append-only.
3. `standardized_name` em `attachments` — nome exibido pós-RF029; `original_name` permanece o nome de upload (imutável).

### 3.2 Status do lote e do item

**Banco = `string` + Enum PHP** (`HasLabel`, `HasColor`, `HasIcon`).

#### Lote — `AttachmentBatchStatus`

| Valor | Significado |
|-------|-------------|
| `pending_classification` | Upload ok; aguarda classificação completa |
| `classified` | Operador concluiu classificação; pronto/aguardando job |
| `renaming` | Job de nomenclatura em execução |
| `renamed` | Todos os itens renomeados com sucesso |
| `partially_failed` | Job terminou com ≥1 item `failed` e ≥1 `renamed` |
| `failed` | Falha estrutural (ex.: lote vazio, exceção não recuperável, zero itens renomeáveis) |

Transições:

```
pending_classification → classified | failed
classified → renaming
renaming → renamed | partially_failed | failed
```

Terminais: `renamed`, `partially_failed`, `failed`. Reabrir classificação **só** enquanto `pending_classification` (após `classified`, reclassificar exige cancelamento operacional — MVP: **não** reabrir; novo lote se necessário). Itens podem ser reclassificados **antes** de `concludeClassification`.

#### Item — `AttachmentBatchItemStatus`

| Valor | Significado |
|-------|-------------|
| `pending` | Sem destino válido |
| `classified` | Destino preenchido |
| `renamed` | Move + `standardized_name` ok |
| `failed` | Falha de rename (recuperável por retry do lote/itens failed) |

**Bloqueio RF028:** `ConcludeAttachmentBatchClassificationAction` exige **todos** os itens `classified` (destino válido). Caso contrário → `AttachmentException::batchClassificationIncomplete()`.

### 3.3 Papéis e histórico

| Ação | Cliente | Operador | Adm |
|------|:-------:|:--------:|:---:|
| Ver lotes | ❌ | ✅ | ✅ |
| Upload lote | ❌ | ✅ | ✅ |
| Classificar / reordenar / concluir | ❌ | ✅ | ✅ (superset) |
| Soft delete lote | ❌ | ❌ | ✅ |
| Restore / forceDelete | ❌ | ❌ | ✅ |

Alinhado a `ImportBatchPolicy` e DRF (Operador classifica lote de anexos; Adm = tudo do Operador + exclusão).

**Histórico:** tabela `attachment_batch_item_classifications` — **cada** save de classificação (incluindo reclassificação) insere linha com `user_id`, `classified_at`, snapshot do destino (FKs `nullOnDelete`). Colunas `classified_by`/`classified_at` no item = última. Append-only: **sem** SoftDeletes, **sem** `updated_at`. **Não** depender só de overwrite.

### 3.4 Nomenclatura (RF029) — fechada

| Aspecto | Decisão |
|---------|---------|
| Padrão | `{YYYYMMDD}_{HHMMSS}_{SEQ}.{ext}` |
| Data/hora | `now()` no **início do job** de rename do lote (`config('app.timezone')` = `America/Sao_Paulo`); **não** a data do upload |
| SEQ | Inteiro 1-based na ordem crescente de `sort_order` do item; **zero-padded 3** (`001`…`999`). Se `items_count > 999`, padding dinâmico `max(3, strlen((string) count))` |
| Extensão | Derivada do `mime_type` (mapa fixo); se divergir do `original_name`, **vence o mime**. Fallback: `pathinfo(original_name, PATHINFO_EXTENSION)` lowercased |
| Coluna | `attachments.standardized_name` (nullable até rename) |
| `original_name` | **Nunca** sobrescrito |
| Storage | `Storage::disk($disk)->move($old, $new)` + update `path` + `standardized_name` na **mesma transação DB** após move ok; se update falhar após move, compensar move de volta ou marcar item `failed` + log (ver fluxo §15) |
| Disco | `config('rjet.attachments.disk')` — private (RN015.3) |
| Unicidade | Unique composto sugerido: `(attachment_batch_id via item, standardized_name)` no nível de negócio; no disco, path inclui UUID do batch ou da PR |

**Colisão (RN029.1):**

1. Nome base `B = Ymd_His_SEQ` (SEQ já único no lote → colisão de *nome lógico* no lote é rara).
2. Path alvo no diretório destino; se `exists` **ou** outro `attachments.path` no mesmo disk aponta para o mesmo path → sufixo `_2`, `_3`, … antes da extensão: `B_2.ext`.
3. Máx. 99 sufixos; depois `AttachmentException::namingCollisionUnresolved()`.
4. Colisão entre lotes no mesmo segundo: diretórios distintos (`attachments/attachment_batch/{id}/` vs `attachments/payment_request/{id}/`) — paths não colidem.

**Exibição:** UI usa `standardized_name ?? original_name` (accessor `displayName()` no Model).

### 3.5 Eventos e fila — fechada

| Evento | Quando | Listener principal |
|--------|--------|-------------------|
| `AttachmentBatchClassified` | Após commit de `status = classified` | **Queue** `RenameAttachmentBatchJob` (um job **por lote**) |
| `AttachmentRenamed` | **Por item** após move+DB ok | Log estruturado; sync flags já via Observer no rebind |
| `AttachmentBatchRenamed` | Job finaliza com todos itens `renamed` (ou emite também em `partially_failed` com flag?) | Log + notificação opcional ao `created_by` |

**Por que um job por lote (não por item):** menos overhead de fila; ordem SEQ estável; um `WithoutOverlapping(batchId)`; falha parcial marcada por item (`failed`) e batch `partially_failed` — recuperável com retry só dos failed.

**Listener de vínculo:** dentro do próprio Service de rename (`rebindToPaymentRequestIfNeeded`) — **não** criar novo `Attachment`. Event `AttachmentRenamed` não deve chamar `storeUploadedFile` de novo.

### 3.6 API REST

**Não** nesta fase (§2.3).

### 3.7 Limites de arquivo (RN027.1 / RN015.4 / P-ANEXO-SIZE)

| Parâmetro | Fonte real |
|-----------|------------|
| MIME | `config('rjet.attachments.accepted_mime_types')` — PDF, JPEG, PNG, WebP; **XML fora** |
| Tamanho | `config('rjet.attachments.max_kilobytes')` ← `RJET_ATTACHMENTS_MAX_KB` (default 10240) |
| Qtd. no FileUpload do batch | Reusar `max_files` da mesma config (default 10) no MVP; se operação precisar de lotes maiores, só elevar env — **não** criar segundo limite paralelo na F6 |

### 3.8 Livewire vs Filament puro

- Upload Create: `FileUpload` **multiple** Filament, mesmo disco/MIME/size da F3 — **suficiente**.
- Classificação com dezenas de arquivos + preview (PDF/imagem) + drag-and-drop: RelationManager + `reorderable` **pode** bastar para MVP pequeno; para UX de classificação rica, especificar Livewire dedicado — §14.

---

## 4. Models

> **Convenções DBA (F1–F5):** PK UUID; FKs com `->index()` explícito (Postgres); `timestampsTz` / `softDeletesTz`; blameable `nullOnDelete`; status/destino = `string` (**nunca** `$table->enum()`); unique parciais via `DB::statement` + helper `supportsPartialIndexes()` (pgsql+sqlite) + `DROP INDEX IF EXISTS` no `down()` — **nunca** `->unique()` no Blueprint quando SoftDeletes; **sem** `after()` em alters (Postgres ignora).

### 4.1 Extensão `Attachment` (existente — a alterar)

| Campo | Tipo | Notas |
|-------|------|-------|
| *(existentes)* | — | Sem mudança de significado (`original_name` **imutável**) |
| `standardized_name` | `string(255)` nullable | **Novo** — nome RF029; **sem** unique global; **sem** índice (não é filtro/ORDER BY frequente) |

- **Traits:** inalterados (`HasUuid`, `SoftDeletes`, `HasBlameable`, `HasFactory`).
- **Accessor:** `displayName(): string` → `standardized_name ?? original_name`.
- **Casts:** inalterados + nenhum cast especial para o novo campo.
- **Alter:** só adiciona a coluna; **sem** `after()`; **não** recria a tabela morph.

### 4.2 `AttachmentBatch` (**a criar**)

| Campo | Tipo SQL | Notas |
|-------|----------|-------|
| `id` | uuid PK | |
| `status` | string(**30**) index | `AttachmentBatchStatus`, default `pending_classification` (23 chars — **mín. 30**) |
| `items_count` | unsignedInteger default 0 | Desnormalizado — comentário na migration |
| `classified_count` | unsignedInteger default 0 | idem |
| `renamed_count` | unsignedInteger default 0 | idem |
| `failed_count` | unsignedInteger default 0 | idem |
| `failure_reason` | text nullable | Falha estrutural / resumo |
| `classified_at` | timestampTz nullable | Conclusão RF028 |
| `renaming_started_at` | timestampTz nullable | |
| `renamed_at` | timestampTz nullable | Término job (sucesso parcial ou total) |
| `naming_generated_at` | timestampTz nullable | `now()` fixado no job (fonte do prefixo) |
| `created_by` / `updated_by` | foreignUuid nullable → users nullOnDelete + index | HasBlameable |
| timestampsTz / softDeletesTz | | |

- **Traits:** `HasFactory`, `HasUuid`, `SoftDeletes`, `HasBlameable`, **`HasAttachments`** (batch **não** precisa coluna `has_attachments` — trait no-ops se coluna ausente).
- **Casts:** status enum; timestamps datetime.
- **Scopes:** `scopeVisibleTo(User)` — Operador/Adm veem tudo; Cliente → `whereRaw('1=0')` (igual ImportBatch).
- **Helpers:** `isPendingClassification()`, `isTerminal()`, `allItemsClassified(): bool`.
- **Cascade soft:** Observer/Service soft-deleta **items**; Attachments só se ainda `attachable` = batch (ver §11). `cascadeOnDelete` na FK do item **não** dispara no soft delete do batch.

### 4.3 `AttachmentBatchItem` (**a criar**)

| Campo | Tipo SQL | Notas |
|-------|----------|-------|
| `id` | uuid PK | |
| `attachment_batch_id` | foreignUuid index → batches `cascadeOnDelete` | Cascade **só** no forceDelete do batch |
| `attachment_id` | foreignUuid index → attachments `restrictOnDelete` | 1:1 ativo; unique **parcial** (abaixo) |
| `sort_order` | unsignedInteger default 0 | Ordem de nomenclatura — **sem** índice sozinho |
| `status` | string(**20**) index | `AttachmentBatchItemStatus` (`pending`/`classified`/`renamed`/`failed`) |
| `destination_type` | string(**30**) nullable index | Enum; null enquanto pending; cobre `operational_category` (21) |
| `payment_request_id` | foreignUuid nullable index `nullOnDelete` | |
| `supplier_id` | foreignUuid nullable index `nullOnDelete` | |
| `operational_label` | string(120) nullable | |
| `classified_by` | foreignUuid nullable index → users `nullOnDelete` | Última |
| `classified_at` | timestampTz nullable | Última |
| `rename_error` | string(500) nullable | Mensagem interna curta / chave |
| `renamed_at` | timestampTz nullable | |
| `created_by` / `updated_by` | foreignUuid nullable index `nullOnDelete` | HasBlameable |
| timestampsTz / softDeletesTz | | SoftDeletes no item (domínio) |

**Unique `attachment_id` (fechado — DBA):** índice **parcial** `WHERE deleted_at IS NULL` via `supportsPartialIndexes()` — **nunca** `$table->unique('attachment_id')` no Blueprint (SoftDeletes ocuparia a unique após soft delete). `down()`: `DROP INDEX IF EXISTS attachment_batch_items_attachment_id_unique`.

**FK `restrictOnDelete` no Attachment:** impede `forceDelete` do Attachment enquanto existir item (mesmo soft-deleted? — no Postgres a linha ainda existe → restrict bloqueia). Soft delete do Attachment **não** dispara a FK. Soft delete do item **não** apaga o Attachment (regra §11).

**Índice de listagem:** composto `(attachment_batch_id, sort_order)` — query real = `WHERE batch_id = ? ORDER BY sort_order`. **Não** indexar `sort_order` sozinho (F5 / performance.md — over-indexing).

### 4.4 `AttachmentBatchItemClassification` (**a criar** — histórico append-only)

| Campo | Tipo SQL | Notas |
|-------|----------|-------|
| `id` | uuid PK | |
| `attachment_batch_item_id` | foreignUuid index → items `cascadeOnDelete` | OK: filha **sem** SoftDeletes — cascade no force do item |
| `user_id` | foreignUuid nullable index → users `nullOnDelete` | Operador; nullable para sobreviver ao hard delete do user |
| `destination_type` | string(30) | Snapshot |
| `payment_request_id` | foreignUuid nullable index `nullOnDelete` | Snapshot — preferível a uuid solto |
| `supplier_id` | foreignUuid nullable index `nullOnDelete` | Snapshot |
| `operational_label` | string(120) nullable | Snapshot |
| `classified_at` | timestampTz | Único timestamp de evento |

**Sem SoftDeletes. Sem `updated_at`. Sem `created_at` redundante** — só `classified_at` + `id` (padrão append-only F3 history / F5 errors, com nome de domínio).

**Model:** `public $timestamps = false;` + `public const UPDATED_AT = null;` — Service preenche `classified_at`.

### 4.5 Índices (resumo — pós-DBA)

| Tabela | Índice | Não indexar |
|--------|--------|-------------|
| `attachment_batches` | `status`; `(created_by, created_at)`; FKs blameable | Contadores |
| `attachment_batch_items` | `(attachment_batch_id, sort_order)`; `status`; `destination_type`; unique **parcial** `attachment_id`; FKs com `->index()` | `sort_order` sozinho |
| `attachment_batch_item_classifications` | `(attachment_batch_item_id, classified_at)`; FKs | — |
| `attachments` | existentes F3 | `standardized_name` (sem unique, sem índice MVP) |

### 4.6 Morph map

Adicionar em `AppServiceProvider` (já tem `supplier`, `payment_request`, `user`):

```
'attachment_batch' => AttachmentBatch::class,
```

**Não** recriar `attachments` — morph já existe; só coluna + alias no map.

---

## 5. Relacionamentos

```
AttachmentBatch ──hasMany──> AttachmentBatchItem
AttachmentBatch ──morphMany─> Attachment (HasAttachments)   // attachable durante staging
AttachmentBatchItem ──belongsTo──> Attachment               // 1:1
AttachmentBatchItem ──belongsTo──> PaymentRequest?          // destino
AttachmentBatchItem ──belongsTo──> Supplier?                // destino
AttachmentBatchItem ──hasMany──> AttachmentBatchItemClassification
Attachment ──morphTo──> attachable (AttachmentBatch | PaymentRequest | …)
PaymentRequest ──morphMany─> Attachment                     // pós-rebind (F3)
User ──hasMany (created_by)──> AttachmentBatch
```

Diagrama de ciclo de vida do morph:

```
[Upload] Attachment → AttachmentBatch
[Classify] Item.destination_* preenchido (Attachment ainda no Batch)
[Rename + destination=PR] Attachment → PaymentRequest  (mesmo id; path movido)
[Rename + Supplier|OperationalCategory] Attachment permanece → AttachmentBatch
```

---

## 6. Enums (**a criar** / estender)

### 6.1 `AttachmentBatchStatus`

Valores §3.2; contratos Filament; `canTransitionTo()` / `isTerminal()`.

Labels i18n: `enums.attachment_batch_status.*`.

### 6.2 `AttachmentBatchItemStatus`

`Pending`, `Classified`, `Renamed`, `Failed`.

### 6.3 `AttachmentBatchDestinationType`

`PaymentRequest = 'payment_request'`, `Supplier = 'supplier'`, `OperationalCategory = 'operational_category'`.

HasLabel/HasColor/HasIcon.

### 6.4 `AttachmentType`

**Sem mudança obrigatória** na F6. Tipo boleto continua atribuível na PR após rebind (fluxo F3) ou opcionalmente no item no MVP futuro — **fora** do mínimo F6.

---

## 7. Exceções de domínio

**Reutilizar e estender** `App\Exceptions\AttachmentException` (já extends `BusinessException`):

| Factory | Quando | `userMessage` |
|---------|--------|---------------|
| `invalidMimeType` | Já existe | existente |
| `fileTooLarge` | Já existe | existente |
| `fileNotFound` | Já existe | existente |
| `batchClassificationIncomplete()` | Conclude com item pending | `__('attachments.errors.batch_classification_incomplete')` |
| `batchNotClassifiable(string $status)` | Status ≠ pending_classification | tradução |
| `invalidDestination()` | Enum/FK inconsistente | tradução |
| `namingCollisionUnresolved(string $base)` | >99 sufixos | tradução **sem** path |
| `storageMoveFailed(string $attachmentId)` | `Storage::move` false | tradução sem path interno |
| `batchEmpty()` | Upload zero arquivos | tradução |
| `unauthorizedBatchOperation()` | Policy fail no Service (belt) | tradução |

Mensagens de log (`message`) em inglês com contexto; UI só `getUserMessage()` pt-BR.

---

## 8. Events / Listeners / Jobs

Namespaces conforme `agrupar_por_dominio` (domínio **Attachment**):

### 8.1 `App\Events\Attachment\AttachmentBatchClassified`

- Payload: `AttachmentBatch $batch`.
- Dispatch: sync após commit.
- Listener: `App\Listeners\Attachment\QueueAttachmentBatchRename` → `RenameAttachmentBatchJob::dispatch($batch)` (**ShouldQueue** no job, não no listener — listener sync fino).

### 8.2 `App\Events\Attachment\AttachmentRenamed`

- Payload: `Attachment $attachment`, `AttachmentBatchItem $item`.
- Por item após sucesso.
- Listener: `LogAttachmentRenamed` (sync) — log estruturado; **não** recria Attachment.

### 8.3 `App\Events\Attachment\AttachmentBatchRenamed`

- Payload: `AttachmentBatch $batch`.
- Quando job termina em `renamed` (todos ok). Em `partially_failed`, **não** dispara este evento (ou disparar variante — **decisão:** só em `renamed` total; parcial só notificação/log do job).
- Listener opcional: `NotifyAttachmentBatchRenamed` (ShouldQueue) → `created_by`.

### 8.4 Job `App\Jobs\Attachment\RenameAttachmentBatchJob`

- `ShouldQueue`, `SerializesModels`, `WithoutOverlapping($batch->id)`.
- `$tries = 3`, `$backoff = [10, 30, 60]`, `$timeout` generoso (ex. 300s).
- `handle`: `AttachmentBatchNamingService::renameBatch($batch)`.
- `failed`: se ainda `renaming`, marcar `failed` + `failure_reason`.
- Idempotência: se já `renamed`, no-op; se `partially_failed`, permitir retry **somente** itens `failed` (Action Adm/Operador “Retry failed renames”).

Registro: `Event::listen` no `AppServiceProvider` (padrão F5) ou discovery.

---

## 9. Camadas (DTO / Service / Action)

### 9.1 DTOs (flat `App\DTOs`)

| DTO | Uso |
|-----|-----|
| `AttachmentBatchData` | Metadados pós-create |
| `AttachmentBatchItemClassificationData` | destination_type + FKs/label + sort_order |
| `AttachmentNamingResult` | standardized_name, path, collisionSuffix |

### 9.2 Services (flat `App\Services`)

| Service | Responsabilidades |
|---------|-------------------|
| `AttachmentService` | **Estender:** helpers `moveAndUpdatePath`, mapa mime→ext, assertMimeAndSize (já existe); **não** duplicar validação |
| `AttachmentBatchService` | `createFromUploads(array $files, User $actor)`, sync contadores, soft delete |
| `AttachmentBatchClassificationService` | `classifyItem(...)`, `reorder(array $orderedIds)`, `conclude(AttachmentBatch)` → event |
| `AttachmentBatchNamingService` | `renameBatch`, `renameItem`, geração de nome, colisão, rebind PR |

**Regra:** Filament/Actions não falam com Storage diretamente — passam pelos Services.

### 9.3 Actions (`App\Actions\Attachment\`)

| Action | Uso |
|--------|-----|
| `CreateAttachmentBatchAction` | Policy create + service upload |
| `ClassifyAttachmentBatchItemAction` | Policy classify + history insert |
| `ReorderAttachmentBatchItemsAction` | Policy + sort_order |
| `ConcludeAttachmentBatchClassificationAction` | Assert completo + status + event |
| `RetryFailedAttachmentBatchRenamesAction` | Re-dispatch job (itens failed) |

### 9.4 Form Requests

Opcional: validação principal no Filament + asserts no Service (padrão F3/F5). Se HTTP aparecer depois, aí sim FormRequest.

---

## 10. File storage

| Tema | Decisão |
|------|---------|
| Disco | `config('rjet.attachments.disk')` (`RJET_ATTACHMENTS_DISK` / `FILESYSTEM_DISK`) |
| Visibility | `private` |
| Diretório batch | `attachments/attachment_batch/{batchUuid}/` via `directoryFor(AttachmentBatch)` |
| Staging upload Filament | Reusar `stagingDirectoryFor($user)` (F3) + relocate no create do batch |
| Diretório pós-rebind PR | `attachments/payment_request/{prUuid}/` (F3) |
| Download | Stream autenticado / `temporaryUrl` existente no Model — **nunca** URL pública permanente |
| Orphan cleanup | `AttachmentService::cleanOrphans` continua válido se paths estiverem só em `attachments` |

---

## 11. Soft deletes / pruning

| Entidade | SoftDeletes | Notas |
|----------|:-----------:|-------|
| `AttachmentBatch` | ✅ | Contadores congelados; cascade soft de items **só** via app |
| `AttachmentBatchItem` | ✅ | Unique parcial `attachment_id` |
| `AttachmentBatchItemClassification` | ❌ | Append-only; hard-owned pelo item |
| `Attachment` | ✅ (já) | Arquivo físico só no `forceDeleted` (Observer F3) |

### 11.1 SoftDeletes vs FK `onDelete` (fechado — DBA)

`cascadeOnDelete` / `restrictOnDelete` **não** são acionados por soft delete (`soft-deletes.md` §6). Comportamento real:

| Evento | O que a FK faz | O que a aplicação faz |
|--------|----------------|------------------------|
| Soft delete **batch** | Nada | Observer/Service: soft-deleta **items**; Attachments ainda no batch → soft-deleta; Attachments já em PR → **não** toca |
| Soft delete **item** | Nada | Classifications **permanecem** (auditoria); Attachment: se attachable=batch → soft-deleta; se PR → mantém |
| Soft delete **Attachment** | Nada (restrict não dispara) | Evitar via Service se item ativo referencia; sync `has_attachments` no parent F3 |
| ForceDelete **batch** | Items: `cascadeOnDelete` (hard) | Classification sai com item (cascade); Attachments: lógica app (batch vs PR) + Observer storage |
| ForceDelete **item** | Classifications: `cascadeOnDelete` (OK — filha sem SoftDeletes) | Attachment: app decide (batch → force; PR → não) |
| ForceDelete **Attachment** | `restrictOnDelete` na FK do item **bloqueia** se linha do item ainda existir | Force item/batch antes, ou `null`/desvincular — preferir force na ordem batch→items |

**Regra de Attachment no soft do batch/item:**

- Se `attachment.attachable` ainda é o batch → soft delete Attachment junto.
- Se já é `PaymentRequest` → item soft-deleted **mantém** Attachment na PR; `attachment_id` permanece para auditoria (unique parcial libera reuso só se item soft-deleted — Service **não** deve reutilizar o mesmo Attachment em outro item ativo).

### 11.2 Conflito F3 (`has_attachments` / morph / Observer)

- Rebind atualiza `attachable_type`/`attachable_id` na **mesma** linha `attachments` — índices morph existentes (`uuidMorphs` + composto com `type`) **não** são unique → rebind **não** viola unique.
- Após rebind: chamar sync `has_attachments` no **batch anterior** (no-op se sem coluna) **e** na PR (`HasAttachments` / Observer F3) — Service deve forçar sync nos dois lados se morph mudar sem delete/create.
- Soft delete do Attachment na PR continua atualizando `payment_requests.has_attachments` como hoje.

**Pruning:** adiado (retenção financeira), igual F3.

---

## 12. Performance

| Tema | Abordagem F6 |
|------|--------------|
| Cache | **Não** cachear lotes (volume baixo; status muda rápido) |
| Índices | §4.5 / §21 — só WHERE/ORDER BY reais |
| Eager load | List: `creator`; View: `items.attachment`, `items.paymentRequest`, `items.supplier`; histórico paginado |
| N+1 | Table Filament `modifyQueryUsing` com `with` |
| Job | Um job/lote; loop itens ordenados; sem carregar blobs em memória |
| Contadores | Atualizar no Service em cada classify/rename (fonte de verdade = agregação dos items) |

**Não indexar (DBA):** `sort_order` sozinho; contadores desnormalizados; `standardized_name`; `failure_reason` / `rename_error` / `operational_label` (texto livre).

**Indexar:** `status` (lote e item); `destination_type`; composto `(attachment_batch_id, sort_order)`; `(created_by, created_at)` no lote; `(attachment_batch_item_id, classified_at)` no histórico; FKs com `->index()` explícito (padrão F5 / Postgres).

---

## 13. Filament Resources (Blueprint obrigatório)

Painel: **`admin`**. Classes `final`. Resource limpo. Table: `recordActions` / `toolbarActions`. Ícones: `Heroicon` enum. Infolist **sempre**. SoftDeletes: `getRecordRouteBindingEloquentQuery()`.

Grupo: `__('navigation.groups.operations')` — confirmado no código (`ImportBatchResource`, `PaymentRequestResource`). Sort sugerido: **16** (logo após ImportBatch = 15).

### 13.1 Resource: `AttachmentBatchResource`

```
Resource: AttachmentBatchResource
  Command: php artisan make:filament-resource AttachmentBatch --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\AttachmentBatches\AttachmentBatchResource
  Structure:
    - AttachmentBatchResource.php (final, limpo — só delegates)
    - Schemas/AttachmentBatchForm.php          # Create = upload múltiplo
    - Schemas/AttachmentBatchInfolist.php
    - Tables/AttachmentBatchesTable.php
    - Pages/ (Create, List, View) — Edit omitido (classificação em página/action dedicada)
    - Pages/ClassifyAttachmentBatch.php       # página custom de classificação OU embed Livewire
    - RelationManagers/AttachmentBatchItemsRelationManager.php
    - RelationManagers/AttachmentBatchItemClassificationsRelationManager.php  # via item ou nested
    - Actions/ConcludeAttachmentBatchClassificationAction.php
    - Actions/RetryFailedAttachmentBatchRenamesAction.php
    - Actions/DownloadAttachmentBatchItemAction.php
  SoftDeletes: getRecordRouteBindingEloquentQuery()
  Icon: Heroicon::OutlinedPaperClip
  Navigation:
    Group: __('navigation.groups.operations')
    Sort: 16
    Label: __('attachment_batches.navigation_label')
  Visibility: Operador + Adm (Policy viewAny); Cliente excluído
  Form (Create):
    Field: files
      Component: Filament\Forms\Components\FileUpload
      Validation: required; multiple; acceptedFileTypes config MIME; maxSize max_kilobytes; maxFiles max_files
      Config: ->multiple()
              ->disk(fn (): string => (string) config('rjet.attachments.disk'))
              ->directory(fn (): string => app(AttachmentService::class)->stagingDirectoryFor(auth()->user()))
              ->visibility('private')
              ->storeFiles(false)
              ->columnSpanFull()
  Infolist:
    Entry: status → TextEntry badge (Enum)
    Entry: items_count / classified_count / renamed_count / failed_count → TextEntry
    Entry: failure_reason → TextEntry visible if failed/partial
    Entry: naming_generated_at / classified_at / renamed_at → TextEntry datetime
    Entry: creator.name → TextEntry
    Entry: created_at → TextEntry
  Table:
    Column: created_at → TextColumn sortable
    Column: status → TextColumn badge
    Column: items_count → TextColumn
    Column: classified_count → TextColumn
    Column: renamed_count → TextColumn
    Column: creator.name → TextColumn toggleable
    Filter: status → SelectFilter
    Filter: created_at → date range
    Filter: TrashedFilter (Adm)
  RelationManagers:
    - AttachmentBatchItemsRelationManager
        Columns: sort_order, attachment.displayName, attachment.mime_type, status, destination_type, paymentRequest reference, supplier.name, operational_label, classified_at
        recordActions: [Download, View classifications]
        reorderable: sort_order (se classificação inline; senão na página Classify)
        toolbarActions: []
  RecordActions: [View, Classify (link página), ConcludeClassification, RetryFailedRenames, Delete (Adm)]
  ToolbarActions: [BulkActionGroup → [DeleteBulk]]  # Adm
```

**Create:** `CreateAttachmentBatchAction` após submit → redirect View.

**View:** polling leve (`wire:poll`) enquanto `renaming`.

### 13.2 Policies

| Policy | viewAny / view | create | classify / conclude / reorder | delete / restore / forceDelete |
|--------|----------------|--------|-------------------------------|--------------------------------|
| `AttachmentBatchPolicy` | Operador, Adm | Operador, Adm | Operador, Adm (`classify`) | Adm |
| `AttachmentBatchItemPolicy` | herdada do batch | — | Operador, Adm | Adm |

Abilities custom: `classify(User, AttachmentBatch): bool`.

`AttachmentPolicy`: inalterada na essência; quando attachable é `AttachmentBatch`, `view`/`manage` via Policy do batch (estender `view` para `can('view', $attachable)` quando Batch).

### 13.3 Widgets

Nenhum obrigatório.

---

## 14. Livewire custom

**Componente:** `App\Livewire\Attachment\ClassifyAttachmentBatch` (class-based), embutido em `ClassifyAttachmentBatch` Filament page.

**Por que não só Filament Repeater:** classificação de dezenas de arquivos exige preview (imagem/PDF), Select de destino condicional (PR searchable / Supplier / label), drag-and-drop de ordem com feedback — Repeater + FileUpload do Create não cobrem bem o fluxo pós-upload.

**Comportamento:**

- Lista itens eager-loaded; `wire:sort` / Alpine Sortable atualiza `ReorderAttachmentBatchItemsAction`.
- Salvar classificação por item → `ClassifyAttachmentBatchItemAction` (histórico).
- Botão “Concluir classificação” → `ConcludeAttachmentBatchClassificationAction`.
- Islands: lista vs painel de preview para não re-renderizar tudo.
- `#[Lazy]` no preview pesado se necessário.

Se no blueprint a equipe validar que RelationManager `reorderable` + EditAction bastam para o volume real RJET, o Livewire pode ser **rebaixado** a opcional — default desta arquitetura: **incluir** o componente.

---

## 15. Fluxos

### 15.1 Fluxo principal

1. Operador/Adm cria lote → upload múltiplo validado (MIME/size).
2. Service cria `AttachmentBatch`, grava cada arquivo como `Attachment` (attachable = batch) + `AttachmentBatchItem` (`pending`, `sort_order` = índice).
3. Status `pending_classification`; contadores atualizados.
4. Operador abre Classify → reordena → define destino por item → cada save gera histórico + item `classified`.
5. Conclude → assert todos classified → batch `classified` → `AttachmentBatchClassified`.
6. Job `RenameAttachmentBatchJob`: fixa `naming_generated_at`; para cada item por `sort_order` gera nome, move storage, atualiza `standardized_name`/`path`, rebind se PR, dispara `AttachmentRenamed`.
7. Batch → `renamed` (ou `partially_failed`) → eventual `AttachmentBatchRenamed`.

### 15.2 Classificação incompleta

1. Conclude com ≥1 item `pending` → `AttachmentException::batchClassificationIncomplete()`.
2. Status do lote **permanece** `pending_classification`.
3. Nenhum evento / job.

### 15.3 Falha de rename (item)

1. `Storage::move` falha ou update inconsistente → item `failed` + `rename_error`; continua próximos itens.
2. Fim: se algum renamed e algum failed → `partially_failed`; se todos failed → `failed`.
3. Retry action reprocessa só `failed`.

### 15.4 Colisão de nome

1. Path existe → sufixo `_N` até livre.
2. Esgotou → item `failed` com `namingCollisionUnresolved` (não derruba o lote inteiro).

### 15.5 Rebind PR

1. Antes: attachable = batch; path sob `attachment_batch/{id}/`.
2. Move para `payment_request/{id}/{standardized_name}`.
3. Update morph + path + standardized_name.
4. `AttachmentObserver` sync `has_attachments` no batch **e** na PR (garantir chamada nos dois lados no Service se morph mudar sem delete).

---

## 16. Segurança

| Tema | Controle |
|------|----------|
| Autorização | Policies + `scopeVisibleTo`; Cliente sem viewAny |
| Upload | MIME allowlist; max KB; path traversal já tratado em `assertPathIsSafe` |
| Disco | Private; download só autenticado |
| Mensagens | Sem path S3/local na UI |
| Classificação | Só Operador/Adm; histórico com `user_id` |
| Rebind | Validar que ator pode `view` a `PaymentRequest` / `Supplier` alvo ao classificar |

---

## 17. Factories / seeders

| Factory | States |
|---------|--------|
| `AttachmentBatchFactory` | `pendingClassification()`, `classified()`, `renaming()`, `renamed()`, `partiallyFailed()`, `failed()` |
| `AttachmentBatchItemFactory` | `pending()`, `classified()`, `forPaymentRequest()`, `forSupplier()`, `operationalCategory()`, `renamed()`, `failed()` |
| `AttachmentBatchItemClassificationFactory` | definition mínima |
| `AttachmentFactory` | Estender state `withStandardizedName()`, `forBatch()` |

Seeders: **não** obrigatório em produção; DatabaseSeeder de demo opcional com 1 lote pending.

Toda Model nova com `--factory` (guideline).

---

## 18. Testes previstos (Pest) — não implementar aqui

Feature / Service (Storage::fake + Queue::fake):

| # | Cenário |
|---|---------|
| 1 | Upload rejeita MIME inválido (ex. XML / octet-stream) |
| 2 | Upload rejeita arquivo > `max_kilobytes` |
| 3 | Create batch cria N attachments morph→batch + items + status `pending_classification` |
| 4 | Reorder altera `sort_order` e SEQ resultante |
| 5 | Classify grava destino + linha de histórico; reclassify adiciona **nova** linha |
| 6 | Conclude incompleto lança / não muda status / não despacha job |
| 7 | Conclude completo → event + job; nomes `YYYYMMDD_HHMMSS_SEQ.ext` com SEQ pela ordem |
| 8 | Colisão simula `exists` → sufixo `_2` |
| 9 | Destino PR: após rename, `attachable` é PR; **um** Attachment; download path ok |
| 10 | Destino Supplier/OperationalCategory: Attachment permanece no batch |
| 11 | Policy: Cliente não `viewAny` / não `classify` |
| 12 | Operador classifica; Adm também |
| 13 | `original_name` preservado; `standardized_name` preenchido |
| 14 | Falha de move marca item `failed` e batch `partially_failed` |
| 15 | Job idempotente se batch já `renamed` |

Unit leve: mapa mime→ext; gerador de nome com Carbon freeze.

---

## 19. Pendências (só o que depende da RJET / não inferível)

| ID | Tema | Nota |
|----|------|------|
| P-F6-MAX-FILES | Volume típico de arquivos por lote | MVP usa `RJET_ATTACHMENTS_MAX_FILES` (10). Se operação rotineira >10, elevar env — sem segundo parâmetro paralelo. |
| P-F6-OPERATIONAL-LABEL | Taxonomia de “categoria operacional” | Modelado como **string livre**. Se RJET exigir catálogo fechado depois, migrar para FK sem quebrar enum `operational_category`. |
| P-F6-REOPEN | Reabrir lote já `classified` | MVP **não** reabre; confirmar se negócio precisa. |
| P-ANEXO-SIZE | Já resolvido em F3 via env | F6 só reutiliza; DRF ainda lista como aberta historicamente — na prática **fechado** em código (10240). |

Nenhuma dessas pendências bloqueia o desenho de schema acima para revisão DBA.

---

## 20. Assunções documentadas

1. Adm é superset do Operador para classificação (padrão F1/F5).
2. Upload do lote = mesmo público do ImportBatch (Operador/Adm), não Cliente.
3. Um job por lote; eventos de rename por item + evento de lote só em sucesso total.
4. Sem API REST na F6.
5. Sem morph `HasAttachments` em `Supplier` nesta fase.
6. Timezone `America/Sao_Paulo` para o prefixo de nome.
7. Histórico append-only obrigatório (critério RF028).

---

## 21. Schema / migrations (consolidado pós-revisão DBA)

Ordem de migrations:

1. `add_standardized_name_to_attachments_table` (alter — **sem** `after()`)
2. `create_attachment_batches_table`
3. `create_attachment_batch_items_table` (+ unique parcial `attachment_id`)
4. `create_attachment_batch_item_classifications_table`

**Regras transversais:**

- Helper privado `supportsPartialIndexes(): bool` = `in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)` — padrão F1–F5 (copiar de `create_payment_request_bank_details_table` / `approvals`).
- Unique parcial: **nunca** `->unique()` no Blueprint quando SoftDeletes; só `DB::statement` + `DROP INDEX IF EXISTS` no `down()`.
- Status / destination_type = `string` — **`$table->enum()` PROIBIDO**.
- FKs com `->index()` explícito (Postgres).
- Contadores desnormalizados: comentário inglês na migration (`// Denormalized: UI cache — source of truth is items()`).
- Sem CHECK SQL multi-coluna (projeto não usa).
- Tamanhos confirmados: lote `status` **30** · item `status` **20** · `destination_type` **30** · `standardized_name` **255** · `operational_label` **120** · `rename_error` **500**.

### 21.1 Alter `attachments`

```php
Schema::table('attachments', function (Blueprint $table): void {
    // No after() — Postgres ignores column order (F4/F5 DBA)
    $table->string('standardized_name', 255)->nullable();
    // No unique, no index in MVP — display/accessor only
});
```

**`down()`:** `dropColumn('standardized_name')`. Não recria morph; `original_name` intocado.

### 21.2 `attachment_batches`

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

### 21.3 `attachment_batch_items`

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
// down: DROP INDEX IF EXISTS attachment_batch_items_attachment_id_unique;
// then Schema::dropIfExists('attachment_batch_items');
```

### 21.4 `attachment_batch_item_classifications`

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

**Model:** `public $timestamps = false;` + `public const UPDATED_AT = null;` — Service preenche `classified_at`.

### 21.5 Matriz FK (forceDelete vs soft)

| FK | On delete (hard) | Soft do pai |
|----|------------------|-------------|
| items.attachment_batch_id | `cascadeOnDelete` | App soft-deleta items |
| items.attachment_id | `restrictOnDelete` | Nada; Attachment tratado por regra §11 |
| items.payment_request_id / supplier_id | `nullOnDelete` | — |
| classifications.attachment_batch_item_id | `cascadeOnDelete` | Soft item **mantém** histórico |
| classifications.payment_request_id / supplier_id | `nullOnDelete` | Snapshot sobrevive |
| classifications.user_id / `*_by` → users | `nullOnDelete` | Blameable / auditoria |
| Batch → Attachment (morph) | Sem FK nativa | App: soft se ainda attachable=batch |

### 21.6 `down()` seguro

Ordem inversa: drop classifications → drop items (após DROP INDEX parcial) → drop batches → dropColumn `standardized_name`. Sem `after()` no alter.

### 21.7 Conflitos F3

| Artefato F3 | Impacto F6 |
|-------------|------------|
| Tabela `attachments` + morph | Só coluna `standardized_name`; morph map + alias `attachment_batch` |
| Unique morph? | **Não existe** — rebind seguro |
| `has_attachments` na PR | Sync pós-rebind / soft (Observer + Service dual) |
| `AttachmentObserver` forceDeleted | Continua limpando storage; F6 não bypassa |
| Índices morph / type | Intactos |

---

## 22. Checklist de implementação (pós-aprovação)

- [ ] Enums + i18n `enums.*` / `attachment_batches.*` / `attachments.errors.*`
- [ ] Migrations §21 (alter sem `after()`; unique parcial `attachment_id`; classifications append-only)
- [ ] Models + factories + morph map + HasAttachments no Batch (`UPDATED_AT = null` em Classification)
- [ ] Estender AttachmentException + Services + Actions
- [ ] Events/Listeners/Job
- [ ] Policies + Gate
- [ ] Filament Resource + página Classify (+ Livewire se mantido)
- [ ] Testes §18 (+ unique parcial; soft batch não apaga Attachment em PR; force cascade classifications)
- [ ] Pint

---

## 23. Próximos passos / Handoff

1. ~~Revisão `dba`~~ → **`dba` concluído** (2026-09-26) — ver §24; schema incorporado em §3 / §4 / §11 / §12 / §21.
2. ~~`/blueprint` (Filament)~~ → gravado em [.ai/blueprints/fase-6-lote-anexos.md](../blueprints/fase-6-lote-anexos.md) (2026-09-26). Próximo: **`implementer`** na ordem do checklist do blueprint.
3. Tester: cobrir §18 da arquitetura (+ casos DBA do blueprint §16.5) com Pest.
4. Não sugerir F7/F8 nesta entrega.

---

## 24. Revisão DBA

> **Revisor:** dba (subagent) · **Data:** 2026-09-26 · **Driver:** PostgreSQL  
> **Escopo:** schema Fase 6 (§3 / §4 / §11 / §12 / §21): alter `attachments.standardized_name`; `attachment_batches`; `attachment_batch_items`; `attachment_batch_item_classifications`; FKs/cascades; SoftDeletes; unique parcial; índices; conflito F3.  
> Guidelines: `database.md`, `enums.md`, `soft-deletes.md`, `performance.md`, `factories-seeders.md`, `PROJECT.md`. Padrão de saída alinhado a F5 §24.  
> Inspeção via migrations/Models reais (`attachments`, `import_batches` / `import_batch_errors`, `payment_request_bank_details` + helper `supportsPartialIndexes()`, `Attachment`, morph map em `AppServiceProvider`).

### Veredito

**Aprovado com ressalvas.** Modelagem em 3NF (contadores + última classificação + `standardized_name` como desnormalizações documentadas), reuso correto do morph `attachments` (sem segunda tabela de storage), enums como `string` + cast PHP, SoftDeletes coerentes (lote/item domínio; classifications append-only), FKs explícitas. Nenhum achado exige rejeição ou redesenho. Correções abaixo já incorporadas em §3, §4, §11, §12 e §21.

### O que está correto

| Item | Avaliação |
|------|-----------|
| Lote/item dedicados; histórico append-only; arquivo via morph F3 | ✅ |
| Status/destino como `string`; sem `$table->enum()`; sem float | ✅ |
| Destino via enum + FKs nullable + `operational_label` (sem model especulativo) | ✅ |
| Contadores desnormalizados com justificativa de UI | ✅ |
| Classifications sem SoftDeletes (auditoria / logs) | ✅ |
| Volume ~70/dia — sem cache de lotes | ✅ |
| Morph map precisa de `attachment_batch`; tabela `attachments` já existe | ✅ |
| Lote `status` string(30) cobre `pending_classification` (23); item string(20) OK | ✅ (fechado) |
| `destination_type` string(30) cobre `operational_category` (21); `partially_failed` (16) | ✅ |
| Sem CHECK multi-coluna no repo — validação no Service | ✅ (confirmado: zero `check()` nas migrations) |

### Achados (Crítico / Importante / Sugestão)

| # | Nível | Achado | Impacto | Correção aplicada |
|---|-------|--------|---------|-------------------|
| 1 | **Crítico** | `unique(attachment_id)` + SoftDeletes no item; `cascadeOnDelete` tratado como se soft cascateasse. | Unique full bloqueia reuso após soft; soft batch não remove items; implementer confunde FK com cascade app. | §4.3/§11/§21: unique **parcial** `WHERE deleted_at IS NULL` + `supportsPartialIndexes()` + DROP no `down()`; matriz soft vs force explícita. |
| 2 | **Crítico** | Classifications: `created_at` + `updated_at` ambíguos; snapshots PR/Supplier sem fechar FK; cascade no soft vs force. | Model gera `updated_at` inexistente; órfãos ou perda de audit. | §4.4/§21.4: só `classified_at`; `UPDATED_AT = null` / `$timestamps = false`; snapshots `nullOnDelete`; cascade FK só no force (filha sem soft). |
| 3 | Importante | Integridade destino (um alvo coerente) citada sem fechar CHECK vs Service. | Implementer inventa CHECK atípico no projeto. | §3.1: **sem** CHECK; Service valida. |
| 4 | Importante | `sort_order` com índice simples + possível índice em `standardized_name`. | Over-indexing (F5 / performance.md). | §4.5/§12/§21: composto `(batch_id, sort_order)` apenas; sem índice em `standardized_name`. |
| 5 | Importante | Tamanhos string do lote vs item pouco contrastados; risco de truncar `pending_classification` se alguém “alinhasse” a 20. | Migration quebrada / truncamento. | §4/§21: lote **30**, item **20**, destination **30**, `standardized_name` **255** documentados. |
| 6 | Importante | Contadores sem obrigação de comentário; alter com possível `after()`. | Desnormalização sem rastro; ruído Postgres. | §3.1/§21: comentário obrigatório; **sem** `after()`. |
| 7 | Importante | Conflito F3 (`has_attachments`, Observer, índices morph) só em fluxo narrativo. | Rebind/sync incompleto; medo de unique inexistente. | §11.2/§21.7: rebind seguro (sem unique morph); dual sync `has_attachments`. |
| 8 | Sugestão | Ordem migrations / `down()` parcial index sem snippet. | Rollback frágil. | §21: ordem 1→4 + `down()` inverso + DROP INDEX. |
| 9 | Sugestão | FKs sem `->index()` explícito no rascunho. | Divergência F5 / Postgres. | §21 snippets com `->index()` em toda FK. |

### Checklist DBA (resumo)

| Área | Resultado |
|------|-----------|
| Normalização 3NF | ✅ (contadores / última classificação / standardized_name documentados) |
| Morph vs dedicado | ✅ lote/item/histórico dedicados; arquivo = morph F3 |
| Campos (`is_`, `_at`, `_by`, `sort_order`, string status) | ✅ |
| Enums (`string`, sem `$table->enum()`) | ✅ |
| UUID + timestampsTz + softDeletesTz | ✅ (Classification só `classified_at`) |
| Unique parcial `attachment_id` | ✅ (com guard F1–F5) |
| FK index explícito (Postgres) | ✅ |
| Cascade soft vs FK onDelete | ✅ documentado §11 / §21.5 |
| Append-only classifications | ✅ |
| Índices só WHERE/ORDER BY | ✅ composto batch+sort; sem sort_order solo; sem standardized_name |
| Conflitos F3 | ✅ alter coluna + morph map; rebind OK |
| CHECK multi-coluna | ✅ não usar (padrão do repo) |

### Decisões DBA confirmadas (2026-09-26)

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | Unique `attachment_id` com SoftDeletes? | Parcial `WHERE deleted_at IS NULL` via `supportsPartialIndexes()`; **nunca** `->unique()` Blueprint |
| 2 | Soft delete cascateia via FK? | **Não** — Observer/Service; FK só no forceDelete |
| 3 | Classifications SoftDeletes / `updated_at`? | **Não** — append-only; `classified_at` only; `UPDATED_AT = null` |
| 4 | Snapshots PR/Supplier no histórico? | FK `nullOnDelete` (não uuid solto) |
| 5 | CHECK SQL destino coerente? | **Não** — Service only |
| 6 | Indexar `sort_order` sozinho / `standardized_name`? | **Não** — composto `(attachment_batch_id, sort_order)` |
| 7 | Tamanho `status` do lote? | **string(30)** (mínimo; `pending_classification` = 23) |
| 8 | `standardized_name`? | `string(255)` nullable; sem unique; sem índice MVP |
| 9 | `after()` no alter attachments? | **Não** |
| 10 | Morph map? | Adicionar `attachment_batch`; não recriar `attachments` |

### Ajustes já aplicados no doc

- Header Status → revisão DBA concluída; pronto para `/blueprint`
- §3.1 integridade destino + comentário de desnormalização
- §4 / §11 / §12 alinhados (SoftDeletes, índices, tamanhos)
- §21 expandido com snippets + matriz FK + conflitos F3
- §22 checklist migrations alinhado
- §23 handoff: dba marcado ✅; próximo blueprint

### Notas para implementer

1. Copiar helper `supportsPartialIndexes()` de `create_payment_request_bank_details_table` (ou `approvals` / F5 import templates).
2. Model `AttachmentBatchItemClassification`: `public $timestamps = false;` + `public const UPDATED_AT = null;` — preencher `classified_at` no Service.
3. Soft-delete batch → soft items; Attachments só se `attachable` = batch; **nunca** soft-deletar Attachment já rebound à PR.
4. ForceDelete Attachment com item ainda presente → `restrictOnDelete` bloqueia; force item/batch na ordem correta.
5. Unique parcial: `CREATE UNIQUE INDEX … WHERE deleted_at IS NULL` + `DROP INDEX IF EXISTS` no `down()`.
6. Alter `standardized_name`: **sem** `after()`; **sem** unique/índice.
7. Morph map: `'attachment_batch' => AttachmentBatch::class`.
8. Destino coerente: assert no Service — sem CHECK SQL.
9. Pós-rebind: sync `has_attachments` na PR (e no-op no batch sem coluna).
10. Testes Pest: unique parcial (soft item libera attachment_id); soft batch mantém Attachment na PR; force item remove classifications; conclude incompleto; rename + rebind um único Attachment; tamanhos status não truncam.
11. Não criar `$table->enum()`; não indexar `sort_order` sozinho nem `standardized_name`.
12. Factories: states §17; Classification factory mínima; Attachment `withStandardizedName()` / `forBatch()`.
