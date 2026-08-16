<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\IncomeTaxBracket;
use App\Models\LegalParameter;
use App\Models\PaymentTerm;
use App\Models\Scenario;
use App\Models\TaxRegime;
use App\Models\UfValue;
use App\Models\UtmValue;
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
            'base_currency_code' => ['CLP', 'string', true],
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
            'ppm_rate' => ['0.001250', 'decimal', false],
            'additional_accident_rate' => ['0.000000', 'decimal', false],
            'gratification_method' => ['MANUAL', 'string', false],
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
            ['IVA', 'IVA', 'TRIBUTARIO', '2026-01-01', null, 0.190000, 'PERCENT', 'SII', 'https://www.sii.cl', '01_Config'],
            ['RETENCION_HONORARIOS', 'Retención honorarios', 'TRIBUTARIO', '2026-01-01', '2026-12-31', 0.152500, 'PERCENT', 'SII', 'https://www.sii.cl', '01_Config + 02_Parametros_Legales'],
            ['RETENCION_HONORARIOS', 'Retención honorarios', 'TRIBUTARIO', '2027-01-01', '2027-12-31', 0.160000, 'PERCENT', 'SII', 'https://www.sii.cl', '02_Parametros_Legales'],
            ['RETENCION_HONORARIOS', 'Retención honorarios', 'TRIBUTARIO', '2028-01-01', '2028-12-31', 0.170000, 'PERCENT', 'SII', 'https://www.sii.cl', 'Baseline 2028'],
            ['AFP_TRABAJADOR', 'Cotización AFP trabajador', 'PREVISIONAL', '2026-01-01', null, 0.100000, 'PERCENT', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', '01_Config'],
            ['SALUD_MINIMA', 'Salud trabajador mínima', 'PREVISIONAL', '2026-01-01', null, 0.070000, 'PERCENT', 'Superintendencia de Salud', 'https://www.supersalud.gob.cl', '01_Config'],
            ['AFC_TRABAJADOR_INDEFINIDO', 'AFC trabajador contrato indefinido', 'PREVISIONAL', '2026-01-01', null, 0.006000, 'PERCENT', 'AFC Chile', 'https://www.afc.cl', '01_Config'],
            ['AFC_EMPLEADOR_INDEFINIDO', 'AFC empleador contrato indefinido', 'PREVISIONAL', '2026-01-01', null, 0.024000, 'PERCENT', 'AFC Chile', 'https://www.afc.cl', '01_Config'],
            ['AFC_EMPLEADOR_PLAZO_FIJO', 'AFC empleador plazo fijo/obra', 'PREVISIONAL', '2026-01-01', null, 0.030000, 'PERCENT', 'AFC Chile', 'https://www.afc.cl', '01_Config'],
            ['LEY_16744_BASICA', 'Ley 16.744 cotización básica', 'LABORAL', '2026-01-01', null, 0.009000, 'PERCENT', 'SUSESO', 'https://www.suseso.cl', '01_Config'],
            ['LEY_16744_ADICIONAL', 'Ley 16.744 tasa adicional empresa', 'LABORAL', '2026-01-01', null, 0.000000, 'PERCENT', 'Empresa', null, 'Compatibilidad histórica'],
            ['SANNA_RATE', 'Seguro SANNA', 'LABORAL', '2026-01-01', null, 0.000300, 'PERCENT', 'SUSESO', 'https://www.suseso.cl', '01_Config'],
            ['IDPC_PRO_PYME_RATE', 'IDPC Pro Pyme referencial', 'TRIBUTARIO', '2026-01-01', null, 0.125000, 'PERCENT', 'SII', 'https://www.sii.cl', '01_Config'],
            ['TOPE_IMPONIBLE_UF', 'Tope imponible previsional UF', 'PREVISIONAL', '2026-01-01', '2026-01-31', 90.000000, 'UF', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', 'Enero 2026'],
            ['TOPE_IMPONIBLE_UF', 'Tope imponible previsional UF', 'PREVISIONAL', '2026-02-01', '2026-12-31', 90.000000, 'UF', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', 'Febrero 2026 en adelante'],
            ['TOPE_AFC_UF', 'Tope imponible AFC UF', 'PREVISIONAL', '2026-01-01', '2026-01-31', 135.200000, 'UF', 'AFC Chile', 'https://www.afc.cl', 'Enero 2026'],
            ['TOPE_AFC_UF', 'Tope imponible AFC UF', 'PREVISIONAL', '2026-02-01', '2026-12-31', 135.200000, 'UF', 'AFC Chile', 'https://www.afc.cl', 'Febrero 2026 en adelante'],
            ['PROVISION_VACACIONES', 'Provisión mensual vacaciones referencial', 'LABORAL', '2026-01-01', null, 0.083300, 'PERCENT', 'Histórico Excel', null, 'Solo referencia histórica, no base legal actual del cálculo'],
            ['COTIZACION_EMPLEADOR', 'Cotización empleador', 'PREVISIONAL', '2026-01-01', '2026-03-31', 0.010000, 'PERCENT', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', '02_Parametros_Legales'],
            ['SIS_RATE', 'SIS separado', 'PREVISIONAL', '2026-01-01', '2026-03-31', 0.015400, 'PERCENT', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', '02_Parametros_Legales'],
            ['COTIZACION_EMPLEADOR', 'Cotización empleador', 'PREVISIONAL', '2026-04-01', '2026-07-31', 0.010000, 'PERCENT', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', '02_Parametros_Legales'],
            ['SIS_RATE', 'SIS separado', 'PREVISIONAL', '2026-04-01', '2026-07-31', 0.016200, 'PERCENT', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', '02_Parametros_Legales'],
            ['COTIZACION_EMPLEADOR', 'Cotización empleador', 'PREVISIONAL', '2026-08-01', '2027-07-31', 0.035000, 'PERCENT', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', '02_Parametros_Legales'],
            ['SIS_RATE', 'SIS / cotización empleador', 'PREVISIONAL', '2026-08-01', '2027-07-31', 0.000000, 'PERCENT', 'Superintendencia de Pensiones', 'https://www.spensiones.cl', '02_Parametros_Legales'],
            ['MINIMUM_MONTHLY_INCOME_STANDARD', 'Ingreso mínimo mensual general', 'LABORAL', '2026-01-01', null, 0.000000, 'CLP', 'Pendiente carga oficial', null, 'Preparado para futura automatización'],
            ['MINIMUM_MONTHLY_INCOME_SPECIAL', 'Ingreso mínimo mensual especial', 'LABORAL', '2026-01-01', null, 0.000000, 'CLP', 'Pendiente carga oficial', null, 'Preparado para futura automatización'],
            ['MINIMUM_MONTHLY_INCOME_NON_REMUNERATIONAL', 'Ingreso mínimo no remuneracional', 'LABORAL', '2026-01-01', null, 0.000000, 'CLP', 'Pendiente carga oficial', null, 'Preparado para futura automatización'],
            ['MAX_WEEKLY_HOURS', 'Jornada máxima semanal', 'LABORAL', '2026-01-01', null, 44.000000, 'HOURS', 'Dirección del Trabajo', 'https://www.dt.gob.cl', 'Preparado para validaciones futuras'],
            ['VACATION_DAYS_PER_YEAR', 'Días feriado anual', 'LABORAL', '2026-01-01', null, 15.000000, 'DAYS', 'Dirección del Trabajo', 'https://www.dt.gob.cl', 'Base legal de devengo'],
            ['GRATIFICATION_ART50_RATE', 'Gratificación art. 50', 'LABORAL', '2026-01-01', null, 0.250000, 'PERCENT', 'Dirección del Trabajo', 'https://www.dt.gob.cl', 'Solo referencia; no automatizar sin método empresa'],
            ['GRATIFICATION_ART50_IMM_CAP', 'Tope IMM gratificación art. 50', 'LABORAL', '2026-01-01', null, 4.750000, 'NUMBER', 'Dirección del Trabajo', 'https://www.dt.gob.cl', 'Solo referencia'],
        ];

        foreach ($rows as [$code, $name, $category, $from, $to, $value, $unit, $sourceName, $sourceUrl, $notes]) {
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
                    'category' => $category,
                    'valid_from' => $from,
                    'valid_to' => $to,
                    'value' => $value,
                    'unit' => $unit,
                    'source' => $sourceName,
                    'source_name' => $sourceName,
                    'source_url' => $sourceUrl,
                    'notes' => $notes,
                    'active' => true,
                ]);
            }
        }
    }

    private function seedUfFallback(int $companyId): void
    {
        foreach ([
            ['2026-07-31', 40844.7900, 'https://www.sii.cl/valores_y_fechas/uf/uf2026.htm', 'UF oficial SII para cálculos de remuneraciones julio 2026.'],
            ['2026-08-01', 40844.7900, 'https://www.sii.cl/valores_y_fechas/uf/uf2026.htm', 'UF oficial SII para cálculos de remuneraciones agosto 2026.'],
        ] as [$date, $value, $source, $notes]) {
            $exists = UfValue::query()
                ->where('company_id', $companyId)
                ->whereDate('value_date', $date)
                ->exists();

            if (! $exists) {
                UfValue::query()->create([
                    'company_id' => $companyId,
                    'value_date' => $date,
                    'value' => $value,
                    'source' => $source,
                    'source_name' => 'Servicio de Impuestos Internos',
                    'source_url' => $source,
                    'notes' => $notes,
                    'active' => true,
                ]);
            }
        }

        foreach ([
            [2026, 7, 66634.00, 'https://www.sii.cl/valores_y_fechas/utm/utm2026.htm'],
            [2026, 8, 66800.00, 'https://www.sii.cl/valores_y_fechas/utm/utm2026.htm'],
        ] as [$year, $month, $value, $source]) {
            UtmValue::query()->firstOrCreate(
                ['company_id' => $companyId, 'period_year' => $year, 'period_month' => $month],
                [
                    'value' => $value,
                    'source' => $source,
                    'source_name' => 'Servicio de Impuestos Internos',
                    'source_url' => $source,
                    'notes' => 'Seeder baseline administrativo.',
                    'active' => true,
                ]
            );
        }

        foreach ([
            ['USD', '2026-07-31', 952.340000, 'https://si3.bcentral.cl/Indicadoressiete/secure/Serie.aspx?gcode=F073.TCO.PRE.Z.D'],
            ['USD', '2026-08-01', 956.120000, 'https://si3.bcentral.cl/Indicadoressiete/secure/Serie.aspx?gcode=F073.TCO.PRE.Z.D'],
            ['EUR', '2026-07-31', 1038.550000, 'https://si3.bcentral.cl/Indicadoressiete/secure/Serie.aspx?gcode=F072.CLP.EUR.N.O.D'],
            ['EUR', '2026-08-01', 1041.220000, 'https://si3.bcentral.cl/Indicadoressiete/secure/Serie.aspx?gcode=F072.CLP.EUR.N.O.D'],
        ] as [$currencyCode, $date, $value, $source]) {
            $currencyId = Currency::query()->where('company_id', $companyId)->where('code', $currencyCode)->value('id');
            if (! $currencyId) {
                continue;
            }

            $exists = ExchangeRate::query()
                ->where('company_id', $companyId)
                ->where('currency_id', $currencyId)
                ->whereDate('rate_date', $date)
                ->exists();

            if (! $exists) {
                ExchangeRate::query()->create([
                    'company_id' => $companyId,
                    'currency_id' => $currencyId,
                    'rate_date' => $date,
                    'value_clp' => $value,
                    'source' => $source,
                    'source_name' => 'Banco Central de Chile',
                    'source_url' => $source,
                    'notes' => 'Seeder baseline administrativo.',
                    'active' => true,
                ]);
            }
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
