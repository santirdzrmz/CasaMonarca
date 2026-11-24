<?php

namespace App\Services\Security\Encryption;

/**
 * Names of the cryptographic operations, written into audit events so the log says exactly which primitive was
 * used at each step (key generation, Diffie-Hellman key agreement, signatures). All come from libsodium.
 */
final class CryptoAlgorithms
{
    /** Key pair for encryption: Curve25519 (X25519) for Diffie-Hellman. */
    public const KEY_AGREEMENT_KEYPAIR = 'X25519';

    /** Key pair for signatures. */
    public const SIGNING_KEYPAIR = 'Ed25519';

    /**
     * crypto_box_seal: the sender creates an EPHEMERAL X25519 key pair, runs X25519 Diffie-Hellman against the
     * recipient's public key, derives the key with HSalsa20 and encrypts with XSalsa20-Poly1305. The ephemeral
     * public key (32 bytes) travels at the start of the ciphertext; its secret half is discarded right away.
     */
    public const SEALED_BOX = 'crypto_box_seal (X25519 ECDH efímero + XSalsa20-Poly1305)';

    public const SIGNATURE = 'Ed25519 (crypto_sign_detached)';

    public const KEY_FILE = 'Argon2id13 + XSalsa20-Poly1305 (crypto_secretbox)';

    public const PAYLOAD = 'AES-256-GCM';

    /** Ciphertext size of a sealed box: 32-byte ephemeral public key + 16-byte Poly1305 tag + the message. */
    public const SEALED_BOX_OVERHEAD = SODIUM_CRYPTO_BOX_SEALBYTES;
}
