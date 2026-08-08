<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scenario extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sales_factor' => 'decimal:4',
            'cost_factor' => 'decimal:4',
            'new_hires_monthly' => 'decimal:2',
            'tariff_variation' => 'decimal:4',
            'is_active' => 'boolean',
            'client_loss_flag' => 'boolean',
        ];
    }

    public function affectedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'affected_client_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }
}
