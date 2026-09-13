<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementBankAssignment;
use App\Models\User;
use App\Support\MassAssignment;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CashMovementBankRegularizationService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Applies the effective bank-account rule without exposing the overlay to
     * financial callers. Direct account ownership remains authoritative.
     */
    public function forEffectiveAccount(Builder $query, CashAccount $account): Builder
    {
        return $query->where(function (Builder $movementQuery) use ($account): void {
            $movementQuery->where('cash_account_id', $account->id)
                ->orWhereExists($this->activeAssignmentSubquery($account->id));
        });
    }

    /**
     * Excludes only active post-cutover overlays. Pre-cutover classifications
     * deliberately retain their legacy Cash Flow treatment.
     */
    public function withoutActivePostCutoverAssignment(Builder $query): Builder
    {
        return $query->whereNotExists($this->activeAssignmentSubquery());
    }

    public function assignmentsForMovement(CashMovement $movement): Builder
    {
        return CashMovementBankAssignment::query()
            ->forCompany($movement->company_id)
            ->where('cash_movement_id', $movement->id)
            ->latest('assigned_at');
    }

    public function assign(int $companyId, int $movementId, int $accountId, ?string $reason, ?User $user = null): CashMovementBankAssignment
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainException('Debe indicar el motivo de la regularización bancaria.');
        }

        return DB::transaction(function () use ($companyId, $movementId, $accountId, $reason, $user): CashMovementBankAssignment {
            // Authoritative lock order: account, movement, assignment/reconciliation.
            $account = $this->lockedEligibleAccount($companyId, $accountId);
            $movement = CashMovement::query()
                ->forCompany($companyId)
                ->whereKey($movementId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEligibleMovement($movement);
            $active = $this->assignmentsForMovement($movement)->active()->lockForUpdate()->first();
            if ($active) {
                throw new DomainException('El movimiento ya tiene una asignación bancaria activa.');
            }

            $classification = $this->classify($movement, $account);
            if ($classification === 'post_cutover') {
                $this->assertOpenBankPeriod($account, $movement->movement_date->toDateString());
            }

            try {
                /** @var CashMovementBankAssignment $assignment */
                $assignment = MassAssignment::create(CashMovementBankAssignment::class, [
                    'company_id' => $companyId,
                    'cash_movement_id' => $movement->id,
                    'cash_account_id' => $account->id,
                    'classification' => $classification,
                    'status' => 'active',
                    'reason' => $reason,
                    'assigned_by_user_id' => $user?->id,
                    'assigned_at' => now(),
                ]);
            } catch (QueryException $exception) {
                // The generated-column unique index is the final concurrent guard.
                if (str_contains(strtolower($exception->getMessage()), 'cash_movement_bank_assignments_one_active_unique')) {
                    throw new DomainException('El movimiento ya tiene una asignación bancaria activa.', previous: $exception);
                }
                throw $exception;
            }

            $this->audit->record(
                'cash_movement_bank_assignment.assigned',
                $assignment,
                $user,
                null,
                array_merge($assignment->toArray(), ['cash_movement_code' => $movement->code])
            );

            return $assignment->refresh();
        });
    }

    public function reverse(int $companyId, int $assignmentId, ?string $reason, ?User $user = null): CashMovementBankAssignment
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainException('Debe indicar el motivo de la reversión bancaria.');
        }

        $seed = CashMovementBankAssignment::query()->forCompany($companyId)->findOrFail($assignmentId);

        return DB::transaction(function () use ($companyId, $assignmentId, $seed, $reason, $user): CashMovementBankAssignment {
            // The seed only identifies the account; every mutable record is re-read under lock.
            $account = $this->lockedEligibleAccount($companyId, (int) $seed->cash_account_id);
            $movement = CashMovement::query()
                ->forCompany($companyId)
                ->whereKey($seed->cash_movement_id)
                ->lockForUpdate()
                ->firstOrFail();
            $assignment = CashMovementBankAssignment::query()
                ->forCompany($companyId)
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->cash_account_id !== $account->id || $assignment->cash_movement_id !== $movement->id || $assignment->status !== 'active') {
                throw new DomainException('La asignación bancaria ya no está activa.');
            }

            if ($assignment->classification === 'post_cutover') {
                $this->assertOpenBankPeriod($account, $movement->movement_date->toDateString());
            }

            $before = $assignment->toArray();
            MassAssignment::fillAndSave($assignment, [
                'status' => 'reversed',
                'reversed_by_user_id' => $user?->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);
            $this->audit->record('cash_movement_bank_assignment.reversed', $assignment->refresh(), $user, $before);

            return $assignment->refresh();
        });
    }

    public function classify(CashMovement $movement, CashAccount $account): string
    {
        return $movement->movement_date->copy()->startOfDay()->lte($account->opening_balance_date->copy()->startOfDay())
            ? 'pre_cutover'
            : 'post_cutover';
    }

    public function regularizationSummary(int $companyId): array
    {
        $base = CashMovement::query()
            ->forCompany($companyId)
            ->where('status', 'posted')
            ->whereNull('cash_account_id');

        return [
            'unregularized' => $this->summary((clone $base)->whereDoesntHave('activeBankAssignment')),
            'regularized_pre_cutover' => $this->summary((clone $base)->whereHas('activeBankAssignment', fn (Builder $query) => $query->where('classification', 'pre_cutover'))),
            'regularized_post_cutover' => $this->summary((clone $base)->whereHas('activeBankAssignment', fn (Builder $query) => $query->where('classification', 'post_cutover'))),
        ];
    }

    private function lockedEligibleAccount(int $companyId, int $accountId): CashAccount
    {
        $account = CashAccount::query()
            ->forCompany($companyId)
            ->with('currencyCatalog')
            ->whereKey($accountId)
            ->lockForUpdate()
            ->first();
        if (! $account || ! $account->is_active) {
            throw new DomainException('Seleccione una cuenta CLP activa de la empresa.');
        }
        $currency = strtoupper($account->currencyCatalog?->code ?: $account->currency ?: 'CLP');
        if ($currency !== 'CLP') {
            throw new DomainException('La regularización bancaria V1.1 solo admite cuentas CLP.');
        }
        if (! $account->opening_balance_date) {
            throw new DomainException('La cuenta requiere una fecha de saldo inicial.');
        }

        return $account;
    }

    private function assertEligibleMovement(CashMovement $movement): void
    {
        if ($movement->status !== 'posted') {
            throw new DomainException('Solo se pueden regularizar movimientos de caja contabilizados.');
        }
        if ($movement->cash_account_id !== null) {
            throw new DomainException('El movimiento ya tiene una cuenta de caja directa y no puede regularizarse.');
        }
    }

    private function assertOpenBankPeriod(CashAccount $account, string $movementDate): void
    {
        $closed = BankReconciliation::query()
            ->forCompany($account->company_id)
            ->where('cash_account_id', $account->id)
            ->where('status', 'reconciled')
            ->whereDate('reconciliation_date', '>=', $movementDate)
            ->lockForUpdate()
            ->exists();
        if ($closed) {
            throw new DomainException('El movimiento pertenece a un período bancario ya conciliado.');
        }
    }

    private function activeAssignmentSubquery(?int $accountId = null): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('cash_movement_bank_assignments')
            ->selectRaw('1')
            ->whereColumn('cash_movement_bank_assignments.cash_movement_id', 'cash_movements.id')
            ->whereColumn('cash_movement_bank_assignments.company_id', 'cash_movements.company_id')
            ->where('cash_movement_bank_assignments.status', 'active')
            ->where('cash_movement_bank_assignments.classification', 'post_cutover');
        if ($accountId !== null) {
            $query->where('cash_movement_bank_assignments.cash_account_id', $accountId);
        }

        return $query;
    }

    private function summary(Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'income' => (float) (clone $query)->sum('income'),
            'expense' => (float) (clone $query)->sum('expense'),
            'net' => (float) (clone $query)->sum(DB::raw('income - expense')),
        ];
    }
}
