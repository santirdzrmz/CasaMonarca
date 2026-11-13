<?php

namespace App\Services\Security\Encryption;

/** Minimal wrapper around the `age` binary (for example with age-plugin-yubikey). Admin commands only. */
final class AgeCli
{
    public static function encrypt(string $plain, string $recipient): string
    {
        return self::run(['age', '-r', $recipient], $plain);
    }

    public static function decrypt(string $path, string $identityFile): string
    {
        return self::run(['age', '-d', '-i', $identityFile, $path], null);
    }

    /** @param list<string> $command */
    private static function run(array $command, ?string $stdin): string
    {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new EncryptionException('Could not run age. Is it installed and on the PATH?');
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new EncryptionException('age failed: '.trim((string) $err));
        }

        return (string) $out;
    }
}
