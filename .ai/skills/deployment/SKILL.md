# Skill: Deployment - Docker + CI/CD + Producao

> Skill unificado para configurar e manter a infraestrutura de deployment.
> Cobre Docker, Nginx, PHP-FPM, Supervisor, GitHub Actions e boas praticas de producao.

---

## Quando Usar

- Configurar Docker para producao (Dockerfile, docker-compose)
- Configurar Nginx como reverse proxy para Laravel
- Configurar PHP-FPM para performance
- Configurar Supervisor para gerenciar processos (queue workers, scheduler)
- Configurar GitHub Actions para CI/CD
- Configurar SSL/TLS com Traefik + Let's Encrypt
- Debugar problemas de deployment ou container
- Otimizar imagem Docker para producao

---

## Pre-requisitos

Antes de comecar:

1. Leia `PROJECT.md` para verificar `usar_docker` e `container_app`
2. Verifique se ja existem arquivos Docker no projeto (evite duplicar)
3. Consulte `.ai/docs/git.md` para fluxo de branches e tags

---

## Estrutura Docker do Projeto

```
Dockerfile                          # Imagem de producao (multi-stage)
docker-compose.yml                  # Orquestracao com Traefik
docker/
├── entrypoint-prod.sh             # Entrypoint: cache, migrate, supervisord
├── nginx/
│   ├── default-prod.conf          # Vhost: rate limiting, security headers, static
│   └── nginx-prod.conf            # Config principal do Nginx
├── php/
│   ├── php-prod.ini               # PHP: OPcache, JIT, seguranca, limits
│   ├── preload-prod.php           # OPcache preloading
│   └── www-prod.conf              # PHP-FPM: pool de workers
└── supervisor/
    └── supervisord-prod.conf      # Supervisor: php-fpm + nginx (+ workers)
```

---

## Template: Dockerfile Multi-Stage

O Dockerfile usa duas fases:
1. **Node (assets)** - Compila Vite/assets
2. **PHP-FPM Alpine (producao)** - Imagem final otimizada

```dockerfile
# ---- Build assets ----
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---- Production image ----
FROM php:8.4-fpm-alpine

LABEL org.opencontainers.image.source=https://github.com/{owner}/{repo}

# Extensoes necessarias
RUN apk add --no-cache \
    nginx supervisor sqlite sqlite-dev \
    postgresql-dev mysql-client mariadb-dev \
    icu-dev libzip-dev libpng-dev libjpeg-turbo-dev \
    freetype-dev libwebp-dev libexif curl-dev \
    libxml2-dev oniguruma-dev linux-headers $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo_sqlite pdo_pgsql pdo_mysql bcmath pcntl \
        intl zip gd exif mbstring curl xml \
    && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencias PHP (apenas producao)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Codigo da aplicacao
COPY . .

# Assets compilados
COPY --from=assets /app/public/build ./public/build

# Autoload otimizado
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && composer run-script post-autoload-dump

# Permissoes
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache database

# Configs
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/99-prod.ini
COPY docker/php/www-prod.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx/nginx-prod.conf /etc/nginx/nginx.conf
COPY docker/nginx/default-prod.conf /etc/nginx/http.d/default.conf
RUN mkdir -p /etc/supervisor/conf.d
COPY docker/supervisor/supervisord-prod.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint-prod.sh /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
```

### Decisoes Chave do Dockerfile

| Decisao | Motivo |
|---------|--------|
| Alpine | Imagem ~80% menor que Debian |
| Multi-stage | Assets nao vao para imagem final |
| `--no-dev` | Sem dependencias de dev em producao |
| `--classmap-authoritative` | Autoload otimizado (sem filesystem scan) |
| `www-data` owner | PHP-FPM roda como www-data |

---

## Template: docker-compose.yml

```yaml
services:
  app:
    build: .
    expose:
      - "80"
    volumes:
      - sqlite_data:/var/www/html/database    # Persistencia SQLite
      - storage:/var/www/html/storage          # Uploads e logs
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.{app}.rule=Host(`{domain}`)"
      - "traefik.http.routers.{app}.entrypoints=websecure"
      - "traefik.http.routers.{app}.tls.certresolver=letsencrypt"
      - "traefik.http.services.{app}.loadbalancer.server.port=80"
    networks:
      - traefik
    restart: unless-stopped

volumes:
  sqlite_data:
  storage:

networks:
  traefik:
    external: true
```

### Notas sobre docker-compose

- **Traefik** gerencia SSL/TLS automaticamente via Let's Encrypt
- **`expose`** (nao `ports`) — Traefik acessa via rede Docker, nao expoe porta no host
- **Volumes nomeados** para persistencia de dados (SQLite) e uploads (storage)
- **Network externa** `traefik` — compartilhada com outros servicos

### Para PostgreSQL/MySQL em vez de SQLite

```yaml
services:
  app:
    build: .
    depends_on:
      db:
        condition: service_healthy
    environment:
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: ${DB_DATABASE}
      DB_USERNAME: ${DB_USERNAME}
      DB_PASSWORD: ${DB_PASSWORD}
    # ... labels, networks

  db:
    image: postgres:16-alpine
    volumes:
      - db_data:/var/lib/postgresql/data
    environment:
      POSTGRES_DB: ${DB_DATABASE}
      POSTGRES_USER: ${DB_USERNAME}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME}"]
      interval: 5s
      timeout: 5s
      retries: 5
    networks:
      - traefik
    restart: unless-stopped

volumes:
  db_data:
  storage:
```

---

## Template: Supervisor

### Basico (PHP-FPM + Nginx)

```ini
[supervisord]
nodaemon=true
user=root
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g "daemon off;"
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

### Com Queue Workers e Scheduler

```ini
# ... (php-fpm e nginx acima)

[program:queue-high]
command=php /var/www/html/artisan queue:work --queue=high --sleep=3 --tries=3 --timeout=90
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:queue-default]
command=php /var/www/html/artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=90
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:queue-low]
command=php /var/www/html/artisan queue:work --queue=low --sleep=10 --tries=3 --timeout=300
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:scheduler]
command=sh -c "while true; do php /var/www/html/artisan schedule:run --no-interaction; sleep 60; done"
autostart=true
autorestart=true
user=www-data
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

### Parametros dos Workers

| Parametro | High | Default | Low |
|-----------|------|---------|-----|
| `numprocs` | 2 | 2 | 1 |
| `--sleep` | 3 | 3 | 10 |
| `--tries` | 3 | 3 | 3 |
| `--timeout` | 90 | 90 | 300 |

---

## Template: Nginx

A configuracao Nginx do projeto inclui:

### Seguranca
- Rate limiting por zona (general, login, API)
- Bloqueio de bots maliciosos (scanners, crawlers agressivos)
- Bloqueio de metodos HTTP suspeitos (TRACE, TRACK, DEBUG)
- Bloqueio de paths sensiveis (`.env`, `.git`, `wp-admin`, `phpmyadmin`)
- Bloqueio de extensoes perigosas (`.pht`, `.php3`, `.sh`, `.sql`)
- Headers de seguranca (X-XSS-Protection, Referrer-Policy, Permissions-Policy)
- Bloqueio de execucao PHP em `/uploads/` e `/storage/`

### Performance
- Cache de assets Vite (1 ano, immutable)
- Cache de imagens (30 dias)
- Cache de fontes (1 ano)
- Gzip (via nginx-prod.conf)
- `access_log off` para assets estaticos

### Laravel
- `try_files` com fallback para `index.php` (SPA routing)
- FastCGI com timeout de 300s
- Real IP de proxies Docker/Traefik
- Health check em `/health`

---

## Template: Entrypoint

```bash
#!/bin/sh
set -e

cd /var/www/html

# ---- Storage structure (volume pode montar vazio) ----
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# ---- Supervisor log dir ----
mkdir -p /var/log/supervisor

# ---- .env ----
if [ ! -f .env ]; then
    cp .env.example .env
fi

# ---- APP_KEY ----
if [ -z "$APP_KEY" ] && ! grep -q "APP_KEY=base64:" .env; then
    php artisan key:generate --force
fi

# ---- Database ----
php artisan migrate --force

# ---- Cache (producao) ----
php artisan optimize:clear
php artisan optimize
php artisan icons:cache
php artisan filament:cache-components

# ---- Start services via Supervisor ----
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
```

### Ordem de Execucao no Entrypoint

1. Criar estrutura de storage (volume pode estar vazio)
2. Configurar permissoes
3. Garantir `.env` existe
4. Gerar `APP_KEY` se ausente
5. Rodar migrations (`--force` para producao)
6. Cache warmup (config, routes, views, icons, Filament)
7. Iniciar Supervisor (que inicia PHP-FPM + Nginx + workers)

---

## Template: GitHub Actions CI/CD

```yaml
name: Build & Push Docker Image

on:
  push:
    branches:
      - main
      - develop
    tags:
      - 'v*.*.*'

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ${{ github.repository }}

jobs:
  build-and-push:
    runs-on: ubuntu-latest

    permissions:
      contents: read
      packages: write

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Login to GitHub Container Registry
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Extract metadata (tags, labels)
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}
          tags: |
            # branch: main → latest
            type=raw,value=latest,enable={{is_default_branch}}
            # branch: develop → develop
            type=ref,event=branch,enable=${{ github.ref == 'refs/heads/develop' }}
            # tag: v1.2.3 → 1.2.3, 1.2, 1
            type=semver,pattern={{version}}
            type=semver,pattern={{major}}.{{minor}}
            type=semver,pattern={{major}}
            # sha curto para rastreabilidade
            type=sha,prefix=

      - name: Build and Push
        uses: docker/build-push-action@v6
        with:
          context: .
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
          cache-from: type=gha
          cache-to: type=gha,mode=max
```

### Tags Semanticas

| Trigger | Tags Geradas |
|---------|--------------|
| Push `main` | `latest`, `sha-abc1234` |
| Push `develop` | `develop`, `sha-abc1234` |
| Tag `v1.2.3` | `1.2.3`, `1.2`, `1`, `sha-abc1234` |

### Cache

- `cache-from: type=gha` — reutiliza cache do GitHub Actions
- `cache-to: type=gha,mode=max` — salva todas as layers

---

## Otimizacoes de Producao

### OPcache

```ini
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 64
opcache.max_accelerated_files = 30000
opcache.validate_timestamps = 0          # Nao verificar mudancas (producao)
opcache.jit = 1255
opcache.jit_buffer_size = 128M
opcache.preload = /var/www/html/docker/php/preload-prod.php
opcache.preload_user = www-data
```

### Preloading

```php
<?php
// docker/php/preload-prod.php
require_once __DIR__ . '/../../vendor/autoload.php';

// Preload core Laravel classes
// O opcache.preload carrega classes em memoria compartilhada
// Reduz tempo de autoload em ~30%
```

### Cache Warmup (Entrypoint)

```bash
php artisan optimize:clear    # Limpa caches antigos
php artisan optimize          # config:cache + route:cache + view:cache + event:cache
php artisan icons:cache       # Cache de icones Filament
php artisan filament:cache-components  # Cache de componentes Filament
```

### Permissoes

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

**Regra:** PHP-FPM roda como `www-data`. Todos os diretorios de escrita devem pertencer a este usuario.

---

## Health Checks

### Endpoint /health no Nginx

```nginx
location = /health {
    access_log off;
    return 200 "OK";
    add_header Content-Type text/plain;
}
```

### Health Check no Docker Compose

```yaml
services:
  app:
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 40s
```

### Health Check no Traefik

```yaml
labels:
  - "traefik.http.services.{app}.loadbalancer.healthcheck.path=/health"
  - "traefik.http.services.{app}.loadbalancer.healthcheck.interval=10s"
```

---

## SSL/TLS com Traefik

### Configuracao do Traefik (externo ao projeto)

```yaml
# traefik.yml (no servidor, nao no projeto)
entryPoints:
  web:
    address: ":80"
    http:
      redirections:
        entryPoint:
          to: websecure
          scheme: https
  websecure:
    address: ":443"

certificatesResolvers:
  letsencrypt:
    acme:
      email: admin@example.com
      storage: /letsencrypt/acme.json
      httpChallenge:
        entryPoint: web
```

### Labels no docker-compose

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.{app}.rule=Host(`{domain}`)"
  - "traefik.http.routers.{app}.entrypoints=websecure"
  - "traefik.http.routers.{app}.tls.certresolver=letsencrypt"
```

---

## Zero-Downtime Deploy

### Estrategia: Rolling Update

1. CI/CD faz build e push da nova imagem
2. No servidor, `docker compose pull && docker compose up -d`
3. Container novo inicia, roda entrypoint (migrate, cache)
4. Traefik detecta health check OK e roteia trafego
5. Container antigo recebe SIGTERM e encerra graciosamente

### Automacao com Watchtower (opcional)

```yaml
# No servidor (docker-compose do Watchtower)
services:
  watchtower:
    image: containrrr/watchtower
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      - WATCHTOWER_CLEANUP=true
      - WATCHTOWER_POLL_INTERVAL=300    # 5 minutos
      - WATCHTOWER_LABEL_ENABLE=true
    restart: unless-stopped
```

```yaml
# No docker-compose do projeto
services:
  app:
    labels:
      - "com.centurylinklabs.watchtower.enable=true"
```

---

## Debugging

### Logs do Container

```bash
# Todos os logs
docker compose logs -f app

# Apenas PHP-FPM
docker compose exec app tail -f /var/log/php-error.log

# Apenas Laravel
docker compose exec app tail -f storage/logs/laravel.log
```

### Shell no Container

```bash
docker compose exec app sh
```

### Rebuild Completo

```bash
docker compose down
docker compose build --no-cache
docker compose up -d
```

### Supervisorctl

```bash
docker compose exec app supervisorctl status
docker compose exec app supervisorctl restart queue-default:*
docker compose exec app supervisorctl stop scheduler
```

---

## Troubleshooting

### Permission denied em storage/

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

**Causa:** volume montado com owner diferente de `www-data`.

### 502 Bad Gateway

1. Verificar se PHP-FPM esta rodando: `docker compose exec app supervisorctl status`
2. Verificar logs do PHP-FPM: `docker compose logs app | grep php-fpm`
3. Verificar se `fastcgi_pass` no Nginx aponta para `127.0.0.1:9000`

### Queue nao processa jobs

1. Verificar se worker esta no Supervisor: `supervisorctl status`
2. Se nao esta, adicionar programa `queue-default` no `supervisord-prod.conf`
3. Verificar `.env` — `QUEUE_CONNECTION` deve ser `database` ou `redis`, nao `sync`
4. Reiniciar workers: `supervisorctl restart queue-default:*`

### Scheduler nao roda

1. Verificar se programa `scheduler` esta no `supervisord-prod.conf`
2. Se nao esta, adicionar o programa scheduler (ver template acima)
3. Verificar com `supervisorctl status`

### OPcache nao atualiza

Em producao, `opcache.validate_timestamps = 0`. Apos deploy:

```bash
# O entrypoint ja faz optimize:clear, mas se precisar manualmente:
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan optimize
```

### Assets desatualizados (CSS/JS)

1. Verificar se a fase `assets` do Dockerfile esta rodando
2. Conferir se `COPY --from=assets /app/public/build ./public/build` existe
3. Rebuild: `docker compose build --no-cache app`

---

## Checklist de Deployment

### Dockerfile

- [ ] Multi-stage: Node (assets) + PHP-FPM (producao)
- [ ] `--no-dev` no composer install
- [ ] `--optimize --classmap-authoritative` no dump-autoload
- [ ] Permissoes de storage/bootstrap/cache para www-data
- [ ] Extensoes PHP necessarias instaladas
- [ ] `EXPOSE 80` definido
- [ ] `ENTRYPOINT` aponta para entrypoint script

### Docker Compose

- [ ] `expose` (nao `ports`) quando usar Traefik
- [ ] Labels Traefik configuradas (router, entrypoint, TLS, port)
- [ ] Volumes para dados persistentes (database, storage)
- [ ] Network `traefik` externa
- [ ] `restart: unless-stopped`
- [ ] `depends_on` com `condition: service_healthy` se usar banco externo

### PHP-FPM

- [ ] `pm = dynamic` com `max_children` adequado ao servidor
- [ ] `pm.max_requests = 500` (previne memory leaks)
- [ ] `catch_workers_output = yes`
- [ ] `clear_env = no` (permite variaveis de ambiente)

### Nginx

- [ ] Rate limiting configurado por zona
- [ ] Security headers (X-XSS-Protection, Referrer-Policy, Permissions-Policy)
- [ ] Bloqueio de paths sensiveis
- [ ] Cache de assets estaticos
- [ ] Health check endpoint `/health`
- [ ] Real IP configurado para rede Docker

### Supervisor

- [ ] PHP-FPM como programa gerenciado
- [ ] Nginx como programa gerenciado
- [ ] Queue workers (high/default/low) se usar filas
- [ ] Scheduler se usar scheduled commands
- [ ] `nodaemon=true` (obrigatorio para container)
- [ ] `stopasgroup=true` e `killasgroup=true` nos workers

### GitHub Actions

- [ ] Trigger em `main`, `develop` e tags `v*.*.*`
- [ ] Login no GHCR com `GITHUB_TOKEN`
- [ ] Tags semanticas (latest, version, sha)
- [ ] Cache de layers habilitado (`type=gha`)
- [ ] Permissions: `contents: read`, `packages: write`

### Producao

- [ ] `APP_ENV=production` no `.env`
- [ ] `APP_DEBUG=false`
- [ ] `LOG_CHANNEL=stack` (nao `single`)
- [ ] `QUEUE_CONNECTION=database` ou `redis` (nao `sync`)
- [ ] `SESSION_DRIVER=database` ou `redis` (nao `file` se multi-container)
- [ ] `CACHE_STORE=redis` ou `database` (nao `file` se multi-container)
- [ ] OPcache habilitado com `validate_timestamps=0`
- [ ] `APP_KEY` definido (nao gerado automaticamente)

---

## Referencias

- `.ai/docs/git.md` - Branches, commits convencionais, CI/CD
- `.ai/docs/queues.md` - Jobs, filas, batch, Horizon
- `.ai/docs/scheduling.md` - Console commands, schedule, Docker scheduler
- `.ai/docs/performance.md` - OPcache, preloading, cache
