#!/usr/bin/env php
<?php

declare(strict_types=1);

final class StagingUatException extends RuntimeException
{
}

final class UatHttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly string $effectiveUrl,
    ) {
    }
}

final class UatSession
{
    private int $http500Count = 0;

    public function __construct(
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly string $cookieFile,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function http500Count(): int
    {
        return $this->http500Count;
    }

    public function cookieFile(): string
    {
        return $this->cookieFile;
    }

    public function get(string $path, array $query = [], bool $followRedirects = true): UatHttpResponse
    {
        $url = $this->absoluteUrl($path);
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $this->request('GET', $url, [], [], $followRedirects);
    }

    public function post(string $path, array $payload, bool $followRedirects = true): UatHttpResponse
    {
        return $this->request('POST', $this->absoluteUrl($path), $payload, [], $followRedirects);
    }

    public function request(
        string $method,
        string $url,
        array $payload = [],
        array $headers = [],
        bool $followRedirects = true,
    ): UatHttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new StagingUatException('No se pudo inicializar cURL.');
        }

        $normalizedHeaders = array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent: flujo-caja-staging-uat/1.0',
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
            throw new StagingUatException("Fallo HTTP {$method} {$url}: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        curl_close($handle);

        if ($status >= 500) {
            $this->http500Count++;
        }

        return new UatHttpResponse(
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

    public static function concurrentGet(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($requests as $key => $request) {
            if (! isset($request['session'], $request['path']) || ! $request['session'] instanceof self) {
                throw new StagingUatException('Solicitud concurrente inválida.');
            }

            /** @var self $session */
            $session = $request['session'];
            $url = $session->absoluteUrl((string) $request['path']);
            $handle = curl_init($url);
            if ($handle === false) {
                throw new StagingUatException('No se pudo inicializar cURL concurrente.');
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_COOKIEJAR => $session->cookieFile(),
                CURLOPT_COOKIEFILE => $session->cookieFile(),
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'User-Agent: flujo-caja-staging-uat/1.0',
                ],
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            curl_multi_add_handle($multi, $handle);
            $handles[$key] = [$handle, $session];
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($status > CURLM_OK) {
                break;
            }

            curl_multi_select($multi, 1.0);
        } while ($running > 0);

        $responses = [];

        foreach ($handles as $key => [$handle, $session]) {
            $raw = curl_multi_getcontent($handle);
            if ($raw === false) {
                $error = curl_error($handle);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                throw new StagingUatException("Fallo HTTP concurrente: {$error}");
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);

            if ($status >= 500) {
                $session->http500Count++;
            }

            $responses[$key] = new UatHttpResponse(
                $status,
                $body,
                self::parseHeaders($rawHeaders),
                $effectiveUrl,
            );

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);

        return $responses;
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

final class UatHtml
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

    public static function xpath(DOMDocument $document): DOMXPath
    {
        return new DOMXPath($document);
    }

    public static function parseForm(string $html, string $baseUrl, callable $selector): array
    {
        $document = self::document($html);
        $xpath = self::xpath($document);

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

        throw new StagingUatException('No se encontró el formulario esperado.');
    }

    public static function resolveUrl(string $baseUrl, string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($url, '/');
    }

    public static function extractTextBySelector(string $html, string $expression): array
    {
        $document = self::document($html);
        $xpath = self::xpath($document);
        $values = [];

        foreach ($xpath->query($expression) ?: [] as $node) {
            $values[] = self::normalizeText($node->textContent ?? '');
        }

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    public static function parseDefinitionList(string $html): array
    {
        $document = self::document($html);
        $xpath = self::xpath($document);
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

    public static function parseKpis(string $html): array
    {
        $document = self::document($html);
        $xpath = self::xpath($document);
        $values = [];

        foreach ($xpath->query('//div[contains(@class,"kpi-card")]') ?: [] as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $labelNode = $xpath->query('.//*[contains(@class,"kpi-label")]', $card)?->item(0);
            $valueNode = $xpath->query('.//*[contains(@class,"kpi-value")]', $card)?->item(0);

            if (! $labelNode || ! $valueNode) {
                continue;
            }

            $values[self::normalizeText($labelNode->textContent ?? '')] = self::normalizeText($valueNode->textContent ?? '');
        }

        return $values;
    }

    public static function parseUserName(string $html): ?string
    {
        $document = self::document($html);
        $xpath = self::xpath($document);
        $node = $xpath->query('//*[contains(@class,"app-user-name")]')?->item(0);

        return $node ? self::normalizeText($node->textContent ?? '') : null;
    }

    public static function parseIndexRows(string $html): array
    {
        $document = self::document($html);
        $xpath = self::xpath($document);
        $rows = [];

        foreach ($xpath->query('//table//tbody/tr') ?: [] as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($xpath->query('./td', $row) ?: [] as $cell) {
                $cells[] = self::normalizeText($cell->textContent ?? '');
            }

            // Capturar cualquier pista de navegacion/identificador de la fila.
            // Las pantallas no son uniformes: algunas usan links, otras forms,
            // botones con formaction, data-* o inputs hidden.
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

            $ids = [];
            foreach ($xpath->query('.//*', $row) ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                foreach (['data-id', 'data-record-id', 'data-row-id', 'data-resource-id'] as $attribute) {
                    $value = trim($node->getAttribute($attribute));
                    if ($value !== '' && ctype_digit($value)) {
                        $ids[] = (int) $value;
                    }
                }

                if (strtolower($node->tagName) === 'input') {
                    $name = strtolower(trim($node->getAttribute('name')));
                    $value = trim($node->getAttribute('value'));
                    if ($value !== '' && ctype_digit($value) && preg_match('/(^|_|\[)(id|ids|record_id|selected)(\]|$)/', $name)) {
                        $ids[] = (int) $value;
                    }
                }
            }

            $rows[] = [
                'urls' => array_values(array_unique($urls)),
                'ids' => array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0))),
                'text' => implode(' | ', $cells),
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    public static function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function extractForm(DOMElement $form, string $action, string $method): array
    {
        $document = $form->ownerDocument;
        $xpath = new DOMXPath($document);
        $fields = [];
        $options = [];

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
                $options[$name] = [];
                $selectedValue = null;

                foreach ($xpath->query('.//option', $node) ?: [] as $option) {
                    if (! $option instanceof DOMElement) {
                        continue;
                    }

                    $entry = [
                        'value' => $option->getAttribute('value'),
                        'label' => self::normalizeText($option->textContent ?? ''),
                    ];
                    $options[$name][] = $entry;

                    if ($option->hasAttribute('selected')) {
                        $selectedValue = $entry['value'];
                    }
                }

                $fields[$name] = $selectedValue ?? ($options[$name][0]['value'] ?? '');
            }
        }

        return [
            'action' => $action,
            'method' => $method,
            'fields' => $fields,
            'options' => $options,
        ];
    }
}

final class StagingMultiuserUatRunner
{
    private readonly string $baseUrl;
    private readonly string $runId;
    private readonly string $outputFile;
    private readonly UatSession $user1;
    private readonly UatSession $user2;

    /** @var array<int, array<string, mixed>> */
    private array $cases = [];

    /** @var array<string, mixed> */
    private array $created = [];

    /** @var array<string, mixed> */
    private array $summary = [];

    public function __construct(
        private readonly array $env,
        private readonly string $workspaceRoot,
    ) {
        $this->baseUrl = rtrim((string) $env['UAT_BASE_URL'], '/');
        $stamp = (new DateTimeImmutable('now'))->format('Ymd-His');
        $this->runId = 'STG-UAT-'.$stamp;
        $this->outputFile = rtrim(sys_get_temp_dir(), '/').'/staging-uat-'.strtolower($this->runId).'.json';
        $this->user1 = new UatSession('UAT Finanzas', $this->baseUrl, $this->tempFile('uat-user1-cookie-'));
        $this->user2 = new UatSession('UAT Operaciones', $this->baseUrl, $this->tempFile('uat-user2-cookie-'));
    }

    public function run(): int
    {
        try {
            $this->bootstrap();
            $this->writeJson();
            $this->renderCliSummary();

            return $this->hasFailures() ? 1 : 0;
        } finally {
            @unlink($this->user1->cookieFile());
            @unlink($this->user2->cookieFile());
        }
    }

    private function bootstrap(): void
    {
        if (! $this->recordCase('Login usuario 1', function (): array {
            $response = $this->login($this->user1, (string) $this->env['UAT_USER1_EMAIL'], (string) $this->env['UAT_USER1_PASSWORD']);

            return ['status' => 'PASS', 'evidence' => 'Dashboard autenticado tras login', 'http_status' => $response->status];
        })) {
            return;
        }

        if (! $this->recordCase('Login usuario 2', function (): array {
            $response = $this->login($this->user2, (string) $this->env['UAT_USER2_EMAIL'], (string) $this->env['UAT_USER2_PASSWORD']);

            return ['status' => 'PASS', 'evidence' => 'Dashboard autenticado tras login', 'http_status' => $response->status];
        })) {
            return;
        }

        if (! $this->recordCase('Admin authorization U1', function (): array {
            $response = $this->user1->get('/administracion/usuarios', [], false);
            $this->assertStatus($response, 403, 'Usuario 1 debe recibir 403 en administración.');

            return ['status' => 'PASS', 'evidence' => '403 en /administracion/usuarios', 'http_status' => $response->status];
        })) {
            return;
        }

        if (! $this->recordCase('Admin authorization U2', function (): array {
            $response = $this->user2->get('/administracion/usuarios', [], false);
            $this->assertStatus($response, 403, 'Usuario 2 debe recibir 403 en administración.');

            return ['status' => 'PASS', 'evidence' => '403 en /administracion/usuarios', 'http_status' => $response->status];
        })) {
            return;
        }

        if (! $this->recordCase('Preflight formularios', function (): array {
            $this->preflightOperationalForms();
            return ['status' => 'PASS', 'evidence' => 'Rutas y campos requeridos disponibles antes de crear datos'];
        })) {
            return;
        }

        $baselineCash = $this->dashboardCash($this->user1);
        $this->summary['baseline_cash'] = $baselineCash;

        $cashAccount = $this->createCashAccount();
        $cashAfterAccount = $this->dashboardCash($this->user1);
        $this->assertApproximately($baselineCash + 1000000.0, $cashAfterAccount, 0.01, 'Saldo inicial UAT no impactó caja como se esperaba.');

        $client = $this->createClient();
        $project = $this->createProject($client);
        [$personA, $personB] = $this->createPeople();
        $assignmentA = $this->createAssignment($client, $project, $personA, 20000.0);
        $assignmentB = $this->createAssignment($client, $project, $personB, 15000.0);

        $timeEntryA = $this->createTimeEntry($client, $project, $personA, 20.0);
        $timeEntryB = $this->createTimeEntry($client, $project, $personB, 10.0);

        $this->recordCase('Costo HH', function () use ($timeEntryA, $timeEntryB): array {
            $obtained = $timeEntryA['hours_approved'] * $timeEntryA['hourly_value']
                + $timeEntryB['hours_approved'] * $timeEntryB['hourly_value'];

            $this->assertApproximately(550000.0, $obtained, 0.01, 'Costo HH UAT no coincide con la referencia esperada.');

            return [
                'status' => 'PASS',
                'expected' => 550000.0,
                'obtained' => $obtained,
                'evidence' => '20x20.000 + 10x15.000',
            ];
        });

        $salesDocument = $this->createSalesDocument($client, $project);
        $cashAfterInvoice = $this->dashboardCash($this->user1);
        $this->recordCase('Factura sin cobro', function () use ($cashAfterAccount, $cashAfterInvoice): array {
            $this->assertApproximately($cashAfterAccount, $cashAfterInvoice, 0.01, 'La caja cambió al crear la factura sin cobro.');

            return ['status' => 'PASS', 'evidence' => 'Caja sin cambio tras documento pendiente'];
        });

        $collectionMovement = $this->createCashMovement([
            'movement_type' => 'income',
            'source_document_type' => 'sales_document',
            'source_document_code' => $salesDocument['code'],
            'counterparty_name' => $client['legal_name'],
            'project_id' => $project['id'],
            'movement_date' => '11/08/2026',
            'income' => $salesDocument['gross_amount'],
            'cash_account_id' => $cashAccount['id'],
            'reference' => $this->runId.' Cobro',
        ]);
        $cashAfterCollection = $this->dashboardCash($this->user1);
        $this->recordCase('Cobro', function () use ($cashAfterInvoice, $cashAfterCollection, $salesDocument): array {
            $delta = round($cashAfterCollection - $cashAfterInvoice, 2);
            $this->assertApproximately($salesDocument['gross_amount'], $delta, 0.01, 'El cobro no impactó caja exactamente una vez por el monto bruto.');

            return [
                'status' => 'PASS',
                'expected' => $salesDocument['gross_amount'],
                'obtained' => $delta,
                'evidence' => 'Movimiento de cobro único',
            ];
        });

        $vatRate = $salesDocument['vat_rate'];
        $expenseA = $this->createExpenseDocument($client, $project, 'A', 300000.0, $vatRate);
        $expenseB = $this->createExpenseDocument($client, $project, 'B', 200000.0, $vatRate);
        $cashAfterExpenseDocs = $this->dashboardCash($this->user1);
        $this->recordCase('Gasto sin pago', function () use ($cashAfterCollection, $cashAfterExpenseDocs): array {
            $this->assertApproximately($cashAfterCollection, $cashAfterExpenseDocs, 0.01, 'La caja cambió al crear gasto sin pago.');

            return ['status' => 'PASS', 'evidence' => 'Caja sin cambio tras gastos pendientes'];
        });

        $this->createCashMovement([
            'movement_type' => 'expense',
            'source_document_type' => 'expense_document',
            'source_document_code' => $expenseA['code'],
            'counterparty_name' => $expenseA['vendor_name'],
            'project_id' => $project['id'],
            'movement_date' => '12/08/2026',
            'expense' => $expenseA['gross_amount'],
            'cash_account_id' => $cashAccount['id'],
            'reference' => $this->runId.' Pago gasto A',
        ]);
        $this->createCashMovement([
            'movement_type' => 'expense',
            'source_document_type' => 'expense_document',
            'source_document_code' => $expenseB['code'],
            'counterparty_name' => $expenseB['vendor_name'],
            'project_id' => $project['id'],
            'movement_date' => '13/08/2026',
            'expense' => $expenseB['gross_amount'],
            'cash_account_id' => $cashAccount['id'],
            'reference' => $this->runId.' Pago gasto B',
        ]);
        $cashAfterExpenses = $this->dashboardCash($this->user1);
        $this->recordCase('Gastos', function () use ($cashAfterCollection, $cashAfterExpenses): array {
            $delta = round($cashAfterExpenses - $cashAfterCollection, 2);
            $this->assertApproximately(-500000.0, $delta, 0.01, 'Los pagos de gasto no sumaron exactamente -500.000.');

            return [
                'status' => 'PASS',
                'expected' => -500000.0,
                'obtained' => $delta,
                'evidence' => 'Pago de gasto A + B',
            ];
        });

        $obligation = $this->createObligation();
        $cashAfterObligation = $this->dashboardCash($this->user1);
        $this->recordCase('Obligación sin pago', function () use ($cashAfterExpenses, $cashAfterObligation): array {
            $this->assertApproximately($cashAfterExpenses, $cashAfterObligation, 0.01, 'La caja cambió al crear obligación sin pago.');

            return ['status' => 'PASS', 'evidence' => 'Caja sin cambio tras obligación pendiente'];
        });

        $this->createCashMovement([
            'movement_type' => 'expense',
            'source_document_type' => 'legal_obligation',
            'source_document_code' => $obligation['code'],
            'counterparty_name' => 'Organismo UAT',
            'project_id' => $project['id'],
            'movement_date' => '14/08/2026',
            'expense' => 150000.0,
            'cash_account_id' => $cashAccount['id'],
            'reference' => $this->runId.' Pago obligación',
        ]);
        $cashAfterObligationPayment = $this->dashboardCash($this->user1);
        $this->recordCase('Pago obligación', function () use ($cashAfterObligation, $cashAfterObligationPayment): array {
            $delta = round($cashAfterObligationPayment - $cashAfterObligation, 2);
            $this->assertApproximately(-150000.0, $delta, 0.01, 'El pago de obligación no impactó caja exactamente una vez.');

            return [
                'status' => 'PASS',
                'expected' => -150000.0,
                'obtained' => $delta,
                'evidence' => 'Movimiento de obligación único',
            ];
        });

        $this->summary['cash_phase_a_expected'] = round($cashAfterAccount + $salesDocument['gross_amount'] - 500000.0, 2);
        $this->summary['cash_phase_a_obtained'] = $cashAfterExpenses;
        $this->summary['cash_phase_b_expected'] = round($this->summary['cash_phase_a_expected'] - 150000.0, 2);
        $this->summary['cash_phase_b_obtained'] = $cashAfterObligationPayment;

        $this->recordCase('Caja reconciliada', function (): array {
            $this->assertApproximately(
                (float) $this->summary['cash_phase_a_expected'],
                (float) $this->summary['cash_phase_a_obtained'],
                0.01,
                'Caja fase A no cuadra.'
            );
            $this->assertApproximately(
                (float) $this->summary['cash_phase_b_expected'],
                (float) $this->summary['cash_phase_b_obtained'],
                0.01,
                'Caja fase B no cuadra.'
            );

            return [
                'status' => 'PASS',
                'phase_a_expected' => $this->summary['cash_phase_a_expected'],
                'phase_a_obtained' => $this->summary['cash_phase_a_obtained'],
                'phase_b_expected' => $this->summary['cash_phase_b_expected'],
                'phase_b_obtained' => $this->summary['cash_phase_b_obtained'],
            ];
        });

        $this->createBudget($project);
        $this->recordCase('Presupuesto vs real', function () use ($project): array {
            $response = $this->user1->get('/gestion/presupuesto', ['project_id' => $project['id'], 'period' => '2026-08-01']);
            $this->assertStatus($response, 200, 'Presupuesto debe responder 200.');

            return ['status' => 'PASS', 'evidence' => 'Vista presupuesto 200'];
        });

        $this->recordCase('Rentabilidad', function () use ($project): array {
            $response = $this->user1->get('/gestion/rentabilidad', ['q' => $this->runId, 'project_id' => $project['id'], 'period' => '2026-08']);
            $this->assertStatus($response, 200, 'Rentabilidad debe responder 200.');
            $rows = UatHtml::parseIndexRows($response->body);

            $row = null;
            foreach ($rows as $candidate) {
                if (str_contains($candidate['text'], $this->runId)) {
                    $row = $candidate;
                    break;
                }
            }

            if ($row === null) {
                throw new StagingUatException('No se encontró la fila del proyecto UAT en rentabilidad.');
            }

            $cells = $row['cells'];
            if (count($cells) < 12) {
                throw new StagingUatException('La tabla de rentabilidad no tiene el formato esperado.');
            }

            $facturado = self::parseLocalizedNumber($cells[4] ?? '0');
            $totalCost = self::parseLocalizedNumber($cells[9] ?? '0');
            $margin = self::parseLocalizedNumber($cells[10] ?? '0');

            $this->assertApproximately(round($facturado - $totalCost, 2), $margin, 1.0, 'La rentabilidad no cuadra con venta facturada menos costo total directo.');

            return [
                'status' => 'PASS',
                'facturado' => $facturado,
                'total_cost' => $totalCost,
                'margin' => $margin,
                'evidence' => 'Margen coherente con tabla de rentabilidad',
            ];
        });

        $this->recordCase('Dashboard', function () use ($cashAfterObligationPayment): array {
            $response = $this->user1->get('/dashboard');
            $this->assertStatus($response, 200, 'Dashboard debe responder 200.');
            $kpis = UatHtml::parseKpis($response->body);
            $cash = self::parseLocalizedNumber($kpis['Saldo Disponible'] ?? '0');
            $this->assertApproximately($cashAfterObligationPayment, $cash, 0.01, 'Dashboard no refleja el saldo final esperado.');

            return ['status' => 'PASS', 'evidence' => 'Saldo Disponible reconciliado'];
        });

        $this->recordCase('Concurrencia', function () use ($project): array {
            $responses = UatSession::concurrentGet([
                'u1-project' => ['session' => $this->user1, 'path' => '/operacion/projects/'.$project['id']],
                'u2-dashboard' => ['session' => $this->user2, 'path' => '/dashboard'],
            ]);

            $this->assertStatus($responses['u1-project'], 200, 'GET concurrente proyecto usuario 1 debe responder 200.');
            $this->assertStatus($responses['u2-dashboard'], 200, 'GET concurrente dashboard usuario 2 debe responder 200.');

            $user1Name = UatHtml::parseUserName($responses['u1-project']->body);
            $user2Name = UatHtml::parseUserName($responses['u2-dashboard']->body);

            if ($user1Name === null || $user2Name === null || $user1Name === $user2Name) {
                throw new StagingUatException('No se pudo confirmar independencia de sesiones concurrentes.');
            }

            return [
                'status' => 'PASS',
                'evidence' => "Sesiones concurrentes aisladas ({$user1Name} / {$user2Name})",
            ];
        });

        $this->runLogoutReloginCase();
    }

    private function login(UatSession $session, string $email, string $password): UatHttpResponse
    {
        $loginPage = $session->get('/login');
        $this->assertStatus($loginPage, 200, 'GET /login debe responder 200.');

        $form = UatHtml::parseForm(
            $loginPage->body,
            $this->baseUrl,
            static fn (DOMElement $form, string $action, string $method): bool => $method === 'POST' && str_contains($action, '/login')
        );

        $payload = $form['fields'];
        $payload['email'] = $email;
        $payload['password'] = $password;

        // No seguir el primer redirect: así distinguimos login correcto, rechazo y 2FA.
        $postResponse = $session->request($form['method'], $form['action'], $payload, [], false);

        if (! in_array($postResponse->status, [302, 303], true)) {
            throw new StagingUatException(
                'POST /login devolvió HTTP '.$postResponse->status
                .' para '.$session->name().'; se esperaba redirect 302/303.'
            );
        }

        $location = trim((string) ($postResponse->headers['location'] ?? ''));
        if ($location === '') {
            throw new StagingUatException(
                'POST /login no entregó cabecera Location para '.$session->name().'.'
            );
        }

        $redirectUrl = UatHtml::resolveUrl($this->baseUrl, $location);
        $redirectPath = parse_url($redirectUrl, PHP_URL_PATH);
        $redirectPath = is_string($redirectPath) ? $redirectPath : '';

        if (str_contains($redirectPath, '/two-factor-challenge')) {
            throw new StagingUatException(
                'El runner no requiere usuarios con challenge 2FA; use usuarios UAT normales.'
            );
        }

        if ($redirectPath === '/login') {
            $rejectedPage = $session->get($redirectUrl);
            $errors = UatHtml::extractTextBySelector(
                $rejectedPage->body,
                '//*[contains(@class,"invalid-feedback") or contains(@class,"alert-danger")]'
            );

            throw new StagingUatException(
                'Login rechazado para '.$session->name()
                .' | correo='.$email
                .' | errores='.($errors === [] ? 'NO_DETECTADOS' : implode(' | ', $errors))
            );
        }

        // Completar el redirect de autenticación y validar la sesión con /dashboard.
        $session->get($redirectUrl);
        $dashboard = $session->get('/dashboard');
        $this->assertStatus($dashboard, 200, 'Dashboard debe responder 200 después del login.');

        $finalPath = parse_url($dashboard->effectiveUrl, PHP_URL_PATH);
        $finalPath = is_string($finalPath) ? $finalPath : '';

        if (str_contains($finalPath, '/two-factor-challenge')) {
            throw new StagingUatException(
                'El runner no requiere usuarios con challenge 2FA; use usuarios UAT normales.'
            );
        }

        if ($finalPath === '/login') {
            throw new StagingUatException(
                'Login no persistió para '.$session->name()
                .' | URL final='.$dashboard->effectiveUrl
            );
        }

        if ($finalPath !== '/dashboard') {
            throw new StagingUatException(
                'Login autenticó pero no permitió acceder a /dashboard para '.$session->name()
                .' | URL final='.$dashboard->effectiveUrl
            );
        }

        $userName = UatHtml::parseUserName($dashboard->body);
        if ($userName === null) {
            throw new StagingUatException(
                'Login no confirmado para '.$session->name()
                .' | URL final='.$dashboard->effectiveUrl
                .' | usuario autenticado no detectado'
            );
        }

        return $dashboard;
    }

    private function preflightOperationalForms(): void
    {
        $requirements = [
            'cash-accounts' => ['name', 'currency_id', 'opening_balance'],
            'clients' => ['legal_name', 'tax_id', 'contact_name', 'contact_email', 'client_status_id'],
            'projects' => ['client_id', 'sales_currency_id', 'name', 'start_date', 'end_date', 'sale_net', 'project_status_id', 'billing_status_id'],
            'people' => ['first_names', 'paternal_surname', 'maternal_surname', 'rut', 'nationality', 'email', 'employment_mode_id', 'worker_status_id', 'hourly_rate_unit_type', 'hourly_value', 'monthly_hours', 'start_date'],
            'assignments' => ['person_id', 'client_id', 'project_id', 'hourly_rate_unit_type', 'hourly_value', 'monthly_hours', 'start_date', 'assignment_status_id'],
            'time-entries' => ['person_id', 'project_id', 'client_id', 'entry_date', 'activity_id', 'hours_worked', 'hours_approved', 'approval_status_id'],
            'sales-documents' => ['client_id', 'project_id', 'document_type_id', 'document_number', 'issue_date', 'due_date', 'net_amount'],
            'expense-documents' => ['vendor_name', 'client_id', 'project_id', 'expense_category_id', 'expense_type_id', 'document_type_id', 'issue_date', 'due_date', 'net_amount'],
            'legal-obligations' => ['obligation_type_id', 'organization_id', 'period_date', 'due_date', 'estimated_amount'],
            'cash-movements' => ['movement_type_id', 'source_document_type', 'source_document_code', 'movement_date', 'payment_method_id', 'cash_account_id', 'status'],
            'budgets' => ['project_id', 'period_date', 'revenue_budget', 'personnel_budget'],
        ];

        // Static catalogue values used by the scenario must be resolvable before
        // the first write. Dynamic relationship fields (client_id, project_id, etc.)
        // are intentionally excluded because their IDs only exist after prior steps.
        $catalogChecks = [
            'cash-accounts' => [
                'currency_id' => ['label_contains' => ['CLP']],
            ],
            'clients' => [
                'client_status_id' => ['label_contains' => ['activo', 'vigente']],
            ],
            'projects' => [
                'sales_currency_id' => ['label_contains' => ['CLP']],
                'project_status_id' => ['label_contains' => ['activo', 'vigente']],
                'billing_status_id' => ['label_contains' => ['activo', 'vigente', 'pendiente']],
            ],
            'people' => [
                'employment_mode_id' => ['first_non_empty' => true],
                'worker_status_id' => ['label_contains' => ['activo', 'vigente']],
                'hourly_rate_currency_id' => ['label_contains' => ['CLP']],
            ],
            'assignments' => [
                'hourly_rate_currency_id' => ['label_contains' => ['CLP']],
                'assignment_status_id' => ['label_contains' => ['activo', 'vigente']],
            ],
            'time-entries' => [
                'activity_id' => ['first_non_empty' => true],
                'approval_status_id' => ['label_contains' => ['aprob', 'ok'], 'first_non_empty' => true],
            ],
            'sales-documents' => [
                'document_type_id' => ['label_contains' => ['factura'], 'first_non_empty' => true],
            ],
            'expense-documents' => [
                'expense_category_id' => ['first_non_empty' => true],
                'expense_type_id' => ['first_non_empty' => true],
                'document_type_id' => ['first_non_empty' => true],
            ],
            'legal-obligations' => [
                'obligation_type_id' => ['first_non_empty' => true],
                'organization_id' => ['first_non_empty' => true],
            ],
            'cash-movements' => [
                'movement_type_id' => ['first_non_empty' => true],
                'payment_method_id' => ['first_non_empty' => true],
            ],
        ];

        $problems = [];

        foreach ($requirements as $resource => $requiredFields) {
            try {
                $page = $this->user1->get('/operacion/'.$resource.'/crear');
                if ($page->status !== 200) {
                    $problems[] = "{$resource}: formulario create HTTP {$page->status}";
                    continue;
                }

                $form = UatHtml::parseForm(
                    $page->body,
                    $this->baseUrl,
                    fn (DOMElement $form, string $action, string $method): bool => $method === 'POST' && str_contains($action, '/operacion/'.$resource)
                );

                $available = array_fill_keys(array_keys($form['fields'] ?? []), true);
                $missing = [];
                foreach ($requiredFields as $field) {
                    if (! isset($available[$field])) {
                        $missing[] = $field;
                    }
                }

                if ($missing !== []) {
                    $problems[] = "{$resource}: faltan campos ".implode(', ', $missing);
                }

                foreach (($catalogChecks[$resource] ?? []) as $field => $selector) {
                    if (! array_key_exists($field, $form['fields'] ?? [])) {
                        continue;
                    }

                    $currentValue = is_scalar(($form['fields'] ?? [])[$field] ?? null)
                        ? (string) ($form['fields'][$field] ?? '')
                        : '';

                    try {
                        $this->resolveFieldValue(
                            $resource,
                            $field,
                            $selector,
                            $form['options'] ?? [],
                            $currentValue,
                        );
                    } catch (Throwable $throwable) {
                        $problems[] = "{$resource}.{$field}: ".$throwable->getMessage();
                    }
                }
            } catch (Throwable $throwable) {
                $problems[] = "{$resource}: ".$throwable->getMessage();
            }
        }

        foreach (['/dashboard', '/gestion/presupuesto', '/gestion/rentabilidad'] as $path) {
            try {
                $response = $this->user1->get($path);
                if ($response->status !== 200) {
                    $problems[] = "{$path}: HTTP {$response->status}";
                }
            } catch (Throwable $throwable) {
                $problems[] = "{$path}: ".$throwable->getMessage();
            }
        }

        if ($problems !== []) {
            throw new StagingUatException(
                "Preflight detectó ".count($problems)." incompatibilidad(es): ".implode(' || ', $problems)
            );
        }
    }

    private function createCashAccount(): array
    {
        $name = $this->runId.' Banco UAT CLP';

        return $this->recordCreate('Cuenta UAT', function () use ($name): array {
            $record = $this->createOperationalRecord('cash-accounts', [
                'name' => $name,
                'currency_id' => ['label_contains' => ['CLP']],
                'opening_balance' => '1000000',
            ], searchTerm: $name);

            return $record;
        });
    }

    private function createClient(): array
    {
        $name = $this->runId.' Cliente';

        return $this->recordCreate('Cliente', function () use ($name): array {
            $record = $this->createOperationalRecord('clients', [
                'legal_name' => $name,
                'tax_id' => self::generateRutFromSeed($this->runId, 1),
                'contact_name' => 'UAT Finanzas',
                'contact_email' => 'uat+'.strtolower(str_replace(['-', ' '], '', $this->runId)).'@example.test',
                'client_status_id' => ['label_contains' => ['activo', 'vigente']],
            ], searchTerm: $name);

            return $record;
        });
    }

    private function createProject(array $client): array
    {
        $name = $this->runId.' Proyecto Agosto 2026';

        return $this->recordCreate('Proyecto', function () use ($client, $name): array {
            $record = $this->createOperationalRecord('projects', [
                'client_id' => (string) $client['id'],
                'sales_currency_id' => ['label_contains' => ['CLP']],
                'name' => $name,
                'start_date' => '10/08/2026',
                'end_date' => '31/08/2026',
                'sale_net' => '2000000',
                'project_status_id' => ['label_contains' => ['activo', 'vigente']],
                'billing_status_id' => ['label_contains' => ['activo', 'vigente', 'pendiente']],
            ], searchTerm: $name);

            return $record;
        });
    }

    private function createPeople(): array
    {
        $personA = $this->recordCreate('Persona A', function (): array {
            return $this->createOperationalRecord('people', [
                'first_names' => $this->runId.' Persona A',
                'paternal_surname' => 'UAT',
                'maternal_surname' => 'Alpha',
                'rut' => self::generateRutFromSeed($this->runId, 2),
                'nationality' => 'Chilena',
                'email' => 'persona-a+'.strtolower(str_replace('-', '', $this->runId)).'@example.test',
                'phone_country_code' => '+56',
                'phone_number' => '987654321',
                'employment_mode_id' => ['first_non_empty' => true],
                'worker_status_id' => ['label_contains' => ['activo', 'vigente']],
                'hourly_rate_unit_type' => 'CURRENCY',
                'hourly_rate_currency_id' => ['label_contains' => ['CLP']],
                'hourly_value' => '20000',
                'monthly_hours' => '160',
                'start_date' => '01/08/2026',
            ], searchTerm: $this->runId.' Persona A');
        });

        $personB = $this->recordCreate('Persona B', function (): array {
            return $this->createOperationalRecord('people', [
                'first_names' => $this->runId.' Persona B',
                'paternal_surname' => 'UAT',
                'maternal_surname' => 'Beta',
                'rut' => self::generateRutFromSeed($this->runId, 3),
                'nationality' => 'Chilena',
                'email' => 'persona-b+'.strtolower(str_replace('-', '', $this->runId)).'@example.test',
                'phone_country_code' => '+56',
                'phone_number' => '912345678',
                'employment_mode_id' => ['first_non_empty' => true],
                'worker_status_id' => ['label_contains' => ['activo', 'vigente']],
                'hourly_rate_unit_type' => 'CURRENCY',
                'hourly_rate_currency_id' => ['label_contains' => ['CLP']],
                'hourly_value' => '15000',
                'monthly_hours' => '160',
                'start_date' => '01/08/2026',
            ], searchTerm: $this->runId.' Persona B');
        });

        return [$personA, $personB];
    }

    private function createAssignment(array $client, array $project, array $person, float $hourlyValue): array
    {
        return $this->recordCreate('Asignación '.($person['first_names'] ?? $person['display'] ?? 'UAT'), function () use ($client, $project, $person, $hourlyValue): array {
            return $this->createOperationalRecord('assignments', [
                'person_id' => (string) $person['id'],
                'client_id' => (string) $client['id'],
                'project_id' => (string) $project['id'],
                'hourly_rate_unit_type' => 'CURRENCY',
                'hourly_rate_currency_id' => ['label_contains' => ['CLP']],
                'hourly_value' => (string) (int) $hourlyValue,
                'monthly_hours' => '160',
                'start_date' => '01/08/2026',
                'assignment_status_id' => ['label_contains' => ['activo', 'vigente']],
            ], searchTerm: null);
        });
    }

    private function createTimeEntry(array $client, array $project, array $person, float $hours): array
    {
        return $this->recordCreate('Horas '.($person['first_names'] ?? $person['display'] ?? 'UAT'), function () use ($client, $project, $person, $hours): array {
            return $this->createOperationalRecord('time-entries', [
                'person_id' => (string) $person['id'],
                'project_id' => (string) $project['id'],
                'client_id' => (string) $client['id'],
                'entry_date' => '10/08/2026',
                'activity_id' => ['first_non_empty' => true],
                'hours_worked' => (string) $hours,
                'hours_approved' => (string) $hours,
                'approval_status_id' => ['label_contains' => ['aprob', 'ok'], 'first_non_empty' => true],
                'payment_status' => 'pending',
            ], searchTerm: null);
        });
    }

    private function createSalesDocument(array $client, array $project): array
    {
        return $this->recordCreate('Factura / Ingreso', function () use ($client, $project): array {
            return $this->createOperationalRecord('sales-documents', [
                'client_id' => (string) $client['id'],
                'project_id' => (string) $project['id'],
                'document_type_id' => ['label_contains' => ['factura'], 'first_non_empty' => true],
                'document_number' => substr(str_replace('-', '', $this->runId), -12),
                'issue_date' => '10/08/2026',
                'due_date' => '31/08/2026',
                'projected_collection_date' => '31/08/2026',
                'payment_probability' => '1',
                'net_amount' => '2000000',
            ], searchTerm: null);
        });
    }

    private function createExpenseDocument(array $client, array $project, string $suffix, float $grossTarget, float $vatRate): array
    {
        return $this->recordCreate('Gasto '.$suffix, function () use ($client, $project, $suffix, $grossTarget, $vatRate): array {
            $net = round($grossTarget / (1 + $vatRate), 2);

            // expense_subcategory_id is intentionally omitted. In the real UI it is
            // a dependent category -> subcategory control and may initially expose only
            // "Seleccione". It is not required by this UAT scenario or by preflight;
            // forcing a guessed value makes the black-box test diverge from the form.
            $record = $this->createOperationalRecord('expense-documents', [
                'vendor_name' => $this->runId.' Proveedor '.$suffix,
                'client_id' => (string) $client['id'],
                'project_id' => (string) $project['id'],
                'expense_category_id' => ['first_non_empty' => true],
                'expense_type_id' => ['first_non_empty' => true],
                'document_type_id' => ['first_non_empty' => true],
                'issue_date' => '12/08/2026',
                'due_date' => '12/08/2026',
                'net_amount' => number_format($net, 2, '.', ''),
            ], searchTerm: null);

            $this->assertApproximately($grossTarget, $record['gross_amount'], 1.0, "El gasto {$suffix} no quedó cercano al monto bruto objetivo.");

            return $record;
        });
    }

    private function createObligation(): array
    {
        return $this->recordCreate('Obligación', function (): array {
            return $this->createOperationalRecord('legal-obligations', [
                'obligation_type_id' => ['first_non_empty' => true],
                'organization_id' => ['first_non_empty' => true],
                'period_date' => '01/08/2026',
                'due_date' => '14/08/2026',
                'estimated_amount' => '150000',
                'source_calculation' => $this->runId.' obligación manual UAT',
                'notes' => 'Pendiente de pago en escenario UAT',
            ], searchTerm: null);
        });
    }

    private function createBudget(array $project): array
    {
        return $this->recordCreate('Presupuesto', function () use ($project): array {
            return $this->createOperationalRecord('budgets', [
                'project_id' => (string) $project['id'],
                'period_date' => '01/08/2026',
                'revenue_budget' => '2400000',
                'personnel_budget' => '550000',
                'other_direct_budget' => '500000',
                'legal_budget' => '150000',
                'other_indirect_budget' => '50000',
                'notes' => $this->runId.' presupuesto UAT',
            ], searchTerm: null);
        });
    }

    private function createCashMovement(array $payload): array
    {
        return $this->recordCreate('Movimiento caja '.($payload['reference'] ?? ''), function () use ($payload): array {
            $movementTypePreference = ($payload['movement_type'] ?? 'expense') === 'income'
                ? ['ingreso', 'cobro', 'abono']
                : ['egreso', 'pago', 'cargo'];

            $record = $this->createOperationalRecord('cash-movements', [
                'movement_type_id' => ['label_contains' => $movementTypePreference, 'first_non_empty' => true],
                'source_document_type' => (string) $payload['source_document_type'],
                'source_document_code' => (string) $payload['source_document_code'],
                'counterparty_name' => (string) ($payload['counterparty_name'] ?? ''),
                'project_id' => (string) ($payload['project_id'] ?? ''),
                'movement_date' => (string) $payload['movement_date'],
                'income' => isset($payload['income']) ? (string) $payload['income'] : '',
                'expense' => isset($payload['expense']) ? (string) $payload['expense'] : '',
                'payment_method_id' => ['first_non_empty' => true],
                'cash_account_id' => (string) $payload['cash_account_id'],
                'reference' => (string) ($payload['reference'] ?? ''),
                'status' => 'posted',
            ], searchTerm: null);

            return $record;
        });
    }

    private function dashboardCash(UatSession $session): float
    {
        $response = $session->get('/dashboard');

        $this->assertStatus(
            $response,
            200,
            'Dashboard debe responder 200.'
        );

        $kpis = UatHtml::parseKpis($response->body);

        if (! isset($kpis['Saldo Disponible'])) {
            $userName = UatHtml::parseUserName($response->body) ?? 'NO_DETECTADO';

            $path = parse_url($response->effectiveUrl, PHP_URL_PATH);
            $path = is_string($path) ? $path : 'NO_DETECTADO';

            $kpiNames = array_keys($kpis);

            $markers = [
                'dashboard_ejecutivo' => str_contains($response->body, 'Dashboard ejecutivo'),
                'saldo_disponible' => str_contains($response->body, 'Saldo Disponible'),
                'login' => str_contains($response->body, 'Acceso local'),
                'two_factor' => str_contains($response->body, 'two-factor')
                    || str_contains($response->body, 'autenticación en dos pasos')
                    || str_contains($response->body, 'Autenticación en dos pasos'),
            ];

            throw new StagingUatException(
                'No se pudo leer KPI "Saldo Disponible". '
                .'URL final='.$response->effectiveUrl
                .' | path='.$path
                .' | usuario='.$userName
                .' | KPIs='.($kpiNames === [] ? 'NINGUNO' : implode(', ', $kpiNames))
                .' | markers='.json_encode($markers, JSON_UNESCAPED_UNICODE)
            );
        }

        return self::parseLocalizedNumber($kpis['Saldo Disponible']);
    }

    private function logoutForm(UatSession $session): array
    {
        // Obtener un token CSRF fresco desde una pagina autenticada de la MISMA sesion.
        $page = $session->get('/dashboard');
        $this->assertStatus($page, 200, 'No se pudo obtener Dashboard antes de logout.');

        if (UatHtml::parseUserName($page->body) === null) {
            throw new StagingUatException('La sesion no esta autenticada antes de logout.');
        }

        $form = UatHtml::parseForm(
            $page->body,
            $this->baseUrl,
            static fn (DOMElement $form, string $action, string $method): bool => $method === 'POST' && str_contains($action, '/logout')
        );

        $token = isset($form['fields']['_token']) ? trim((string) $form['fields']['_token']) : '';
        if ($token === '') {
            throw new StagingUatException('Formulario logout no contiene CSRF _token.');
        }

        return $form;
    }

    private function runLogoutReloginCase(): void
    {
        $this->recordCase('Logout/relogin', function (): array {
            $logoutForm = $this->logoutForm($this->user1);

            // No seguir el redirect inicial. Para logout correcto Laravel debe responder 302/303.
            // Referer y Origin reproducen mejor un POST de navegador sin exponer secretos.
            $logout = $this->user1->request(
                $logoutForm['method'],
                $logoutForm['action'],
                $logoutForm['fields'],
                [
                    'Referer: '.$this->baseUrl.'/dashboard',
                    'Origin: '.$this->baseUrl,
                ],
                false,
            );

            if (! in_array($logout->status, [302, 303], true)) {
                throw new StagingUatException(
                    'Logout usuario 1 esperaba HTTP 302/303 y obtuvo HTTP '.$logout->status
                    .'. Token CSRF presente: SI; URL='.$logout->effectiveUrl
                );
            }

            $location = (string) ($logout->headers['location'] ?? '');
            if ($location !== '' && ! str_contains($location, '/login') && ! str_ends_with(rtrim($location, '/'), parse_url($this->baseUrl, PHP_URL_HOST) ?: '')) {
                throw new StagingUatException('Logout redirigio a destino inesperado: '.$location);
            }

            $afterLogout = $this->user1->get('/dashboard', [], false);
            if (! in_array($afterLogout->status, [302, 303], true)) {
                throw new StagingUatException('Usuario 1 deberia perder la sesion tras logout. HTTP '.$afterLogout->status.'.');
            }

            $user2StillAlive = $this->user2->get('/dashboard');
            $this->assertStatus($user2StillAlive, 200, 'Usuario 2 debe mantener su sesion tras logout usuario 1.');
            if (UatHtml::parseUserName($user2StillAlive->body) === null) {
                throw new StagingUatException('No se pudo confirmar sesion activa de usuario 2 despues del logout de usuario 1.');
            }

            $relogin = $this->login(
                $this->user1,
                (string) $this->env['UAT_USER1_EMAIL'],
                (string) $this->env['UAT_USER1_PASSWORD']
            );
            $this->assertStatus($relogin, 200, 'Relogin usuario 1 debe responder 200.');

            return ['status' => 'PASS', 'evidence' => 'Logout aislado + sesion U2 vigente + relogin U1 exitoso'];
        });
    }

    public function runAuthOnly(): int
    {
        try {
            if (! $this->recordCase('Login usuario 1', function (): array {
                $response = $this->login($this->user1, (string) $this->env['UAT_USER1_EMAIL'], (string) $this->env['UAT_USER1_PASSWORD']);
                return ['status' => 'PASS', 'evidence' => 'Dashboard autenticado tras login', 'http_status' => $response->status];
            })) {
                $this->writeJson();
                $this->renderCliSummary();
                return 1;
            }

            if (! $this->recordCase('Login usuario 2', function (): array {
                $response = $this->login($this->user2, (string) $this->env['UAT_USER2_EMAIL'], (string) $this->env['UAT_USER2_PASSWORD']);
                return ['status' => 'PASS', 'evidence' => 'Dashboard autenticado tras login', 'http_status' => $response->status];
            })) {
                $this->writeJson();
                $this->renderCliSummary();
                return 1;
            }

            $this->recordCase('Sesiones independientes', function (): array {
                $responses = UatSession::concurrentGet([
                    'u1' => ['session' => $this->user1, 'path' => '/dashboard'],
                    'u2' => ['session' => $this->user2, 'path' => '/dashboard'],
                ]);
                $this->assertStatus($responses['u1'], 200, 'Dashboard usuario 1 debe responder 200.');
                $this->assertStatus($responses['u2'], 200, 'Dashboard usuario 2 debe responder 200.');
                $name1 = UatHtml::parseUserName($responses['u1']->body);
                $name2 = UatHtml::parseUserName($responses['u2']->body);
                if ($name1 === null || $name2 === null || $name1 === $name2) {
                    throw new StagingUatException('No se pudo confirmar independencia de sesiones.');
                }
                return ['status' => 'PASS', 'evidence' => 'Sesiones concurrentes aisladas'];
            });

            if (! $this->hasFailures()) {
                $this->runLogoutReloginCase();
            }

            $this->writeJson();
            $this->renderCliSummary();
            return $this->hasFailures() ? 1 : 0;
        } finally {
            @unlink($this->user1->cookieFile());
            @unlink($this->user2->cookieFile());
        }
    }

    private function createOperationalRecord(string $resource, array $overrides, ?string $searchTerm): array
    {
        $formPage = $this->user1->get('/operacion/'.$resource.'/crear');
        $this->assertStatus($formPage, 200, "Formulario create de {$resource} debe responder 200.");

        $form = UatHtml::parseForm(
            $formPage->body,
            $this->baseUrl,
            fn (DOMElement $form, string $action, string $method): bool => $method === 'POST' && str_contains($action, '/operacion/'.$resource)
        );

        $payload = $form['fields'];
        foreach ($overrides as $field => $value) {
            $currentValue = isset($payload[$field]) && is_scalar($payload[$field])
                ? (string) $payload[$field]
                : '';
            $payload[$field] = $this->resolveFieldValue(
                $resource,
                $field,
                $value,
                $form['options'] ?? [],
                $currentValue,
            );
        }

        // No seguir el redirect inicial. El Location posterior al alta es la
        // fuente mas fiable para recuperar el registro recien creado.
        $post = $this->user1->request($form['method'], $form['action'], $payload, [], false);

        if ($post->status >= 500) {
            throw new StagingUatException("Alta {$resource} devolvio HTTP {$post->status}.");
        }

        if (! in_array($post->status, [302, 303], true)) {
            $errors = UatHtml::extractTextBySelector(
                $post->body,
                '//*[contains(@class,"alert-danger") or contains(@class,"invalid-feedback") or contains(@class,"text-danger")]'
            );
            throw new StagingUatException(
                "Alta {$resource} no redirigio como se esperaba. HTTP {$post->status}"
                .($errors === [] ? '' : ' | '.implode(' | ', $errors))
            );
        }

        $location = trim((string) ($post->headers['location'] ?? ''));
        if ($location === '') {
            throw new StagingUatException("Alta {$resource} respondio redirect sin Location.");
        }

        $redirectUrl = UatHtml::resolveUrl($this->baseUrl, $location);
        $redirectPath = parse_url($redirectUrl, PHP_URL_PATH);
        $redirectPath = is_string($redirectPath) ? $redirectPath : '';

        $id = $this->extractResourceIdFromUrl($resource, $redirectUrl);

        // Completar el redirect para consumir flash/session y confirmar que el
        // alta no termino realmente en un formulario con errores.
        $redirectResponse = $this->user1->get($redirectUrl);
        if ($redirectResponse->status >= 500) {
            throw new StagingUatException("Redirect posterior al alta {$resource} devolvio HTTP {$redirectResponse->status}.");
        }

        $errors = UatHtml::extractTextBySelector(
            $redirectResponse->body,
            '//*[contains(@class,"alert-danger") or contains(@class,"invalid-feedback")]'
        );
        if ($errors !== []) {
            throw new StagingUatException("Alta {$resource} con error: ".implode(' | ', $errors));
        }

        if ($id === null) {
            $located = $this->locateOperationalRecord($resource, $searchTerm);
            $id = $located['id'];
        }

        $showUrl = '/operacion/'.$resource.'/'.$id;
        $show = $this->user1->get($showUrl, [], false);

        // Algunos mantenedores no exponen show y redirigen/usan solo edit. En
        // ese caso probar la URL edit como fuente de datos antes de fallar.
        if ($show->status !== 200) {
            $editUrl = $showUrl.'/editar';
            $show = $this->user1->get($editUrl, [], false);
            if ($show->status !== 200) {
                throw new StagingUatException(
                    "No se pudo abrir detalle de {$resource} ID {$id}. HTTP show/edit {$show->status}."
                );
            }
            $showUrl = $editUrl;
        }

        $details = UatHtml::parseDefinitionList($show->body);
        if ($details === []) {
            // Fallback para pantallas edit sin <dl>: usar valores del formulario
            // y la fila localizada. Los campos criticos se completan abajo.
            try {
                $editForm = UatHtml::parseForm(
                    $show->body,
                    $this->baseUrl,
                    static fn (DOMElement $form, string $action, string $method): bool => in_array($method, ['POST', 'PUT', 'PATCH'], true)
                );
                foreach ($editForm['fields'] as $key => $value) {
                    $details[(string) $key] = is_scalar($value) ? (string) $value : '';
                }
            } catch (Throwable) {
                // Se mantiene vacio; normalizeRecord puede usar overrides.
            }
        }

        $record = $this->normalizeRecord($resource, $id, $details, $showUrl);

        // Completar valores indispensables desde el payload enviado cuando la
        // vista de detalle no los expone con las etiquetas esperadas.
        if ($resource === 'cash-accounts') {
            $record['name'] ??= (string) ($overrides['name'] ?? '');
        }
        if ($resource === 'clients') {
            $record['legal_name'] ??= (string) ($overrides['legal_name'] ?? '');
        }
        if ($resource === 'projects') {
            $record['name'] ??= (string) ($overrides['name'] ?? '');
        }
        if ($resource === 'people') {
            $record['first_names'] ??= (string) ($overrides['first_names'] ?? '');
        }

        return $record;
    }

    private function locateOperationalRecord(string $resource, ?string $searchTerm): array
    {
        $response = $this->user1->get('/operacion/'.$resource, $searchTerm ? ['q' => $searchTerm] : []);
        $this->assertStatus($response, 200, "Listado {$resource} debe responder 200.");
        $rows = UatHtml::parseIndexRows($response->body);

        if ($rows === []) {
            throw new StagingUatException("No se encontraron filas en {$resource} tras la creacion.");
        }

        $row = null;
        if ($searchTerm !== null) {
            foreach ($rows as $candidate) {
                if (str_contains((string) ($candidate['text'] ?? ''), $searchTerm)) {
                    $row = $candidate;
                    break;
                }
            }
            if ($row === null) {
                throw new StagingUatException(
                    "No se encontro la fila de {$resource} para el termino UAT esperado."
                );
            }
        } else {
            $row = $rows[0];
        }

        foreach ((array) ($row['urls'] ?? []) as $candidateUrl) {
            if (! is_string($candidateUrl) || $candidateUrl === '') {
                continue;
            }
            $id = $this->extractResourceIdFromUrl($resource, $candidateUrl);
            if ($id !== null) {
                return [
                    'id' => $id,
                    'show_url' => '/operacion/'.$resource.'/'.$id,
                    'row_text' => (string) ($row['text'] ?? ''),
                ];
            }
        }

        foreach ((array) ($row['ids'] ?? []) as $candidateId) {
            $id = (int) $candidateId;
            if ($id > 0) {
                return [
                    'id' => $id,
                    'show_url' => '/operacion/'.$resource.'/'.$id,
                    'row_text' => (string) ($row['text'] ?? ''),
                ];
            }
        }

        throw new StagingUatException(
            "No se pudo identificar el ID de {$resource}. La fila UAT existe, pero no expone un identificador util en URLs/data-id."
        );
    }

    private function extractResourceIdFromUrl(string $resource, string $url): ?int
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : rawurldecode($url);

        if (preg_match('#/operacion/'.preg_quote($resource, '#').'/([0-9]+)(?:/|$)#', $path, $matches)) {
            $id = (int) $matches[1];
            return $id > 0 ? $id : null;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            foreach (['id', 'record_id', 'resource_id'] as $key) {
                if (isset($params[$key]) && ctype_digit((string) $params[$key]) && (int) $params[$key] > 0) {
                    return (int) $params[$key];
                }
            }
        }

        return null;
    }

    private function normalizeRecord(string $resource, int $id, array $details, string $showUrl): array
    {
        $record = [
            'id' => $id,
            'show_url' => $showUrl,
            'details' => $details,
        ];

        return match ($resource) {
            'cash-accounts' => $record + [
                'name' => $details['Nombre'] ?? null,
            ],
            'clients' => $record + [
                'legal_name' => $details['Razon social'] ?? null,
                'code' => $details['Codigo'] ?? null,
            ],
            'projects' => $record + [
                'name' => $details['Proyecto/Servicio'] ?? null,
                'code' => $details['Codigo'] ?? null,
            ],
            'people' => $record + [
                'first_names' => $details['Nombres'] ?? null,
                'code' => $details['Codigo'] ?? null,
            ],
            'assignments' => $record + [
                'code' => $details['Codigo'] ?? null,
            ],
            'time-entries' => $record + [
                'code' => $details['Codigo'] ?? null,
                'hours_approved' => self::parseLocalizedNumber($details['Horas aprobadas'] ?? '0'),
                'hourly_value' => self::parseLocalizedNumber($details['Tarifa aplicable'] ?? '0'),
            ],
            'sales-documents' => $record + [
                'code' => $details['Codigo'] ?? null,
                'net_amount' => self::parseLocalizedNumber($details['Neto'] ?? '0'),
                'vat_rate' => self::parseLocalizedNumber($details['IVA'] ?? '0') / 100,
                'vat_amount' => self::parseLocalizedNumber($details['Monto IVA'] ?? '0'),
                'gross_amount' => self::parseLocalizedNumber($details['Total'] ?? '0'),
                'status' => $details['Estado'] ?? null,
            ],
            'expense-documents' => $record + [
                'code' => $details['Codigo'] ?? null,
                'vendor_name' => $details['Proveedor'] ?? null,
                'gross_amount' => self::parseLocalizedNumber($details['Total'] ?? '0'),
            ],
            'legal-obligations' => $record + [
                'code' => $details['Codigo'] ?? null,
                'pending_amount' => self::parseLocalizedNumber($details['Pendiente'] ?? '0'),
            ],
            'cash-movements' => $record + [
                'code' => $details['Codigo'] ?? null,
            ],
            'budgets' => $record + [
                'period_date' => $details['Periodo'] ?? null,
            ],
            default => $record,
        };
    }

    private function resolveFieldValue(
        string $resource,
        string $field,
        mixed $value,
        array $options,
        string $currentValue = '',
    ): string {
        if (! is_array($value)) {
            return (string) $value;
        }

        $fieldOptions = $options[$field] ?? [];
        $labelContains = array_values(array_filter(array_map(
            static fn (string $term): string => mb_strtolower(trim($term)),
            (array) ($value['label_contains'] ?? [])
        )));

        if ($labelContains !== []) {
            $matched = $this->matchOptionByLabel($fieldOptions, $labelContains);
            if ($matched !== null) {
                return $matched;
            }
        }

        if (($value['first_non_empty'] ?? false) === true) {
            foreach ($fieldOptions as $option) {
                $candidate = (string) ($option['value'] ?? '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        // Algunos controles del aplicativo usan un input oculto/custom control y
        // no exponen <option> en el HTML que consume cURL. Si el formulario ya
        // trae un valor válido, no lo sobreescribimos con cadena vacía.
        if (trim($currentValue) !== '') {
            return $currentValue;
        }

        // Las monedas comparten el mismo catálogo. Si el mantenedor actual no
        // renderiza opciones, resolver el ID desde otro formulario que sí las
        // exponga, sin escribir datos ni acceder directamente a la BD.
        if ($labelContains !== [] && str_ends_with($field, 'currency_id')) {
            $currencyId = $this->resolveCurrencyIdFromForms($labelContains);
            if ($currencyId !== null) {
                return $currencyId;
            }
        }

        $availableLabels = [];
        foreach ($fieldOptions as $option) {
            $label = UatHtml::normalizeText((string) ($option['label'] ?? ''));
            if ($label !== '') {
                $availableLabels[] = $label;
            }
        }

        throw new StagingUatException(
            "No se pudo resolver {$resource}.{$field} antes del POST"
            .($labelContains === [] ? '' : ' buscando ['.implode(', ', $labelContains).']')
            .($availableLabels === [] ? '; el formulario no expone opciones utilizables.' : '; opciones: '.implode(' | ', array_slice($availableLabels, 0, 12)))
        );
    }

    private function matchOptionByLabel(array $fieldOptions, array $terms): ?string
    {
        foreach ($fieldOptions as $option) {
            $label = mb_strtolower((string) ($option['label'] ?? ''));
            $optionValue = (string) ($option['value'] ?? '');
            if ($optionValue === '') {
                continue;
            }

            foreach ($terms as $term) {
                if ($term !== '' && str_contains($label, $term)) {
                    return $optionValue;
                }
            }
        }

        return null;
    }

    private function resolveCurrencyIdFromForms(array $terms): ?string
    {
        $candidates = [
            ['cash-accounts', 'currency_id'],
            ['projects', 'sales_currency_id'],
            ['people', 'hourly_rate_currency_id'],
            ['assignments', 'hourly_rate_currency_id'],
        ];

        foreach ($candidates as [$resource, $field]) {
            try {
                $page = $this->user1->get('/operacion/'.$resource.'/crear');
                if ($page->status !== 200) {
                    continue;
                }

                $form = UatHtml::parseForm(
                    $page->body,
                    $this->baseUrl,
                    fn (DOMElement $form, string $action, string $method): bool =>
                        $method === 'POST' && str_contains($action, '/operacion/'.$resource)
                );

                $matched = $this->matchOptionByLabel((array) (($form['options'] ?? [])[$field] ?? []), $terms);
                if ($matched !== null) {
                    return $matched;
                }

                $current = $form['fields'][$field] ?? null;
                if (is_scalar($current) && trim((string) $current) !== '') {
                    return (string) $current;
                }
            } catch (Throwable) {
                // Continuar con el siguiente formulario candidato.
            }
        }

        return null;
    }

    private function recordCreate(string $label, callable $creator): array
    {
        $record = $creator();
        $this->created[$label][] = [
            'id' => $record['id'] ?? null,
            'code' => $record['code'] ?? null,
            'name' => $record['name'] ?? $record['legal_name'] ?? $record['first_names'] ?? null,
            'url' => $record['show_url'] ?? null,
        ];
        $this->cases[] = [
            'name' => $label,
            'status' => 'PASS',
            'evidence' => $record['show_url'] ?? ($record['code'] ?? 'Creado'),
        ];

        return $record;
    }

    private function recordCase(string $name, callable $callback): bool
    {
        try {
            $result = $callback();
            $this->cases[] = array_merge(['name' => $name], $result);

            return true;
        } catch (Throwable $throwable) {
            $this->cases[] = [
                'name' => $name,
                'status' => 'FAIL',
                'error' => $throwable->getMessage(),
            ];

            return false;
        }
    }

    private function hasFailures(): bool
    {
        foreach ($this->cases as $case) {
            if (($case['status'] ?? 'FAIL') !== 'PASS') {
                return true;
            }
        }

        return false;
    }

    private function writeJson(): void
    {
        $payload = [
            'run_id' => $this->runId,
            'timestamp' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'base_url' => $this->baseUrl,
            'cases' => $this->cases,
            'created_records' => $this->created,
            'summary' => array_merge($this->summary, [
                'http_500' => $this->user1->http500Count() + $this->user2->http500Count(),
                'result' => $this->hasFailures() ? 'FAIL' : 'PASS',
            ]),
        ];

        file_put_contents(
            $this->outputFile,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    private function renderCliSummary(): void
    {
        echo "STAGING MULTIUSER UAT\n";
        echo 'Run: '.$this->runId."\n\n";

        foreach ($this->cases as $case) {
            $status = (string) ($case['status'] ?? 'FAIL');
            $label = str_pad((string) $case['name'], 28, ' ');
            $suffix = $status === 'PASS'
                ? 'PASS'
                : 'FAIL'.(! empty($case['error']) ? ' - '.$case['error'] : '');

            echo $label.' '.$suffix."\n";
        }

        echo "\n";
        echo 'HTTP 500: '.($this->user1->http500Count() + $this->user2->http500Count())."\n";
        echo 'P0: '.($this->hasFailures() ? '1+' : '0')."\n";
        echo 'P1: 0'."\n";
        echo 'JSON: '.$this->outputFile."\n";
        echo 'Cleanup: MANUAL (registros prefijados con '.$this->runId.")\n";
        echo 'RESULTADO: '.($this->hasFailures() ? 'FAIL' : 'PASS')."\n";
    }

    private function tempFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if ($file === false) {
            throw new StagingUatException('No se pudo crear archivo temporal.');
        }

        return $file;
    }

    private function assertStatus(UatHttpResponse $response, int $expected, string $message): void
    {
        if ($response->status !== $expected) {
            throw new StagingUatException($message." HTTP {$response->status}.");
        }
    }

    private function assertApproximately(float $expected, float $actual, float $tolerance, string $message): void
    {
        if (abs($expected - $actual) > $tolerance) {
            throw new StagingUatException($message.' Esperado '.number_format($expected, 2, '.', '').', obtenido '.number_format($actual, 2, '.', '').'.');
        }
    }

    private static function parseLocalizedNumber(string $value): float
    {
        $value = trim($value);
        if ($value === '' || $value === '—') {
            return 0.0;
        }

        $value = preg_replace('/[^\d,\.\-]/u', '', $value) ?? $value;
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);

        return round((float) $value, 2);
    }

    private static function generateRutFromSeed(string $seed, int $offset): string
    {
        // El generador anterior tomaba solo los primeros 8 digitos del runId
        // (YYYYMMDD). Todas las ejecuciones del mismo dia producian los mismos
        // RUT y terminaban chocando con datos UAT de corridas previas.
        //
        // Usamos el runId completo (incluye hora) + offset y lo distribuimos
        // sobre un rango de 8 digitos. El DV se calcula normalmente, por lo que
        // el RUT sigue pasando la validacion modulo 11 de la aplicacion.
        $digest = hash('sha256', $seed.'|'.$offset);
        $bucket = (int) hexdec(substr($digest, 0, 8));
        $number = 10000000 + ($bucket % 80000000);

        return $number.'-'.self::rutDv($number);
    }

    private static function rutDv(int $number): string
    {
        $sum = 0;
        $multiplier = 2;

        while ($number > 0) {
            $sum += ($number % 10) * $multiplier;
            $number = intdiv($number, 10);
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }

        $remainder = 11 - ($sum % 11);

        return match ($remainder) {
            11 => '0',
            10 => 'K',
            default => (string) $remainder,
        };
    }
}

function loadEnvFile(string $path): array
{
    if (! is_file($path)) {
        throw new StagingUatException("No existe archivo de credenciales: {$path}");
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

    foreach (['UAT_BASE_URL', 'UAT_USER1_EMAIL', 'UAT_USER1_PASSWORD', 'UAT_USER2_EMAIL', 'UAT_USER2_PASSWORD'] as $required) {
        if (! array_key_exists($required, $values) || trim((string) $values[$required]) === '') {
            throw new StagingUatException("Falta variable requerida {$required} en el archivo de credenciales.");
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
$workspaceRoot = dirname($scriptDir);
$envPath = $scriptDir.'/.env.staging-uat';

try {
    if (! extension_loaded('curl') || ! extension_loaded('dom')) {
        throw new StagingUatException('Este runner requiere extensiones PHP curl y dom.');
    }

    $env = loadEnvFile($envPath);
    assertAuthorizedStagingHost((string) $env['UAT_BASE_URL']);
    $runner = new StagingMultiuserUatRunner($env, $workspaceRoot);
    exit($runner->run());
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
