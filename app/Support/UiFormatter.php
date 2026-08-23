<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\LegalParameter;
use App\Models\PayrollRecord;
use App\Models\UfValue;
use App\Models\UtmValue;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class UiFormatter
{
    public static function display(object $item, string $field, array $definition): string
    {
        $type = $definition['type'] ?? 'text';
        $presentation = $definition['presentation'] ?? null;
        $value = data_get($item, $field);

        if ($type === 'relation') {
            $relation = $definition['relation_name'] ?? str($field)->beforeLast('_id')->camel()->toString();
            $related = method_exists($item, $relation)
                ? data_get($item, $relation)
                : ($value ? $definition['model']::query()->find($value) : null);

            return data_get($related, $definition['display']) ?? '—';
        }

        if ($type === 'select') {
            return $definition['options'][$value] ?? ($value !== null && $value !== '' ? (string) $value : '—');
        }

        if ($item instanceof PayrollRecord && $field === 'calculation_status') {
            return match (strtoupper((string) $value)) {
                'REQUIERE_REVISION' => 'Requiere revisión',
                default => $value !== null && $value !== '' ? (string) $value : '—',
            };
        }

        if ($presentation === 'rut') {
            return ChileanRut::format($value) ?? '—';
        }

        if ($presentation === 'phone') {
            return self::formatPhone(
                phoneCountryCode: (string) data_get($item, 'phone_country_code', '+56'),
                number: $value,
            );
        }

        if ($presentation === 'percent') {
            return self::formatPercent($value);
        }

        if ($presentation === 'uf') {
            return self::formatUf($value);
        }

        if ($presentation === 'exchange_rate') {
            return self::formatExchangeRate($value, $definition['exchange_rate_currency'] ?? 'CLP');
        }

        if ($presentation === 'hours') {
            return self::formatHours($value);
        }

        if ($field === 'value' && $item instanceof LegalParameter) {
            return match (strtoupper((string) $item->unit)) {
                'PERCENT', '%' => self::formatPercent($value),
                'CLP' => self::formatMoney($value, 'CLP'),
                'UF' => self::formatUf($value),
                'UTM' => self::formatMoney($value, 'CLP'),
                'DAYS' => self::formatNumber($value).' días',
                'HOURS' => self::formatNumber($value).' h',
                'BOOLEAN' => (float) $value > 0 ? 'Sí' : 'No',
                default => self::formatNumber($value),
            };
        }

        if ($field === 'value' && $item instanceof UfValue) {
            return self::formatUf($value);
        }

        if ($field === 'value' && $item instanceof UtmValue) {
            return self::formatMoney($value, 'CLP');
        }

        if ($field === 'value_clp' && $item instanceof ExchangeRate) {
            return self::formatExchangeRate($value, 'CLP');
        }

        if ($type === 'money') {
            $currency = self::displayCurrency($item, $definition);

            return self::formatMoney($value, $currency);
        }

        if (in_array($type, ['decimal', 'number'], true)) {
            return self::formatNumber($value, $definition['decimals'] ?? null);
        }

        if ($type === 'checkbox') {
            return $value ? 'Sí' : 'No';
        }

        if ($value instanceof CarbonInterface) {
            return self::formatDate($value);
        }

        return $value !== null && $value !== '' ? (string) $value : '—';
    }

    public static function formatMoney(mixed $value, mixed $currency = 'CLP'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $spec = self::currencySpec($currency);

        if (($spec['code'] ?? null) === 'UF') {
            return self::formatUf($value, $spec['minor_units']);
        }

        return self::formatCurrencyValue((float) $value, $spec['symbol'], $spec['minor_units']);
    }

    public static function formatUf(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return self::formatCurrencyValue((float) $value, 'UF', $decimals);
    }

    public static function formatExchangeRate(mixed $value, mixed $currency = 'CLP', ?int $decimals = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $spec = self::currencySpec($currency);
        $decimals ??= max(2, min(6, $spec['minor_units'] + 2));

        return self::formatCurrencyValue((float) $value, $spec['symbol'], $decimals);
    }

    public static function formatNumber(mixed $value, ?int $decimals = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $decimals ??= floor((float) $value) === (float) $value ? 0 : 2;

        return self::localizedDecimal((float) $value, $decimals, false);
    }

    public static function formatPercent(mixed $value, int $decimals = 2, bool $fraction = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $numeric = (float) $value;
        if ($fraction) {
            $numeric *= 100;
        }

        return self::localizedDecimal($numeric, $decimals).' %';
    }

    public static function formatHours(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return self::localizedDecimal((float) $value, $decimals).' h';
    }

    public static function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $date = self::parseDateInput($value);
        if ($date === null) {
            return (string) $value;
        }

        return $date->format('d/m/Y');
    }

    public static function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $dateTime = self::parseDateInput($value);
        if ($dateTime === null) {
            return (string) $value;
        }

        return $dateTime->format('d/m/Y H:i');
    }

    public static function parseDateInput(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $string);
                if ($date !== false && $date->format($format) === $string) {
                    return $date;
                }
            } catch (\Throwable) {
                // Ignore and try next format.
            }
        }

        return null;
    }

    public static function formatPhone(?string $phoneCountryCode, mixed $number): string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?: '';
        if ($digits === '') {
            return '—';
        }

        $formatted = match (strlen($digits)) {
            9 => substr($digits, 0, 1).' '.substr($digits, 1, 4).' '.substr($digits, 5),
            8 => substr($digits, 0, 4).' '.substr($digits, 4),
            default => $digits,
        };

        return trim(($phoneCountryCode ?: '+56').' '.$formatted);
    }

    public static function isNumericField(string $field, array $definition): bool
    {
        $type = $definition['type'] ?? 'text';
        $presentation = $definition['presentation'] ?? null;

        return in_array($type, ['money', 'decimal', 'number'], true)
            || in_array($presentation, ['percent', 'hours'], true)
            || in_array($field, ['net_amount', 'gross_amount', 'vat_amount', 'paid_amount', 'pending_amount'], true);
    }

    public static function localizedDecimal(float $value, int $decimals, bool $keepTrailingZeros = false): string
    {
        $formatted = number_format($value, $decimals, ',', '.');

        if ($keepTrailingZeros || $decimals === 0) {
            return $formatted;
        }

        return rtrim(rtrim($formatted, '0'), ',');
    }

    public static function roundAmount(mixed $value, mixed $currency = 'CLP'): float
    {
        $spec = self::currencySpec($currency);

        return round((float) $value, $spec['minor_units'], PHP_ROUND_HALF_UP);
    }

    public static function currencyCode(mixed $currency = 'CLP'): string
    {
        return self::currencySpec($currency)['code'];
    }

    private static function displayCurrency(object $item, array $definition): mixed
    {
        if (array_key_exists('currency', $definition)) {
            return $definition['currency'];
        }

        if (isset($definition['currency_relation'])) {
            return data_get($item, $definition['currency_relation']);
        }

        if (isset($definition['currency_field'])) {
            return data_get($item, $definition['currency_field']);
        }

        return 'CLP';
    }

    private static function formatCurrencyValue(float $value, string $symbol, int $decimals): string
    {
        $negative = $value < 0;
        $prefix = $negative ? '-' : '';
        $formatted = self::localizedDecimal(abs($value), $decimals, true);

        return $prefix.$symbol.' '.$formatted;
    }

    private static function currencySpec(mixed $currency): array
    {
        if ($currency instanceof Currency) {
            return [
                'code' => strtoupper((string) $currency->code),
                'symbol' => $currency->symbol ?: strtoupper((string) $currency->code),
                'minor_units' => (int) ($currency->minor_units ?? 2),
            ];
        }

        if (is_array($currency)) {
            return [
                'code' => strtoupper((string) ($currency['code'] ?? 'CLP')),
                'symbol' => (string) ($currency['symbol'] ?? '$'),
                'minor_units' => (int) ($currency['minor_units'] ?? 2),
            ];
        }

        $code = strtoupper(trim((string) $currency));

        return match ($code) {
            'CLP' => ['code' => 'CLP', 'symbol' => '$', 'minor_units' => 0],
            'USD' => ['code' => 'USD', 'symbol' => 'US$', 'minor_units' => 2],
            'EUR' => ['code' => 'EUR', 'symbol' => '€', 'minor_units' => 2],
            'UF' => ['code' => 'UF', 'symbol' => 'UF', 'minor_units' => 2],
            default => self::currencyFromDatabase($code),
        };
    }

    private static function currencyFromDatabase(string $code): array
    {
        static $cache = [];

        if (! isset($cache[$code])) {
            $currency = Currency::query()->where('code', $code)->first();
            $cache[$code] = $currency
                ? [
                    'code' => strtoupper((string) $currency->code),
                    'symbol' => $currency->symbol ?: strtoupper((string) $currency->code),
                    'minor_units' => (int) ($currency->minor_units ?? 2),
                ]
                : [
                    'code' => $code,
                    'symbol' => $code,
                    'minor_units' => 2,
                ];
        }

        return $cache[$code];
    }
}
