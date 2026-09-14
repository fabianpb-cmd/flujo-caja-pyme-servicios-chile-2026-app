<?php

namespace Tests\Feature;

use App\Services\AssistantContextService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssistantContextServiceTest extends TestCase
{
    public function test_it_uses_configured_field_metadata_and_strips_sensitive_values(): void
    {
        $context = app(AssistantContextService::class)->build([
            'resource' => 'sales-documents', 'focused_field' => 'net_amount',
            'form' => ['project_id' => '12', 'net_amount' => '1000', 'password' => 'secret', 'email' => 'private@example.test'],
        ], 'operational.create');
        $this->assertSame('Neto', $context['focused_definition']['label']);
        $this->assertSame(12, $context['ids']['project_id']);
        $this->assertSame('filled', $context['form']['net_amount']['state']);
        $this->assertArrayNotHasKey('password', $context['form']);
        $this->assertArrayNotHasKey('email', $context['form']);
    }

    public function test_it_rejects_unknown_resources_and_fields(): void
    {
        try { app(AssistantContextService::class)->build(['resource' => 'secrets'], 'x'); $this->fail('Expected resource validation.'); } catch (ValidationException) { $this->assertTrue(true); }
        $context = app(AssistantContextService::class)->build(['resource' => 'projects', 'focused_field' => 'password'], 'x');
        $this->assertNull($context['focused_field']);
    }
}
