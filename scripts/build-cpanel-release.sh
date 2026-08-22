#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
release_mode="${CPANEL_RELEASE_MODE:-staging}"
case "$release_mode" in
  staging|production) ;;
  *)
    echo "CPANEL_RELEASE_MODE debe ser staging o production." >&2
    exit 1
    ;;
esac

dist_root="$repo_root/dist/cpanel-$release_mode"
temp_root="$(mktemp -d "${TMPDIR:-/tmp}/cpanel-release.XXXXXX")"
private_root="$temp_root/app-private"
public_root="$temp_root/public"
bootstrap_check="$dist_root/php-runtime-check.php"
app_root="${CPANEL_APP_ROOT:-/home/tdatcons/apps/flujo-caja-$release_mode}"
env_template=".env.${release_mode}.template"
sql_output_name="${CPANEL_SQL_OUTPUT_NAME:-${release_mode}-bootstrap.sql}"
manifest_file="$dist_root/manifest.json"
checksums_file="$dist_root/checksums.txt"
release_id="cpanel-${release_mode}-$(date +%Y%m%d-%H%M%S)"

cleanup() {
  rm -rf "$temp_root"
}

trap cleanup EXIT

mkdir -p "$dist_root" "$private_root" "$public_root"

command -v rsync >/dev/null 2>&1 || {
  echo "Falta rsync en el sistema." >&2
  exit 1
}

validate_dotenv_template() {
  php -r '
    $repoRoot = $argv[1];
    $template = $argv[2];
    require $repoRoot."/vendor/autoload.php";

    try {
        Dotenv\Dotenv::createImmutable($repoRoot, $template)->load();
    } catch (Throwable $e) {
        fwrite(STDERR, "No se puede parsear ".$template.": ".$e->getMessage().PHP_EOL);
        exit(1);
    }
  ' "$repo_root" "$env_template"
}

validate_public_index_template() {
  php -r '
    $repoRoot = $argv[1];
    $index = $repoRoot."/public/index.php";
    if (!is_file($index)) {
        fwrite(STDERR, "No existe public/index.php en el repositorio.".PHP_EOL);
        exit(1);
    }
    $contents = file_get_contents($index);
    if ($contents === false || !str_contains($contents, "usePublicPath(__DIR__)")) {
        fwrite(STDERR, "public/index.php debe incluir usePublicPath(__DIR__).".PHP_EOL);
        exit(1);
    }
  ' "$repo_root"
}

assert_zip_contains() {
  local zip_file="$1"
  local expected="$2"
  local label="$3"
  local entries

  entries="$(unzip -Z1 "$zip_file")"

  if ! grep -Fxq "$expected" <<<"$entries"; then
    echo "$label debe contener $expected" >&2
    exit 1
  fi
}

assert_zip_not_contains() {
  local zip_file="$1"
  local pattern="$2"
  local label="$3"

  if unzip -Z1 "$zip_file" | grep -Eq "$pattern"; then
    echo "$label contiene rutas no permitidas" >&2
    exit 1
  fi
}

scan_zip_for_forbidden_strings() {
  local zip_file="$1"
  local label="$2"
  local patterns='(/Users/|/Applications/|127\.0\.0\.1:8000|/private/var/folders/|/var/folders/)'

  while IFS= read -r entry; do
    case "$entry" in
      *.php|*.blade.php|*.json|*.xml|*.txt|*.md|*.css|*.js|*.html|*.htaccess|*.env|*.sql)
        if unzip -p "$zip_file" "$entry" | LC_ALL=C grep -a -nE "$patterns" >/dev/null; then
          echo "Ruta local bloqueante detectada en $label: $entry" >&2
          exit 1
        fi
      ;;
    esac
  done < <(unzip -Z1 "$zip_file")
}

validate_env_template() {
  php -r '
    $repoRoot = $argv[1];
    $template = $argv[2];
    require $repoRoot."/vendor/autoload.php";

    try {
        $dotenv = Dotenv\Dotenv::createMutable($repoRoot, $template);
        $values = $dotenv->load();
    } catch (Throwable $e) {
        fwrite(STDERR, "No se puede parsear ".$template.": ".$e->getMessage().PHP_EOL);
        exit(1);
    }

    foreach (["APP_NAME", "APP_ENV", "APP_URL", "DB_CONNECTION", "DB_HOST", "SESSION_LIFETIME", "BOOTSTRAP_COMPANY_NAME", "BOOTSTRAP_ADMIN_EMAIL"] as $key) {
        if (!isset($values[$key]) || trim((string) $values[$key]) === "") {
            fwrite(STDERR, "Falta variable requerida en ".$template.": $key".PHP_EOL);
            exit(1);
        }
    }
  ' "$repo_root" "$env_template"
}

validate_sql_dump() {
  local validator
  validator="$(mktemp "${TMPDIR:-/tmp}/cpanel-sql-validate.XXXXXX.php")"

  cat > "$validator" <<'PHP'
<?php
$sqlFile = $argv[1];
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer el dump SQL.".PHP_EOL);
    exit(1);
}

foreach (["Empresa Staging", "Jaime", "Emilio", "IRIS Consultor", "Empresa Demo"] as $forbidden) {
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

  php "$validator" "$1"
  rm -f "$validator"
}

generate_manifest() {
  php -r '
    $repoRoot = $argv[1];
    $manifest = $argv[2];
    $releaseId = $argv[3];
    $mode = $argv[4];
    $appZip = $argv[5];
    $publicZip = $argv[6];
    $sqlName = $argv[7];
    $requiresDbMigration = $argv[8] === "true";

    $gitCommit = trim((string) shell_exec("cd ".escapeshellarg($repoRoot)." && git rev-parse --short HEAD 2>/dev/null"));
    $composer = json_decode((string) file_get_contents($repoRoot."/composer.json"), true);
    $lock = json_decode((string) file_get_contents($repoRoot."/composer.lock"), true);
    $laravelVersion = null;
    foreach (($lock["packages"] ?? []) as $package) {
        if (($package["name"] ?? null) === "laravel/framework") {
            $laravelVersion = $package["version"] ?? null;
            break;
        }
    }

    $migrationFiles = array_map("basename", glob($repoRoot."/database/migrations/*.php") ?: []);
    sort($migrationFiles);

    $payload = [
        "release_id" => $releaseId,
        "timestamp" => gmdate(DATE_ATOM),
        "mode" => $mode,
        "git_commit" => $gitCommit !== "" ? $gitCommit : null,
        "php_min" => "8.4",
        "laravel_version" => $laravelVersion,
        "migrations" => $migrationFiles,
        "sha256" => [
            "app-private.zip" => hash_file("sha256", $appZip),
            "public.zip" => hash_file("sha256", $publicZip),
        ],
        "bootstrap_sql" => is_file(dirname($manifest)."/".$sqlName) ? $sqlName : null,
        "requires_db_migration" => $requiresDbMigration,
    ];

    file_put_contents($manifest, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
  ' "$repo_root" "$manifest_file" "$release_id" "$release_mode" "$dist_root/app-private.zip" "$dist_root/public.zip" "$sql_output_name" "$( [ "$release_mode" = "production" ] && echo true || echo false )"
}

find "$dist_root" -mindepth 1 -maxdepth 1 -exec rm -rf {} +

validate_env_template
validate_public_index_template

rsync -a \
  --exclude '.git/' \
  --exclude '.DS_Store' \
  --exclude '.env' \
  --exclude '.env.*' \
  --exclude 'dist/' \
  --exclude '.phpunit.result.cache' \
  --exclude 'README.md' \
  --exclude 'CLAUDE.md' \
  --exclude 'docs/' \
  --exclude 'node_modules/' \
  --exclude 'tests/' \
  --exclude 'phpunit.xml' \
  --exclude 'phpunit.mysql.xml' \
  --exclude 'scripts/' \
  --exclude 'public/' \
  --exclude 'database/*.sqlite' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/app/import_report.json' \
  --exclude 'storage/app/private/import_report.json' \
  --exclude 'storage/app/private/uat_credentials.json' \
  --exclude 'storage/app/private/UAT_ADMIN_PASSWORD_FALLBACK.txt' \
  --exclude 'database/seeders/DemoDataSeeder.php' \
  --exclude 'bootstrap/cache/*.php' \
  "$repo_root/" "$private_root/"

find "$private_root/database" -name '*.sqlite' -delete
find "$private_root" -name '.DS_Store' -delete
find "$private_root/storage/framework/cache" -type f ! -name '.gitignore' -delete
find "$private_root/storage/framework/sessions" -type f ! -name '.gitignore' -delete
find "$private_root/storage/framework/views" -type f ! -name '.gitignore' -delete
find "$private_root/storage/logs" -type f ! -name '.gitignore' -delete
find "$private_root/storage/app/private" -type f ! -name '.gitignore' -delete
find "$private_root/storage/app" -maxdepth 1 -type f ! -name '.gitignore' -delete
find "$private_root/vendor" -type f \( -iname 'README*' -o -iname 'CHANGELOG*' -o -iname 'UPGRADE*' -o -iname 'CONTRIBUTING*' -o -iname '*.md' \) -delete
find "$private_root/bootstrap/cache" -type f ! -name '.gitignore' -delete

(cd "$private_root" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist)

find "$private_root/database" -name '*.sqlite' -delete
find "$private_root/storage/framework/cache" -type f ! -name '.gitignore' -delete
find "$private_root/storage/framework/sessions" -type f ! -name '.gitignore' -delete
find "$private_root/storage/framework/views" -type f ! -name '.gitignore' -delete
find "$private_root/storage/logs" -type f ! -name '.gitignore' -delete
find "$private_root/storage/app/private" -type f ! -name '.gitignore' -delete
find "$private_root/storage/app" -maxdepth 1 -type f ! -name '.gitignore' -delete
find "$private_root/vendor" -type f \( -iname 'README*' -o -iname 'CHANGELOG*' -o -iname 'UPGRADE*' -o -iname 'CONTRIBUTING*' -o -iname '*.md' \) -delete
find "$private_root/bootstrap/cache" -type f ! -name '.gitignore' -delete

cat > "$bootstrap_check" <<'PHP'
<?php
echo "DELETE AFTER VALIDATION\n\n";

$checks = [
    'PHP version' => version_compare(PHP_VERSION, '8.4.0', '>='),
    'extension ctype' => extension_loaded('ctype'),
    'extension curl' => extension_loaded('curl'),
    'extension dom' => extension_loaded('dom'),
    'extension fileinfo' => extension_loaded('fileinfo'),
    'extension filter' => extension_loaded('filter'),
    'extension hash' => extension_loaded('hash'),
    'extension intl' => extension_loaded('intl'),
    'extension mbstring' => extension_loaded('mbstring'),
    'extension openssl' => extension_loaded('openssl'),
    'extension pcre' => extension_loaded('pcre'),
    'extension pdo' => extension_loaded('pdo'),
    'extension session' => extension_loaded('session'),
    'extension tokenizer' => extension_loaded('tokenizer'),
    'extension xml' => extension_loaded('xml'),
];

foreach ($checks as $label => $ok) {
    echo $label.': '.($ok ? "OK" : "ERROR").PHP_EOL;
}
PHP

cp "$repo_root/$env_template" "$dist_root/$env_template"

rm -f "$dist_root/app-private.zip" "$dist_root/public.zip" "$checksums_file" "$manifest_file"

(cd "$private_root" && zip -qr "$dist_root/app-private.zip" .)

rsync -a "$repo_root/public/" "$public_root/"
cat > "$public_root/index.php" <<PHP
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists(\$maintenance = '$app_root/storage/framework/maintenance.php')) {
    require \$maintenance;
}

require '$app_root/vendor/autoload.php';

/** @var Application \$app */
\$app = require_once '$app_root/bootstrap/app.php';
\$app->usePublicPath(__DIR__);

\$app->handleRequest(Request::capture());
PHP

(cd "$public_root" && zip -qr "$dist_root/public.zip" .)

DIST_ROOT="$dist_root" SQL_OUTPUT_NAME="$sql_output_name" "$script_dir/build-staging-bootstrap-db.sh" >/dev/null

assert_zip_contains "$dist_root/app-private.zip" "vendor/autoload.php" "app-private.zip"
assert_zip_contains "$dist_root/app-private.zip" "bootstrap/app.php" "app-private.zip"
assert_zip_contains "$dist_root/public.zip" "index.php" "public.zip"
assert_zip_contains "$dist_root/public.zip" ".htaccess" "public.zip"
assert_zip_contains "$dist_root/public.zip" "css/app-dashboard.css" "public.zip"
assert_zip_not_contains "$dist_root/app-private.zip" '(^|/)\.env($|[./])' "app-private.zip"
assert_zip_not_contains "$dist_root/app-private.zip" '(^|/)tests/' "app-private.zip"
assert_zip_not_contains "$dist_root/app-private.zip" '(^|/)database/seeders/DemoDataSeeder\.php$' "app-private.zip"
assert_zip_not_contains "$dist_root/public.zip" '(^|/)vendor/|(^|/)app/|(^|/)config/|(^|/)database/|(^|/)storage/' "public.zip"

if ! unzip -p "$dist_root/public.zip" index.php | grep -Fq 'usePublicPath(__DIR__)'; then
  echo "public/index.php debe incluir usePublicPath(__DIR__)" >&2
  exit 1
fi

scan_zip_for_forbidden_strings "$dist_root/app-private.zip" "app-private.zip"
scan_zip_for_forbidden_strings "$dist_root/public.zip" "public.zip"
validate_sql_dump "$dist_root/$sql_output_name"
generate_manifest

(
  cd "$dist_root"
  shasum -a 256 app-private.zip public.zip "$(basename "$sql_output_name")" manifest.json > "$(basename "$checksums_file")"
)

printf 'app-private.zip\npublic.zip\n%s\nmanifest.json\nchecksums.txt\nPHP runtime check: %s\n' "$sql_output_name" "$bootstrap_check"
