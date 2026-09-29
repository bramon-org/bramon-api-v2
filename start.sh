#!/bin/bash

# BRAMON API Laravel - Startup Script

set -e

# Increase memory limit for PHP CLI
export PHP_MEMORY_LIMIT=512M

echo "🚀 Starting BRAMON API (Laravel)..."

# Check if .env exists
if [ ! -f .env ]; then
    echo "📝 Creating .env from .env.example..."
    cp .env.example .env
fi

# Install dependencies if vendor doesn't exist
if [ ! -d vendor ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction
fi

# Generate app key if not set
if ! grep -q "^APP_KEY=" .env || grep -q "^APP_KEY=$" .env; then
    echo "🔑 Generating application key..."
    php -d memory_limit=512M artisan key:generate --force
fi

# Create storage directories
echo "📁 Creating storage directories..."
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Create sqlite database if using sqlite
if grep -q "DB_CONNECTION=sqlite" .env; then
    echo "🗄️ Creating SQLite database..."
    touch database/database.sqlite
fi

# Clear and cache config
echo "⚡ Optimizing..."
php -d memory_limit=512M artisan config:clear
php -d memory_limit=512M artisan route:clear
php -d memory_limit=512M artisan view:clear

# Start server
echo "✅ Starting server on http://localhost:8080"
php -d memory_limit=512M artisan serve --host=0.0.0.0 --port=8080