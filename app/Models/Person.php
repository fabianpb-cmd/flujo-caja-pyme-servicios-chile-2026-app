<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Person extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'additional_health_plan' => 'decimal:2',
            'monthly_value' => 'decimal:2',
            'hourly_value' => 'decimal:2',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function employmentMode(): BelongsTo
    {
        return $this->belongsTo(EmploymentMode::class);
    }

    public function employmentContractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function afp(): BelongsTo
    {
        return $this->belongsTo(Afp::class);
    }

    public function workerStatus(): BelongsTo
    {
        return $this->belongsTo(RecordStatus::class);
    }

    public function healthSystemCatalog(): BelongsTo
    {
        return $this->belongsTo(HealthSystem::class, 'health_system_id');
    }
}
