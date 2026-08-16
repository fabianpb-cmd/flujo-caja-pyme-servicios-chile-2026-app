#!/usr/bin/env php
<?php

declare(strict_types=1);

final class CleanupException extends RuntimeException
{
}

final class CleanupHttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly string $effectiveUrl,
    ) {
    }
}

final class CleanupSession
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $cookieFile,
    ) {
    }

    public function get(string $path, array $query = [], bool $followRedirects = true): CleanupHttpResponse
    {
        $url = $this->absoluteUrl($path);
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $this->request('GET', $url, [], [], $followRedirects);
    }

    public function request(
        string $method,
        string $url,
        array $payload = [],
        array $headers = [],
        bool $followRedirects = true,
    ): CleanupHttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new CleanupException('No se pudo inicializar cURL.');
        }

        $normalizedHeaders = array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent: flujo-caja-staging-cleanup/1.0',
        ], $headers);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HTTPHEADER => $normalizedHeaders,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        if (strtoupper($method) !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = http_build_query($payload);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new CleanupException("Fallo HTTP {$method} {$url}: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        curl_close($handle);

        return new CleanupHttpResponse(
            $status,
            $body,
            self::parseHeaders($rawHeaders),
            $effectiveUrl,
        );
    }

    public function absoluteUrl(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return $path;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    public function cookieFile(): string
    {
        return $this->cookieFile;
    }

    private static function parseHeaders(string $rawHeaders): array
    {
        $blocks = preg_split("/\r\n\r\n|\n\n/", trim($rawHeaders)) ?: [];
        $lastBlock = (string) end($blocks);
        $headers = [];

        foreach (preg_split("/\r\n|\n|\r/", $lastBlock) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}

final class CleanupHtml
{
    public static function document(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    public static function parseForm(string $html, string $baseUrl, callable $selector): array
    {
        $document = self::document($html);
        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//form') ?: [] as $form) {
            if (! $form instanceof DOMElement) {
                continue;
            }

            $action = self::resolveUrl($baseUrl, $form->getAttribute('action') ?: $baseUrl);
            $method = strtoupper($form->getAttribute('method') ?: 'GET');

            if (! $selector($form, $action, $method)) {
                continue;
            }

            return self::extractForm($form, $action, $method);
        }

        throw new CleanupException('No se encontró el formulario esperado.');
    }

    public static function parseDefinitionList(string $html): array
    {
        $document = self::document($html);
        $xpath = new DOMXPath($document);
        $values = [];

        foreach ($xpath->query('//dt') ?: [] as $dt) {
            if (! $dt instanceof DOMElement) {
                continue;
            }

            $label = self::normalizeText($dt->textContent ?? '');
            $dd = $dt->nextSibling;
            while ($dd && (! $dd instanceof DOMElement || $dd->tagName !== 'dd')) {
                $dd = $dd->nextSibling;
            }

            if (! $dd instanceof DOMElement) {
                continue;
            }

            $values[$label] = self::normalizeText($dd->textContent ?? '');
        }

        return $values;
    }

    public static function parseIndexRows(string $html): array
    {
        $document = self::document($html);
        $xpath = new DOMXPath($document);
        $rows = [];

        foreach ($xpath->query('//table//tbody/tr') ?: [] as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $urls = [];
            foreach ($xpath->query('.//*[@href or @action or @formaction]', $row) ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                foreach (['href', 'action', 'formaction'] as $attribute) {
                    $value = trim($node->getAttribute($attribute));
                    if ($value !== '') {
                        $urls[] = $value;
                    }
                }
            }

            $cells = [];
            foreach ($xpath->query('./td', $row) ?: [] as $cell) {
                $cells[] = self::normalizeText($cell->textContent ?? '');
            }

            $rows[] = [
                'text' => implode(' | ', $cells),
                'cells' => $cells,
                'urls' => array_values(array_unique($urls)),
            ];
        }

        return $rows;
    }

    public static function parseUserName(string $html): ?string
    {
        $document = self::document($html);
        $xpath = new DOMXPath($document);
        $node = $xpath->query('//*[contains(@class,"app-user-name")]')?->item(0);

        return $node ? self::normalizeText($node->textContent ?? '') : null;
    }

    public static function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function resolveUrl(string $baseUrl, string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($url, '/');
    }

    private static function extractForm(DOMElement $form, string $action, string $method): array
    {
        $document = $form->ownerDocument;
        $xpath = new DOMXPath($document);
        $fields = [];

        foreach ($xpath->query('.//input|.//textarea|.//select', $form) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            $name = trim($node->getAttribute('name'));
            if ($name === '') {
                continue;
            }

            if ($tag === 'input') {
                $type = strtolower($node->getAttribute('type') ?: 'text');
                if (in_array($type, ['submit', 'button', 'file'], true)) {
                    continue;
                }

                if (in_array($type, ['checkbox', 'radio'], true)) {
                    if ($node->hasAttribute('checked')) {
                        $fields[$name] = $node->getAttribute('value') !== '' ? $node->getAttribute('value') : '1';
                    }

                    continue;
                }

                $fields[$name] = $node->getAttribute('value');
                continue;
            }

            if ($tag === 'textarea') {
                $fields[$name] = $node->textContent ?? '';
                continue;
            }

            if ($tag === 'select') {
                $selected = '';
                foreach ($xpath->query('.//option', $node) ?: [] as $option) {
                    if ($option instanceof DOMElement && $option->hasAttribute('selected')) {
                        $selected = $option->getAttribute('value');
                        break;
                    }
                }

                $fields[$name] = $selected;
            }
        }

        return [
            'action' => $action,
            'method' => $method,
            'fields' => $fields,
        ];
    }
}

final class StagingUatCleanup
{
    private const CONFIRM_TOKEN = 'DELETE-STAGING-UAT';

    /** @var list<string> */
    private array $resources = [
        'cash-movements',
        'time-entries',
        'assignments',
        'budgets',
        'sales-documents',
        'expense-documents',
        'legal-obligations',
        'people',
        'projects',
        'clients',
        'cash-accounts',
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $candidatesByResource = [];

    /** @var array<string, int> */
    private array $countsByResource = [];

    /** @var array<string, bool> */
    private array $runIds = [];

    /** @var list<array<string, mixed>> */
    private array $ambiguous = [];

    /** @var list<array<string, mixed>> */
    private array $eliminable = [];

    /** @var list<array<string, mixed>> */
    private array $deleted = [];

    /** @var list<array<string, mixed>> */
    private array $failed = [];

    private readonly CleanupSession $session;
    private readonly string $baseUrl;
    private readonly bool $execute;
    private readonly ?string $confirmValue;

    public function __construct(
        private readonly array $env,
        private readonly array $argv,
    ) {
        $this->baseUrl = rtrim((string) $env['UAT_BASE_URL'], '/');
        $this->execute = in_array('--execute', $argv, true);
        $this->confirmValue = $this->parseNamedArgument('--confirm');
        $this->session = new CleanupSession($this->baseUrl, $this->tempFile('cleanup-staging-uat-'));
    }

    public function run(): int
    {
        try {
            $this->login();
            $this->scanCandidates();

            if ($this->execute) {
                $this->guardExecution();
                $this->deleteCandidates();
            }

            $post = $this->postChecks();
            $this->renderSummary($post);

            return ($this->execute && count($this->failed) > 0) ? 1 : 0;
        } finally {
            @unlink($this->session->cookieFile());
        }
    }

    private function login(): void
    {
        $loginPage = $this->session->get('/login');
        $this->assertStatus($loginPage, 200, 'GET /login debe responder 200.');

        $form = CleanupHtml::parseForm(
            $loginPage->body,
            $this->baseUrl,
            static fn (DOMElement $form, string $action, string $method): bool => $method === 'POST' && str_contains($action, '/login')
        );

        $payload = $form['fields'];
        $payload['email'] = (string) $this->env['UAT_USER1_EMAIL'];
        $payload['password'] = (string) $this->env['UAT_USER1_PASSWORD'];

        // No seguir el primer redirect. CURLOPT_CUSTOMREQUEST mantiene POST al seguir
        // redirects y algunos servidores terminan recibiendo POST /dashboard (HTTP 405).
        $postResponse = $this->session->request($form['method'], $form['action'], $payload, [], false);

        if (! in_array($postResponse->status, [302, 303], true)) {
            throw new CleanupException('POST /login devolvió HTTP '.$postResponse->status.'; se esperaba 302/303.');
        }

        $location = trim((string) ($postResponse->headers['location'] ?? ''));
        if ($location === '') {
            throw new CleanupException('POST /login no entregó cabecera Location.');
        }

        $redirectUrl = CleanupHtml::resolveUrl($this->baseUrl, $location);
        $redirectPath = parse_url($redirectUrl, PHP_URL_PATH);
        $redirectPath = is_string($redirectPath) ? $redirectPath : '';

        if (str_contains($redirectPath, '/two-factor-challenge')) {
            throw new CleanupException('El cleanup requiere un usuario UAT normal sin challenge 2FA.');
        }

        if ($redirectPath === '/login') {
            throw new CleanupException('Login rechazado; revise las credenciales UAT del archivo .env.staging-uat.');
        }

        // Completar el redirect como GET y validar explícitamente /dashboard.
        $this->session->get($redirectUrl);
        $dashboard = $this->session->get('/dashboard');
        $this->assertStatus($dashboard, 200, 'Dashboard debe responder 200 después del login.');

        $finalPath = parse_url($dashboard->effectiveUrl, PHP_URL_PATH);
        $finalPath = is_string($finalPath) ? $finalPath : '';
        if ($finalPath === '/login' || str_contains($finalPath, '/two-factor-challenge')) {
            throw new CleanupException('No se pudo establecer una sesión UAT autenticada.');
        }

        if (CleanupHtml::parseUserName($dashboard->body) === null) {
            throw new CleanupException('No se pudo confirmar la sesión autenticada en Dashboard.');
        }
    }

    private function scanCandidates(): void
    {
        foreach ($this->resources as $resource) {
            $this->countsByResource[$resource] = 0;
            $this->candidatesByResource[$resource] = [];

            $index = $this->session->get('/operacion/'.$resource, ['q' => 'STG-UAT-']);
            $this->assertStatus($index, 200, "Listado {$resource} debe responder 200.");

            $rows = CleanupHtml::parseIndexRows($index->body);

            foreach ($rows as $row) {
                if (! str_contains($row['text'], 'STG-UAT-')) {
                    continue;
                }

                $showUrl = $this->extractShowUrl($resource, $row['urls']);
                if ($showUrl === null) {
                    $candidate = [
                        'resource' => $resource,
                        'row_text' => $row['text'],
                        'reason' => 'No se encontró URL de detalle para validar evidencia.',
                    ];
                    $this->ambiguous[] = $candidate;
                    $this->candidatesByResource[$resource][] = $candidate;
                    $this->countsByResource[$resource]++;
                    continue;
                }

                $show = $this->session->get($showUrl);
                $this->assertStatus($show, 200, "Detalle {$resource} debe responder 200.");
                $details = CleanupHtml::parseDefinitionList($show->body);
                $joined = $this->joinEvidence($row['text'], $details);
                $runIds = $this->extractRunIds($joined);
                foreach ($runIds as $runId) {
                    $this->runIds[$runId] = true;
                }

                $hasDirectEvidence = $this->hasDirectUatEvidence($details, $row['text']);
                $deleteForm = null;
                if ($hasDirectEvidence) {
                    try {
                        $deleteForm = CleanupHtml::parseForm(
                            $show->body,
                            $this->baseUrl,
                            fn (DOMElement $form, string $action, string $method): bool => $method === 'POST'
                                && str_contains($action, '/operacion/'.$resource.'/')
                                && (($form->getAttribute('method') ?: 'POST') !== '')
                                && str_contains(json_encode($form->textContent), 'Eliminar')
                        );
                    } catch (Throwable) {
                        $deleteForm = null;
                    }
                }

                $candidate = [
                    'resource' => $resource,
                    'show_url' => $showUrl,
                    'row_text' => $row['text'],
                    'details' => $details,
                    'run_ids' => $runIds,
                    'delete_form' => $deleteForm,
                ];

                if (! $hasDirectEvidence) {
                    $candidate['reason'] = 'Coincide en búsqueda, pero no hay evidencia inequívoca en campos visibles.';
                    $this->ambiguous[] = $candidate;
                } elseif ($deleteForm === null) {
                    $candidate['reason'] = 'Registro UAT confirmado, pero no expone eliminación segura por rutas normales.';
                    $this->ambiguous[] = $candidate;
                } else {
                    $this->eliminable[] = $candidate;
                }

                $this->candidatesByResource[$resource][] = $candidate;
                $this->countsByResource[$resource]++;
            }
        }
    }

    private function guardExecution(): void
    {
        if (! $this->execute) {
            return;
        }

        if ($this->confirmValue !== self::CONFIRM_TOKEN) {
            throw new CleanupException('Falta confirmación exacta --confirm=DELETE-STAGING-UAT.');
        }
    }

    private function deleteCandidates(): void
    {
        foreach ($this->resources as $resource) {
            foreach ($this->eliminable as $candidate) {
                if ($candidate['resource'] !== $resource) {
                    continue;
                }

                try {
                    $form = $candidate['delete_form'];
                    // Igual que en login: no seguir redirects conservando CUSTOMREQUEST POST.
                    $response = $this->session->request($form['method'], $form['action'], $form['fields'], [], false);

                    if (! in_array($response->status, [200, 302, 303, 204], true)) {
                        throw new CleanupException("HTTP {$response->status} al eliminar {$resource}.");
                    }

                    // Si Laravel redirige tras eliminar, seguir Location explícitamente como GET.
                    if (in_array($response->status, [302, 303], true)) {
                        $location = trim((string) ($response->headers['location'] ?? ''));
                        if ($location !== '') {
                            $follow = $this->session->get(CleanupHtml::resolveUrl($this->baseUrl, $location));
                            if ($follow->status >= 500) {
                                throw new CleanupException("HTTP {$follow->status} después de eliminar {$resource}.");
                            }
                        }
                    }

                    $this->deleted[] = [
                        'resource' => $resource,
                        'show_url' => $candidate['show_url'],
                        'run_ids' => $candidate['run_ids'],
                    ];
                } catch (Throwable $throwable) {
                    $this->failed[] = [
                        'resource' => $resource,
                        'show_url' => $candidate['show_url'] ?? null,
                        'error' => $throwable->getMessage(),
                    ];
                }
            }
        }
    }

    private function postChecks(): array
    {
        $remaining = 0;
        foreach ($this->resources as $resource) {
            $index = $this->session->get('/operacion/'.$resource, ['q' => 'STG-UAT-']);
            if ($index->status === 200) {
                foreach (CleanupHtml::parseIndexRows($index->body) as $row) {
                    if (str_contains($row['text'], 'STG-UAT-')) {
                        $remaining++;
                    }
                }
            }
        }

        $up = $this->session->get('/up', [], false);
        $dashboard = $this->session->get('/dashboard', [], false);

        // /login se comprueba sin cookies: una sesión autenticada puede redirigir /login
        // legítimamente y producir un falso FAIL.
        $publicCookie = $this->tempFile('cleanup-public-check-');
        try {
            $publicSession = new CleanupSession($this->baseUrl, $publicCookie);
            $login = $publicSession->get('/login', [], false);
        } finally {
            @unlink($publicCookie);
        }

        return [
            'remaining' => $remaining,
            '/up' => $up->status === 200 ? 'PASS' : 'FAIL',
            '/login' => $login->status === 200 ? 'PASS' : 'FAIL',
            '/dashboard' => $dashboard->status === 200 && CleanupHtml::parseUserName($dashboard->body) !== null ? 'PASS' : 'FAIL',
        ];
    }

    private function renderSummary(array $post): void
    {
        $runIds = array_keys($this->runIds);
        sort($runIds);

        echo "STAGING UAT CLEANUP\n\n";
        echo 'Run IDs encontrados: '.count($runIds)."\n";
        if ($runIds !== []) {
            foreach ($runIds as $runId) {
                echo ' - '.$runId."\n";
            }
        }

        $candidateCount = array_sum($this->countsByResource);
        echo 'Registros candidatos: '.$candidateCount."\n";
        echo 'Ambiguos: '.count($this->ambiguous)."\n";
        echo 'Eliminables: '.count($this->eliminable)."\n\n";
        echo "Por recurso:\n";
        foreach ($this->resources as $resource) {
            echo str_pad($resource, 20, ' ', STR_PAD_RIGHT).' '.$this->countsByResource[$resource]."\n";
        }

        echo "\nEjecución: ".($this->execute ? 'EXECUTE' : 'DRY-RUN')."\n";
        if (! $this->execute) {
            echo "DRY-RUN: NINGÚN DATO ELIMINADO\n";
        } else {
            echo 'Eliminados: '.count($this->deleted)."\n";
            echo 'Fallidos: '.count($this->failed)."\n";
            echo 'Restantes STG-UAT: '.$post['remaining']."\n";
            echo 'Requiere revisión: '.count($this->ambiguous)."\n";
        }

        echo "/up: {$post['/up']}\n";
        echo "/login: {$post['/login']}\n";
        echo "/dashboard: {$post['/dashboard']}\n";

        $result = ($this->execute && count($this->failed) > 0) ? 'FAIL' : 'PASS';
        echo "RESULTADO: {$result}\n";
    }

    private function hasDirectUatEvidence(array $details, string $rowText): bool
    {
        $interestingLabels = [
            'Nombre',
            'Razon social',
            'Proyecto/Servicio',
            'Nombres',
            'Referencia',
            'Notas',
            'Fuente',
            'Observaciones calculo',
            'Correo',
            'Proveedor',
            'Contraparte',
        ];

        foreach ($details as $label => $value) {
            if ($value === '' || $value === '—') {
                continue;
            }

            if (str_contains($value, 'STG-UAT-')) {
                return true;
            }

            if ($label === 'Correo' && str_contains($value, 'stg-uat')) {
                return true;
            }

            if (in_array($label, $interestingLabels, true) && str_contains($value, 'UAT')) {
                if (str_contains($value, 'STG-UAT-')) {
                    return true;
                }
            }
        }

        return str_contains($rowText, 'STG-UAT-');
    }

    private function joinEvidence(string $rowText, array $details): string
    {
        return $rowText.' '.implode(' ', array_map(
            static fn (string $label, string $value): string => $label.' '.$value,
            array_keys($details),
            array_values($details),
        ));
    }

    /**
     * @return list<string>
     */
    private function extractRunIds(string $text): array
    {
        preg_match_all('/STG-UAT-\d{8}-\d{6}/', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function extractShowUrl(string $resource, array $urls): ?string
    {
        foreach ($urls as $url) {
            if (preg_match('#/operacion/'.preg_quote($resource, '#').'/\d+$#', $url) === 1) {
                return $url;
            }
        }

        return null;
    }

    private function parseNamedArgument(string $prefix): ?string
    {
        foreach ($this->argv as $arg) {
            if (str_starts_with($arg, $prefix.'=')) {
                return substr($arg, strlen($prefix) + 1);
            }
        }

        return null;
    }

    private function tempFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if ($file === false) {
            throw new CleanupException('No se pudo crear archivo temporal.');
        }

        return $file;
    }

    private function assertStatus(CleanupHttpResponse $response, int $expected, string $message): void
    {
        if ($response->status !== $expected) {
            throw new CleanupException($message." HTTP {$response->status}.");
        }
    }
}

function cleanupLoadEnv(string $path): array
{
    if (! is_file($path)) {
        throw new CleanupException("No existe archivo de credenciales: {$path}");
    }

    $values = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = $value;
    }

    foreach (['UAT_BASE_URL', 'UAT_USER1_EMAIL', 'UAT_USER1_PASSWORD'] as $required) {
        if (! array_key_exists($required, $values) || trim((string) $values[$required]) === '') {
            throw new CleanupException("Falta variable requerida {$required} en scripts/.env.staging-uat.");
        }
    }

    return $values;
}

function assertAuthorizedStagingHost(string $baseUrl): void
{
    $normalized = rtrim($baseUrl, '/');
    $parts = parse_url($normalized);

    if (
        ! is_array($parts)
        || ($parts['scheme'] ?? null) !== 'https'
        || ($parts['host'] ?? null) !== 'licitaciones.tdatconsulting.cl'
        || isset($parts['port'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || (($parts['path'] ?? '') !== '')
        || (($parts['query'] ?? '') !== '')
        || (($parts['fragment'] ?? '') !== '')
    ) {
        fwrite(STDERR, "ERROR: runner autorizado solo para staging.\n");
        exit(1);
    }
}

$scriptDir = __DIR__;
$envPath = $scriptDir.'/.env.staging-uat';

try {
    if (! extension_loaded('curl') || ! extension_loaded('dom')) {
        throw new CleanupException('Este runner requiere extensiones PHP curl y dom.');
    }

    $env = cleanupLoadEnv($envPath);
    assertAuthorizedStagingHost((string) $env['UAT_BASE_URL']);
    $cleanup = new StagingUatCleanup($env, $argv);
    exit($cleanup->run());
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
