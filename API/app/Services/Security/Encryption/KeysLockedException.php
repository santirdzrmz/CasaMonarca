<?php

namespace App\Services\Security\Encryption;

/** The required key exists but is not unlocked in this session. The API answers 423 (Locked). */
final class KeysLockedException extends EncryptionException {}
