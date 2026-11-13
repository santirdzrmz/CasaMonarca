<?php

return [
    /*
    | Application-layer envelope encryption for registration and ARCO payloads with
    | per-user keys. See docs/operations/registry-user-keys.md.
    |
    | enabled          Write ciphertext for new and changed payloads, and require unlocked
    |                  keys (or a temporary access grant) to read them.
    | write_plaintext  Dual-write window: keep the plaintext column populated.
    |                  Set to false only after backfill, parity tests and an
    |                  approved rollback window.
    | public_keys      Active public key per domain (base64): each record's DEK is wrapped
    |                  with it (cand = before approval, proc = approved). Private keys do NOT
    |                  live on the server; an admin delivers them and users unlock them per session.
    | session_key_ttl  Seconds unlocked keys stay available in the session.
    | operator_session_ttl  Seconds an operator's session key (Ks) lives; grants are tied to it.
    */
    'enabled' => (bool) env('REGISTRY_ENCRYPTION_ENABLED', false),
    'write_plaintext' => (bool) env('REGISTRY_ENCRYPTION_WRITE_PLAINTEXT', true),
    'context_environment' => env('REGISTRY_ENCRYPTION_ENV', env('APP_ENV', 'production')),
    'public_keys' => [
        'cand' => ['id' => env('REGISTRY_PK_CAND_ID', 'pk:cand-1'), 'public' => env('REGISTRY_PK_CAND_PUBLIC')],
        'proc' => ['id' => env('REGISTRY_PK_PROC_ID', 'pk:proc-1'), 'public' => env('REGISTRY_PK_PROC_PUBLIC')],
    ],
    'session_key_ttl' => (int) env('REGISTRY_SESSION_KEY_TTL', 3600),
    'operator_session_ttl' => (int) env('REGISTRY_OPERATOR_SESSION_TTL', 14400),
];
