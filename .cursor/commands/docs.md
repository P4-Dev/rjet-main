---
description: Gera documentação técnica, funcional ou de testes para uma feature
---

# /docs $ARGUMENTS

Gere documentação para **$ARGUMENTS**.

## Tipos de Documentação

Identifique o tipo baseado no argumento:

| Argumento | Documento Gerado |
|-----------|------------------|
| `requisitos {Feature}` | DRF - Documento de Requisitos Funcionais |
| `arquitetura {Feature}` | DTA - Documento Técnico de Arquitetura |
| `testes {Feature}` | DCT - Documento de Casos de Teste |
| `api {Feature}` | Documentação de API REST |
| `adr {Decisão}` | Architecture Decision Record |
| `completo {Feature}` | Todos os documentos |

## Preferências (OBRIGATÓRIO)

Leia **"Preferências de Comunicação e Estilo de Código"** em PROJECT.md — **CRÍTICO:** `idioma_documentacao` define o idioma de toda documentação gerada. `nivel_resposta` define o nível de detalhe. **Siga rigorosamente.**

## Fluxo de Geração

### 1. Coleta de Informações

Busque informações em:
- **PROJECT.md** - Contexto do projeto
- **Outputs de agentes** - Se existirem docs preliminares
- **Código fonte** - Models, Controllers, Resources
- **Testes existentes** - Para casos de teste

### 2. Geração

Use os templates do `tech-writer` agent.

### 3. Salvamento

```
docs/
├── requirements/DRF-{numero}-{feature}.md
├── architecture/DTA-{numero}-{feature}.md
├── testing/DCT-{numero}-{feature}.md
├── api/{feature}.md
└── architecture/ADR/ADR-{numero}-{titulo}.md
```

## Exemplos

### Gerar requisitos
```
/docs requisitos Pedidos
```
Output: `docs/requirements/DRF-001-pedidos.md`

### Gerar arquitetura
```
/docs arquitetura Pagamentos
```
Output: `docs/architecture/DTA-001-pagamentos.md`

### Gerar casos de teste
```
/docs testes OrderResource
```
Output: `docs/testing/DCT-001-order-resource.md`

### Gerar documentação de API
```
/docs api Orders
```
Output: `docs/api/orders.md`

### Gerar ADR
```
/docs adr Escolha do Redis para cache
```
Output: `docs/architecture/ADR/ADR-001-redis-cache.md`

### Gerar tudo
```
/docs completo Assinaturas
```
Output: Todos os documentos para o módulo

## Templates

### DRF - Requisitos

```markdown
# Documento de Requisitos Funcionais
## {Feature}

**Código:** DRF-{numero}
**Versão:** 1.0
**Data:** {data}
**Status:** Rascunho

## 1. Objetivo
{Por que esta feature existe}

## 2. Requisitos Funcionais

### RF001 - {Nome}
**Descrição:** {O que deve fazer}
**Prioridade:** Alta
**Critérios de Aceite:**
- [ ] {critério}

## 3. Regras de Negócio

### RN001 - {Nome}
{Descrição da regra}

## 4. Casos de Uso

### UC001 - {Nome}
**Ator:** {quem}
**Fluxo:**
1. {passo}

## 5. Wireframes
{Referências ou ASCII}

## 6. Rastreabilidade
| RF | UC | Teste |
|----|----| ------|
| RF001 | UC001 | TC001 |
```

### DCT - Casos de Teste

```markdown
# Documento de Casos de Teste
## {Feature}

**Código:** DCT-{numero}
**Versão:** 1.0
**Data:** {data}

## 1. Escopo
{O que será testado}

## 2. Casos de Teste

### TC001 - {Nome}
**Requisito:** RF001
**Tipo:** Funcional
**Prioridade:** Alta

**Pré-condições:**
- {condição}

**Passos:**
| # | Ação | Esperado |
|---|------|----------|
| 1 | {ação} | {resultado} |

**Cenários Gherkin:**
```gherkin
Cenário: {nome}
  Dado {contexto}
  Quando {ação}
  Então {resultado}
```

**Código Pest:**
```php
it('{descrição}', function () {
    // arrange
    // act
    // assert
});
```

## 3. Matriz de Cobertura
| Requisito | Testes | % |
|-----------|--------|---|
| RF001 | TC001, TC002 | 100% |
```

## Integração com Agentes

O `/docs` pode usar outputs de outros agentes:

```
# Fluxo completo
"Use o business-analyst para levantar requisitos de Pedidos"
  → Output informal

/docs requisitos Pedidos
  → Formaliza em DRF

"Use o architect para desenhar Pedidos"
  → Output informal

/docs arquitetura Pedidos
  → Formaliza em DTA

"Use o tester para definir cenários de Pedidos"
  → Output informal

/docs testes Pedidos
  → Formaliza em DCT
```

## Output

Após gerar:

```
✅ Documentação gerada

📁 Arquivos criados:
- docs/requirements/DRF-001-pedidos.md
- docs/architecture/DTA-001-pedidos.md
- docs/testing/DCT-001-pedidos.md

📊 Cobertura:
- Requisitos: 5 RFs documentados
- Casos de Uso: 3 UCs documentados
- Testes: 12 TCs documentados

🔗 Rastreabilidade:
- RF001 → UC001 → TC001, TC002
- RF002 → UC002 → TC003
```
