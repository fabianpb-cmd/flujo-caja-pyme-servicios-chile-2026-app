<?php

namespace App\Services;

use App\Models\Afp;
use App\Models\ExchangeRate;
use App\Models\LegalParameter;
use App\Models\UfValue;
use App\Models\UtmValue;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;

class LegalParameterService
{
    public function ufValueExact(int $companyId, CarbonInterface|string $date): string
    {
        $date = Carbon::parse($date)->toDateString();

        $uf = UfValue::query()
            ->forCompany($companyId)
            ->whereDate('value_date', $date)
            ->where('active', true)
            ->first();

        if (! $uf) {
            throw new DomainException("Falta UF oficial para {$date}.");
        }

        return (string) $uf->value;
    }

    public function value(int $companyId, string $code, CarbonInterface|string $date): string
    {
        $date = Carbon::parse($date)->toDateString();

        $parameter = LegalParameter::query()
            ->forCompany($companyId)
            ->where('parameter_code', $code)
            ->where('active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            })
            ->orderByDesc('valid_from')
            ->first();

        if (! $parameter) {
            throw new DomainException("Falta parametro legal {$code} vigente para {$date}.");
        }

        return (string) $parameter->value;
    }

    public function ufValue(int $companyId, CarbonInterface|string $date): string
    {
        $date = Carbon::parse($date)->toDateString();
        $uf = $this->latestOfficialUfOnOrBefore($companyId, $date);

        if ($uf === null) {
            throw new DomainException("Falta UF oficial para {$date}.");
        }

        return (string) $uf['value'];
    }

    public function latestOfficialUfOnOrBefore(int $companyId, CarbonInterface|string $date): ?array
    {
        $date = Carbon::parse($date)->toDateString();

        $uf = UfValue::query()
            ->forCompany($companyId)
            ->where('active', true)
            ->whereDate('value_date', '<=', $date)
            ->orderByDesc('value_date')
            ->first();

        if (! $uf) {
            return null;
        }

        $valueDate = Carbon::parse($uf->value_date)->toDateString();

        return [
            'value' => (string) $uf->value,
            'value_date' => $valueDate,
            'is_exact' => $valueDate === $date,
        ];
    }

    public function afpRate(Afp $afp, CarbonInterface|string $date): array
    {
        $date = Carbon::parse($date)->toDateString();

        $rate = $afp->rates()
            ->where('active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            })
            ->orderByDesc('valid_from')
            ->first();

        if (! $rate) {
            throw new DomainException("Falta tasa AFP vigente para {$afp->name} en {$date}.");
        }

        return [
            'employee_commission_rate' => (string) $rate->employee_commission_rate,
            'employer_commission_rate' => (string) $rate->employer_commission_rate,
            'insurance_rate' => (string) $rate->insurance_rate,
        ];
    }

    public function utmValue(int $companyId, CarbonInterface|string $date): string
    {
        $period = Carbon::parse($date);

        $utm = UtmValue::query()
            ->forCompany($companyId)
            ->where('period_year', $period->year)
            ->where('period_month', $period->month)
            ->where('active', true)
            ->first();

        if (! $utm) {
            throw new DomainException("Falta UTM oficial para {$period->format('m/Y')}.");
        }

        return (string) $utm->value;
    }

    public function exchangeRate(int $companyId, int $currencyId, CarbonInterface|string $date): string
    {
        $date = Carbon::parse($date)->toDateString();

        $rate = ExchangeRate::query()
            ->forCompany($companyId)
            ->where('currency_id', $currencyId)
            ->whereDate('rate_date', $date)
            ->where('active', true)
            ->first();

        if (! $rate) {
            throw new DomainException("Falta tipo de cambio oficial para la moneda seleccionada en {$date}.");
        }

        return (string) $rate->value_clp;
    }
}
