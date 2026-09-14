<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::query()->create(['code' => 'CMP-AI', 'name' => 'AI', 'status' => 'active']);
        $this->user = User::query()->create(['company_id' => $company->id, 'name' => 'Admin', 'email' => 'ai@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);
    }

    public function test_endpoint_is_authenticated_and_disabled_without_provider_call(): void
    {
        $this->postJson(route('assistant.ask'), ['question' => 'Hola'])->assertRedirect(route('login'));
        config()->set('assistant.enabled', false);
        $this->app->bind(AiProvider::class, fn () => new class implements AiProvider { public function ask(array $context): array { throw new \RuntimeException('provider should not run'); } });
        $this->actingAs($this->user)->postJson(route('assistant.ask'), ['question' => 'Hola', 'resource' => 'projects'])->assertOk()->assertJsonPath('answer', 'El Ayudante TDAT todavía no está habilitado.');
        $panel = file_get_contents(resource_path('views/components/assistant-panel.blade.php'));
        $this->assertStringContainsString('nonce="{{ $cspNonce ?? \'\' }}"', $panel);
        $this->assertStringNotContainsString('onclick=', $panel);
    }

    public function test_validation_rate_limit_and_read_only_request_contract(): void
    {
        config()->set(['assistant.enabled' => true, 'assistant.per_minute' => 1, 'assistant.per_day' => 20]);
        $this->app->bind(AiProvider::class, fn () => new class implements AiProvider { public function ask(array $context): array { return ['status' => 'DEFINED', 'answer' => 'Regla', 'source_ids' => ['GLOBAL-READONLY-001']]; } });
        $this->withoutExceptionHandling();
        try { $this->actingAs($this->user)->postJson(route('assistant.ask'), ['resource' => 'projects']); $this->fail('Expected question validation.'); } catch (\Illuminate\Validation\ValidationException) { $this->assertTrue(true); }
        $this->withExceptionHandling();
        $this->actingAs($this->user)->postJson(route('assistant.ask'), ['question' => '¿Qué debo ingresar?', 'resource' => 'projects', 'form' => ['password' => 'secret']])->assertOk()->assertJsonPath('status', 'DEFINED');
        $this->actingAs($this->user)->postJson(route('assistant.ask'), ['question' => 'otra', 'resource' => 'projects'])->assertStatus(429);
        RateLimiter::clear('assistant:minute:'.$this->user->id);
    }
}
