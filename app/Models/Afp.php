<?php

namespace App\Models;

use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Afp extends Model
{
    use HasFunctionalCode;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(AfpRate::class);
    }
}
