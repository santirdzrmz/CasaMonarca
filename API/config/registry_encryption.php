<?php

return [
    /*
    | Application-layer envelope encryption for registration and ARCO payloads.
    | See docs/operations/data-at-rest-encryption.md (Phase 2).
    |
    | enabled          Write ciphertext for new and changed payloads.
    | write_plaintext  Dual-write window: keep the plaintext column populated.
    |                  Set to false only after backfill, parity tests and an
    |                  approved rollback window.
    | key_directory    Directory outside the repository and web root holding one
    |                  base64 256-bit key file per KEK id (`<kek_id>.key`, mode 600).
    |                  Temporary fallback until AWS KMS is provisioned.
    */
    'enabled' => (bool) env('REGISTRY_ENCRYPTION_ENABLED', false),
    'write_plaintext' => (bool) env('REGISTRY_ENCRYPTION_WRITE_PLAINTEXT', true),
    'active_kek_id' => env('REGISTRY_KEK_ID'),
    'key_directory' => env('REGISTRY_KEY_DIRECTORY'),
    'context_environment' => env('REGISTRY_ENCRYPTION_ENV', env('APP_ENV', 'production')),
];
