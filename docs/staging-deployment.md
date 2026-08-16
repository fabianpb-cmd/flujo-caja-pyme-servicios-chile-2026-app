# Staging deployment checklist

Fecha: 2026-08-14

## Runtime

- PHP 8.3+
- Laravel 13
- MySQL externo al host de la app
- HTTPS detrás de reverse proxy o load balancer
- DocumentRoot apuntando a `public/`

## Variables de entorno requeridas

- `APP_NAME`
- `APP_ENV`
- `APP_KEY`
- `APP_DEBUG=false`
- `APP_URL`
- `DB_CONNECTION=mysql`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `SESSION_DRIVER=database`
- `SESSION_LIFETIME=30`
- `SESSION_ABSOLUTE_LIFETIME=480`
- `SESSION_EXPIRE_ON_CLOSE=true`
- `SESSION_SECURE_COOKIE=true`
- `SESSION_SAME_SITE=lax`
- `CACHE_STORE=database`
- `QUEUE_CONNECTION=sync` o el driver realmente usado
- `LOG_CHANNEL` / `LOG_LEVEL`
- `TRUSTED_PROXIES` y `TRUSTED_PROXY_HEADERS` según proveedor
- `BOOTSTRAP_COMPANY_NAME`
- `BOOTSTRAP_COMPANY_CODE`
- `BOOTSTRAP_ADMIN_NAME`
- `BOOTSTRAP_ADMIN_EMAIL`
- `BOOTSTRAP_ADMIN_PASSWORD`

## Bootstrap inicial

1. Ejecutar `php artisan migrate --force`.
2. Ejecutar `php artisan db:seed --force`.
3. Verificar que `BootstrapCompanySeeder` cree la empresa y el primer admin con variables seguras.
4. El admin debe completar 2FA en su primer ingreso.

`DemoDataSeeder` solo corre en `local`/`testing`.

## Health check

- Ruta: `GET /up`
- Debe responder `200`
- No requiere login
- No expone secretos ni hace consultas pesadas

## Cache / optimize

Debe funcionar:

- `php artisan config:cache`
- `php artisan route:cache`
- `php artisan view:cache`
- `php artisan optimize`

Luego, para desarrollo local:

- `php artisan optimize:clear`

## Logs / storage / session

- `storage/` debe ser persistente y escribible.
- `bootstrap/cache` debe ser escribible.
- Sesiones por base de datos o el driver acordado.
- Logs a `stderr/stdout` o mecanismo equivalente del proveedor si está disponible.

## Scheduler / queues

- Scheduler: no usado actualmente.
- Queues: no hay jobs de negocio asíncronos detectados; mantener `sync` salvo que el proveedor defina workers.

## Smoke test inicial

- `GET /up` => 200
- `GET /login` => 200
- login admin => exige 2FA
- dashboard => 200 después de autenticación completa
- crear Cliente, Persona, Proyecto, Asignación y Hora => sin 500
