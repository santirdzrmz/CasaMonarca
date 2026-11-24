<?php

namespace App\Console\Commands;

use App\Services\Security\Encryption\AccessGrantCrypto;
use App\Services\Security\Encryption\CryptoAlgorithms;
use App\Services\Security\Encryption\KeyFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only traceability report of the registry keys. It does not trust the audit log alone: it re-checks the stored
 * data itself — every sealed box must carry a 32-byte ephemeral X25519 public key (proof that an X25519
 * Diffie-Hellman was run for it) and those ephemeral keys must all be different; every granted temporary access must
 * carry a valid Ed25519 signature from a registered key. No private key is needed or opened.
 */
class RegistryKeysTrace extends Command
{
    protected $signature = 'registry:keys-trace {--json : Salida en JSON}';

    protected $description = 'Reporte de trazabilidad de llaves: generación, entregas, Diffie-Hellman de cada sobre, firmas y eventos de bitácora';

    /** Wrapped DEK: 32-byte DEK + 32-byte AAD hash, sealed. */
    private const DEK_SEALED_BYTES = 64 + CryptoAlgorithms::SEALED_BOX_OVERHEAD;

    /** Delivered domain private key: 32 bytes, sealed. */
    private const DOMAIN_KEY_SEALED_BYTES = SODIUM_CRYPTO_BOX_SECRETKEYBYTES + CryptoAlgorithms::SEALED_BOX_OVERHEAD;

    private const ENVELOPE_COLUMNS = [
        'migrant_registry_entries' => ['payload_json_enc', 'pending_payload_json_enc'],
        'migrant_arco_requests' => ['original_payload_json_enc', 'proposed_payload_json_enc'],
    ];

    public function handle(): int
    {
        $report = [
            'generatedAt' => now()->toIso8601String(),
            'encryptionEnabled' => (bool) config('registry_encryption.enabled'),
            'domainKeys' => $this->domainKeys(),
            'userKeys' => $this->userKeys(),
            'keyGrants' => $this->keyGrants(),
            'envelopes' => $this->envelopes(),
            'operatorAccess' => $this->operatorAccess(),
            'auditEvents' => DB::table('audit_events')
                ->where(fn ($q) => $q->where('event_type', 'like', 'registry.keys.%')->orWhere('event_type', 'like', 'registry.access.%')->orWhere('event_type', 'like', 'registry.encryption.%'))
                ->selectRaw('event_type, outcome, count(*) as total, max(occurred_at) as last')
                ->groupBy('event_type', 'outcome')->orderBy('event_type')->get()
                ->map(fn (object $row): array => (array) $row)->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return $this->healthy($report) ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array<string, mixed>> */
    private function domainKeys(): array
    {
        $generated = DB::table('audit_events')->where('event_type', 'registry.keys.domain_generated')->orderBy('occurred_at')->get(['occurred_at', 'metadata']);
        $rows = [];

        foreach (['cand' => 'Candidatos (antes de aprobar)', 'proc' => 'Procesados (aprobados)'] as $domain => $label) {
            $id = (string) config("registry_encryption.public_keys.$domain.id");
            $public = base64_decode((string) config("registry_encryption.public_keys.$domain.public"), true);
            $event = $generated->last(fn (object $e): bool => array_key_exists($id, (array) (json_decode((string) $e->metadata, true)['publicKeys'] ?? [])));
            $logged = $event ? (json_decode((string) $event->metadata, true)['publicKeys'][$id] ?? null) : null;

            $rows[] = [
                'domain' => $label,
                'kekId' => $id,
                'algorithm' => CryptoAlgorithms::KEY_AGREEMENT_KEYPAIR,
                'configured' => $public !== false && strlen((string) $public) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES,
                'fingerprint' => $public ? KeyFile::fingerprint($public) : null,
                'generationLogged' => $event?->occurred_at,
                // The key in .env must be the one whose generation was logged.
                'matchesLoggedKey' => $logged !== null ? hash_equals((string) $logged, (string) config("registry_encryption.public_keys.$domain.public")) : null,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function userKeys(): array
    {
        return DB::table('registry_user_key_history as h')->join('users as u', 'u.id', '=', 'h.user_id')
            ->orderBy('u.email')->orderBy('h.id')
            ->get(['u.email', 'h.fingerprint', 'h.enrolled_at', 'h.retired_at', 'h.retired_reason', 'h.public_key', 'h.signing_public_key'])
            ->map(fn (object $row): array => [
                'email' => $row->email,
                'fingerprint' => $row->fingerprint,
                'enrolledAt' => $row->enrolled_at,
                'status' => $row->retired_at ? "retirada ({$row->retired_reason}) {$row->retired_at}" : 'vigente',
                // The fingerprint must be derivable from the stored public keys.
                'fingerprintValid' => hash_equals($row->fingerprint, KeyFile::fingerprint(
                    (string) base64_decode($row->public_key, true),
                    (string) base64_decode($row->signing_public_key, true),
                )),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function keyGrants(): array
    {
        $grants = DB::table('registry_key_grants as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->orderBy('g.id')->get(['g.id', 'u.email', 'g.kek_id', 'g.wrapped', 'g.created_at', 'g.expires_at', 'g.revoked_at']);
        $ephemeral = [];
        $rows = [];

        foreach ($grants as $grant) {
            $raw = base64_decode($grant->wrapped, true);
            $sealed = $raw !== false && strlen($raw) === self::DOMAIN_KEY_SEALED_BYTES;

            if ($sealed) {
                $ephemeral[] = substr($raw, 0, SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
            }

            $rows[] = [
                'id' => $grant->id,
                'email' => $grant->email,
                'kekId' => $grant->kek_id,
                'deliveredAt' => $grant->created_at,
                'status' => $grant->revoked_at ? 'revocada' : ($grant->expires_at && now()->gte($grant->expires_at) ? 'vencida' : 'activa'),
                'sealedBoxFormat' => $sealed,
            ];
        }

        return ['rows' => $rows, 'diffieHellman' => $this->dh(count($rows), $ephemeral)];
    }

    /** @return array<string, mixed> */
    private function envelopes(): array
    {
        $byKek = [];
        $ephemeral = [];
        $total = 0;
        $badFormat = 0;

        foreach (self::ENVELOPE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->whereNotNull($column)->orderBy('id')->select(['id', $column])
                    ->chunk(500, function ($rows) use ($column, &$byKek, &$ephemeral, &$total, &$badFormat): void {
                        foreach ($rows as $row) {
                            $total++;
                            $envelope = json_decode((string) $row->{$column}, true);
                            $kek = is_array($envelope) ? (string) ($envelope['kek'] ?? '?') : '?';
                            $wrapped = is_array($envelope) ? base64_decode((string) ($envelope['wk'] ?? ''), true) : false;
                            $byKek[$kek] = ($byKek[$kek] ?? 0) + 1;

                            if ($wrapped === false || strlen($wrapped) !== self::DEK_SEALED_BYTES || ($envelope['alg'] ?? null) === null) {
                                $badFormat++;

                                continue;
                            }

                            $ephemeral[] = substr($wrapped, 0, SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
                        }
                    });
            }
        }

        ksort($byKek);

        return [
            'total' => $total,
            'byKekId' => $byKek,
            'payloadCipher' => CryptoAlgorithms::PAYLOAD,
            'unexpectedFormat' => $badFormat,
            'diffieHellman' => $this->dh($total - $badFormat, $ephemeral),
        ];
    }

    /** @return array<string, mixed> */
    private function operatorAccess(): array
    {
        $sessions = DB::table('registry_access_sessions')->get(['id', 'public_key', 'fingerprint']);
        $byStatus = DB::table('registry_access_grants')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $valid = 0;
        $invalid = [];

        foreach (DB::table('registry_access_grants')->whereNotNull('signature')->get() as $grant) {
            $session = $sessions->firstWhere('id', $grant->session_id);
            $signer = DB::table('registry_user_key_history')->where('user_id', $grant->signed_by)->get(['signing_public_key']);
            $payload = $session ? AccessGrantCrypto::payload([
                'id' => $grant->id, 'resource_type' => $grant->resource_type, 'resource_id' => $grant->resource_id, 'user_id' => $grant->user_id,
                'sessionPublic' => (string) base64_decode($session->public_key, true), 'expires_at' => (string) $grant->expires_at,
                'signed_by' => $grant->signed_by, 'wrapped_deks' => (string) $grant->wrapped_deks,
            ]) : null;
            // Any key the signer has had: an old grant stays verifiable after the signer's key is replaced.
            $ok = $payload !== null && $signer->contains(
                fn (object $key): bool => AccessGrantCrypto::verify($payload, (string) $grant->signature, (string) base64_decode($key->signing_public_key, true)),
            );

            $ok ? $valid++ : $invalid[] = (int) $grant->id;
        }

        return [
            'sessionKeys' => $sessions->count(),
            'grantsByStatus' => $byStatus,
            'signatureAlgorithm' => CryptoAlgorithms::SIGNATURE,
            'signaturesValid' => $valid,
            'signaturesInvalid' => $invalid,
        ];
    }

    /**
     * @param  list<string>  $ephemeralKeys
     * @return array<string, mixed>
     */
    private function dh(int $sealed, array $ephemeralKeys): array
    {
        $distinct = count(array_unique($ephemeralKeys));

        return [
            'method' => CryptoAlgorithms::SEALED_BOX,
            'sealedBoxes' => $sealed,
            'distinctEphemeralKeys' => $distinct,
            // A fresh ephemeral key per box means a separate X25519 Diffie-Hellman was run for each one.
            'oneExchangePerBox' => $sealed === $distinct,
        ];
    }

    /** @param array<string, mixed> $report */
    private function healthy(array $report): bool
    {
        return collect($report['userKeys'])->every(fn (array $key): bool => $key['fingerprintValid'])
            && collect($report['keyGrants']['rows'])->every(fn (array $grant): bool => $grant['sealedBoxFormat'])
            && $report['keyGrants']['diffieHellman']['oneExchangePerBox']
            && $report['envelopes']['unexpectedFormat'] === 0
            && $report['envelopes']['diffieHellman']['oneExchangePerBox']
            && $report['operatorAccess']['signaturesInvalid'] === []
            && collect($report['domainKeys'])->every(fn (array $key): bool => $key['matchesLoggedKey'] !== false);
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        $yes = fn (?bool $value): string => $value === null ? '—' : ($value ? 'sí' : 'NO');

        $this->info('Trazabilidad de llaves del registro · '.$report['generatedAt'].' · cifrado '.($report['encryptionEnabled'] ? 'activo' : 'APAGADO'));

        $this->newLine();
        $this->line('<options=bold>1. Llaves de dominio</>');
        $this->table(['Dominio', 'Id', 'Tipo', 'Huella', 'Generación en bitácora', 'Coincide con la registrada'], array_map(fn (array $k): array => [
            $k['domain'], $k['kekId'], $k['algorithm'], $k['fingerprint'] ?? 'sin configurar', $k['generationLogged'] ?? 'no registrada', $yes($k['matchesLoggedKey']),
        ], $report['domainKeys']));

        $this->line('<options=bold>2. Llaves personales (historial completo)</>');
        $this->table(['Usuario', 'Huella', 'Alta', 'Estado', 'Huella válida'], array_map(fn (array $k): array => [
            $k['email'], $k['fingerprint'], $k['enrolledAt'], $k['status'], $yes($k['fingerprintValid']),
        ], $report['userKeys']));

        $this->line('<options=bold>3. Entregas de llaves de dominio</>');
        $this->table(['#', 'Usuario', 'Llave', 'Entregada', 'Estado', 'Sobre sellado'], array_map(fn (array $g): array => [
            $g['id'], $g['email'], $g['kekId'], $g['deliveredAt'], $g['status'], $yes($g['sealedBoxFormat']),
        ], $report['keyGrants']['rows']));
        $this->dhLine($report['keyGrants']['diffieHellman']);

        $this->newLine();
        $this->line('<options=bold>4. Sobres de registros y ARCO</> ('.$report['envelopes']['payloadCipher'].' para el contenido)');
        $this->table(['Llave (kek)', 'Sobres'], collect($report['envelopes']['byKekId'])->map(fn (int $n, string $kek): array => [$kek, $n])->values()->all());
        $this->line('Formato inesperado: '.$report['envelopes']['unexpectedFormat']);
        $this->dhLine($report['envelopes']['diffieHellman']);

        $this->newLine();
        $access = $report['operatorAccess'];
        $this->line('<options=bold>5. Accesos temporales de operadores</>');
        $this->line('Llaves de sesión registradas: '.$access['sessionKeys'].' · accesos por estado: '.(json_encode($access['grantsByStatus']) ?: '{}'));
        $this->line('Firmas '.$access['signatureAlgorithm'].' válidas: '.$access['signaturesValid'].($access['signaturesInvalid'] === [] ? '' : ' · INVÁLIDAS: '.implode(', ', $access['signaturesInvalid'])));

        $this->newLine();
        $this->line('<options=bold>6. Eventos en la bitácora</>');
        $this->table(['Evento', 'Resultado', 'Total', 'Último'], array_map(fn (array $e): array => [$e['event_type'], $e['outcome'], $e['total'], $e['last']], $report['auditEvents']));

        $checked = $report['envelopes']['total'] + count($report['keyGrants']['rows']) + count($report['userKeys']);

        if (! $this->healthy($report)) {
            $this->error('Hay inconsistencias; revisa las filas marcadas con NO.');
        } elseif ($checked === 0) {
            $this->warn('No hay llaves ni sobres que verificar'.($report['encryptionEnabled'] ? '.' : ': el cifrado está apagado (REGISTRY_ENCRYPTION_ENABLED=false).'));
        } else {
            $this->info('Todo verificado.');
        }
    }

    /** @param array<string, mixed> $dh */
    private function dhLine(array $dh): void
    {
        $this->line(sprintf(
            'Diffie-Hellman (%s): %d sobres, %d llaves efímeras distintas → %s',
            $dh['method'],
            $dh['sealedBoxes'],
            $dh['distinctEphemeralKeys'],
            $dh['oneExchangePerBox'] ? 'un intercambio X25519 por sobre' : 'NO coincide',
        ));
    }
}
