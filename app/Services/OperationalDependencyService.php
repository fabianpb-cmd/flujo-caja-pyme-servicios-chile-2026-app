<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRecord;
use App\Models\PayrollRecordTimeEntry;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocument;
use App\Models\SalesDocumentTimeEntry;
use App\Models\Scenario;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Prevents deleting operational records that are still referenced by another
 * business record. This is deliberately stricter than nullable/cascading SQL
 * foreign keys: operational history must not disappear or lose its context
 * from a generic CRUD delete.
 */
class OperationalDependencyService
{
    /**
     * @return Collection<int, array{label: string, count: int}>
     */
    public function blockers(Model $record): Collection
    {
        return collect($this->dependenciesFor($record))
            ->map(fn (array $dependency): array => [
                'label' => $dependency['label'],
                'count' => $this->dependencyCount($dependency, $record),
            ])
            ->filter(fn (array $dependency): bool => $dependency['count'] > 0)
            ->values();
    }

    public function deletionMessage(Model $record): ?string
    {
        return $this->mutationMessage($record, 'eliminar');
    }

    public function mutationMessage(Model $record, string $action = 'modificar'): ?string
    {
        $blockers = $this->blockers($record);

        if ($blockers->isEmpty()) {
            return null;
        }

        return sprintf(
            'No se puede %s el registro porque está siendo utilizado por: %s. Desactívelo o reasigne las dependencias antes de continuar.',
            $action,
            $this->references($blockers),
        );
    }

    /**
     * Direct references that must retain their operational and financial trace.
     * The map intentionally includes nullable database relations as well: null
     * on delete protects the SQL statement, but it would silently erase context.
     *
     * @return array<int, array{model: class-string<Model>, column: string, label: string}>
     */
    private function dependenciesFor(Model $record): array
    {
        return match ($record::class) {
            Client::class => [
                $this->dependency(Project::class, 'client_id', 'proyectos'),
                $this->dependency(ProjectAssignment::class, 'client_id', 'asignaciones'),
                $this->dependency(TimeEntry::class, 'client_id', 'registros de horas'),
                $this->dependency(SalesDocument::class, 'client_id', 'documentos de venta'),
                $this->dependency(ExpenseDocument::class, 'client_id', 'egresos asociados'),
                $this->dependency(Scenario::class, 'affected_client_id', 'escenarios'),
            ],
            Project::class => [
                $this->dependency(ProjectAssignment::class, 'project_id', 'asignaciones'),
                $this->dependency(TimeEntry::class, 'project_id', 'registros de horas'),
                $this->dependency(PayrollRecord::class, 'project_id', 'remuneraciones'),
                $this->dependency(SalesDocument::class, 'project_id', 'documentos de venta'),
                $this->dependency(ExpenseDocument::class, 'project_id', 'egresos asociados'),
                $this->dependency(Budget::class, 'project_id', 'presupuestos'),
                $this->dependency(CashMovement::class, 'project_id', 'movimientos de caja'),
            ],
            Person::class => [
                $this->dependency(ProjectAssignment::class, 'person_id', 'asignaciones'),
                $this->dependency(TimeEntry::class, 'person_id', 'registros de horas'),
                $this->dependency(PayrollRecord::class, 'person_id', 'remuneraciones'),
                $this->dependency(PayrollAdjustment::class, 'person_id', 'novedades de remuneración'),
            ],
            ProjectAssignment::class => [
                $this->dependency(TimeEntry::class, 'assignment_id', 'registros de horas'),
                $this->dependency(SalesDocumentTimeEntry::class, 'project_assignment_id', 'líneas de prefacturación'),
            ],
            TimeEntry::class => [
                $this->dependency(SalesDocumentTimeEntry::class, 'time_entry_id', 'líneas de prefacturación'),
                $this->dependency(PayrollRecordTimeEntry::class, 'time_entry_id', 'remuneraciones por hora'),
            ],
            SalesDocument::class => [
                $this->dependency(SalesDocumentTimeEntry::class, 'sales_document_id', 'líneas de prefacturación'),
                $this->cashMovementDependency('sales_document'),
            ],
            ExpenseDocument::class => [
                $this->cashMovementDependency('expense_document'),
            ],
            PayrollRecord::class => [
                $this->cashMovementDependency('payroll_record'),
            ],
            LegalObligation::class => [
                $this->cashMovementDependency('legal_obligation'),
            ],
            CashAccount::class => [
                $this->dependency(CashMovement::class, 'cash_account_id', 'movimientos de caja'),
            ],
            Scenario::class => [
                $this->dependency(Budget::class, 'scenario_id', 'presupuestos'),
            ],
            default => [],
        };
    }

    /**
     * @param class-string<Model> $model
     * @return array{model: class-string<Model>, column: string, label: string}
     */
    private function dependency(string $model, string $column, string $label): array
    {
        return compact('model', 'column', 'label');
    }

    /**
     * @param array{model?: class-string<Model>, column?: string, label: string, query?: callable(Model): \Illuminate\Database\Eloquent\Builder} $dependency
     */
    private function dependencyCount(array $dependency, Model $record): int
    {
        if (isset($dependency['query'])) {
            return (int) $dependency['query']($record)->count();
        }

        return (int) $dependency['model']::query()
            ->where($dependency['column'], $record->getKey())
            ->count();
    }

    /**
     * @return array{label: string, query: callable(Model): \Illuminate\Database\Eloquent\Builder}
     */
    private function queryDependency(string $label, callable $query): array
    {
        return compact('label', 'query');
    }

    private function cashMovementDependency(string $sourceType): array
    {
        return $this->queryDependency(
            'movimientos de caja',
            fn (Model $record) => CashMovement::query()
                ->where('company_id', $record->getAttribute('company_id'))
                ->where('source_document_type', $sourceType)
                ->where('source_document_code', $record->getAttribute('code'))
        );
    }

    /**
     * @param Collection<int, array{label: string, count: int}> $blockers
     */
    private function references(Collection $blockers): string
    {
        return $blockers
            ->map(fn (array $dependency): string => $dependency['count'].' '.$dependency['label'])
            ->implode(', ');
    }
}
