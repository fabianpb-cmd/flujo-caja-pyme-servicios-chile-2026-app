# cPanel staging files

## SUBIR

- `app/`
- `bootstrap/`
- `config/`
- `database/`
- `public/`
- `resources/`
- `routes/`
- `storage/`
- `vendor/` only si el hosting no tiene Composer/SSH
- `artisan`
- `.env` generado solo en el servidor
- `.env.staging.template`

## NO SUBIR

- `.git/`
- `tests/`
- `node_modules/`
- `docs/` no necesarios para runtime
- dumps/backup de BD
- logs locales
- archivos temporales
- secretos
- datos UAT operacionales
- `dist/`

## GENERAR EN SERVIDOR

- `vendor/` cuando exista SSH + Composer
- `storage:link` solo si el hosting lo permite y llega a ser necesario para archivos públicos
- caches de Laravel (`config`, `route`, `view`, `optimize`)

## ARTEFACTOS DEL BUILD LOCAL

- `dist/cpanel-staging/app-private.zip`
- `dist/cpanel-staging/public.zip`
- `dist/cpanel-staging/staging-bootstrap.sql`
- `dist/cpanel-staging/SHA256SUMS.txt`
- `dist/cpanel-staging/php-runtime-check.php`
