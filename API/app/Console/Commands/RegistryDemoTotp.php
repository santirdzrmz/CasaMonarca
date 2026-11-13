<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Console\Command;

class RegistryDemoTotp extends Command
{
    protected $signature = 'registry:demo-totp {email}';

    protected $description = 'SOLO LOCAL: muestra el código TOTP vigente de un usuario de demostración (para no usar una app de autenticación)';

    public function handle(TotpService $totp): int
    {
        if (! app()->environment('local')) {
            $this->error('registry:demo-totp solo corre con APP_ENV=local.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null || ! is_string($user->two_factor_secret) || $user->two_factor_secret === '') {
            $this->error('El usuario no existe o no tiene TOTP configurado.');

            return self::FAILURE;
        }

        $this->info($totp->currentCode($user->two_factor_secret).'  (cambia cada 30 segundos)');

        return self::SUCCESS;
    }
}
