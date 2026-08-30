# Multi-stage production Dockerfile for FASRE Laravel 11 Backend on Render
FROM php:8.3-cli-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies & PostgreSQL dev libraries
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libpq-dev \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    linux-headers

# Install required PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    mbstring \
    bcmath \
    opcache \
    pcntl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files first for optimal Docker caching
COPY composer.json composer.lock ./

# Install production PHP dependencies
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# Copy application source code
COPY . .

# Run post-autoload scripts
RUN composer dump-autoload --optimize --no-dev

# Setup entrypoint script permissions
RUN chmod +x /var/www/html/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Expose default port
EXPOSE 8000

# Set entrypoint
ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
