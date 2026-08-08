# Flujo de Caja Pyme

Aplicacion local Laravel para reemplazar funcionalmente el Excel V3 de flujo de caja.

## Requisitos locales

- PHP 8.3+
- Composer
- MySQL local

## Configuracion

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configura MySQL en `.env`:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flujo_caja_pyme
DB_USERNAME=root
DB_PASSWORD=
```

## Ejecucion local

```bash
php artisan migrate --seed
php artisan finance:import-excel /Users/fpinab/Documents/Flujo_Caja_Pyme_Servicios_Chile_2026_V3.xlsx --dry-run
php artisan finance:import-excel /Users/fpinab/Documents/Flujo_Caja_Pyme_Servicios_Chile_2026_V3.xlsx
php artisan test
php artisan serve
```

El reporte del importador queda en:

```text
storage/app/import_report.json
```

Usuario UAT demo:

```text
admin@flujo.local
```

La contrasena local del usuario admin se toma desde `UAT_ADMIN_PASSWORD` en `.env`.
Si no la defines, el seeder genera una contrasena local y la deja en `storage/app/private/uat_credentials.json`.
