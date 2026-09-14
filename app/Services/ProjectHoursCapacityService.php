<?php

namespace App\Services;

use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;

class ProjectHoursCapacityService
{
    private const EPSILON = 0.00001;

    public function __construct(private readonly BillingStrategyService $strategies)
    {
    }

    public function summarize(Project $project, CarbonInterface|string|null $period = null): array
    {
        $project->loadMissing(['contractType']);
        $strategy = $this->strategies->forProject($project);
        $scope = match ($strategy) {
            BillingStrategyService::HOURS_BANK => 'PROJECT',
            BillingStrategyService::MONTHLY_RECURRING => 'MONTH',
            default => null,
        };
        $month = $strategy === BillingStrategyService::MONTHLY_RECURRING
            ? Carbon::parse($period ?: now())->startOfMonth()
            : null;

        if ($scope === null) {
            return ['strategy' => $strategy, 'capacity_hours' => null, 'consumed_hours' => null, 'remaining_hours' => null, 'capacity_scope' => null, 'period_date' => null];
        }

        if ((float) $project->sale_net <= 0 || (float) $project->contracted_hourly_rate <= 0) {
            return ['strategy' => $strategy, 'capacity_hours' => null, 'consumed_hours' => null, 'remaining_hours' => null, 'capacity_scope' => $scope, 'period_date' => $month?->toDateString()];
        }

        $capacity = (float) $project->sale_net / (float) $project->contracted_hourly_rate;
        $consumed = $this->approvedEntries($project, $month)->sum(fn (TimeEntry $entry): float => (float) $entry->hours_approved);

        return [
            'strategy' => $strategy,
            'capacity_hours' => $capacity,
            'consumed_hours' => round((float) $consumed, 4),
            'remaining_hours' => round($capacity - (float) $consumed, 4),
            'capacity_scope' => $scope,
            'period_date' => $month?->toDateString(),
        ];
    }

    public function assertWithinCapacity(Project $project, CarbonInterface|string|null $period = null): array
    {
        $summary = $this->summarize($project, $period);
        if ($summary['capacity_hours'] !== null && $summary['consumed_hours'] > $summary['capacity_hours'] + self::EPSILON) {
            $label = $summary['capacity_scope'] === 'MONTH' ? 'La bolsa mensual de horas fue excedida por HH aprobadas.' : 'La bolsa total de horas fue excedida por HH aprobadas.';
            throw new DomainException($label.' Ajuste el contrato o las HH aprobadas antes de facturar.');
        }

        return $summary;
    }

    public function assertExistingConsumptionWithinCapacity(Project $project): void
    {
        $strategy = $this->strategies->forProject($project);
        if ($strategy === BillingStrategyService::HOURS_BANK) {
            $this->assertWithinCapacity($project);
            return;
        }
        if ($strategy !== BillingStrategyService::MONTHLY_RECURRING) {
            return;
        }

        $periods = $this->approvedEntries($project)->map(fn (TimeEntry $entry) => $entry->entry_date->copy()->startOfMonth()->toDateString())->unique();
        foreach ($periods as $period) {
            $this->assertWithinCapacity($project, $period);
        }
    }

    private function approvedEntries(Project $project, ?Carbon $period = null)
    {
        return TimeEntry::query()
            ->forCompany($project->company_id)
            ->where('project_id', $project->id)
            ->where('hours_approved', '>', 0)
            ->when($period, fn ($query) => $query->whereBetween('entry_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()]))
            ->with('approvalStatus')
            ->get()
            ->filter(fn (TimeEntry $entry): bool => in_array(strtolower((string) ($entry->approvalStatus?->code ?: $entry->approval_status)), ['approved', 'aprobado'], true));
    }
}
