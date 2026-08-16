<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseDocument extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = ['company_id', 'code', 'vat_rate', 'vat_amount', 'recoverable_vat_amount', 'gross_amount', 'payment_status', 'paid_amount'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'projected_payment_date' => 'date',
            'actual_payment_date' => 'date',
            'net_amount' => 'decimal:2',
            'vat_rate' => 'decimal:6',
            'vat_amount' => 'decimal:2',
            'recoverable_vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'tax_deductible' => 'boolean',
            'deductible_vat' => 'boolean',
        ];
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function expenseSubcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class);
    }
}
