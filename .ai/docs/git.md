# Git e CI/CD - Guideline

> **Regras para versionamento, branches, commits e pipelines de deploy.**

---

## 1. Branch Strategy (Git Flow Simplificado)

```
main ─────────────────────────────────────────────────── (produção)
  └── develop ────────────────────────────────────────── (staging)
        ├── feature/order-management ──────── (nova feature)
        ├── fix/payment-calculation ────────── (bug fix)
        ├── hotfix/critical-auth-bug ──────── (hotfix urgente)
        └── chore/update-dependencies ─────── (manutenção)
```

### Nomenclatura de Branches

| Prefixo | Quando Usar | Exemplo |
|---------|-------------|---------|
| `feature/` | Nova funcionalidade | `feature/order-management` |
| `fix/` | Correção de bug | `fix/payment-rounding` |
| `hotfix/` | Correção urgente em produção | `hotfix/auth-token-expired` |
| `chore/` | Manutenção, deps, config | `chore/update-laravel-12` |
| `refactor/` | Refatoração sem mudança de comportamento | `refactor/order-service` |
| `docs/` | Apenas documentação | `docs/api-endpoints` |
| `test/` | Apenas testes | `test/payment-edge-cases` |

### Regras

1. **Branch names em inglês**, lowercase, separados por hífens
2. **Nunca commitar direto na `main`**
3. **`develop`** é a branch de integração — features mergeiam aqui
4. **`main`** recebe apenas merges de `develop` ou `hotfix/`
5. **Delete branches** após merge

---

## 2. Commits Convencionais

### Formato

```
<tipo>(<escopo>): <descrição curta>

<corpo opcional>

<rodapé opcional>
```

### Tipos

| Tipo | Descrição | Exemplo |
|------|-----------|---------|
| `feat` | Nova funcionalidade | `feat(orders): add status filter to list` |
| `fix` | Correção de bug | `fix(payment): correct rounding calculation` |
| `refactor` | Refatoração | `refactor(auth): extract token validation` |
| `test` | Apenas testes | `test(orders): add edge case for cancellation` |
| `docs` | Documentação | `docs(api): update swagger annotations` |
| `style` | Formatação (sem mudança de lógica) | `style: run pint formatting` |
| `chore` | Manutenção | `chore(deps): update filament to v5.3` |
| `perf` | Performance | `perf(dashboard): cache stats query` |
| `ci` | Pipeline CI/CD | `ci: add staging deploy workflow` |

### Regras

1. **Descrição em inglês**, imperativo, lowercase
2. **Máximo 72 caracteres** na primeira linha
3. **Escopo** é o módulo/feature afetado (opcional mas recomendado)
4. **Corpo** explica o "porquê", não o "o quê"
5. **Breaking changes** devem ter `BREAKING CHANGE:` no rodapé

### Exemplos

```bash
# Simples
feat(products): add stock alert notification

# Com corpo
fix(orders): prevent duplicate payment processing

The payment job was running without WithoutOverlapping middleware,
allowing concurrent executions for the same order.

# Breaking change
refactor(api): change pagination format to cursor-based

BREAKING CHANGE: API responses now use cursor pagination instead
of offset pagination. Update clients to use `next_cursor` param.
```

---

## 3. Pull Requests

### Template

```markdown
## Descrição
{O que foi feito e por quê}

## Tipo de Mudança
- [ ] Nova feature
- [ ] Bug fix
- [ ] Refatoração
- [ ] Breaking change
- [ ] Documentação
- [ ] Testes

## Checklist
- [ ] Testes criados/atualizados
- [ ] Testes passando (`php artisan test`)
- [ ] Pint executado (`vendor/bin/pint`)
- [ ] Migrations revisadas
- [ ] Traduções atualizadas (pt_BR + en)
- [ ] Swagger atualizado (se API)

## Screenshots (se UI)
{Antes e depois}
```

### Regras

1. **Título** segue formato de commit convencional
2. **Descrição** clara do que foi feito
3. **Reviewer** obrigatório antes de merge
4. **CI deve passar** antes de merge
5. **Squash merge** para branches de feature (1 commit limpo)
6. **Merge commit** para `develop → main` (preserva histórico)

---

## 4. GitHub Actions - CI

### Workflow de Pull Request

```yaml
# .github/workflows/ci.yml
name: CI

on:
  pull_request:
    branches: [develop, main]

jobs:
  tests:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: testing
          MYSQL_ROOT_PASSWORD: password
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, pdo_mysql, redis
          coverage: xdebug

      - name: Install Dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Copy .env
        run: cp .env.ci .env

      - name: Generate Key
        run: php artisan key:generate

      - name: Run Migrations
        run: php artisan migrate --force
        env:
          DB_HOST: 127.0.0.1
          DB_PORT: 3306

      - name: Run Pint
        run: vendor/bin/pint --test

      - name: Run Tests
        run: php artisan test --compact
        env:
          DB_HOST: 127.0.0.1
          DB_PORT: 3306

  assets:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install & Build
        run: |
          npm ci
          npm run build
```

### Workflow de Deploy

```yaml
# .github/workflows/deploy.yml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    environment: production

    steps:
      - uses: actions/checkout@v4

      - name: Build Docker Image
        run: |
          docker build -t ghcr.io/${{ github.repository }}:${{ github.sha }} .
          docker tag ghcr.io/${{ github.repository }}:${{ github.sha }} ghcr.io/${{ github.repository }}:latest

      - name: Login to GHCR
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Push Image
        run: |
          docker push ghcr.io/${{ github.repository }}:${{ github.sha }}
          docker push ghcr.io/${{ github.repository }}:latest

      - name: Deploy to Server
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.SERVER_HOST }}
          username: ${{ secrets.SERVER_USER }}
          key: ${{ secrets.SERVER_SSH_KEY }}
          script: |
            cd /app
            docker compose pull
            docker compose up -d
            docker compose exec -T app php artisan migrate --force
            docker compose exec -T app php artisan config:cache
            docker compose exec -T app php artisan route:cache
            docker compose exec -T app php artisan view:cache
```

---

## 5. Environments

| Ambiente | Branch | URL | Auto-deploy |
|----------|--------|-----|-------------|
| Local | qualquer | `localhost` | N/A |
| Staging | `develop` | `staging.app.com` | Sim (push) |
| Production | `main` | `app.com` | Sim (push) |

### Variáveis de Ambiente por Ambiente

```bash
# .env.ci - usado no GitHub Actions
APP_ENV=testing
APP_DEBUG=true
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=testing
DB_USERNAME=root
DB_PASSWORD=password
CACHE_DRIVER=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
```

---

## 6. Tags e Releases

### Versionamento Semântico

```
v{MAJOR}.{MINOR}.{PATCH}

MAJOR = Breaking changes (v2.0.0)
MINOR = Nova feature (v1.1.0)
PATCH = Bug fix (v1.0.1)
```

### Criar Release

```bash
# Tag
git tag -a v1.2.0 -m "feat: order management system"
git push origin v1.2.0

# GitHub Release (via gh CLI)
gh release create v1.2.0 --title "v1.2.0 - Order Management" --notes "
## Features
- Order CRUD with Filament
- Payment processing integration
- Email notifications

## Fixes
- Fixed stock calculation rounding
"
```

---

## 7. Hooks e Automação Local

### Pre-commit (opcional)

```bash
#!/bin/sh
# .git/hooks/pre-commit

# Executar Pint
vendor/bin/pint --dirty

# Adicionar arquivos formatados
git add -u
```

### Composer Scripts

```json
{
    "scripts": {
        "test": "php artisan test --compact",
        "lint": "vendor/bin/pint --dirty",
        "check": [
            "@lint",
            "@test"
        ]
    }
}
```

---

## 8. Proteção de Secrets

### Regras

1. **Nunca** commitar `.env`, credenciais ou tokens
2. **Usar** GitHub Secrets para CI/CD
3. **Usar** `.env.example` como referência
4. **Revisar** `.gitignore` ao adicionar novos tipos de arquivo

### .gitignore Essenciais

```gitignore
.env
.env.backup
.env.production
storage/*.key
*.pem
*.key
vendor/
node_modules/
```

---

## 9. Checklist

- [ ] Branches seguem nomenclatura padronizada
- [ ] Commits seguem formato convencional
- [ ] PR template preenchido com checklist
- [ ] CI roda testes + Pint em PRs
- [ ] Deploy automático configurado (staging + production)
- [ ] Secrets protegidos (nunca no código)
- [ ] `.env.example` atualizado
- [ ] Tags semânticas para releases
