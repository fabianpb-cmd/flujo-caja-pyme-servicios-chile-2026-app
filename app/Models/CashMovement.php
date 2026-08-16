<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = ['company_id', 'code', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'income' => 'decimal:2',
            'expense' => 'decimal:2',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function movementType(): BelongsTo
    {
        return $this->belongsTo(CashMovementType::class);
    }
}
