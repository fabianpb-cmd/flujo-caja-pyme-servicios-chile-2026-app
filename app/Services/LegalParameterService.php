<?php

namespace App\Services;

use App\Models\Afp;
use App\Models\LegalParameter;
use App\Models\UfValue;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;

class LegalParameterService
{
    public function value(int $companyId, string $code, CarbonInterface|string $date): string
    {
        $date = Carbon::parse($date)->toDateString();

        $parameter = LegalParameter::query()
            ->forCompany($companyId)
            ->where('parameter_code', $code)
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

        $uf = UfValue::query()
            ->forCompany($companyId)
            ->whereDate('value_date', $date)
            ->first();

        if (! $uf) {
            throw new DomainException("Falta UF oficial para {$date}.");
        }

        return (string) $uf->value;
    }

    public function afpRate(Afp $afp, CarbonInterface|string $date): array
    {
        $date = Carbon::parse($date)->toDateString();

        $rate = $afp->rates()
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
}
