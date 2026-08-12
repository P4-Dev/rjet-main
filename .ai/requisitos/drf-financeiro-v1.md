# Documento de Requisitos Funcionais

## Módulo Financeiro — RJET BPO de Pagamentos

**Versão:** 1.2  
**Data:** 2026-08-12  
**Autor:** tech-writer (subagent)  
**Status:** Rascunho  
**Fonte de análise:** [.cursor/plans/levantamento_requisitos_rjet_financeiro_ea1e4370.plan.md](../../.cursor/plans/levantamento_requisitos_rjet_financeiro_ea1e4370.plan.md)

---

## 1. Introdução

### 1.1 Propósito

Este documento formaliza os requisitos funcionais e não funcionais do **módulo Financeiro** da plataforma RJET, consolidando o levantamento produzido pelo `business-analyst` e as **decisões de negócio** validadas pelo cliente RJET. Objetiva servir como referência para arquitetura, modelagem de dados, implementação (Laravel 12 + Filament 5), testes e aceite com stakeholders.

### 1.2 Escopo


| Incluído (MVP)                                                                                    | Excluído (fora desta versão)                     |
| ------------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| Gestão de inputs financeiros: solicitações, anexos, workflow por alçadas                          | Módulo de RH (projeto separado)                  |
| Hierarquia **Empresa/Cliente → Filial → Conta bancária**; usuários com **múltiplas filiais**      | Integração bancária via API / Open Banking       |
| Geração de arquivos **CNAB 240** para **upload manual** no internet banking (início com **Itaú**) | OCR em provedor cloud                            |
| **OCR local** para leitura de **código de barras / linha digitável de boleto**                    | Importação de **XML NF-e** no fluxo de pagamento |
| **Importação em lote** de solicitações via planilha com **template de mapeamento de colunas**     |                                                  |
| **Lote de anexos** com classificação operacional e **nomenclatura padronizada** (data/hora)       |                                                  |
| Armazenamento de anexos em **S3** (produção)                                                      |                                                  |
| Anexos apenas **PDF** e **imagens** (NF/recibo/comprovante/boleto)                                |                                                  |




### 1.3 Definições e Acrônimos


| Termo                           | Definição                                                                                                                        |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| DRF                             | Documento de Requisitos Funcionais                                                                                               |
| BPO                             | Business Process Outsourcing — terceirização de processos; aqui, operação de pagamentos                                          |
| CNAB                            | Padrão brasileiro de arquivos de troca com instituições financeiras (remessa/retorno)                                            |
| OCR                             | Reconhecimento óptico de caracteres / leitura automatizada de dados em documento ou imagem                                       |
| Alçada                          | Limite de valor ou regra que define quem aprova uma solicitação                                                                  |
| BACEN                           | Banco Central do Brasil — referência à lista de instituições financeiras                                                         |
| PF / PJ                         | Pessoa Física / Pessoa Jurídica                                                                                                  |
| PIX                             | Método de pagamento instantâneo do Banco Central                                                                                 |
| **Empresa / Cliente (Company)** | Entidade de nível superior que agrupa filiais (ex.: Altitude, Glow); escopo de fornecedor global, SLA e visibilidade operacional |
| **Filial (Branch)**             | Unidade operacional vinculada a uma Empresa, com CNPJ e contas bancárias próprias                                                |
| **Template de Importação**      | Cadastro que mapeia colunas de planilha para campos de solicitação de pagamento                                                  |
| **Nomenclatura Padronizada**    | String alfanumérica gerada pelo sistema com base em data/hora para arquivos do lote de anexos                                    |
| **Permissão Aprovador**         | Flag/capacidade atribuída a usuários Operador ou Adm — não constitui perfil separado                                             |


---



## 2. Descrição Geral



### 2.1 Perspectiva do Produto

A solução é um sistema **centralizado** para captura estruturada de **solicitações de pagamento** (individual ou em lote), tratamento de comprovantes (incluindo leitura automatizada de boleto e padronização de nomenclatura em lote de anexos), **governança** via aprovações por valor com **SLA configurável por empresa** e geração de **remessas CNAB 240** para processamento manual no banco. Prioriza **rastreabilidade**, **redução de retrabalho** operacional e consistência de dados entre **empresas e filiais**.

### 2.2 Funções do Produto (roadmap em 8 fases)

1. **Fase 1 — Base:** autenticação, perfis, usuários (multi-filial), empresas, filiais e contas bancárias; políticas de acesso e visibilidade.
2. **Fase 2 — Cadastros gerenciais:** centros de custo, apropriações, fornecedores globais com override de forma de pagamento por empresa, bancos (cadastro manual).
3. **Fase 3 — Solicitação + OCR:** núcleo de solicitação de pagamento, anexos (S3, PDF/imagens), formulário condicional, OCR de boleto.
4. **Fase 4 — Workflow:** regras de alçada, aprovações (permissão em Operador/Adm), notificações, SLA por empresa com escalação, devolução ao solicitante em rejeição.
5. **Fase 5 — Lote de solicitações:** templates de importação (mapeamento de colunas) e importação em lote via planilha.
6. **Fase 6 — Lote de anexos:** upload múltiplo, classificação ordenada por operadores e nomenclatura padronizada por data/hora.
7. **Fase 7 — Baixa e CNAB:** baixa em lote, configuração CNAB 240 (Itaú inicial) por filial/banco, geração assíncrona e download.
8. **Fase 8 — Dashboard e relatórios:** indicadores no Filament e exportação analítica (Excel com links a anexos).



### 2.3 Usuários e Características


| Perfil       | Descrição resumida                          | Necessidades principais                                                                                                                               | Escopo de visibilidade                               |
| ------------ | ------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| **Cliente**  | Usuário que consome o fluxo de solicitações | Visualizar e **criar** solicitações de pagamento                                                                                                      | Solicitações das **filiais às quais está vinculado** |
| **Operador** | Usuário operacional                         | Tudo do Cliente + **alterar status e dados** de solicitações; classificar lote de anexos; importar planilhas                                          | **Todas as empresas** e filiais                      |
| **Adm**      | Administrador do módulo                     | Tudo do Operador + **gerenciar usuários**, **excluir** registros, cadastrar **classificações gerenciais**, configurar **workflow / CNAB / templates** | **Todas as empresas** e filiais                      |


**Permissão Aprovador:** não é um perfil separado. Usuários **Operador** ou **Adm** podem receber a **permissão de aprovador** para atuar em alçadas conforme **ApprovalRule**.

**Multi-filial:** um usuário pode pertencer a **múltiplas filiais** (ex.: time de compras centralizado que atende várias filiais).

### 2.4 Restrições

- **CNAB apenas manual:** arquivo gerado para upload pelo usuário; sem conectores bancários em tempo real no MVP.
- **CNAB layout 240:** padrão universal adotado; **banco inicial: Itaú**; demais bancos adaptados incrementalmente via Strategy pattern.
- **OCR local:** sem serviços de OCR em nuvem; biblioteca PHP no servidor/aplicação para leitura de boleto.
- **Anexos:** apenas **PDF** e **imagens** (foto de celular, print); **sem XML NF-e** neste fluxo.
- **Storage em produção:** **S3** (ou compatível); visibilidade **private** por padrão.
- **2FA:** **não obrigatório** por ora.
- **LGPD / mascaramento:** dados de fornecedores **não exigem mascaramento** neste contexto operacional (decisão RJET).
- **Escopo MVP:** somente domínio **Financeiro**; demais módulos (ex.: RH) ficam fora.
- **Volume operacional inicial:** ~**70 pagamentos/dia** — dimensionamento de performance deve considerar esse patamar.



### 2.5 Dependências

- **Biblioteca PHP** para decodificação de **código de barras / linha digitável** de boleto.
- **Biblioteca de leitura de planilha** (Excel/CSV) para importação em lote de solicitações.
- **Cadastro manual de bancos** (referência BACEN), aplicado a todos os clientes/empresas.
- **Armazenamento S3** (ou MinIO compatível) configurado via ambiente — [.ai/docs/file-storage.md](../docs/file-storage.md).
- **Especificação CNAB 240 Itaú** para remessa de boleto, transferência e PIX.
- **Ambiente de homologação bancária** desejável para validação de arquivos CNAB (risco R09).

---



## 3. Requisitos Funcionais

Os requisitos estão numerados **RF001..RF037** e agrupados por fase. Cada item inclui regras de negócio (**RN**) locais quando aplicável.

---



### Fase 1 — Base (autenticação, perfis, empresa, filial)



#### RF001 — Autenticação e sessão

**Prioridade:** Alta  

**Descrição:** O sistema deve permitir que usuários autentiquem-se de forma segura e acessem apenas funcionalidades permitidas pelo seu perfil e permissões.

**Regras de negócio:**

- RN001.1: Sessão e credenciais seguem o mecanismo padrão da aplicação (Laravel/Filament).
- RN001.2: **2FA não é obrigatório** por ora; pode ser habilitado futuramente sem impacto nos critérios desta versão.

**Critérios de aceite:**

- [ ] Usuário válido acessa o painel após autenticação bem-sucedida.
- [ ] Usuário inválido não obtém acesso às áreas restritas.
- [ ] Sessão expira ou é invalidada conforme política global da aplicação.

---



#### RF002 — Gestão de usuários (CRUD) com perfil e múltiplas filiais

**Prioridade:** Alta  

**Descrição:** Perfis **Adm** devem poder criar, ler, atualizar e desativar/excluir usuários associando **UserRole**, **permissão de aprovador** (quando aplicável) e vínculo **N:N com filiais**.

**Regras de negócio:**

- RN002.1: Cada usuário possui enum **UserRole**: Cliente, Operador, Adm.
- RN002.2: Um usuário pode pertencer a **múltiplas filiais** (relacionamento N:N).
- RN002.3: **Permissão de aprovador** é atributo separado do perfil (boolean ou equivalente), aplicável a Operador e Adm.

**Critérios de aceite:**

- [ ] Adm cadastra usuário com perfil, filiais e permissões obrigatórias.
- [ ] Adm altera perfil, filiais associadas e permissão de aprovador.
- [ ] Listagem e detalhe refletem `UserRole`, filiais vinculadas e flags de permissão.

---



#### RF003 — Cadastro de empresas/clientes (Company)

**Prioridade:** Alta  

**Descrição:** CRUD de **empresas/clientes** como entidade superior que agrupa filiais (ex.: Altitude, Glow).

**Regras de negócio:**

- RN003.1: Toda **filial** pertence a exatamente **uma empresa**.
- RN003.2: Configurações de **SLA de aprovação** e **override de forma de pagamento de fornecedor** são escopadas por empresa.

**Critérios de aceite:**

- [ ] Adm cria, edita e consulta empresas.
- [ ] Filiais só podem ser cadastradas vinculadas a uma empresa existente.
- [ ] Exclusão lógica respeita SoftDeletes quando aplicável.

---



#### RF004 — Cadastro de filiais (Branch)

**Prioridade:** Alta  

**Descrição:** CRUD de **filiais** vinculadas a uma **empresa**, com identificação corporativa (**CNPJ**, razão social e demais campos acordados).

**Regras de negócio:**

- RN004.1: Filial é entidade central para segregação de regras de alçada e CNAB nas fases posteriores.
- RN004.2: Filial herda contexto de empresa para visibilidade e configurações globais.

**Critérios de aceite:**

- [ ] Adm (ou perfil autorizado) cria, edita e consulta filiais no contexto de uma empresa.
- [ ] Validações de CNPJ e campos obrigatórios impedem persistência inválida.
- [ ] Exclusão lógica respeita SoftDeletes quando aplicável.

---



#### RF005 — Contas bancárias da filial (BranchBankAccount)

**Prioridade:** Alta  

**Descrição:** Uma filial pode possuir **uma ou mais** contas bancárias vinculadas; o sistema deve permitir CRUD dessas contas como subcadastro da filial.

**Regras de negócio:**

- RN005.1: Dados bancários sensíveis protegidos por controle de acesso; sem exigência de mascaramento LGPD para fornecedores (decisão RJET).

**Critérios de aceite:**

- [ ] Contas são listadas, criadas, editadas e removidas (ou desativadas) no contexto da filial.
- [ ] Associação com registro de **Bank** é possível quando o cadastro de bancos existir (Fase 2).

---



#### RF006 — Autorização por perfil, visibilidade e permissão de aprovador (policies)

**Prioridade:** Alta  

**Descrição:** Operações sensíveis devem ser bloqueadas para perfis/permissões sem autorização. Escopo de dados deve respeitar decisões de visibilidade por perfil.

**Regras de negócio:**

- RN006.1: **Cliente:** criar/visualizar solicitações das **filiais às quais está vinculado**; não gerir usuários nem configurações globais.
- RN006.2: **Operador / Adm:** visualizam solicitações de **todas as empresas**; Operador altera dados/status; Adm gerencia cadastros e configurações.
- RN006.3: **Aprovar solicitações** exige **permissão de aprovador** além do perfil Operador ou Adm.
- RN006.4: Apenas Adm gerencia usuários, exclusões autorizadas, templates de importação, CNAB e cadastros gerenciais.

**Critérios de aceite:**

- [ ] Cliente não visualiza solicitações de filiais às quais não está vinculado.
- [ ] Operador/Adm acessam solicitações cross-empresa conforme política.
- [ ] Usuário sem permissão de aprovador não consegue aprovar/rejeitar alçadas.
- [ ] Tentativas não autorizadas retornam negação (HTTP 403 ou equivalente Filament).

---



#### RF007 — Modelo de usuário customizado (multi-filial)

**Prioridade:** Alta  

**Descrição:** O modelo **User** deve suportar papéis, relacionamento **N:N com filiais**, permissão de aprovador e campos de auditoria em linha com padrões globais.

**Critérios de aceite:**

- [ ] Migrações e model refletem `UserRole`, `branches()` (N:N) e flag de aprovador.
- [ ] Factories/seeders básicos permitem ambientes de teste com usuário multi-filial.

---



### Fase 2 — Cadastros gerenciais



#### RF008 — Centro de custo (CostCenter)

**Prioridade:** Média  

**Descrição:** CRUD de **centros de custo** para classificação gerencial das solicitações e relatórios (escopo por filial conforme modelagem).

**Critérios de aceite:**

- [ ] Adm gerencia registros com validações mínimas (código/nome únicos se aplicável).
- [ ] Registros podem ser associados a solicitações na Fase 3.

---



#### RF009 — Apropriação (Appropriation)

**Prioridade:** Média  

**Descrição:** CRUD de **apropriações** (natureza/rubrica gerencial conforme definição de negócio na implementação).

**Critérios de aceite:**

- [ ] Adm mantém apropriações utilizáveis em solicitações e relatórios.
- [ ] Integridade referencial com demais entidades respeitada.

---



#### RF010 — Fornecedor global com override de forma de pagamento por empresa

**Prioridade:** Alta  

**Descrição:** CRUD de **fornecedores** com **PersonType** (Pf, Pj), validação de **CPF/CNPJ**, cadastro **global/compartilhado** e configuração de **forma de pagamento padrão por empresa** (override).

**Regras de negócio:**

- RN010.1: Pf exige CPF válido; Pj exige CNPJ válido.
- RN010.2: Fornecedor é **global no nível de empresa** — ex.: fornecedor RJET padrão para Altitude; na Glow a **forma de pagamento** pode ser diferente via override por empresa.
- RN010.3: Override por empresa não duplica o cadastro do fornecedor; associa preferências de pagamento à combinação fornecedor+empresa.

**Critérios de aceite:**

- [ ] Cadastro impede documento inválido ou inconsistente com PersonType.
- [ ] Adm configura forma de pagamento override por empresa para um fornecedor existente.
- [ ] Solicitação de pagamento utiliza override da empresa da filial quando existir; caso contrário, usa padrão global.

---



#### RF011 — Instituições financeiras / BACEN (Bank) — cadastro manual

**Prioridade:** Alta  

**Descrição:** Manter cadastro de **bancos** via **cadastro manual** aplicado a **todos os clientes/empresas** (referência BACEN).

**Regras de negócio:**

- RN011.1: Não há importação automática via API BACEN/cron nesta versão.
- RN011.2: Cadastro central de bancos disponível para todas as empresas.

**Critérios de aceite:**

- [ ] Adm cadastra e mantém bancos manualmente.
- [ ] Contas da filial e configurações CNAB podem referenciar **Bank**.

---



### Fase 3 — Solicitação de pagamento (core) e OCR



#### RF012 — Solicitação de pagamento (PaymentRequest)

**Prioridade:** Alta  

**Descrição:** CRUD de **solicitação de pagamento** como objeto central do domínio, com status de ciclo de vida **PaymentRequestStatus**: Requested, Launched, Settled.

**Regras de negócio:**

- RN012.1: Valores monetários seguem `decimal(10,2)`.
- RN012.2: Transições de status registradas em **histórico** (RNF005 / `payment_request_status_history`).
- RN012.3: Filial gravada automaticamente conforme usuário solicitante (ou filial selecionada quando multi-filial).

**Critérios de aceite:**

- [ ] Solicitação criada com status inicial consistente (ex.: Requested).
- [ ] Histórico de mudanças de status persistido de forma auditável.

---



#### RF013 — Formulário em três blocos com lógica condicional

**Prioridade:** Alta  

**Descrição:** Formulário organizado em **três blocos** lógicos; campos visíveis e obrigatórios dependem de **PaymentMethod** e subtipo de depósito.

**Regras de negócio:**

- RN013.1: **PaymentMethod:** Boleto, Deposit.
- RN013.2: Para Deposit: **DepositType:** Pix, Transfer.
- RN013.3: Para Pix: **PixKeyType:** Random, Cpf, Phone, Email.
- RN013.4: Forma de pagamento sugerida pode vir do override fornecedor+empresa (RF010).

**Critérios de aceite:**

- [ ] Ao selecionar Boleto, exige-se fluxo de dados de boleto (incluindo anexo quando obrigatório).
- [ ] Ao selecionar Deposit + Pix, exibe tipo de chave e valida formato coerente.
- [ ] Ao selecionar Deposit + Transfer, exibe campos bancários de transferência.

---



#### RF014 — Dados bancários da solicitação (PaymentRequestBankDetails)

**Prioridade:** Alta  

**Descrição:** Persistir dados bancários específicos da solicitação em estrutura dedicada **PaymentRequestBankDetails**, separando cabeçalho da solicitação de detalhes de liquidação.

**Critérios de aceite:**

- [ ] Dados condicionais ao método de pagamento são salvos e recuperados corretamente.
- [ ] Relatórios e CNAB consomem esses campos na Fase 7.

---



#### RF015 — Anexos em S3 (PDF e imagens)

**Prioridade:** Alta  

**Descrição:** Permitir **upload** de arquivos vinculados à solicitação; armazenamento em **S3** (produção) com relação **morph** `attachments` — [.ai/docs/database.md](../docs/database.md), [.ai/docs/file-storage.md](../docs/file-storage.md).

**Regras de negócio:**

- RN015.1: Tipos permitidos: **PDF** e **imagens** (JPEG, PNG, WebP ou equivalentes acordados).
- RN015.2: **XML NF-e não faz parte** deste fluxo; NF vem em PDF ou imagem.
- RN015.3: Disco **private**; download via controle de acesso autenticado.
- RN015.4: Tamanho máximo por arquivo — **parâmetro operacional a definir** (sub-pendência seção 8).

**Critérios de aceite:**

- [ ] Upload rejeita tipos MIME não permitidos.
- [ ] Arquivos persistidos em S3 (ou disco configurado em dev).
- [ ] Usuário autorizado anexa, lista e baixa arquivos da solicitação.

---



#### RF016 — Cálculo automático do valor líquido

**Prioridade:** Alta  

**Descrição:** Calcular **valor líquido = valor bruto − descontos/deduções**.

**Critérios de aceite:**

- [ ] Alteração de bruto ou descontos atualiza líquido na UI e na persistência.
- [ ] Arredondamento segue `decimal(10,2)` sem uso de float.

---



#### RF017 — OCR local de boleto ao anexar

**Prioridade:** Alta  

**Descrição:** Ao anexar documento de boleto, tentar **extrair linha digitável, valor e vencimento** com biblioteca **local** (sem cloud).

**Regras de negócio:**

- RN017.1: Falha de OCR não impede salvamento do anexo; preenchimento manual permitido.
- RN017.2: Usuário revisa e corrige dados extraídos antes de submeter.

**Critérios de aceite:**

- [ ] OCR preenche campos esperados quando leitura for bem-sucedida.
- [ ] Feedback de falha apresentado sem vazar dados sensíveis.

---



#### RF018 — Listagem de solicitações com filtros e escopo de visibilidade

**Prioridade:** Média  

**Descrição:** Listar solicitações com filtros (filial, empresa, status, período, vencimento, fornecedor) respeitando **escopo de visibilidade por perfil**.

**Regras de negócio:**

- RN018.1: Cliente vê apenas solicitações das **filiais vinculadas**.
- RN018.2: Operador/Adm veem solicitações de **todas as empresas**.

**Critérios de aceite:**

- [ ] Filtros reduzem resultados corretamente dentro do escopo do usuário.
- [ ] Cliente não acessa solicitações fora de suas filiais via listagem ou URL direta.
- [ ] Paginação e ordenação padrão definidas (ex.: mais recentes primeiro).

---



### Fase 4 — Workflow com alçadas



#### RF019 — Regras de aprovação (ApprovalRule)

**Prioridade:** Alta  

**Descrição:** **Adm** configura **faixas de valor** por **filial** associando **aprovador** (usuário com **permissão de aprovador**).

**Regras de negócio:**

- RN019.1: Aprovador **não é perfil separado** — regra referencia usuários Operador/Adm com permissão.
- RN019.2: Exemplo ilustrativo: até R$ 5.000 → gerente; R$ 5.000–50.000 → diretor; > R$ 50.000 → CFO; **baseline de seeders** (0–5k / 5k–50k / >50k) aceito como matriz default (risco R05 — dados reais por filial ajustáveis operacionalmente).

**Critérios de aceite:**

- [x] Adm cria, edita e desativa regras; sobreposição tratada com regra de desempate documentada.
- [x] Valor da solicitação aciona faixa correta por filial.
- [x] Apenas usuários com permissão de aprovador aparecem como candidatos.

---



#### RF020 — Roteamento automático para aprovador

**Prioridade:** Alta  

**Descrição:** Ao exigir aprovação, solicitação **roteada** automaticamente conforme **ApprovalRule**.

**Critérios de aceite:**

- [x] Aprovador designado recebe registro **Approval** com status Pending.
- [x] Filas de “pendentes de aprovação” refletem roteamento.

---



#### RF021 — Notificação ao aprovador

**Prioridade:** Média  

**Descrição:** Notificar aprovador quando solicitação for atribuída — [.ai/docs/notifications.md](../docs/notifications.md).

**Critérios de aceite:**

- [x] Aprovador notificado ao receber pendência.
- [x] Falhas de notificação não corrompem estado da solicitação.

---



#### RF022 — Rejeição retorna ao solicitante

**Prioridade:** Alta  

**Descrição:** Em **Rejected**, solicitação retorna ao **solicitante** para correção e reenvio.

**Critérios de aceite:**

- [x] Histórico registra rejeição, autor e motivo (se previsto).
- [x] Solicitante visualiza solicitação como “devolvida” e pode agir conforme permissões.

---



#### RF023 — Registro de aprovações (Approval)

**Prioridade:** Alta  

**Descrição:** Persistir trilha de **Approval** com **ApprovalStatus:** Pending, Approved, Rejected.

**Critérios de aceite:**

- [x] Aprovação e rejeição atualizam status e disparam efeitos de fluxo coerentes.
- [x] Histórico de aprovações consultável por perfis autorizados.

---



#### RF024 — SLA configurável por empresa com escalação automática

**Prioridade:** Alta  

**Descrição:** **Adm** configura **SLA de aprovação por empresa em dias úteis** (`approval_sla_business_days`, default 2); ao expirar, o sistema executa **escalação automática** conforme escada fechada na seção 8 (sininho Filament / canal `database`; sem reatribuição no breach).

**Regras de negócio:**

- RN024.1: SLA é parâmetro **por empresa (Company)**, não global fixo — coluna `approval_sla_business_days` (dias úteis Mon–Fri; feriados BR fora do escopo F4).
- RN024.2: Escalação dispara evento **ApprovalSlaBreached** (seção 6).
- RN024.3: Escada de escalação — **fechada** (seção 8): notificação interna (sininho) para aprovador atual + Adms ativos; marca `escalated_at` uma vez; **não** reatribui aprovador no breach; **não** envia mail na escalação.

**Critérios de aceite:**

- [x] Adm define SLA em **dias úteis** por empresa.
- [x] Aprovação pendente além do SLA dispara escalação registrada (`escalated_at` + evento).
- [x] Notificação enviada aos envolvidos na escalação (canal `database` / sininho).

---



### Fase 5 — Lote de solicitações (importação via planilha)



#### RF025 — Cadastro de template de importação (ImportTemplate)

**Prioridade:** Alta  

**Descrição:** **Adm** cadastra **templates de importação** que mapeiam **colunas da planilha** para **campos de PaymentRequest** (ex.: CPF/CNPJ, valor bruto, vencimento, centro de custo).

**Regras de negócio:**

- RN025.1: Template inclui metadados (nome, empresa/filial alvo opcional, formato aceito).
- RN025.2: Mapeamento coluna → campo é configurável e versionável (soft delete).

**Critérios de aceite:**

- [ ] Adm cria template associando cada coluna obrigatória a um campo do domínio.
- [ ] Template pode ser selecionado no fluxo de importação.
- [ ] Campos obrigatórios do domínio devem estar mapeados ou ter default definido.

---



#### RF026 — Importação em lote de solicitações via planilha

**Prioridade:** Alta  

**Descrição:** Usuário autorizado envia **planilha** conforme template; sistema valida **linha a linha** e gera **PaymentRequests** em lote, com **relatório de erros** por linha.

**Regras de negócio:**

- RN026.1: Linhas inválidas não impedem importação das válidas (modo parcial) ou bloqueiam lote inteiro — comportamento documentado na implementação.
- RN026.2: Importação dispara evento **PaymentRequestBatchImported** (seção 6).
- RN026.3: Validações incluem CPF/CNPJ, valores monetários e filial conforme usuário.

**Critérios de aceite:**

- [ ] Upload de planilha com template selecionado processa registros.
- [ ] Relatório lista linhas com erro e motivo.
- [ ] Solicitações válidas criadas com status inicial e filial correta.
- [ ] Job assíncrono recomendado para planilhas grandes (RNF009).

---



### Fase 6 — Lote de anexos (nomenclatura padronizada)



#### RF027 — Upload múltiplo de arquivos (AttachmentBatch)

**Prioridade:** Alta  

**Descrição:** Usuário autorizado faz **upload de múltiplos arquivos** (PDF/imagens) em um **lote de anexos** para processamento operacional interno.

**Regras de negócio:**

- RN027.1: Mesmas restrições de tipo de RF015 (PDF/imagens; S3).
- RN027.2: Lote recebe identificador único para rastreio.

**Critérios de aceite:**

- [ ] Upload múltiplo aceita apenas tipos permitidos.
- [ ] Lote listado com status (ex.: pendente classificação, concluído).

---



#### RF028 — Classificação ordenada por operadores internos

**Prioridade:** Alta  

**Descrição:** **Operadores internos** classificam arquivos do lote de forma **ordenada** (sequência definida na UI), associando cada arquivo a solicitação, fornecedor ou categoria operacional conforme fluxo acordado.

**Regras de negócio:**

- RN028.1: Ordem de classificação preservada para geração de nomenclatura.
- RN028.2: Dispara evento **AttachmentBatchClassified** ao concluir classificação.

**Critérios de aceite:**

- [ ] Operador reordena e classifica itens do lote.
- [ ] Classificação incompleta impede geração de nomenclatura final.
- [ ] Histórico registra operador e timestamp de cada classificação.

---



#### RF029 — Geração de nomenclatura padronizada (data/hora)

**Prioridade:** Alta  

**Descrição:** Após classificação, sistema gera **nome padronizado alfanumérico** para cada arquivo com base em **data/hora do sistema** (e regras complementares acordadas, ex.: prefixo de lote, sequencial).

**Regras de negócio:**

- RN029.1: Nomenclatura única por arquivo no contexto do lote (colisão tratada com sufixo sequencial).
- RN029.2: Arquivo renomeado no storage mantém referência ao registro original.
- RN029.3: Dispara evento **AttachmentRenamed** por arquivo ou em lote.

**Critérios de aceite:**

- [ ] Nomes gerados seguem padrão documentado (ex.: `YYYYMMDD_HHMMSS_SEQ.ext`).
- [ ] Download/listagem exibe nomenclatura padronizada.
- [ ] Renomeação não corrompe vínculo com solicitação quando associada.

---



### Fase 7 — Baixa em lote e CNAB



#### RF030 — Tela de baixa em lote com filtros

**Prioridade:** Alta  

**Descrição:** Interface para filtrar solicitações elegíveis (filial, vencimento, status) e preparar **baixa em lote**.

**Critérios de aceite:**

- [ ] Filtros retornam apenas itens elegíveis.
- [ ] Usuário seleciona subconjunto para baixa.

---



#### RF031 — Data de baixa e banco em lote

**Prioridade:** Alta  

**Descrição:** Informar **data de baixa** e **banco** para o lote antes da geração/registro.

**Critérios de aceite:**

- [ ] Validações impedem lote sem data ou banco quando obrigatório.
- [ ] Dados gravados em **PaymentSettlement** (ou equivalente).

---



#### RF032 — Configuração CNAB 240 por filial e banco (CnabConfig)

**Prioridade:** Alta  

**Descrição:** **Adm** configura parâmetros de geração **CNAB layout 240** por **filial + banco** (convênio, carteira, etc.).

**Regras de negócio:**

- RN032.1: **Layout 240** adotado como padrão universal.
- RN032.2: **Banco inicial: Itaú**; demais bancos via adapters incrementais (risco R02).

**Critérios de aceite:**

- [ ] Configuração Itaú 240 disponível e utilizada na geração inicial.
- [ ] Alterações auditáveis (created_by/updated_by).

---



#### RF033 — Geração assíncrona de CNAB (GenerateCnabFileJob)

**Prioridade:** Alta  

**Descrição:** Job **GenerateCnabFileJob** produz arquivo CNAB 240 a partir do lote (boleto, transferência, PIX).

**Regras de negócio:**

- RN033.1: **Strategy / adapter por banco** — Itaú primeiro.
- RN033.2: **Dry-run** ou validação prévia antes do arquivo final (risco R08).

**Critérios de aceite:**

- [ ] Job completa com **CnabFile** e **CnabFileItem** consistentes.
- [ ] Falhas geram registro recuperável pelo operador.

---



#### RF034 — Download do arquivo CNAB

**Prioridade:** Alta  

**Descrição:** Usuário autorizado baixa arquivo gerado para upload manual no internet banking.

**Critérios de aceite:**

- [ ] Download restrito a perfis/filiais autorizados.
- [ ] Nome e formato seguem convenção CNAB 240 Itaú acordada.

---



### Fase 8 — Dashboard e relatórios



#### RF035 — Dashboard Filament com indicadores

**Prioridade:** Média  

**Descrição:** Painel com estatísticas: **total pago no mês**, **pendentes**, **a vencer**, **vencidos**.

**Critérios de aceite:**

- [ ] Números batem com consultas de referência em base de teste.
- [ ] Filtro por empresa/filial/período quando previsto.

---



#### RF036 — Gráficos por filial e centro de custo

**Prioridade:** Média  

**Descrição:** Visualizações por **filial** e **centro de custo**.

**Critérios de aceite:**

- [ ] Gráficos respondem a filtros globais do dashboard.
- [ ] Performance adequada para ~70 pagamentos/dia (RNF011).

---



#### RF037 — Relatório analítico em Excel com links para anexos

**Prioridade:** Média  

**Descrição:** Job **GenerateAnalyticalReportJob** gera planilha **Excel** com dados analíticos e **links** para anexos no S3.

**Critérios de aceite:**

- [ ] Excel contém colunas acordadas e abre em ferramenta padrão.
- [ ] Links de anexos respeitam autenticação (URL assinada ou equivalente).

---



## 4. Requisitos Não-Funcionais



### RNF001 — Modelagem de dados

Chaves primárias **UUID**; **timestamps**; **SoftDeletes**; colunas de autoria `created_by` **/** `updated_by` — [.ai/docs/database.md](../docs/database.md). Hierarquia **Company → Branch → BranchBankAccount**.

### RNF002 — Valores monetários

Todos os valores financeiros persistidos como `decimal(10,2)` — sem `float`/`double`.

### RNF003 — Status persistidos como string

Campos de status/tipo como `string(20)` indexados, com **cast para Enum PHP** — [.ai/docs/enums.md](../docs/enums.md).

### RNF004 — Anexos polimórficos

Relação **morph** para `attachments` — [.ai/docs/database.md](../docs/database.md).

### RNF005 — Auditoria de status de solicitação

Tabela **payment_request_status_history** para trilha mínima de mudanças de status.

### RNF006 — Segurança e controle de acesso

Controle de acesso por **policies** (RF006) e escopo por perfil/filial/empresa. **Sem mascaramento LGPD** para dados de fornecedores (decisão RJET); manter boas práticas de proteção de credenciais e URLs assinadas para anexos.

### RNF007 — Validação de documentos

Validação rigorosa de **CPF/CNPJ** em fornecedores, importação em lote e solicitações.

### RNF008 — Qualidade de arquivo CNAB

**Validação / dry-run** antes da geração final CNAB 240 (risco **R08**).

### RNF009 — Processamento assíncrono

**GenerateCnabFileJob**, **GenerateAnalyticalReportJob** e importação de planilhas grandes executam em fila — [.ai/docs/queues.md](../docs/queues.md).

### RNF010 — Eventos de domínio

Eventos nomeados no passado, classes `final`, payloads mínimos — [.ai/docs/events.md](../docs/events.md).

### RNF011 — Performance (volume inicial)

Sistema dimensionado para ~**70 pagamentos/dia** e lotes moderados; latência de listagens e dashboard adequada sem otimização prematura.

### RNF012 — Armazenamento de arquivos

Produção em **S3** (private); desenvolvimento pode usar disco local — [.ai/docs/file-storage.md](../docs/file-storage.md). Tipos: **PDF** e **imagens** apenas.

---



## 5. Status e transições

> Padrão: `string` no banco; Enum no PHP — [.ai/docs/enums.md](../docs/enums.md).



### 5.1 UserRole (papéis)


| Valor    | Uso                                                              |
| -------- | ---------------------------------------------------------------- |
| Cliente  | Criação/visualização de solicitações (escopo filiais vinculadas) |
| Operador | Alteração operacional; lote de anexos; importação                |
| Adm      | Administração completa do módulo                                 |


*Transições:* N/A (atributo do usuário).

**Permissão adicional (não enum de perfil):** `can_approve` (ou equivalente) em Operador/Adm.

### 5.2 PersonType


| Valor | Uso                    |
| ----- | ---------------------- |
| Pf    | Pessoa física — CPF    |
| Pj    | Pessoa jurídica — CNPJ |




### 5.3 PaymentRequestStatus


| Status    | Descrição                               | Transições permitidas (macro fluxo) |
| --------- | --------------------------------------- | ----------------------------------- |
| Requested | Solicitada / em elaboração ou aprovação | → Launched                          |
| Launched  | Lançada no sistema / enviada ao banco   | → Settled                           |
| Settled   | Liquidada / baixada                     | —                                   |




### 5.4 PaymentMethod / DepositType / PixKeyType


| Enum          | Valores                   |
| ------------- | ------------------------- |
| PaymentMethod | Boleto, Deposit           |
| DepositType   | Pix, Transfer             |
| PixKeyType    | Random, Cpf, Phone, Email |




### 5.5 ApprovalStatus


| Status   | Transições                                                                 |
| -------- | -------------------------------------------------------------------------- |
| Pending  | → Approved, → Rejected; escalação SLA (RF024) **não** muda status — só `escalated_at` |
| Approved | —                                                                          |
| Rejected | — (devolução ao solicitante, RF022)                                        |


---



## 6. Eventos de negócio

> Listeners e filas da **Fase 4** preenchidos a partir do código. Demais eventos (F5–F8) permanecem TBD — [.ai/docs/events.md](../docs/events.md).


| Evento                      | Gatilho provável                  | Listeners                                                                 | Fila |
| --------------------------- | --------------------------------- | ------------------------------------------------------------------------- | ---- |
| PaymentRequestCreated       | Criação da solicitação            | `LogPaymentRequestActivity` (F3); `RoutePaymentRequestOnCreated` (F4, sync) | Route: Não; Log: conforme F3 |
| PaymentRequestStatusChanged | Mudança de status                 | `LogPaymentRequestActivity`                                               | Não (F3) |
| PaymentRequestBatchImported | Importação em lote concluída      | Notificar solicitante; log                                                | Sim (TBD F5) |
| ApprovalAssigned            | Nova pendência (`route`)          | `SendApprovalAssignedNotification` → mail + database                      | Sim  |
| ApprovalReassigned          | Reatribuição (aprovador inativo)  | `SendApprovalReassignedNotification` → mail + database                    | Sim  |
| PaymentRequestApproved      | Aprovação concluída               | `SendPaymentRequestApprovedNotification` → mail + database                | Sim  |
| PaymentRequestRejected      | Rejeição registrada               | `SendPaymentRequestRejectedNotification` → mail + database                | Sim  |
| ApprovalSlaBreached         | SLA expirado (`escalate`)         | `SendApprovalSlaBreachedNotifications` → database only (aprovador + Adms) | Sim  |
| AttachmentBatchClassified   | Lote de anexos classificado       | Disparar renomeação                                                       | TBD  |
| AttachmentRenamed           | Nomenclatura padronizada aplicada | Atualizar vínculos                                                        | TBD  |
| CnabFileGenerated           | Job CNAB concluído                | Registrar download                                                        | Sim  |
| AnalyticalReportGenerated   | Excel pronto                      | Notificar solicitante                                                     | Sim  |


---



## 7. Matriz de rastreabilidade (Fase 4)

| Requisito | Artefatos principais | Testes | Status |
|-----------|----------------------|--------|--------|
| RF019 | `ApprovalRule`, `ApprovalRuleService`, `ApprovalRuleResource`, `ApprovalRuleSeeder` | `ApprovalRuleServiceTest`, `ApprovalRuleResourceTest`, `ApprovalAuthorizationTest` | Implementado |
| RF020 | `ApprovalService::route`, `RoutePaymentRequestOnCreated`, tabs/filas PR | `ApprovalServiceTest`, `PaymentRequestApprovalActionsTest` | Implementado |
| RF021 | `ApprovalAssigned` + `ApprovalAssignedNotification` | `ApprovalServiceTest` (notify / isolation) | Implementado |
| RF022 | `reject` / `resubmit`, badge Devolvida, actions Filament | `ApprovalServiceTest`, `PaymentRequestApprovalActionsTest`, `PaymentRequestEditabilityTest` | Implementado |
| RF023 | `Approval`, `ApprovalStatus`, `ApprovalsRelationManager` | `ApprovalStatusTest`, `ApprovalCascadeTest`, `ApprovalAuthorizationTest` | Implementado |
| RF024 | `approval_sla_business_days`, `BusinessDays`, `approvals:escalate-sla`, `ApprovalSlaBreached` | `ApprovalSlaEscalationTest`, `BusinessDaysTest`, form SLA Company | Implementado |


---



## 8. Sub-pendências e decisões fechadas

| ID | Tema | Status | Decisão / nota |
|----|------|--------|----------------|
| P-SLA-ESC | Escada exata de escalação de SLA (RF024 / RN024.3) | **Fechada** (2026-08-05 / entrega F4 2026-08-12) | Notificação interna no **sininho Filament** (canal `database`) para aprovador atual + Adms ativos; preenche `escalated_at` uma vez; **sem** reassign no breach; **sem** mail na escalação. Comando `approvals:escalate-sla` a cada 15 min. |
| P-ANEXO-SIZE | Tamanho máximo por arquivo (RN015.4) | Aberta / F3 | Parâmetro operacional (`RJET_ATTACHMENTS_MAX_KB`); fora do escopo F4. |
| P-FERIADOS | Feriados BR no cálculo de dias úteis do SLA | Adiada | F4 conta apenas Mon–Fri; feriados podem entrar depois sem mudar a coluna. |


---



## Histórico de revisões

| Versão | Data | Autor | Descrição |
|--------|------|-------|-----------|
| 1.0 | 2026-07-07 | tech-writer | Versão inicial do DRF |
| 1.1 | 2026-07-07 | tech-writer | Ajustes pós-levantamento |
| 1.2 | 2026-08-12 | tech-writer | Fase 4: critérios RF019–RF024 marcados; eventos F4; escada SLA fechada (§8); matriz §7 |

