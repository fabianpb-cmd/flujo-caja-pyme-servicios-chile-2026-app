<?php

namespace App\Models;

use App\Models\Concerns\HasFunctionalCode;
use App\Models\Concerns\GuardsSensitiveAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use GuardsSensitiveAttributes;
    use HasFunctionalCode;

    protected $guarded = ['code', 'status'];

    public function settings(): HasMany
    {
        return $this->hasMany(CompanySetting::class);
    }
}
