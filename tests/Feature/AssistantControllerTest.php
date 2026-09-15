<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Models\Company;
use App\Models\ContractType;
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
        $this->assertStringContainsString('data-assistant-launcher', $panel);
        $this->assertStringNotContainsString('bottom-0', $panel);
        $this->assertStringNotContainsString('end-0', $panel);
        $this->assertStringNotContainsString('m-4', $panel);
        $this->assertStringContainsString('right: 24px; bottom: 24px;', $panel);
        $this->assertStringContainsString('requestAnimationFrame', $panel);
        $this->assertStringContainsString('data-assistant-avoid-overlap', file_get_contents(resource_path('views/operational/form.blade.php')));
        $this->assertStringContainsString('data-assistant-avoid-overlap', file_get_contents(resource_path('views/operational/sales-guided-form.blade.php')));
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

    public function test_dashboard_context_calls_provider_and_uses_screen_knowledge(): void
    {
        config()->set(['assistant.enabled' => true, 'assistant.per_minute' => 10, 'assistant.per_day' => 20]);
        $this->app->bind(AiProvider::class, fn () => new class implements AiProvider {
            public function ask(array $context): array { return ['status' => 'DEFINED', 'answer' => 'Dashboard es consulta.', 'source_ids' => ['PAGE-DASHBOARD-001']]; }
        });
        $this->actingAs($this->user)->withHeader('Referer', route('dashboard'))->postJson(route('assistant.ask'), ['question' => '¿Qué debo ingresar?'])->assertOk()->assertJsonPath('status', 'DEFINED')->assertJsonPath('source_ids.0', 'PAGE-DASHBOARD-001');
    }

    public function test_unknown_screen_does_not_call_provider(): void
    {
        config()->set('assistant.enabled', true);
        $this->app->bind(AiProvider::class, fn () => new class implements AiProvider { public function ask(array $context): array { throw new \RuntimeException('provider should not run'); } });
        $this->actingAs($this->user)->withHeader('Referer', url('/unknown-screen'))->postJson(route('assistant.ask'), ['question' => '¿Qué debo ingresar?'])->assertOk()->assertJsonPath('status', 'NOT_DEFINED');
    }

    public function test_project_screen_guide_is_sent_to_the_provider_with_safe_missing_field_states(): void
    {
        config()->set(['assistant.enabled' => true, 'assistant.per_minute' => 10, 'assistant.per_day' => 20]);
        $provider = new class implements AiProvider {
            public array $context = [];

            public function ask(array $context): array
            {
                $this->context = $context;
                return ['status' => 'DEFINED', 'answer' => 'Completa los campos requeridos visibles.', 'source_ids' => ['FORM-GUIDE:projects']];
            }
        };
        $this->app->instance(AiProvider::class, $provider);
        $monthly = ContractType::query()->create([
            'company_id' => $this->user->company_id,
            'domain' => 'commercial',
            'code' => 'MENSUAL_RECURRENTE',
            'name' => 'Mensual recurrente',
            'active' => true,
        ]);

        $this->actingAs($this->user)->withHeader('Referer', route('operational.create', ['projects']))
            ->postJson(route('assistant.ask'), [
                'question' => '¿Cómo completo esta pantalla?',
                'resource' => 'projects',
                'form' => ['client_id' => '', 'name' => 'filled', 'project_status_id' => '', 'billing_status_id' => 'filled', 'contract_type_id' => (string) $monthly->id, 'sale_net' => '123456'],
            ])->assertOk()->assertJsonPath('status', 'DEFINED')->assertJsonPath('source_ids.0', 'FORM-GUIDE:projects');

        $this->assertSame('FORM-GUIDE:projects', $provider->context['FORM_GUIDE']['source_id']);
        $this->assertContains('Cliente', $provider->context['FORM_GUIDE']['missing_required_fields']);
        $this->assertSame('MONTHLY_RECURRING', $provider->context['FORM_GUIDE']['contract_strategy']['strategy']);
        $this->assertStringNotContainsString('123456', json_encode($provider->context['FORM_GUIDE']));
    }
}
