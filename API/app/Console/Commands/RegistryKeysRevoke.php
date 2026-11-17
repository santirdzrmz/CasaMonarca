<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\Encryption\RegistryUserKeys;
use Illuminate\Console\Command;

class RegistryKeysRevoke extends Command
{
    protected $signature = 'registry:keys-revoke {email}';

    protected $description = 'Revoca la llave y las entregas de un usuario (no puede desbloquear nuevas sesiones)';

    public function handle(RegistryUserKeys $keys): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No existe ese usuario.');

            return self::FAILURE;
        }

        $grants = $keys->revoke($user)['revokedGrants'];

        $this->info("Revocadas $grants entregas de {$user->email}.");
        $this->warn('Si su archivo de llave pudo ser copiado, rota las llaves de dominio: registry:keys-init --generation=N+1 y vuelve a sellar los registros.');

        return self::SUCCESS;
    }
}
