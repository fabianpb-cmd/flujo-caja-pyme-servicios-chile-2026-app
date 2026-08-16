<?php

namespace App\Support;

final class ChileanRut
{
    public static function normalize(?string $rut): ?string
    {
        $rut = strtoupper(trim((string) $rut));
        if ($rut === '') {
            return null;
        }

        $rut = preg_replace('/[^0-9K]/', '', $rut) ?? '';
        if ($rut === '') {
            return null;
        }

        $body = substr($rut, 0, -1);
        $dv = substr($rut, -1);
        $body = ltrim($body, '0');

        return $body === '' ? null : $body.'-'.$dv;
    }

    public static function format(?string $rut): ?string
    {
        $normalized = self::normalize($rut);
        if ($normalized === null) {
            return null;
        }

        [$body, $dv] = explode('-', $normalized, 2);
        return number_format((int) $body, 0, '', '.').'-'.$dv;
    }

    public static function isValid(?string $rut): bool
    {
        $normalized = self::normalize($rut);
        if ($normalized === null) {
            return false;
        }

        [$body, $dv] = explode('-', $normalized, 2);
        if ($body === '' || ! ctype_digit($body)) {
            return false;
        }

        return self::checkDigit($body) === $dv;
    }

    public static function checkDigit(string $body): string
    {
        $sum = 0;
        $multiplier = 2;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += ((int) $body[$i]) * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }

        $rest = 11 - ($sum % 11);

        return match ($rest) {
            11 => '0',
            10 => 'K',
            default => (string) $rest,
        };
    }
}
