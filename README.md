# Docker Laravel Template

Template Docker pronto para rodar aplicacoes **Laravel + Filament** com dois modos: desenvolvimento local e producao otimizada.

---

## Indice

- [Pre-requisitos](#pre-requisitos)
- [Estrutura do Projeto](#estrutura-do-projeto)
- [Desenvolvimento Local](#desenvolvimento-local)
  - [1. Configurar o .env](#1-configurar-o-env)
  - [2. Subir os containers](#2-subir-os-containers)
  - [3. Instalar dependencias](#3-instalar-dependencias)
  - [4. Acessar a aplicacao](#4-acessar-a-aplicacao)
  - [Comandos uteis no dia a dia](#comandos-uteis-no-dia-a-dia)
- [Build de Producao](#build-de-producao)
  - [Como funciona o Dockerfile de producao](#como-funciona-o-dockerfile-de-producao)
  - [Configurar extensoes (docker.conf)](#configurar-extensoes-dockerconf)
  - [Build com o wizard interativo](#build-com-o-wizard-interativo)
  - [Build via linha de comando](#build-via-linha-de-comando)
- [Autenticacao nos Registries](#autenticacao-nos-registries)
  - [GitHub Container Registry (GHCR)](#github-container-registry-ghcr)
  - [Docker Hub](#docker-hub)
  - [Verificar se funcionou](#verificar-se-funcionou)
- [Deploy com push para registry](#deploy-com-push-para-registry)
- [Referencia de Extensoes](#referencia-de-extensoes)
- [Windows](#windows)

---

## Pre-requisitos

| Ferramenta       | Versao minima | Como instalar                        |
| ---------------- | ------------- | ------------------------------------ |
| Docker           | 24+           | https://docs.docker.com/get-docker/  |
| Docker Compose   | v2+           | Ja vem com o Docker Desktop          |
| Git              | 2.x           | https://git-scm.com/downloads        |

> **Windows:** Docker Desktop exige WSL2. O terminal WSL ja roda todos os comandos deste README normalmente.

---

## Estrutura do Projeto

```
.
├── Dockerfile              # Imagem de producao (multi-stage, Alpine)
├── Dockerfile.dev          # Imagem de desenvolvimento (Debian, full)
├── docker-compose.yml      # Orquestracao para desenvolvimento local
├── docker.conf             # Config de build (nome da imagem, extensoes)
├── deploy.sh               # Script de build + push com wizard interativo
├── .dockerignore            # Arquivos excluidos do build de producao
├── .gitattributes           # Forca line endings LF (compatibilidade Windows)
└── docker/
    ├── entrypoint.sh           # Entrypoint desenvolvimento
    ├── entrypoint-prod.sh      # Entrypoint producao (migrations, cache, etc.)
    ├── supervisord.conf        # Supervisor desenvolvimento
    ├── supervisor/
    │   └── supervisord-prod.conf   # Supervisor producao
    ├── nginx/
    │   ├── nginx.conf              # Nginx config base (dev)
    │   ├── default.conf            # Vhost dev (usa template com envsubst)
    │   ├── nginx-prod.conf         # Nginx config base (producao)
    │   └── default-prod.conf       # Vhost producao (rate limiting, seguranca)
    ├── php/
    │   ├── php.ini                 # PHP config desenvolvimento
    │   ├── php-prod.ini            # PHP config producao (JIT, hardened)
    │   ├── www-prod.conf           # PHP-FPM pool producao
    │   └── preload-prod.php        # OPcache preload (classes do Laravel)
    ├── mysql/
    │   ├── my.cnf                  # Config otimizada do MySQL
    │   └── init.sql                # Script de inicializacao do banco
    └── postgresql/
        ├── init.sql                # Script de inicializacao do banco
        └── postgresql.conf         # Config otimizada do PostgreSQL
```

---

## Desenvolvimento Local

### 1. Configurar o .env

Copie o `.env.example` do seu projeto Laravel e preencha as variaveis do banco:

```bash
cp .env.example .env
```

As variaveis que o `docker-compose.yml` usa para criar o banco e conectar ao Redis:

```env
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret

REDIS_HOST=redis
REDIS_PORT=6379

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

> **Importante:** `DB_HOST` deve ser `postgres` e `REDIS_HOST` deve ser `redis` (nomes dos servicos no docker-compose), **nao** `localhost`.

> **Usando MySQL?** Descomente o servico `mysql` no `docker-compose.yml`, comente o `postgres`, e ajuste:
> ```env
> DB_CONNECTION=mysql
> DB_HOST=mysql
> DB_PORT=3306
> ```

### 2. Subir os containers

```bash
docker compose up -d --build
```

Isso cria 4 containers:

| Container      | Funcao                          | Porta        |
| -------------- | ------------------------------- | ------------ |
| `app`          | PHP-FPM + Supervisor + Horizon  | 5173 (Vite)  |
| `nginx_app`    | Servidor web                    | 80           |
| `postgres_app` | Banco de dados PostgreSQL       | 5432         |
| `redis_app`    | Cache, filas e sessoes          | 6379         |

### 3. Instalar dependencias

Na primeira vez (ou quando mudar o `composer.json` / `package.json`):

```bash
# Dependencias PHP
docker compose exec app composer install

# Dependencias Node (para Vite/assets)
docker compose exec app npm install

# Gerar a key da aplicacao
docker compose exec app php artisan key:generate

# Rodar migrations
docker compose exec app php artisan migrate

# (Opcional) Rodar seeders
docker compose exec app php artisan db:seed
```

### 4. Acessar a aplicacao

- **Aplicacao:** http://localhost
- **Vite HMR (hot reload):** http://localhost:5173
- **Banco:** `localhost:5432` (use DBeaver, TablePlus, pgAdmin, etc.)

Para rodar o Vite em modo dev (hot reload de CSS/JS):

```bash
docker compose exec app npm run dev
```

### Comandos uteis no dia a dia

```bash
# Ver logs de todos os containers
docker compose logs -f

# Ver logs de um container especifico
docker compose logs -f app

# Entrar no container da aplicacao
docker compose exec app bash

# Parar tudo
docker compose down

# Parar e remover o volume do banco (reset completo)
docker compose down -v

# Rebuild apos mudar o Dockerfile.dev
docker compose up -d --build
```

---

## Build de Producao

O build de producao gera uma **imagem unica e otimizada** que roda Nginx + PHP-FPM + Queue Worker via Supervisor, pronta para deploy.

### Como funciona o Dockerfile de producao

O `Dockerfile` usa 3 stages para manter a imagem final pequena:

```
Stage 1: composer-deps   -> instala dependencias PHP (sem dev)
Stage 2: assets          -> instala Node e roda npm run build
Stage 3: imagem final    -> Alpine + PHP-FPM + Nginx + codigo + assets compilados
```

Alem disso, no build time:
- Gera autoload otimizado do Composer
- Faz cache de icones e componentes Filament
- Configura OPcache com JIT para performance maxima

### Configurar extensoes (docker.conf)

Antes de fazer o build, edite o arquivo `docker.conf` para definir o nome da imagem e as extensoes que seu projeto precisa:

```bash
# docker.conf

# Nome do seu projeto
IMAGE_NAME="meu-projeto"
GHCR_REPO="ghcr.io/seu-usuario/${IMAGE_NAME}"
DOCKERHUB_REPO="seu-usuario/${IMAGE_NAME}"

# Extensoes ativas (separadas por espaco)
DB_DRIVERS="mysql"          # Opcoes: mysql pgsql sqlite
EXTRA_PECL="redis"          # Opcoes: redis mongodb imagick memcached
EXTRA_EXT=""                # Opcoes: gmp soap sockets calendar
```

> Se nao existir `docker.conf`, o `deploy.sh` usa os valores padrao (mysql + redis).

### Build com o wizard interativo

Basta rodar sem argumentos:

```bash
./deploy.sh
```

O wizard guia voce passo a passo:
1. **Tag** da imagem (ex: `latest`, `v1.0.0`)
2. **Database drivers** (selecione com setas + espaco)
3. **Extensoes PECL** (redis, imagick, etc.)
4. **Extensoes PHP** (gmp, soap, etc.)
5. **Destino do push** (GHCR, Docker Hub, ambos, ou nenhum)

### Build via linha de comando

Para CI/CD ou quando voce ja sabe o que quer:

```bash
# Build basico (so MySQL + Redis, sem push)
./deploy.sh --db mysql --pecl redis --tag v1.0.0 --ghcr

# MySQL + PostgreSQL
./deploy.sh --db "mysql pgsql"

# Todas as extensoes
./deploy.sh --db "mysql pgsql sqlite" --pecl "redis imagick" --ext "gmp soap"

# Apenas build local, sem push (selecione "none" no wizard)
./deploy.sh -i

# Ver todas as opcoes
./deploy.sh --help
```

---

## Autenticacao nos Registries

Para que o `deploy.sh` consiga fazer push da imagem, voce precisa autenticar sua maquina nos registries. **Isso so precisa ser feito uma vez por maquina.**

### GitHub Container Registry (GHCR)

**Passo 1 - Criar um Personal Access Token (PAT):**

1. Acesse https://github.com/settings/tokens
2. Clique em **"Generate new token (classic)"**
3. De um nome descritivo (ex: `docker-push`)
4. Selecione os escopos:
   - `write:packages`
   - `read:packages`
   - `delete:packages` (opcional, para limpar imagens antigas)
5. Clique em **"Generate token"**
6. **Copie o token** (ele so aparece uma vez!)

**Passo 2 - Fazer login no terminal:**

```bash
echo "SEU_TOKEN_AQUI" | docker login ghcr.io -u SEU_USUARIO_GITHUB --password-stdin
```

Exemplo:

```bash
echo "ghp_abc123..." | docker login ghcr.io -u your-user --password-stdin
```

> Voce deve ver: `Login Succeeded`

### Docker Hub

**Passo 1 - Criar uma conta (se nao tiver):**

1. Acesse https://hub.docker.com e crie uma conta

**Passo 2 - Criar um Access Token:**

1. Acesse https://hub.docker.com/settings/security
2. Clique em **"New Access Token"**
3. De um nome descritivo (ex: `docker-push`)
4. Permissao: **Read & Write**
5. Clique em **"Generate"**
6. **Copie o token**

**Passo 3 - Fazer login no terminal:**

```bash
echo "SEU_TOKEN_AQUI" | docker login -u SEU_USUARIO_DOCKERHUB --password-stdin
```

Exemplo:

```bash
echo "dckr_pat_abc123..." | docker login -u your-user --password-stdin
```

> Voce deve ver: `Login Succeeded`

### Verificar se funcionou

```bash
# Ver logins ativos
cat ~/.docker/config.json
```

Voce deve ver algo como:

```json
{
  "auths": {
    "ghcr.io": { "auth": "..." },
    "https://index.docker.io/v1/": { "auth": "..." }
  }
}
```

> **Dica de seguranca:** se preferir nao manter tokens em texto, instale o [docker-credential-helper](https://docs.docker.com/engine/reference/commandline/login/#credential-helpers) para armazenar no keychain do sistema.

---

## Deploy com push para registry

Apos a autenticacao, basta rodar o deploy com o destino desejado:

```bash
# Push para ambos (GHCR + Docker Hub)
./deploy.sh --tag v1.0.0 --both

# Push apenas para GHCR
./deploy.sh --tag v1.0.0 --ghcr

# Push apenas para Docker Hub
./deploy.sh --tag v1.0.0 --dockerhub

# Ou use o wizard interativo e selecione o destino
./deploy.sh
```

Apos o push, a imagem estara disponivel em:

- **GHCR:** `ghcr.io/seu-usuario/meu-projeto:v1.0.0`
- **Docker Hub:** `seu-usuario/meu-projeto:v1.0.0`

No servidor de producao, basta fazer pull e rodar:

```bash
docker pull ghcr.io/seu-usuario/meu-projeto:v1.0.0

docker run -d \
  --name meu-projeto \
  -p 80:80 \
  --env-file .env \
  ghcr.io/seu-usuario/meu-projeto:v1.0.0
```

O entrypoint de producao cuida automaticamente de:
- Criar estrutura de diretorios do storage
- Copiar `.env.example` se nao houver `.env`
- Gerar `APP_KEY` se necessario
- Rodar `php artisan migrate --force`
- Gerar caches de otimizacao (`optimize`, `icons:cache`, `filament:cache-components`)
- Iniciar Nginx + PHP-FPM + Queue Worker via Supervisor

---

## Referencia de Extensoes

### Database Drivers

| Nome     | Descricao                          |
| -------- | ---------------------------------- |
| `mysql`  | MySQL / MariaDB (padrao)           |
| `pgsql`  | PostgreSQL                         |
| `sqlite` | SQLite                             |

### PECL Extensions

| Nome        | Descricao                         |
| ----------- | --------------------------------- |
| `redis`     | Redis cache driver (padrao)       |
| `mongodb`   | MongoDB driver                    |
| `imagick`   | Manipulacao de imagens            |
| `memcached` | Memcached cache driver            |

### PHP Extensions

| Nome       | Descricao                         |
| ---------- | --------------------------------- |
| `gmp`      | GNU Multiple Precision            |
| `soap`     | SOAP web services                 |
| `sockets`  | Socket programming                |
| `calendar` | Calendar functions                |

> Extensoes que ja vem instaladas por padrao (nao precisam ser selecionadas): `opcache`, `bcmath`, `pcntl`, `intl`, `zip`, `gd`, `exif`, `mbstring`, `curl`, `xml`.

---

## Windows

O template funciona em Windows via **Docker Desktop + WSL2**.

O arquivo `.gitattributes` garante que todos os scripts e configs sao clonados com line endings `LF` (formato Linux), evitando erros do tipo `\r: not found` dentro dos containers.

**Nao e necessaria nenhuma configuracao extra.** Basta:

1. Ter o Docker Desktop instalado com backend WSL2
2. Clonar o repositorio normalmente
3. Abrir o terminal WSL e seguir os mesmos passos deste README

> **Dica:** se voce usa VS Code, instale a extensao "Remote - WSL" para editar os arquivos diretamente dentro do WSL com hot reload funcionando.
