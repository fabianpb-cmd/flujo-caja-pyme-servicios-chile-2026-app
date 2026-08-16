<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_route_responds_ok_without_authentication(): void
    {
        $this->get('/up')->assertOk();
    }
}
