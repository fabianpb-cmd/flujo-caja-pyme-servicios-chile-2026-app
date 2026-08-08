<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $guarded = [];

    public function settings(): HasMany
    {
        return $this->hasMany(CompanySetting::class);
    }
}
