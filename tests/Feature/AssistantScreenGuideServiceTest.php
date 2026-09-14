<?php

namespace Tests\Feature;

use App\Services\AssistantScreenGuideService;
use Tests\TestCase;

class AssistantScreenGuideServiceTest extends TestCase
{
    public function test_it_builds_a_safe_guide_for_every_operational_resource(): void
    {
        $service = app(AssistantScreenGuideService::class);

        foreach (array_keys(config('operational')) as $resource) {
            $guide = $service->build(['resource' => $resource, 'form' => []]);

            $this->assertNotNull($guide, $resource);
            $this->assertNotSame('', $guide['screen']);
            $this->assertSame('FORM-GUIDE:'.$resource, $guide['source_id']);
            foreach ($guide['fields'] as $field) {
                $this->assertArrayHasKey('classification', $field);
                $this->assertArrayHasKey('required', $field);
                if ($field['depends_on']) {
                    $this->assertContains($field['depends_on'], array_column($guide['fields'], 'key'));
                }
            }
        }
    }

    public function test_project_guide_uses_required_field_states_without_record_values(): void
    {
        $guide = app(AssistantScreenGuideService::class)->build([
            'resource' => 'projects',
            'form' => [
                'client_id' => ['state' => 'empty'],
                'name' => ['state' => 'filled'],
                'project_status_id' => ['state' => 'empty'],
                'billing_status_id' => ['state' => 'filled'],
                'sale_net' => ['state' => 'filled', 'value' => 'confidencial'],
            ],
        ]);

        $this->assertContains('Cliente', $guide['missing_required_fields']);
        $this->assertContains('Estado proyecto', $guide['missing_required_fields']);
        $this->assertNotContains('Proyecto/Servicio', $guide['missing_required_fields']);
        $this->assertSame('CATALOG_SELECTION', collect($guide['fields'])->firstWhere('key', 'client_id')['classification']);
        $this->assertSame('SYSTEM_CALCULATED', collect($guide['fields'])->firstWhere('key', 'sale_total')['classification']);
        $this->assertStringNotContainsString('confidencial', json_encode($guide));
    }
}
