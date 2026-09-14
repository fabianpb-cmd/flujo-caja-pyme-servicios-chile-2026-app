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

    public function test_screen_keys_retrieve_contextual_rules_without_a_resource(): void
    {
        $service = app(AssistantKnowledgeService::class);
        $this->assertContains('PAGE-DASHBOARD-001', array_column($service->select('¿Qué debo ingresar?', ['resource' => null, 'page_key' => 'dashboard', 'route' => 'dashboard']), 'id'));
        $this->assertContains('PAGE-AGENDA-001', array_column($service->select('Explícame esta pantalla', ['resource' => null, 'page_key' => 'management.financial-agenda', 'route' => 'management.financial-agenda']), 'id'));
        $this->assertContains('PAGE-OBLIGATIONS-001', array_column($service->select('¿Qué debo ingresar?', ['resource' => null, 'page_key' => 'management.obligations', 'route' => 'management.obligations']), 'id'));
        $this->assertContains('PAGE-BUDGETS-001', array_column($service->select('¿Qué puedo hacer aquí?', ['resource' => null, 'page_key' => 'management.budgets', 'route' => 'management.budgets']), 'id'));
        $this->assertContains('PAGE-FLOWS-001', array_column($service->select('¿Cómo se calcula?', ['resource' => null, 'page_key' => 'management.flows', 'route' => 'management.flows']), 'id'));
        $this->assertContains('PAGE-PROFITABILITY-001', array_column($service->select('Explícame esta pantalla', ['resource' => null, 'page_key' => 'management.profitability', 'route' => 'management.profitability']), 'id'));
        $this->assertContains('PAGE-BANK-STATEMENTS-001', array_column($service->select('¿Qué puedo hacer aquí?', ['resource' => null, 'page_key' => 'bank-statements', 'route' => 'bank-statements.index']), 'id'));
        $this->assertContains('PAGE-BANK-REGULARIZATION-001', array_column($service->select('¿Para qué sirve esta pantalla?', ['resource' => null, 'page_key' => 'bank-regularization', 'route' => 'bank-regularization.index']), 'id'));
        $this->assertContains('PAGE-BANK-RECONCILIATION-001', array_column($service->select('¿Qué puedo hacer aquí?', ['resource' => null, 'page_key' => 'bank-reconciliation', 'route' => 'bank-reconciliation.index']), 'id'));
        $this->assertContains('PAGE-RECEIVABLES-001', array_column($service->select('Explícame esta pantalla', ['resource' => null, 'page_key' => 'receivables', 'route' => 'receivables.index']), 'id'));
        $this->assertContains('PAGE-PAYABLES-001', array_column($service->select('Explícame esta pantalla', ['resource' => null, 'page_key' => 'payables', 'route' => 'payables.index']), 'id'));
    }

    public function test_assignment_question_without_focused_field_retrieves_hourly_cost_rule(): void
    {
        $rules = app(AssistantKnowledgeService::class)->select(
            'que son los Valor HH de costeo del proyecto',
            ['resource' => 'assignments', 'focused_field' => null, 'route' => 'operational.edit'],
        );

        $this->assertContains('ASSIGNMENT-HOURLY-COST-001', array_column($rules, 'id'));
    }

    public function test_focused_assignment_field_prioritizes_its_verified_rule(): void
    {
        $rules = app(AssistantKnowledgeService::class)->select(
            '¿qué significa este campo?',
            ['resource' => 'assignments', 'focused_field' => 'hourly_value', 'route' => 'operational.edit'],
        );

        $assignmentRules = array_values(array_filter($rules, fn (array $rule): bool => $rule['module'] === 'assignments'));
        $this->assertSame('ASSIGNMENT-HOURLY-COST-001', $assignmentRules[0]['id']);
    }

    public function test_assignment_cost_and_commercial_question_retrieves_distinction_rule(): void
    {
        $rules = app(AssistantKnowledgeService::class)->select(
            '¿es lo mismo que la tarifa comercial?',
            ['resource' => 'assignments', 'focused_field' => null, 'route' => 'operational.edit'],
        );

        $this->assertContains('ASSIGNMENT-COMMERCIAL-VS-COST-001', array_column($rules, 'id'));
    }

    public function test_assignment_project_value_question_retrieves_specific_rule(): void
    {
        $rules = app(AssistantKnowledgeService::class)->select(
            '¿qué significa monto pactado por proyecto/hito?',
            ['resource' => 'assignments', 'focused_field' => null, 'route' => 'operational.edit'],
        );

        $this->assertContains('ASSIGNMENT-PROJECT-VALUE-001', array_column($rules, 'id'));
    }

    public function test_assignment_monthly_hours_question_retrieves_specific_rule(): void
    {
        $rules = app(AssistantKnowledgeService::class)->select(
            '¿qué son las horas mensuales comprometidas?',
            ['resource' => 'assignments', 'focused_field' => null, 'route' => 'operational.edit'],
        );

        $this->assertContains('ASSIGNMENT-MONTHLY-HOURS-001', array_column($rules, 'id'));
    }

    public function test_unknown_assignment_question_does_not_retrieve_unrelated_rules(): void
    {
        $rules = app(AssistantKnowledgeService::class)->select(
            '¿qué porcentaje de descuento debo aplicar?',
            ['resource' => 'assignments', 'focused_field' => null, 'route' => 'operational.edit'],
        );

        $this->assertSame([], array_values(array_filter(array_column($rules, 'module'), fn (string $module): bool => $module === 'assignments')));
    }

    public function test_project_contract_questions_retrieve_consumable_strategy_rules(): void
    {
        $service = app(AssistantKnowledgeService::class);
        $context = ['resource' => 'projects', 'focused_field' => 'contract_type_id', 'route' => 'operational.edit'];

        $this->assertContains('PROJECT-CONTRACT-HOURS-BANK-001', array_column($service->select('¿Qué significa Bolsa de horas y qué pasa si excedo la bolsa?', $context), 'id'));
        $this->assertContains('PROJECT-CONTRACT-MONTHLY-RECURRING-001', array_column($service->select('¿Qué es Mensual recurrente? ¿Se acumulan las horas no usadas?', $context), 'id'));
    }
}
