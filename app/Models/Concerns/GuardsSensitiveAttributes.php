<?php

namespace App\Models\Concerns;

use Illuminate\Support\Arr;

trait GuardsSensitiveAttributes
{
    protected function fillableFromArray(array $attributes)
    {
        $fillable = parent::fillableFromArray($attributes);

        if (! $this->allowsTrustedGuardedAssignment()) {
            return $fillable;
        }

        foreach ($this->guardedAttributeKeys() as $key) {
            if (array_key_exists($key, $attributes)) {
                $fillable[$key] = $attributes[$key];
            }
        }

        return $fillable;
    }

    public function isFillable($key)
    {
        if ($this->allowsTrustedGuardedAssignment() && in_array($key, $this->guardedAttributeKeys(), true)) {
            return true;
        }

        return parent::isFillable($key);
    }

    public function massAssignablePayload(array $attributes): array
    {
        return Arr::except($attributes, $this->guardedAttributeKeys());
    }

    public function guardedPayload(array $attributes): array
    {
        return Arr::only($attributes, $this->guardedAttributeKeys());
    }

    private function allowsTrustedGuardedAssignment(): bool
    {
        return ! app()->bound('mass_assignment.untrusted_request')
            || app('mass_assignment.untrusted_request') !== true;
    }

    private function guardedAttributeKeys(): array
    {
        return array_values(array_filter(
            $this->getGuarded(),
            static fn ($key): bool => is_string($key) && $key !== '*'
        ));
    }
}
