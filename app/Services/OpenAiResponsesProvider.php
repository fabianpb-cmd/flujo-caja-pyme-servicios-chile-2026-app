<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Exceptions\AssistantProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiResponsesProvider implements AiProvider
{
    public function ask(array $context): array
    {
        $key = (string) config('assistant.api_key');
        if ($key === '') {
            throw new AssistantProviderException('unconfigured');
        }

        try {
            $response = Http::acceptJson()->withToken($key)->timeout((int) config('assistant.timeout'))
                ->post(rtrim((string) config('assistant.base_url'), '/').'/responses', [
                    'model' => config('assistant.model'),
                    'reasoning' => ['effort' => config('assistant.reasoning')],
                    'max_output_tokens' => (int) config('assistant.max_output_tokens'),
                    'text' => ['format' => ['type' => 'json_object']],
                    'input' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                    ],
                ]);
        } catch (ConnectionException) {
            $this->logFailure(['error_code' => 'timeout']);
            throw new AssistantProviderException('timeout');
        }

        if (! $response->successful()) {
            $error = $response->json('error');
            $this->logFailure([
                'http_status' => $response->status(),
                'error_type' => is_array($error) ? ($error['type'] ?? null) : null,
                'error_code' => is_array($error) ? ($error['code'] ?? null) : null,
                'request_id' => $response->header('x-request-id'),
            ]);
            throw new AssistantProviderException('provider_'.$response->status());
        }

        $text = $this->extractOutputText($response->json());
        $decoded = is_string($text) && trim($text) !== '' ? json_decode($text, true) : null;
        if (! is_array($decoded)) {
            $this->logFailure([
                'error_code' => 'invalid_json',
                'request_id' => $response->header('x-request-id'),
            ]);
            throw new AssistantProviderException('invalid_json');
        }

        return $decoded;
    }

    private function extractOutputText(array $response): ?string
    {
        $topLevel = $response['output_text'] ?? null;
        if (is_string($topLevel) && trim($topLevel) !== '') {
            return $topLevel;
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

    private function logFailure(array $context): void
    {
        Log::warning('Assistant OpenAI provider failure', array_intersect_key($context, array_flip([
            'http_status', 'error_type', 'error_code', 'request_id',
        ])));
    }

    private function systemPrompt(): string
    {
        return 'Eres Ayudante TDAT, asistente read-only del aplicativo Flujo de Caja Pyme. Responde exclusivamente usando KNOWLEDGE_RULES, FORM_CONTEXT, FORM_GUIDE y BUSINESS_CONTEXT entregados. FORM_GUIDE describe la estructura y uso de la pantalla actual, pero no autoriza inventar valores ni semántica de negocio; cuando la uses, cita exclusivamente su source_id controlado. No uses conocimiento general para completar datos del negocio. No inventes montos, fechas, personas, clientes, proyectos, condiciones de pago, cuentas, estados ni catálogos. Si una regla no está respaldada responde status NOT_DEFINED. Si falta un dato del usuario responde REQUIRED. Si falta configuración responde ADMIN_REQUIRED. Si proviene de cálculo autoritativo responde CALCULATED. No afirmes haber ejecutado operaciones. Responde breve, claro y en español, como JSON con status, answer, source_ids, required_information y warnings.';
    }
}
