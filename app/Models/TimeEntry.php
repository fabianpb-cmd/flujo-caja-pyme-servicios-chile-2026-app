<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use App\Services\HourlyRateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TimeEntry extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = ['company_id', 'code', 'client_id', 'assignment_id', 'hourly_value', 'calculated_amount'];

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

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'assignment_id');
    }

    public function salesDocuments(): BelongsToMany
    {
        return $this->belongsToMany(SalesDocument::class, 'sales_document_time_entries')
            ->withPivot(['hours_approved', 'hourly_rate_amount', 'rate_unit_type', 'subtotal_clp'])
            ->withTimestamps();
    }

    public function getHourlyRateDisplayCurrencyAttribute(): mixed
    {
        $resolution = app(HourlyRateService::class)->resolveForEntry($this);

        return $resolution['currency'] ?? 'CLP';
    }

    public function getHourlyRateSourceLabelAttribute(): ?string
    {
        return app(HourlyRateService::class)->resolveForEntry($this)['source_label'] ?? null;
    }

    public function getHourlyRateSourceTypeAttribute(): ?string
    {
        return app(HourlyRateService::class)->resolveForEntry($this)['source_type'] ?? null;
    }

    public function getHourlyRateResolvedAmountAttribute(): mixed
    {
        return app(HourlyRateService::class)->resolveForEntry($this)['amount'] ?? null;
    }
}
