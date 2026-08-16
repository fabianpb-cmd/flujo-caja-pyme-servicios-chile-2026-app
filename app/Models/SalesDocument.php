<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDocument extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = [
        'company_id',
        'code',
        'vat_rate',
        'vat_amount',
        'gross_amount',
        'status',
        'collected_amount',
        'billing_source',
        'billing_snapshot',
        'calculation_status',
        'calculation_notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'projected_collection_date' => 'date',
            'scenario_collection_date' => 'date',
            'actual_collection_date' => 'date',
            'payment_probability' => 'decimal:6',
            'net_amount' => 'decimal:2',
            'vat_rate' => 'decimal:6',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'is_voided' => 'boolean',
            'billing_period_date' => 'date',
            'adjustment_amount' => 'decimal:2',
            'billing_snapshot' => 'array',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function timeEntryLinks(): HasMany
    {
        return $this->hasMany(SalesDocumentTimeEntry::class);
    }

    public function timeEntries(): BelongsToMany
    {
        return $this->belongsToMany(TimeEntry::class, 'sales_document_time_entries')
            ->withPivot(['hours_approved', 'hourly_rate_amount', 'rate_unit_type', 'subtotal_clp'])
            ->withTimestamps();
    }
}
