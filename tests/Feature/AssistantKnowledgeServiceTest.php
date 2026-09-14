<?php

namespace Tests\Feature;

use App\Services\AssistantKnowledgeService;
use Tests\TestCase;

class AssistantKnowledgeServiceTest extends TestCase
{
    public function test_it_selects_verified_rules_and_keeps_global_rules(): void
    {
        $rules = app(AssistantKnowledgeService::class)->select('¿Cómo se factura un proyecto cerrado por hito?', ['resource' => 'sales-documents', 'focused_field' => null, 'route' => 'operational.create']);
        $this->assertContains('GLOBAL-READONLY-001', array_column($rules, 'id'));
        $this->assertContains('PROJECT-CLOSED-001', array_column($rules, 'id'));
        $this->assertLessThanOrEqual(12, count($rules));
    }

    public function test_unknown_question_only_has_global_rules(): void
    {
        $rules = app(AssistantKnowledgeService::class)->select('¿Cuál es la temperatura de Marte?', ['resource' => null, 'focused_field' => null, 'route' => 'dashboard']);
        $this->assertSame(['GLOBAL-READONLY-001', 'GLOBAL-CATALOG-001', 'GLOBAL-READONLY-002'], array_column($rules, 'id'));
    }
}
