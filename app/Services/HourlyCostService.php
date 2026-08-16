<?php

namespace App\Services;

use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\ProjectAssignment;
use App\Models\TimeEntry;
use App\Support\UiFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class HourlyCostService
{
    public function forPayroll(PayrollRecord $payroll): array
    {
        $payroll->loadMissing('person');

        return $this->forPersonPeriod(
            companyId: $payroll->company_id,
            personId: $payroll->person_id,
            periodDate: $payroll->period_date,
            payroll: $payroll,
        );
    }

    public function forPersonPeriod(
        int $companyId,
        int $personId,
        CarbonInterface|string $periodDate,
        ?PayrollRecord $payroll = null,
    ): array {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $periodEnd = $period->copy()->endOfMonth();
        $payroll ??= PayrollRecord::query()
            ->forCompany($companyId)
            ->where('person_id', $personId)
            ->whereDate('period_date', $period->toDateString())
            ->firstOrFail();

        $person = $payroll->relationLoaded('person')
            ? $payroll->person
            : Person::query()->forCompany($companyId)->findOrFail($personId);

        $entries = TimeEntry::query()
            ->forCompany($companyId)
            ->with('approvalStatus')
            ->where('person_id', $personId)
            ->whereBetween('entry_date', [$period->toDateString(), $periodEnd->toDateString()])
            ->where('hours_approved', '>', 0)
            ->get()
            ->filter(fn (TimeEntry $entry): bool => $this->isApproved($entry))
            ->values();

        $workedHours = round((float) $entries->sum('hours_approved'), 4);
        $projectHours = round((float) $entries->whereNotNull('project_id')->sum('hours_approved'), 4);
        $internalHours = round(max(0, $workedHours - $projectHours), 4);
        $referenceCapacity = $this->referenceCapacityHours($person, $period, $payroll);

        $realHourlyCost = $workedHours > 0
            ? round((float) $payroll->employer_cost / $workedHours, 6)
            : null;
        $referenceHourlyCost = $referenceCapacity['hours'] > 0
            ? round((float) $payroll->employer_cost / $referenceCapacity['hours'], 6)
            : null;

        $projectBreakdown = $entries
            ->groupBy('project_id')
            ->map(function ($rows, $projectId) use ($realHourlyCost) {
                $hours = round((float) $rows->sum('hours_approved'), 4);
                $allocated = $realHourlyCost !== null ? round($hours * $realHourlyCost, 2) : 0.0;

                return [
                    'project_id' => $projectId ? (int) $projectId : null,
                    'hours' => $hours,
                    'allocated_cost' => $allocated,
                ];
            })
            ->values()
            ->all();

        $allocatedCost = round((float) collect($projectBreakdown)->sum('allocated_cost'), 2);
        $unassignedCost = round(max(0, (float) $payroll->employer_cost - $allocatedCost), 2);

        return [
            'company_cost' => round((float) $payroll->employer_cost, 2),
            'worked_hours' => $workedHours,
            'project_hours' => $projectHours,
            'internal_hours' => $internalHours,
            'real_hourly_cost' => $realHourlyCost,
            'reference_capacity_hours' => $referenceCapacity['hours'],
            'reference_hourly_cost' => $referenceHourlyCost,
            'reference_capacity_label' => $referenceCapacity['label'],
            'reference_estimated' => $referenceCapacity['estimated'],
            'allocated_cost' => $allocatedCost,
            'unassigned_cost' => $unassignedCost,
            'project_breakdown' => $projectBreakdown,
            'real_hourly_cost_message' => $workedHours > 0 ? null : 'Costo HH real no disponible: no existen horas aprobadas en el período.',
            'calculation_breakdown' => [
                'result' => [
                    'label' => 'Costo HH real',
                    'value' => $realHourlyCost !== null ? UiFormatter::formatMoney($realHourlyCost) : '—',
                    'note' => $referenceCapacity['estimated'] ? 'Costo referencial estimado para capacidad teórica.' : 'Costo real calculado con horas aprobadas.',
                ],
                'warnings' => array_values(array_filter([
                    $workedHours <= 0 ? '⚠ Costo HH real no disponible: no existen horas aprobadas en el período.' : null,
                ])),
                'sections' => [
                    [
                        'title' => 'Costo HH',
                        'rows' => [
                            ['label' => 'Costo empresa período', 'value' => UiFormatter::formatMoney($payroll->employer_cost)],
                            ['label' => 'Horas utilizadas', 'value' => UiFormatter::formatHours($workedHours)],
                            ['label' => 'Costo HH real', 'value' => $realHourlyCost !== null ? UiFormatter::formatMoney($realHourlyCost) : '—', 'strong' => true],
                            ['label' => 'Costo HH referencial', 'value' => $referenceHourlyCost !== null ? UiFormatter::formatMoney($referenceHourlyCost) : '—'],
                        ],
                    ],
                    [
                        'title' => 'Distribución por proyecto',
                        'rows' => collect($projectBreakdown)->map(fn (array $breakdown): array => [
                            'label' => $breakdown['project_id'] ? 'Proyecto '.$breakdown['project_id'] : 'Sin proyecto',
                            'value' => UiFormatter::formatMoney($breakdown['allocated_cost']),
                        ])->values()->all(),
                    ],
                ],
                'parameters' => array_values(array_filter([
                    ['label' => 'Período', 'value' => UiFormatter::formatDate($period), 'validity' => UiFormatter::formatDate($period), 'source' => 'Payroll/HH'],
                    ['label' => 'Capacidad ref.', 'value' => UiFormatter::formatHours($referenceCapacity['hours']), 'validity' => UiFormatter::formatDate($period), 'source' => $referenceCapacity['label']],
                ])),
            ],
        ];
    }

    public function companyProjectAllocation(int $companyId, CarbonInterface|string|null $periodDate = null): array
    {
        $rows = [];
        $unassigned = 0.0;

        $query = PayrollRecord::query()
            ->forCompany($companyId)
            ->with('person')
            ->when($periodDate, fn ($builder) => $builder->whereDate('period_date', Carbon::parse($periodDate)->startOfMonth()->toDateString()));

        $query->get()
            ->each(function (PayrollRecord $payroll) use (&$rows, &$unassigned): void {
                $metrics = $this->forPayroll($payroll);
                foreach ($metrics['project_breakdown'] as $breakdown) {
                    if (! $breakdown['project_id']) {
                        continue;
                    }

                    if (! isset($rows[$breakdown['project_id']])) {
                        $rows[$breakdown['project_id']] = [
                            'cost' => 0.0,
                            'hours' => 0.0,
                            'vacation_provision' => 0.0,
                        ];
                    }

                    $ratio = $metrics['worked_hours'] > 0 ? ($breakdown['hours'] / $metrics['worked_hours']) : 0.0;
                    $rows[$breakdown['project_id']]['cost'] += $breakdown['allocated_cost'];
                    $rows[$breakdown['project_id']]['hours'] += $breakdown['hours'];
                    $rows[$breakdown['project_id']]['vacation_provision'] += round((float) $payroll->vacation_provision * $ratio, 2);
                }

                $unassigned += $metrics['unassigned_cost'];
            });

        return [
            'projects' => collect($rows)->map(fn (array $row) => [
                'cost' => round($row['cost'], 2),
                'hours' => round($row['hours'], 4),
                'vacation_provision' => round($row['vacation_provision'], 2),
            ])->all(),
            'unassigned_cost' => round($unassigned, 2),
        ];
    }

    private function referenceCapacityHours(Person $person, Carbon $period, PayrollRecord $payroll): array
    {
        $monthHours = (int) ($person->monthly_hours ?: 0);
        $estimated = false;
        $label = 'Horas contractuales del período';

        if ($monthHours <= 0) {
            $monthHours = (int) ProjectAssignment::query()
                ->where('company_id', $person->company_id)
                ->where('person_id', $person->id)
                ->where(function ($query) use ($period) {
                    $query->whereNull('start_date')->orWhereDate('start_date', '<=', $period->copy()->endOfMonth());
                })
                ->where(function ($query) use ($period) {
                    $query->whereNull('end_date')->orWhereDate('end_date', '>=', $period);
                })
                ->sum('monthly_hours');
            $label = 'Horas referenciales desde asignaciones vigentes';
            $estimated = true;
        }

        if ($monthHours <= 0) {
            return [
                'hours' => round((float) ($payroll->hours_approved ?? 0), 4),
                'label' => 'Estimado según horas aprobadas del período',
                'estimated' => true,
            ];
        }

        $activeDays = $this->activeDaysInPeriod($person, $period);
        $ratio = $period->daysInMonth > 0 ? ($activeDays / $period->daysInMonth) : 0;

        return [
            'hours' => round($monthHours * $ratio, 4),
            'label' => $label,
            'estimated' => $estimated || $ratio < 1,
        ];
    }

    private function activeDaysInPeriod(Person $person, Carbon $period): int
    {
        $start = $person->start_date ? Carbon::parse($person->start_date)->max($period) : $period->copy();
        $end = $person->end_date ? Carbon::parse($person->end_date)->min($period->copy()->endOfMonth()) : $period->copy()->endOfMonth();

        if ($end->lt($start)) {
            return 0;
        }

        return $start->diffInDays($end) + 1;
    }

    private function isApproved(TimeEntry $entry): bool
    {
        $code = strtolower((string) ($entry->approvalStatus?->code ?: $entry->approval_status));

        return in_array($code, ['approved', 'aprobado'], true);
    }
}
