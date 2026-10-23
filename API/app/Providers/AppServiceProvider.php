<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Debug pages expose environment values, stack traces and request data.
        if ($this->app->isProduction() && config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false when APP_ENV=production.');
        }
    }
}
