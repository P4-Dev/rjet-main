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
chown -R www-data:www-data database
chmod -R 775 database

# ---- Cache (produção) ----
php artisan optimize:clear
php artisan optimize
php artisan icons:cache 2>/dev/null || true
php artisan filament:cache-components 2>/dev/null || true

# ---- Start services via Supervisor ----
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
