<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\LegalParameter;
use App\Models\PaymentTerm;
use App\Models\Scenario;
use App\Models\TaxRegime;
use App\Models\UfValue;
use App\Services\CatalogService;
use Illuminate\Database\Seeder;

class OperationalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->orderBy('id')->each(function (Company $company) {
            app(CatalogService::class)->seedDefaultsForCompany($company->id);

            $this->seedSettings($company->id);
            $this->seedLegalParameters($company->id);
            $this->seedUfFallback($company->id);
            $this->seedScenarios($company->id);
        });
    }

    private function seedSettings(int $companyId): void
    {
        $defaults = [
            'currency' => ['CLP', 'string', true],
            'model_start_date' => ['2026-07-01', 'date', false],
            'analysis_month' => ['2026-07-01', 'date', false],
            'opening_balance' => ['0', 'decimal', false],
            'standard_client_payment_days' => ['30', 'integer', false],
            'margin_minimum' => ['0.30', 'decimal', false],
            'client_concentration_threshold' => ['0.40', 'decimal', false],
            'obligation_alert_days' => ['10', 'integer', false],
            'obligation_due_day' => ['20', 'integer', false],
            'previred_due_day' => ['13', 'integer', false],
            'active_scenario' => ['BASE', 'string', false],
            'tax_regime_code' => ['PRO_PYME_GENERAL', 'string', false],
            'ppm_active' => ['1', 'boolean', false],
            'vacation_provision_rate' => ['0.0833', 'decimal', false],
            'occupational_insurance_entity_code' => ['ACHS', 'string', false],
        ];

        foreach ($defaults as $key => [$value, $type, $public]) {
            CompanySetting::query()->firstOrCreate(
                ['company_id' => $companyId, 'setting_key' => $key],
                ['setting_value' => $value, 'setting_type' => $type, 'is_public' => $public]
            );
        }

        $defaultTermId = PaymentTerm::query()->where('company_id', $companyId)->where('code', '30_DIAS')->value('id');
        $defaultRegimeCode = TaxRegime::query()->where('company_id', $companyId)->where('code', 'PRO_PYME_GENERAL')->value('code');

        if ($defaultTermId) {
            CompanySetting::query()->firstOrCreate(
                ['company_id' => $companyId, 'setting_key' => 'default_payment_term_id'],
                ['setting_value' => (string) $defaultTermId, 'setting_type' => 'integer', 'is_public' => false]
            );
        }

        if ($defaultRegimeCode) {
            CompanySetting::query()->firstOrCreate(
                ['company_id' => $companyId, 'setting_key' => 'tax_regime_code'],
                ['setting_value' => $defaultRegimeCode, 'setting_type' => 'string', 'is_public' => false]
            );
        }
    }

    private function seedLegalParameters(int $companyId): void
    {
        $rows = [
            ['IVA', 'IVA', '2026-01-01', null, 0.190000, '%', '01_Config'],
            ['RETENCION_HONORARIOS', 'Retención honorarios', '2026-01-01', '2026-12-31', 0.152500, '%', '01_Config + 02_Parametros_Legales'],
            ['RETENCION_HONORARIOS', 'Retención honorarios', '2027-01-01', '2027-12-31', 0.160000, '%', '02_Parametros_Legales'],
            ['AFP_TRABAJADOR', 'Cotización AFP trabajador', '2026-01-01', null, 0.100000, '%', '01_Config'],
            ['SALUD_MINIMA', 'Salud trabajador mínima', '2026-01-01', null, 0.070000, '%', '01_Config'],
            ['AFC_TRABAJADOR_INDEFINIDO', 'AFC trabajador contrato indefinido', '2026-01-01', null, 0.006000, '%', '01_Config'],
            ['AFC_EMPLEADOR_INDEFINIDO', 'AFC empleador contrato indefinido', '2026-01-01', null, 0.024000, '%', '01_Config'],
            ['AFC_EMPLEADOR_PLAZO_FIJO', 'AFC empleador plazo fijo/obra', '2026-01-01', null, 0.030000, '%', '01_Config'],
            ['LEY_16744_BASICA', 'Ley 16.744 cotización básica', '2026-01-01', null, 0.009000, '%', '01_Config'],
            ['LEY_16744_ADICIONAL', 'Ley 16.744 tasa adicional empresa', '2026-01-01', null, 0.000000, '%', '01_Config'],
            ['SANNA_RATE', 'Seguro SANNA', '2026-01-01', null, 0.000300, '%', '01_Config'],
            ['PPM_RATE', 'PPM activo', '2026-01-01', null, 0.001250, '%', '01_Config'],
            ['IDPC_PRO_PYME_RATE', 'IDPC Pro Pyme referencial', '2026-01-01', null, 0.125000, '%', '01_Config'],
            ['TOPE_IMPONIBLE_UF', 'Tope imponible previsional UF', '2026-01-01', '2026-12-31', 90.000000, 'UF', '01_Config'],
            ['TOPE_AFC_UF', 'Tope imponible AFC UF', '2026-01-01', '2026-12-31', 135.200000, 'UF', '01_Config'],
            ['PROVISION_VACACIONES', 'Provisión mensual vacaciones', '2026-01-01', null, 0.083300, '%', '01_Config'],
            ['COTIZACION_EMPLEADOR', 'Cotización empleador', '2026-01-01', '2026-03-31', 0.010000, '%', '02_Parametros_Legales'],
            ['SIS_RATE', 'SIS separado', '2026-01-01', '2026-03-31', 0.015400, '%', '02_Parametros_Legales'],
            ['COTIZACION_EMPLEADOR', 'Cotización empleador', '2026-04-01', '2026-07-31', 0.010000, '%', '02_Parametros_Legales'],
            ['SIS_RATE', 'SIS separado', '2026-04-01', '2026-07-31', 0.016200, '%', '02_Parametros_Legales'],
            ['COTIZACION_EMPLEADOR', 'Cotización empleador', '2026-08-01', '2027-07-31', 0.035000, '%', '02_Parametros_Legales'],
            ['SIS_RATE', 'SIS / cotización empleador', '2026-08-01', '2027-07-31', 0.000000, '%', '02_Parametros_Legales'],
            ['IMPUESTO_SEGUNDA_CATEGORIA_RATE', 'Impuesto segunda categoría', '2026-01-01', null, 0.000000, '%', 'Database baseline'],
        ];

        foreach ($rows as [$code, $name, $from, $to, $value, $unit, $source]) {
            $exists = LegalParameter::query()
                ->where('company_id', $companyId)
                ->where('parameter_code', $code)
                ->whereDate('valid_from', $from)
                ->exists();

            if (! $exists) {
                LegalParameter::query()->create([
                    'company_id' => $companyId,
                    'parameter_code' => $code,
                    'parameter_name' => $name,
                    'valid_from' => $from,
                    'valid_to' => $to,
                    'value' => $value,
                    'unit' => $unit,
                    'source' => $source,
                    'notes' => 'Seeder baseline administrativo.',
                ]);
            }
        }
    }

    private function seedUfFallback(int $companyId): void
    {
        $exists = UfValue::query()
            ->where('company_id', $companyId)
            ->whereDate('value_date', '2026-07-31')
            ->exists();

        if (! $exists) {
            UfValue::query()->create([
                'company_id' => $companyId,
                'value_date' => '2026-07-31',
                'value' => 41000.0000,
                'source' => '01_Config',
                'notes' => 'Fallback inicial; completar histórico real según período.',
            ]);
        }
    }

    private function seedScenarios(int $companyId): void
    {
        foreach ([
            ['code' => 'CONSERVADOR', 'name' => 'Conservador', 'sales_factor' => 0.90, 'cost_factor' => 1.10, 'collection_delay_days' => 15, 'new_hires_monthly' => 500000, 'client_loss_flag' => true, 'tariff_variation' => -0.05, 'description' => 'Menores ventas, mayores costos, retrasos y pérdida del cliente indicado.'],
            ['code' => 'BASE', 'name' => 'Base', 'sales_factor' => 1.00, 'cost_factor' => 1.00, 'collection_delay_days' => 0, 'new_hires_monthly' => 0, 'client_loss_flag' => false, 'tariff_variation' => 0, 'description' => 'Escenario sin ajustes sobre los registros ingresados.'],
            ['code' => 'OPTIMISTA', 'name' => 'Optimista', 'sales_factor' => 1.10, 'cost_factor' => 0.95, 'collection_delay_days' => -5, 'new_hires_monthly' => 0, 'client_loss_flag' => false, 'tariff_variation' => 0.05, 'description' => 'Mayor facturación, mejora de costos y cobro anticipado.'],
        ] as $row) {
            Scenario::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $row['code']],
                $row + ['is_active' => $row['code'] === 'BASE']
            );
        }
    }
}
