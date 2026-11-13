<?php

namespace App\Console\Commands;

use App\Models\MigrantArcoRequest;
use App\Models\MigrantRegistryEntry;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\KeyRing;
use App\Services\Security\Encryption\PrivateKeyDirectory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class RegistryEncryptBackfill extends Command
{
    protected $signature = 'registry:encrypt-backfill
        {--batch=100 : Rows per batch}
        {--dry-run : Count rows that need encryption without writing}
        {--purge-plaintext : After verifying, replace plaintext columns with placeholders (irreversible without a backup)}
        {--private-dir= : directory with the domain private keys, needed for the decrypt-and-compare check}
        {--age-identity= : age identity file to open .age private keys}';

    protected $description = 'Resumable backfill of registration and ARCO payloads into authenticated envelopes, with decrypt-and-compare verification';

    public function handle(): int
    {
        if (! config('registry_encryption.enabled')) {
            $this->error('Set REGISTRY_ENCRYPTION_ENABLED=true (with REGISTRY_PK_*_PUBLIC configured) before running the backfill.');

            return self::FAILURE;
        }

        $purge = (bool) $this->option('purge-plaintext');

        if ((string) $this->option('private-dir') === '') {
            $this->error('The backfill needs --private-dir (domain private keys) to verify each row after sealing it.');

            return self::FAILURE;
        }

        try {
            PrivateKeyDirectory::unlockInto(app(KeyRing::class), (string) $this->option('private-dir'), ((string) $this->option('age-identity')) ?: null);
        } catch (EncryptionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($purge && ! $this->option('dry-run') && ! $this->confirm('Plaintext will be replaced by placeholders. Confirm a fresh encrypted backup exists and the rollback window has ended.')) {
            return self::FAILURE;
        }

        $failures = 0;

        foreach ([MigrantRegistryEntry::class, MigrantArcoRequest::class] as $class) {
            $failures += $this->process($class, (int) $this->option('batch'), (bool) $this->option('dry-run'), $purge);
        }

        app(KeyRing::class)->lock();

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param class-string<Model> $class */
    private function process(string $class, int $batch, bool $dryRun, bool $purge): int
    {
        $sealed = $skipped = $failed = 0;
        $columns = array_keys((new $class)->getEncryptedPayloadColumns());

        $query = $class::query();
        if (method_exists($class, 'withTrashed')) {
            $query->withTrashed();
        }

        $query->chunkById(max(1, $batch), function ($rows) use (&$sealed, &$skipped, &$failed, $columns, $dryRun, $purge): void {
            foreach ($rows as $row) {
                try {
                    $needs = false;
                    foreach ($columns as $column) {
                        $needs = $needs || ($row->getRawOriginal($column) !== null && $row->getRawOriginal($column.'_enc') === null);
                    }

                    if (! $needs) {
                        $skipped++;
                    } elseif (! $dryRun) {
                        $expected = array_map(fn ($c) => $row->{$c}, $columns);
                        $row->sealEncryptedPayloads();
                        $fresh = $row->newQuery()->withoutGlobalScopes()->findOrFail($row->getKey());

                        foreach ($columns as $i => $column) {
                            if ($fresh->{$column} !== $expected[$i]) {
                                throw new EncryptionException("Round-trip mismatch on {$column}.");
                            }
                        }

                        $sealed++;
                    } else {
                        $sealed++;
                    }

                    if ($purge && ! $dryRun) {
                        $row->purgePlaintextPayloads();
                    }
                } catch (EncryptionException $e) {
                    $failed++;
                    $this->error(sprintf('%s #%s failed: %s', class_basename($row), $row->getKey(), $e->getMessage()));
                }
            }
        });

        $this->line(sprintf('%s: %s %d, already encrypted %d, failed %d', class_basename($class), $dryRun ? 'to encrypt' : 'encrypted', $sealed, $skipped, $failed));

        return $failed;
    }
}
