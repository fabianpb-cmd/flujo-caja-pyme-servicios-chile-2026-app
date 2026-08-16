<?php

namespace Tests\Unit;

use App\Services\CurrencyConversionService;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    public function test_converts_to_clp_with_half_up_rounding_and_preserves_raw_value(): void
    {
        $result = app(CurrencyConversionService::class)->convert(1000.50, 'USD', 'CLP', 924.78, '2026-08-09');

        $this->assertSame(1000.5, $result['original_amount']);
        $this->assertSame('USD', $result['original_currency']);
        $this->assertSame(924.78, $result['exchange_rate']);
        $this->assertSame(925242.39, $result['raw_converted_amount']);
        $this->assertSame(925242.0, $result['converted_amount']);
        $this->assertSame('CLP', $result['target_currency']);
        $this->assertSame('2026-08-09', $result['conversion_date']);
        $this->assertTrue($result['rounding_applied']);
    }

    public function test_keeps_minor_units_for_foreign_target_currency(): void
    {
        $result = app(CurrencyConversionService::class)->convert(100, 'EUR', 'USD', 1.123456, '2026-08-09');

        $this->assertSame(112.3456, $result['raw_converted_amount']);
        $this->assertSame(112.35, $result['converted_amount']);
    }
}
