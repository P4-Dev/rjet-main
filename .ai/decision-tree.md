# Arvore de Decisao - Agentes e Comandos

## Como usar este guia

Identifique o tipo de tarefa e siga o fluxo para encontrar o agente/comando correto.

## 1. Fluxo por Tipo de Tarefa

### Quero criar uma feature nova completa

1. `/kickoff` — Levantamento inicial, consulta guidelines
2. `business-analyst` — Requisitos funcionais, user stories
3. `/blueprint` — Plano de implementacao Filament
4. `architect` — Decisoes tecnicas (se complexo)
5. `/feature` — Execucao completa (model, migration, resource, service, tests)
6. `tester` — Cobertura de testes adicional
7. `reviewer` — Code review final
8. `tech-writer` — Documentacao (se necessario)

### Quero criar apenas um Resource Filament

→ `/resource` — Gera resource com form, table, pages

### Quero criar apenas um Service

→ `/service` — Gera service com testes

### Quero criar testes

→ `/test` — Gera testes Pest
→ `tester` — Se precisa de analise de cobertura

### Quero criar uma API REST

→ `/api-rest` — Gera controller, routes, resource, form request, tests

### Quero criar um componente Livewire

→ `/livewire-components` — Gera componente com padroes v4

### Quero integrar com API externa

→ `/api-integration` — Gera client, DTO, service, exception, tests

### Quero criar uma migration

→ `/migrate` — Gera migration inteligente seguindo database.md
→ `dba` — Se precisa de revisao de estrutura antes

### Quero configurar deployment/Docker

→ Skill `deployment/` — Docker, CI/CD, Nginx, PHP-FPM, Supervisor, GitHub Actions

### Quero revisar codigo existente

→ `reviewer` — Code review geral
→ `security` — Auditoria de seguranca
→ `dba` — Revisao de banco/migrations/queries

### Quero documentacao

→ `tech-writer` — DRF, DTA, DCT, API docs, Changelog

### Quero otimizar performance

→ `dba` — Queries, indices, N+1
→ Consultar: `.ai/docs/performance.md`

### Quero planejar implementacao Filament

→ `/blueprint` — Plano detalhado para implementacao

## 2. Tabela Resumo de Agentes

| Agente | Quando Usar | Input | Output |
|--------|-------------|-------|--------|
| business-analyst | Levantamento de requisitos | Briefing do negocio | User stories, regras, criterios |
| architect | Decisoes tecnicas complexas | Requisitos | Estrutura, models, relationships |
| implementer | Implementacao de codigo | Plano/spec | Codigo funcional |
| tester | Analise e criacao de testes | Codigo existente | Testes Pest |
| reviewer | Code review | Codigo para revisar | Review report |
| security | Auditoria de seguranca | Codigo/modulo | Relatorio OWASP |
| dba | Banco de dados | Migrations/queries | Otimizacoes, correcoes |
| tech-writer | Documentacao | Codigo/specs | Docs formais |

## 3. Tabela Resumo de Comandos

| Comando | Quando Usar | O Que Gera |
|---------|-------------|------------|
| /kickoff | Inicio de qualquer feature | Levantamento + checklist |
| /blueprint | Planejamento Filament | Plano de implementacao |
| /feature | Feature completa | Model + Migration + Resource + Service + Tests |
| /resource | Apenas resource Filament | Resource + Form + Table + Pages |
| /service | Apenas service | Service + Tests |
| /test | Apenas testes | Testes Pest |
| /api-rest | API REST completa | Controller + Routes + Resource + FormRequest + Tests |
| /livewire-components | Componente Livewire | Componente + View + Tests |
| /api-integration | Integracao externa | Client + DTO + Service + Exception + Tests |
| /migrate | Migration inteligente | Migration seguindo database.md + sugestao de Enums |

## 4. Fluxo de Decisao Rapida

```
Tarefa → E feature nova?
  ├── Sim → Complexa?
  │     ├── Sim → /kickoff → business-analyst → /blueprint → /feature → tester → reviewer
  │     └── Nao → /feature
  └── Nao → E revisao?
        ├── Sim → Tipo?
        │     ├── Codigo → reviewer
        │     ├── Seguranca → security
        │     ├── Banco → dba
        │     └── Documentacao → tech-writer
        └── Nao → E criacao isolada?
              ├── Resource → /resource
              ├── Service → /service
              ├── Teste → /test ou tester
              ├── API → /api-rest
              ├── Livewire → /livewire-components
              ├── Integracao → /api-integration
              ├── Migration → /migrate
              └── Deployment → skill deployment/
```

## 5. Quando Usar Agente vs Comando

| Situacao | Use |
|----------|-----|
| Criar artefato novo com padrao definido | **Comando** (/feature, /resource, etc.) |
| Analise, revisao, decisao | **Agente** (reviewer, architect, etc.) |
| Precisa de criatividade/julgamento | **Agente** |
| Precisa de output padronizado | **Comando** |
| Nao sabe por onde comecar | `/kickoff` → depois decide |

## 6. Guidelines por Contexto

| Trabalhando com... | Guidelines a consultar |
|---------------------|----------------------|
| Models/Migrations | database.md, enums.md, soft-deletes.md |
| Filament Resources | localization.md, enums.md, file-storage.md |
| APIs | api.md, error-handling.md |
| Jobs/Queues | queues.md, events.md, notifications.md |
| Testes | factories-seeders.md, testing guideline |
| Performance | performance.md, database.md |
| CI/CD | git.md, pint.md, phpstan.md, skill deployment/ |
| Livewire | livewire.md |
| Deployment/Docker | skill deployment/, git.md, queues.md, scheduling.md |
| MCP Tools | mcp-tools.md |
