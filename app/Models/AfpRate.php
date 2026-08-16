<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AfpRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'employee_commission_rate' => 'decimal:6',
            'employer_commission_rate' => 'decimal:6',
            'insurance_rate' => 'decimal:6',
            'active' => 'boolean',
        ];
    }

    public function afp(): BelongsTo
    {
        return $this->belongsTo(Afp::class);
    }
}
