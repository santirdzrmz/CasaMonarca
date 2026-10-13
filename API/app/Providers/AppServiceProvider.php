<?php

namespace App\Providers;

use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\FileKekProvider;
use App\Services\Security\Encryption\KekProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KekProvider::class, fn (): KekProvider => new FileKekProvider(
            config('registry_encryption.key_directory'),
            config('registry_encryption.active_kek_id'),
        ));

        $this->app->singleton(EnvelopeCipher::class, fn ($app): EnvelopeCipher => new EnvelopeCipher(
            $app->make(KekProvider::class),
            (string) config('registry_encryption.context_environment'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
