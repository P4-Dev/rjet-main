FROM php:8.4-fpm-alpine

# Define arguments with defaults
ARG USER_ID=1000
ARG GROUP_ID=1000

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    sqlite-dev \
    icu-dev \
    shadow

# Create a developer user or sync www-data UID/GID
RUN usermod -u ${USER_ID} www-data && \
    groupmod -g ${GROUP_ID} www-data

# Install PHP extensions for Laravel & SQLite
RUN docker-php-ext-configure intl && \
    docker-php-ext-install pdo pdo_sqlite bcmath zip intl && \
    docker-php-ext-enable pdo_sqlite

# Set Workdir
WORKDIR /var/www

# Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy configs
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

# Nginx needs these folders to be writable by www-data
RUN mkdir -p /var/lib/nginx /var/log/nginx /var/tmp/nginx /var/log/supervisor /var/run/supervisord /var/www && \
    chown -R www-data:www-data /var/lib/nginx /var/log/nginx /var/tmp/nginx /var/log/supervisor /var/run/supervisord /var/www /run

# Switch to the non-root user
USER www-data

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]