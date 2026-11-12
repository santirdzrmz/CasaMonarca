<?php

namespace App\Console\Commands;

use App\Services\Security\Encryption\AgeCli;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\KeyGrants;
use Illuminate\Console\Command;

class RegistryKeysInit extends Command
{
    protected $signature = 'registry:keys-init
        {--private-dir= : Carpeta, en la computadora del administrador, donde se guardan las llaves privadas}
        {--age-recipient= : Destinatario age (por ejemplo el de la YubiKey) con el que se cifran las privadas}
        {--plain : SOLO DESARROLLO: guardar las privadas en claro}
        {--generation=1 : Versión de las llaves (pk:cand-N y pk:proc-N)}';

    protected $description = 'Genera los pares de llaves por dominio (candidatos y procesados) para REGISTRY_ENCRYPTION_MODE=publickey';

    public function handle(): int
    {
        $dir = (string) $this->option('private-dir');
        $recipient = (string) $this->option('age-recipient');
        $generation = max(1, (int) $this->option('generation'));

        if ($dir === '' || (! $this->option('plain') && $recipient === '')) {
            $this->error('Indica --private-dir y, o bien --age-recipient (recomendado, YubiKey), o bien --plain (solo desarrollo).');

            return self::FAILURE;
        }

        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            $this->error("No se pudo crear $dir");

            return self::FAILURE;
        }

        $env = [];

        foreach (['cand', 'proc'] as $domain) {
            $id = "pk:{$domain}-{$generation}";
            $pair = sodium_crypto_box_keypair();
            $secret = base64_encode(sodium_crypto_box_secretkey($pair));

            try {
                $recipient !== ''
                    ? file_put_contents($dir.DIRECTORY_SEPARATOR.KeyGrants::privateFileName($id, 'age'), AgeCli::encrypt($secret, $recipient))
                    : file_put_contents($dir.DIRECTORY_SEPARATOR.KeyGrants::privateFileName($id, 'key'), $secret);
            } catch (EncryptionException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            sodium_memzero($secret);

            $upper = strtoupper($domain);
            $env[] = "REGISTRY_PK_{$upper}_ID=$id";
            $env[] = "REGISTRY_PK_{$upper}_PUBLIC=".base64_encode(sodium_crypto_box_publickey($pair));
        }

        $this->info("Llaves privadas guardadas en $dir. NO las copies al servidor ni al repositorio.");
        $this->line('Copia SOLO estas líneas (llaves públicas) al .env del servidor:');
        $this->newLine();

        foreach ($env as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->line('Después activa REGISTRY_ENCRYPTION_MODE=publickey y REGISTRY_ENCRYPTION_ENABLED=true.');

        return self::SUCCESS;
    }
}
