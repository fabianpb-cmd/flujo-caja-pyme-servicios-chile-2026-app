<?php

namespace App\Services;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiPurchaseOrderExtractor
{
    public function extract(string $filename, string $pdf): array
    {
        $key = (string) config('assistant.api_key');
        if ($key === '') {
            throw new DomainException('La extracción de OC no está configurada. Contacte a administración.');
        }

        try {
            $response = Http::acceptJson()->withToken($key)->timeout((int) config('assistant.timeout'))
                ->post(rtrim((string) config('assistant.base_url'), '/').'/responses', [
                    'model' => config('assistant.model'),
                    'reasoning' => ['effort' => config('assistant.reasoning')],
                    'text' => ['format' => [
                        'type' => 'json_schema',
                        'name' => 'purchase_order_extraction',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ]],
                    'input' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_file', 'filename' => $filename, 'file_data' => 'data:application/pdf;base64,'.base64_encode($pdf), 'detail' => 'low'],
                            ['type' => 'input_text', 'text' => $this->prompt()],
                        ],
                    ]],
                ]);
        } catch (ConnectionException) {
            $this->logFailure(['error_code' => 'timeout']);
            throw new DomainException('No se pudo analizar la OC en este momento. Intente nuevamente.');
        }

        if (! $response->successful()) {
            $error = $response->json('error');
            $this->logFailure([
                'http_status' => $response->status(),
                'error_type' => is_array($error) ? ($error['type'] ?? null) : null,
                'error_code' => is_array($error) ? ($error['code'] ?? null) : null,
                'request_id' => $response->header('x-request-id'),
            ]);
            throw new DomainException('No se pudo analizar la OC en este momento. Intente nuevamente.');
        }

        $text = $this->extractOutputText($response->json());
        $data = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($data)) {
            $this->logFailure(['error_code' => 'invalid_json', 'request_id' => $response->header('x-request-id')]);
            throw new DomainException('La respuesta de análisis de la OC no fue válida. Intente nuevamente.');
        }

        return $data;
    }

    private function extractOutputText(array $response): ?string
    {
        if (is_string($response['output_text'] ?? null) && trim($response['output_text']) !== '') {
            return $response['output_text'];
        }

        $parts = [];
        foreach (($response['output'] ?? []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null) && trim($content['text']) !== '') {
                    $parts[] = $content['text'];
                }
            }
        }

        return $parts === [] ? null : implode('', $parts);
    }

    private function prompt(): string
    {
        return 'Eres un extractor de órdenes de compra. Extrae únicamente información explícitamente presente en el documento. No calcules ni infieras datos contractuales que no aparecen. No elijas catálogos internos. No inventes fechas, RUT, montos, monedas, condiciones de pago, tipo de proyecto, tipo de contrato, responsable, tarifa HH ni estados. Si un dato no está presente usa null. Los montos deben devolverse como números sin separadores. Las fechas deben devolverse YYYY-MM-DD únicamente cuando sean inequívocas.';
    }

    private function schema(): array
    {
        $fields = ['document_type', 'purchase_order_number', 'buyer_name', 'buyer_tax_id', 'issue_date', 'service_description', 'currency_code', 'net_amount', 'vat_amount', 'total_amount', 'payment_terms_days', 'payment_terms_text', 'service_start_date', 'service_end_date'];
        $nullableString = ['type' => ['string', 'null']];
        $properties = array_fill_keys($fields, $nullableString);
        $properties['document_type'] = ['type' => 'string', 'enum' => ['PURCHASE_ORDER', 'UNKNOWN']];
        foreach (['net_amount', 'vat_amount', 'total_amount', 'payment_terms_days'] as $field) {
            $properties[$field] = ['type' => ['number', 'null']];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                ...$properties,
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys($fields, ['type' => 'number']), 'required' => $fields],
                'evidence' => ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys($fields, $nullableString), 'required' => $fields],
            ],
            'required' => [...$fields, 'warnings', 'confidence', 'evidence'],
        ];
    }

    private function logFailure(array $context): void
    {
        Log::warning('Purchase order OpenAI extractor failure', array_intersect_key($context, array_flip(['http_status', 'error_type', 'error_code', 'request_id'])));
    }
}
