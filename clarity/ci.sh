#!/bin/sh

set -eu

cd /var/www/html

cp .env.ci .env

composer audit --locked --no-interaction
npm audit --audit-level=high
vendor/bin/pint --test
npx eslint .
npm run build

php artisan migrate:fresh --force
php artisan passport:keys --force
php artisan test --compact --parallel
php artisan test tests/Browser --compact
