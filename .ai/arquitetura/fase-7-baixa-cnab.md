# Arquitetura: Fase 7 — Baixa em lote e CNAB

> **Escopo:** RF030–RF034  
> **Fora de escopo:** Fase 8 (dashboard/Excel). Não redesenhar `PaymentRequest`, workflow de alçadas, importação de planilha (F5) nem lote de anexos (F6). Só **hooks mínimos** e documentados em `PaymentRequest` (ver §2.4).  
> **Stack:** Laravel 12 · Filament 5 · PHP 8.4 · Pest 4 · Livewire 4 · PostgreSQL · Redis  
> **Fonte:** DRF v1.2 (§2.2 item 7, §2.3, §2.4, §2.5, RF030–RF034, RNF001–RNF012, §5, §6) + `PROJECT.md` (regras de negócio da tela de baixa) + arquiteturas F1–F6 + código real  
> **Autor:** architect (subagent)  
> **Data:** 2026-09-26  
> **Status:** Revisão `dba` concluída (**aprovado com ressalvas**, §21) e blueprint gravado em `.ai/blueprints/fase-7-baixa-cnab.md`. Pronto para `implementer`.
> **Revisão 2026-09-27:** fila CNAB dedicada + recuperação de execução interrompida (§3.11). Substitui A7 (§3.10), "Fila: `default`" e o `dontRelease()` de §9.4. O restante da Fase 7 não muda.

---

## 1. Contexto

As Fases 1–6 entregaram a hierarquia `Company → Branch → BranchBankAccount`, o cadastro manual de bancos, a solicitação de pagamento com dados bancários dedicados (boleto, PIX e transferência), o gate de aprovação antes de `Launched`, a importação em lote e o lote de anexos. A **Fase 7** fecha o ciclo operacional do BPO em três partes:

1. **Baixa em lote:** o operador filtra solicitações elegíveis (filial, vencimento), escolhe um subconjunto e informa **data de baixa** e **conta pagadora**. Tudo fica gravado em `PaymentSettlement`.
2. **Remessa CNAB 240:** a partir da baixa, gera-se o arquivo CNAB 240 para **upload manual** no internet banking. O banco inicial é o Itaú, via Strategy/adapter por banco, com validação prévia (dry-run) e job assíncrono.
3. **Liquidação:** a confirmação da baixa leva as solicitações de `Launched` para `Settled`, com histórico.

Regra de negócio literal do `PROJECT.md` (seção Regras de Negócio): *"Depois a tela de baixa com filtros de filial/data vencimento. Na tela de baixa selecionar o banco que foi pago para gravar em todos os pagamentos filtrados e também inserir a data de baixa (data pagamento banco). Status será Pagamento efetivado."* e *"Gerar arquivo cnab de pagamento para inserir no banco. Cnab para boleto, cnab para transferência e cnab para pix. Na configuração dos cnabs, inserir dados e parâmetros da conta de cada filial do cliente."*

| Capacidade já existente | Origem |
|-------------------------|--------|
| `PaymentRequestStatus` (`Requested` → `Launched` → `Settled`) + `canTransitionTo()` | F3 |
| `PaymentRequestService::transitionStatus()` (histórico + `PaymentRequestStatusChanged`) | F3/F4 |
| Gate `Launched` exige `hasApprovedForLaunch()` (fingerprint) | F4 |
| `PaymentRequestBankDetails` (linha digitável/barcode, PIX chave/QR, transferência) | F3 |
| `BranchBankAccount` (agência, conta, DV, `bank_id`, `bank_code` snapshot, `is_active`) | F1/F2 |
| `Bank` (código BACEN, ISPB), cadastro manual; Itaú `341` no `BankSeeder` | F2 |
| Padrão de lote operacional (status + job + `markFailed` + Policy Operador/Adm + grupo `operations`) | F5/F6 |
| Padrão de download privado (`Storage::disk()->download()` atrás de Policy) | F5 (`DownloadImportSpreadsheetAction`) |
| Notificações `mail` + `database` para o criador do lote | F5/F6 |

### 1.1 Estado real do código (2026-09-26)

| Artefato | Status |
|----------|--------|
| `App\Models\PaymentRequest` | **Existe**: `status` (cast `PaymentRequestStatus`), `payment_method`, `net_amount` decimal:2, `due_date`, `branch_id`; scopes `visibleTo`, `status`, `dueBetween`, `forBranch`; relação `bankDetails()` |
| `App\Models\PaymentRequestBankDetails` | **Existe**: `deposit_type`, `pix_key_type`, `pix_key`, `pix_qr_code`, `digitable_line` (54), `barcode` (48), `bank_id`, `agency`, `agency_digit`, `account_number`, `account_digit`, `account_type`, `holder_name`, `holder_document` |
| `App\Models\BranchBankAccount` | **Existe**: `branch_id`, `bank_id`, `bank_code` (3), `bank_name`, `agency`, `agency_digit`, `account_number`, `account_digit`, `account_type`, `holder_name`, `is_default`, `is_active`; SoftDeletes; HasBlameable |
| `App\Models\Branch` / `Company` | **Existem**: `document` (CNPJ), `legal_name`, `is_active` |
| `App\Models\Bank` | **Existe**: `code`, `name`, `ispb`, `is_active` |
| `App\Enums\PaymentRequestStatus` | **Existe**: `Requested → Launched → Settled`; ícones `heroicon-o-*` |
| `App\Enums\PaymentMethod` / `DepositType` / `PixKeyType` / `AccountType` | **Existem**: `Boleto`/`Deposit`; `Pix`/`Transfer`; `Random`/`Cpf`/`Phone`/`Email`; `Checking`/`Savings` |
| `PaymentRequestService::transitionStatus()` | **Existe**: Operador/Adm + `isVisibleTo`; `Launched` exige aprovação; **`Launched → Settled` hoje é livre**, sem dados de baixa |
| `TransitionStatusAction` (Filament PR) | **Existe**: oferece todas as `allowedTransitions()`, **inclusive `Settled`** |
| `PaymentRequest::isEditableBy()` | Adm edita qualquer status; Operador edita `Requested`/`Launched` |
| Models `PaymentSettlement`, `CnabConfig`, `CnabFile`, `CnabFileItem` | **Não existem** |
| Events `CnabFileGenerated` e correlatos | **Não existem** (DRF §6: "Job CNAB concluído → Registrar download", fila Sim) |
| Jobs existentes | `App\Jobs\PaymentRequest\ProcessImportBatchJob`, `App\Jobs\Attachment\RenameAttachmentBatchJob` (`tries=3`, `backoff=[10,30,60]`, `WithoutOverlapping(...)->dontRelease()`, `failed()` → `markFailed`) |
| Integrations existentes | `App\Integrations\Ocr\BoletoOcrClient` (interface) + `Local`/`Null`; `App\Integrations\Spreadsheet\SpreadsheetReader` + `OpenSpout...`; bind no `AppServiceProvider::register()` |
| Biblioteca CNAB em `composer.json` / `composer.lock` | **Nenhuma** (busca por `cnab`, `boleto`, `laravel-boleto`: zero resultados) |
| Util de conversão linha digitável ↔ código de barras | **Não existe** (`LocalBoletoOcrClient` só extrai linha digitável, valor e vencimento) |
| Morph map | `supplier`, `payment_request`, `user`, `attachment_batch`; **a F7 não precisa de morph** |
| Config `rjet.*` | `attachments`, `ocr`, `imports`; **sem** `cnab` |
| Painel Filament | Único `admin`. Grupo `operations`: `PaymentRequest` (1), `ImportBatch` (15), `AttachmentBatch` (16). Grupo `settings`: `User` (1), `ApprovalRule` (20), `ImportTemplate` (40). Grupo `registrations`: `Company` (1) … `Bank` (6) |
| Activity log Spatie | **Não** usado; o histórico é dedicado por entidade (`payment_request_status_history`, `attachment_batch_item_classifications`) |
| `.ai/rules/` | **Ausente** nesta cópia; convenções vêm de `PROJECT.md`, `.ai/docs` e F1–F6 |
| Filament Blueprint vendor | `filament/blueprint` está em `require-dev`, mas `vendor/filament/blueprint/resources/markdown/planning/` **não existe** nesta cópia. A estrutura segue `.ai/skills/filament/SKILL.md` (igual à F6) |
| Tabela de riscos R02/R08/R09 | Só referenciada no DRF (linhas 116, 760, 780, 889); a tabela completa não está versionada. Leitura adotada: R02 = adapters por banco; R08 = qualidade do arquivo/dados; R09 = homologação bancária |

### 1.2 Preferências de projeto aplicadas

- Documento em **pt-BR**; classes, namespaces, tabelas e colunas em **inglês**.
- `agrupar_por_dominio`: Jobs, Events, Actions, Listeners e **Integrations** agrupados; Services, DTOs, Models e Policies flat.
- Comentários no código: nível mínimo, em inglês.
- Timezone de negócio: `America/Sao_Paulo`. "Hoje" em todas as regras de data usa esse timezone.
- Traduções obrigatórias (`pt_BR` + `en`), um arquivo por Resource.

---

## 2. Requisitos

### 2.1 Funcionais (Fase 7)

| RF | Descrição | Artefato principal |
|----|-----------|-------------------|
| RF030 | Tela de baixa em lote com filtros (filial, vencimento, status) e seleção de subconjunto | Página `CreatePaymentSettlement` (tabela Filament + bulk action) + `PaymentRequest::scopeEligibleForSettlement` |
| RF031 | Data de baixa e banco informados para o lote antes da geração/registro | `PaymentSettlement.settlement_date` + `branch_bank_account_id` |
| RF032 | Configuração CNAB 240 por filial + banco (Adm), auditável | `CnabConfig` (1:1 com `BranchBankAccount`) + HasBlameable + `CnabFile.config_snapshot` |
| RF033 | Geração assíncrona (`GenerateCnabFileJob`) com Strategy por banco, dry-run e falha recuperável | `CnabRemittanceAdapter` + `Itau240RemittanceAdapter` + `CnabRemittanceValidator` + `CnabFile`/`CnabFileItem` |
| RF034 | Download restrito, nome e formato conforme convenção Itaú 240 | `DownloadCnabFileAction` + `CnabFilePolicy::download` + `adapter->fileName()` |

**Regras de negócio:**

| RN | Conteúdo | Decisão nesta arquitetura |
|----|----------|---------------------------|
| RN032.1 | Layout 240 como padrão universal | Enum `CnabLayout` só com layouts 240; `CnabRemittanceValidator` é estrutural para 240 |
| RN032.2 | Itaú inicial; demais bancos via adapters (R02) | `CnabAdapterResolver` mapeia `CnabLayout` → adapter; MVP = `Itau240` |
| RN033.1 | Strategy/adapter por banco | Contrato `App\Integrations\Cnab\CnabRemittanceAdapter` (§9.5) |
| RN033.2 | Dry-run antes do arquivo final (R08) | Dry-run síncrono sob demanda **e** gate obrigatório dentro do job (§3.5) |
| RN006.4 | Apenas Adm gerencia CNAB | `CnabConfigPolicy`: escrita só Adm; Operador só lê |
| DRF §2.4 | CNAB só manual, sem API bancária | Nenhuma chamada HTTP; o arquivo vai para o disco privado e o download é autenticado |

**Critérios de aceite (DRF):**

RF030:

- [ ] Filtros retornam apenas itens elegíveis.
- [ ] Usuário seleciona subconjunto para baixa.

RF031:

- [ ] Validações impedem lote sem data ou banco quando obrigatório.
- [ ] Dados gravados em **PaymentSettlement**.

RF032:

- [ ] Configuração Itaú 240 disponível e usada na geração inicial.
- [ ] Alterações auditáveis (`created_by`/`updated_by`).

RF033:

- [ ] Job completa com **CnabFile** e **CnabFileItem** consistentes.
- [ ] Falhas geram registro recuperável pelo operador.

RF034:

- [ ] Download restrito a perfis/filiais autorizados.
- [ ] Nome e formato seguem convenção CNAB 240 Itaú acordada.

### 2.2 Não-funcionais aplicáveis

| RNF | Aplicação na F7 |
|-----|-----------------|
| RNF001 | UUID; `timestampsTz`/`softDeletesTz`; `created_by`/`updated_by` via `HasBlameable` |
| RNF002 | `amount`, `total_amount` em `decimal(10,2)`; somas com bcmath (`bcadd`), nunca float; no CNAB, valor em centavos inteiros via string |
| RNF003 | `status`, `layout`, `payment_type` como `string` + Enum PHP (`HasLabel`/`HasColor`/`HasIcon`) |
| RNF005 | `Launched → Settled` sempre via `transitionStatus()` → linha em `payment_request_status_history` |
| RNF006 | Policies por papel; Cliente sem acesso a baixa, CNAB e download |
| RNF008 | Dry-run (validação por item + estrutural do arquivo) antes de gravar o arquivo final |
| RNF009 | `GenerateCnabFileJob` em fila |
| RNF010 | Events no passado, `final`, payload mínimo (§8) |
| RNF011 | ~70 pagamentos/dia; baixas de dezenas de itens; sem cache |
| RNF012 | Disco private (local em dev, S3 em prod) via `config('rjet.cnab.disk')`; o `.rem` não é PDF nem imagem porque é artefato gerado pelo sistema, não upload |

### 2.3 API REST

> **Decisão fechada: NÃO expor API nesta fase.** O DRF não pede consumo externo de baixa/CNAB; o MVP é Filament interno. A restrição §2.4 ("sem integração bancária via API") também afasta endpoints de remessa. Sem Sanctum/Passport/Swagger na F7. Reavaliar só se surgir consumidor externo (ex.: ERP do cliente consultando baixas).

### 2.4 Fora de escopo e hooks

| Item | Fase | Tratamento na F7 |
|------|------|------------------|
| Dashboard "total pago no mês" / Excel | 8 | `payment_settlements.settlement_date` + `settled_at` + itens com `amount` ficam consultáveis; sem widget na F7 |
| Arquivo de **retorno** CNAB (conciliação automática) | — | Fora do DRF. `cnab_file_items.reference` ("seu número") já é gerado de forma determinística para permitir retorno futuro |
| Pagamento de tributos/concessionárias (segmento O/N) | — | Fora do escopo. Linha digitável de arrecadação (48 dígitos, inicia com `8`) é **inelegível para CNAB** (erro de dry-run); baixa manual sem CNAB continua possível |
| PIX por QR Code no CNAB | — | Fora do MVP. PR só com `pix_qr_code` é inelegível para CNAB. Se tiver chave **e** QR, **usa a chave** (fecha o hook F3 "CNAB F7 escolhe fonte") |
| Redesign PR / alçadas / import / anexos | — | **Não** |

**Hooks mínimos em código existente** (análogos ao estreitamento da F4 sobre `Launched`):

| Arquivo | Mudança | Motivo |
|---------|---------|--------|
| `App\Services\PaymentRequestService::transitionStatus()` | Novo parâmetro opcional `?PaymentSettlement $settlement = null`. Para `$to === Settled`, exige `$settlement` em `draft` que contenha a PR como item ativo; senão lança `PaymentRequestException::settlementRequired()` | Toda PR `Settled` passa a ter data de baixa e conta registradas (RF031). Reusa histórico e evento existentes |
| `App\Filament\Resources\PaymentRequests\Actions\TransitionStatusAction` | Remove `Settled` das opções do Select | A baixa passa a ser feita só pela tela de baixa |
| `App\Events\PaymentRequest\PaymentRequestStatusChanged` | Implementar `ShouldDispatchAfterCommit` | Na confirmação, `transitionStatus()` roda dentro da transação externa da baixa; evita logar mudança revertida |
| `App\Models\PaymentRequest` | Relações `settlementItems()` (hasMany) e `activeSettlementItem()` (hasOne, `released_at IS NULL`); scope `scopeEligibleForSettlement()`; `isEditableBy()` retorna `false` para **não-Adm** quando há item ativo em baixa `draft` | Elegibilidade (RF030) e congelamento dos dados usados no CNAB |
| `PaymentRequestInfolist` (opcional) | Entries somente leitura: data de baixa, conta pagadora, link para a baixa | Rastreabilidade na PR |
| `tests/Feature/Services/PaymentRequestStatusTransitionTest.php` | Os casos `Launched → Settled` passam a criar `PaymentSettlement` e passá-la ao service | Consequência do guard (teste existente, não removido) |

---

## 3. Decisões de Design

### 3.1 Normalização e entidades

| Candidato | Decisão | Justificativa (matriz `database.md` §3) |
|-----------|---------|------------------------------------------|
| **PaymentSettlement** (baixa em lote) | **Dedicado** `payment_settlements` | Core do domínio financeiro; FKs críticas (filial, conta pagadora); status próprio; "movimentações financeiras" são dedicadas por regra do architect |
| **PaymentSettlementItem** (PR na baixa) | **Dedicado** `payment_settlement_items` | Estrutura específica (snapshot de valor, liberação); FK crítica para PR; unicidade "1 PR em no máximo 1 baixa ativa" exige FK real + índice parcial. Morph descartado: sem FK e entidade core |
| **CnabConfig** | **Dedicado** `cnab_configs`, **1:1 com `BranchBankAccount`** | Parâmetros bancários são core; FK crítica; divergem por banco. Não é key-value genérico (`database.md` §4 desaconselha) |
| **CnabFile** | **Dedicado** `cnab_files` | Artefato financeiro auditável; status e NSA próprios; FK para baixa e config |
| **CnabFileItem** | **Dedicado** `cnab_file_items`, append-only | Mapa item → posição no arquivo (lote, sequencial, forma, "seu número") + erros de validação. Filha "hard-owned" do arquivo (padrão `ImportBatchError`) |
| **Comprovantes da baixa** | **Não** nesta fase | O DRF não pede. Se vier, reusar morph `attachments` (`HasAttachments`) em `PaymentSettlement` |

**Chave da `CnabConfig` (fechada): `branch_bank_account_id`, e não `(branch_id, bank_id)`.**

- O DRF diz "por filial + banco", mas o header CNAB precisa de **agência, conta e DV**, que vivem em `BranchBankAccount`. A filial pode ter **duas contas no mesmo banco**; com `(branch_id, bank_id)` a config ficaria ambígua.
- `branch_bank_account_id` já implica filial (`branch_id`) e banco (`bank_id`). Guardar `branch_id`/`bank_id` na config seria dependência transitiva (viola 3NF).
- **Unicidade:** índice único **parcial** `(branch_bank_account_id) WHERE deleted_at IS NULL`: no máximo uma config viva por conta; soft delete libera para recriar (padrão `supportsPartialIndexes()` F1–F6). Config **inativa** (`is_active = false`) continua ocupando a vaga: desativar é pausa, **substituir** exige soft delete (DBA §21 #2).
- **NSA por conta, não por config (DBA §21 #2):** o banco numera remessas por conta/convênio. Ao criar config para uma conta que já teve config (soft-deleted), o Service inicializa `last_file_sequence` com `MAX(cnab_files.file_sequence)` dos arquivos das configs anteriores da mesma conta (`withTrashed`), e qualquer edição manual de `last_file_sequence` exige valor `>=` esse máximo. O unique `(cnab_config_id, file_sequence)` sozinho não impede reuso de NSA entre configs da mesma conta.

**Conta pagadora na baixa (fechada): `branch_bank_account_id`, e não `bank_id`.** O "banco que foi pago" (`PROJECT.md`) é a conta de débito da filial. O `Bank` é derivado (`branchBankAccount.bank`). Isso também resolve qual `CnabConfig` usar sem ambiguidade.

**Filial única por baixa (fechada).** A baixa pertence a **uma** filial (`branch_id`) porque a conta pagadora e o CNPJ do header do arquivo são da filial. A seleção com filiais misturadas é rejeitada (`PaymentSettlementException::mixedBranches()`).

**Desnormalizações intencionais** (comentário obrigatório em inglês na migration):

1. `payment_settlements.items_count` e `total_amount`: cache de listagem. A fonte de verdade é a soma dos itens ativos, recalculada no Service a cada mudança.
2. `payment_settlement_items.amount`: **snapshot** do `net_amount` no momento da seleção. É o valor que vai para o CNAB e para a baixa. Divergência com a PR vira erro de validação (`amount_changed`).
3. `cnab_files.config_snapshot` (json): parâmetros de config, conta e filial usados na geração. Auditoria histórica de "com que dados este arquivo foi gerado" sem tabela de histórico da config.
4. `cnab_files.items_count` / `total_amount` / `records_count`: métricas do arquivo gerado, imutáveis após `generated`.
5. `cnab_file_items.amount` e `reference`: snapshot do que foi escrito no arquivo.
6. `payment_settlements.branch_id` (DBA §21 #3): dependência transitiva de `branch_bank_account_id → branch_bank_accounts.branch_id`. Mantida para listagem/filtro por filial (índice `(branch_id, settlement_date)`), `scopeVisibleTo` e restrict próprio. Coerência garantida no Service (`bankAccountNotAllowed`); `BranchBankAccount.branch_id` não pode mudar enquanto houver baixa ou config referenciando a conta (guard no `BranchBankAccountService`). Sem FK composta (atípico no repo).
7. `cnab_files.layout` (DBA §21 #3): snapshot do `cnab_configs.layout` usado na geração (resolve o adapter sem ler json). Redundante com `config_snapshot` por intenção; `layout` da config é imutável após emissão.

### 3.2 O que é "baixa": dois passos na mesma entidade

**Semântica já fixada no código/DRF** (respeitada):

- `Launched` = "Lançada no sistema / enviada ao banco" (DRF §5.3; F4 §3.7 e linha 79: *"CNAB / baixa: continua exigindo `Launched`"*).
- `Settled` = "Liquidada / baixada" (DRF §5.3) = "Pagamento efetivado" (`PROJECT.md`).

**Opções avaliadas:**

| Opção | Descrição | Problema |
|-------|-----------|----------|
| A | `Settled` já no registro da baixa (um passo); CNAB gerado depois | Marca como liquidado algo que pode ainda nem ter ido ao banco; gerar remessa de algo "já pago" é contraditório e abre risco de pagamento duplo |
| B | `Settled` no sucesso do job CNAB | Gerar arquivo ≠ banco pagar. Mistura artefato técnico com liquidação e impede baixa de pagamentos feitos sem CNAB (bancos sem adapter) |
| **C (escolhida)** | **Dois passos:** (1) registrar a baixa em `draft` com itens, data e conta, gravados **antes** da geração (RF031); CNAB opcional a partir dela; (2) **confirmar** a baixa → PRs `Launched → Settled` | Um clique a mais, compensado pela coerência semântica e pela recuperação de falhas |

**Regras fechadas da opção C:**

| Regra | Decisão |
|-------|---------|
| Elegibilidade | PR `Launched`, não soft-deleted, sem item ativo em outra baixa |
| Criação | Grava `PaymentSettlement(draft)` + itens (snapshot `amount`) + `settlement_date` + `branch_bank_account_id`. PR **permanece `Launched`** |
| CNAB é obrigatório? | **Não.** Opcional por baixa: bancos sem adapter ou pagamentos feitos manualmente podem ser baixados sem arquivo. A UI sinaliza "sem remessa gerada" |
| Quando gerar CNAB | Só em baixa `draft`, com `CnabConfig` ativa para a conta e `settlement_date >= hoje` (o banco rejeita pagamento retroativo) |
| Confirmação (`settled`) | Exige `settlement_date <= hoje` (não se liquida pagamento futuro) e nenhum `CnabFile` em `queued`/`generating`. Todas as PRs ainda `Launched`, não trashed e com `net_amount == amount` → transição em transação única (tudo ou nada) |
| Consequência | Data futura D: cria a baixa → gera CNAB → faz upload → confirma em D ou depois. Data passada (pagamento já feito fora do sistema): cria → confirma, sem CNAB |
| Cancelamento | Só em `draft` e sem CNAB em andamento. Libera os itens (`released_at`), marca `CnabFile` `generated` como `superseded` (bloqueia download). PRs continuam `Launched` e voltam a ser elegíveis |
| Imutabilidade | Data, conta e filial **não são editáveis** após criar. Para mudar, cancelar e recriar. Remover **um item** é permitido em `draft` sem CNAB ativo/gerado (§9.3) |

Diagrama de estados da **PR** (inalterado; só o caminho para `Settled` fica restrito):

```
Requested --(gate aprovação F4)--> Launched --(ConfirmPaymentSettlement)--> Settled
                                      │
                                      └─ enquanto em baixa draft: sem edição para não-Adm
```

### 3.3 Status da baixa — `PaymentSettlementStatus`

| Valor | Significado |
|-------|-------------|
| `draft` | Baixa registrada (itens, data e conta); CNAB pode ser gerado; aguarda confirmação |
| `settled` | Confirmada; todas as PRs `Settled` |
| `cancelled` | Cancelada; itens liberados |

Transições: `draft → settled | cancelled`. Terminais: `settled`, `cancelled`.

### 3.4 Status do arquivo — `CnabFileStatus`

| Valor | Significado |
|-------|-------------|
| `queued` | Geração solicitada; job despachado |
| `generating` | Job em execução |
| `generated` | Arquivo gravado, validado e disponível para download |
| `failed` | Dry-run reprovou ou erro de infraestrutura após as tentativas; detalhes em `failure_reason` + itens com `validation_errors` |
| `superseded` | Substituído (regeneração Adm) ou invalidado (cancelamento da baixa); **sem download** |

Transições: `queued → generating | failed`; `generating → generated | failed`; `generated → superseded`. Terminais: `failed`, `superseded`.

**Idempotência (fechada):**

- No máximo **um arquivo "ativo" por baixa**: índice único parcial `(payment_settlement_id) WHERE status IN ('queued','generating','generated') AND deleted_at IS NULL`. Precedente de índice parcial por status: `approvals_payment_request_pending_unique`. Os literais ficam **hardcoded na migration** (migration não importa enum); um teste garante que batem com `CnabFileStatus::activeValues()`. Novo status "ativo" no futuro exige migration que recria o índice (DBA §21 #4).
- Nova geração só por ação explícita: **Retry** (último arquivo `failed` → novo `CnabFile`) ou **Regenerar** (Adm: `generated → superseded` + novo `CnabFile`, com motivo).
- Um NSA (número sequencial do arquivo) por arquivo, atribuído uma única vez: `last_file_sequence` na config com `lockForUpdate`, gravado em `cnab_files.file_sequence`, que não muda em retries do mesmo job. Unique parcial `(cnab_config_id, file_sequence) WHERE file_sequence IS NOT NULL` impede reuso **dentro da config**, inclusive contra arquivos soft-deleted (predicado **sem** `deleted_at`, intencional). Entre configs da mesma conta, o Service semeia o NSA (§3.1). Limite do layout: 6 dígitos; `last_file_sequence + 1 > 999999` → `CnabException::fileSequenceExhausted()`.
- O job é no-op se o `CnabFile` não estiver em `queued`/`generating`.

### 3.5 Dry-run (RNF008 / R08)

Dois pontos de uso, **mesmo pipeline**, em `CnabRemittanceService`:

| Uso | Onde | Persiste? | Consome NSA? |
|-----|------|-----------|--------------|
| **Simulação sob demanda** | Action "Validar remessa" na View da baixa (síncrona; ~70 itens é trivial) | **Não**; relatório em modal | Não (NSA provisório = `last_file_sequence + 1`) |
| **Gate obrigatório** | Dentro do `GenerateCnabFileJob`, antes de gravar no disco | Sim: `cnab_file_items` com `is_valid`/`validation_errors`; `failed` se reprovado | Só se aprovado |

**Pipeline** (as três camadas precisam passar):

1. **Validação de config** (`adapter->validateConfig()`): config ativa; layout suportado pelo banco da conta (`341` para `itau_240`); conta ativa com agência, conta e DV; CNPJ da filial com 14 dígitos válidos; nome da empresa não vazio.
2. **Validação por item** (`adapter->validateItem()` + regras comuns): PR ainda `Launched`, não trashed, `net_amount == amount`; `amount > 0`; `settlement_date >= hoje`; regras por tipo:
   - **Boleto:** `barcode` (44) ou `digitable_line` (47) conversível, com DVs válidos; arrecadação (inicia com `8`/48 dígitos) → `utility_bill_not_supported`.
   - **Transferência:** `bank_id` com código BACEN, agência, conta, DV, `account_type`, `holder_document` CPF/CNPJ válido, nome do favorecido (`holder_name` ou `supplier.name`).
   - **PIX:** `pix_key_type` + `pix_key` válidos; só QR → `pix_qr_code_not_supported`.
3. **Validação estrutural do arquivo** (`CnabRemittanceValidator`, agnóstica de banco para 240):
   - toda linha com **exatamente 240** caracteres; só ASCII imprimível (acentos transliterados antes); separador de linha conforme config (`\r\n`);
   - sequência de tipos de registro: `0` (header arquivo), um ou mais blocos `1` (header lote) → `3` (detalhes) → `5` (trailer lote), e `9` (trailer arquivo);
   - código do banco (posições 1–3) igual ao da config em todas as linhas;
   - numeração do lote contínua (1..n); sequencial de registro dentro do lote contínuo (1..m);
   - trailer de lote: quantidade de registros e somatório de valores batem com os detalhes;
   - trailer de arquivo: quantidade de lotes e de registros batem;
   - somatório total == soma de `amount` dos itens válidos (bcmath);
   - NSA no header == `file_sequence`.

**Política de itens inválidos (fechada): tudo ou nada.** Qualquer item inválido reprova o arquivo inteiro (`failed`), com o relatório por item. O operador corrige (remove o item da baixa ou cancela e recria) e faz **Retry**. Motivo: arquivo parcial silencioso numa remessa de pagamento é pior que reprovação explícita (R08).

### 3.6 Strategy/adapter por banco (R02)

- **Contrato:** `App\Integrations\Cnab\CnabRemittanceAdapter` (§9.5). Cada banco implementa formatação, validação específica, agrupamento em lotes, códigos de forma de pagamento e nome de arquivo.
- **Resolver:** `App\Integrations\Cnab\CnabAdapterResolver::for(CnabLayout): CnabRemittanceAdapter`, com mapa registrado no `AppServiceProvider::register()` (mesmo local dos binds de OCR/Spreadsheet).
- **MVP:** `App\Integrations\Cnab\Itau\Itau240RemittanceAdapter` (SISPAG CNAB 240).
- **Novo banco:** novo case em `CnabLayout`, novo adapter e registro no resolver. Parâmetros extras de banco entram como **colunas tipadas** em `cnab_configs` via migration (sem json genérico; ver trade-off §17).
- **Sem HTTP:** nenhum adapter faz I/O de rede; só gera string.

**Mapeamento de formas do MVP (Itaú SISPAG).** Os códigos são referência e devem ser confirmados na especificação vigente e em homologação (R09):

| `CnabPaymentType` | Origem na PR | Segmentos | Forma de pagamento (lote) |
|-------------------|--------------|-----------|---------------------------|
| `boleto` | `payment_method = boleto` | J (+ J-52 com dados de pagador/beneficiário) | `30` boleto Itaú (barcode inicia `341`) / `31` boleto de outros bancos |
| `transfer` | `deposit` + `deposit_type = transfer` | A + B | `01` crédito em conta Itaú (banco favorecido `341`) / `41` TED |
| `pix_key` | `deposit` + `deposit_type = pix` + chave | A + B (chave PIX no B) | `45` PIX transferência |

Um lote por combinação de forma de pagamento (exigência do layout). A ordem dos lotes e dos detalhes é determinística (forma → vencimento → id da PR) para permitir golden files nos testes.

### 3.7 Biblioteca vs gerador próprio (decisão arquitetural, sem nova dependência)

| Opção | Avaliação |
|-------|-----------|
| `eduardokum/laravel-boleto` | Foco em **cobrança** (emissão de boleto, remessa/retorno de cobrança). O escopo aqui é **pagamento a fornecedores (SISPAG)**. Não cobre bem A/B/J/J-52 de pagamento |
| Pacotes `cnab-php` genéricos | Manutenção irregular; layouts de pagamento Itaú incompletos ou desatualizados; lógica opaca dificulta homologação |
| **Gerador próprio (escolhido)** | Escopo pequeno e fechado (3 tipos de pagamento, 7 tipos de registro); formatação de largura fixa trivial; controle total para homologação (R09); testável com golden files; sem nova dependência (`AGENTS.md`: não mudar dependências sem aprovação) |

**O que o gerador próprio precisa garantir** (validado no dry-run, §3.5): registro de 240 posições; header/trailer de arquivo e de lote coerentes; sequenciais contínuos; NSA; numéricos com zeros à esquerda e alfanuméricos com espaços à direita, em maiúsculas ASCII; valores em centavos sem separador; datas `DDMMAAAA`; CNPJ/CPF só dígitos; agência, conta e DV nos campos do layout Itaú; convênio/campos reservados preenchidos conforme spec.

O documento **não** reproduz o layout byte a byte. A tabela de posições fica **dentro** do adapter Itaú (constantes por campo), com referência à versão do manual SISPAG usada. Isso é pendência de insumo (P-F7-SPEC, §19).

### 3.8 Armazenamento e download

| Tema | Decisão |
|------|---------|
| Disco | `config('rjet.cnab.disk')` ← `RJET_CNAB_DISK` → fallback `FILESYSTEM_DISK` (mesmo padrão de `rjet.imports.disk`) |
| Diretório | `cnab/{branch_uuid}/{YYYY}/{MM}/{cnab_file_uuid}.rem`. O path interno é baseado em UUID e **nunca** exibido |
| Visibility | `private` |
| Nome de download | `adapter->fileName()`. Itaú MVP: `{bank_code}_{branch_document}_{YYYYMMDD settlement_date}_{NSA:06}.rem` (ex. `341_12345678000190_20260928_000007.rem`); convenção a confirmar com a RJET (P-F7-FILENAME) |
| Download | `Storage::disk($disk)->download($path, $filename)` atrás de `CnabFilePolicy::download` (padrão `DownloadImportSpreadsheetAction`). **Sem** `temporaryUrl`, para não gerar link compartilhável de arquivo de pagamento |
| Integridade | `checksum` SHA-256 gravado na geração; conferido antes do download (divergente → `CnabException::fileIntegrityCheckFailed()` + log crítico) |
| Encoding | ASCII (transliteração via `Str::ascii` + uppercase); line ending em config (`rjet.cnab.line_ending`, default `\r\n`) |

### 3.9 Auditoria

| Entidade | Auditoria |
|----------|-----------|
| `CnabConfig` | `created_by`/`updated_by` (HasBlameable) + `updated_at`. **Sem** tabela de histórico nem Spatie (o projeto não usa). O histórico efetivo de "que parâmetros geraram cada arquivo" fica em `cnab_files.config_snapshot` (trade-off §17) |
| `PaymentSettlement` | Blameable + `settled_at`/`settled_by` + `cancelled_at`/`cancelled_by`/`cancellation_reason` |
| PR | `payment_request_status_history` (`Launched → Settled`, `notes` = referência da baixa) via `transitionStatus()` |
| `CnabFile` | Blameable (quem solicitou) + `generated_at` + `superseded_at`/`superseded_by`/`supersede_reason` + `downloaded_at`/`downloaded_by` (primeiro download) + log estruturado a **cada** download (evento `CnabFileDownloaded`) |

### 3.10 Assunções

| # | Assunção | Alternativa descartada / nota |
|---|----------|-------------------------------|
| A1 | Elegíveis = só `Launched`. O "filtro status" do RF030 fica fixo (coluna exibida, sem seletor) | Incluir `Requested` aprovadas violaria F4 (CNAB/baixa exige `Launched`) |
| A2 | Uma baixa = uma filial = uma conta pagadora = no máximo um arquivo ativo | Baixa multi-filial quebraria o header CNAB |
| A3 | CNAB opcional por baixa | Obrigatório impediria baixar pagamentos em bancos sem adapter |
| A4 | Itens inválidos reprovam o arquivo inteiro | Arquivo parcial: risco R08 |
| A5 | Cliente não vê baixas nem arquivos (igual `ImportBatchPolicy`) | O DRF fala em "filiais autorizadas". O model tem `branch_id`, então liberar leitura por filial ao Cliente depois é trivial (`scopeVisibleTo`) |
| A6 | Operador e Adm operam baixa/CNAB; config é só Adm; regenerar arquivo é só Adm | Alinhado a RN006.4 |
| A7 | ~~Fila `default` (como F5/F6)~~ **Substituída em 2026-09-27 (§3.11):** conexão CNAB dedicada, fila `cnab`, `retry_after` 240 | ~~`high` exigiria worker dedicado…~~ O `retry_after` 90 da conexão default deixava a baixa presa após crash do worker |
| A8 | Notificação ao solicitante da geração: `mail` + `database` (padrão F5/F6) | — |
| A9 | Retorno CNAB e tributos fora de escopo | — |
| A10 | `settlement_date` sem limite máximo no futuro no MVP | Só as regras de CNAB (≥ hoje) e de confirmação (≤ hoje) |
| A11 | `settlement_date` é `date` (data de calendário do pagamento no banco), não `_at` (DBA §21 #10) | Mesma semântica de `payment_requests.due_date` (`date`). O sufixo `_at` fica para instantes `timestampTz` (`settled_at`, `cancelled_at`). Comparação com "hoje" via `today('America/Sao_Paulo')->toDateString()` |

### 3.11 Revisão 2026-09-27 — Fila CNAB dedicada e recuperação (decisão aprovada)

**Problema.** `GenerateCnabFileJob` (`$timeout = 120`, `WithoutOverlapping(payment_settlement_id)->dontRelease()->expireAfter(180)`) rodava na conexão default (`retry_after = 90`). Se o worker morre com o arquivo em `generating`, a fila reentrega aos 90 s, o lock ainda existe e `dontRelease()` descarta o job **sem** chamar `failed()`. O arquivo fica em `generating` para sempre: confirmar/cancelar/remover item recusam (`cnabGenerationInProgress`) e o Retry da UI só existe para `failed`.

**Restrições.** `retry_after` é da **conexão**, não do nome da fila. Subir o global atrasaria a recuperação de import, anexo e e-mail. Baixar o timeout do CNAB limitaria o tamanho da remessa.

**Decisão.**

| Item | Valor |
|------|-------|
| Conexão | Própria, via `config('rjet.cnab.queue.connection')`: `cnab_database` (driver `database`, default atual) ou `cnab_redis` (driver `redis`, produção). Mesmo backend da conexão default (`jobs` / `REDIS_QUEUE_CONNECTION`) |
| Fila | `cnab` (`config('rjet.cnab.queue.name')`) |
| `retry_after` da conexão CNAB | **240 s** (`RJET_CNAB_QUEUE_RETRY_AFTER`) |
| `$timeout` do job | **120 s** (inalterado) |
| Lock | `WithoutOverlapping(payment_settlement_id)->releaseAfter(30)->expireAfter(180)` |
| Worker `cnab` | `--timeout=150` (> 120 do job, < 240 do `retry_after`) |
| `$tries` / `$maxExceptions` / `$backoff` | **10** / **3** / `[10, 30, 60]` |
| Demais jobs | Conexão default, `retry_after = 90`, sem mudança |

**Invariantes de tempo.** `timeout 120 < lock 180 < retry_after 240`, com `120 < worker 150 < 240`. Worker morto em t0: o lock expira em t0+180 e a fila reentrega em t0+240. A reentrega pega o lock e `generate()` retoma no mesmo `CnabFile`, reaproveitando o `file_sequence` se já atribuído (§9.4 passo 4).

**`releaseAfter(30)` em vez de `dontRelease()`.** A duplicata não é mais descartada: volta à fila e roda depois. Nunca há duas execuções simultâneas da mesma baixa. Quando ela obtém o lock, `generate()` é no-op se o arquivo já saiu de `queued|generating`. 30 s: a geração típica leva segundos (≤ 500 itens em memória); o pior caso é aguardar o lock inteiro (180 s) = 6 releases. Como cada release conta como tentativa, `$tries = 10` cobre 6 releases + 3 exceções + 1 sucesso, e `$maxExceptions = 3` mantém "3 falhas reais" como limite. Atraso menor queimaria tentativas; maior atrasaria a UI (poll 5 s) sem ganho.

**Selecionar a conexão.** O construtor do job chama `onConnection(config('rjet.cnab.queue.connection'))->onQueue(config('rjet.cnab.queue.name'))`, e todo dispatch (listener `QueueCnabFileGeneration`, reconciliador) herda isso sem repetir nomes. `phpunit.xml` define `RJET_CNAB_QUEUE_CONNECTION=sync`.

**Workers obrigatórios.**
- Produção (`docker/supervisor/supervisord-prod.conf`), novo programa `queue-worker-cnab`: `php /var/www/html/artisan queue:work cnab_redis --queue=cnab --sleep=3 --timeout=150 --max-time=3600`, `numprocs=1`, `stopwaitsecs=160` (deploy não mata geração em curso). Com driver database: `cnab_database`. Requer `pcntl`.
- Proibido consumir a fila `cnab` pela conexão default em produção (aplicaria `retry_after = 90` e reabriria o bug).
- Dev (`composer dev`): `queue:listen --queue=default,cnab` é aceitável **só em dev** (mesmo backend; crash de worker não é cenário de dev).
- Agendador: `schedule:work` (supervisor) ou cron com `schedule:run` precisa existir em produção. O supervisor atual não tem nenhum dos dois (o `approvals:escalate-sla` tem a mesma dependência).

**Recuperação por reconciliador (rede de segurança, não o caminho principal).**
- Comando `cnab:recover-stuck-files {--dry-run}` → `App\Actions\Cnab\RecoverStuckCnabFilesAction` → `CnabFileService::recoverStuck(): array{requeued: int, failed: int}` (padrão `approvals:escalate-sla`). Agenda: `everyFiveMinutes()->withoutOverlapping()` em `routes/console.php`.
- **Parado:** `status IN (queued, generating)` (via `CnabFileStatus::inProgressValues()`, que já existe; **não** `activeValues()`), não soft-deleted, `updated_at <= now() - (retry_after + margem)` = **300 s** (`retry_after` lido de `queue.connections.{rjet.cnab.queue.connection}.retry_after`; margem `rjet.cnab.recovery.margin_seconds = 60`).
- **Iteração:** `chunkById(100)` na ordem de id, **sem** `orderBy('updated_at')`. `chunkById` pagina por id; combinar com outra ordenação pula registros. A ordem não muda o resultado porque cada arquivo é revalidado depois do lock.
- Por arquivo, em transação: `lockForUpdate` na linha, **recarrega pelo model** (`CnabFile::query()`, escopo de soft delete ativo) e confere de novo `$file->status->isInProgress()` e `updated_at` ainda stale. Se o arquivo sumiu, saiu de andamento ou foi tocado pelo job entretanto, pula. Só então:
  - **Teto:** se também `created_at <= now() - rjet.cnab.recovery.max_age_minutes` (**20 min**) → `markFailed($file, __('cnab_files.messages.generation_interrupted'))` (dispara `CnabFileGenerationFailed` → notificação existente; Retry aparece na UI). Log `warning`.
  - **Senão:** `CnabFile::whereKey()->update(['updated_at' => now()])` (update direto: sem observers/blameable, preserva `updated_by`) e, **após o commit**, `GenerateCnabFileJob::dispatch($file)` **no mesmo `CnabFile`**. Log `info`. O `touch` limita a no máximo um reenfileiramento por arquivo a cada 5 min.
- `--dry-run`: conta o que seria reenfileirado/marcado como `failed`, sem alterar nada.
- **Não faz:** não cria outro `CnabFile`, não atribui/altera NSA, não toca `generated`/`failed`/`superseded` nem soft-deleted, não altera a baixa, não chama `retry()`, não remove jobs da fila, não força liberação de lock.
- **Sem coluna de tentativas** (recusada pela `dba` em 2026-09-27): o teto por idade (`created_at`, 20 min) mais o throttle por `updated_at` limitam o número de resgates (~3 antes do teto). Um contador não mudaria o comportamento observável.

**Ajustes obrigatórios em `CnabFileService::generate()` (a recuperação depende deles).**
1. Passo 2 (`startGenerating`): **sempre** atualizar `updated_at` ao entrar, inclusive quando o arquivo já está em `generating` com `started_at` preenchido. Hoje o `forceFill` sem mudança não salva, e uma execução resgatada pareceria parada.
2. Passo 7: a transação final recarrega o `CnabFile` com `lockForUpdate` e só grava `generated` se o status ainda for `generating`. Caso contrário, apaga o arquivo recém-gravado (compensação do passo 9) e retorna sem `CnabFileGenerated`. Isso evita sobrescrever um `failed` gravado pelo reconciliador.

**Indexação.** Sem índice novo e sem migration alterada. A `dba` (2026-09-27) recusou o índice parcial proposto para o reconciliador: o índice `status` existente em `cnab_files` basta neste volume (~70 pagamentos/dia). Reavaliar só acima de ~100 mil linhas em `cnab_files` ou se o `EXPLAIN` do reconciliador mostrar seq scan relevante.

**Substitui:** A7 (§3.10), "Fila: `default` (A7)" e `dontRelease()` (§9.4), linha "Job duplicado" (§13), e o item 21 de §0.7 do blueprint.

---

## 4. Models

> **Convenções DBA F1–F6:** PK UUID; FKs com `->index()` explícito (Postgres); `timestampsTz`/`softDeletesTz`; blameable `nullOnDelete`; status = `string` (nunca `$table->enum()`); uniques parciais via `DB::statement` + `supportsPartialIndexes()` (pgsql+sqlite) + `DROP INDEX IF EXISTS` no `down()`, nunca `->unique()` no Blueprint quando há SoftDeletes ou condição; sem `after()`; sem CHECK SQL multi-coluna (validação no Service).

### 4.1 `PaymentSettlement` (a criar)

| Campo | Tipo SQL | Notas |
|-------|----------|-------|
| `id` | uuid PK | |
| `branch_id` | foreignUuid index → `branches` **restrictOnDelete** | Filial única da baixa. Desnormalizado (comentário obrigatório; §3.1 item 6) |
| `branch_bank_account_id` | foreignUuid index → `branch_bank_accounts` **restrictOnDelete** | Conta pagadora ("banco que foi pago"); deve pertencer à `branch_id` (Service) |
| `status` | string(20) index default `draft` | `PaymentSettlementStatus` |
| `settlement_date` | date | Data de baixa / pagamento no banco (RF031). `date` como `due_date` (A11). Sem índice simples: coberto pelo composto `(branch_id, settlement_date)` |
| `items_count` | unsignedInteger default 0 | Desnormalizado (comentário) |
| `total_amount` | decimal(10,2) default 0 | Desnormalizado. Overflow → `PaymentSettlementException::totalAmountOverflow()` (RNF002) |
| `notes` | text nullable | |
| `settled_at` | timestampTz nullable | Confirmação |
| `settled_by` | foreignUuid nullable index → users nullOnDelete | |
| `cancelled_at` | timestampTz nullable | |
| `cancelled_by` | foreignUuid nullable index → users nullOnDelete | |
| `cancellation_reason` | string(500) nullable | |
| `created_by` / `updated_by` | foreignUuid nullable index → users nullOnDelete | HasBlameable |
| timestampsTz / softDeletesTz | | |

- **Índices extras:** `(branch_id, settlement_date)` para listagem por filial e período. `status` já indexado. **Sem** índice simples em `settlement_date` (tabela pequena: dezenas de baixas/mês; F8 avalia `(status, settlement_date)` se o dashboard pedir).
- **Status:** `string(20)` cobre o maior valor (`cancelled` = 9).
- **Traits:** `HasFactory`, `HasUuid`, `SoftDeletes`, `HasBlameable`.
- **Casts:** `status` → `PaymentSettlementStatus`; `settlement_date` → `date`; `total_amount` → `decimal:2`; `items_count` → integer; `settled_at`/`cancelled_at` → datetime.
- **Relações:** `branch()`, `branchBankAccount()` (withTrashed na leitura), `items()` (hasMany), `activeItems()` (hasMany `whereNull('released_at')`), `cnabFiles()` (hasMany), `currentCnabFile()` (hasOne `ofMany` o mais recente com status em `queued|generating|generated`), `creator()`, `settler()`, `canceller()`.
- **Scopes:** `scopeVisibleTo(User)` (Operador/Adm tudo; Cliente `whereRaw('1 = 0')`, igual `ImportBatch`), `scopeStatus(PaymentSettlementStatus)`, `scopeForBranch(Branch|string)`.
- **Helpers:** `isDraft()`, `isTerminal()`, `hasActiveCnabGeneration(): bool` (`queued|generating`), `hasGeneratedCnabFile(): bool`, `cnabConfig(): ?CnabConfig` (via conta).

### 4.2 `PaymentSettlementItem` (a criar)

| Campo | Tipo SQL | Notas |
|-------|----------|-------|
| `id` | uuid PK | |
| `payment_settlement_id` | foreignUuid index → `payment_settlements` **cascadeOnDelete** | Cascade só no forceDelete da baixa |
| `payment_request_id` | foreignUuid index → `payment_requests` **restrictOnDelete** | Impede hard delete de PR que já passou por baixa |
| `amount` | decimal(10,2) | Snapshot de `net_amount` na seleção (comentário) |
| `released_at` | timestampTz nullable | Preenchido no cancelamento da baixa ou na remoção do item |
| `released_by` | foreignUuid nullable index → users nullOnDelete | |
| timestampsTz | | **Sem** SoftDeletes: ciclo de vida via `released_at`; filha "hard-owned" da baixa |

- **Unique parcial (crítico):** `payment_settlement_items_active_request_unique ON (payment_request_id) WHERE released_at IS NULL`. Uma PR tem no máximo **um** item ativo (em baixa `draft` ou `settled`). Garante exclusão mútua sob concorrência (dois operadores selecionando as mesmas PRs). O predicado não olha `payment_settlements.deleted_at`: isso é seguro porque só baixa `cancelled` (itens já liberados) pode ser soft-deleted (`cannotDeleteActive`).
- **FK index de `payment_request_id` mantido** além do unique parcial: o parcial só cobre itens ativos; histórico (`settlementItems()`, itens liberados) usa o índice simples.
- **Por que `released_at` e não SoftDeletes:** cancelar não é excluir. O item cancelado continua auditável e visível na baixa cancelada; o índice parcial fica semântico.
- **Traits:** `HasFactory`, `HasUuid`. Sem blameable: a autoria é da baixa; liberação tem `released_by`.
- **Casts:** `amount` → `decimal:2`; `released_at` → datetime.
- **Relações:** `settlement()`, `paymentRequest()` (withTrashed na leitura), `cnabFileItems()`.
- **Scopes:** `scopeActive()` (`released_at IS NULL`).

### 4.3 `CnabConfig` (a criar)

| Campo | Tipo SQL | Notas |
|-------|----------|-------|
| `id` | uuid PK | |
| `branch_bank_account_id` | foreignUuid index → `branch_bank_accounts` **restrictOnDelete** | Chave de negócio (§3.1) |
| `layout` | string(30) | `CnabLayout` (`itau_240` = 8; folga para layouts futuros). **Sem índice** (tabela com uma linha por conta; nunca filtrada sozinha) |
| `company_name` | string(30) nullable | Nome da empresa no header. **30 = limite do campo no layout CNAB 240**, não limite de nome de filial (`branches.legal_name` é 200). Fallback = `branch.legal_name` truncado/transliterado pelo adapter |
| `agreement_code` | string(20) nullable | Convênio (Itaú SISPAG não exige; outros bancos sim) |
| `wallet_code` | string(10) nullable | Carteira (reservado a adapters futuros; Itaú pagamento ignora) |
| `payment_type_code` | string(2) default `'20'` | Tipo de pagamento do lote (`20` fornecedores; confirmar na spec) |
| `last_file_sequence` | unsignedInteger default 0 | Último NSA usado. Em Postgres vira `integer` (unsigned não é imposto): Service/Form garantem `0..999999` (NSA 6 dígitos). Editável pelo Adm **só** enquanto não houver `CnabFile` com `file_sequence` nesta config, e nunca abaixo do maior NSA já emitido para a conta (§3.1) |
| `is_active` | boolean default true index | `database.md` §5. Índice mantido por consistência com `banks`/`branch_bank_accounts` (custo desprezível) |
| `created_by` / `updated_by` | foreignUuid nullable index → users nullOnDelete | RF032 auditável |
| timestampsTz / softDeletesTz | | |

- **Unique parcial:** `cnab_configs_account_unique ON (branch_bank_account_id) WHERE deleted_at IS NULL`. Inativa ainda ocupa a vaga (§3.1).
- **Traits:** `HasFactory`, `HasUuid`, `SoftDeletes`, `HasBlameable`.
- **Casts:** `layout` → `CnabLayout`; `is_active` → boolean; `last_file_sequence` → integer.
- **Relações:** `branchBankAccount()`, `cnabFiles()`, `creator()`, `updater()`. Acessores de leitura `branch()` e `bank()` via conta (sem colunas).
- **Scopes:** `scopeActive()`, `scopeForAccount(BranchBankAccount|string)`.
- **Helpers:** `hasIssuedFiles(): bool` (bloqueia edição de `last_file_sequence` e troca de conta/layout).
- **Regra de edição:** após o primeiro arquivo emitido, `branch_bank_account_id` e `layout` ficam imutáveis (`CnabException::configLockedAfterIssue()`). Mudar exige **excluir (soft delete)** a config e criar nova; desativar só pausa a geração e não libera o unique (DBA §21 #2). A nova config herda o NSA da conta (§3.1).
- **Leitura histórica:** `CnabFile::config()` usa `withTrashed()` (arquivo antigo aponta para config substituída).

### 4.4 `CnabFile` (a criar)

| Campo | Tipo SQL | Notas |
|-------|----------|-------|
| `id` | uuid PK | |
| `payment_settlement_id` | foreignUuid index → `payment_settlements` **restrictOnDelete** | |
| `cnab_config_id` | foreignUuid index → `cnab_configs` **restrictOnDelete** | |
| `layout` | string(30) | Snapshot do layout usado (desnormalizado; §3.1 item 7) |
| `status` | string(20) index default `queued` | `CnabFileStatus` (maior valor `generating`/`superseded` = 10) |
| `file_sequence` | unsignedInteger nullable | NSA (`integer` no Postgres; máx. 999999 validado no Service); atribuído uma vez na geração aprovada |
| `disk` | string(50) nullable | |
| `path` | string(500) nullable | Interno; nunca exposto |
| `filename` | string(100) nullable | Nome de download |
| `size` | unsignedInteger nullable | bytes |
| `checksum` | string(64) nullable | SHA-256 |
| `records_count` | unsignedInteger default 0 | Linhas do arquivo |
| `items_count` | unsignedInteger default 0 | Pagamentos no arquivo |
| `total_amount` | decimal(10,2) default 0 | Snapshot |
| `config_snapshot` | json nullable | Config + conta + filial no momento da geração (precedente: `import_batches.mappings_snapshot`) |
| `failure_reason` | text nullable | Mensagem segura (UI); detalhe técnico só no log |
| `started_at` / `generated_at` | timestampTz nullable | |
| `superseded_at` | timestampTz nullable | |
| `superseded_by` | foreignUuid nullable index → users nullOnDelete | |
| `supersede_reason` | string(500) nullable | |
| `downloaded_at` | timestampTz nullable | Primeiro download |
| `downloaded_by` | foreignUuid nullable index → users nullOnDelete | |
| `created_by` / `updated_by` | foreignUuid nullable index → users nullOnDelete | Quem solicitou |
| timestampsTz / softDeletesTz | | |

- **Unique parcial (idempotência):** `cnab_files_settlement_active_unique ON (payment_settlement_id) WHERE status IN ('queued','generating','generated') AND deleted_at IS NULL`. Literais hardcoded (sem importar enum na migration); teste de sincronia com `CnabFileStatus::activeValues()`.
- **Unique parcial (NSA):** `cnab_files_config_sequence_unique ON (cnab_config_id, file_sequence) WHERE file_sequence IS NOT NULL`. **Sem** `deleted_at` no predicado, de propósito: NSA de arquivo soft-deleted nunca é reutilizado. Via `DB::statement` (não `->unique()` Blueprint) para deixar a intenção explícita e igual nos dois drivers.
- **Sem índice** em `disk`, `path`, `filename`, `checksum`, `config_snapshot`, `generated_at`: nunca filtrados; listagem é por baixa (FK).
- **Tamanhos** alinhados ao repo: `disk` 50 / `path` 500 / `failure_reason` text (= `import_batches`); `checksum` 64 (= `approvals.material_fingerprint`, SHA-256 hex); `filename` 100 (nome gerado, ~40 chars); `supersede_reason` 500 (= `attachment_batch_items.rename_error`, `import_batch_errors.message`).
- **Traits:** `HasFactory`, `HasUuid`, `SoftDeletes`, `HasBlameable`.
- **Casts:** `status` → `CnabFileStatus`; `layout` → `CnabLayout`; `config_snapshot` → array; `total_amount` → `decimal:2`; inteiros; datas → datetime.
- **Relações:** `settlement()` (withTrashed na leitura), `config()` (belongsTo `CnabConfig`, withTrashed), `items()` (hasMany `CnabFileItem`), `creator()`, `downloader()`, `superseder()`.
- **Helpers:** `isDownloadable(): bool` (`generated` + path presente), `isActive(): bool`, `isRetryable(): bool` (`failed` + baixa `draft`).

### 4.5 `CnabFileItem` (a criar, append-only)

| Campo | Tipo SQL | Notas |
|-------|----------|-------|
| `id` | uuid PK | |
| `cnab_file_id` | foreignUuid index → `cnab_files` **cascadeOnDelete** | OK: filha sem SoftDeletes |
| `payment_settlement_item_id` | foreignUuid index → `payment_settlement_items` **restrictOnDelete** | |
| `payment_type` | string(20) | `CnabPaymentType` |
| `payment_form_code` | string(2) nullable | Forma no lote (ex. `30`, `41`, `45`); null se inválido |
| `batch_number` | unsignedSmallInteger nullable | Lote no arquivo |
| `record_sequence` | unsignedInteger nullable | Sequencial do primeiro segmento do item no lote |
| `reference` | string(20) | "Seu número": **últimos** 20 hex (maiúsculas) do UUID da PR sem hífens. O repo usa `Str::orderedUuid()`, cujos 12 primeiros hex são timestamp: os primeiros 20 teriam ~28 bits aleatórios; os últimos 20 têm ~74 (DBA §21 #5). Único no arquivo por **constraint** (abaixo) |
| `amount` | decimal(10,2) | Snapshot escrito |
| `is_valid` | boolean default true | Sem índice (baixa cardinalidade, tabela pequena) |
| `validation_errors` | json nullable | Lista de `{code, field, params}` (chaves de tradução `cnab_files.validation.*`) |
| `created_at` | timestampTz useCurrent | Sem `updated_at`, sem SoftDeletes (padrão `ImportBatchError`) |

- **Uniques (DBA §21 #5):** `unique(['cnab_file_id', 'payment_settlement_item_id'])` (um registro por item por arquivo) e `unique(['cnab_file_id', 'reference'])` ("seu número" único na remessa, base do retorno futuro). Blueprint `->unique()` é permitido aqui: tabela sem SoftDeletes e sem predicado. O unique composto começando por `cnab_file_id` torna o `->index()` da FK redundante, mas ele é **mantido** pela convenção F1–F6 (toda FK com índice explícito).
- **Model:** `public $timestamps = false;` + `public const UPDATED_AT = null;` (padrão `ImportBatchError`; `created_at` vem do `useCurrent()`).
- **Casts:** `payment_type` → `CnabPaymentType`; `amount` → `decimal:2`; `is_valid` → boolean; `validation_errors` → array; `created_at` → datetime.
- **Sem blameable:** escrito pelo job (sem usuário autenticado); autoria está em `cnab_files.created_by`.
- **Relações:** `file()`, `settlementItem()`.

### 4.6 Hooks no `PaymentRequest` (existente)

- `settlementItems(): HasMany<PaymentSettlementItem>`.
- `activeSettlementItem(): HasOne<PaymentSettlementItem>` (`whereNull('released_at')`).
- `scopeEligibleForSettlement(Builder)`: `status = launched` + `whereDoesntHave('settlementItems', released_at IS NULL)`. O soft delete já é excluído pelo scope global.
- `isInDraftSettlement(): bool` (item ativo cuja baixa está `draft`).
- `isEditableBy()`: acrescentar, antes das regras de Operador, `if (! $user->isAdm() && $this->isInDraftSettlement()) return false;`.
- **Sem colunas novas** em `payment_requests`.

### 4.7 Índices (resumo, para a revisão DBA)

| Tabela | Índice | Não indexar |
|--------|--------|-------------|
| `payment_settlements` | `status`; `(branch_id, settlement_date)`; FKs (`branch_id`, `branch_bank_account_id`, `*_by`) | `settlement_date` sozinho (coberto pelo composto; tabela pequena), `total_amount`, `items_count`, `notes` |
| `payment_settlement_items` | unique parcial `payment_request_id WHERE released_at IS NULL`; FKs (inclui `payment_request_id` simples, para histórico) | `amount`, `released_at` sozinho |
| `cnab_configs` | unique parcial `branch_bank_account_id WHERE deleted_at IS NULL`; `is_active`; FKs | `layout`, `agreement_code`, `wallet_code` |
| `cnab_files` | `status`; unique parcial ativo por baixa; unique parcial `(cnab_config_id, file_sequence)`; FKs. A `dba` (2026-09-27) recusou o índice parcial em `updated_at` para o reconciliador (§3.11); o índice `status` basta | `checksum`, `filename`, `config_snapshot`, `generated_at` |
| `cnab_file_items` | unique `(cnab_file_id, payment_settlement_item_id)`; unique `(cnab_file_id, reference)`; FKs | `reference` sozinho (retorno futuro decide), `is_valid` |
| `payment_requests` | **Sem índice novo no MVP (confirmado pela `dba`, §21).** ~70/dia ≈ 25 mil linhas/ano: o planner combina `status`/`branch_id`/`due_date` (bitmap AND) e o anti-join de elegibilidade usa o unique parcial de itens. Reavaliar `(branch_id, status, due_date)` acima de ~200 mil linhas ou se o `EXPLAIN ANALYZE` da tela de elegíveis passar de ~50 ms | — |

### 4.8 Morph map

**Sem alteração.** A F7 não usa relações polimórficas.

---

## 5. Relacionamentos

```
Branch ──hasMany──> PaymentSettlement
BranchBankAccount ──hasMany──> PaymentSettlement            (conta pagadora)
BranchBankAccount ──hasOne(ativo)──> CnabConfig             (1 viva por conta — unique parcial)
Bank <──belongsTo── BranchBankAccount                         (banco derivado)

PaymentSettlement ──hasMany──> PaymentSettlementItem
PaymentSettlement ──hasMany──> CnabFile
PaymentSettlement ──hasOne(ofMany ativo)──> CnabFile          (currentCnabFile)
PaymentSettlementItem ──belongsTo──> PaymentRequest
PaymentRequest ──hasMany──> PaymentSettlementItem
PaymentRequest ──hasOne(released_at null)──> PaymentSettlementItem  (activeSettlementItem)

CnabConfig ──hasMany──> CnabFile
CnabFile ──hasMany──> CnabFileItem
CnabFileItem ──belongsTo──> PaymentSettlementItem

PaymentRequest ──hasOne──> PaymentRequestBankDetails ──belongsTo──> Bank   (fonte dos dados do favorecido — F3)
PaymentRequest ──hasMany──> PaymentRequestStatusHistory                     (Launched→Settled — F3)
```

**Matriz de FKs (fechada pela `dba`, §21):**

| Filha.coluna | Pai | `onDelete` | Motivo |
|--------------|-----|-----------|--------|
| `payment_settlements.branch_id` | `branches` | restrict | Registro financeiro bloqueia hard delete da filial |
| `payment_settlements.branch_bank_account_id` | `branch_bank_accounts` | restrict | Idem para a conta pagadora |
| `payment_settlement_items.payment_settlement_id` | `payment_settlements` | cascade | Filha hard-owned; só dispara no forceDelete |
| `payment_settlement_items.payment_request_id` | `payment_requests` | restrict | PR que passou por baixa não é apagada fisicamente |
| `cnab_configs.branch_bank_account_id` | `branch_bank_accounts` | restrict | Config bloqueia hard delete da conta |
| `cnab_files.payment_settlement_id` | `payment_settlements` | restrict | Obriga a apagar arquivos (e o físico, via observer) antes da baixa |
| `cnab_files.cnab_config_id` | `cnab_configs` | restrict | Config com arquivo emitido não é apagada fisicamente |
| `cnab_file_items.cnab_file_id` | `cnab_files` | cascade | Append-only, hard-owned |
| `cnab_file_items.payment_settlement_item_id` | `payment_settlement_items` | restrict | Item de baixa já escrito em arquivo não some sozinho |
| `*_by` (`created_by`, `updated_by`, `settled_by`, `cancelled_by`, `released_by`, `superseded_by`, `downloaded_by`) | `users` | nullOnDelete | Blameable (padrão F1–F6) |

**Sem ciclo de restrict.** O único caminho com restrict cruzado é `payment_settlements ← cnab_files ← cnab_file_items → payment_settlement_items → payment_settlements`. Ele se resolve com a ordem fixa de §10.3 (arquivos primeiro, com seus itens por cascade; depois a baixa, com seus itens por cascade). Tentar a baixa primeiro falha no restrict de `cnab_files` (erro, sem corrupção). Consequência aceita: `branches` e `payment_requests` têm cascade para `branch_bank_accounts`/PRs, mas o hard delete da filial passa a ser bloqueado pelos restricts acima assim que houver baixa, config ou item.

Ciclo de vida:

```
[Seleção] PRs Launched elegíveis ──CreatePaymentSettlement──> Settlement(draft) + Items(ativos, amount snapshot)
[Opcional] ValidateCnabRemittance (dry-run em memória)
[Opcional] RequestCnabFile ──> CnabFile(queued) ──Job──> generating ──dry-run ok──> generated (+ items, NSA, arquivo)
                                                             └─dry-run falha / infra──> failed (+ items com erros) ──Retry──> novo CnabFile
[Download] generated ──(Policy)──> stream .rem ──> CnabFileDownloaded
[Confirmar] Settlement(draft) ──> settled; cada PR Launched ──> Settled (history + event)
[Cancelar] Settlement(draft) ──> cancelled; Items.released_at; CnabFile generated ──> superseded
```

---

## 6. Enums (a criar)

Todos em `App\Enums`, `string`-backed, implementando `HasLabel`, `HasColor`, `HasIcon` (padrão `PaymentRequestStatus`), labels em `lang/*/enums.php`.

### 6.1 `PaymentSettlementStatus`

| Case | Valor | Cor | Ícone |
|------|-------|-----|-------|
| `Draft` | `draft` | `warning` | `heroicon-o-pencil-square` |
| `Settled` | `settled` | `success` | `heroicon-o-check-badge` |
| `Cancelled` | `cancelled` | `gray` | `heroicon-o-x-circle` |

Métodos: `canTransitionTo(self)`, `allowedTransitions()`, `isTerminal()`.

### 6.2 `CnabFileStatus`

| Case | Valor | Cor | Ícone |
|------|-------|-----|-------|
| `Queued` | `queued` | `gray` | `heroicon-o-clock` |
| `Generating` | `generating` | `info` | `heroicon-o-arrow-path` |
| `Generated` | `generated` | `success` | `heroicon-o-document-check` |
| `Failed` | `failed` | `danger` | `heroicon-o-exclamation-triangle` |
| `Superseded` | `superseded` | `gray` | `heroicon-o-archive-box-x-mark` |

Métodos: `canTransitionTo()`, `isTerminal()`, `isActive()` (`queued|generating|generated`), `static activeValues(): list<string>` (usado nas queries e no `currentCnabFile`). A migration **não** chama o enum: repete os literais no predicado do índice parcial, e um teste garante a igualdade (DBA §21 #4).

**Tamanho das colunas (medido pela `dba`):**

| Coluna | Maior valor | Tamanho | Folga |
|--------|-------------|---------|-------|
| `payment_settlements.status` | `cancelled` (9) | string(20) | ✅ |
| `cnab_files.status` | `generating` / `superseded` (10) | string(20) | ✅ |
| `cnab_configs.layout` / `cnab_files.layout` | `itau_240` (8) | string(30) | ✅ (layouts futuros tipo `banco_do_brasil_240` = 19) |
| `cnab_file_items.payment_type` | `pix_key` / `transfer` (8) | string(20) | ✅ |

### 6.3 `CnabLayout`

| Case | Valor | Label | Extra |
|------|-------|-------|-------|
| `Itau240` | `itau_240` | "Itaú — CNAB 240 (SISPAG)" | `bankCode(): string` → `'341'` |

`HasLabel` obrigatório. `HasColor`/`HasIcon` opcionais (tipo sem estado; `enums.md` §6).

### 6.4 `CnabPaymentType`

| Case | Valor | Derivação |
|------|-------|-----------|
| `Boleto` | `boleto` | `payment_method = boleto` |
| `Transfer` | `transfer` | `deposit` + `deposit_type = transfer` |
| `PixKey` | `pix_key` | `deposit` + `deposit_type = pix` + chave presente |

`static fromPaymentRequest(PaymentRequest): ?self` (null = inelegível para CNAB, ex. PIX só QR).

---

## 7. Exceções de domínio

Ambas estendem `App\Exceptions\BusinessException`. `message` técnico em inglês (log); `userMessage` via `__()` em pt-BR/en, **sem** paths internos nem dados bancários completos.

### 7.1 `App\Exceptions\PaymentSettlementException`

| Factory | Quando | `userMessage` |
|---------|--------|---------------|
| `emptySelection()` | Nenhuma PR selecionada | `payment_settlements.errors.empty_selection` |
| `mixedBranches()` | PRs de filiais diferentes | `…mixed_branches` |
| `paymentRequestNotEligible(int $count)` | PR não `Launched`/trashed/fora do escopo | `…not_eligible` (`:count`) |
| `paymentRequestAlreadyInSettlement()` | Violação do unique parcial ou item ativo | `…already_in_settlement` |
| `bankAccountNotAllowed()` | Conta não pertence à filial | `…bank_account_not_allowed` |
| `bankAccountInactive()` | Conta inativa/soft-deleted ou sem `bank_id` | `…bank_account_inactive` |
| `tooManyItems(int $max)` | Excede `rjet.cnab.max_items` | `…too_many_items` |
| `totalAmountOverflow()` | Soma não cabe em `decimal(10,2)` | `…total_amount_overflow` |
| `notDraft(string $status)` | Operação exige `draft` | `…not_draft` |
| `settlementDateInFuture()` | Confirmar com data > hoje | `…settlement_date_in_future` |
| `cnabGenerationInProgress()` | Confirmar, cancelar ou remover item com arquivo `queued`/`generating` | `…cnab_in_progress` |
| `cnabFileAlreadyGenerated()` | Remover item com arquivo `generated` ativo | `…cnab_already_generated` |
| `itemsChangedSinceSelection(int $count)` | Na confirmação: PR não `Launched`, trashed ou `net_amount ≠ amount` | `…items_changed` |
| `cannotDeleteActive()` | Delete de baixa não cancelada | `…cannot_delete_active` |
| `unauthorized()` | Belt no Service se a Policy falhar | `…unauthorized` |

### 7.2 `App\Exceptions\CnabException`

| Factory | Quando | `userMessage` |
|---------|--------|---------------|
| `configNotFound()` | Conta sem `CnabConfig` ativa | `cnab_files.errors.config_not_found` |
| `configInactive()` | Config desativada | `…config_inactive` |
| `configAlreadyExists()` | Segunda config viva para a conta | `cnab_configs.errors.already_exists` |
| `configLockedAfterIssue()` | Mudar conta/layout/sequência após emitir arquivo | `cnab_configs.errors.locked_after_issue` |
| `layoutNotSupported(string $layout)` | Layout sem adapter no resolver | `…layout_not_supported` |
| `bankMismatch(string $expected, string $actual)` | Banco da conta ≠ banco do layout | `…bank_mismatch` |
| `paymentDateInPast()` | Gerar CNAB com `settlement_date < hoje` | `…payment_date_in_past` |
| `settlementNotDraft()` | Gerar CNAB em baixa não `draft` | `…settlement_not_draft` |
| `generationAlreadyActive()` | Já existe arquivo ativo (unique parcial) | `…generation_already_active` |
| `remittanceInvalid(int $errorCount)` | Dry-run reprovado (gate do job ou geração síncrona) | `…remittance_invalid` (`:count`) |
| `fileNotRetryable()` | Retry em arquivo não `failed` ou baixa não `draft` | `…not_retryable` |
| `fileNotDownloadable()` | Download de arquivo não `generated` | `…not_downloadable` |
| `fileMissing()` | Path ausente no disco | `…file_missing` |
| `fileIntegrityCheckFailed()` | Checksum divergente | `…integrity_failed` |
| `storageWriteFailed()` | `Storage::put` falhou | `…storage_failed` |
| `fileSequenceExhausted()` | Próximo NSA > 999999 (6 dígitos do layout) | `…file_sequence_exhausted` |
| `fileSequenceBelowIssued(int $max)` | Adm tenta gravar `last_file_sequence` abaixo do maior NSA já emitido para a conta | `cnab_configs.errors.sequence_below_issued` |

**Erros por item de validação não são exceções.** São `CnabValidationError` (DTO §9.1) com códigos estáveis e tradução em `cnab_files.validation.*`: `payment_request_unavailable`, `amount_changed`, `amount_not_positive`, `payment_date_in_past`, `missing_barcode`, `invalid_barcode`, `utility_bill_not_supported`, `pix_qr_code_not_supported`, `missing_pix_key`, `invalid_pix_key`, `missing_transfer_data`, `invalid_holder_document`, `missing_beneficiary_name`, `beneficiary_bank_missing`, `branch_document_invalid`, `account_data_incomplete`, `line_length_invalid`, `non_ascii_character`, `record_order_invalid`, `sequence_gap`, `batch_trailer_mismatch`, `file_trailer_mismatch`, `total_amount_mismatch`, `bank_code_mismatch`.

---

## 8. Events e listeners

Namespaces por domínio (`agrupar_por_dominio`): `Settlement` e `Cnab`. Todas as classes `final`, payload mínimo (o model), disparo **no Service, após o commit**.

| Event | Quando | Listener(s) | Fila |
|-------|--------|-------------|------|
| `App\Events\Settlement\PaymentSettlementCreated` | Baixa `draft` criada | `App\Listeners\Settlement\LogPaymentSettlementActivity` (log estruturado) | Não (sync, leve) |
| `App\Events\Settlement\PaymentSettlementConfirmed` | Baixa → `settled` | `LogPaymentSettlementActivity` | Não |
| `App\Events\Settlement\PaymentSettlementCancelled` | Baixa → `cancelled` | `LogPaymentSettlementActivity` | Não |
| `App\Events\PaymentRequest\PaymentRequestStatusChanged` (existente) | Cada PR `Launched → Settled` | `LogPaymentRequestActivity` (existente) | Não. Passa a `ShouldDispatchAfterCommit` (§2.4) |
| `App\Events\Cnab\CnabFileGenerationRequested` | `CnabFile(queued)` criado | `App\Listeners\Cnab\QueueCnabFileGeneration` → `GenerateCnabFileJob::dispatch($file)` (listener sync fino; padrão `QueueAttachmentBatchRename`) | Job em fila |
| `App\Events\Cnab\CnabFileGenerated` (DRF §6) | Job concluiu com `generated` | `App\Listeners\Cnab\NotifyCnabFileGenerated` → `CnabFileGeneratedNotification` ao `created_by` ("arquivo pronto para download"); `LogCnabFileActivity` | Sim (notify); log sync |
| `App\Events\Cnab\CnabFileGenerationFailed` | Arquivo → `failed` (dry-run ou infra) | `App\Listeners\Cnab\NotifyCnabFileGenerationFailed` → `CnabFileGenerationFailedNotification`; `LogCnabFileActivity` | Sim (notify) |
| `App\Events\Cnab\CnabFileDownloaded` | Cada download autorizado | `LogCnabFileActivity` (usuário, IP, `file_sequence`, checksum) | Não |

**Leitura do DRF §6 "CnabFileGenerated → Registrar download":** interpretado como "disponibilizar e registrar o arquivo para download e avisar o solicitante". O registro de cada download efetivo é o evento dedicado `CnabFileDownloaded`.

**Registro:** listeners com `handle(Event)` tipado usam auto-discovery (o `AppServiceProvider` já avisa que registro explícito duplicaria). Não adicionar `Event::listen` para eles.

**Notificações** (`App\Notifications`, `ShouldQueue`, `via: ['mail','database']`, padrão `AttachmentBatchRenamedNotification`):

- `CnabFileGeneratedNotification(CnabFile)`: título/corpo com NSA, quantidade e valor total; URL `PaymentSettlementResource::getUrl('view', …)`. Nunca link direto ao arquivo.
- `CnabFileGenerationFailedNotification(CnabFile)`: `failure_reason` seguro + URL da baixa.
- Chaves em `lang/*/notifications.php` (`cnab_file_generated.*`, `cnab_file_generation_failed.*`).

---

## 9. Camadas

### 9.1 DTOs (flat `App\DTOs`, `final readonly`)

| DTO | Campos | Uso |
|-----|--------|-----|
| `PaymentSettlementData` | `branchId`, `branchBankAccountId`, `settlementDate` (CarbonImmutable), `paymentRequestIds` (list<string>), `notes` | Criação da baixa |
| `EligiblePaymentFilterData` | `branchId`, `dueFrom`, `dueUntil`, `paymentMethod` (opcional) | Query de elegíveis (tela e testes) |
| `CnabRemittanceData` | `layout`, `fileSequence`, `generatedAt`, `paymentDate`, `company` (CNPJ, nome), `debitAccount` (bank code, agência, conta, DV), `config` (campos tipados), `items` (list<CnabRemittanceItemData>) | Entrada do adapter |
| `CnabRemittanceItemData` | `settlementItemId`, `reference`, `paymentType`, `amount` (string), `dueDate`, `beneficiaryName`, `beneficiaryDocument`, `beneficiaryBankCode`, `agency`, `agencyDigit`, `accountNumber`, `accountDigit`, `accountType`, `pixKeyType`, `pixKey`, `barcode` (44) | Um pagamento |
| `CnabRemittanceResult` | `content` (string), `recordsCount`, `batchesCount`, `placements` (map settlementItemId → {batchNumber, recordSequence, paymentFormCode}), `totalAmount` | Saída do adapter |
| `CnabValidationError` | `code`, `field` (nullable), `params` (array), `settlementItemId` (nullable) | Erro de config, item ou estrutural |
| `CnabValidationReport` | `configErrors`, `itemErrors` (map itemId → list), `structuralErrors`, `isValid(): bool`, `errorCount(): int` | Dry-run e gate do job |

### 9.2 Services (flat `App\Services`)

| Service | Responsabilidades (assinaturas em prosa) |
|---------|------------------------------------------|
| `PaymentSettlementService` | `eligibleQuery(EligiblePaymentFilterData, User): Builder` (base da tela: `visibleTo` + `eligibleForSettlement` + filtros + eager load); `create(PaymentSettlementData, User): PaymentSettlement` (asserts §7.1, `lockForUpdate` nas PRs, cria itens com snapshot, contadores bcmath, trata `UniqueConstraintViolationException` → `paymentRequestAlreadyInSettlement`, dispara `PaymentSettlementCreated` após o commit); `releaseItem(PaymentSettlementItem, User): void`; `confirm(PaymentSettlement, User): PaymentSettlement` (lock da baixa; asserts; para cada item ativo chama `PaymentRequestService::transitionStatus(pr, Settled, actor, notes, settlement)`; grava `settled_at/by`; evento); `cancel(PaymentSettlement, User, string $reason): PaymentSettlement` (libera itens; `CnabFileService::supersedeForCancellation`; evento); `recalculateTotals(PaymentSettlement): void` |
| `CnabConfigService` | `create(array, User)`, `update(CnabConfig, array, User)`, `deactivate(CnabConfig, User)`: valida conta ativa com `bank_id`, `layout->bankCode() === account.bank.code`, unicidade, lock após emissão, e chama `adapter->validateConfig()` para feedback imediato no form. No `create`, semeia `last_file_sequence` com o maior NSA já emitido para a conta (configs anteriores `withTrashed`); no `update`, rejeita valor abaixo dele (§3.1, DBA §21 #2). `deactivate` só pausa; substituir config = soft delete + create |
| `CnabRemittanceService` | Puro e sem persistência. `buildData(PaymentSettlement, CnabConfig, int $fileSequence): CnabRemittanceData` (mapeia PR + bankDetails + supplier; `BoletoBarcode` converte linha digitável; escolhe chave PIX em vez de QR); `validate(PaymentSettlement): CnabValidationReport` (config + itens + build + validação estrutural = **dry-run**); `render(CnabRemittanceData): CnabRemittanceResult` |
| `CnabFileService` | `request(PaymentSettlement, User): CnabFile` (asserts: `draft`, data ≥ hoje, config ativa, sem ativo; cria `queued`; evento `CnabFileGenerationRequested` após o commit); `generate(CnabFile): void` (chamado pelo job; §9.4); `markFailed(CnabFile, string $userReason, ?CnabValidationReport)`; `retry(CnabFile, User): CnabFile`; `regenerate(CnabFile, User, string $reason): CnabFile` (Adm: supersede + request); `supersedeForCancellation(PaymentSettlement, User)`; `download(CnabFile, User): StreamedResponse` (asserts, checksum, `downloaded_at/by` na primeira vez, evento) |

**Regra:** Filament e Actions não falam com `Storage` nem com adapters diretamente; tudo passa pelos Services.

### 9.3 Actions

`App\Actions\Settlement\` (Policy check + delegação ao Service; mesmo padrão de `App\Actions\Attachment\*`):

| Action | Uso |
|--------|-----|
| `CreatePaymentSettlementAction` | `authorize('create', PaymentSettlement::class)` → `PaymentSettlementService::create` |
| `ReleasePaymentSettlementItemAction` | `authorize('update', $settlement)`, apenas `draft` sem CNAB ativo/gerado → `releaseItem` |
| `ConfirmPaymentSettlementAction` | `authorize('confirm', $settlement)` → `confirm` |
| `CancelPaymentSettlementAction` | `authorize('cancel', $settlement)` → `cancel` |

`App\Actions\Cnab\`:

| Action | Uso |
|--------|-----|
| `ValidateCnabRemittanceAction` | `authorize('generateCnab', $settlement)` → `CnabRemittanceService::validate` (dry-run) → `CnabValidationReport` |
| `RequestCnabFileGenerationAction` | `authorize('generateCnab', $settlement)` → `CnabFileService::request` |
| `RetryCnabFileGenerationAction` | `authorize('retry', $file)` → `retry` |
| `RegenerateCnabFileAction` | `authorize('regenerate', $file)` (Adm) → `regenerate` |
| `DownloadCnabFileAction` | `authorize('download', $file)` → `download` |

### 9.4 Job: `App\Jobs\Cnab\GenerateCnabFileJob`

- `implements ShouldQueue`; `use Queueable, SerializesModels` (igual `ProcessImportBatchJob`/`RenameAttachmentBatchJob`).
- `public int $tries = 10;` `public int $maxExceptions = 3;` `public int $timeout = 120;` `public array $backoff = [10, 30, 60];` (rev. 2026-09-27; antes `$tries = 3`).
- Construtor: `onConnection(config('rjet.cnab.queue.connection'))->onQueue(config('rjet.cnab.queue.name'))`.
- `middleware()`: `WithoutOverlapping((string) $file->payment_settlement_id)->releaseAfter(30)->expireAfter($timeout + 60)` (rev. 2026-09-27; ~~`->dontRelease()`~~ descartava a reentrega sem `failed()`, §3.11).
- Construtor: `public CnabFile $file`.
- `handle(CnabFileService $service)`: `$service->generate($this->file)`.

`CnabFileService::generate(CnabFile)`, passo a passo:

1. Recarrega com lock. Se status ∉ {`queued`, `generating`}, **return** (idempotente).
2. Status `generating`, `started_at`. Sempre atualiza `updated_at`, mesmo se já `generating` (rev. 2026-09-27, §3.11).
3. `CnabRemittanceService::validate()` (gate dry-run). Se inválido: persiste `cnab_file_items` com erros, chama `markFailed(userReason = remittance_invalid)`, dispara `CnabFileGenerationFailed` e **retorna sem lançar**. Erro determinístico não deve gastar retries.
4. Transação com `lockForUpdate` na `CnabConfig`: se `file_sequence` é null, atribui `last_file_sequence + 1` e incrementa a config; senão reusa (retry após crash).
5. `render()` → `CnabRemittanceValidator` de novo sobre o conteúdo final (defesa em profundidade).
6. `Storage::disk()->put(path, content)`. Se falhar, lança `CnabException::storageWriteFailed()` (infra → retry).
7. Transação: grava `cnab_file_items` (placements), `size`, `checksum`, `records_count`, `items_count`, `total_amount`, `config_snapshot`, `filename`, `generated_at`, status `generated`. Recarrega com `lockForUpdate`; se status ≠ `generating` (ex.: reconciliador marcou `failed`), apaga o arquivo gravado e retorna sem evento (rev. 2026-09-27).
8. Após o commit: `CnabFileGenerated`.
9. Compensação: se o passo 7 falhar após o `put`, apaga o arquivo recém-gravado antes de relançar.

`failed(?Throwable)`: se o arquivo ainda está em andamento (`queued|generating`; `markFailed` já recusa `generated`), `markFailed` com mensagem segura (`BusinessException::getUserMessage()` ou `cnab_files.messages.generation_interrupted`); log com `cnab_file_id`, `exception`, `exception_class` (padrão `RenameAttachmentBatchJob`). O NSA atribuído **permanece consumido** (lacuna aceitável e auditável; o Itaú não exige NSA contíguo para upload manual, a confirmar em P-F7-NSA).

~~Fila: `default` (A7).~~ Conexão `rjet.cnab.queue.connection` (`cnab_database`/`cnab_redis`), fila `cnab`, `retry_after` 240, worker `--timeout=150`; recuperação por `cnab:recover-stuck-files` (§3.11, rev. 2026-09-27).

### 9.5 Integrations: contratos do adapter (`App\Integrations\Cnab\`)

| Classe | Papel |
|--------|-------|
| `CnabRemittanceAdapter` (interface) | `layout(): CnabLayout`; `supportsBankCode(string $code): bool`; `validateConfig(CnabConfig, BranchBankAccount, Branch): list<CnabValidationError>`; `validateItem(CnabRemittanceItemData, CarbonImmutable $paymentDate): list<CnabValidationError>`; `paymentFormCode(CnabRemittanceItemData, string $debitBankCode): string`; `build(CnabRemittanceData): CnabRemittanceResult`; `fileName(CnabRemittanceData): string` |
| `CnabAdapterResolver` | `for(CnabLayout): CnabRemittanceAdapter`; lança `CnabException::layoutNotSupported` |
| `CnabRemittanceValidator` | Validação estrutural 240, agnóstica de banco (§3.5 camada 3): `validate(string $content, CnabRemittanceData, CnabRemittanceResult): list<CnabValidationError>` |
| `FixedWidthFormatter` | Helpers puros: `numeric(value, length)`, `alpha(value, length)` (ASCII, uppercase, trunca/pad), `date(Carbon)`, `amountInCents(string decimal, length)` via bcmath, `blank(length)` |
| `Itau\Itau240RemittanceAdapter` | Implementação SISPAG: agrupa por forma, monta registros 0/1/3(A,B,J,J-52)/5/9, calcula trailers, nome do arquivo |
| `Itau\Itau240Layout` | Constantes de posições/tamanhos por campo e por registro, com referência à versão do manual (P-F7-SPEC). Único lugar com "bytes" |
| `App\Support\BoletoBarcode` | `fromDigitableLine(string): string` (47 → 44 com validação de DVs dos campos e DV geral), `isUtilityBill(string): bool`, `bankCode(string $barcode): string`. Reutilizável pelo OCR no futuro, sem refatorar o OCR nesta fase |

**Bind** (`AppServiceProvider::register()`): `CnabAdapterResolver` como singleton com mapa `[CnabLayout::Itau240->value => Itau240RemittanceAdapter::class]`.

### 9.6 Form Requests

Não se aplicam (sem HTTP próprio). Validação de UI no schema Filament e asserts de negócio nos Services (padrão F3–F6).

### 9.7 Config (`config/rjet.php`, nova chave `cnab`)

| Chave | Env | Default |
|-------|-----|---------|
| `cnab.disk` | `RJET_CNAB_DISK` → `FILESYSTEM_DISK` | `local` |
| `cnab.directory` | — | `cnab` |
| `cnab.max_items` | `RJET_CNAB_MAX_ITEMS` | `500` (limite também da baixa) |
| `cnab.line_ending` | — | `"\r\n"` |
| `cnab.queue.connection` | `RJET_CNAB_QUEUE_CONNECTION` | `cnab_database` (prod/.env.example: `cnab_redis`; phpunit: `sync`) |
| `cnab.queue.name` | `RJET_CNAB_QUEUE` | `cnab` |
| `cnab.recovery.margin_seconds` | — | `60` |
| `cnab.recovery.max_age_minutes` | — | `20` |
| `queue.connections.cnab_database` / `cnab_redis` `.retry_after` | `RJET_CNAB_QUEUE_RETRY_AFTER` | `240` (fonte única; o reconciliador lê daqui) |

---

## 10. Performance, file storage e soft deletes

### 10.1 Performance (RNF011: ~70/dia)

| Tema | Decisão |
|------|---------|
| Cache | **Nenhum.** Configs são poucas e as consultas triviais; status muda rápido |
| Eager load, tela de elegíveis | `supplier`, `bankDetails.bank`, `branch` |
| Eager load, lista de baixas | `branch`, `branchBankAccount.bank`, `currentCnabFile`, `creator` |
| Eager load, View da baixa | `items.paymentRequest.supplier`, `items.paymentRequest.bankDetails.bank`, `cnabFiles.creator` |
| Eager load, geração | `settlement.branch`, `settlement.branchBankAccount.bank`, `activeItems.paymentRequest.{supplier, bankDetails.bank}`, `config` |
| N+1 | `Model::preventLazyLoading` já ativo fora de produção; Tables com `modifyQueryUsing(with(...))` |
| Geração em memória | Conteúdo em string: 500 itens × ~4 linhas × 242 bytes ≈ 0,5 MB, aceitável; sem chunk/stream no MVP |
| Totais | bcmath no Service; nada de `SUM` em float no PHP |
| Índices | §4.7. Nenhum índice novo em `payment_requests` (confirmado pela `dba`; gatilho de reavaliação em §4.7) |
| Concorrência | Unique parciais são a trava final: `UniqueConstraintViolationException` → `paymentRequestAlreadyInSettlement` (itens) / `generationAlreadyActive` (arquivo ativo). Checagem prévia no Service só melhora a mensagem, não substitui o índice |

### 10.2 File storage

§3.8. O `.rem` é gerado pelo sistema, não é upload do usuário. As restrições de MIME PDF/imagem (RN015.1) valem só para uploads e não se aplicam aqui. Sem `FileUpload` na F7.

Observer `CnabFileObserver::forceDeleted` apaga o arquivo físico (padrão F3 `AttachmentObserver`). Soft delete **não** apaga o arquivo (retenção financeira). Observer só dispara em forceDelete **por model** (`->each->forceDelete()`); `forceDelete()` em query builder deixa o `.rem` órfão no disco (DBA §21 #1).

### 10.3 Soft deletes

| Entidade | SoftDeletes | Regra |
|----------|:-----------:|-------|
| `PaymentSettlement` | ✅ | Delete só Adm e só em `cancelled`. `settled` é registro financeiro e **nunca** é excluído via UI |
| `PaymentSettlementItem` | ❌ | Ciclo via `released_at`; unique parcial depende disso |
| `CnabConfig` | ✅ | Soft delete libera a conta para nova config (é o caminho de **substituição**; desativar não libera); bloqueado se houver arquivo `queued`/`generating`. ForceDelete bloqueado pelo restrict de `cnab_files` (inclusive soft-deleted) |
| `CnabFile` | ✅ | Sem delete na UI; soft só em cascata da baixa |
| `CnabFileItem` | ❌ | Append-only, hard-owned |

**Soft delete vs FK `onDelete`** (`soft-deletes.md` §6: FK não dispara no soft):

| Evento | FK | Aplicação |
|--------|----|-----------|
| Soft delete **baixa** (cancelada) | Nada | `PaymentSettlementObserver::deleted` soft-deleta `cnab_files` (já `superseded`/`failed`) |
| ForceDelete **baixa** (Adm) | Items: `cascadeOnDelete`. `cnab_files`: `restrictOnDelete` **bloqueia** | **Ordem fechada (DBA §21 #1)**, numa transação: (1) `$settlement->cnabFiles()->withTrashed()->get()->each->forceDelete()`. `withTrashed` é obrigatório porque os arquivos já foram soft-deletados junto com a baixa, e o scope global os esconderia. `each` é obrigatório para disparar o observer que apaga o físico. Os `cnab_file_items` saem por cascade. (2) `$settlement->forceDelete()`: os `payment_settlement_items` saem por cascade, já sem `cnab_file_items` apontando para eles. Ordem resultante: `cnab_file_items → cnab_files → payment_settlement_items → payment_settlements`. Apagar o físico **após o commit** (ou tolerar arquivo ausente) para não perder o `.rem` se a transação reverter |
| Soft delete **PR** (Adm) com item ativo em `draft` | Nada | Dry-run acusa `payment_request_unavailable`; confirmar lança `itemsChangedSinceSelection`. Operador remove o item ou cancela |
| ForceDelete **PR** | `payment_settlement_items.payment_request_id` restrict **bloqueia** | Intencional: PR que passou por baixa não é apagada fisicamente |
| Soft delete **BranchBankAccount** com baixa `draft` | Nada | Criar baixa/CNAB exige conta ativa; baixas existentes leem com `withTrashed`. `BranchBankAccountService`/Policy deve bloquear exclusão com `CnabConfig` viva (guard novo, análogo a `BankService::ensureDeletable`) |
| ForceDelete **BranchBankAccount** | Restrict (settlements, configs) **bloqueia** | Intencional |
| Restore **baixa** | — | Sem restore na UI no MVP (baixa `cancelled` excluída não volta). Se surgir, `PaymentSettlementObserver::restored` restaura os `cnab_files` soft-deletados no mesmo `deleted_at` |

**Pruning:** adiado (retenção financeira; alinhado a F3/F6).

---

## 11. Filament Resources (formato Blueprint obrigatório)

Painel **`admin`**. Todas as classes `final`. Resource principal limpo (só delegates). Table com `recordActions`/`toolbarActions`. Ícones com enum `Heroicon`. Infolist **sempre**. SoftDeletes via `getRecordRouteBindingEloquentQuery()`. Labels 100% `__()`.

### 11.1 Resource: `CnabConfigResource`

```
Resource: CnabConfigResource
  Command: php artisan make:filament-resource CnabConfig --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\CnabConfigs\CnabConfigResource
  Structure:  (pasta PLURAL: CnabConfigs/)
    - CnabConfigResource.php (final, LIMPO — só delegates)
    - Schemas/CnabConfigForm.php (final)
    - Schemas/CnabConfigInfolist.php (final) — SEMPRE
    - Tables/CnabConfigsTable.php (final)
    - Pages/ (CreateCnabConfig, EditCnabConfig, ListCnabConfigs, ViewCnabConfig)
    - RelationManagers/CnabFilesRelationManager.php (somente leitura: arquivos emitidos com esta config)
  SoftDeletes: getRecordRouteBindingEloquentQuery()
  Icon: Heroicon::OutlinedDocumentText
  Navigation:
    Group: __('navigation.groups.settings')
    Sort: 30   (entre ApprovalRule=20 e ImportTemplate=40)
    Label: __('cnab_configs.navigation_label')
  Visibility: viewAny Operador+Adm; create/edit/delete Adm (Policy)
  Form:
    Section: __('cnab_configs.sections.account')
      Field: branch_id (virtual, dehydrated(false))
        Component: Filament\Forms\Components\Select
        Validation: required
        Config: ->options(Branch::active()) ->searchable() ->preload() ->live() ->native(false)
                ->afterStateHydrated(from record.branchBankAccount.branch_id)
                ->disabled(fn (?CnabConfig $record) => $record?->hasIssuedFiles())
      Field: branch_bank_account_id
        Component: Filament\Forms\Components\Select
        Validation: required; exists branch_bank_accounts (active, bank_id not null, branch_id = state branch_id)
        Config: ->options(fn (Get $get) => accounts of branch: "{bank_code} — Ag {agency} / CC {account_number}-{account_digit}")
                ->searchable() ->native(false) ->disabled(hasIssuedFiles)
      Field: layout
        Component: Filament\Forms\Components\Select
        Validation: required; enum CnabLayout
        Config: ->options(CnabLayout::class) ->default(CnabLayout::Itau240) ->native(false) ->disabled(hasIssuedFiles)
    Section: __('cnab_configs.sections.parameters')
      Field: company_name
        Component: Filament\Forms\Components\TextInput
        Validation: nullable; max:30
        Config: ->helperText(__('cnab_configs.help.company_name_fallback'))
      Field: agreement_code
        Component: Filament\Forms\Components\TextInput
        Validation: nullable; max:20; regex digits/alnum
      Field: wallet_code
        Component: Filament\Forms\Components\TextInput
        Validation: nullable; max:10
      Field: payment_type_code
        Component: Filament\Forms\Components\TextInput
        Validation: required; size:2; digits
        Config: ->default('20')
      Field: last_file_sequence
        Component: Filament\Forms\Components\TextInput
        Validation: required; integer; min:0; max:999999
        Config: ->numeric() ->default(0) ->disabled(hasIssuedFiles) ->helperText(__('cnab_configs.help.last_file_sequence'))
      Field: is_active
        Component: Filament\Forms\Components\Toggle
        Config: ->default(true)
  Infolist:
    Entry: branchBankAccount.branch.name → Filament\Infolists\Components\TextEntry
    Entry: branchBankAccount (bank_code, agency, account) → TextEntry (formatStateUsing)
    Entry: layout → TextEntry ->badge()
    Entry: company_name / agreement_code / wallet_code / payment_type_code → TextEntry ->placeholder('—')
    Entry: last_file_sequence → TextEntry
    Entry: is_active → Filament\Infolists\Components\IconEntry ->boolean()
    Entry: creator.name / updater.name / created_at / updated_at → TextEntry (auditoria RF032)
  Table:
    Column: branchBankAccount.branch.name → Filament\Tables\Columns\TextColumn ->searchable() ->sortable()
    Column: branchBankAccount.bank_code → TextColumn
    Column: account (agência/conta) → TextColumn (formatStateUsing)
    Column: layout → TextColumn ->badge()
    Column: last_file_sequence → TextColumn
    Column: is_active → Filament\Tables\Columns\IconColumn ->boolean()
    Column: updater.name / updated_at → TextColumn ->toggleable(isToggledHiddenByDefault: true)
    Filter: branch → Filament\Tables\Filters\SelectFilter (relationship branchBankAccount.branch)
    Filter: layout → SelectFilter ->options(CnabLayout::class)
    Filter: is_active → Filament\Tables\Filters\TernaryFilter
    Filter: TrashedFilter (Adm)
  RelationManagers:
    - CnabFilesRelationManager (hasMany cnabFiles; read-only; colunas NSA, status, baixa, generated_at)
  RecordActions: [View, Edit (Adm), Delete (Adm), Restore (Adm)]
  ToolbarActions: [BulkActionGroup → [DeleteBulk (Adm)]]
```

Pages Create/Edit delegam persistência a `CnabConfigService` (`handleRecordCreation`/`handleRecordUpdate`) e capturam `BusinessException` → `Notification::danger($e->getUserMessage())` + `halt()`.

### 11.2 Resource: `PaymentSettlementResource` (baixa em lote)

```
Resource: PaymentSettlementResource
  Command: php artisan make:filament-resource PaymentSettlement --generate --soft-deletes --view --panel=admin --no-interaction
  Location: App\Filament\Resources\PaymentSettlements\PaymentSettlementResource
  Structure:  (pasta PLURAL: PaymentSettlements/)
    - PaymentSettlementResource.php (final, LIMPO)
    - Schemas/PaymentSettlementForm.php (final) — schema do modal da bulk action (data + conta + notas)
    - Schemas/PaymentSettlementInfolist.php (final) — SEMPRE
    - Tables/PaymentSettlementsTable.php (final)
    - Tables/EligiblePaymentRequestsTable.php (final) — tabela da página de seleção
    - Pages/ListPaymentSettlements.php
    - Pages/CreatePaymentSettlement.php — página custom (Resource Page + HasTable), NÃO CreateRecord
    - Pages/ViewPaymentSettlement.php
    - (sem EditPaymentSettlement — baixa imutável)
    - RelationManagers/ItemsRelationManager.php
    - RelationManagers/CnabFilesRelationManager.php
    - Actions/CreatePaymentSettlementBulkAction.php
    - Actions/ConfirmPaymentSettlementAction.php
    - Actions/CancelPaymentSettlementAction.php
    - Actions/ReleasePaymentSettlementItemAction.php
    - Actions/ValidateCnabRemittanceAction.php
    - Actions/GenerateCnabFileAction.php
    - Actions/RetryCnabFileGenerationAction.php
    - Actions/RegenerateCnabFileAction.php
    - Actions/DownloadCnabFileAction.php
  SoftDeletes: getRecordRouteBindingEloquentQuery()
  Icon: Heroicon::OutlinedCheckBadge
  Navigation:
    Group: __('navigation.groups.operations')
    Sort: 20   (após PaymentRequest=1, ImportBatch=15, AttachmentBatch=16)
    Label: __('payment_settlements.navigation_label')   // "Baixas"
  Visibility: Operador + Adm (viewAny); Cliente excluído
  Form (modal da CreatePaymentSettlementBulkAction):
    Field: settlement_date
      Component: Filament\Forms\Components\DatePicker
      Validation: required; date
      Config: ->native(false) ->default(today('America/Sao_Paulo')) ->helperText(__('payment_settlements.help.settlement_date'))
    Field: branch_bank_account_id
      Component: Filament\Forms\Components\Select
      Validation: required; conta ativa da filial das PRs selecionadas, com bank_id
      Config: ->options(fn (Collection $records) => active accounts of the single branch of $records)
              ->default(conta is_default da filial) ->native(false)
              ->hint(fn (Get $get) => CnabConfig ativa? "CNAB disponível" : "Sem configuração CNAB — baixa manual")
    Field: notes
      Component: Filament\Forms\Components\Textarea
      Validation: nullable; max:1000
      Config: ->rows(3)
  Infolist:
    Section: __('payment_settlements.sections.summary')
      Entry: status → Filament\Infolists\Components\TextEntry ->badge()
      Entry: branch.name → TextEntry
      Entry: branchBankAccount → TextEntry (bank_code — Ag/CC)
      Entry: settlement_date → TextEntry ->date('d/m/Y')
      Entry: items_count → TextEntry
      Entry: total_amount → TextEntry ->money('BRL')
      Entry: notes → TextEntry ->placeholder('—')
    Section: __('payment_settlements.sections.cnab')
      Entry: currentCnabFile.status → TextEntry ->badge() ->placeholder(__('payment_settlements.messages.no_cnab'))
      Entry: currentCnabFile.file_sequence / filename / generated_at → TextEntry
      Entry: currentCnabFile.failure_reason → TextEntry ->visible(failed)
    Section: __('common.sections.audit')
      Entry: creator.name / created_at / settler.name / settled_at / canceller.name / cancelled_at / cancellation_reason → TextEntry
  Table (PaymentSettlementsTable):
    Column: settlement_date → Filament\Tables\Columns\TextColumn ->date('d/m/Y') ->sortable()
    Column: branch.name → TextColumn ->searchable() ->sortable()
    Column: branchBankAccount.bank_code → TextColumn
    Column: status → TextColumn ->badge()
    Column: items_count → TextColumn
    Column: total_amount → TextColumn ->money('BRL') ->sortable()
    Column: currentCnabFile.status → TextColumn ->badge() ->label(__('payment_settlements.fields.cnab_status'))
    Column: creator.name → TextColumn ->toggleable()
    Column: created_at → TextColumn ->dateTime('d/m/Y H:i') ->sortable() ->toggleable(isToggledHiddenByDefault: true)
    Filter: status → Filament\Tables\Filters\SelectFilter ->options(PaymentSettlementStatus::class)
    Filter: branch → SelectFilter ->relationship('branch','name') ->searchable() ->preload()
    Filter: settlement_date → Filament\Tables\Filters\Filter (DatePicker from/until)
    Filter: TrashedFilter (Adm)
    DefaultSort: settlement_date desc
    ModifyQuery: with(['branch','branchBankAccount','currentCnabFile','creator'])
  RelationManagers:
    - ItemsRelationManager (hasMany items)
        Columns: paymentRequest.supplier.name, paymentRequest.payment_method (badge), paymentRequest.due_date, amount (money BRL),
                 paymentRequest.status (badge), released_at (placeholder "Ativo"), link para PR (url PaymentRequestResource view)
        recordActions: [ReleasePaymentSettlementItemAction (draft, sem CNAB ativo/gerado)]
        headerActions: []  toolbarActions: []
        modifyQuery: with(['paymentRequest.supplier','paymentRequest.bankDetails.bank'])
    - CnabFilesRelationManager (hasMany cnabFiles)
        Columns: file_sequence, status (badge), items_count, total_amount (money), creator.name, generated_at, downloaded_at, failure_reason (limit + tooltip)
        recordActions: [DownloadCnabFileAction, RetryCnabFileGenerationAction, RegenerateCnabFileAction (Adm), ViewValidationErrors (modal com cnab_file_items inválidos)]
        toolbarActions: []
  RecordActions (tabela): [View, Delete (Adm, só cancelled), Restore (Adm)]
  ToolbarActions (tabela): [BulkActionGroup → [DeleteBulk (Adm, só cancelled)]]
  ViewPaymentSettlement header actions:
    [ValidateCnabRemittanceAction, GenerateCnabFileAction, DownloadCnabFileAction (currentCnabFile),
     ConfirmPaymentSettlementAction, CancelPaymentSettlementAction]
    + polling leve (->poll('5s')) enquanto currentCnabFile ∈ {queued, generating}
```

**Página `CreatePaymentSettlement` (RF030 + RF031)**, Filament puro:

```
Page: CreatePaymentSettlement
  Base: Filament\Resources\Pages\Page  implements Filament\Tables\Contracts\HasTable
  Traits: Filament\Tables\Concerns\InteractsWithTable
  Route: PaymentSettlementResource::getPages()['create'] => '/create'
  Authorization: mount() → authorize('create', PaymentSettlement::class)
  Table: EligiblePaymentRequestsTable::configure($table)
    Query: PaymentSettlementService::eligibleQuery(...) — base = PaymentRequest::visibleTo($user)->eligibleForSettlement()
           ->with(['supplier','bankDetails.bank','branch'])
    Column: branch.name → TextColumn ->sortable()
    Column: supplier.name → TextColumn ->searchable()
    Column: payment_method → TextColumn ->badge()
    Column: bankDetails.deposit_type → TextColumn ->badge() ->placeholder('—')
    Column: due_date → TextColumn ->date('d/m/Y') ->sortable()
    Column: net_amount → TextColumn ->money('BRL') ->sortable() ->summarize(Sum::make()->money('BRL'))
    Column: status → TextColumn ->badge()   (sempre Launched — informativo, A1)
    Filter: branch → SelectFilter ->relationship('branch','name') ->searchable() ->preload()   (seleção de filial única: validada na bulk)
    Filter: due_date → Filter (DatePicker due_from / due_until) — usa scopeDueBetween existente
    Filter: payment_method → SelectFilter ->options(PaymentMethod::class)
    DefaultSort: due_date asc
    Selectable: sim (inclui "selecionar todos os filtrados" nativo)
    ToolbarActions: [BulkActionGroup → [CreatePaymentSettlementBulkAction]]
      CreatePaymentSettlementBulkAction:
        ->schema(PaymentSettlementForm::components())
        ->action(): monta PaymentSettlementData(branchId = filial única dos $records, ids, data, conta, notas)
                    → CreatePaymentSettlementAction; BusinessException → Notification::danger + halt
                    → sucesso: redirect PaymentSettlementResource::getUrl('view', record)
        ->deselectRecordsAfterCompletion()
    EmptyState: __('payment_settlements.messages.no_eligible')
```

**Actions da View** (resumo de visibilidade e regras):

| Action | Visível quando | Efeito |
|--------|----------------|--------|
| `ValidateCnabRemittanceAction` | `draft` + config ativa + Policy `generateCnab` | Modal com relatório (erros de config, por item e estruturais) ou "Remessa válida" |
| `GenerateCnabFileAction` | `draft` + config ativa + sem arquivo ativo + data ≥ hoje | `requiresConfirmation`; `RequestCnabFileGenerationAction`; toast "Geração enfileirada" |
| `DownloadCnabFileAction` | `currentCnabFile` `generated` + Policy `download` | Stream do `.rem` com `filename` |
| `RetryCnabFileGenerationAction` | último arquivo `failed` + baixa `draft` | Novo `CnabFile(queued)` |
| `RegenerateCnabFileAction` | `generated` + Adm + baixa `draft` | Modal com motivo obrigatório + aviso de duplicidade de pagamento; supersede + novo |
| `ConfirmPaymentSettlementAction` | `draft` + data ≤ hoje + sem arquivo em andamento | `requiresConfirmation`, com aviso quando não houver CNAB gerado; confirma |
| `CancelPaymentSettlementAction` | `draft` + sem arquivo em andamento | Modal com motivo obrigatório; aviso se houver arquivo `generated` ("pode já ter sido enviado ao banco") |

### 11.3 Policies

| Policy | viewAny / view | create | Custom | delete / restore / forceDelete |
|--------|----------------|--------|--------|--------------------------------|
| `PaymentSettlementPolicy` | Operador, Adm | Operador, Adm | `update` (remover item) = Operador/Adm + `draft`; `confirm` = Operador/Adm + `draft`; `cancel` = Operador/Adm + `draft`; `generateCnab` = Operador/Adm + `draft` | `delete`/`deleteAny` = Adm + `cancelled`; `restore` = Adm; `forceDelete` = Adm + `cancelled` |
| `CnabConfigPolicy` | Operador, Adm | Adm | `update` = Adm | Adm |
| `CnabFilePolicy` | Operador, Adm | — (via `generateCnab` da baixa) | `download` = Operador/Adm + `generated`; `retry` = Operador/Adm + `failed` + baixa `draft`; `regenerate` = **Adm** + `generated` + baixa `draft` | `delete`/`forceDelete` = **false** (só via cascade do Service) |

Cliente: `false` em tudo (A5). Registrar as três policies no array `POLICIES` do `AppServiceProvider`.

### 11.4 Widgets

Nenhum obrigatório (dashboard é Fase 8).

---

## 12. Livewire custom

**Não é necessário na F7.** Justificativa (`.ai/docs/livewire.md` §8 Regra #1: usar componentes Filament):

- A tela de baixa é uma **tabela Filament** (`InteractsWithTable`) com filtros nativos, seleção múltipla (inclusive "todos os filtrados") e **bulk action com schema** (data + conta + notas). Isso cobre RF030/RF031 sem Blade custom.
- O relatório de dry-run cabe num **modal de Action** (`modalContent` com view Blade simples usando `<x-filament::badge>`/`<x-filament::section>`), sem estado Livewire próprio.
- A F6 precisou de Livewire por causa de preview de arquivos + drag-and-drop. A F7 não tem nada parecido.

Se a operação pedir depois uma UX de "carrinho" (montar a baixa em várias sessões, somatórios por forma de pagamento ao vivo), aí sim criar `App\Livewire\Settlement\SettlementBuilder` (class-based, islands para o painel de totais, `#[Computed]` para somatórios).

---

## 13. Fluxos

### 13.1 Fluxo principal (com CNAB)

1. Operador abre **Baixas → Nova baixa**. A tabela lista só PRs `Launched` visíveis, sem baixa ativa.
2. Filtra por filial e intervalo de vencimento (e, se quiser, forma de pagamento); seleciona itens ou "todos os filtrados".
3. Clica em **"Baixar selecionados"**. No modal informa **data de baixa** (default hoje) e **conta pagadora** (default: conta padrão da filial; hint indica se há CNAB configurado).
4. `CreatePaymentSettlementAction` → Service valida (seleção não vazia, filial única, conta ativa da filial com banco, todas elegíveis, limite de itens, overflow) com lock das PRs → cria `PaymentSettlement(draft)` + itens (snapshot `amount`) + totais → `PaymentSettlementCreated`. Redireciona para a View.
5. Na View, **"Validar remessa"** (dry-run): relatório em modal, sem persistir.
6. **"Gerar CNAB"** → `CnabFile(queued)` → `CnabFileGenerationRequested` → `QueueCnabFileGeneration` → `GenerateCnabFileJob`.
7. Job: `generating` → gate de dry-run ok → NSA atribuído → render → validação estrutural → grava no disco private → `generated` + `cnab_file_items` → `CnabFileGenerated` → notificação `mail` + `database` ao solicitante.
8. Operador **baixa o arquivo** (Policy + checksum) e faz upload manual no internet banking do Itaú → `CnabFileDownloaded`.
9. Na data do pagamento (ou depois), **"Confirmar baixa"** → transação: cada PR `Launched → Settled` via `transitionStatus(..., $settlement)` (histórico com nota da baixa) → baixa `settled` + `settled_at/by` → após o commit: `PaymentRequestStatusChanged` por PR + `PaymentSettlementConfirmed`.

### 13.2 Fluxo sem CNAB (pagamento manual / banco sem adapter)

Passos 1–4 iguais → passo 9 direto. A confirmação avisa "Nenhuma remessa gerada para esta baixa".

### 13.3 Alternativos

| Cenário | Comportamento |
|---------|---------------|
| **Lote vazio** | Bulk action sem seleção fica desabilitada; o Service lança `emptySelection()` (belt) |
| **Filiais misturadas** | `mixedBranches()`; nada é criado |
| **Sem data ou conta** | Validação do schema (`required`) impede submit (RF031) |
| **Conta de outra filial ou inativa** | `bankAccountNotAllowed()` / `bankAccountInactive()` |
| **Concorrência** (duas baixas com a mesma PR) | Segunda falha no unique parcial → `paymentRequestAlreadyInSettlement()`; transação revertida inteira |
| **Config CNAB ausente/inativa** | "Validar/Gerar CNAB" ocultos, com hint na View; chamada direta → `configNotFound()`/`configInactive()`. Baixa manual continua possível |
| **Banco da conta ≠ layout** | Config não pode ser salva (`bankMismatch`); se o banco da conta mudar depois, o dry-run acusa `bank_code_mismatch` |
| **Data de baixa passada ao gerar CNAB** | `paymentDateInPast()`; orientação: cancelar e recriar com data válida, ou baixar sem CNAB |
| **Item inelegível no dry-run/gate** (PIX só QR, arrecadação, transferência incompleta, valor alterado, PR excluída) | Simulação: lista de erros. Job: `failed` + `cnab_file_items` inválidos + notificação. Correção: **remover o item** (Action da RM) e depois **Retry**; ou cancelar a baixa, corrigir a PR (Operador volta a editar após o cancelamento) e recriar |
| **Falha de infraestrutura** (disco/S3 indisponível) | Job lança erro → retry com backoff 10/30/60. Após 3 exceções (`$maxExceptions`, rev. 2026-09-27): `failed()` → `failed` com mensagem genérica + notificação. NSA consumido permanece registrado. Operador usa **Retry** |
| **Crash após gravar arquivo, antes do commit** | Compensação apaga o arquivo. No retry, `file_sequence` já atribuído é reutilizado (mesmo NSA) |
| **Job duplicado / reexecução** | `WithoutOverlapping` por baixa com `releaseAfter(30)` (a duplicata volta para a fila; ~~`dontRelease()`~~ rev. 2026-09-27) + no-op se o arquivo não está `queued`/`generating` |
| **Worker morre durante a geração** | Lock expira em 180 s; a conexão CNAB reentrega em 240 s; `generate()` retoma no mesmo arquivo/NSA. Se ainda parado: `cnab:recover-stuck-files` reenfileira após 300 s sem atualização e marca `failed` (`generation_interrupted`) após 20 min, liberando o Retry |
| **Segunda geração sem ação explícita** | Unique parcial → `generationAlreadyActive()` |
| **Regeneração** (banco rejeitou, dado corrigido) | Adm informa motivo → arquivo atual `superseded` (sem download) → novo `queued` com novo NSA. Aviso explícito de risco de pagamento duplicado |
| **Confirmar com CNAB em andamento** | `cnabGenerationInProgress()` |
| **Confirmar com data futura** | `settlementDateInFuture()` |
| **Confirmar com PR alterada/excluída** | `itemsChangedSinceSelection(n)`; nada muda (tudo ou nada). Remover item ou cancelar |
| **Cancelar com arquivo gerado** | Permitido com aviso; arquivo → `superseded`; itens liberados; PRs elegíveis de novo |
| **Download de arquivo superseded/failed** | Action oculta; chamada direta → `fileNotDownloadable()` |
| **Arquivo sumiu do disco / checksum divergente** | `fileMissing()` / `fileIntegrityCheckFailed()` + log `critical`; Adm usa **Regenerar** |
| **Tentativa de `Settled` pela ação individual da PR** | Opção removida; o Service lança `settlementRequired()` |

---

## 14. Segurança

| Tema | Controle |
|------|----------|
| Autorização | Policies §11.3 + `scopeVisibleTo` (Cliente `1 = 0`) + `abort_unless(can(...), 403)` dentro das Actions Filament (padrão existente) + belt nos Services |
| Papéis | Cliente: nada. Operador: baixa, dry-run, gerar, retry, download, confirmar, cancelar, remover item. Adm: tudo do Operador + config CNAB + regenerar + excluir baixa cancelada |
| Segregação de funções | O MVP não exige aprovação dupla na baixa (DRF não pede). Hook futuro: ability `confirm` exigindo `can_approve` |
| Arquivo | Disco private; path UUID não exposto; download só via app autenticada (sem URL assinada compartilhável); checksum SHA-256 |
| Rastreabilidade | `created_by` (solicitante), `downloaded_by/at`, evento `CnabFileDownloaded` com usuário e IP em log, `superseded_by` + motivo, `settled_by`, `cancelled_by` + motivo, histórico de status da PR |
| Integridade de dados | Snapshot de valor + bloqueio de edição para não-Adm durante baixa `draft` + revalidação na geração e na confirmação |
| Mensagens | `getUserMessage()` sem path, sem conta completa, sem stack; detalhe técnico só em log estruturado |
| Injeção no arquivo | `FixedWidthFormatter` transliterada para ASCII, remove quebras de linha/controle e trunca por campo, então um campo não "vaza" para outro |
| Sem rede | Nenhum adapter faz HTTP (DRF §2.4) |

---

## 15. Factories e seeders

| Factory | Definition | States |
|---------|------------|--------|
| `PaymentSettlementFactory` | `branch` + `branchBankAccount` da mesma filial (`recycle`), `status=draft`, `settlement_date=today`, contadores 0 | `draft()`, `settled()` (com `settled_at/by`), `cancelled()` (com `cancelled_at/by/reason`), `forBranch(Branch)`, `forAccount(BranchBankAccount)`, `withItems(int $n = 3)` (`afterCreating`: PRs `launched()` da filial + itens + `recalculateTotals`), `dated(string)` |
| `PaymentSettlementItemFactory` | `settlement`, `paymentRequest` (**sempre nova** PR `launched()` da filial da baixa, para não colidir no unique parcial ativo), `amount` = `net_amount` | `released()` (`released_at`/`released_by`), `forPaymentRequest(PaymentRequest)` |
| `CnabConfigFactory` | conta `itau()` **nova** por config (o unique parcial por conta impede reuso), `layout=itau_240`, `payment_type_code='20'`, `last_file_sequence=0`, `is_active=true` | `itau240()`, `inactive()`, `forAccount(BranchBankAccount)`, `withSequence(int)`, `trashed()` (para testar substituição + herança de NSA) |
| `CnabFileFactory` | `settlement` + `config` da **mesma conta** da baixa (`recycle`), `status=queued` (ativo: **um** por baixa), `layout=itau_240`, `file_sequence=null` | `queued()`, `generating()`, `generated()` (preenche `file_sequence` por `sequence()` crescente por config, `disk`, `path`, `filename`, `checksum`, `size`; o teste grava o conteúdo com `Storage::fake`), `failed()` (`failure_reason`), `superseded()` (`superseded_at/by/reason`). Para vários arquivos na mesma baixa, só um pode estar em `queued`/`generating`/`generated` |
| `CnabFileItemFactory` | `file`, `settlementItem` da **mesma baixa** do arquivo (`recycle`), `payment_type=transfer`, `reference` = últimos 20 hex do UUID da PR (único no arquivo), `amount`, `is_valid=true` | `invalid(string $code)`, `boleto()`, `pixKey()` |
| `BranchBankAccountFactory` (existente, **estender**) | — | `itau()` (`bank_code='341'`, `bank_id` do Bank 341 via `Bank::query()->firstOrCreate(['code' => '341'], …)`, pois `banks_code_unique` é parcial e `Bank::factory()` repetido quebraria; agência 4 dígitos, conta, DV) |
| `PaymentRequestBankDetailsFactory` (existente) | Já tem `boleto()`, `pix()`, `pixQrCode()`, `pixBoth()`, `transfer()` | **Estender** `boleto()` com linha digitável **válida** (DVs corretos) para golden files; novo `utilityBill()` (arrecadação 48 dígitos) |
| `PaymentRequestFactory` (existente) | Já tem `launched()`, `settled()`, `boleto()`, `depositPix()`, `depositPixQrCode()`, `depositTransfer()`, `forBranch()` | Novo `inDraftSettlement()` opcional (atalho de testes) |

**Seeders:**

- `CnabConfigSeeder` (**apenas dev**, chamado pelo `DevelopmentSeeder`): idempotente (`firstOrCreate` por `branch_bank_account_id`) para a conta Itaú padrão da filial demo, se existir. Nunca em produção (dados bancários reais são cadastrados pelo Adm).
- Sem seeder de baixas/arquivos (dados transacionais; testes usam factories).

Todos os models novos criados com `php artisan make:model ... --factory --no-interaction`.

---

## 16. Testes previstos (Pest, não implementar aqui)

Feature tests com `Storage::fake`, `Queue::fake`/`Event::fake`/`Notification::fake`, Carbon congelado em `America/Sao_Paulo`.

| # | Arquivo sugerido | Cenário |
|---|------------------|---------|
| 1 | `PaymentSettlementEligibilityTest` | `eligibleQuery` retorna só `Launched`; respeita filial, vencimento e forma; exclui trashed; exclui PR com item ativo; **inclui** PR cujo item foi liberado |
| 2 | `PaymentSettlementServiceTest` | Cria `draft` com snapshot de `amount`, `items_count`, `total_amount` (bcmath) e evento `PaymentSettlementCreated`; rejeita seleção vazia, filiais misturadas, conta de outra filial, conta inativa/sem banco, PR não elegível, excesso de itens |
| 3 | `PaymentSettlementServiceTest` | PR já em baixa `draft` → `paymentRequestAlreadyInSettlement` (unique parcial) |
| 4 | `PaymentSettlementServiceTest` | **Confirmar:** PRs → `Settled` + linhas em `payment_request_status_history` + `PaymentRequestStatusChanged` por PR após o commit; `settled_at/by`; data futura → exceção; arquivo `queued/generating` → exceção; PR trashed ou valor alterado → exceção **sem nenhuma PR alterada** (rollback) |
| 5 | `PaymentSettlementServiceTest` | **Cancelar:** itens com `released_at`, arquivo `generated` → `superseded`, PRs elegíveis de novo; bloqueado com arquivo em andamento |
| 6 | `PaymentSettlementServiceTest` | **Remover item:** recalcula totais; bloqueado com arquivo ativo/gerado |
| 7 | `PaymentRequestStatusTransitionTest` (existente, **atualizar**) | `Launched → Settled` sem baixa → `settlementRequired`; com baixa `draft` contendo a PR → ok |
| 8 | `PaymentRequestEditabilityTest` / Policy | Operador não edita PR em baixa `draft`; Adm edita; após cancelamento, Operador volta a editar |
| 9 | `PaymentSettlementAuthorizationTest` | Matriz: Cliente nega tudo (viewAny, create, confirm, cancel, generateCnab, download); Operador opera; Adm deleta só `cancelled`; `regenerate` só Adm; `CnabConfig` escrita só Adm, Operador só lê |
| 10 | `CnabConfigServiceTest` | Única por conta (unique parcial; soft delete libera); banco ≠ `341` para `itau_240` → `bankMismatch`; blameable preenchido; `last_file_sequence`, conta e layout travados após emissão |
| 11 | `CnabRemittanceDryRunTest` | Baixa válida → relatório ok, **nenhum** arquivo no disco, `last_file_sequence` inalterado; erros esperados para PIX só QR, arrecadação, transferência incompleta, documento inválido, valor alterado, data passada, PR excluída, CNPJ da filial inválido |
| 12 | `CnabRemittanceValidatorTest` (unit) | Linha ≠ 240 → `line_length_invalid`; caractere não-ASCII; ordem de registros inválida; lacuna de sequência; trailer de lote/arquivo divergente; somatório divergente |
| 13 | `Itau240RemittanceAdapterTest` | Golden files: entrada fixa (boleto Itaú, boleto outro banco, TED, crédito Itaú, PIX chave) → conteúdo idêntico ao fixture; agrupamento em lotes por forma; `fileName()` na convenção |
| 14 | `BoletoBarcodeTest` (unit) | Linha digitável 47 → barcode 44; DVs inválidos rejeitados; arrecadação detectada |
| 15 | `GenerateCnabFileJobTest` | Sucesso: `generated`, arquivo em `Storage::fake` com nome e checksum, `cnab_file_items` com lote/sequência/forma, NSA incrementado **uma vez**, `CnabFileGenerated`, notificação ao solicitante |
| 16 | `GenerateCnabFileJobTest` | Dry-run reprovado: `failed`, itens com `validation_errors`, **sem** arquivo, NSA **não** consumido, sem retry (1 tentativa), `CnabFileGenerationFailed` |
| 17 | `GenerateCnabFileJobTest` | Falha de storage: exceção → `failed()` marca `failed` com mensagem genérica; Retry cria novo `CnabFile(queued)` |
| 18 | `GenerateCnabFileJobTest` | Idempotência: segundo `request` com arquivo ativo → `generationAlreadyActive`; job em arquivo `generated` → no-op; retry com `file_sequence` já atribuído reusa o mesmo NSA |
| 19 | `CnabFileDownloadTest` | Operador/Adm baixam (stream, `Content-Disposition` com `filename`); Cliente 403; `superseded`/`failed` não baixáveis; arquivo ausente → erro amigável; checksum divergente → erro; primeiro download grava `downloaded_at/by`; `CnabFileDownloaded` a cada download |
| 20 | `SettlementSchemaConstraintsTest` | Unique parciais: item ativo por PR (liberado libera); config viva por conta (inativa **não** libera, soft delete libera); arquivo ativo por baixa; `(config, file_sequence)` inclusive contra arquivo soft-deleted; uniques `(cnab_file_id, payment_settlement_item_id)` e `(cnab_file_id, reference)`; literais do índice == `CnabFileStatus::activeValues()`; nova config da mesma conta herda o NSA; forceDelete da baixa na ordem de §10.3 remove arquivo físico de arquivo soft-deleted |
| 21 | `PaymentSettlementResourceTest` | Lista e View renderizam por papel; página de seleção lista só elegíveis; bulk action cria a baixa e redireciona; `TransitionStatusAction` não oferece mais `Settled` |
| 22 | `CnabConfigResourceTest` | Create/Edit por Adm; Operador sem botões de escrita; select de contas filtrado pela filial |
| 23 | `GenerateCnabFileJobTest` | Job na conexão/fila de `rjet.cnab.queue.*`; middleware com `releaseAfter(30)`/`expireAfter(180)`; lock ocupado → job liberado (não descartado) e depois gera |
| 24 | `GenerateCnabFileJobTest` | Reentrada em `generating` atualiza `updated_at` e reusa `file_sequence`; arquivo virou `failed` antes do passo 7 → não grava `generated`, apaga o arquivo, sem `CnabFileGenerated` |
| 25 | `CnabRecoverStuckFilesCommandTest` | Parado há > 300 s e < 20 min → reenfileira o mesmo `CnabFile`, `updated_at` tocado, NSA inalterado, nenhum arquivo novo; > 20 min → `failed` com `generation_interrupted` + `CnabFileGenerationFailed`; recente, `generated`/`failed`/`superseded`/trashed → ignorados; `--dry-run` não altera nada |

Sem teste de sincronia de literais para o reconciliador: não há índice novo (a `dba` recusou o índice parcial em 2026-09-27, §3.11).

---

## 17. Trade-offs

| Decisão | Custo | Benefício |
|---------|-------|-----------|
| Baixa em dois passos (`draft` → `settled`) | Um clique a mais | Respeita `Launched`/`Settled` do DRF/F4; CNAB antes da liquidação; baixa manual possível |
| CNAB opcional | Operador pode esquecer de gerar | Não bloqueia bancos sem adapter; a UI avisa na confirmação |
| Config 1:1 com `BranchBankAccount` (não filial + banco) | Diverge da letra do DRF | Sem ambiguidade com duas contas no mesmo banco; 3NF; header CNAB precisa da conta |
| Colunas tipadas na config (sem json de opções) | Novo banco pode exigir migration | Validação forte, Filament simples, alinhado a `database.md` §4 |
| Sem tabela de histórico da `CnabConfig` | Não mostra "quem mudou o convênio de X para Y" | `config_snapshot` por arquivo registra o que importa (parâmetros efetivamente usados) + blameable. Tabela dedicada pode vir depois sem quebrar schema |
| Gerador próprio em vez de lib | Esforço de implementação e manutenção do layout | Escopo pequeno, controle total para homologação, golden files, sem nova dependência |
| Itens inválidos reprovam o arquivo inteiro | Operador precisa remover/corrigir antes | Sem remessa parcial silenciosa (R08) |
| NSA consumido em falha de infra | Lacunas na numeração | Nunca reusa NSA (unique); rastreável; a confirmar com o Itaú (P-F7-NSA) |
| `released_at` em vez de SoftDeletes no item | Diverge do padrão F6 (SoftDeletes no item) | Semântica correta (cancelar ≠ excluir) e unique parcial limpo |
| Bloqueio de edição de PR para não-Adm em baixa `draft` | Operador precisa remover o item ou cancelar para corrigir | Dados do CNAB congelados; menos risco de pagar valor diferente |
| Sem `temporaryUrl` no download | Arquivo passa pela app | Sem link compartilhável de arquivo de pagamento; auditoria de cada download |
| ~~Fila `default`~~ (substituída em 2026-09-27) | ~~Sem prioridade para pagamentos~~ | ~~Não exige worker novo; volume baixo~~ |
| Conexão CNAB dedicada + reconciliador (2026-09-27) | Mais um worker e o agendador obrigatórios em produção; até ~5 min até o resgate e 20 min até o Retry aparecer | Recuperação sem mexer no `retry_after` global nem limitar a remessa; mesmo NSA e mesmo arquivo |
| Sem índice novo em `payment_requests` | Possível scan maior no futuro | Evita over-indexing; confirmado pela `dba` (§21), com gatilho de reavaliação em §4.7 |

---

## 18. Próximos passos

1. ~~**Revisão `dba`**~~ ✅ **Concluída em 2026-09-26: aprovado com ressalvas** (§21). Correções incorporadas em §3, §4, §5, §6, §7, §9.2, §10, §15, §16 e §20. Sem índice novo em `payment_requests`.
2. ~~**`/blueprint`**~~ ✅ **Concluído em 2026-09-26**: `.ai/blueprints/fase-7-baixa-cnab.md` (`CnabConfigResource`, `PaymentSettlementResource`, página `CreatePaymentSettlement`, Actions de §11, correções DBA §21). **Próximo passo: `implementer`.**
3. **`implementer`**, nesta ordem: enums + traduções → migrations → models/factories → exceções → `BoletoBarcode` + `FixedWidthFormatter` + `CnabRemittanceValidator` → `Itau240RemittanceAdapter` (com golden files) → Services → Actions → Events/Listeners/Notifications → Job → Policies → hooks em `PaymentRequest`/`PaymentRequestService`/`TransitionStatusAction` → Filament → testes §16 → Pint.
4. **Homologação (R09)**: gerar arquivos de exemplo (boleto, TED, PIX) e validar no ambiente/validador do Itaú antes de produção.
5. **API REST: não** nesta fase (§2.3).

---

## 19. Pendências (dependem da RJET/Itaú; não bloqueiam o schema)

| ID | Tema | Nota |
|----|------|------|
| P-F7-SPEC | Versão do manual SISPAG CNAB 240 Itaú a seguir (posições, forma de pagamento, finalidade TED, J-52) | Insumo DRF §2.5. Fica em `Itau240Layout`; os códigos de forma de §3.6 são referência a confirmar |
| P-F7-HOMOLOG | Acesso a homologação/validador Itaú (R09) | Sem isso, os golden files validam só a estrutura, não a aceitação bancária |
| P-F7-FILENAME | Convenção de nome do arquivo "acordada" (RF034) | Default `{bank}_{cnpj}_{data}_{NSA}.rem`; ajustável só no adapter |
| P-F7-NSA | Itaú exige NSA contíguo? | Se exigir, reavaliar consumo de NSA em falha de infra |
| P-F7-CLIENT-VIEW | Cliente deve ver baixas das suas filiais (somente leitura)? | MVP: não (A5); o model já suporta escopo por `branch_id` |
| P-F7-SOD | Confirmação da baixa exige aprovador (`can_approve`)? | MVP: não; hook via ability `confirm` |
| P-F7-TRIBUTOS | Concessionárias/tributos (segmento O/N) e PIX QR (J-52 PIX) | Fora do MVP; inelegíveis para CNAB, baixa manual permitida |

---

## 20. Checklist de implementação (pós-aprovação)

- [ ] Enums §6 + `lang/{pt_BR,en}/enums.php`
- [ ] Traduções `payment_settlements.php`, `cnab_configs.php`, `cnab_files.php` (incl. `validation.*`, `errors.*`), `navigation.php`, `notifications.php`
- [ ] Config `rjet.cnab.*`
- [ ] Migrations §4 na ordem `payment_settlements → payment_settlement_items → cnab_configs → cnab_files → cnab_file_items` (unique parciais com `supportsPartialIndexes()` + `DROP INDEX IF EXISTS` no `down()`; literais de status no predicado, sem importar enum; comentários de desnormalização em `branch_id`, `items_count`, `total_amount`, `amount`, `layout`, `config_snapshot`; FKs com `->index()`; uniques Blueprint só em `cnab_file_items`; sem índice simples em `settlement_date` nem em `cnab_configs.layout`; sem `after()`; sem `check()`; sem `$table->enum()`) — DBA §21
- [ ] NSA por conta: seed de `last_file_sequence` + guard `fileSequenceBelowIssued` / `fileSequenceExhausted` (DBA §21 #2, #8)
- [ ] ForceDelete da baixa com `withTrashed()->get()->each->forceDelete()` nos arquivos (DBA §21 #1)
- [ ] Models + factories + relações/scopes/hook em `PaymentRequest`
- [ ] `PaymentSettlementException`, `CnabException`
- [ ] `App\Support\BoletoBarcode`; `App\Integrations\Cnab\*` + bind do resolver
- [ ] Services §9.2; Actions §9.3; Job §9.4
- [ ] Events/Listeners/Notifications §8; `PaymentRequestStatusChanged` com `ShouldDispatchAfterCommit`
- [ ] Policies §11.3 registradas; guard de exclusão de `BranchBankAccount` com config viva; guard de `branch_id` imutável em conta referenciada por baixa/config (DBA §21 #3)
- [ ] Hook `transitionStatus(..., ?PaymentSettlement)` + `TransitionStatusAction` sem `Settled` + teste existente atualizado
- [ ] Filament §11 (Resources, página de seleção, Actions, RMs)
- [ ] Observers `PaymentSettlementObserver` (cascade soft) e `CnabFileObserver` (arquivo físico no forceDelete)
- [ ] Testes §16
- [ ] `vendor/bin/pint --dirty --format agent`

---

## 21. Revisão DBA

> **Revisor:** dba (subagent) · **Data:** 2026-09-26 · **Driver:** PostgreSQL (testes em sqlite)  
> **Escopo:** schema Fase 7 (§3 / §4 / §5 / §6 / §10 / §15): `payment_settlements`, `payment_settlement_items`, `cnab_configs`, `cnab_files`, `cnab_file_items`; FKs e ordem de delete; SoftDeletes; unique parciais (`released_at`, status, NSA); índices; necessidade de índice em `payment_requests`.  
> Guidelines: `database.md`, `enums.md`, `soft-deletes.md`, `performance.md`, `factories-seeders.md`, `PROJECT.md`. Padrão de saída alinhado a F5/F6 §24.  
> Inspeção via migrations/Models reais: `approvals` (índice parcial por status), `banks` / `branch_bank_accounts` / `attachment_batch_items` (helper `supportsPartialIndexes()` + `DROP INDEX IF EXISTS`), `import_batches` / `import_batch_errors` (`mappings_snapshot`, append-only `created_at`), `payment_requests` (`net_amount decimal(10,2)`, `due_date date`, `branch_id` cascade), `branches` (`legal_name` 200, `document` 20), `HasUuid` (`Str::orderedUuid()`), `ImportBatchError` (`$timestamps = false` + `UPDATED_AT = null`).

### Veredito

**Aprovado com ressalvas.** Modelagem em 3NF com desnormalizações documentadas (contadores, snapshots de valor/layout/config, `branch_id` na baixa), entidades dedicadas corretas (sem morph), status como `string` + cast PHP, `decimal(10,2)` em todo valor, SoftDeletes coerentes (baixa/config/arquivo de domínio; item de baixa com ciclo via `released_at`; item de arquivo append-only), FKs explícitas com `->index()`. As decisões de produto (dois passos, config 1:1 com conta, filial única, CNAB opcional) não violam integridade. Dois achados críticos (ordem real do forceDelete e reuso de NSA ao substituir config) foram corrigidos no próprio documento; nenhum exige redesenho.

### O que está correto

| Item | Avaliação |
|------|-----------|
| 5 tabelas dedicadas; sem morph; sem key-value genérico na config | ✅ |
| Config 1:1 com `branch_bank_account_id` (sem `branch_id`/`bank_id` transitivos na config) | ✅ |
| Status/layout/tipo como `string`; zero `$table->enum()`; zero float | ✅ |
| Tamanhos medidos: `cancelled` 9 e `generating`/`superseded` 10 em string(20); `itau_240` 8 em string(30); `pix_key`/`transfer` 8 em string(20) | ✅ |
| `settlement_date` `date` + `settled_at` `timestampTz` (precedente `due_date`) | ✅ (A11) |
| Unique parcial `(payment_request_id) WHERE released_at IS NULL` (sem SoftDeletes no item) | ✅ |
| Predicado `status IN (...) AND deleted_at IS NULL`: válido em pgsql e sqlite, mesmo padrão de `approvals_payment_request_pending_unique` | ✅ (literais hardcoded, #4) |
| `CnabFileItem` append-only: só `created_at useCurrent`, sem blameable, `UPDATED_AT = null` (padrão `ImportBatchError`) | ✅ |
| `config_snapshot` / `validation_errors` como `json` (precedente `import_batches.mappings_snapshot`, `import_batch_errors.raw_values`) | ✅ |
| `failure_reason` text; `disk` 50; `path` 500; `checksum` 64; `*_reason` 500; `filename` 100 (compatíveis com import/attachment/approvals) | ✅ |
| `company_name` string(30) como limite do layout CNAB | ✅ (documentado) |
| `is_valid` sem índice (baixa cardinalidade, sempre lido por `cnab_file_id`) | ✅ |
| Blameable `nullOnDelete`; `timestampsTz`/`softDeletesTz` onde há SoftDeletes | ✅ |
| Sem `after()`, sem `check()` multi-coluna (zero ocorrências no repo) | ✅ |
| Volume ~70/dia: sem cache, sem índice novo em `payment_requests` | ✅ |

### Achados (Crítico / Importante / Sugestão)

| # | Nível | Achado | Impacto | Correção aplicada |
|---|-------|--------|---------|-------------------|
| 1 | **Crítico** | ForceDelete da baixa: `$settlement->cnabFiles()` esconde arquivos soft-deletados (o observer os soft-deleta junto com a baixa), e `forceDelete()` em query builder não dispara `CnabFileObserver`. | Restrict de `cnab_files` bloqueia o delete, ou o `.rem` (arquivo de pagamento) fica órfão no disco. | §5 matriz FK + "sem ciclo"; §10.2/§10.3: ordem fechada `withTrashed()->get()->each->forceDelete()` → baixa; físico após o commit; §16 #20; §20. |
| 2 | **Crítico** | "Desativar e criar nova config" conflita com o unique `WHERE deleted_at IS NULL` (inativa ocupa a vaga). E a nova config recomeçaria o NSA em 0 para a **mesma conta**, e o unique `(cnab_config_id, file_sequence)` não cobre configs diferentes. | Impossível substituir config via desativação; ou NSA repetido no banco (remessa rejeitada ou confundida com anterior). | §3.1/§4.3/§9.2: substituição = soft delete + create; seed de `last_file_sequence` = maior NSA da conta (`withTrashed`); `fileSequenceBelowIssued` (§7.2); `CnabFile::config()` withTrashed. |
| 3 | Importante | `payment_settlements.branch_id` é dependência transitiva de `branch_bank_account_id`; `cnab_files.layout` duplica a config/snapshot. Nenhum dos dois estava listado como desnormalização. | 3NF sem rastro; risco de filial ≠ filial da conta se `branch_bank_accounts.branch_id` mudar. | §3.1 itens 6 e 7 (comentário obrigatório); guard de `branch_id` imutável em conta referenciada (§20); Service `bankAccountNotAllowed`. Sem FK composta (atípica no repo). |
| 4 | Importante | §6.2 dizia que `activeValues()` é "usado no índice parcial". | Migration que importa enum deixa de ser imutável (enum muda → rollback/replay diverge). | §3.4/§4.4/§6.2: literais na migration + teste de sincronia; novo status ativo = migration que recria o índice. |
| 5 | Importante | `cnab_file_items` sem unicidade no banco; `reference` = **primeiros** 20 hex de `Str::orderedUuid()` (12 hex de timestamp → ~28 bits aleatórios). | "Seu número" duplicado na remessa quebra o retorno futuro; item duplicado no mesmo arquivo passa silencioso. | §4.5/§4.7: `unique(cnab_file_id, payment_settlement_item_id)` + `unique(cnab_file_id, reference)` (Blueprint ok: sem soft/predicado); `reference` = **últimos** 20 hex (~74 bits); factory ajustada. |
| 6 | Importante | Concorrência dependia de checagem prévia no Service. | Corrida entre dois operadores/jobs. | §10.1: unique parciais como trava final; `UniqueConstraintViolationException` → `paymentRequestAlreadyInSettlement` / `generationAlreadyActive`. |
| 7 | Sugestão | Índice simples em `settlement_date` (coberto pelo composto `(branch_id, settlement_date)`) e em `cnab_configs.layout` (uma linha por conta, nunca filtrado sozinho). | Over-indexing (`performance.md`: tabelas < 1000). | §4.1/§4.3/§4.7: removidos; `is_active` mantido por consistência com `banks`/`branch_bank_accounts`. |
| 8 | Sugestão | `unsignedInteger` vira `integer` no Postgres (unsigned não é imposto); NSA tem 6 dígitos. | Valor negativo ou NSA > 999999 escrito errado no header. | §3.4/§4.3/§4.4: validação `0..999999`; `CnabException::fileSequenceExhausted()` (§7.2). |
| 9 | Sugestão | Composto `(branch_id, status, due_date)` em `payment_requests` em aberto. | — | §4.7/§10.1/§17: **não criar**; gatilho de reavaliação (> ~200 mil linhas ou `EXPLAIN ANALYZE` > ~50 ms). |
| 10 | Sugestão | `settlement_date` vs convenção `_at`. | Renome desnecessário. | A11: mantém `date` (calendário, como `due_date`); `_at` só para `timestampTz`. |
| 11 | Sugestão | Factories não garantiam os uniques (arquivo ativo único por baixa, config nova por conta, NSA crescente, Bank 341 com unique parcial). | Testes flakey / violação de constraint no setup. | §15: states e `recycle` ajustados; `trashed()` em config; `Bank::firstOrCreate` em `itau()`. |

### Checklist DBA (resumo)

| Área | Resultado |
|------|-----------|
| Normalização 3NF | ✅ (7 desnormalizações documentadas em §3.1) |
| Morph vs dedicado | ✅ tudo dedicado; morph map inalterado |
| Campos (`is_`, `_at`, `_by`, `_date` de calendário, `_id`) | ✅ |
| Enums (`string`, sem `$table->enum()`, tamanhos medidos) | ✅ |
| UUID + `timestampsTz` + `softDeletesTz` | ✅ (`cnab_file_items` só `created_at`) |
| Monetários `decimal(10,2)` + guard de overflow | ✅ |
| Unique parciais com `supportsPartialIndexes()` + `DROP INDEX IF EXISTS` | ✅ (3 parciais + 2 Blueprint em `cnab_file_items`) |
| FK `->index()` explícito | ✅ |
| `onDelete` e ordem de forceDelete | ✅ fechada (§5, §10.3) |
| Soft delete vs FK | ✅ observer para soft; FK só no force |
| Índices só com WHERE/ORDER BY real | ✅ (sem `settlement_date` simples, sem `layout`) |
| `payment_requests` | ✅ sem índice novo |
| CHECK multi-coluna / `after()` | ✅ não usar |
| Factories cobrindo uniques | ✅ (§15) |

### Decisões DBA confirmadas (2026-09-26)

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | `settlement_date` `date` ou `settled_at`-like? | `date` (A11); `settled_at` é o instante da confirmação |
| 2 | Status string(20) cabe? | Sim: maior valor 10 (`generating`/`superseded`) |
| 3 | `is_valid` indexar? | **Não** |
| 4 | `is_active` em `cnab_configs` indexar? | Sim, só por consistência do repo |
| 5 | Ordem de forceDelete | `cnab_file_items → cnab_files (withTrashed, each) → payment_settlement_items → payment_settlements`; sem ciclo de restrict |
| 6 | Predicado `status IN (...)` estável? | Sim, com literais na migration + teste de sincronia |
| 7 | NSA unique considera `deleted_at`? | **Não**, de propósito (NSA nunca reutiliza) |
| 8 | NSA entre configs da mesma conta | Service semeia/valida pelo maior NSA da conta; sem coluna nova |
| 9 | Unique `(cnab_file_id, reference)`? | **Sim**, + `(cnab_file_id, payment_settlement_item_id)` |
| 10 | `last_file_sequence` unsignedInteger cabe 999999? | Sim (`integer` no PG); faixa validada no Service |
| 11 | Índice composto em `payment_requests`? | **Não** no MVP |
| 12 | `CnabFileItem` sem blameable? | Sim (escrito pelo job) |
| 13 | `json` vs `jsonb`? | `json` (precedente; sem consulta por chave) |
| 14 | FK composta para garantir `branch_id` da baixa = filial da conta? | **Não**; Service + guard de imutabilidade |

### Ajustes já aplicados no doc

- Header Status → revisão DBA concluída; pronto para `/blueprint`
- §3.1: substituição de config, NSA por conta, desnormalizações 6 e 7
- §3.4: literais do predicado; NSA sem `deleted_at`; limite 999999
- §3.10: A11 (`settlement_date`)
- §4.1–§4.7: índices, tamanhos, comentários, uniques de `cnab_file_items`, `reference` pelos últimos 20 hex, withTrashed nas leituras
- §5: matriz de FKs + análise de ciclo
- §6: tamanhos medidos; `activeValues()` fora da migration
- §7.2: `fileSequenceExhausted()`, `fileSequenceBelowIssued()`
- §9.2: `CnabConfigService` (seed/guard de NSA; deactivate ≠ substituir)
- §10: observer por model; ordem de forceDelete; restore; concorrência
- §15 / §16 #20 / §17 / §18 item 1 / §20 alinhados

### Notas para implementer

1. Copiar `supportsPartialIndexes()` de `create_approvals_table` / `create_banks_table`. Índices parciais via `DB::statement`; `down()` com `DROP INDEX IF EXISTS` **antes** do `dropIfExists`.
2. Nomes dos índices parciais: `payment_settlement_items_active_request_unique`, `cnab_configs_account_unique`, `cnab_files_settlement_active_unique`, `cnab_files_config_sequence_unique`.
3. Predicado de arquivo ativo com literais `'queued','generating','generated'` — **não** interpolar `CnabFileStatus::activeValues()` na migration.
4. `cnab_file_items`: `$table->unique(['cnab_file_id', 'payment_settlement_item_id'])` e `$table->unique(['cnab_file_id', 'reference'])` no Blueprint; `$table->timestampTz('created_at')->useCurrent()`.
5. Comentário em inglês (uma linha, estilo `payment_requests.net_amount`) em: `payment_settlements.branch_id`, `items_count`, `total_amount`; `payment_settlement_items.amount`; `cnab_files.layout`, `items_count`, `total_amount`, `records_count`, `config_snapshot`; `cnab_file_items.amount`, `reference`. Comentário de cascade em FKs cascade (padrão `attachment_batch_items`: "applies to hard/force delete only").
6. Sem `->index()` em `settlement_date` nem em `cnab_configs.layout`; `status` com `->index()`; composto `$table->index(['branch_id', 'settlement_date'])`.
7. ForceDelete da baixa: transação + `cnabFiles()->withTrashed()->get()->each->forceDelete()` + `forceDelete()` da baixa; apagar o físico após o commit.
8. `CnabConfigService::create`: `last_file_sequence = max(file_sequence)` dos `cnab_files` cujas configs (withTrashed) têm o mesmo `branch_bank_account_id`; `update` rejeita valor menor (`fileSequenceBelowIssued`).
9. Atribuição de NSA: `lockForUpdate` na config; `> 999999` → `fileSequenceExhausted`.
10. `reference` = `strtoupper(substr(str_replace('-', '', $paymentRequest->id), -20))`.
11. Capturar `UniqueConstraintViolationException` nos creates de item de baixa e de `CnabFile` e mapear para as exceções de domínio.
12. Testes Pest (§16 #20): cada unique parcial/Blueprint, inativa não libera vaga, herança de NSA, sincronia literais × enum, forceDelete de baixa cancelada com arquivo soft-deleted remove o físico.
