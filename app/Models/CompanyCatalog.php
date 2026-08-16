<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasFunctionalCode;
use Illuminate\Database\Eloquent\Model;

abstract class CompanyCatalog extends Model
{
    use BelongsToCompany;
    use HasFunctionalCode;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
