<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PayrollRecord extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = [
        'company_id',
        'code',
        'status',
        'calculation_status',
        'calculation_notes',
        'base_salary',
        'gross_amount',
        'taxable_amount',
        'taxable_gross',
        'pension_health_base',
        'afc_base',
        'uf_value',
        'pension_cap_uf',
        'afc_cap_uf',
        'employee_retention',
        'retention_rate',
        'afp_mandatory',
        'afp_commission_rate',
        'afp_commission',
        'health_legal',
        'health_employee',
        'afc_employee_rate',
        'afc_employee',
        'afc_employer_rate',
        'afc_employer',
        'employer_pension_rate',
        'employer_pension',
        'accident_insurance_rate',
        'accident_insurance',
        'sanna_rate',
        'sanna',
        'iusc_taxable_base',
        'iusc_factor',
        'iusc_rebate',
        'iusc_amount',
        'employer_cost',
        'vacation_provision',
        'vacation_days_accrued_period',
        'vacation_daily_value',
        'vacation_provision_amount',
        'net_pay',
        'legal_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'payment_date' => 'date',
            'monthly_value' => 'decimal:2',
            'hourly_value' => 'decimal:2',
            'project_value' => 'decimal:2',
            'hours_approved' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'bonuses' => 'decimal:2',
            'non_taxable_allowances' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'taxable_gross' => 'decimal:2',
            'pension_health_base' => 'decimal:2',
            'afc_base' => 'decimal:2',
            'uf_value' => 'decimal:4',
            'pension_cap_uf' => 'decimal:4',
            'afc_cap_uf' => 'decimal:4',
            'employee_retention' => 'decimal:2',
            'retention_rate' => 'decimal:6',
            'afp_mandatory' => 'decimal:2',
            'afp_commission_rate' => 'decimal:6',
            'afp_commission' => 'decimal:2',
            'health_legal' => 'decimal:2',
            'health_additional' => 'decimal:2',
            'health_employee' => 'decimal:2',
            'afc_employee_rate' => 'decimal:6',
            'afc_employee' => 'decimal:2',
            'afc_employer_rate' => 'decimal:6',
            'afc_employer' => 'decimal:2',
            'employer_pension_rate' => 'decimal:6',
            'employer_pension' => 'decimal:2',
            'accident_insurance_rate' => 'decimal:6',
            'accident_insurance' => 'decimal:2',
            'sanna_rate' => 'decimal:6',
            'sanna' => 'decimal:2',
            'iusc_taxable_base' => 'decimal:2',
            'iusc_factor' => 'decimal:6',
            'iusc_rebate' => 'decimal:2',
            'iusc_amount' => 'decimal:2',
            'advances' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'employer_cost' => 'decimal:2',
            'vacation_provision' => 'decimal:2',
            'vacation_days_accrued_period' => 'decimal:4',
            'vacation_daily_value' => 'decimal:2',
            'vacation_provision_amount' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'legal_snapshot' => 'array',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function timeEntries(): BelongsToMany
    {
        return $this->belongsToMany(TimeEntry::class, 'payroll_record_time_entries')
            ->withTimestamps();
    }
}
