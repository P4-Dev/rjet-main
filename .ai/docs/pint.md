# Laravel Pint - Guideline

> **Regras para formatação de código com Laravel Pint. Pint é OBRIGATORIO - nenhum codigo
> nao formatado entra no repositorio.**

---

## 1. Regra Fundamental

**Pint e OBRIGATORIO.** Todo codigo PHP deve ser formatado antes de cada commit. O CI bloqueia
codigo nao formatado.

| Regra | Obrigatoriedade |
|-------|:---------------:|
| Rodar Pint antes de cada commit | **Obrigatorio** |
| CI rejeita codigo nao formatado | **Sim** |
| Preset `laravel` como base | **Obrigatorio** |
| `declare(strict_types=1)` em todo arquivo | **Obrigatorio** |
| Nenhuma excecao para "so um arquivo rapido" | **Zero tolerancia** |

```bash
# Regra de ouro: SEMPRE rodar antes de commitar
vendor/bin/pint --dirty
```

---

## 2. Instalacao

Laravel Pint ja vem incluso no Laravel. **Nao requer instalacao adicional.**

```bash
# Ja disponivel em qualquer projeto Laravel
vendor/bin/pint --version
```

Caso precise instalar manualmente (raro):

```bash
composer require laravel/pint --dev
```

---

## 3. Configuracao (`pint.json`)

O arquivo `pint.json` na raiz do projeto define o preset e regras customizadas.

### Estrutura Basica

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```

### Configuracao Completa Recomendada

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "final_class": true,
        "no_unused_imports": true,
        "ordered_imports": {
            "sort_algorithm": "alpha",
            "imports_order": ["const", "class", "function"]
        },
        "array_syntax": {
            "syntax": "short"
        },
        "no_extra_blank_lines": {
            "tokens": [
                "extra",
                "throw",
                "use"
            ]
        },
        "trailing_comma_in_multiline": {
            "elements": ["arrays", "arguments", "parameters"]
        },
        "single_quote": true,
        "no_empty_statement": true,
        "no_whitespace_in_blank_line": true,
        "blank_line_before_statement": {
            "statements": ["return", "throw", "try"]
        }
    },
    "exclude": [
        "bootstrap/cache",
        "storage",
        "node_modules",
        "vendor"
    ],
    "notPath": [
        "_ide_helper.php",
        "_ide_helper_models.php",
        ".phpstorm.meta.php"
    ],
    "notName": [
        "*.blade.php"
    ]
}
```

### Opcoes de Exclusao

| Opcao | Funcao | Exemplo |
|-------|--------|---------|
| `exclude` | Exclui diretorios inteiros | `"storage"`, `"bootstrap/cache"` |
| `notPath` | Exclui arquivos por caminho parcial | `"_ide_helper.php"` |
| `notName` | Exclui arquivos por padrao de nome | `"*.blade.php"` |

---

## 4. Preset Laravel

O preset `laravel` e baseado no PSR-12 com regras adicionais. O que ele garante:

| Regra | Descricao |
|-------|-----------|
| PSR-12 completo | Base de formatacao |
| Ordenacao de imports | `use` statements ordenados alfabeticamente |
| Blank lines | Linhas em branco consistentes entre metodos |
| Trailing commas | Virgula no ultimo item de arrays multiline |
| Single quotes | Aspas simples para strings sem interpolacao |
| Short array syntax | `[]` ao inves de `array()` |
| Visibility | Visibilidade explicita em metodos e propriedades |
| Spacing | Espacamento consistente em operadores e controle |
| Type hints | Formatacao de type hints e return types |
| Braces | Chaves na mesma linha para classes/metodos |

### Exemplo: Antes e Depois

```php
// ANTES (nao formatado)
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model{
    protected $fillable = ["status","total","customer_id"];

    public function items() : HasMany
    {
        return $this->hasMany(OrderItem::class) ;
    }
}
```

```php
// DEPOIS (Pint formatado com preset laravel)
<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = ['status', 'total', 'customer_id'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
```

---

## 5. Regras Customizadas Recomendadas

### `declare_strict_types` - OBRIGATORIO

Forca `declare(strict_types=1)` no topo de todo arquivo PHP.

```json
{
    "rules": {
        "declare_strict_types": true
    }
}
```

**Resultado:**

```php
<?php

declare(strict_types=1);

namespace App\Models;
```

### `final_class`

Marca classes como `final` por padrao. Previne heranca nao planejada.

```json
{
    "rules": {
        "final_class": true
    }
}
```

**Nota:** Exclua Models, FormRequests e outros que precisam de heranca se necessario:

```json
{
    "rules": {
        "final_class": {
            "consider_absent_docblock_as_internal_class": false
        }
    }
}
```

### `no_unused_imports`

Remove automaticamente `use` statements nao utilizados.

```json
{
    "rules": {
        "no_unused_imports": true
    }
}
```

### `ordered_imports`

Ordena imports alfabeticamente, agrupados por tipo.

```json
{
    "rules": {
        "ordered_imports": {
            "sort_algorithm": "alpha",
            "imports_order": ["const", "class", "function"]
        }
    }
}
```

### `trailing_comma_in_multiline`

Virgula no ultimo elemento de arrays, argumentos e parametros multiline.

```json
{
    "rules": {
        "trailing_comma_in_multiline": {
            "elements": ["arrays", "arguments", "parameters"]
        }
    }
}
```

### `blank_line_before_statement`

Linha em branco antes de `return`, `throw` e `try`.

```json
{
    "rules": {
        "blank_line_before_statement": {
            "statements": ["return", "throw", "try"]
        }
    }
}
```

### Todas as Regras Juntas

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "final_class": true,
        "no_unused_imports": true,
        "ordered_imports": {
            "sort_algorithm": "alpha",
            "imports_order": ["const", "class", "function"]
        },
        "array_syntax": {
            "syntax": "short"
        },
        "trailing_comma_in_multiline": {
            "elements": ["arrays", "arguments", "parameters"]
        },
        "single_quote": true,
        "no_empty_statement": true,
        "no_whitespace_in_blank_line": true,
        "blank_line_before_statement": {
            "statements": ["return", "throw", "try"]
        }
    }
}
```

---

## 6. Execucao

### Comandos Principais

| Comando | Funcao | Quando Usar |
|---------|--------|-------------|
| `vendor/bin/pint` | Formata **todos** os arquivos | Formatacao completa do projeto |
| `vendor/bin/pint --dirty` | Formata apenas arquivos **alterados** (git) | **Desenvolvimento local** (recomendado) |
| `vendor/bin/pint --test` | Verifica sem alterar arquivos | **CI/CD** - falha se houver erro |
| `vendor/bin/pint path/to/file.php` | Formata arquivo especifico | Correcao pontual |
| `vendor/bin/pint app/Models` | Formata diretorio especifico | Formatacao parcial |
| `vendor/bin/pint --dirty --format agent` | Formato para output de agente/CI | **Execucao via Claude/agente** |
| `vendor/bin/pint --format agent` | Formato agent para todos os arquivos | Output legivel por agentes |

### Exemplos Praticos

```bash
# Desenvolvimento diario - so arquivos alterados
vendor/bin/pint --dirty

# Verificar se tudo esta formatado (sem alterar nada)
vendor/bin/pint --test

# Formatar um arquivo especifico
vendor/bin/pint app/Models/Order.php

# Formatar todo o diretorio de Models
vendor/bin/pint app/Models

# Execucao via agente (Claude Code, CI)
vendor/bin/pint --dirty --format agent

# Ver o que seria alterado (dry-run verboso)
vendor/bin/pint --test -v
```

### Flags Uteis

| Flag | Descricao |
|------|-----------|
| `--dirty` | Apenas arquivos com mudancas no git |
| `--test` | Modo verificacao (exit code 1 se houver erro) |
| `--format agent` | Output otimizado para agentes/CI |
| `-v` | Modo verboso (mostra detalhes) |
| `--config path/to/pint.json` | Usar configuracao customizada |
| `--preset laravel` | Forcar preset (override do pint.json) |

---

## 7. Git Hook (Pre-commit)

### Script Automatico

Crie o hook para rodar Pint automaticamente antes de cada commit:

```bash
#!/bin/sh
# .git/hooks/pre-commit

# Rodar Pint nos arquivos staged
STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.php$')

if [ -z "$STAGED_FILES" ]; then
    exit 0
fi

echo "Executando Laravel Pint nos arquivos staged..."

# Formatar apenas os arquivos staged
for FILE in $STAGED_FILES; do
    vendor/bin/pint "$FILE"
done

# Re-adicionar os arquivos formatados ao stage
echo "$STAGED_FILES" | xargs git add

echo "Pint executado com sucesso."
```

### Tornar Executavel

```bash
chmod +x .git/hooks/pre-commit
```

### Alternativa Simples (Todos os Dirty)

```bash
#!/bin/sh
# .git/hooks/pre-commit

vendor/bin/pint --dirty
git add -u
```

### Com Husky (se usar Node.js)

```json
// package.json
{
    "husky": {
        "hooks": {
            "pre-commit": "vendor/bin/pint --dirty && git add -u"
        }
    }
}
```

---

## 8. CI Integration

### GitHub Actions - Step de Verificacao

```yaml
# .github/workflows/ci.yml
name: CI

on:
  pull_request:
    branches: [develop, main]

jobs:
  code-style:
    name: Code Style (Pint)
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install Dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run Laravel Pint
        run: vendor/bin/pint --test

  tests:
    name: Tests
    needs: code-style
    runs-on: ubuntu-latest
    # ... steps de teste ...
```

### Pontos Importantes

1. **`--test` no CI** - Nunca usa `--dirty` no CI (nao ha historico git completo)
2. **Falha o build** - `--test` retorna exit code 1 se houver arquivos nao formatados
3. **Rodar antes dos testes** - Nao faz sentido rodar testes se o codigo nao esta formatado
4. **Job separado** - Code style e um job separado para feedback rapido

### Mensagem de Erro no CI

Quando o Pint falha no CI, o desenvolvedor deve:

```bash
# 1. Rodar Pint localmente
vendor/bin/pint --dirty

# 2. Commitar as correcoes
git add -u
git commit -m "style: run pint formatting"

# 3. Push
git push
```

---

## 9. Composer Scripts

### Configuracao no `composer.json`

```json
{
    "scripts": {
        "lint": "vendor/bin/pint --dirty",
        "lint:all": "vendor/bin/pint",
        "lint:check": "vendor/bin/pint --test",
        "lint:agent": "vendor/bin/pint --dirty --format agent",
        "test": "php artisan test --compact",
        "check": [
            "@lint",
            "@test"
        ]
    }
}
```

### Uso

```bash
# Formatar arquivos alterados
composer lint

# Formatar todos os arquivos
composer lint:all

# Verificar formatacao (sem alterar)
composer lint:check

# Execucao via agente
composer lint:agent

# Rodar tudo: Pint + testes
composer check
```

### Integracao com Script `dev`

```json
{
    "scripts": {
        "dev": [
            "Composer\\Config::disableProcessTimeout",
            "npx concurrently -c \"#93c5fd,#c4b5fd,#fb923c\" \"php artisan serve\" \"npm run dev\" --names=server,vite"
        ],
        "check": [
            "@lint",
            "@test"
        ],
        "pre-commit": [
            "@lint:check"
        ]
    }
}
```

---

## 10. Integracao com IDE

### PHPStorm

1. **Settings > Tools > External Tools** - Adicionar Pint:
   - Program: `$ProjectFileDir$/vendor/bin/pint`
   - Arguments: `$FilePath$`
   - Working directory: `$ProjectFileDir$`

2. **File Watcher** (auto-format ao salvar):
   - File type: PHP
   - Program: `$ProjectFileDir$/vendor/bin/pint`
   - Arguments: `$FilePath$`
   - Output paths to refresh: `$FilePath$`

3. **Keymap** - Atribuir atalho ao External Tool (sugestao: `Ctrl+Alt+L` override)

### VS Code

#### Extensao Recomendada

Instalar a extensao **Laravel Pint** (`open-southeners.laravel-pint`).

#### Configuracao (`settings.json`)

```json
{
    "laravel-pint.enable": true,
    "laravel-pint.executablePath": "vendor/bin/pint",
    "[php]": {
        "editor.defaultFormatter": "open-southeners.laravel-pint",
        "editor.formatOnSave": true
    }
}
```

#### Alternativa com `php-cs-fixer`

```json
{
    "php-cs-fixer.executablePath": "${workspaceFolder}/vendor/bin/pint",
    "php-cs-fixer.onsave": true,
    "[php]": {
        "editor.formatOnSave": true
    }
}
```

### Neovim (via null-ls ou conform.nvim)

```lua
-- conform.nvim
require("conform").setup({
    formatters_by_ft = {
        php = { "pint" },
    },
    formatters = {
        pint = {
            command = "vendor/bin/pint",
            args = { "$FILENAME" },
            stdin = false,
        },
    },
})
```

---

## 11. Checklist

- [ ] `pint.json` existe na raiz do projeto com preset `laravel`
- [ ] Regra `declare_strict_types` habilitada no `pint.json`
- [ ] `vendor/bin/pint --dirty` executado antes de cada commit
- [ ] Git hook pre-commit configurado para rodar Pint automaticamente
- [ ] CI roda `vendor/bin/pint --test` e bloqueia codigo nao formatado
- [ ] Composer scripts configurados (`lint`, `lint:check`, `check`)
- [ ] IDE configurada para formatar ao salvar (format on save)
- [ ] Execucoes via agente usam `--format agent`
- [ ] Exclusoes configuradas (`notPath`, `notName`, `exclude`) para arquivos gerados
- [ ] Time inteiro roda Pint localmente - nenhum commit sem formatacao
