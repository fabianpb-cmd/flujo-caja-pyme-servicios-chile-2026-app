<?php

namespace Tests\Feature;

use App\Exceptions\AssistantProviderException;
use App\Services\OpenAiResponsesProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_it_skips_reasoning_items_and_reads_the_first_message(): void
    {
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['*' => Http::response(['output' => [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['status' => 'DEFINED', 'answer' => 'Regla', 'source_ids' => ['RULE']])]]],
        ]])]);
        $response = app(OpenAiResponsesProvider::class)->ask(['question' => 'x']);
        $this->assertSame('DEFINED', $response['status']);
    }

    public function test_it_reads_a_message_at_output_zero(): void
    {
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['*' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"status":"DEFINED","answer":"Regla","source_ids":["RULE"]}']]]]])]);
        $this->assertSame('DEFINED', app(OpenAiResponsesProvider::class)->ask(['question' => 'x'])['status']);
    }

    public function test_it_concatenates_multiple_output_text_parts_in_order(): void
    {
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['*' => Http::response(['output' => [['type' => 'message', 'content' => [
            ['type' => 'output_text', 'text' => '{"status":"DEFINED",'],
            ['type' => 'output_text', 'text' => '"answer":"Regla","source_ids":["RULE"]}'],
        ]]]])]);
        $this->assertSame('Regla', app(OpenAiResponsesProvider::class)->ask(['question' => 'x'])['answer']);
    }

    public function test_it_never_exposes_provider_bodies_on_failure(): void
    {
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['*' => Http::response(['error' => ['message' => 'secret provider body']], 401)]);
        try { app(OpenAiResponsesProvider::class)->ask(['question' => 'x']); $this->fail('Expected provider failure.'); } catch (AssistantProviderException $exception) { $this->assertSame('provider_401', $exception->getMessage()); }
    }

    public function test_it_logs_only_safe_metadata_for_http_failures(): void
    {
        config()->set('assistant.api_key', 'secret-api-key');
        Log::spy();
        Log::shouldReceive('warning')->times(3)->withArgs(function (string $message, array $context): bool {
            return $message === 'Assistant OpenAI provider failure'
                && isset($context['http_status'], $context['error_type'], $context['error_code'], $context['request_id'])
                && ! str_contains(json_encode($context), 'PRIVATE')
                && ! str_contains(json_encode($context), 'secret-api-key')
                && array_diff(array_keys($context), ['http_status', 'error_type', 'error_code', 'request_id']) === [];
        });

        foreach ([400, 401, 429] as $status) {
            Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'bad_request', 'message' => 'PROMPT BODY SECRET']], $status, ['x-request-id' => 'req-'.$status])]);
            try { app(OpenAiResponsesProvider::class)->ask(['question' => 'PRIVATE PROMPT', 'FORM_CONTEXT' => ['amount' => 'PRIVATE']]); } catch (AssistantProviderException) { }
        }

    }

    public function test_it_logs_invalid_json_without_response_body(): void
    {
        config()->set('assistant.api_key', 'secret-api-key');
        Log::spy();
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Assistant OpenAI provider failure'
                && $context === ['error_code' => 'invalid_json', 'request_id' => 'req-json'];
        });
        Http::fake(['*' => Http::response(['output_text' => 'PRIVATE RESPONSE BODY'], 200, ['x-request-id' => 'req-json'])]);

        try { app(OpenAiResponsesProvider::class)->ask(['question' => 'PRIVATE PROMPT']); $this->fail('Expected invalid JSON.'); } catch (AssistantProviderException $exception) { $this->assertSame('invalid_json', $exception->getMessage()); }

    }

    public function test_output_without_message_or_output_text_is_invalid_json_and_safe(): void
    {
        config()->set('assistant.api_key', 'secret-api-key');
        Log::spy();
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Assistant OpenAI provider failure' && $context === ['error_code' => 'invalid_json', 'request_id' => 'req-empty'];
        });
        Http::fake(['*' => Http::response(['output' => [['type' => 'reasoning', 'summary' => [['text' => 'PRIVATE BODY']]]]], 200, ['x-request-id' => 'req-empty'])]);

        try { app(OpenAiResponsesProvider::class)->ask(['question' => 'PRIVATE PROMPT', 'BUSINESS_CONTEXT' => ['value' => 'PRIVATE']]); $this->fail('Expected invalid JSON.'); } catch (AssistantProviderException $exception) { $this->assertSame('invalid_json', $exception->getMessage()); }
    }
}
