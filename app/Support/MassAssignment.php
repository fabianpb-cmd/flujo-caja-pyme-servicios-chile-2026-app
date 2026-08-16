<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

class MassAssignment
{
    public static function create(string $modelClass, array $attributes): Model
    {
        /** @var Model $model */
        $model = new $modelClass();
        [$massAssignable, $guarded] = self::split($model, $attributes);

        $model->fill($massAssignable);

        if ($guarded !== []) {
            $model->forceFill($guarded);
        }

        $model->save();

        return $model;
    }

    public static function fillAndSave(Model $model, array $attributes): Model
    {
        [$massAssignable, $guarded] = self::split($model, $attributes);

        $model->fill($massAssignable);

        if ($guarded !== []) {
            $model->forceFill($guarded);
        }

        $model->save();

        return $model;
    }

    public static function split(Model $model, array $attributes): array
    {
        if (method_exists($model, 'massAssignablePayload') && method_exists($model, 'guardedPayload')) {
            return [$model->massAssignablePayload($attributes), $model->guardedPayload($attributes)];
        }

        $guarded = array_values(array_filter(
            $model->getGuarded(),
            static fn ($key): bool => is_string($key) && $key !== '*'
        ));

        if ($guarded === []) {
            return [$attributes, []];
        }

        $protected = [];
        $fillable = $attributes;

        foreach ($guarded as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $protected[$key] = $attributes[$key];
            unset($fillable[$key]);
        }

        return [$fillable, $protected];
    }
}
