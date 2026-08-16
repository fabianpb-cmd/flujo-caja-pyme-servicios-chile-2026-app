# cPanel production deployment checklist

Modo previsto: cPanel sin SSH, sin Composer remoto y sin Artisan remoto.

## 1. Preflight local

- `php artisan test --group=security`
- `php artisan test`
- `composer audit`
- Build local en modo producción:
  - `CPANEL_RELEASE_MODE=production`
  - `CPANEL_APP_ROOT=/home/CPANEL_USER/apps/APP_NAME`
  - `bash scripts/build-cpanel-release.sh`

## 2. Artefactos esperados

En `dist/cpanel-production/`:

- `app-private.zip`
- `public.zip`
- `production-bootstrap.sql`
- `manifest.json`
- `checksums.txt`
- `.env.production.template`
- `php-runtime-check.php` solo para validación temporal, nunca público por defecto

## 3. Backup previo obligatorio

Confirmar antes de tocar producción:

### A. MySQL

- Export completo por phpMyAdmin
- Estructura + datos
- Triggers/routines si existen
- Nombre sugerido: `production-db-before-YYYYMMDD-HHMM.sql`

### B. App privada

- Backup comprimido de `APP_ROOT`

### C. Public

- Backup comprimido de `PUBLIC_ROOT`

### D. .env

- Backup privado separado del `.env` productivo

Sin estos cuatro backups, no iniciar deploy.

## 4. Variables productivas

Crear `.env` desde `.env.production.template` y completar manualmente:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://PRODUCTION_HOST`
- `APP_KEY` nueva y exclusiva de producción
- credenciales MySQL productivas
- cookies seguras / same-site / timeouts ya endurecidos

Nunca copiar `APP_KEY` local o de staging.

## 5. Primer deploy de producción

Para una base productiva vacía:

1. Crear DB y usuario MySQL exclusivos
2. Importar `production-bootstrap.sql` en phpMyAdmin
3. Subir `app-private.zip` a `APP_ROOT` y extraer
4. Subir `public.zip` a `PUBLIC_ROOT` y extraer
5. Crear `.env` productivo
6. Verificar permisos de escritura en `storage/` y `bootstrap/cache/`
7. Validar `/up` y `/login`

## 6. Actualizaciones futuras sin SSH

Orden estricto:

1. Ventana de mantenimiento
2. Backup DB
3. Backup `APP_ROOT`
4. Backup `PUBLIC_ROOT`
5. Backup `.env`
6. Subir `app-private.zip`
7. Extraer en `APP_ROOT` sin sobrescribir `.env`
8. Aplicar migraciones
9. Subir `public.zip`
10. Extraer en `PUBLIC_ROOT`
11. Validar permisos
12. Smoke tests
13. Eliminar ZIPs del web root
14. Confirmar release

## 7. Migraciones sin SSH

Política segura:

- Preferido: SQL incremental revisado y aplicado por phpMyAdmin
- Si una migración es traducible de forma inequívoca a SQL, generar un `production-migrations.sql` específico para ese release y validarlo primero en staging
- Si una migración depende de lógica PHP, backfill complejo o servicios Laravel, **no desplegarla en producción cPanel sin SSH** hasta disponer de un mecanismo temporal privado y acotado o acceso CLI controlado

No se permite:

- endpoint público genérico de Artisan
- web shell
- deploy.php
- cleanup.php

## 8. Smoke test posterior

Desde Mac:

- `GET /up` => 200
- `GET /login` => 200
- CSS principal => 200
- CSP presente
- login normal opcional
- dashboard => 200
- logout/relogin

Usar `scripts/smoke-production.php` con `scripts/.env.production-smoke`.

## 9. Rollback de código

### Si falla antes de migración DB

- restaurar backup de `APP_ROOT`
- restaurar backup de `PUBLIC_ROOT`
- preservar `.env`
- repetir smoke

### Si falla después de migración DB

- primero validar compatibilidad backward
- si no existe compatibilidad, restaurar DB completa y luego restaurar código anterior

No dejar código y esquema incompatibles.

## 10. Rollback DB por phpMyAdmin

1. Bloquear acceso / mantenimiento
2. Confirmar existencia del backup SQL correcto
3. Importar backup completo en phpMyAdmin
4. Restaurar código compatible
5. Ejecutar smoke tests
6. Reabrir acceso

Nunca borrar la base antes de verificar el backup.

## 11. Checklist de seguridad

- `APP_ENV=production`
- `APP_DEBUG=false`
- `SESSION_SECURE_COOKIE=true`
- `SESSION_HTTP_ONLY=true`
- `SESSION_SAME_SITE=lax`
- `SESSION_LIFETIME=30`
- `SESSION_ABSOLUTE_LIFETIME=480`
- 2FA admin obligatorio
- remember me deshabilitado
- CSP única de la app
- HTTPS obligatorio
- sin diagnósticos públicos
- sin ZIPs públicos permanentes
- sin `.env` público
- `storage/` y `bootstrap/cache/` escribibles
- `composer audit` limpio

## 12. Restore drill previo a producción

Ensayo manual recomendado en staging:

1. Export DB staging
2. Backup código staging
3. Aplicar cambio inocuo controlado
4. Restaurar DB y código
5. Validar `/up`, `/login`, `/dashboard`

No automatizar este drill en producción.
