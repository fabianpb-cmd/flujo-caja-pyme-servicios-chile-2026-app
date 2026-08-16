<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GuardsSensitiveAttributes;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    use BelongsToCompany;
    use GuardsSensitiveAttributes;

    protected $guarded = ['company_id'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'active' => 'boolean'];
    }
}
