# 🚀 KICKOFF.md - Como Iniciar um Novo Projeto

> **Este arquivo é lido pelo Claude no início de cada projeto.**
> Ele explica como usar o framework e define o fluxo de trabalho.

---

## Contexto do Framework

Este projeto usa o **Laravel AI Framework** integrado com:
- **Laravel Boost** - MCP Server com tools e guidelines
- **Filament Blueprint** - Planning mode para Filament

### Recursos Disponíveis

**Slash Commands:**
- `/feature Nome` - Cria feature completa
- `/resource Model` - Cria Filament Resource
- `/service Nome` - Cria Service + DTO
- `/test Classe` - Cria testes
- `/blueprint Descrição` - Gera plano detalhado
- `/docs tipo Feature` - Gera documentação
- `/migrate Tabela` - Gera migration inteligente seguindo database.md

**Sub-Agentes (use "Use o {agente} para..."):**
- `business-analyst` - Refina requisitos
- `architect` - Desenha arquitetura
- `dba` - Otimiza banco de dados
- `implementer` - Implementa código
- `tester` - Cria testes
- `security` - Audita segurança
- `reviewer` - Revisa código
- `tech-writer` - Gera documentação formal

**Skills (carregados automaticamente quando relevante):**
- `architecture/` - DTOs, Services, Actions
- `filament/` - Resources, Forms, Tables
- `livewire-components/` - Custom Livewire v4 components, Islands, widgets
- `testing/` - Pest, mocking
- `api-rest/` - Controllers, Resources, Swagger (APIs do produto)
- `api-integration/` - Integrations, webhooks (APIs externas)
- `deployment/` - Docker, CI/CD, Nginx, PHP-FPM, Supervisor, GitHub Actions

---

## Fluxo Padrão para Nova Feature

Quando o usuário pedir uma nova feature ou sistema, siga este fluxo:

```
1. ENTENDIMENTO
   └── Pergunte o que não está claro
   └── Use business-analyst se for complexo

2. PLANEJAMENTO
   └── Use architect para desenhar
   └── Use /blueprint para plano Filament
   └── Use dba para revisar estrutura de dados

3. DOCUMENTAÇÃO INICIAL
   └── /docs requisitos {Feature}
   └── /docs arquitetura {Feature}

4. IMPLEMENTAÇÃO
   └── /feature {Nome} ou implementação manual
   └── Siga padrões de PROJECT.md

5. TESTES
   └── /test {Resource}
   └── /docs testes {Feature}

6. REVISÃO
   └── Use security para auditar
   └── Use reviewer para code review
```

---

## Como Interpretar Pedidos

### Pedido Vago
```
Usuário: "Preciso de um sistema de estoque"

Claude deve:
1. Perguntar detalhes essenciais
2. OU usar business-analyst para levantar requisitos
3. Apresentar escopo antes de implementar
```

### Pedido Claro
```
Usuário: "Crie um CRUD de produtos com categorias, 
         estoque mínimo e alertas"

Claude deve:
1. Confirmar entendimento brevemente
2. Ir direto para /blueprint ou /feature
3. Seguir padrões de PROJECT.md
```

### Pedido com Contexto
```
Usuário: "Seguindo nosso padrão, adicione gestão de 
         fornecedores ao módulo de estoque"

Claude deve:
1. Verificar código existente
2. Manter consistência visual e arquitetural
3. Implementar seguindo padrões estabelecidos
```

---

## Padrões a Seguir

### Sempre consultar (PRIMEIRO):
0. **PROJECT.md → "Preferências de Comunicação e Estilo de Código"** - Define idioma de resposta, nível de detalhe, comentários no código (nível e idioma), convenção de variáveis e idioma de documentação. **TODA interação deve seguir estas preferências.**

### Sempre consultar:
1. **PROJECT.md** - Configurações, padrões do projeto e **ambiente de execução (Docker)**
2. **CLAUDE.md** - Guidelines consolidadas (gerado pelo Boost)
3. **`.ai/docs/database.md`** - Normalização, nomenclatura de campos, morph vs dedicado
4. **`.ai/docs/localization.md`** - i18n, arquivos de tradução, uso de `__()`
5. **`.ai/docs/api.md`** - REST API, versionamento, autenticação, Swagger
6. **`.ai/docs/livewire.md`** - Livewire v4, Islands, breaking changes, componentes custom
7. **`.ai/docs/performance.md`** - N+1, cache, índices, paginação
8. **`.ai/docs/queues.md`** - Jobs, filas, batch, Horizon
9. **`.ai/docs/notifications.md`** - Email, database, broadcast, Filament notifications
10. **`.ai/docs/error-handling.md`** - Exceções, logging estruturado, handler global
11. **`.ai/docs/git.md`** - Branches, commits convencionais, CI/CD, deploy
12. **`.ai/docs/enums.md`** - PHP Enums, contratos Filament, transições de estado
13. **`.ai/docs/events.md`** - Events, Listeners, Subscribers, Observers
14. **`.ai/docs/file-storage.md`** - Upload, Storage facade, discos, validação de arquivos
15. **`.ai/docs/factories-seeders.md`** - Factories, states, sequences, seeders idempotentes
16. **`.ai/docs/scheduling.md`** - Console commands, schedule, cron, Docker scheduler
17. **`.ai/docs/soft-deletes.md`** - Soft deletes, restore, pruning, cascade, Filament trashed
18. **`.ai/docs/phpstan.md`** - Larastan, análise estática, levels, baseline, CI
19. **`.ai/docs/pint.md`** - Laravel Pint, formatação, regras, pre-commit hook
20. **`.ai/docs/mcp-tools.md`** - MCP Tools do Boost, workflows, integração com agentes
21. **Código existente** - Para manter consistência

### Referências Estruturais:
- **`.ai/checklists.md`** - Checklists unificados por tipo de arquivo (Model, Migration, Resource, etc.)
- **`.ai/decision-tree.md`** - Árvore de decisão: qual agente, comando ou guideline usar

### Sempre usar:
1. **MCP Tools** - `schema`, `routes`, `search-docs`
2. **Skills** - Quando contexto for relevante
3. **Convenções** - Nomenclatura, estrutura de pastas

### Sempre gerar:
1. **Testes** - Para todo código novo
2. **Documentação** - Quando solicitado ou para features complexas

---

## Exemplo de Início de Projeto

### Usuário diz:
> "Quero desenvolver um sistema de gestão de estoque"

### Claude responde:

```
Vou ajudar a desenvolver o sistema de gestão de estoque!

Para começar, preciso entender melhor o escopo. Algumas perguntas:

**Entidades principais:**
1. Quais entidades você precisa? (Produtos, Categorias, 
   Fornecedores, Movimentações, Inventário?)
2. Produtos têm variações (tamanho, cor)?

**Funcionalidades:**
3. Precisa de controle de estoque mínimo com alertas?
4. Vai ter entrada/saída manual ou integração com vendas?
5. Precisa de relatórios? Quais?

**Integrações:**
6. Integra com algum sistema externo (ERP, e-commerce)?
7. Precisa de código de barras/QR code?

**Usuários:**
8. Quem vai usar? (Admin, Estoquista, Comprador?)
9. Permissões diferentes por perfil?

Ou, se preferir, posso usar o **business-analyst** para fazer 
um levantamento completo de requisitos. O que prefere?
```

### Após respostas, Claude:

```
Perfeito! Com base nas suas respostas, vou seguir este plano:

## 1. Documentação de Requisitos
Vou usar o business-analyst e depois formalizar com /docs requisitos

## 2. Arquitetura
Entidades identificadas:
- Product (com variações)
- Category
- Supplier
- StockMovement
- Inventory

Vou usar o architect para desenhar e /docs arquitetura para formalizar

## 3. Planejamento Filament
/blueprint Sistema de estoque com produtos, categorias, 
fornecedores, movimentações e alertas de estoque mínimo

## 4. Implementação
Ordem: Category → Supplier → Product → StockMovement → Inventory

Posso começar? Quer que eu siga esse fluxo completo ou 
prefere acompanhar passo a passo?
```

---

## Memória Persistente (Setup Inicial)

Na **primeira interação** com um novo projeto, configure a memória em camadas.
O Claude Code cria automaticamente o diretório `memory/` em:

```
~/.claude/projects/{project-path}/memory/
```

### Estrutura obrigatória

Crie estes arquivos na primeira sessão:

```
memory/
├── MEMORY.md        ← Índice (always-loaded, max 200 linhas)
├── decisions.md     ← Decisões técnicas e preferências do usuário
├── patterns.md      ← Padrões do código, namespaces, convenções
├── debugging.md     ← Problemas resolvidos e lições aprendidas
└── session-log.md   ← Resumo cronológico do que foi feito
```

### MEMORY.md (template)

```markdown
# Project Memory

## Quick Reference
- Stack: {detectar via composer.json/package.json}
- Guidelines: `.ai/docs/` (NOT `.ai/guidelines/` - must stay EMPTY)
- {decisões-chave do usuário}

## Topic Files (read when relevant)
- `decisions.md` - Technical decisions, communication prefs
- `patterns.md` - Code patterns, namespaces, conventions
- `debugging.md` - Resolved issues and lessons learned
- `session-log.md` - What was done in each session
```

### Regras

1. **MEMORY.md** = apenas índice (~500 bytes). Nunca colocar detalhes aqui.
2. **Topic files** = lidos sob demanda. Só carregar o relevante para a tarefa.
3. **Atualizar** topic files quando aprender algo novo (padrão, bug, decisão).
4. **session-log.md** = append-only. Uma linha por sessão com data e resumo.
5. **Nunca** duplicar conteúdo entre MEMORY.md e topic files.

---

## Lembre-se

1. **Pergunte antes de assumir** em casos complexos
2. **Mostre o plano** antes de implementar features grandes
3. **Siga PROJECT.md** para padrões visuais e técnicos
4. **Use os agentes** - eles existem para ajudar
5. **Documente** features importantes
6. **Teste** todo código novo
7. **Busque documentação na internet** ao implementar integrações com APIs externas
