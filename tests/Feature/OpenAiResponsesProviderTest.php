<?php

namespace Tests\Feature;

use App\Exceptions\AssistantProviderException;
use App\Services\OpenAiResponsesProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiResponsesProviderTest extends TestCase
{
    public function test_it_uses_responses_api_and_parses_structured_json(): void
    {
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['https://api.openai.test/*' => Http::response(['output_text' => json_encode(['status' => 'DEFINED', 'answer' => 'Regla', 'source_ids' => ['RULE']])])]);
        config()->set('assistant.base_url', 'https://api.openai.test/v1');
        $response = app(OpenAiResponsesProvider::class)->ask(['question' => 'x']);
        $this->assertSame('DEFINED', $response['status']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.test/v1/responses' && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_it_never_exposes_provider_bodies_on_failure(): void
    {
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['*' => Http::response(['error' => ['message' => 'secret provider body']], 401)]);
        try { app(OpenAiResponsesProvider::class)->ask(['question' => 'x']); $this->fail('Expected provider failure.'); } catch (AssistantProviderException $exception) { $this->assertSame('provider_401', $exception->getMessage()); }
    }
}
