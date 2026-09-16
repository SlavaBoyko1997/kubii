#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
    echo "Missing .env file. Copy .env.example to .env and configure it first."
    exit 1
fi

if [ ! -f vendor/autoload.php ]; then
    composer install --no-dev --optimize-autoloader --no-interaction
fi

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
mkdir -p storage/app/private/{livewire-tmp,database-imports,database-exports}
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

php artisan storage:link --force 2>/dev/null || true

exec "$@"
