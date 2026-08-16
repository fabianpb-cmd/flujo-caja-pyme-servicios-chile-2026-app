<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAssignment extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = ['company_id', 'code'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'hourly_value' => 'decimal:2',
            'project_value' => 'decimal:2',
            'hourly_rate_currency_id' => 'integer',
        ];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignmentStatus(): BelongsTo
    {
        return $this->belongsTo(RecordStatus::class);
    }

    public function hourlyRateCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'hourly_rate_currency_id');
    }

    public function getHourlyRateDisplayCurrencyAttribute(): mixed
    {
        if ($this->hourly_rate_unit_type === 'UF') {
            return 'UF';
        }

        return $this->hourlyRateCurrency ?: 'CLP';
    }
}
