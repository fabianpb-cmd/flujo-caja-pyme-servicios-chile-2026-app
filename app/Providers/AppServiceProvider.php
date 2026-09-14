<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Services\OpenAiResponsesProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProvider::class, OpenAiResponsesProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal() || $this->app->runningUnitTests());
    }
}
