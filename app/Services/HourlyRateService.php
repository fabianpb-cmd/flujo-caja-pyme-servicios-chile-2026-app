<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\TimeEntry;
use App\Support\UiFormatter;
use Carbon\CarbonInterface;
use DomainException;

class HourlyRateService
{
    public function __construct(
        private readonly LegalParameterService $legalParameters,
        private readonly CurrencyConversionService $conversions,
    ) {
    }

    public function resolvePersonRate(Person $person, CarbonInterface|string $date): float
    {
        return $this->resolve(
            companyId: $person->company_id,
            amount: $person->hourly_value,
            unitType: $person->hourly_rate_unit_type,
            currency: $person->hourlyRateCurrency,
            date: $date,
        );
    }

    public function resolveAssignmentRate(ProjectAssignment $assignment, CarbonInterface|string $date): float
    {
        return $this->resolve(
            companyId: $assignment->company_id,
            amount: $assignment->hourly_value,
            unitType: $assignment->hourly_rate_unit_type,
            currency: $assignment->hourlyRateCurrency,
            date: $date,
        );
    }

    public function resolveAssignmentProjectValue(ProjectAssignment $assignment, CarbonInterface|string $date): float
    {
        return $this->resolve(
            companyId: $assignment->company_id,
            amount: $assignment->project_value,
            unitType: $assignment->hourly_rate_unit_type,
            currency: $assignment->hourlyRateCurrency,
            date: $date,
        );
    }

    public function resolveProjectRate(Project $project, CarbonInterface|string $date): float
    {
        return $this->resolve(
            companyId: $project->company_id,
            amount: $project->contracted_hourly_rate,
            unitType: 'CURRENCY',
            currency: $project->salesCurrency,
            date: $date,
        );
    }

    public function resolveCostingForAssignment(ProjectAssignment $assignment, CarbonInterface|string $date): array
    {
        $assignment->loadMissing(['person.hourlyRateCurrency', 'project.salesCurrency', 'hourlyRateCurrency']);
        $person = $assignment->person;

        if ((float) ($assignment->hourly_value ?? 0) > 0) {
            return $this->detailsFromAssignment($assignment, $date);
        }

        if ($person && (float) ($person->hourly_value ?? 0) > 0) {
            return $this->detailsFromPerson($person, $date, $assignment);
        }

        return [
            'amount' => null,
            'unit_type' => null,
            'currency' => null,
            'currency_code' => null,
            'currency_symbol' => null,
            'source_type' => null,
            'source_label' => null,
            'assignment_id' => $assignment->id,
            'effective_date' => $this->dateString($date),
        ];
    }

    public function resolveCostingForTimeEntry(Person $person, Project $project, CarbonInterface|string $date): array
    {
        $assignment = $this->assignmentFor($person->company_id, $person->id, $project->id, $date);

        if ($assignment && (float) $assignment->hourly_value > 0) {
            return $this->detailsFromAssignment($assignment, $date);
        }

        if ((float) ($person->hourly_value ?? 0) > 0) {
            return $this->detailsFromPerson($person, $date, $assignment);
        }

        return [
            'amount' => null,
            'unit_type' => null,
            'currency' => null,
            'currency_code' => null,
            'currency_symbol' => null,
            'source_type' => null,
            'source_label' => null,
            'assignment_id' => $assignment?->id,
            'effective_date' => $this->dateString($date),
        ];
    }

    public function resolveCostingForEntry(TimeEntry $entry): array
    {
        $entry->loadMissing(['person.hourlyRateCurrency', 'project.salesCurrency', 'assignment.hourlyRateCurrency', 'assignment.assignmentStatus']);

        $person = $entry->person;
        $project = $entry->project;
        if (! $person || ! $project) {
            return [
                'amount' => null,
                'unit_type' => null,
                'currency' => null,
                'currency_code' => null,
                'currency_symbol' => null,
                'source_type' => null,
                'source_label' => null,
                'assignment_id' => $entry->assignment_id,
                'effective_date' => optional($entry->entry_date)?->toDateString(),
            ];
        }

        return $this->resolveCostingForTimeEntry($person, $project, $entry->entry_date ?? now())
            + ['assignment_id' => $entry->assignment_id];
    }

    public function resolveForTimeEntry(Person $person, Project $project, CarbonInterface|string $date): array
    {
        $assignment = $this->assignmentFor($person->company_id, $person->id, $project->id, $date);

        if ($assignment && (float) $assignment->hourly_value > 0) {
            return $this->detailsFromAssignment($assignment, $date);
        }

        if ((float) ($project->contracted_hourly_rate ?? 0) > 0) {
            return $this->detailsFromProject($project, $date, $assignment);
        }

        return [
            'amount' => null,
            'unit_type' => null,
            'currency' => null,
            'currency_code' => null,
            'currency_symbol' => null,
            'source_type' => null,
            'source_label' => null,
            'assignment_id' => $assignment?->id,
            'effective_date' => $this->dateString($date),
        ];
    }

    public function resolveForEntry(TimeEntry $entry): array
    {
        $entry->loadMissing(['person', 'project.salesCurrency', 'assignment.hourlyRateCurrency', 'assignment.assignmentStatus']);

        $person = $entry->person;
        $project = $entry->project;
        if (! $person || ! $project) {
            return [
                'amount' => null,
                'unit_type' => null,
                'currency' => null,
                'currency_code' => null,
                'currency_symbol' => null,
                'source_type' => null,
                'source_label' => null,
                'assignment_id' => $entry->assignment_id,
                'effective_date' => optional($entry->entry_date)?->toDateString(),
            ];
        }

        return $this->resolveForTimeEntry($person, $project, $entry->entry_date ?? now())
            + ['assignment_id' => $entry->assignment_id];
    }

    private function resolve(
        int $companyId,
        float|int|string|null $amount,
        ?string $unitType,
        Currency|string|null $currency,
        CarbonInterface|string $date,
    ): float {
        $rawAmount = (float) ($amount ?? 0);
        if ($rawAmount <= 0) {
            return 0.0;
        }

        if (strtoupper((string) $unitType) === 'UF') {
            $uf = (float) $this->legalParameters->ufValue($companyId, $date);

            return round($rawAmount * $uf, 0, PHP_ROUND_HALF_UP);
        }

        $currencyCode = strtoupper((string) ($currency instanceof Currency ? $currency->code : ($currency ?: 'CLP')));
        if ($currencyCode === 'CLP') {
            return round($rawAmount, 0, PHP_ROUND_HALF_UP);
        }

        if (! $currency instanceof Currency) {
            throw new DomainException("Falta configuración de moneda para convertir {$currencyCode} a CLP.");
        }

        $exchangeRate = (float) $this->legalParameters->exchangeRate($companyId, $currency->id, $date);

        return (float) $this->conversions->convert(
            amount: $rawAmount,
            fromCurrency: $currency,
            toCurrency: 'CLP',
            exchangeRate: $exchangeRate,
            date: $date,
        )['converted_amount'];
    }

    private function assignmentFor(int $companyId, int $personId, int $projectId, CarbonInterface|string $date): ?ProjectAssignment
    {
        return ProjectAssignment::query()
            ->where('company_id', $companyId)
            ->where('person_id', $personId)
            ->where('project_id', $projectId)
            ->with(['assignmentStatus', 'hourlyRateCurrency'])
            ->where(function ($query) use ($date) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date);
            })
            ->get()
            ->first(fn (ProjectAssignment $assignment): bool => strtolower((string) ($assignment->assignmentStatus?->code ?: $assignment->status)) === 'active');
    }

    private function detailsFromAssignment(ProjectAssignment $assignment, CarbonInterface|string $date): array
    {
        $unitType = strtoupper((string) ($assignment->hourly_rate_unit_type ?: 'CURRENCY'));
        $currency = $this->displayCurrencyForRate($unitType, $assignment->hourlyRateCurrency);

        return [
            'amount' => $assignment->hourly_value,
            'unit_type' => $unitType,
            'currency' => $currency,
            'currency_code' => $currency instanceof Currency ? strtoupper((string) $currency->code) : UiFormatter::currencyCode($currency),
            'currency_symbol' => $currency instanceof Currency ? ($currency->symbol ?: strtoupper((string) $currency->code)) : null,
            'source_type' => 'assignment',
            'source_label' => trim((string) ($assignment->code ?: $assignment->project?->name ?: 'Asignación')),
            'assignment_id' => $assignment->id,
            'effective_date' => $this->dateString($date),
        ];
    }

    private function detailsFromPerson(Person $person, CarbonInterface|string $date, ?ProjectAssignment $assignment = null): array
    {
        $unitType = strtoupper((string) ($person->hourly_rate_unit_type ?: 'CURRENCY'));
        $currency = $this->displayCurrencyForRate($unitType, $person->hourlyRateCurrency);

        return [
            'amount' => $person->hourly_value,
            'unit_type' => $unitType,
            'currency' => $currency,
            'currency_code' => $currency instanceof Currency ? strtoupper((string) $currency->code) : UiFormatter::currencyCode($currency),
            'currency_symbol' => $currency instanceof Currency ? ($currency->symbol ?: strtoupper((string) $currency->code)) : null,
            'source_type' => 'person',
            'source_label' => trim((string) ('Persona · '.($person->full_name ?: $person->name ?: 'No informado'))),
            'assignment_id' => $assignment?->id,
            'effective_date' => $this->dateString($date),
        ];
    }

    private function detailsFromProject(Project $project, CarbonInterface|string $date, ?ProjectAssignment $assignment = null): array
    {
        $currency = $project->salesCurrency ?: 'CLP';
        return [
            'amount' => $project->contracted_hourly_rate,
            'unit_type' => 'CURRENCY',
            'currency' => $currency,
            'currency_code' => $currency instanceof Currency ? strtoupper((string) $currency->code) : UiFormatter::currencyCode($currency),
            'currency_symbol' => $currency instanceof Currency ? ($currency->symbol ?: strtoupper((string) $currency->code)) : null,
            'source_type' => 'project',
            'source_label' => trim((string) ($project->code ?: $project->name ?: 'Proyecto')),
            'assignment_id' => $assignment?->id,
            'effective_date' => $this->dateString($date),
        ];
    }

    private function displayCurrencyForRate(string $unitType, Currency|string|null $currency): Currency|string
    {
        if ($unitType === 'UF') {
            return 'UF';
        }

        return $currency ?: 'CLP';
    }

    private function dateString(CarbonInterface|string $date): ?string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
    }
}
