# cPanel staging deployment checklist

Mode B: sin SSH, sin Composer remoto y sin Artisan remoto.

## A. cPanel

- Usar subdominio `STAGING_HOST`.
- Mantener Laravel fuera de `public_html`.
- Preferir `APP_ROOT` fuera del web root.
- Si cPanel permite apuntar el subdominio directo a `APP_ROOT/public`, usar esa opción.
- El hosting heredó una CSP desde `/home/tdatcons/public_html/.htaccess`; debe quedar condicionada al host principal, no al staging.

## B. Archivos

- Subir los artefactos construidos localmente.
- No subir `.env` local, tests, docs no necesarios, dumps ni datos UAT.
- `vendor/` va dentro de `app-private.zip`.

## C. PHP

- PHP requerido: 8.4 en este hosting.
- Extensiones mínimas: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pcre`, `pdo`, `session`, `tokenizer`, `xml`

## D. MySQL

- Crear base y usuario exclusivos para staging.
- No usar `root`.
- No reutilizar bases locales ni de producción.

## E. .env

- Generar `.env` en el servidor desde `.env.staging.template`.
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://STAGING_HOST`
- `APP_KEY` nueva para staging.

## F. Migraciones

- En cPanel sin SSH no se ejecuta Artisan remoto. La base se prepara con `staging-bootstrap.sql`.

## G. Parámetros

- `staging-bootstrap.sql` ya incluye schema, catálogos, parámetros, empresa bootstrap y admin bootstrap.
- `DemoDataSeeder` no forma parte del paquete staging.

## H. Admin

- Definir `BOOTSTRAP_ADMIN_PASSWORD` fuera de Git.
- El primer login debe exigir 2FA.

## I. Storage

- Preservar `storage/` entre despliegues.
- Mantener escribibles `storage/` y `bootstrap/cache/`.
- `storage:link` no es necesario para el runtime actual.

## J. SSL

- HTTPS obligatorio en staging.
- SSL gestionado por cPanel/AutoSSL.

## K. Optimize

- Orden recomendado:
- No subir caches de Laravel generadas en Mac.
- Laravel debe funcionar sin Artisan remoto.

## L. Smoke test

- `GET /up` => 200
- `GET /login` => 200
- login admin => 2FA obligatorio
- dashboard => 200 tras completar 2FA
- crear Cliente, Persona, Proyecto, Asignación y Hora => sin 500

## Actualizaciones sin SSH

- Opción futura preferida: cron de cPanel que ejecute Artisan si el hosting permite PHP CLI.
- Fallback manual: backup de la base, SQL de migración revisado y phpMyAdmin.
