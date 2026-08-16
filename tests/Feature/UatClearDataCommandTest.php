<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UatClearDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rejects_production_environment(): void
    {
        config(['app.env' => 'production']);

        $response = $this->artisan('uat:clear-data', ['--force' => true]);

        $response->assertExitCode(1);
        $response->expectsOutputToContain('deshabilitado en producción');
    }
}
