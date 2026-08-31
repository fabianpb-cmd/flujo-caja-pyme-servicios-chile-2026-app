<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class PeriodInput
{
    public static function parse(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfMonth();
        }

        if ($value === null || $value === '') {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        foreach (['!m/Y', '!Y-m', '!d/m/Y', '!Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $string);
                if ($date !== false && $date->format(ltrim($format, '!')) === $string) {
                    return $date->startOfMonth();
                }
            } catch (\Throwable) {
                // Ignore and try next explicit format.
            }
        }

        return null;
    }
}
