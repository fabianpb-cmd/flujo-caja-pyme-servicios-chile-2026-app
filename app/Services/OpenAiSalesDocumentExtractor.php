<?php
namespace App\Services;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class OpenAiSalesDocumentExtractor
{
    public function extract(string $filename, string $pdf): array
    {
        $key = (string) config('assistant.api_key'); if ($key === '') throw new DomainException('La extracción de facturas no está configurada.');
        try {
            $response = Http::acceptJson()->withToken($key)->timeout((int) config('assistant.timeout'))->post(
                rtrim((string) config('assistant.base_url'), '/').'/responses',
                [
                    'model' => config('assistant.model'),
                    'reasoning' => ['effort' => config('assistant.reasoning')],
                    'text' => ['format' => ['type' => 'json_schema', 'name' => 'sales_document_extraction', 'strict' => true, 'schema' => $this->schema()]],
                    'input' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_file', 'filename' => $filename, 'file_data' => 'data:application/pdf;base64,'.base64_encode($pdf), 'detail' => 'low'],
                            ['type' => 'input_text', 'text' => 'Extrae solo datos explícitos de una factura o documento de venta. Normaliza fechas inequívocas a YYYY-MM-DD. No inventes datos, no calcules importes, no elijas catálogos ni cambies cálculos contractuales. Conserva evidencia breve por campo.'],
                        ],
                    ]],
                ],
            );
        }
        catch (ConnectionException) { Log::warning('Sales PDF extractor failure', ['error_code' => 'timeout']); throw new DomainException('No se pudo analizar la factura en este momento.'); }
        if (! $response->successful()) { Log::warning('Sales PDF extractor failure', ['http_status' => $response->status(), 'request_id' => $response->header('x-request-id')]); throw new DomainException('No se pudo analizar la factura en este momento.'); }
        $text = $response->json('output_text'); foreach (($response->json('output') ?? []) as $item) foreach (($item['content'] ?? []) as $content) if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) $text = $content['text'];
        $data = is_string($text) ? json_decode($text, true) : null; if (! is_array($data)) throw new DomainException('La respuesta de análisis de la factura no fue válida.'); return $data;
    }
    private function schema(): array
    {
        $nullable = ['type' => ['string', 'null']]; $fields = ['document_type', 'document_number', 'issuer_name', 'issuer_tax_id', 'customer_name', 'customer_tax_id', 'issue_date', 'due_date', 'payment_terms_text', 'currency_code', 'description']; $properties = array_fill_keys($fields, $nullable);
        foreach (['payment_terms_days', 'net_amount', 'vat_rate', 'vat_amount', 'exempt_amount', 'total_amount'] as $field) $properties[$field] = ['type' => ['number', 'null']];
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [...$properties, 'warnings' => ['type' => 'array', 'items' => ['type' => 'string']], 'confidence' => ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys(array_keys($properties), ['type' => 'number']), 'required' => array_keys($properties)], 'evidence' => ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys(array_keys($properties), $nullable), 'required' => array_keys($properties)]], 'required' => [...array_keys($properties), 'warnings', 'confidence', 'evidence']];
    }
}
