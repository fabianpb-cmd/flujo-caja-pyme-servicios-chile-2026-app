#!/usr/bin/env php
<?php

declare(strict_types=1);

final class ProductionSmokeException extends RuntimeException
{
}

final class ProductionSmokeResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly string $effectiveUrl,
    ) {
    }
}

final class ProductionSmokeSession
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $cookieFile,
    ) {
    }

    public function get(string $path, bool $followRedirects = true): ProductionSmokeResponse
    {
        return $this->request('GET', $this->absoluteUrl($path), [], [], $followRedirects);
    }

    public function request(
        string $method,
        string $url,
        array $payload = [],
        array $headers = [],
        bool $followRedirects = true,
    ): ProductionSmokeResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ProductionSmokeException('No se pudo inicializar cURL.');
        }

        $requestHeaders = array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent: flujo-caja-production-smoke/1.0',
        ], $headers);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 20,
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
            throw new ProductionSmokeException("Fallo HTTP {$method} {$url}: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        curl_close($handle);

        return new ProductionSmokeResponse($status, $body, self::parseHeaders($rawHeaders), $effectiveUrl);
    }

    private function absoluteUrl(string $path): string
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
        $last = (string) end($blocks);
        $headers = [];
        foreach (preg_split("/\r\n|\n|\r/", $last) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}

function smokeLoadEnv(string $path): array
{
    if (! is_file($path)) {
        throw new ProductionSmokeException("No existe {$path}");
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }

    if (empty($values['PRODUCTION_BASE_URL'])) {
        throw new ProductionSmokeException('Falta PRODUCTION_BASE_URL.');
    }

    return $values;
}

function smokeParseForm(string $html, string $baseUrl, callable $selector): array
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    foreach ($xpath->query('//form') ?: [] as $form) {
        if (! $form instanceof DOMElement) {
            continue;
        }
        $action = $form->getAttribute('action');
        $action = preg_match('/^https?:\/\//i', $action) ? $action : rtrim($baseUrl, '/').'/'.ltrim($action, '/');
        $method = strtoupper($form->getAttribute('method') ?: 'GET');
        if (! $selector($form, $action, $method)) {
            continue;
        }

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
                        $fields[$name] = $node->getAttribute('value') ?: '1';
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

        return ['action' => $action, 'method' => $method, 'fields' => $fields];
    }

    throw new ProductionSmokeException('No se encontró el formulario esperado.');
}

$envPath = __DIR__.'/.env.production-smoke';
$cookieFile = tempnam(sys_get_temp_dir(), 'production-smoke-');
if ($cookieFile === false) {
    fwrite(STDERR, "[ERROR] No se pudo crear cookie jar temporal.\n");
    exit(1);
}

try {
    $env = smokeLoadEnv($envPath);
    $session = new ProductionSmokeSession(rtrim((string) $env['PRODUCTION_BASE_URL'], '/'), $cookieFile);
    $checks = [];

    $up = $session->get('/up', false);
    $checks['/up'] = $up->status === 200 ? 'PASS' : 'FAIL';

    $login = $session->get('/login', false);
    $checks['/login'] = $login->status === 200 ? 'PASS' : 'FAIL';

    $csp = ($login->headers['content-security-policy'] ?? '') !== '' ? 'PASS' : 'FAIL';
    $checks['CSP'] = $csp;

    $css = $session->get('/css/app-dashboard.css', false);
    $checks['asset css'] = $css->status === 200 ? 'PASS' : 'FAIL';

    if (! empty($env['PRODUCTION_USER_EMAIL']) && ! empty($env['PRODUCTION_USER_PASSWORD'])) {
        $form = smokeParseForm(
            $login->body,
            (string) $env['PRODUCTION_BASE_URL'],
            static fn (DOMElement $form, string $action, string $method): bool => $method === 'POST' && str_contains($action, '/login')
        );
        $payload = $form['fields'];
        $payload['email'] = (string) $env['PRODUCTION_USER_EMAIL'];
        $payload['password'] = (string) $env['PRODUCTION_USER_PASSWORD'];

        $auth = $session->request($form['method'], $form['action'], $payload);
        $checks['login auth'] = ($auth->status === 200 || str_contains($auth->effectiveUrl, '/two-factor-challenge')) ? 'PASS' : 'FAIL';

        if ($checks['login auth'] === 'PASS' && ! str_contains($auth->effectiveUrl, '/two-factor-challenge')) {
            $dashboard = $session->get('/dashboard', false);
            $checks['/dashboard'] = $dashboard->status === 200 ? 'PASS' : 'FAIL';

            $logoutForm = smokeParseForm(
                $dashboard->body,
                (string) $env['PRODUCTION_BASE_URL'],
                static fn (DOMElement $form, string $action, string $method): bool => $method === 'POST' && str_contains($action, '/logout')
            );
            $logout = $session->request($logoutForm['method'], $logoutForm['action'], $logoutForm['fields']);
            $checks['logout'] = $logout->status === 200 ? 'PASS' : 'FAIL';
        }
    }

    echo "PRODUCTION SMOKE\n";
    foreach ($checks as $label => $status) {
        echo str_pad($label, 16, ' ')." {$status}\n";
    }
    exit(in_array('FAIL', $checks, true) ? 1 : 0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] '.$throwable->getMessage().PHP_EOL);
    exit(1);
} finally {
    @unlink($cookieFile);
}
