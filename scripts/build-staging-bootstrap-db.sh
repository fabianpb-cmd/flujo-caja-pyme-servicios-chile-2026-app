#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
dist_root="${DIST_ROOT:-$repo_root/dist/cpanel-staging}"
sql_output_name="${SQL_OUTPUT_NAME:-staging-bootstrap.sql}"
env_file="$repo_root/.env"
bootstrap_profile="${BOOTSTRAP_PROFILE:-$( [[ "$sql_output_name" == production-* ]] && echo production || echo staging )}"

case "$bootstrap_profile" in
  staging)
    default_company_name="Empresa Staging"
    default_company_code="STAGING"
    default_admin_name="Administrador inicial"
    default_admin_email="admin@STAGING_HOST"
    ;;
  production)
    default_company_name="Empresa Producción"
    default_company_code="PROD"
    default_admin_name="Administrador inicial"
    default_admin_email="admin@PRODUCTION_HOST"
    ;;
  *)
    echo "BOOTSTRAP_PROFILE debe ser staging o production." >&2
    exit 1
    ;;
esac

mkdir -p "$dist_root"

read_env() {
  local key="$1"
  local default="${2:-}"

  php -r '
    $file = $argv[1];
    $key = $argv[2];
    $default = $argv[3];
    if (!is_file($file)) {
        echo $default;
        exit(0);
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === "" || str_starts_with($line, "#") || !str_contains($line, "=")) {
            continue;
        }
        [$currentKey, $value] = explode("=", $line, 2);
        if (trim($currentKey) !== $key) {
            continue;
        }
        $value = trim($value);
        if ($value !== "" && (($value[0] === "\"" && str_ends_with($value, "\"")) || ($value[0] === "'"'"'" && str_ends_with($value, "'"'"'")))) {
            $value = substr($value, 1, -1);
        }
        echo $value;
        exit(0);
    }
    echo $default;
  ' "$env_file" "$key" "$default"
}

db_host="${DB_HOST:-$(read_env DB_HOST 127.0.0.1)}"
db_port="${DB_PORT:-$(read_env DB_PORT 3306)}"
db_username="${DB_USERNAME:-$(read_env DB_USERNAME root)}"
db_password="${DB_PASSWORD:-$(read_env DB_PASSWORD)}"
db_name="${DB_DATABASE:-flujo_caja_staging_build}"
bootstrap_admin_email="${BOOTSTRAP_ADMIN_EMAIL:-$default_admin_email}"
bootstrap_admin_password="${BOOTSTRAP_ADMIN_PASSWORD:-}"

if [ -z "$bootstrap_admin_password" ] || [ "$bootstrap_admin_password" = "DEFINE_THIS_OUTSIDE_GIT" ]; then
  echo "BOOTSTRAP_ADMIN_PASSWORD debe definirse fuera de Git para generar el bootstrap SQL." >&2
  exit 1
fi

case "$db_name" in
  flujo_caja_staging_build|flujo_caja_staging_build_*) ;;
  *)
    echo "DB_DATABASE debe ser flujo_caja_staging_build o un sufijo seguro equivalente." >&2
    exit 1
    ;;
esac

for bin in php mysql mysqldump; do
  command -v "$bin" >/dev/null 2>&1 || {
    echo "Falta el binario requerido: $bin" >&2
    exit 1
  }
done

export MYSQL_PWD="$db_password"
mysql --protocol=tcp -h "$db_host" -P "$db_port" -u "$db_username" -e "CREATE DATABASE IF NOT EXISTS \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

seed_env=(
  APP_ENV=production
  DB_CONNECTION=mysql
  DB_HOST="$db_host"
  DB_PORT="$db_port"
  DB_DATABASE="$db_name"
  DB_USERNAME="$db_username"
  DB_PASSWORD="$db_password"
  BOOTSTRAP_COMPANY_NAME="${BOOTSTRAP_COMPANY_NAME:-$default_company_name}"
  BOOTSTRAP_COMPANY_CODE="${BOOTSTRAP_COMPANY_CODE:-$default_company_code}"
  BOOTSTRAP_ADMIN_NAME="${BOOTSTRAP_ADMIN_NAME:-$default_admin_name}"
  BOOTSTRAP_ADMIN_EMAIL="${BOOTSTRAP_ADMIN_EMAIL:-$default_admin_email}"
  BOOTSTRAP_ADMIN_PASSWORD="${BOOTSTRAP_ADMIN_PASSWORD:-DEFINE_THIS_OUTSIDE_GIT}"
)

env "${seed_env[@]}" php artisan migrate:fresh --force
env "${seed_env[@]}" php artisan db:seed --force

hash_validator_base="$(mktemp "${TMPDIR:-/tmp}/cpanel-bootstrap-hash-validate.XXXXXX")"
hash_validator="${hash_validator_base}.php"
mv "$hash_validator_base" "$hash_validator"
cat > "$hash_validator" <<'PHP'
<?php
$repoRoot = $argv[1];
require $repoRoot."/vendor/autoload.php";
$app = require $repoRoot."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = getenv("BOOTSTRAP_ADMIN_EMAIL");
$rawPassword = getenv("BOOTSTRAP_ADMIN_PASSWORD");
$hash = Illuminate\Support\Facades\DB::table("users")->where("email", $email)->value("password");

if (!is_string($hash) || $hash === "") {
    fwrite(STDERR, "No se pudo validar el hash bcrypt del admin bootstrap.".PHP_EOL);
    exit(1);
}

if (str_contains($hash, "\\")) {
    fwrite(STDERR, "El hash bcrypt del admin bootstrap está escapado incorrectamente.".PHP_EOL);
    exit(1);
}

if (!str_starts_with($hash, '$2')) {
    fwrite(STDERR, "El hash bcrypt del admin bootstrap no tiene formato válido.".PHP_EOL);
    exit(1);
}

if (!Illuminate\Support\Facades\Hash::check($rawPassword, $hash)) {
    fwrite(STDERR, "Hash::check falló para el admin bootstrap.".PHP_EOL);
    exit(1);
}
PHP
env \
  APP_ENV=production \
  DB_CONNECTION=mysql \
  DB_HOST="$db_host" \
  DB_PORT="$db_port" \
  DB_DATABASE="$db_name" \
  DB_USERNAME="$db_username" \
  DB_PASSWORD="$db_password" \
  BOOTSTRAP_ADMIN_EMAIL="$bootstrap_admin_email" \
  BOOTSTRAP_ADMIN_PASSWORD="$bootstrap_admin_password" \
  php "$hash_validator" "$repo_root"
rm -f "$hash_validator"

sql_output="$dist_root/$sql_output_name"
mysqldump \
  --protocol=tcp \
  --default-character-set=utf8mb4 \
  --single-transaction \
  --routines \
  --triggers \
  --skip-comments \
  --no-tablespaces \
  --set-gtid-purged=OFF \
  -h "$db_host" \
  -P "$db_port" \
  -u "$db_username" \
  "$db_name" > "$sql_output"

sql_validator_base="$(mktemp "${TMPDIR:-/tmp}/cpanel-bootstrap-sql-validate.XXXXXX")"
sql_validator="${sql_validator_base}.php"
mv "$sql_validator_base" "$sql_validator"
cat > "$sql_validator" <<'PHP'
<?php
$sqlFile = $argv[1];
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer el dump SQL.".PHP_EOL);
    exit(1);
}

foreach (["admin@STAGING_HOST", "admin@PRODUCTION_HOST", "Empresa Staging", "Empresa Producción", "Jaime", "Emilio", "IRIS Consultor", "Empresa Demo"] as $forbidden) {
    if (str_contains($sql, $forbidden)) {
        fwrite(STDERR, "El dump SQL contiene datos demo o marcadores no permitidos: ".$forbidden.PHP_EOL);
        exit(1);
    }
}

if (!str_contains($sql, '$2y$') && !str_contains($sql, '$2b$') && !str_contains($sql, '$2a$')) {
    fwrite(STDERR, "El dump SQL no contiene un hash bcrypt válido.".PHP_EOL);
    exit(1);
}

if (str_contains($sql, '\\$2y\\$') || str_contains($sql, '\\$2b\\$') || str_contains($sql, '\\$2a\\$')) {
    fwrite(STDERR, "El dump SQL contiene bcrypt escapado incorrectamente.".PHP_EOL);
    exit(1);
}
PHP
php "$sql_validator" "$sql_output"
rm -f "$sql_validator"

echo "$sql_output"
