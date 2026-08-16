<?php

namespace App\Services;

use App\Models\IncomeTaxBracket;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;

class IncomeTaxService
{
    public function calculate(float $taxableBase, CarbonInterface|string $periodDate): array
    {
        $period = Carbon::parse($periodDate)->startOfMonth();

        $bracket = IncomeTaxBracket::query()
            ->where('period_year', $period->year)
            ->where('period_month', $period->month)
            ->where('period_type', 'MENSUAL')
            ->where('active', true)
            ->where('from_amount', '<=', $taxableBase)
            ->where(function ($query) use ($taxableBase): void {
                $query->whereNull('to_amount')
                    ->orWhere('to_amount', '>=', $taxableBase);
            })
            ->orderByDesc('from_amount')
            ->first();

        if (! $bracket) {
            throw new DomainException("Tabla IUSC no configurada para este período.");
        }

        $factor = (float) $bracket->factor;
        $rebate = (float) $bracket->rebate_amount;
        $amount = (float) max(0, round($taxableBase * $factor - $rebate, 2));

        return [
            'iusc_taxable_base' => round($taxableBase, 2),
            'iusc_bracket' => $this->label($bracket),
            'iusc_factor' => $factor,
            'iusc_rebate' => $rebate,
            'iusc_amount' => $amount,
        ];
    }

    private function label(IncomeTaxBracket $bracket): string
    {
        $to = $bracket->to_amount === null ? 'Y_MAS' : (string) round((float) $bracket->to_amount, 2);

        return implode('|', [
            $bracket->period_year,
            str_pad((string) $bracket->period_month, 2, '0', STR_PAD_LEFT),
            $bracket->period_type,
            round((float) $bracket->from_amount, 2),
            $to,
        ]);
    }
}
