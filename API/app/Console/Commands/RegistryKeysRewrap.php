<?php

namespace App\Console\Commands;

use App\Models\MigrantArcoRequest;
use App\Models\MigrantRegistryEntry;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\EnvelopeCipher;
use App\Services\Security\Encryption\KekDomain;
use App\Services\Security\Encryption\KeyRing;
use App\Services\Security\Encryption\PrivateKeyDirectory;
use App\Services\Security\Encryption\PublicKeyKekProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class RegistryKeysRewrap extends Command
{
    protected $signature = 'registry:keys-rewrap
        {--private-dir= : Carpeta con TODAS las llaves privadas (la generación vieja y la nueva)}
        {--age-identity= : Archivo de identidad age (YubiKey) para abrir las llaves .age}
        {--dry-run : Solo cuenta los sobres que cambiarían}';

    protected $description = 'Rotación de llaves: vuelve a envolver las DEK con la llave pública activa (registry:keys-init --generation=N+1) sin tocar el contenido cifrado';

    public function handle(): int
    {
        if (! config('registry_encryption.enabled') || (string) $this->option('private-dir') === '') {
            $this->error('Requiere REGISTRY_ENCRYPTION_ENABLED=true y --private-dir.');

            return self::FAILURE;
        }

        $ring = app(KeyRing::class);

        try {
            $loaded = PrivateKeyDirectory::unlockAll($ring, (string) $this->option('private-dir'), ((string) $this->option('age-identity')) ?: null);
        } catch (EncryptionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Llaves privadas abiertas: $loaded");
        $dryRun = (bool) $this->option('dry-run');
        $changed = $skipped = $failed = 0;

        foreach ([MigrantRegistryEntry::class, MigrantArcoRequest::class] as $class) {
            $this->process($class, $dryRun, $changed, $skipped, $failed);
        }

        $ring->lock();
        $this->info("Re-envueltos: $changed | sin cambios: $skipped | fallidos: $failed".($dryRun ? ' (simulación)' : ''));
        $this->line('Después de rotar, vuelve a entregar las llaves nuevas: registry:keys-grant para cada usuario.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param class-string<Model> $class */
    private function process(string $class, bool $dryRun, int &$changed, int &$skipped, int &$failed): void
    {
        $cipher = app(EnvelopeCipher::class);
        $query = $class::query();
        if (method_exists($class, 'withTrashed')) {
            $query->withTrashed();
        }

        $query->chunkById(100, function ($rows) use ($cipher, $dryRun, &$changed, &$skipped, &$failed): void {
            foreach ($rows as $row) {
                foreach ($row->getEncryptedPayloadColumns() as $column => [$purpose]) {
                    $envelope = $row->getRawOriginal($column.'_enc');
                    $kek = is_string($envelope) ? (json_decode($envelope, true)['kek'] ?? null) : null;
                    $domain = $row->encryptionKeyDomain($column);
                    $active = $domain !== null ? (string) config("registry_encryption.public_keys.$domain.id") : '';

                    if (! is_string($kek) || ! PublicKeyKekProvider::handles($kek) || $kek === $active) {
                        $skipped++;

                        continue;
                    }

                    try {
                        if (! $dryRun) {
                            $new = KekDomain::using($domain, fn (): string => $cipher->rewrap($envelope, $row->getTable(), $row->getKey(), $purpose));

                            if ($cipher->decrypt($new, $row->getTable(), $row->getKey(), $purpose) !== $cipher->decrypt($envelope, $row->getTable(), $row->getKey(), $purpose)) {
                                throw new EncryptionException('Round-trip mismatch.');
                            }

                            $row->newQuery()->withoutGlobalScopes()->whereKey($row->getKey())->toBase()->update([$column.'_enc' => $new]);
                        }

                        $changed++;
                    } catch (EncryptionException $e) {
                        $failed++;
                        $this->warn(sprintf('%s#%s.%s: %s', class_basename($row), $row->getKey(), $column, $e->getMessage()));
                    }
                }
            }
        });
    }
}
