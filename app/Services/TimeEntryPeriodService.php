<?php

namespace App\Services;

use App\Models\ApprovalStatus;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\TimeEntry;
use App\Support\MassAssignment;
use App\Support\UiFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimeEntryPeriodService
{
    public function __construct(
        private readonly HourlyRateService $hourlyRates,
        private readonly CatalogService $catalogs,
    ) {
    }

    public function preview(int $companyId, array $payload): array
    {
        $entryMode = strtolower((string) ($payload['entry_mode'] ?? 'daily'));
        if ($entryMode !== 'period') {
            return [
                'rows' => [],
                'total_hours' => 0.0,
                'can_save' => false,
                'field_errors' => [],
                'summary' => [],
            ];
        }

        $fieldErrors = [];
        $rows = [];
        $person = $this->person($companyId, $payload);
        $project = $this->project($companyId, $payload);
        $approvalCode = $this->approvalCode($companyId, $payload);
        $paymentStatus = strtolower(trim((string) ($payload['payment_status'] ?? '')));

        if ($paymentStatus === 'paid' && $approvalCode !== 'approved') {
            $fieldErrors['payment_status'][] = 'Un registro solo puede marcarse como pagado cuando su aprobación está en estado Aprobado.';
        }

        $startDate = UiFormatter::parseDateInput($payload['period_start_date'] ?? null);
        $endDate = UiFormatter::parseDateInput($payload['period_end_date'] ?? null);
        if ($startDate && $endDate && $endDate->lt($startDate)) {
            $fieldErrors['period_end_date'][] = 'La fecha término debe ser igual o posterior a la fecha inicio.';
        }

        $distributionMode = strtolower((string) ($payload['period_distribution_mode'] ?? 'equal'));
        $rows = $this->buildRows($payload, $distributionMode, $startDate, $endDate);

        if ($rows->isEmpty() && $startDate && $endDate) {
            $fieldErrors['period_rows'][] = 'No hay fechas disponibles para el período indicado.';
        }

        $duplicateDates = $rows
            ->filter(fn (array $row): bool => $this->rowIncluded($row))
            ->groupBy('entry_date')
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->keys()
            ->values();

        if ($duplicateDates->isNotEmpty()) {
            $fieldErrors['period_rows'][] = 'No se puede registrar más de una fila para la misma fecha en una sola carga por período.';
        }

        $includedRows = $rows->filter(fn (array $row): bool => $this->rowIncluded($row));
        if ($includedRows->isEmpty()) {
            $fieldErrors['period_rows'][] = 'Seleccione al menos una fecha para registrar.';
        }

        $activeAssignments = collect();
        if ($person && $project) {
            $activeAssignments = ProjectAssignment::query()
                ->where('company_id', $companyId)
                ->where('person_id', $person->id)
                ->where('project_id', $project->id)
                ->with(['assignmentStatus:id,code', 'costCenter:id,name', 'hourlyRateCurrency:id,code,symbol,minor_units', 'person.hourlyRateCurrency'])
                ->get()
                ->filter(fn (ProjectAssignment $assignment): bool => strtolower((string) ($assignment->assignmentStatus?->code ?? '')) === 'active')
                ->values();
        }

        $existingHours = $this->existingHoursByDate($companyId, $person?->id, $rows);
        $batchHours = $includedRows
            ->mapWithKeys(fn (array $row): array => [
                $row['entry_date'] => round((float) ($row['hours_worked'] ?? 0), 2),
            ]);

        $previewRows = [];
        foreach ($rows as $row) {
            $rowPreview = $this->previewRow(
                row: $row,
                person: $person,
                project: $project,
                approvalCode: $approvalCode,
                activeAssignments: $activeAssignments,
                existingHours: (float) ($existingHours[$row['entry_date']] ?? 0),
                batchHours: (float) ($batchHours[$row['entry_date']] ?? 0),
            );

            if ($rowPreview['errors'] !== []) {
                $fieldErrors['period_rows'] = array_merge($fieldErrors['period_rows'] ?? [], array_map(
                    fn (string $message): string => sprintf('%s: %s', $rowPreview['date_display'], $message),
                    $rowPreview['errors']
                ));
            }

            $previewRows[] = $rowPreview;
        }

        $normalizedRows = collect($previewRows);
        $totalHours = round((float) $normalizedRows
            ->filter(fn (array $row): bool => $row['included'])
            ->sum('hours_worked'), 2);

        return [
            'rows' => $normalizedRows->values()->all(),
            'total_hours' => $totalHours,
            'can_save' => empty($fieldErrors),
            'field_errors' => $fieldErrors,
            'summary' => $this->buildSummary($normalizedRows, $project),
        ];
    }

    public function create(int $companyId, array $payload): array
    {
        $preview = $this->preview($companyId, $payload);
        if (! empty($preview['field_errors'])) {
            throw ValidationException::withMessages($preview['field_errors']);
        }

        $project = Project::query()->forCompany($companyId)->findOrFail($payload['project_id']);
        $created = DB::transaction(function () use ($companyId, $payload, $preview, $project) {
            $createdEntries = [];

            foreach ($preview['rows'] as $row) {
                if (! ($row['included'] ?? false)) {
                    continue;
                }

                $attributes = [
                    'company_id' => $companyId,
                    'person_id' => (int) $payload['person_id'],
                    'client_id' => (int) $project->client_id,
                    'project_id' => (int) $project->id,
                    'assignment_id' => $row['assignment_id'],
                    'entry_date' => $row['entry_date'],
                    'activity_id' => (int) $payload['activity_id'],
                    'hours_worked' => $row['hours_worked'],
                    'hours_approved' => $row['hours_approved'],
                    'hourly_value' => $row['hourly_value_amount'],
                    'calculated_amount' => round((float) $row['hours_approved'] * (float) ($row['hourly_value_amount'] ?? 0), 2),
                    'cost_center_id' => ($payload['cost_center_id'] ?? null) ?: ($row['cost_center_id'] ?? null),
                    'approval_status_id' => (int) $payload['approval_status_id'],
                    'payment_status' => $payload['payment_status'],
                ];

                $attributes = $this->catalogs->syncLegacyFields('time-entries', $attributes);
                $createdEntries[] = MassAssignment::create(TimeEntry::class, $attributes);
            }

            return $createdEntries;
        });

        return [
            'created' => $created,
            'days_count' => count($created),
            'total_hours' => $preview['total_hours'],
        ];
    }

    private function buildRows(array $payload, string $distributionMode, ?Carbon $startDate, ?Carbon $endDate): Collection
    {
        $providedRows = collect($payload['period_rows'] ?? [])
            ->map(function (array $row): array {
                return [
                    'entry_date' => UiFormatter::parseDateInput($row['entry_date'] ?? null)?->toDateString(),
                    'included' => filter_var($row['included'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
                    'hours_worked' => $this->numericOrNull($row['hours_worked'] ?? null),
                ];
            })
            ->filter(fn (array $row): bool => filled($row['entry_date']))
            ->values();

        $baseRows = $providedRows->isNotEmpty()
            ? $providedRows
            : $this->defaultRowsForRange($startDate, $endDate);

        if ($distributionMode === 'manual') {
            return $baseRows;
        }

        if ($distributionMode === 'equal') {
            $hoursPerDay = $this->numericOrNull($payload['period_hours_per_day'] ?? null);

            return $baseRows->map(fn (array $row): array => [
                ...$row,
                'hours_worked' => $row['included'] && $hoursPerDay !== null ? $hoursPerDay : null,
            ])->values();
        }

        if ($distributionMode === 'total') {
            $totalHours = $this->numericOrNull($payload['period_total_hours'] ?? null);
            $includedCount = max(1, $baseRows->where('included', true)->count());
            $distributed = $this->distributeTotalAcrossRows((float) ($totalHours ?? 0), $includedCount);
            $distributedIndex = 0;

            return $baseRows->map(function (array $row) use (&$distributedIndex, $distributed): array {
                if (! $row['included']) {
                    return [...$row, 'hours_worked' => null];
                }

                $hours = $distributed[$distributedIndex] ?? null;
                $distributedIndex++;

                return [...$row, 'hours_worked' => $hours];
            })->values();
        }

        return $baseRows;
    }

    private function defaultRowsForRange(?Carbon $startDate, ?Carbon $endDate): Collection
    {
        if (! $startDate || ! $endDate || $endDate->lt($startDate)) {
            return collect();
        }

        $rows = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $rows[] = [
                'entry_date' => $cursor->toDateString(),
                'included' => ! $cursor->isWeekend(),
                'hours_worked' => null,
            ];
            $cursor->addDay();
        }

        return collect($rows);
    }

    private function distributeTotalAcrossRows(float $totalHours, int $includedCount): array
    {
        if ($includedCount <= 0 || $totalHours <= 0) {
            return [];
        }

        $rows = [];
        $remaining = round($totalHours, 2);
        $base = round($totalHours / $includedCount, 2);

        for ($index = 0; $index < $includedCount; $index++) {
            if ($index === $includedCount - 1) {
                $rows[] = round($remaining, 2);
                break;
            }

            $rows[] = $base;
            $remaining = round($remaining - $base, 2);
        }

        return $rows;
    }

    private function previewRow(
        array $row,
        ?Person $person,
        ?Project $project,
        ?string $approvalCode,
        Collection $activeAssignments,
        float $existingHours,
        float $batchHours,
    ): array {
        $date = UiFormatter::parseDateInput($row['entry_date'] ?? null);
        $errors = [];
        $warnings = [];
        $assignment = null;
        $rate = null;
        $hoursWorked = $this->numericOrNull($row['hours_worked'] ?? null);

        if (! $date) {
            $errors[] = 'Fecha inválida.';
        }

        if (($row['included'] ?? false) && $hoursWorked === null) {
            $errors[] = 'Ingrese las horas del día.';
        }

        if (($row['included'] ?? false) && $hoursWorked !== null && ($hoursWorked <= 0 || $hoursWorked > 24)) {
            $errors[] = $hoursWorked > 24
                ? 'Las horas trabajadas no pueden superar 24 en un mismo registro.'
                : 'Las horas trabajadas deben ser mayores que 0.';
        }

        if (($row['included'] ?? false) && (! $person || ! $project)) {
            $errors[] = 'Seleccione una persona y un proyecto válidos.';
        }

        if (($row['included'] ?? false) && $date && $person && $project) {
            $matchingAssignments = $activeAssignments
                ->filter(function (ProjectAssignment $assignment) use ($date): bool {
                    $startsBefore = $assignment->start_date === null || $assignment->start_date->startOfDay()->lte($date);
                    $endsAfter = $assignment->end_date === null || $assignment->end_date->startOfDay()->gte($date);

                    return $startsBefore && $endsAfter;
                })
                ->values();

            if ($matchingAssignments->isEmpty()) {
                $errors[] = $this->assignmentMissingMessage($activeAssignments);
            } elseif ($matchingAssignments->count() > 1) {
                $errors[] = 'Existe más de una asignación vigente para esta persona y proyecto en la fecha indicada.';
            } else {
                $assignment = $matchingAssignments->first();
                $rate = $this->hourlyRates->resolveCostingForAssignment($assignment, $date);
                if (($rate['amount'] ?? null) === null) {
                    $warnings[] = 'No existe un Valor HH de costeo configurado para esta fecha.';
                }
            }
        }

        if (($row['included'] ?? false) && $hoursWorked !== null && round($existingHours + $batchHours, 2) > 24) {
            $errors[] = sprintf(
                'La suma diaria de horas trabajadas para esta persona no puede superar 24 (existentes: %s, nuevas: %s).',
                UiFormatter::formatHours($existingHours),
                UiFormatter::formatHours($batchHours)
            );
        }

        $hoursApproved = $this->resolvedApprovedHours($approvalCode, $hoursWorked);

        return [
            'entry_date' => $date?->toDateString(),
            'date_display' => $date ? ucfirst($date->locale('es')->isoFormat('ddd DD/MM/YYYY')) : (string) ($row['entry_date'] ?? ''),
            'included' => (bool) ($row['included'] ?? false),
            'hours_worked' => $hoursWorked,
            'hours_approved' => $hoursApproved,
            'hours_display' => $hoursWorked !== null ? UiFormatter::formatHours($hoursWorked) : '—',
            'approved_display' => $hoursApproved !== null ? UiFormatter::formatHours($hoursApproved) : '—',
            'assignment_id' => $assignment?->id,
            'assignment_code' => $assignment?->code,
            'assignment_label' => $assignment ? trim((string) (($assignment->code ?: 'Asignación').' · '.($assignment->project?->name ?: $project?->name ?: 'No informado'))) : 'No disponible',
            'cost_center_id' => $assignment?->cost_center_id,
            'cost_center_name' => $assignment?->costCenter?->name,
            'hourly_value_amount' => $rate['amount'] ?? null,
            'hourly_value_display' => ($rate['amount'] ?? null) !== null
                ? trim(UiFormatter::formatMoney($rate['amount'], $rate['currency'] ?? 'CLP').' / HH')
                : 'No configurado',
            'hourly_value_source' => $rate['source_label'] ?? null,
            'existing_hours_display' => $existingHours > 0 ? UiFormatter::formatHours($existingHours) : '0,00 h',
            'status' => ! ($row['included'] ?? false)
                ? 'excluded'
                : ($errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'ok')),
            'errors' => $errors,
            'warnings' => $warnings,
            'status_label' => ! ($row['included'] ?? false)
                ? 'Excluida'
                : ($errors !== [] ? 'Revisión requerida' : ($warnings !== [] ? 'Con advertencia' : 'Lista')),
        ];
    }

    private function buildSummary(Collection $rows, ?Project $project): array
    {
        $includedRows = $rows->filter(fn (array $row): bool => $row['included'] && $row['status'] !== 'error');
        $rateLabels = $includedRows->pluck('hourly_value_display')->filter()->unique()->values();
        $rateSources = $includedRows->pluck('hourly_value_source')->filter()->unique()->values();
        $assignmentLabels = $includedRows->pluck('assignment_label')->filter()->unique()->values();

        return [
            'client_label' => $project?->client?->legal_name,
            'shared_rate_display' => $rateLabels->count() === 1 ? $rateLabels->first() : null,
            'shared_rate_source' => $rateSources->count() === 1 ? $rateSources->first() : null,
            'shared_assignment_label' => $assignmentLabels->count() === 1 ? $assignmentLabels->first() : null,
            'multiple_assignments' => $assignmentLabels->count() > 1,
            'multiple_rates' => $rateLabels->count() > 1,
        ];
    }

    private function existingHoursByDate(int $companyId, ?int $personId, Collection $rows): array
    {
        if (! $personId || $rows->isEmpty()) {
            return [];
        }

        $dates = $rows
            ->pluck('entry_date')
            ->filter()
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return [];
        }

        return TimeEntry::query()
            ->where('company_id', $companyId)
            ->where('person_id', $personId)
            ->whereIn(DB::raw('date(entry_date)'), $dates->all())
            ->selectRaw('date(entry_date) as entry_date, SUM(hours_worked) as total_hours')
            ->groupBy(DB::raw('date(entry_date)'))
            ->pluck('total_hours', 'entry_date')
            ->map(fn ($value) => round((float) $value, 2))
            ->all();
    }

    private function person(int $companyId, array $payload): ?Person
    {
        if (! filled($payload['person_id'] ?? null)) {
            return null;
        }

        return Person::query()->forCompany($companyId)->find($payload['person_id']);
    }

    private function project(int $companyId, array $payload): ?Project
    {
        if (! filled($payload['project_id'] ?? null)) {
            return null;
        }

        return Project::query()->forCompany($companyId)->with('client')->find($payload['project_id']);
    }

    private function approvalCode(int $companyId, array $payload): ?string
    {
        if (! filled($payload['approval_status_id'] ?? null)) {
            return null;
        }

        return ApprovalStatus::query()
            ->where('company_id', $companyId)
            ->whereKey($payload['approval_status_id'])
            ->value('code');
    }

    private function resolvedApprovedHours(?string $approvalCode, ?float $hoursWorked): ?float
    {
        if ($hoursWorked === null) {
            return null;
        }

        return match (strtolower((string) $approvalCode)) {
            'approved' => round($hoursWorked, 2),
            'rejected' => 0.0,
            default => 0.0,
        };
    }

    private function assignmentMissingMessage(Collection $assignments): string
    {
        if ($assignments->count() === 1) {
            $assignment = $assignments->first();
            $range = match (true) {
                $assignment->start_date && $assignment->end_date => sprintf(
                    '%s al %s',
                    UiFormatter::formatDate($assignment->start_date),
                    UiFormatter::formatDate($assignment->end_date)
                ),
                $assignment->start_date => 'desde '.UiFormatter::formatDate($assignment->start_date),
                $assignment->end_date => 'hasta '.UiFormatter::formatDate($assignment->end_date),
                default => 'sin vigencia informada',
            };

            return 'La fecha registrada está fuera de la vigencia de la asignación ('.$range.').';
        }

        if ($assignments->isNotEmpty()) {
            return 'La fecha registrada está fuera de la vigencia de la asignación seleccionada.';
        }

        return 'No existe una asignación vigente para esta persona y proyecto en la fecha indicada.';
    }

    private function rowIncluded(array $row): bool
    {
        return (bool) ($row['included'] ?? false);
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = is_numeric($value) ? (float) $value : (float) str_replace(',', '.', (string) $value);

        return is_finite($numeric) ? round($numeric, 2) : null;
    }
}
