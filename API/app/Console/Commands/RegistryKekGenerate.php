<?php

namespace App\Console\Commands;

use App\Services\Security\Encryption\FileKekProvider;
use Illuminate\Console\Command;

class RegistryKekGenerate extends Command
{
    protected $signature = 'registry:kek-generate {id : KEK id, e.g. registry-2026-01}';

    protected $description = 'Create a 256-bit registry KEK file in REGISTRY_KEY_DIRECTORY (mode 600, never overwritten)';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $directory = config('registry_encryption.key_directory');

        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id)) {
            $this->error('Invalid KEK id.');

            return self::FAILURE;
        }

        if (! is_string($directory) || $directory === '' || ! is_dir($directory)) {
            $this->error('REGISTRY_KEY_DIRECTORY must point to an existing directory outside the repository and web root.');

            return self::FAILURE;
        }

        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$id.'.key';

        if (file_exists($path)) {
            $this->error('A key with this id already exists; refusing to overwrite.');

            return self::FAILURE;
        }

        $handle = fopen($path, 'x');
        chmod($path, 0600);
        fwrite($handle, FileKekProvider::generateKeyMaterial()."\n");
        fclose($handle);

        $this->info("KEK [{$id}] created. Back it up offline with two-person custody; losing it destroys the data it protects.");

        return self::SUCCESS;
    }
}
