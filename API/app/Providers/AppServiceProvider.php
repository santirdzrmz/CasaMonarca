<?php

namespace App\Providers;

use App\Services\Security\Encryption\DekRing;
use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\FileKekProvider;
use App\Services\Security\Encryption\KekProvider;
use App\Services\Security\Encryption\KeyRing;
use App\Services\Security\Encryption\PublicKeyKekProvider;
use App\Services\Security\Encryption\RoutingKekProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KeyRing::class);
        $this->app->singleton(DekRing::class);

        $this->app->singleton(KekProvider::class, function ($app): KekProvider {
            $file = new FileKekProvider(
                config('registry_encryption.key_directory'),
                config('registry_encryption.active_kek_id'),
            );

            if (config('registry_encryption.mode') !== 'publickey') {
                return $file;
            }

            $keys = [];
            foreach ((array) config('registry_encryption.public_keys') as $domain => $key) {
                $keys[$domain] = ['id' => (string) $key['id'], 'public' => (string) base64_decode((string) $key['public'], true)];
            }

            return new RoutingKekProvider(new PublicKeyKekProvider($keys, $app->make(KeyRing::class)), $file);
        });

        $this->app->singleton(EnvelopeCipher::class, fn ($app): EnvelopeCipher => new EnvelopeCipher(
            $app->make(KekProvider::class),
            (string) config('registry_encryption.context_environment'),
            $app->make(DekRing::class),
        ));
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
