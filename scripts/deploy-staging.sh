#!/usr/bin/env bash
set -e

composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
