<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTerm extends CompanyCatalog
{
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'days' => 'integer',
        ]);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
