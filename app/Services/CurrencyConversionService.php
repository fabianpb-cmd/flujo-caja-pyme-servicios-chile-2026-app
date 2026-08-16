<?php

namespace App\Services;

use App\Support\UiFormatter;
use Carbon\CarbonInterface;

class CurrencyConversionService
{
    public function convert(
        float|int|string $amount,
        mixed $fromCurrency,
        mixed $toCurrency,
        float|int|string $exchangeRate,
        CarbonInterface|string|null $date = null,
    ): array {
        $originalAmount = (float) $amount;
        $rate = (float) $exchangeRate;
        $rawConvertedAmount = $originalAmount * $rate;
        $convertedAmount = UiFormatter::roundAmount($rawConvertedAmount, $toCurrency);

        return [
            'original_amount' => $originalAmount,
            'original_currency' => UiFormatter::currencyCode($fromCurrency),
            'exchange_rate' => $rate,
            'raw_converted_amount' => $rawConvertedAmount,
            'converted_amount' => $convertedAmount,
            'target_currency' => UiFormatter::currencyCode($toCurrency),
            'conversion_date' => $date instanceof CarbonInterface ? $date->toDateString() : $date,
            'rounding_applied' => $convertedAmount !== $rawConvertedAmount,
        ];
    }
}
