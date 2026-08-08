<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'pay_period' => 'date',
            'hours_worked' => 'decimal:2',
            'hours_approved' => 'decimal:2',
            'hourly_value' => 'decimal:2',
            'calculated_amount' => 'decimal:2',
        ];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function activityCatalog(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }

    public function approvalStatus(): BelongsTo
    {
        return $this->belongsTo(ApprovalStatus::class);
    }
}
