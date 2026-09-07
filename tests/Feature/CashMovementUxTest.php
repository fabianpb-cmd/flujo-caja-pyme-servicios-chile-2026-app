<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_movement_form_uses_payment_date_label_and_human_money_input(): void
    {
        $company = Company::query()->create(['code' => 'CMP-CASH-UX', 'name' => 'Caja UX', 'status' => 'active']);
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin', 'email' => 'cash-ux@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);

        $movement = CashMovement::query()->forceCreate([
            'company_id' => $company->id,
            'code' => 'ING-UX-0001',
            'movement_type' => 'Ingreso',
            'movement_date' => '2026-09-07',
            'income' => 2627141.58,
            'expense' => 0,
            'status' => 'posted',
            'created_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('operational.edit', ['resource' => 'cash-movements', 'record' => $movement->id]));

        $response->assertOk()->assertSee('Fecha de cobro/pago')->assertSee('value="2.627.141,58"', false);
        $content = $response->getContent();
        $this->assertDoesNotMatchRegularExpression("/moneyInputs[\\s\\S]{0,300}addEventListener\\('focus'[\\s\\S]{0,150}normalizeLocalizedNumber\\(input\\.value\\)/", $content);
        $this->assertStringContainsString("input.value = normalizeLocalizedNumber(input.value);", $content);
        $this->assertSame('2.627.141,58', \App\Support\UiFormatter::formatNumber(2627141.58));
    }
}
