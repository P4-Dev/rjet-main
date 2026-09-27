# Entrega Técnica — Fase 7: Baixa em lote e CNAB 240

| Campo | Valor |
|-------|-------|
| **Fase** | 7 — Baixa em lote / geração de remessa CNAB 240 (Itaú SISPAG) |
| **Data** | 2026-09-27 |
| **Status** | Entregue (homologação bancária pendente, ver "Riscos / pendências") |
| **Stack** | Laravel 12 · Filament 5 · Livewire 4 · PHP 8.4 · Pest 4 · PostgreSQL · Redis · gerador CNAB próprio (sem nova dependência) |
| **Requisitos** | RF030–RF034 |

---

## Resumo executivo

A Fase 7 entrega a **baixa em lote** de solicitações `Launched` e a **remessa CNAB 240 Itaú** gerada em fila. A baixa tem dois passos na mesma entidade: `PaymentSettlement` nasce em `draft` com itens, data de baixa e conta pagadora da filial; a **confirmação** leva a baixa a `settled` e cada PR de `Launched → Settled`. Esse é o **único** caminho para `Settled`. O CNAB é opcional e só existe em baixa `draft`: `CnabConfig` (1:1 com `BranchBankAccount`, só Adm) alimenta o `GenerateCnabFileJob`, que roda em **conexão de fila dedicada** (`cnab`, `retry_after` 240), passa por dry-run obrigatório e grava o `.rem` em disco privado. O download exige policy e confere o checksum SHA-256. Um reconciliador agendado (`cnab:recover-stuck-files`) recupera arquivos presos por worker morto. Sem API REST, sem arquivo de retorno, sem widgets.

---

## Objetivos / RFs cobertos

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| **RF030** | Seleção de PRs elegíveis com filtros (filial, vencimento, forma) e seleção de subconjunto | `Pages/CreatePaymentSettlement` (Page + tabela Filament), `EligiblePaymentRequestsTable`, `PaymentRequest::scopeEligibleForSettlement()` |
| **RF031** | Data de baixa e conta pagadora gravadas no lote | `PaymentSettlement` (`settlement_date`, `branch_bank_account_id`), `CreatePaymentSettlementBulkAction`, `PaymentSettlementService` |
| **RF032** | Configuração CNAB 240 por conta da filial (Adm), auditável | `CnabConfig` + `CnabConfigService` + `CnabConfigResource`; HasBlameable; `cnab_files.config_snapshot` |
| **RF033** | Geração assíncrona com strategy por banco, dry-run e falha recuperável | `GenerateCnabFileJob`, `CnabFileService`, `CnabRemittanceService`, `CnabAdapterResolver`, `Itau240RemittanceAdapter`, `CnabRemittanceValidator`, `CnabFile`/`CnabFileItem` |
| **RF034** | Download restrito, nome na convenção Itaú 240 | `DownloadCnabFileAction` (domínio + Filament), `CnabFilePolicy::download`, `Itau240RemittanceAdapter::fileName()` |

---

## O que foi entregue

### Domínio e persistência

- **Models:** `PaymentSettlement` (SoftDeletes), `PaymentSettlementItem` (**sem** SoftDeletes; liberação via `released_at`), `CnabConfig` (SoftDeletes, HasBlameable), `CnabFile` (SoftDeletes; `config()` com `withTrashed()`), `CnabFileItem` (append-only).
- **Migrations** (ordem): `payment_settlements` → `payment_settlement_items` → `cnab_configs` → `cnab_files` → `cnab_file_items`. Índices únicos parciais:
  - uma config viva por conta: `(branch_bank_account_id) WHERE deleted_at IS NULL`. Desativar **não** libera a vaga; substituir = soft delete + create;
  - um arquivo ativo por baixa: `(payment_settlement_id) WHERE status IN ('queued','generating','generated') AND deleted_at IS NULL` (literais conferidos contra `CnabFileStatus::activeValues()` em teste);
  - NSA único por config: `(cnab_config_id, file_sequence) WHERE file_sequence IS NOT NULL`, sem `deleted_at` no predicado (NSA nunca volta).
- **Sem colunas novas** em `payment_requests`.
- **Enums:** `PaymentSettlementStatus` (`draft → settled | cancelled`), `CnabFileStatus` (`queued → generating → generated → superseded`; `failed` terminal), `CnabLayout` (`itau_240`), `CnabPaymentType` (`boleto`, `transfer`, `pix_key`).
- **DTOs:** `PaymentSettlementData`, `EligiblePaymentFilterData`, `CnabRemittanceData`, `CnabRemittanceItemData`, `CnabRemittanceResult`, `CnabValidationError`, `CnabValidationReport`.
- **Exceções:** `PaymentSettlementException` (ex.: `mixedBranches`, `settlementDateInFuture`, `cnabGenerationInProgress`, `itemsChangedSinceSelection`), `CnabException` (ex.: `configInactive`, `paymentDateInPast`, `generationAlreadyActive`, `fileIntegrityCheckFailed`, `fileSequenceExhausted`, `fileSequenceBelowIssued`). Extensões: `PaymentRequestException::settlementRequired()`, `BranchException::bankAccountHasCnabConfig()` / `bankAccountBranchLocked()`.
- **Services:** `PaymentSettlementService` (create, confirm, cancel, release de item), `CnabConfigService` (create semeia `last_file_sequence` com o maior NSA já emitido para a conta), `CnabRemittanceService` (dry-run puro, sem persistência), `CnabFileService` (request, generate, markFailed, retry, regenerate, download, recoverStuck), `BranchBankAccountService` (guards de exclusão e troca de filial).
- **Actions de domínio:** `App\Actions\Settlement\*` (Create, Confirm, Cancel, ReleaseItem) e `App\Actions\Cnab\*` (RequestGeneration, Retry, Regenerate, Download, ValidateRemittance, RecoverStuckCnabFiles).
- **Integração CNAB** (`App\Integrations\Cnab\`, sem HTTP): contrato `CnabRemittanceAdapter`, `CnabAdapterResolver` (bind no `AppServiceProvider`), `FixedWidthFormatter`, `Itau\Itau240Layout` (posições), `Itau\Itau240RemittanceAdapter` (segmentos A/B para crédito/TED/PIX por chave, J/J-52 para boleto), `CnabRemittanceValidator` (estrutural 240: largura, sequência de registros, trailers, somatórios, NSA). Suporte: `App\Support\BoletoBarcode` (linha digitável ↔ código de barras e DVs).
- **Observers:** `PaymentSettlementObserver`, `CnabFileObserver` (forceDelete apaga o `.rem` após o commit). `BranchBankAccountObserver` estendido.
- **Hooks em código existente:**
  - `PaymentRequestService::transitionStatus()` recebe `?PaymentSettlement $settlement`; para `Settled` exige baixa `draft` com item ativo da PR, senão `settlementRequired()`;
  - `TransitionStatusAction` (PR) não oferece mais `Settled`;
  - `PaymentRequestStatusChanged` passou a `ShouldDispatchAfterCommit`;
  - `PaymentRequest::isEditableBy()` retorna `false` para não-Adm enquanto a PR está em baixa `draft`.
- **Armazenamento:** disco `rjet.cnab.disk` (visibility `private`), path `cnab/{branch_id}/{YYYY}/{MM}/{cnab_file_id}.rem`. O path nunca aparece na UI; o nome de download vem de `fileName()`.
- **Config:** `config/rjet.php` → `rjet.cnab` (`disk`, `directory`, `max_items` 500, `line_ending` `\r\n`, `queue.connection`, `queue.name`, `recovery.margin_seconds` 60, `recovery.max_age_minutes` 20). `config/queue.php` → conexões `cnab_database` e `cnab_redis`.
- **Factories / seeder:** factories das 5 entidades; `BranchBankAccountFactory::itau()`, estados de boleto em `PaymentRequestBankDetailsFactory`; `CnabConfigSeeder` chamado pelo `DevelopmentSeeder` (só dev).

### Filament (painel admin)

- **`CnabConfigResource`** (grupo settings, sort 30): Form / Infolist / Table; Pages List, Create, Edit, View; `CnabFilesRelationManager` somente leitura.
- **`PaymentSettlementResource`** (grupo operations, sort 20): Pages List, **Create** (Page de seleção com `EligiblePaymentRequestsTable` + `CreatePaymentSettlementBulkAction`) e View. **Sem Edit**: data, conta e filial são imutáveis; para mudar, cancelar e recriar. A seção CNAB do infolist faz polling a cada 5 s enquanto o arquivo atual está `queued` ou `generating`.
- **RelationManagers:** `ItemsRelationManager` (com `ReleasePaymentSettlementItemAction`), `CnabFilesRelationManager`.
- **Actions na View:** `ValidateCnabRemittanceAction` (dry-run síncrono, relatório em modal `filament/cnab/validation-report.blade.php`, não consome NSA), `GenerateCnabFileAction`, `RetryCnabFileGenerationAction`, `RegenerateCnabFileAction` (Adm, com motivo), `DownloadCnabFileAction`, `ViewCnabValidationErrorsAction` (`filament/cnab/file-errors.blade.php`), `ConfirmPaymentSettlementAction`, `CancelPaymentSettlementAction`.

### Autorização e regras de negócio

- **Policies:** `PaymentSettlementPolicy` (Operador/Adm operam; `confirm`/`cancel`/`generateCnab`/`update` só em `draft`; delete/forceDelete só Adm em baixa `cancelled`), `CnabConfigPolicy` (Operador lê; escrita só Adm), `CnabFilePolicy` (`download` só em `generated`; `retry` em `failed` com baixa `draft`; `regenerate` só Adm; update/delete sempre `false`).
- **Cliente:** nega tudo em baixa, config e arquivo.
- **Elegibilidade:** PR `Launched`, não trashed, sem item ativo em outra baixa. Uma baixa = uma filial = uma conta = no máximo um arquivo ativo (`mixedBranches` para seleção misturada).
- **Datas:** gerar CNAB exige `settlement_date >= hoje`; confirmar exige `settlement_date <= hoje` e nenhum arquivo `queued`/`generating`. Data futura: cria, gera, sobe no banco, confirma no dia. Data passada: cria e confirma sem CNAB.
- **Confirmação:** transação única, tudo ou nada; cada PR `Launched → Settled` via `transitionStatus(..., $settlement)` com histórico em `payment_request_status_history`. PR alterada desde a seleção (`net_amount != amount`) bloqueia.
- **Cancelamento:** libera itens (`released_at`), marca arquivo `generated` como `superseded` (bloqueia download); PRs voltam a ser elegíveis.
- **Dry-run tudo ou nada:** qualquer item inválido reprova o arquivo inteiro (`failed` + `cnab_file_items` com `validation_errors`). Inelegíveis para CNAB: arrecadação/concessionária (`utility_bill_not_supported`) e PIX só com QR (`pix_qr_code_not_supported`).
- **NSA:** atribuído uma vez por `CnabFile` com `lockForUpdate`; retry do mesmo job reaproveita o `file_sequence`; Retry/Regenerar criam **novo** `CnabFile` (novo NSA).
- **Download:** confere existência e checksum SHA-256; divergência → `fileIntegrityCheckFailed` + log crítico. Sem `temporaryUrl`. Primeiro download grava `downloaded_at`/`downloaded_by`; todo download dispara `CnabFileDownloaded`.
- **Conta da filial:** exclusão bloqueada com `CnabConfig` viva; `branch_id` travado se a conta já foi usada por baixa ou config.

### Events / Listeners / Notifications / Jobs

| Artefato | Detalhe |
|----------|---------|
| `PaymentSettlementCreated` / `Confirmed` / `Cancelled` | `LogPaymentSettlementActivity` (sync, log estruturado) |
| `PaymentRequestStatusChanged` (existente) | Agora `ShouldDispatchAfterCommit`: não loga transição revertida pela transação da baixa |
| `CnabFileGenerationRequested` | `ShouldDispatchAfterCommit` → `QueueCnabFileGeneration` → `GenerateCnabFileJob::dispatch()` |
| `CnabFileGenerated` | `NotifyCnabFileGenerated` (`ShouldQueue`) → `CnabFileGeneratedNotification`; `LogCnabFileActivity` |
| `CnabFileGenerationFailed` | `NotifyCnabFileGenerationFailed` (`ShouldQueue`, **`$afterCommit = true`**) → `CnabFileGenerationFailedNotification`; `LogCnabFileActivity` |
| `CnabFileDownloaded` | `LogCnabFileActivity` (usuário, IP, NSA) |
| Notifications | `mail` + `database`, `ShouldQueue`, destino = quem solicitou a geração; link para a View da baixa, nunca para o arquivo |

**`GenerateCnabFileJob`** (`App\Jobs\Cnab`):

| Parâmetro | Valor |
|-----------|-------|
| Conexão | `config('rjet.cnab.queue.connection')` ← `RJET_CNAB_QUEUE_CONNECTION` (`cnab_database` default; `cnab_redis` em produção; `sync` no phpunit) |
| Fila | `cnab` (`RJET_CNAB_QUEUE`) |
| `retry_after` da conexão | 240 s (`RJET_CNAB_QUEUE_RETRY_AFTER`) |
| `$timeout` | 120 |
| `$tries` / `$maxExceptions` | 10 / 3 |
| `$backoff` | `[10, 30, 60]` |
| Middleware | `WithoutOverlapping(payment_settlement_id)->releaseAfter(30)->expireAfter(180)`. **Sem `dontRelease()`**: a duplicata volta à fila em vez de ser descartada |
| `failed()` | Marca `failed` se o arquivo não estiver terminal; `failure_reason` = mensagem de usuário da `BusinessException` ou `generation_interrupted`; exceção crua só no log |

Invariante de tempo: `timeout 120 < lock 180 < retry_after 240`, e `120 < worker --timeout 150 < 240`. Worker morto em t0: o lock expira em t0+180, a conexão CNAB reentrega em t0+240 e `generate()` retoma o **mesmo** `CnabFile` com o mesmo NSA. Cada release conta como tentativa: 10 tentativas cobrem 6 releases + 3 exceções + 1 sucesso. Os demais jobs seguem na conexão default (`retry_after` 90).

Em `CnabFileService::generate()`: `startGenerating()` sempre atualiza `updated_at` (execução resgatada não parece parada); a transação final recarrega com `lockForUpdate` e só grava `generated` se o status ainda for `generating`. Caso contrário apaga o `.rem` recém-gravado e não dispara `CnabFileGenerated`, sem sobrescrever um `failed` do reconciliador.

**Reconciliador `cnab:recover-stuck-files`** (`CnabRecoverStuckFilesCommand` → `RecoverStuckCnabFilesAction` → `CnabFileService::recoverStuck()`):

- Agendado em `routes/console.php`: `everyFiveMinutes()->withoutOverlapping()`.
- Seleciona `status IN (queued, generating)` (`CnabFileStatus::inProgressValues()`), não trashed, com `updated_at` parado há mais de **300 s** (`retry_after` 240 da conexão CNAB + `margin_seconds` 60).
- Itera com `chunkById(100)` por id, **sem** `orderBy('updated_at')`. Cada arquivo é revalidado sob `lockForUpdate`.
- Arquivo criado há **20 min** ou mais (`max_age_minutes`): `markFailed()` com `cnab_files.messages.generation_interrupted` → notificação de falha → Retry aparece na UI. Log `warning`.
- Senão: toca `updated_at` por update direto (preserva `updated_by`) e, após o commit, reenfileira o **mesmo** `CnabFile`. Log `info`. No máximo um resgate por arquivo a cada 5 min.
- `--dry-run`: só conta (`requeued` / `failed`), sem alterar nada.
- Não cria arquivo, não mexe em NSA, não toca `generated`/`failed`/`superseded`, não altera a baixa, não remove jobs nem libera lock.
- **Sem índice novo e sem coluna nova** (contador de tentativas e índice parcial em `updated_at` recusados pela DBA em 2026-09-27). O índice `status` de `cnab_files` basta no volume atual (~70 pagamentos/dia).

### i18n

- Novos: `lang/{pt_BR,en}/payment_settlements.php`, `cnab_configs.php`, `cnab_files.php`.
- Extensões: `enums.php` (4 enums novos), `notifications.php` (gerado/falha), `payment_requests.php` (campos de baixa na PR e `settlement_required`), `branch_bank_accounts.php` (guards de conta).
- `failure_reason` e erros de item gravados como mensagem de usuário i18n; detalhe técnico só no log.

### Testes (Pest)

**200 passed · 592 assertions** (2026-09-27, reexecutado para este documento):

```bash
docker compose exec -T app php artisan test --compact --filter='Settlement|Cnab|BoletoBarcode|PaymentRequestStatusTransition|Itau240RemittanceAdapterTest|BranchBankAccountSettlementGuards'
```

A suíte completa não foi reexecutada neste documento.

| Área | Arquivo |
|------|---------|
| Service da baixa (create/confirm/cancel/release) | `tests/Feature/PaymentSettlementServiceTest.php` |
| Elegibilidade (RF030) | `tests/Feature/PaymentSettlementEligibilityTest.php` |
| Policies | `tests/Feature/PaymentSettlementAuthorizationTest.php` |
| Filament baixa | `tests/Feature/PaymentSettlementResourceTest.php` |
| Edição de PR em baixa `draft` | `tests/Feature/PaymentRequestEditabilityInSettlementTest.php` |
| Guard `Launched → Settled` (atualizado) | `tests/Feature/Services/PaymentRequestStatusTransitionTest.php` |
| Guards de conta da filial | `tests/Feature/BranchBankAccountSettlementGuardsTest.php` |
| Config CNAB (service / Filament) | `tests/Feature/CnabConfigServiceTest.php`, `tests/Feature/CnabConfigResourceTest.php` |
| Dry-run | `tests/Feature/CnabRemittanceDryRunTest.php` |
| Adapter Itaú 240 (golden files) | `tests/Feature/Itau240RemittanceAdapterTest.php` + `tests/Fixtures/cnab/itau240/*.rem` (boleto Itaú, boleto outro banco, crédito Itaú, TED, PIX chave, misto) |
| Job | `tests/Feature/GenerateCnabFileJobTest.php` |
| Reconciliador | `tests/Feature/CnabRecoverStuckFilesCommandTest.php` |
| Download | `tests/Feature/CnabFileDownloadTest.php` |
| Schema / índices parciais / FKs | `tests/Feature/SettlementSchemaConstraintsTest.php` |
| Unit | `tests/Unit/BoletoBarcodeTest.php`, `tests/Unit/CnabRemittanceValidatorTest.php`, `tests/Unit/SettlementEnumsTest.php` |

---

## Decisões relevantes

1. **Baixa em dois passos** (`draft` → `settled`): gerar arquivo não é pagamento. `Settled` só na confirmação, via `PaymentSettlementService::confirm`.
2. **CNAB opcional:** banco sem adapter ou pagamento feito fora do sistema pode ser baixado sem remessa.
3. **`CnabConfig` 1:1 com `BranchBankAccount`**, não `(branch_id, bank_id)`: o header precisa de agência/conta/DV e a filial pode ter duas contas no mesmo banco.
4. **NSA por conta:** nova config de uma conta semeia o sequencial a partir do maior NSA já emitido; edição manual abaixo disso é rejeitada.
5. **Dry-run tudo ou nada** e também como gate dentro do job; o dry-run sob demanda não consome NSA.
6. **Gerador próprio** (`Itau240Layout` + adapter), sem biblioteca: as opções de mercado cobrem cobrança, não SISPAG.
7. **Fila dedicada** (revisão de 2026-09-27): o `retry_after` 90 da conexão default + `dontRelease()` deixavam o arquivo em `generating` para sempre após crash de worker. `retry_after` é da conexão, por isso a conexão própria.
8. **Reconciliador como rede de segurança**, não caminho principal; limites por idade (`created_at`) e throttle por `updated_at` no lugar de contador de tentativas.
9. **Notificação de falha só após o commit** (`$afterCommit` no listener): `markFailed()` roda em transação, inclusive dentro da transação do reconciliador.
10. **`PaymentRequestStatusChanged` após commit:** a confirmação transiciona várias PRs numa transação externa.
11. **Sem Edit da baixa:** imutável; remover item é permitido em `draft` sem arquivo ativo.

---

## Fora de escopo

| Item | Fase prevista |
|------|---------------|
| API REST / Sanctum / Swagger | — |
| Arquivo de **retorno** CNAB (conciliação) | — (`cnab_file_items.reference` já é determinística para uso futuro) |
| Tributos / concessionárias (segmentos N/O) | — (baixa manual sem CNAB continua possível) |
| PIX por QR Code no CNAB | — (PR com chave **e** QR usa a chave) |
| Comprovantes anexados à baixa | — |
| Dashboard "total pago no mês" / Excel / widgets | F8 |
| Outros bancos além do Itaú | Novo case em `CnabLayout` + adapter + registro no resolver |

---

## Como validar

### 1. Ambiente e migrations

```bash
docker compose exec app php artisan migrate

# Opcional (dev): config CNAB demo para conta Itaú
docker compose exec app php artisan db:seed --class=CnabConfigSeeder
```

### 2. Variáveis de ambiente (`.env`)

| Variável | Default no código | `.env.example` | Descrição |
|----------|-------------------|----------------|-----------|
| `RJET_CNAB_DISK` | `FILESYSTEM_DISK` | vazio | Disco privado dos `.rem` (S3 em produção) |
| `RJET_CNAB_MAX_ITEMS` | `500` | `500` | Itens por baixa |
| `RJET_CNAB_QUEUE_CONNECTION` | `cnab_database` | `cnab_redis` | Conexão do `GenerateCnabFileJob` |
| `RJET_CNAB_QUEUE` | `cnab` | `cnab` | Nome da fila |
| `RJET_CNAB_QUEUE_RETRY_AFTER` | `240` | `240` | `retry_after` das conexões `cnab_*` |

**Atenção — conexão da fila CNAB.** Produção e o `.env` local precisam de `RJET_CNAB_QUEUE_CONNECTION=cnab_redis`. Sem a variável, o default `cnab_database` grava o job na tabela `jobs`, e nem o worker `cnab_redis` de produção nem o `queue:listen` do `composer dev` (conexão default = redis) o enxergam: o arquivo fica em `queued` até o reconciliador marcar `failed` aos 20 min. Em 2026-09-27 o `.env` local **não** tinha a variável. O `phpunit.xml` fixa `sync`, então os testes não pegam esse erro de ambiente.

### 3. Workers e agendador

| Ambiente | Processo | Comando |
|----------|----------|---------|
| Produção | `[program:queue-worker-cnab]` (`docker/supervisor/supervisord-prod.conf`) | `queue:work cnab_redis --queue=cnab --sleep=3 --timeout=150 --max-time=3600`, `numprocs=1`, `stopwaitsecs=160` |
| Container dev | `[program:laravel-queue-cnab]` (`docker/supervisord.conf`) | mesmo comando; `stopwaitsecs=160` |
| `composer dev` | `queue:listen --queue=default,cnab` | Aceitável só em dev (usa a conexão default, `retry_after` 90) |

- `--timeout=150` tem de ficar entre o timeout do job (120) e o `retry_after` (240). `stopwaitsecs=160` evita que deploy mate geração em curso. Requer `pcntl`.
- **Proibido** consumir a fila `cnab` pela conexão default em produção: volta o `retry_after` 90.
- **Agendador de produção ausente.** `supervisord-prod.conf` **não** tem `schedule:work` nem cron com `schedule:run`. Sem ele, `cnab:recover-stuck-files` não roda (e `approvals:escalate-sla`, da F4, também não). O `docker/supervisord.conf` de dev tem `laravel-schedule`.

Conferência rápida:

```bash
docker compose exec app php artisan config:show rjet.cnab.queue
docker compose exec app php artisan schedule:list
docker compose exec app php artisan cnab:recover-stuck-files --dry-run
```

### 4. Testes Pest (subset Fase 7)

```bash
docker compose exec -T app php artisan test --compact --filter='Settlement|Cnab|BoletoBarcode|PaymentRequestStatusTransition|Itau240RemittanceAdapterTest|BranchBankAccountSettlementGuards'
```

Esperado: **200 passed, 592 assertions**.

### 5. Smoke manual (Filament)

1. Adm: Configurações → **Configurações CNAB** — criar config para uma conta Itaú (banco 341) ativa da filial (ou usar o seeder). Operador só visualiza.
2. Operador: Operações → **Baixas** → Nova baixa — filtrar filial/vencimento, selecionar PRs `Launched`, bulk "Baixar selecionados" com data **futura** e conta. Baixa nasce em `draft`; PRs continuam `Launched` e o Operador não consegue editá-las.
3. View da baixa → **Validar remessa**: relatório em modal, nada persistido.
4. **Gerar CNAB**: arquivo `queued → generating → generated`. A seção CNAB atualiza sozinha a cada 5 s enquanto o arquivo está na fila ou em geração. Notificação ao solicitante. Com item inválido (ex.: boleto de concessionária), esperar `failed` + erros por item + botão Retry.
5. **Baixar arquivo**: nome `341_{CNPJ}_{YYYYMMDD}_{NSA}.rem`, linhas de 240 posições com `\r\n`. Cliente não vê o resource.
6. Adm: **Regenerar** com motivo → anterior `superseded` (download bloqueado), novo NSA.
7. **Confirmar baixa** (com data de hoje ou passada): PRs `Settled` com histórico; em PR avulsa, `TransitionStatusAction` não oferece `Settled`.
8. Em outra baixa `draft`: **Cancelar** → itens liberados, arquivo `generated` vira `superseded`, PRs elegíveis de novo.
9. Conta da filial com config viva: excluir (individual ou `DeleteBulkAction`) deve notificar o bloqueio.

---

## Riscos / pendências

- **Layout Itaú 240 não conferido contra o manual SISPAG vigente.** Os golden files (`tests/Fixtures/cnab/itau240/`) protegem contra **regressão**, não garantem **conformidade** com o banco. Códigos de forma (01/41/45/30/31), campos reservados e convenção de nome de arquivo precisam de homologação real com o Itaú **antes de produção**.
- **`ForceDeleteBulkAction` de conta referenciada ainda responde 500** (FK `restrict` sem guard na UI). O delete individual e o `DeleteBulkAction` já notificam o bloqueio. Pendente: envolver o force delete em lote no mesmo `runGuarded`.
- **Agendador de produção ausente:** adicionar `schedule:work` ao `supervisord-prod.conf` (ou cron `schedule:run` por minuto). Sem isso o reconciliador e o `approvals:escalate-sla` não rodam.
- **`RJET_CNAB_QUEUE_CONNECTION` fora do `.env`:** default de código (`cnab_database`) diverge do `.env.example` (`cnab_redis`). Conferir em cada ambiente.
- **Índice do reconciliador:** reavaliar só acima de ~100 mil linhas em `cnab_files` ou se o `EXPLAIN` mostrar seq scan relevante.

---

## Referências

| Documento | Caminho |
|-----------|---------|
| Blueprint (plano de implementação) | [`.ai/blueprints/fase-7-baixa-cnab.md`](../blueprints/fase-7-baixa-cnab.md) — §0.7 item 21, §11.2, §11.3 |
| Arquitetura e decisões | [`.ai/arquitetura/fase-7-baixa-cnab.md`](../arquitetura/fase-7-baixa-cnab.md) — §3.11 (fila dedicada e recuperação) |
| DRF v1 (RF030–RF034) | [`.ai/requisitos/drf-financeiro-v1.md`](../requisitos/drf-financeiro-v1.md) |
| Entrega Fase 5 | [`.ai/entregas/fase-5-lote-solicitacoes.md`](fase-5-lote-solicitacoes.md) |
| Config CNAB / filas | [`config/rjet.php`](../../config/rjet.php), [`config/queue.php`](../../config/queue.php) |

---

## Histórico

| Versão | Data | Descrição |
|--------|------|-----------|
| 1.0 | 2026-09-27 | Documento de entrega da Fase 7 |
