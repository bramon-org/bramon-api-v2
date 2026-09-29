# Build stage
FROM php:8.4-fpm-alpine AS builder

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    linux-headers \
    sqlite-dev \
    freetype-dev \
    zlib-dev \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype \
    && docker-php-ext-install \
    pdo \
    pdo_sqlite \
    pdo_mysql \
    bcmath \
    opcache \
    gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files and application code
COPY composer.json composer.lock ./
COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY routes ./routes

# Create bootstrap/cache directory
RUN mkdir -p bootstrap/cache

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Generate optimized autoloader
RUN composer dump-autoload --optimize --classmap-authoritative

# Production stage
FROM php:8.4-fpm-alpine

# Install runtime dependencies
RUN apk add --no-cache \
    libpng \
    libpng-dev \
    libxml2 \
    sqlite-libs \
    sqlite-dev \
    freetype \
    freetype-dev \
    zlib-dev \
    nginx \
    supervisor

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype \
    && docker-php-ext-install \
    pdo \
    pdo_sqlite \
    pdo_mysql \
    bcmath \
    opcache \
    gd

# Configure PHP
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/http.d/default.conf

# Configure Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create www user (may already exist in base image)
RUN adduser -D -H -u 1000 -s /bin/bash www-data 2>/dev/null || true

# Set working directory
WORKDIR /var/www/html

# Copy application from builder (excluding storage and bootstrap/cache)
COPY --from=builder /var/www/html/app ./app
COPY --from=builder /var/www/html/bootstrap ./bootstrap
COPY --from=builder /var/www/html/config ./config
COPY --from=builder /var/www/html/database ./database
COPY --from=builder /var/www/html/public ./public
COPY --from=builder /var/www/html/routes ./routes
COPY --from=builder /var/www/html/artisan ./artisan
COPY --from=builder /var/www/html/composer.json ./composer.json
COPY --from=builder /var/www/html/composer.lock ./composer.lock
COPY --from=builder /var/www/html/vendor ./vendor

# Create necessary directories and set permissions
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data \
    storage \
    bootstrap/cache \
    /var/www/html \
    && find storage -type d -exec chmod 775 {} \;

# Expose port
EXPOSE 8080

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]