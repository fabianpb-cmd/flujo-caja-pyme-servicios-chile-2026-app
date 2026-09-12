<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    use BelongsToCompany;

    protected $guarded = ['company_id'];

    protected function casts(): array
    {
        return [
            'reconciliation_date' => 'date',
            'bank_balance' => 'decimal:2',
            'system_balance_snapshot' => 'decimal:2',
            'difference' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }
}
