<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\Encryption\KeyFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RegistryKeysEnroll extends Command
{
    protected $signature = 'registry:keys-enroll {email} {--out= : Archivo de llave que se entrega al usuario}';

    protected $description = 'Crea el par de llaves personal de un coordinador o administrador y registra su llave pública';

    public function handle(): int
    {
        $out = (string) $this->option('out');
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($out === '' || $user === null) {
            $this->error($out === '' ? 'Indica --out con la ruta del archivo de llave.' : 'No existe ese usuario.');

            return self::FAILURE;
        }

        if (! in_array($user->role?->value, ['admin', 'coordinator'], true)) {
            $this->error('Solo coordinadores y administradores tienen llaves. El operador usa accesos temporales por registro.');

            return self::FAILURE;
        }

        $passphrase = (string) $this->secret('Contraseña del archivo de llave (la elige el usuario, mínimo 12 caracteres)');

        if (strlen($passphrase) < 12 || $passphrase !== (string) $this->secret('Repite la contraseña')) {
            $this->error('La contraseña es muy corta o no coincide.');

            return self::FAILURE;
        }

        $keyFile = KeyFile::create($passphrase);
        file_put_contents($out, $keyFile['file']);

        DB::table('registry_user_keys')->updateOrInsert(
            ['user_id' => $user->getKey()],
            [
                'public_key' => base64_encode($keyFile['public']),
                'signing_public_key' => base64_encode($keyFile['signing_public']),
                'fingerprint' => $keyFile['fingerprint'],
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->info("Archivo de llave creado: $out (entrégalo al usuario; no se vuelve a generar).");
        $this->line('Huella de sus llaves públicas (cifrado y firma): '.$keyFile['fingerprint']);
        $this->line('Confírmala con el usuario por otro canal (voz o en persona) antes de ejecutar registry:keys-grant.');

        return self::SUCCESS;
    }
}
