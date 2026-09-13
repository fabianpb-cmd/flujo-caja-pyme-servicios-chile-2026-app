<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BankStatementLine extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;

    protected $guarded = ['company_id', 'bank_statement_import_id', 'cash_account_id', 'row_hash'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'value_date' => 'date',
            'amount' => 'decimal:2',
            'ignored_at' => 'datetime',
        ];
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->where('status', 'unmatched');
    }

    public function bankStatementImport(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function activeMatch(): HasOne
    {
        return $this->hasOne(BankStatementMatch::class)->where('status', 'active');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankStatementMatch::class);
    }

    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by_user_id');
    }
}
