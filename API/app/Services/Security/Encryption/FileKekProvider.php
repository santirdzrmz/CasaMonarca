<?php

namespace App\Services\Security\Encryption;

final class FileKekProvider implements KekProvider
{
    private const ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /** @var array<string, string> */
    private array $keys = [];

    public function __construct(
        private readonly ?string $directory,
        private readonly ?string $activeId,
    ) {}

    public function activeKekId(): string
    {
        if ($this->activeId === null || ! preg_match(self::ID_PATTERN, $this->activeId)) {
            throw new EncryptionException('REGISTRY_KEK_ID is missing or invalid.');
        }

        return $this->activeId;
    }

    public function wrap(string $dek, string $aad): string
    {
        return Aes256Gcm::seal($this->key($this->activeKekId()), $dek, $aad);
    }

    public function unwrap(string $kekId, string $wrapped, string $aad): string
    {
        return Aes256Gcm::open($this->key($kekId), $wrapped, $aad);
    }

    public static function generateKeyMaterial(): string
    {
        return base64_encode(random_bytes(32));
    }

    private function key(string $id): string
    {
        if (isset($this->keys[$id])) {
            return $this->keys[$id];
        }

        if ($this->directory === null || $this->directory === '' || ! preg_match(self::ID_PATTERN, $id)) {
            throw new EncryptionException('Registry key directory or key id is not configured.');
        }

        $path = rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.$id.'.key';

        if (! is_file($path) || ! is_readable($path)) {
            throw new EncryptionException(sprintf('KEK [%s] is not available.', $id));
        }

        if (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077) !== 0) {
            throw new EncryptionException(sprintf('KEK file for [%s] must not be accessible by group or others (mode 600).', $id));
        }

        $key = base64_decode(trim((string) file_get_contents($path)), true);

        if ($key === false || strlen($key) !== 32) {
            throw new EncryptionException(sprintf('KEK [%s] must be a base64-encoded 256-bit key.', $id));
        }

        return $this->keys[$id] = $key;
    }
}
