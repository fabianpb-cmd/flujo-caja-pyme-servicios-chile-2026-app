<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CompanyOwnedPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->company_id && $user->active;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    private function owns(User $user, Model $model): bool
    {
        return $this->viewAny($user)
            && (! isset($model->company_id) || (int) $model->company_id === (int) $user->company_id);
    }
}
