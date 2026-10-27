<?php

namespace App\Providers;

use App\Services\GeminiClient;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GeminiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
