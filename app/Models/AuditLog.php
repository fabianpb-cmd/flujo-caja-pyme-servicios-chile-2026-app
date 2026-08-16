<?php

namespace App\Models;

use App\Models\Concerns\GuardsSensitiveAttributes;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use GuardsSensitiveAttributes;

    protected $guarded = ['company_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'before_data', 'after_data', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
        ];
    }
}
