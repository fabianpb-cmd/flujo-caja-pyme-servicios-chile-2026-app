<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalObligation extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = ['company_id', 'code', 'paid_amount', 'pending_amount', 'status'];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'due_date' => 'date',
            'payment_date' => 'date',
            'estimated_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'pending_amount' => 'decimal:2',
            'vat_carryforward_amount' => 'decimal:2',
        ];
    }

    public function obligationType(): BelongsTo
    {
        return $this->belongsTo(ObligationType::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(LegalOrganization::class);
    }
}
