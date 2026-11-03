<?php

namespace App\Services\Security\Encryption;

use RuntimeException;

/** Fail-closed error: callers must never fall back to plaintext on this. */
class EncryptionException extends RuntimeException {}
