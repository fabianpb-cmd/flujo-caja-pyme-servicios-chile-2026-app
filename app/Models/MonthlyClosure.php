<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use Illuminate\Database\Eloquent\Model;

class MonthlyClosure extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;

    protected $guarded = ['company_id', 'status', 'opening_balance', 'closing_balance', 'cash_in', 'cash_out', 'accounts_receivable', 'accounts_payable', 'closed_at'];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'cash_in' => 'decimal:2',
            'cash_out' => 'decimal:2',
            'accounts_receivable' => 'decimal:2',
            'accounts_payable' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }
}
