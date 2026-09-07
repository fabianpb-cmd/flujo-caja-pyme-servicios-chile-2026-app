<?php

namespace Tests\Feature;

use App\Models\Company;
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

        $response = $this->actingAs($admin)->get(route('operational.create', 'cash-movements'));

        $response->assertOk()->assertSee('Fecha de cobro/pago')->assertSee('data-money-input="true"', false);
        $this->assertSame('2.627.141,58', \App\Support\UiFormatter::formatNumber(2627141.58));
    }
}
