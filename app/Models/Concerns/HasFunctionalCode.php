<?php

namespace App\Models\Concerns;

use App\Services\CodeGeneratorService;
use Illuminate\Database\Eloquent\Model;

trait HasFunctionalCode
{
    protected bool $functionalCodeAuto = true;

    public function functionalCodeAuto(): bool
    {
        return $this->functionalCodeAuto;
    }

    protected static function bootHasFunctionalCode(): void
    {
        static::creating(function (Model $model): void {
            $service = app(CodeGeneratorService::class);

            if (! $service->autoManaged($model) || filled($model->code)) {
                return;
            }

            $model->code = $service->temporaryCode($model);
        });

        static::created(function (Model $model): void {
            $service = app(CodeGeneratorService::class);

            if (! $service->autoManaged($model)) {
                return;
            }

            $currentCode = (string) $model->code;
            if ($currentCode !== '' && ! $service->isTemporary($currentCode)) {
                return;
            }

            $finalCode = $service->finalCode($model);
            if ($model->code !== $finalCode) {
                $model->forceFill(['code' => $finalCode])->saveQuietly();
            }
        });

        static::updating(function (Model $model): void {
            $service = app(CodeGeneratorService::class);

            if (! $service->autoManaged($model) || ! $model->isDirty('code')) {
                return;
            }

            $model->setAttribute('code', $model->getOriginal('code'));
        });
    }
}
