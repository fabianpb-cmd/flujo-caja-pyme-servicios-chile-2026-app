<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalObligation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

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
