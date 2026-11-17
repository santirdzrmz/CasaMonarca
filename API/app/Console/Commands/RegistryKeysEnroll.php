<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\KeyFile;
use App\Services\Security\Encryption\RegistryUserKeys;
use Illuminate\Console\Command;

class RegistryKeysEnroll extends Command
{
    protected $signature = 'registry:keys-enroll {email} {--out= : Archivo de llave que se entrega al usuario}
        {--replace : Reemplaza la llave activa del usuario (la anterior queda en el historial y sus entregas se revocan)}';

    protected $description = 'Crea el par de llaves personal de un coordinador o administrador y registra su llave pública';

    public function handle(RegistryUserKeys $keys): int
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

        $active = $keys->active($user);

        if ($active !== null && ! $this->option('replace')) {
            $this->error("El usuario ya tiene una llave activa (huella {$active->fingerprint}). Usa --replace para reemplazarla; la anterior se conserva en el historial.");

            return self::FAILURE;
        }

        $passphrase = (string) $this->secret('Contraseña del archivo de llave (la elige el usuario, mínimo 12 caracteres)');

        if (strlen($passphrase) < 12 || $passphrase !== (string) $this->secret('Repite la contraseña')) {
            $this->error('La contraseña es muy corta o no coincide.');

            return self::FAILURE;
        }

        $keyFile = KeyFile::create($passphrase);

        try {
            $result = $keys->enroll($user, $keyFile, 'registry:keys-enroll', (bool) $this->option('replace'));
        } catch (EncryptionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        file_put_contents($out, $keyFile['file']);

        if ($result['replaced'] !== null) {
            $this->warn("Llave anterior ({$result['replaced']}) retirada; se revocaron {$result['revokedGrants']} entregas. Vuelve a entregar con registry:keys-grant.");
        }

        $this->info("Archivo de llave creado: $out (entrégalo al usuario; no se vuelve a generar).");
        $this->line('Huella de sus llaves públicas (cifrado y firma): '.$keyFile['fingerprint']);
        $this->line('Confírmala con el usuario por otro canal (voz o en persona) antes de ejecutar registry:keys-grant.');

        return self::SUCCESS;
    }
}
