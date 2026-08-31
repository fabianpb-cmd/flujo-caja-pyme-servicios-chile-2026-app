<?php

namespace Tests\Unit;

use App\Support\PeriodInput;
use Tests\TestCase;

class PeriodInputTest extends TestCase
{
    public function test_it_normalizes_month_year_inputs_to_the_first_day_of_the_same_month(): void
    {
        $cases = [
            '01/2026' => '2026-01-01',
            '09/2026' => '2026-09-01',
            '12/2026' => '2026-12-01',
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, PeriodInput::parse($raw)?->toDateString(), $raw);
        }
    }
}
