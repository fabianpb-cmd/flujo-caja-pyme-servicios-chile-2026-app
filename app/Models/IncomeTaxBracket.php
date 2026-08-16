<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomeTaxBracket extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'factor' => 'decimal:6',
            'rebate_amount' => 'decimal:2',
            'effective_max_rate' => 'decimal:4',
            'active' => 'boolean',
        ];
    }
}
