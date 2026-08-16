<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDocumentTimeEntry extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;

    protected $guarded = ['company_id', 'snapshot', 'subtotal_original', 'conversion_rate', 'conversion_date', 'subtotal_clp'];

    protected function casts(): array
    {
        return [
            'hours_approved' => 'decimal:4',
            'hourly_rate_amount' => 'decimal:6',
            'subtotal_original' => 'decimal:6',
            'conversion_rate' => 'decimal:6',
            'conversion_date' => 'date',
            'subtotal_clp' => 'decimal:2',
            'snapshot' => 'array',
        ];
    }

    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }
}
