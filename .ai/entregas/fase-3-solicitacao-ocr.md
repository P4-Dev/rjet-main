# Entrega Técnica — Fase 3: Solicitação de Pagamento e OCR

| Campo | Valor |
|-------|-------|
| **Fase** | 3 — Solicitação de pagamento (core) e OCR local de boleto |
| **Data** | 2026-07-31 |
| **Status** | Entregue |
| **Stack** | Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL · Redis · S3 (produção) |
| **Requisitos** | RF012–RF018 |

---

## Resumo executivo

A Fase 3 entrega o núcleo operacional do RJET Finance: criação e gestão de **solicitações de pagamento** (`PaymentRequest`) com dados de liquidação dedicados, histórico auditável de status, anexos em storage privado e **OCR local de boleto em PDF** (sem dependência de cloud). O painel Filament expõe CRUD completo com formulário reativo em três blocos, transições de status, escopo por perfil (RF018) e integração com cadastros das Fases 1–2. A implementação inclui hardening pós-review (transações Filament, revalidação de MIME, autorização em camadas e limpeza de anexos órfãos).

---

## Objetivos / RFs cobertos

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| **RF012** | CRUD de solicitação, ciclo de status e histórico append-only | `PaymentRequest`, `PaymentRequestStatusHistory`, `PaymentRequestService` |
| **RF013** | Formulário em 3 blocos com lógica condicional por forma/modalidade de pagamento | `PaymentRequestForm`, enums `DepositType` / `PixKeyType` |
| **RF014** | Dados bancários dedicados da solicitação (1:1) | `PaymentRequestBankDetails` |
| **RF015** | Anexos morph em disco private (PDF + imagens) | `Attachment`, trait `HasAttachments`, `AttachmentService` |
| **RF016** | Valor líquido = bruto − descontos, persistido | `PaymentRequestService::calculateNetAmount`, coluna `net_amount` |
| **RF017** | OCR local ao anexar boleto PDF; falha não bloqueia fluxo | `LocalBoletoOcrClient`, `ExtractBoletoDataAction`, `smalot/pdfparser` |
| **RF018** | Listagem com filtros e escopo por perfil | `scopeVisibleTo`, `PaymentRequestsTable`, `PaymentRequestPolicy` |

Complemento de configuração: flag **`companies.is_appropriation_required`** (Toggle no `CompanyResource`) torna `appropriation_id` obrigatório dinamicamente por empresa.

---

## O que foi entregue

### Domínio e persistência

- **Models:** `PaymentRequest`, `PaymentRequestBankDetails`, `PaymentRequestStatusHistory`, `Attachment`; trait `HasAttachments`.
- **Enums:** `PaymentRequestStatus` (`Requested → Launched → Settled`, sem regressão), `DepositType`, `PixKeyType`, `AttachmentType` (reuso de `PaymentMethod` da Fase 2).
- **Migrations:** `payment_requests`, `payment_request_bank_details` (1:1, unique parcial), `payment_request_status_history` (append-only, sem `updated_at`), `attachments` (morph), `companies.is_appropriation_required`.
- **Desnormalizações:** `net_amount` persistido; `has_attachments` sincronizado via `AttachmentObserver`.
- **Observers:** `PaymentRequestObserver` (cascade soft-delete/restore de bank details e anexos); `AttachmentObserver` (sync de flag e remoção física no force delete).
- **DTOs:** `PaymentRequestData`, `PaymentRequestBankDetailsData`, `BoletoOcrResult`.
- **Exceções:** `PaymentRequestException`, `AttachmentException`.
- **Regras de validação:** `ValidDigitableLine`, `ValidCpfOrCnpj` (+ reuso de `ValidCpf` / `ValidCnpj`).
- **Factories e seeder:** factories para todas as entidades novas; `PaymentRequestSeeder` registrado em `DatabaseSeeder` (empresas demo com/sem apropriação obrigatória).

### Storage e OCR

- **Config** `config/rjet.php`: disco, limites de tamanho/quantidade, MIME aceitos, TTL de staging, driver OCR.
- **Disco private** configurável via `RJET_ATTACHMENTS_DISK` (local em dev; S3 em produção).
- **Staging por usuário:** uploads temporários em `attachments/staging/{userId}` antes da persistência da solicitação; realocação para `attachments/payment_request/{uuid}` no create.
- **MIME aceitos:** PDF, JPEG, PNG, WEBP; máx. 10 MB (configurável).
- **OCR PDF-only:** `LocalBoletoOcrClient` extrai linha digitável (e valor/vencimento quando possível) via `smalot/pdfparser`; `NullBoletoOcrClient` quando `RJET_OCR_DRIVER=null`; falha retorna aviso sem bloquear upload/submit.
- **Dependência aprovada:** `smalot/pdfparser` ^2.12 em `composer.json`.

### Filament (painel admin)

- **`PaymentRequestResource`** (resource enxuto, delegates para schemas/tables/pages):
  - **Form** (`PaymentRequestForm`): 3 blocos — Identificação, Valores, Pagamento e comprovantes; campos condicionais por `payment_method` / `deposit_type`; sugestão RF010 via `ResolveSupplierPaymentMethodAction`.
  - **Table** (`PaymentRequestsTable`): colunas, filtros (status, forma, empresa, filial, vencimento, anexos, soft deletes), sumarizador de valor líquido.
  - **Infolist** (`PaymentRequestInfolist`): visualização read-only.
  - **Pages:** List, Create, Edit, View.
  - **RelationManagers:** `AttachmentsRelationManager`, `StatusHistoriesRelationManager`.
  - **Actions:** `TransitionStatusAction`, `ExtractBoletoOcrAction`, `DownloadAttachmentAction`, `DeletePaymentRequestAction`, `RestorePaymentRequestAction`, `ForceDeletePaymentRequestAction`.
- **`CompanyResource` (atualização):** Toggle `is_appropriation_required` (form, table, infolist, filtro).

### Autorização e regras de negócio

- **Policies:** `PaymentRequestPolicy`, `AttachmentPolicy`, `PaymentRequestStatusHistoryPolicy` (histórico read-only).
- **Escopo RF018:** `PaymentRequest::scopeVisibleTo` + `getEloquentQuery()` no Resource; Cliente vê filiais vinculadas; Operador/Adm veem todas.
- **Edição por status/role:** `PaymentRequest::isEditableBy` — Cliente só em `Requested`; Operador em `Requested`/`Launched`; Adm em qualquer status.
- **Transição de status:** somente Operador/Adm; via `PaymentRequestService::transitionStatus()` com `canTransitionTo`, registro em histórico e evento `PaymentRequestStatusChanged`.
- **Exclusão:** soft delete apenas Adm; restore/forceDelete apenas Adm.
- **Ownership:** validação de filial, centro de custo e apropriação pertencentes à filial/empresa da solicitação (`PaymentRequestService`).
- **Events/Listeners:** `PaymentRequestCreated`, `PaymentRequestStatusChanged` → `LogPaymentRequestActivity`.
- **Morph map:** alias `payment_request` registrado em `AppServiceProvider`.

### Ops / comandos

- **`attachments:clean-orphans`:** remove uploads expirados em staging (TTL configurável) e arquivos físicos sem registro em `attachments`; suporta `--dry-run` e `--hours`.

### Hardening pós-review

- Transações de banco em **Create/Edit** Filament (`hasDatabaseTransactions`) para rollback em falha de anexos.
- **Revalidação de MIME/tamanho** no disco ao vincular paths de staging (`assertStoredPathIsAllowed`).
- **Autorização de path:** upload só aceita diretório do attachable ou staging do usuário autenticado (`assertPathIsSafe`).
- **Policy `transitionStatus`** e checagem explícita na action Filament.
- **ForceDelete/Restore** com authorize na policy e actions dedicadas.

### i18n

- Arquivos `lang/pt_BR/payment_requests.php` e `lang/en/payment_requests.php`.
- Chaves de enums, companies (`is_appropriation_required`) e mensagens OCR/notificações.

### Testes (Pest)

**146 casos** em **14 arquivos** dedicados à Fase 3:

| Área | Arquivo(s) |
|------|------------|
| Service / validação | `PaymentRequestServiceTest`, `PaymentRequestBankDetailsValidationTest`, `PaymentRequestOwnershipValidationTest`, `PaymentRequestStatusTransitionTest` |
| OCR / linha digitável | `BoletoOcrTest`, `DigitableLineParserTest` |
| Anexos | `AttachmentUploadTest`, `AttachmentOrphanCleanupTest` |
| Policies | `PaymentRequestAuthorizationTest` |
| Filament | `PaymentRequestResourceTest`, `PaymentRequestSchemaTest` |
| Models / cascade | `PaymentRequestCascadeTest` |
| Enums / cálculo | `PaymentRequestStatusTest`, `NetAmountCalculationTest` |

Fixture: `tests/Fixtures/boleto-sample.pdf`.

---

## Decisões relevantes

- **OCR PDF-only:** imagens são aceitas como anexo (RF015), mas OCR retorna aviso — preenchimento manual; sem Tesseract, cloud ou leitura de QR Pix.
- **Histórico dedicado:** `payment_request_status_history` append-only (sem soft delete, sem `updated_at`), separado de morph genérico de activities.
- **`net_amount` persistido:** calculado com `bcsub` no Service e gravado no create/update; não é derivado só na UI.
- **Staging de anexos:** upload antes do save da solicitação; paths validados e realocados no commit; TTL limpo pelo comando agendável.
- **Bank details 1:1:** liquidação (boleto, Pix, transferência) isolada do cabeçalho; `payment_method` permanece no cabeçalho para filtros.
- **Apropriação dinâmica:** coluna nullable no schema; obrigatoriedade via `Company.is_appropriation_required`.
- **Status linear:** `Requested → Launched → Settled`; sem regressão na F3 (hook para workflow F4).
- **OCR síncrono:** sem Job/queue na F3; interface `BoletoOcrClient` permite troca futura de driver.

---

## Fora de escopo

Itens explicitamente **não entregues** nesta fase (previstos em fases futuras):

| Item | Fase prevista |
|------|---------------|
| API REST / Sanctum / Swagger | — (reavaliar se houver portal externo) |
| Widgets Filament | — |
| Jobs assíncronos de OCR | — (OCR síncrono na F3) |
| `ApprovalRule` / workflow de alçadas | F4 |
| Importação em lote / lote de solicitações | F5 |
| Lote de anexos / conciliação multi-arquivo | F6 |
| CNAB / baixa em lote | F7 |
| Dashboard / relatórios Excel | F8 |
| OCR de imagem / leitura de QR Pix | — |

Hooks preparados: enum de status completo, events de ciclo de vida, morph `attachments` reutilizável, `canTransitionTo` extensível.

---

## Como validar

### 1. Ambiente e migrations

```bash
# Docker (conforme PROJECT.md)
docker compose exec app php artisan migrate

# Opcional: dados demo
docker compose exec app php artisan db:seed --class=PaymentRequestSeeder
```

### 2. Variáveis de ambiente (`.env`)

| Variável | Default | Descrição |
|----------|---------|-----------|
| `RJET_ATTACHMENTS_DISK` | `local` | Disco de anexos (S3 em produção) |
| `RJET_ATTACHMENTS_MAX_KB` | `10240` | Tamanho máximo por arquivo (10 MB) |
| `RJET_ATTACHMENTS_STAGING_TTL_HOURS` | `24` | TTL de arquivos em staging |
| `RJET_OCR_ENABLED` | `true` | Habilita OCR |
| `RJET_OCR_DRIVER` | `local` | `local` ou `null` (desabilita) |

### 3. Testes Pest (subset Fase 3)

```bash
docker compose exec app php artisan test --compact \
  tests/Feature/Filament/PaymentRequestResourceTest.php \
  tests/Feature/Filament/PaymentRequestSchemaTest.php \
  tests/Feature/Services/PaymentRequestServiceTest.php \
  tests/Feature/Services/PaymentRequestBankDetailsValidationTest.php \
  tests/Feature/Services/PaymentRequestStatusTransitionTest.php \
  tests/Feature/Services/PaymentRequestOwnershipValidationTest.php \
  tests/Feature/Policies/PaymentRequestAuthorizationTest.php \
  tests/Feature/AttachmentUploadTest.php \
  tests/Feature/AttachmentOrphanCleanupTest.php \
  tests/Feature/BoletoOcrTest.php \
  tests/Feature/Models/PaymentRequestCascadeTest.php \
  tests/Unit/PaymentRequestStatusTest.php \
  tests/Unit/DigitableLineParserTest.php \
  tests/Unit/NetAmountCalculationTest.php
```

### 4. Comando de limpeza de órfãos

```bash
# Simular
docker compose exec app php artisan attachments:clean-orphans --dry-run

# Executar (TTL padrão 24h)
docker compose exec app php artisan attachments:clean-orphans

# TTL customizado
docker compose exec app php artisan attachments:clean-orphans --hours=48
```

### 5. Smoke manual (Filament)

1. Autenticar como Adm/Operador/Cliente e acessar **Operações → Solicitações de pagamento**.
2. Criar solicitação **Boleto** com PDF — verificar OCR preenchendo linha digitável (ou aviso se falhar).
3. Criar solicitação **Depósito/Pix** e **Transferência** — validar campos condicionais.
4. Transicionar status `Requested → Launched → Settled` (Operador/Adm).
5. Como Cliente, confirmar visibilidade restrita às filiais vinculadas.
6. Toggle **Exigir apropriação** em Empresa e validar obrigatoriedade no form.

---

## Riscos / follow-ups conhecidos

- **Agendamento do comando `attachments:clean-orphans`:** implementado, mas agendamento em `routes/console.php` / cron de produção deve ser configurado pela operação (não bloqueante para go-live funcional).
- **OCR heurístico:** extração depende de PDF com texto selecionável; boletos escaneados ou layout atípico exigem revisão manual (comportamento esperado).
- **Workflow F4:** transições atuais são manuais Operador/Adm; alçadas de aprovação alterarão regras de `canTransitionTo` sem mudança estrutural de schema.

Nenhum bloqueante conhecido para uso interno via painel Filament.

---

## Referências

| Documento | Caminho |
|-----------|---------|
| Blueprint (plano de implementação) | [`.ai/blueprints/fase-3-solicitacao-ocr.md`](../blueprints/fase-3-solicitacao-ocr.md) |
| Arquitetura e decisões | [`.ai/arquitetura/fase-3-solicitacao-ocr.md`](../arquitetura/fase-3-solicitacao-ocr.md) |
| DRF v1 (RF012–RF018) | [`.ai/requisitos/drf-financeiro-v1.md`](../requisitos/drf-financeiro-v1.md) |
| Config de anexos/OCR | [`config/rjet.php`](../../config/rjet.php) |
| Exemplo de env | [`.env.example`](../../.env.example) |

---

## Histórico

| Versão | Data | Descrição |
|--------|------|-----------|
| 1.0 | 2026-07-31 | Documento de entrega da Fase 3 |
