# Entrega Técnica — Fase 5: Lote de solicitações (importação via planilha)

| Campo | Valor |
|-------|-------|
| **Fase** | 5 — Lote de solicitações / importação via planilha |
| **Data** | 2026-09-26 |
| **Status** | Entregue |
| **Stack** | Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL · Redis · OpenSpout 4.32 (transitivo) |
| **Requisitos** | RF025–RF026 |

---

## Resumo executivo

A Fase 5 entrega **templates versionáveis de importação** (`ImportTemplate` + versão + mappings) e **importação em lote via planilha** (`ImportBatch` + job + erros por linha). Linhas válidas criam `PaymentRequest` via `PaymentRequestService::create()`; inválidas viram `ImportBatchError` (modo parcial). O job **não** chama `ApprovalService::route()` — o evento `PaymentRequestCreated` da Fase 4 roteia cada PR. Adm configura templates (settings); Operador e Adm importam (operations). Sem API REST, sem widgets, sem Edit de lote.

---

## Objetivos / RFs cobertos

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| **RF025** | Adm cadastra template mapeando colunas → campos de `PaymentRequest`; versionável | `ImportTemplate`, `ImportTemplateVersion`, `ImportTemplateMapping`, `ImportTemplateService`, `ImportTemplateResource` |
| **RF026** | Upload com template; validação linha a linha; cria PRs; relatório de erros; job assíncrono | `ImportBatch`, `ImportBatchError`, `ImportBatchService`, `ProcessImportBatchJob`, `ImportBatchResource` |

---

## O que foi entregue

### Domínio e persistência

- **Models:** `ImportTemplate` (SoftDeletes), `ImportTemplateVersion` (SoftDeletes, `UPDATED_AT = null`), `ImportTemplateMapping` (**sem** SoftDeletes, `$timestamps = false`), `ImportBatch` (SoftDeletes), `ImportBatchError` (append-only, `$timestamps = false`).
- **Extensão F3:** `payment_requests.import_batch_id` nullable, index, `nullOnDelete` (hook de rastreabilidade do lote).
- **Enums:** `ImportBatchStatus` (`Pending → Processing → Completed|Failed`), `ImportFileFormat` (`Csv`, `Xlsx`), `ImportTargetField` (campos de domínio + bank fields).
- **Migrations:** `import_templates`, `import_template_versions` (unique full template+version; unique parcial um `is_current`), `import_template_mappings` (hard-owned, cascade na version), `import_batches` (`import_template_version_id` **restrictOnDelete**, `mappings_snapshot` JSON, contadores), `import_batch_errors`, alter em `payment_requests`.
- **DTOs:** `ImportTemplateData`, `ImportTemplateMappingData`, `ImportBatchData`, `ImportRowData`.
- **Exceção:** `ImportException` (mensagens i18n via `getUserMessage()`).
- **Services:** `ImportTemplateService` (create → v1; `updateMetadata`; `publishVersion` insert-only; delete), `ImportBatchService` (`start` → dispatch job; `process` linha a linha; `markFailed`; parsers money/date).
- **Integração:** `SpreadsheetReader` + `OpenSpoutSpreadsheetReader` (bind em `AppServiceProvider`). Disco não-local: cópia para temp antes do OpenSpout, unlink no `finally`. Default `RJET_IMPORTS_DISK` = local.
- **Observer:** `ImportBatchObserver` — forceDelete apaga arquivo no disco; soft-delete **não**.
- **Config** `config/rjet.php` → `rjet.imports`: disk, directory, `max_kilobytes` (5120), `max_rows` (500), extensions.
- **Factories / seeder:** factories das 5 entidades; `ImportTemplateSeeder` (“Depósito Pix (CSV)”) registrado em `DatabaseSeeder`.

### Filament (painel admin)

- **`ImportTemplateResource`** (settings, só Adm): Form / Table / Infolist; Pages List, Create, Edit, View; `VersionsRelationManager`; `PublishImportTemplateVersionAction`.
- **`ImportBatchResource`** (operations, Operador/Adm): Form (upload + template), Table, Infolist; Pages **List, Create, View** — **sem Edit**; RelationManagers `Errors` e `PaymentRequests`; `DownloadImportSpreadsheetAction`.
- **Poll:** `ViewImportBatch` faz polling a cada 5s enquanto status não é terminal.

### Autorização e regras de negócio

- **Policies:** `ImportTemplatePolicy` (só Adm); `ImportBatchPolicy` (view/create O+A; update sempre false; delete/restore/forceDelete só Adm).
- **Cliente** não importa nem vê lotes/templates.
- **Operador** vê todas as filiais no painel (igual F3); no import, “filial fora de escopo” = filial **inativa** ou CNPJ da linha que **não bate** com o branch do template (não é filtro de membership do usuário).
- **Modo parcial:** linha válida → PR; inválida → `ImportBatchError`; `Failed` só em falha estrutural (arquivo ilegível, vazio, row limit, creator ausente, etc.).
- **Formato incompatível:** rejeitado em `start()` com `ImportException` — **não** persiste batch Failed.
- **Boleto no lote:** falha na linha (`boletoNotSupportedInBatch`); sem criar Supplier on-the-fly; duplicata só **intra-lote**.
- **Roteamento F4:** cada create dispara `PaymentRequestCreated` → `RoutePaymentRequestOnCreated`; job **não** chama `route()`.

### Events / Listeners / Notifications / Jobs

| Artefato | Detalhe |
|----------|---------|
| `ProcessImportBatchJob` | Queue; `$tries = 3`; `$timeout = 120`; `$backoff = [10, 30, 60]`; `WithoutOverlapping` por batch id (`dontRelease`) |
| `PaymentRequestBatchImported` | Disparado em **Completed** (mesmo com `success_count = 0`); **não** dispara em Failed |
| `LogPaymentRequestBatchImported` | Sync (log) |
| `NotifyImportBatchCompleted` | `ShouldQueue` → notifica creator |
| `PaymentRequestBatchImportedNotification` | mail + database, `ShouldQueue` |

### i18n

- `lang/{pt_BR,en}/import_templates.php`, `import_batches.php`.
- Extensões em `enums.php`, `notifications.php`, `common.php`.
- `failure_reason` gravado com mensagem de usuário i18n (`missing_creator`, `processing_failed`, `row_limit_exceeded`, etc.); detalhe técnico no log.

### Testes (Pest)

**90 passed · 218 assertions** (2026-09-26):

```bash
docker compose exec app php artisan test --compact --filter=Import
```

**10 arquivos** (contagem expandida com datasets ≈ 90 casos):

| Área | Arquivo |
|------|---------|
| Service / processo / eventos | `tests/Feature/ImportBatchServiceTest.php` |
| Job | `tests/Feature/ProcessImportBatchJobTest.php` |
| Template service | `tests/Feature/ImportTemplateServiceTest.php` |
| Schema / FKs / cascade | `tests/Feature/ImportSchemaConstraintsTest.php` |
| Policies | `tests/Feature/ImportBatchAuthorizationTest.php` |
| Filament templates | `tests/Feature/ImportTemplateResourceTest.php` |
| Filament batches | `tests/Feature/ImportBatchResourceTest.php` |
| Unit status | `tests/Unit/ImportBatchStatusTest.php` |
| Unit parsing | `tests/Unit/ImportRowParsingTest.php` |
| Unit target fields | `tests/Unit/ImportTargetFieldTest.php` |

---

## Decisões relevantes

1. **Modo parcial** — válidas criam PR; inválidas → erro de linha; Failed só estrutural.
2. **Versionamento** — create publica v1; alterar mappings = `publishVersion()` (insert, nunca update in-place); mappings sem SoftDeletes; soft-delete de template **não** apaga versions.
3. **Sem draft** de versão; select de import só `is_current` + template ativo/não trashed.
4. **Hook F4** — reuso de `PaymentRequestCreated` / `route()`; job não re-roteia.
5. **Boleto** falha na linha (anexo obrigatório F3; lote de anexos = F6).
6. **Sem criar Supplier**; duplicata só intra-lote.
7. **Formato incompatível no `start()`** — ImportException, sem batch Failed (aceito).
8. **Row limit mid-process** — status Failed; `total_rows` inclui a linha que estourou (essa linha não cria PR); `success_count` = PRs já criadas; evento batch **não** dispara.
9. **Completed com success_count = 0** — evento e notificação disparam (ex.: só erros de linha / só boletos).
10. **OpenSpout** v4.32.0 via vendor transitivo de `filament/actions`; `composer.json` **não** declara require direto (follow-up).
11. **Página EditImportBatch removida**; update policy sempre false.
12. **Arquivo do lote** em campos do batch (não morph `attachments`); forceDelete apaga arquivo + cascade hard nos errors + null em `import_batch_id` das PRs.

---

## Fora de escopo

| Item | Fase prevista |
|------|---------------|
| API REST / Sanctum / Swagger | — |
| Widgets Filament | — |
| Anexos em lote / nomenclatura | F6 |
| CNAB / baixa em lote | F7 |
| Dashboard / relatórios Excel | F8 |
| Criação de fornecedor pela planilha | — |
| Promoção de `openspout/openspout` a require direto | Follow-up (aprovação de dep) |

Hook preparado: PRs do lote têm `import_batch_id`; evento `PaymentRequestBatchImported` disponível para métricas F8.

---

## Como validar

### 1. Ambiente e migrations

```bash
docker compose exec app php artisan migrate

# Opcional: template demo
docker compose exec app php artisan db:seed --class=ImportTemplateSeeder
```

### 2. Variáveis de ambiente (`.env`)

| Variável | Default | Descrição |
|----------|---------|-----------|
| `RJET_IMPORTS_DISK` | `local` (via `FILESYSTEM_DISK`) | Disco das planilhas |
| `RJET_IMPORTS_MAX_KILOBYTES` | `5120` | Tamanho máx. do arquivo (5 MB) |
| `RJET_IMPORTS_MAX_ROWS` | `500` | Limite de linhas de dados (acima → Failed estrutural) |

### 3. Testes Pest (subset Fase 5)

```bash
docker compose exec app php artisan test --compact --filter=Import
```

Ou lista explícita:

```bash
docker compose exec app php artisan test --compact \
  tests/Feature/ImportBatchServiceTest.php \
  tests/Feature/ProcessImportBatchJobTest.php \
  tests/Feature/ImportTemplateServiceTest.php \
  tests/Feature/ImportSchemaConstraintsTest.php \
  tests/Feature/ImportBatchAuthorizationTest.php \
  tests/Feature/ImportTemplateResourceTest.php \
  tests/Feature/ImportBatchResourceTest.php \
  tests/Unit/ImportBatchStatusTest.php \
  tests/Unit/ImportRowParsingTest.php \
  tests/Unit/ImportTargetFieldTest.php
```

Esperado: **90 passed, 218 assertions**.

Worker de fila necessário para o job em ambiente manual:

```bash
docker compose exec app php artisan queue:work
```

### 4. Smoke manual (Filament)

1. Adm: Configurações → **Templates de importação** — criar template CSV com mappings (ou usar seed “Depósito Pix (CSV)”).
2. Operador: Operações → **Importações** — Create, selecionar template, enviar CSV válido + 1 linha inválida.
3. Abrir View do lote — poll até `Completed` / `Failed`.
4. Conferir RelationManagers: erros por linha + PRs criadas com `import_batch_id`; PRs entram no workflow de alçadas (F4).
5. Download da planilha original; Cliente não vê o resource.

---

## Riscos / follow-ups conhecidos

- **OpenSpout só transitivo:** se Filament remover a dep, o reader quebra. Promover `"openspout/openspout": "^4.23"` (ou pin 4.32) a `require` antes de produção — **pendente de aprovação**.
- **Worker Redis/queue:** importação depende de `queue:work`; sem worker o lote fica `Pending`.
- **Disco remoto:** path S3 exige cópia temp (já implementada); custo I/O em lotes grandes.
- **Callback `ProcessImportBatchJob::failed()`:** se o job abortar fora do `process()` (ex. timeout), pode gravar mensagem técnica em `failure_reason` (path principal do `process()` já usa i18n).
- **Fases 6–8** ainda necessárias para boleto com anexo em lote, CNAB e dashboard.

Nenhum bloqueante conhecido para uso interno via painel Filament (Depósito/Pix/Transferência).

---

## Referências

| Documento | Caminho |
|-----------|---------|
| Blueprint (plano de implementação) | [`.ai/blueprints/fase-5-lote-solicitacoes.md`](../blueprints/fase-5-lote-solicitacoes.md) |
| Arquitetura e decisões | [`.ai/arquitetura/fase-5-lote-solicitacoes.md`](../arquitetura/fase-5-lote-solicitacoes.md) |
| DRF v1 (RF025–RF026) | [`.ai/requisitos/drf-financeiro-v1.md`](../requisitos/drf-financeiro-v1.md) |
| Entrega Fase 4 | [`.ai/entregas/fase-4-workflow-alcadas.md`](fase-4-workflow-alcadas.md) |
| Config de imports | [`config/rjet.php`](../../config/rjet.php) |

---

## Histórico

| Versão | Data | Descrição |
|--------|------|-----------|
| 1.0 | 2026-09-26 | Documento de entrega da Fase 5 |
