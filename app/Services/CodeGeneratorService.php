<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CodeGeneratorService
{
    public function autoManaged(Model $model): bool
    {
        return method_exists($model, 'functionalCodeAuto')
            ? (bool) $model->functionalCodeAuto()
            : false;
    }

    public function temporaryCode(Model $model): string
    {
        return 'TMP-'.Str::upper((string) Str::ulid());
    }

    public function finalCode(Model $model): string
    {
        $base = sprintf('%s-%s', $this->prefixFor($model), $this->suffixFor($model));
        $candidate = $base;
        $attempt = 1;

        while ($this->codeExists($model, $candidate)) {
            $candidate = sprintf('%s-%d', $base, $attempt++);
        }

        return $candidate;
    }

    public function isTemporary(string $code): bool
    {
        return str_starts_with($code, 'TMP-');
    }

    private function prefixFor(Model $model): string
    {
        return match (true) {
            $model instanceof \App\Models\Client => 'CLI',
            $model instanceof \App\Models\Project => 'PRY',
            $model instanceof \App\Models\Person => 'PER',
            $model instanceof \App\Models\ProjectAssignment => 'ASI',
            $model instanceof \App\Models\TimeEntry => 'HOR',
            $model instanceof \App\Models\PayrollRecord => 'REM',
            $model instanceof \App\Models\SalesDocument => 'ING',
            $model instanceof \App\Models\ExpenseDocument => 'EGR',
            $model instanceof \App\Models\LegalObligation => 'OBL',
            $model instanceof \App\Models\CashMovement => 'MOV',
            $model instanceof \App\Models\CashAccount => 'CTA',
            $model instanceof \App\Models\Company => 'CMP',
            $model instanceof \App\Models\Afp => 'AFP',
            $model instanceof \App\Models\ProjectManager => 'RES',
            $model instanceof \App\Models\CostCenter => 'CCO',
            $model instanceof \App\Models\Position => 'CAR',
            $model instanceof \App\Models\EmploymentMode => 'MOD',
            $model instanceof \App\Models\ContractType => 'TCT',
            $model instanceof \App\Models\Bank => 'BAN',
            $model instanceof \App\Models\BankAccountType => 'TCTA',
            $model instanceof \App\Models\PaymentMethod => 'MPA',
            $model instanceof \App\Models\PaymentTerm => 'PZO',
            $model instanceof \App\Models\ClientType => 'TCL',
            $model instanceof \App\Models\ProjectType => 'TPR',
            $model instanceof \App\Models\Activity => 'ACT',
            $model instanceof \App\Models\ApprovalStatus => 'APR',
            $model instanceof \App\Models\ExpenseCategory => 'CAT',
            $model instanceof \App\Models\ExpenseSubcategory => 'SBC',
            $model instanceof \App\Models\DocumentType => 'TDO',
            $model instanceof \App\Models\ObligationType => 'TOB',
            $model instanceof \App\Models\RecordStatus => 'EST',
            $model instanceof \App\Models\ExpenseType => 'TGE',
            $model instanceof \App\Models\CashMovementType => 'TMT',
            $model instanceof \App\Models\HealthSystem => 'SAL',
            $model instanceof \App\Models\LegalOrganization => 'ORG',
            $model instanceof \App\Models\OccupationalInsuranceEntity => 'LEY16',
            default => Str::upper(Str::substr(class_basename($model), 0, 3)) ?: 'COD',
        };
    }

    private function suffixFor(Model $model): string
    {
        $key = $model->getKey();

        return $key ? sprintf('%06d', (int) $key) : Str::upper((string) Str::ulid());
    }

    private function codeExists(Model $model, string $code): bool
    {
        return $model::query()
            ->where('code', $code)
            ->when($model->getKey(), fn ($query) => $query->whereKeyNot($model->getKey()))
            ->exists();
    }
}
