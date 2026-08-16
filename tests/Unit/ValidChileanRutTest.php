<?php

namespace Tests\Unit;

use App\Rules\ValidChileanRut;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidChileanRutTest extends TestCase
{
    public function test_accepts_valid_chilean_ruts_and_rejects_invalid_ones(): void
    {
        $validSamples = ['12.345.678-5', '12345678-5', '123456785', '76432109-K', '76432109-k'];

        foreach ($validSamples as $sample) {
            $validator = Validator::make(['rut' => $sample], ['rut' => ['required', new ValidChileanRut()]]);
            $this->assertTrue($validator->passes(), $sample);
        }

        $invalidSamples = ['12.345.678-4', '12345678-4', '12345678', 'abc', '12.345.678-'];

        foreach ($invalidSamples as $sample) {
            $validator = Validator::make(['rut' => $sample], ['rut' => ['required', new ValidChileanRut()]]);
            $this->assertFalse($validator->passes(), $sample);
        }
    }

    public function test_nullable_rut_can_be_left_empty(): void
    {
        $validator = Validator::make(['rut' => ''], ['rut' => ['nullable', new ValidChileanRut()]]);

        $this->assertTrue($validator->passes());
    }
}
