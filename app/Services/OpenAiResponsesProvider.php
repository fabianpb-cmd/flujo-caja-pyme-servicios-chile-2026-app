<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Exceptions\AssistantProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
            throw new AssistantProviderException('timeout');
        }

        if (! $response->successful()) {
            throw new AssistantProviderException('provider_'.$response->status());
        }

        $text = (string) ($response->json('output_text') ?? data_get($response->json('output'), '0.content.0.text', ''));
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new AssistantProviderException('invalid_json');
        }

        return $decoded;
    }

    private function systemPrompt(): string
    {
        return 'Eres Ayudante TDAT, asistente read-only del aplicativo Flujo de Caja Pyme. Responde exclusivamente usando KNOWLEDGE_RULES, FORM_CONTEXT y BUSINESS_CONTEXT entregados. No uses conocimiento general para completar datos del negocio. No inventes montos, fechas, personas, clientes, proyectos, condiciones de pago, cuentas, estados ni catálogos. Si una regla no está respaldada responde status NOT_DEFINED. Si falta un dato del usuario responde REQUIRED. Si falta configuración responde ADMIN_REQUIRED. Si proviene de cálculo autoritativo responde CALCULATED. No afirmes haber ejecutado operaciones. Responde breve, claro y en español, como JSON con status, answer, source_ids, required_information y warnings.';
    }
}
