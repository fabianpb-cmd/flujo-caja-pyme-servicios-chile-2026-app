<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\MassAssignment;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(string $action, Model $model, ?User $user = null, ?array $before = null, ?array $after = null): void
    {
        MassAssignment::create(AuditLog::class, [
            'company_id' => $model->company_id ?? $user?->company_id,
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'before_data' => $before,
            'after_data' => $after ?? $model->toArray(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
