<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Support\UiFormatter;
use App\Support\ChileanRut;
use Tests\TestCase;

class UiFormatterTest extends TestCase
{
    public function test_formats_chilean_clp_values(): void
    {
        $this->assertSame('$ 1.390.112', UiFormatter::formatMoney(1390112));
        $this->assertSame('$ 0', UiFormatter::formatMoney(0));
    }

    public function test_formats_uf_percentages_and_hours(): void
    {
        $this->assertSame('UF 40.844,79', UiFormatter::formatUf(40844.79));
        $this->assertSame('15,25 %', UiFormatter::formatPercent(0.1525));
        $this->assertSame('7,5 h', UiFormatter::formatHours(7.5));
    }

    public function test_formats_dates_in_chilean_dd_mm_yyyy_format(): void
    {
        $this->assertSame('09/08/2026', UiFormatter::formatDate('2026-08-09'));
        $this->assertSame('09/08/2026', UiFormatter::formatDate('09/08/2026'));
        $this->assertSame('09/08/2026 14:35', UiFormatter::formatDateTime('2026-08-09 14:35:00'));
        $this->assertSame('09/08/2026 14:35', UiFormatter::formatDateTime('09/08/2026 14:35:00'));
        $this->assertSame('31/08/2026', UiFormatter::formatDate(new \Illuminate\Support\Carbon('2026-08-31')));
        $this->assertSame('31/08/2026 14:35', UiFormatter::formatDateTime(new \Illuminate\Support\Carbon('2026-08-31 14:35:00')));
    }

    public function test_parses_localized_and_iso_dates_strictly(): void
    {
        $this->assertSame('2026-08-31', UiFormatter::parseDateInput('31/08/2026')?->toDateString());
        $this->assertSame('2026-08-31', UiFormatter::parseDateInput('2026-08-31')?->toDateString());
        $this->assertNull(UiFormatter::parseDateInput('31/02/2026'));
    }

    public function test_formats_foreign_currencies_using_minor_units(): void
    {
        $this->assertSame('US$ 1.234,56', UiFormatter::formatMoney(1234.56, 'USD'));
        $this->assertSame('€ 1.234,56', UiFormatter::formatMoney(1234.56, 'EUR'));
    }

    public function test_formats_chilean_numbers_and_ruts_without_touching_phones(): void
    {
        $this->assertSame('1.234.567', UiFormatter::formatNumber(1234567));
        $this->assertSame('1.234,5', UiFormatter::formatNumber(1234.5));
        $this->assertSame('12.345.678-5', ChileanRut::format('12345678-5'));
        $this->assertSame('+56 9 8765 4321', UiFormatter::formatPhone('+56', '987654321'));
    }

    public function test_rounds_amounts_using_currency_minor_units_and_half_up(): void
    {
        $this->assertSame(1390112.0, UiFormatter::roundAmount(1390112.49, 'CLP'));
        $this->assertSame(1390113.0, UiFormatter::roundAmount(1390112.50, 'CLP'));
    }

    public function test_supports_currency_models_with_configured_minor_units(): void
    {
        $currency = new Currency([
            'code' => 'KWD',
            'symbol' => 'KD',
            'minor_units' => 3,
        ]);

        $this->assertSame('KD 1,235', UiFormatter::formatMoney(1.2346, $currency));
    }

    public function test_formats_negative_money_consistently(): void
    {
        $this->assertSame('-$ 125.000', UiFormatter::formatMoney(-125000, 'CLP'));
    }
}
