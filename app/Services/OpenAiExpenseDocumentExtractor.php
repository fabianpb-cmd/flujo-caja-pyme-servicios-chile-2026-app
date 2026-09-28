<?php

namespace App\Services;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiExpenseDocumentExtractor
{
    public function extract(string $filename, string $pdf): array
    {
        $key = (string) config('assistant.api_key');
        if ($key === '') {
            throw new DomainException('La extracción de gastos no está configurada. Contacte a administración.');
        }
        try {
            $response = Http::acceptJson()->withToken($key)->timeout((int) config('assistant.timeout'))
                ->post(rtrim((string) config('assistant.base_url'), '/').'/responses', [
                    'model' => config('assistant.model'),
                    'reasoning' => ['effort' => config('assistant.reasoning')],
                    'text' => ['format' => ['type' => 'json_schema', 'name' => 'expense_document_extraction', 'strict' => true, 'schema' => $this->schema()]],
                    'input' => [['role' => 'user', 'content' => [
                        ['type' => 'input_file', 'filename' => $filename, 'file_data' => 'data:application/pdf;base64,'.base64_encode($pdf), 'detail' => 'low'],
                        ['type' => 'input_text', 'text' => $this->prompt()],
                    ]]],
                ]);
        } catch (ConnectionException) {
            Log::warning('Expense PDF extractor failure', ['error_code' => 'timeout']);
            throw new DomainException('No se pudo analizar el documento de gasto en este momento.');
        }
        if (! $response->successful()) {
            Log::warning('Expense PDF extractor failure', ['http_status' => $response->status(), 'request_id' => $response->header('x-request-id')]);
            throw new DomainException('No se pudo analizar el documento de gasto en este momento.');
        }
        $text = $response->json('output_text');
        if (! is_string($text) || trim($text) === '') {
            foreach (($response->json('output') ?? []) as $item) {
                foreach (($item['content'] ?? []) as $content) {
                    if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                        $text = $content['text'];
                    }
                }
            }
        }
        $data = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($data)) {
            throw new DomainException('La respuesta de análisis del gasto no fue válida.');
        }
        return $data;
    }

    private function prompt(): string
    {
        return 'Extrae únicamente datos explícitos del documento de proveedor. Identifica el tipo de documento, proveedor, fechas, moneda, importes y condiciones de pago. Las fechas inequívocas deben normalizarse a YYYY-MM-DD; no inventes fechas ni hagas aritmética. Conserva evidencia breve por campo. Si falta un dato usa null. No elijas catálogos, IVA recuperable, cliente, proyecto, categorías, estado de pago ni códigos internos. Si la moneda explícita no es CLP, adviértelo. No almacenes razonamiento interno.';
    }

    private function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $fields = ['document_type', 'document_number', 'supplier_name', 'supplier_tax_id', 'issue_date', 'due_date', 'payment_terms_text', 'currency_code', 'description'];
        $properties = array_fill_keys($fields, $nullableString);
        foreach (['payment_terms_days', 'net_amount', 'vat_rate', 'vat_amount', 'exempt_amount', 'total_amount'] as $field) {
            $properties[$field] = ['type' => ['number', 'null']];
        }
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [...$properties,
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            'confidence' => ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys(array_keys($properties), ['type' => 'number']), 'required' => array_keys($properties)],
            'evidence' => ['type' => 'object', 'additionalProperties' => false, 'properties' => array_fill_keys(array_keys($properties), $nullableString), 'required' => array_keys($properties)],
        ], 'required' => [...array_keys($properties), 'warnings', 'confidence', 'evidence']];
    }
}
