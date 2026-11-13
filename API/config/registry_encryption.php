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

    /*
    | Per-user keys (public-key model). See docs/operations/registry-user-keys.md.
    |
    | mode             file: the symmetric file KEK (current behaviour).
    |                  publickey: each record's DEK is wrapped with a PUBLIC key per domain
    |                  (cand = before approval, proc = approved); opening it needs the user's private
    |                  key, delivered by an admin and unlocked in the user's session.
    | public_keys      Active public key per domain (base64). Private keys do NOT live on the server.
    | session_key_ttl  Seconds unlocked keys stay available in the session.
    | operator_session_ttl  Seconds an operator's session key (Ks) lives; grants are tied to it.
    */
    'mode' => env('REGISTRY_ENCRYPTION_MODE', 'file'),
    'public_keys' => [
        'cand' => ['id' => env('REGISTRY_PK_CAND_ID', 'pk:cand-1'), 'public' => env('REGISTRY_PK_CAND_PUBLIC')],
        'proc' => ['id' => env('REGISTRY_PK_PROC_ID', 'pk:proc-1'), 'public' => env('REGISTRY_PK_PROC_PUBLIC')],
    ],
    'session_key_ttl' => (int) env('REGISTRY_SESSION_KEY_TTL', 3600),
    'operator_session_ttl' => (int) env('REGISTRY_OPERATOR_SESSION_TTL', 14400),
];
