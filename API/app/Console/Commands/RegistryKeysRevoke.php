<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RegistryKeysRevoke extends Command
{
    protected $signature = 'registry:keys-revoke {email}';

    protected $description = 'Revoca la llave y las entregas de un usuario (no puede desbloquear nuevas sesiones)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No existe ese usuario.');

            return self::FAILURE;
        }

        DB::table('registry_user_keys')->where('user_id', $user->getKey())->update(['revoked_at' => now()]);
        $grants = DB::table('registry_key_grants')->where('user_id', $user->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $this->info("Revocadas $grants entregas de {$user->email}.");
        $this->warn('Si su archivo de llave pudo ser copiado, rota las llaves de dominio: registry:keys-init --generation=N+1 y vuelve a sellar los registros.');

        return self::SUCCESS;
    }
}
