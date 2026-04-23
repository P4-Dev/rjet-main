# MCP Tools - Laravel Boost

> Esta guideline documenta todas as ferramentas MCP disponibilizadas pelo Laravel Boost.
> Use-as para consultar dados do projeto, buscar documentacao e depurar problemas.

---

## Configuracao

O Boost e configurado no `.mcp.json` do projeto:

```json
{
  "mcpServers": {
    "laravel-boost": {
      "command": "npx",
      "args": ["-y", "@nicepkg/gpt-runner-mcp@latest"]
    }
  }
}
```

O Boost detecta automaticamente as versoes dos pacotes instalados no `composer.json`
e retorna documentacao especifica para essas versoes.

---

## Ferramentas Disponiveis

### 1. `schema` - Estrutura do Banco de Dados

**Proposito:** Consultar a estrutura do banco (tabelas, colunas, tipos, indices, FKs).

**Quando Usar:**
- Antes de criar migrations (verificar tabelas existentes)
- Antes de criar Models (confirmar colunas e tipos)
- Para entender relationships entre tabelas
- Para verificar indices existentes
- No code review de migrations

**Exemplos de Uso:**
```
schema                          # Todas as tabelas
schema --table=users            # Detalhes de uma tabela
schema --table=orders           # Colunas, indices, FKs
```

**Dicas:**
- Sempre consulte antes de criar uma migration para evitar duplicatas
- Use para confirmar tipos de coluna ao definir `casts()` no Model
- Verifique FKs existentes antes de adicionar novas

**Limitacoes:**
- Mostra a estrutura atual do banco, nao o historico de migrations
- Nao mostra dados, apenas estrutura

---

### 2. `routes` - Rotas Registradas

**Proposito:** Listar rotas registradas (URI, nome, metodo, middleware, controller).

**Quando Usar:**
- Antes de criar novas rotas (verificar conflitos de URI ou nome)
- Para encontrar qual controller/action trata uma URL
- Para verificar middleware aplicado
- Para gerar URLs corretas com `route()`

**Exemplos de Uso:**
```
routes                          # Todas as rotas
routes --name=users             # Filtrar por nome
routes --path=/api              # Filtrar por path
```

**Dicas:**
- Verifique nomes de rotas duplicados antes de registrar novas
- Confirme middleware de autenticacao nas rotas de API
- Use com `get-absolute-url` para gerar links corretos

**Limitacoes:**
- Apenas rotas registradas no momento (nao rotas dinamicas)

---

### 3. `search-docs` - Documentacao Oficial

**Proposito:** Buscar documentacao oficial dos pacotes instalados, com versoes corretas.

**Quando Usar:**
- **ANTES** de implementar qualquer funcionalidade
- Quando nao tem certeza da sintaxe correta
- Para verificar breaking changes entre versoes
- Para encontrar exemplos de codigo atualizados

**Sintaxe de Busca:**

| Tipo | Exemplo | Comportamento |
|------|---------|---------------|
| Palavra simples | `authentication` | Auto-stemming (encontra `authenticate`, `auth`) |
| Multiplas palavras (AND) | `rate limit` | Ambas devem estar presentes |
| Frase exata | `"infinite scroll"` | Palavras adjacentes na ordem |
| Misto | `middleware "rate limit"` | `middleware` AND frase exata |
| Multiplas queries | `["authentication", "middleware"]` | ANY (qualquer uma) |

**Exemplos de Uso:**
```
search-docs queries=["form validation", "form request"]
search-docs queries=["table columns", "table filters"] packages=["filament"]
search-docs queries=["queue jobs", "queue batching"] packages=["laravel"]
```

**Dicas:**
- Use queries **amplas e simples** — o resultado ja vem rankeado por relevancia
- **NAO** inclua nome do pacote na query (ja e passado automaticamente)
- Passe `packages` para filtrar resultados por pacote especifico
- Use multiplas queries de uma vez para cobrir termos relacionados
- Resultados retornam documentacao especifica para as versoes instaladas

**Limitacoes:**
- Depende da disponibilidade da API do Boost
- Nao cobre pacotes de terceiros fora do ecossistema Laravel

---

### 4. `database-query` - Consulta SQL (Leitura)

**Proposito:** Executar queries SELECT no banco de dados.

**Quando Usar:**
- Para verificar dados existentes antes de criar seeds
- Para debugar problemas de dados
- Para verificar contagem de registros
- Para testar queries antes de implementar scopes

**Exemplos de Uso:**
```
database-query SELECT COUNT(*) FROM users
database-query SELECT * FROM orders WHERE status = 'pending' LIMIT 10
database-query SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'
```

**Dicas:**
- Sempre use `LIMIT` para evitar retornos massivos
- Use para verificar se seeders ja rodaram
- Combine com `schema` para entender o que consultar

**Limitacoes:**
- **Apenas leitura** — nao executa INSERT, UPDATE, DELETE, DROP, etc.
- Nao suporta transacoes ou statements multiplos

---

### 5. `tinker` - PHP Interativo

**Proposito:** Executar codigo PHP no contexto da aplicacao Laravel.

**Quando Usar:**
- Para testar logica de negocio rapidamente
- Para verificar Eloquent queries e relationships
- Para debugar problemas complexos
- Para testar metodos de Service/Model

**Exemplos de Uso:**
```php
tinker User::count()
tinker User::with('orders')->find('uuid-here')->toArray()
tinker app(OrderService::class)->calculateTotal($order)
tinker config('app.name')
tinker OrderStatus::Pending->getLabel()
```

**Dicas:**
- Use para validar queries Eloquent antes de implementar
- Teste casts de Enum para confirmar comportamento
- Verifique config values com `config()`
- **Nao** use para operacoes destrutivas em producao

**Limitacoes:**
- Execucao sincrona — operacoes longas podem dar timeout
- Cuidado com side effects (criar/alterar dados)

---

### 6. `browser-logs` - Logs do Navegador

**Proposito:** Ler erros e logs do console do navegador.

**Quando Usar:**
- Para debugar erros de JavaScript
- Para investigar falhas de Livewire/Alpine.js
- Para verificar erros de requisicao (CORS, 404, 500)
- Para depurar problemas visuais com erros no console

**Exemplos de Uso:**
```
browser-logs                    # Logs recentes
```

**Dicas:**
- **Apenas logs recentes sao uteis** — ignore logs antigos
- Util para debugar problemas de Filament (modais, actions, notificacoes)
- Combine com `tinker` para investigar a causa raiz no backend

**Limitacoes:**
- Requer que o navegador esteja aberto e conectado
- Nao mostra logs de requisicoes assincronas internas do servidor

---

### 7. `list-artisan-commands` - Comandos Artisan

**Proposito:** Listar todos os comandos Artisan disponiveis com suas opcoes.

**Quando Usar:**
- **ANTES** de rodar qualquer comando `php artisan`
- Para verificar opcoes disponiveis em `make:*`
- Para descobrir comandos personalizados do projeto
- Para confirmar flags e parametros

**Exemplos de Uso:**
```
list-artisan-commands
list-artisan-commands --filter=make
list-artisan-commands --filter=filament
```

**Dicas:**
- Sempre verifique opcoes antes de rodar comandos `make:`
- Use para encontrar comandos Filament: `make:filament-resource`, etc.
- Confirme que `--no-interaction` e suportado antes de passar

**Limitacoes:**
- Lista apenas comandos registrados no momento

---

### 8. `get-absolute-url` - URL Absoluta

**Proposito:** Gerar a URL absoluta correta (scheme, dominio, porta).

**Quando Usar:**
- Sempre que compartilhar uma URL com o usuario
- Para gerar links para paginas do projeto
- Para construir URLs de callback/webhook

**Exemplos de Uso:**
```
get-absolute-url /admin/users
get-absolute-url /api/v1/orders
get-absolute-url /login
```

**Dicas:**
- **Obrigatorio** usar antes de compartilhar qualquer URL do projeto com o usuario
- Garante que scheme (http/https), dominio e porta estao corretos
- Especialmente importante em ambientes Docker onde porta pode variar

**Limitacoes:**
- Depende da configuracao `APP_URL` no `.env`

---

## Workflows Comuns

### Nova Feature

```
1. search-docs    → Verificar sintaxe e padroes atuais
2. schema         → Verificar tabelas existentes
3. routes         → Verificar rotas existentes
4. list-artisan-commands → Verificar opcoes de make:*
5. (implementar)
6. get-absolute-url → Compartilhar URL da feature com usuario
```

### Debug de Problema

```
1. browser-logs   → Verificar erros no console
2. tinker         → Testar logica no backend
3. database-query → Verificar dados no banco
4. schema         → Confirmar estrutura
5. search-docs    → Verificar se esta usando API correta
```

### Code Review (DBA)

```
1. schema         → Verificar indices e FKs
2. database-query → Verificar volume de dados
3. search-docs    → Confirmar best practices
4. tinker         → Testar queries problematicas
```

---

## Integracao com Agentes

| Agente | Tools que deve usar |
|--------|---------------------|
| `business-analyst` | `search-docs`, `routes` |
| `architect` | `schema`, `routes`, `search-docs` |
| `implementer` | `search-docs`, `schema`, `list-artisan-commands`, `routes` |
| `tester` | `search-docs`, `schema`, `tinker` |
| `dba` | `schema`, `database-query`, `search-docs`, `tinker` |
| `reviewer` | `schema`, `routes`, `search-docs` |
| `security` | `routes`, `schema`, `browser-logs` |
| `tech-writer` | `routes`, `get-absolute-url` |

---

## Checklist de Uso

### Antes de Implementar

- [ ] `search-docs` para sintaxe e padroes atuais do pacote
- [ ] `schema` para tabelas existentes (evitar duplicatas)
- [ ] `routes` para rotas existentes (evitar conflitos)
- [ ] `list-artisan-commands` para opcoes de geracao

### Durante Implementacao

- [ ] `tinker` para testar logica complexa
- [ ] `database-query` para verificar dados
- [ ] `search-docs` para duvidas de sintaxe

### Depois de Implementar

- [ ] `get-absolute-url` para compartilhar URLs com o usuario
- [ ] `browser-logs` para verificar erros no frontend
- [ ] `tinker` para validar o resultado

---

## Regra de Ouro

> **Sempre use `search-docs` ANTES de implementar.** A documentacao retornada e especifica
> para as versoes dos pacotes instalados no projeto. Isso evita usar APIs deprecadas
> ou sintaxe de versoes anteriores.
