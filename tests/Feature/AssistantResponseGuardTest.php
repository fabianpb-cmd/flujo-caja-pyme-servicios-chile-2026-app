<?php

namespace Tests\Feature;

use App\Services\AssistantResponseGuard;
use Tests\TestCase;

class AssistantResponseGuardTest extends TestCase
{
    public function test_it_rejects_unverified_sources_or_claimed_mutations(): void
    {
        $guard = app(AssistantResponseGuard::class);
        $this->assertSame('NOT_DEFINED', $guard->guard(['status' => 'DEFINED', 'answer' => 'Respuesta', 'source_ids' => []], ['RULE'])['status']);
        $this->assertSame('NOT_DEFINED', $guard->guard(['status' => 'DEFINED', 'answer' => 'Guardé el documento.', 'source_ids' => ['RULE']], ['RULE'])['status']);
        $this->assertSame('NOT_DEFINED', $guard->guard(['status' => 'CALCULATED', 'answer' => 'Monto', 'source_ids' => ['FAKE']], ['RULE'])['status']);
    }

    public function test_it_preserves_a_verified_read_only_response(): void
    {
        $response = app(AssistantResponseGuard::class)->guard(['status' => 'DEFINED', 'answer' => '<b>Regla</b>', 'source_ids' => ['RULE']], ['RULE']);
        $this->assertSame('DEFINED', $response['status']);
        $this->assertSame('Regla', $response['answer']);
    }
}
