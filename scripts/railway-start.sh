#!/bin/sh
set -e

# SQLite lives on the container filesystem: recreate, migrate and seed on every boot.
mkdir -p database storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
touch "${DB_DATABASE:-database/database.sqlite}"

php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
