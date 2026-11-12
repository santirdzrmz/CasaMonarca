<?php

namespace App\Services\Security\Encryption;

/**
 * Data keys (DEKs) of specific records that a user was explicitly granted access to (temporary, signed grants).
 * Request-scoped like the KeyRing: it is filled by the LoadRegistryKeys middleware and wiped when the request ends.
 */
final class DekRing
{
    /** @var array<string, string> */
    private array $deks = [];

    /** @var array<string, array{type: string, id: string}> */
    private array $used = [];

    public function grant(string $resourceType, int|string $resourceId, string $purpose, string $dek): void
    {
        $this->deks[self::key($resourceType, $resourceId, $purpose)] = $dek;
    }

    public function dek(string $resourceType, int|string $resourceId, string $purpose): ?string
    {
        $key = self::key($resourceType, $resourceId, $purpose);

        if (! isset($this->deks[$key])) {
            return null;
        }

        $this->used[$resourceType.'#'.$resourceId] = ['type' => $resourceType, 'id' => (string) $resourceId];

        return $this->deks[$key];
    }

    /** @return list<array{type: string, id: string}> records whose granted key was actually used in this request */
    public function usedResources(): array
    {
        return array_values($this->used);
    }

    public function isEmpty(): bool
    {
        return $this->deks === [];
    }

    public function clear(): void
    {
        foreach ($this->deks as $key => $dek) {
            sodium_memzero($dek);
            unset($this->deks[$key]);
        }

        $this->used = [];
    }

    private static function key(string $resourceType, int|string $resourceId, string $purpose): string
    {
        return $resourceType.'|'.$resourceId.'|'.$purpose;
    }
}
