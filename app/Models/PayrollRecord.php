<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollRecord extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'payment_date' => 'date',
            'monthly_value' => 'decimal:2',
            'hourly_value' => 'decimal:2',
            'project_value' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'bonuses' => 'decimal:2',
            'non_taxable_allowances' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'employee_retention' => 'decimal:2',
            'employer_cost' => 'decimal:2',
            'vacation_provision' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }
}
