<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\Encryption\EncryptionException;
use App\Services\Security\Encryption\KeyGrants;
use App\Services\Security\Encryption\PrivateKeyDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RegistryKeysGrant extends Command
{
    protected $signature = 'registry:keys-grant {email}
        {--domains=cand,proc : Dominios a entregar (cand, proc)}
        {--private-dir= : Carpeta con las llaves privadas (en la computadora del administrador)}
        {--age-identity= : Archivo de identidad age (YubiKey) para abrir las llaves privadas .age}
        {--fingerprint= : Huella de la llave pública del usuario, confirmada con él por otro canal}
        {--days=90 : Vigencia de la entrega}';

    protected $description = 'Entrega a un coordinador o administrador las llaves privadas de dominio, cifradas con SU llave pública';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        $key = $user ? DB::table('registry_user_keys')->where('user_id', $user->getKey())->whereNull('revoked_at')->first() : null;

        if ($key === null) {
            $this->error('El usuario no existe o no tiene llave registrada (registry:keys-enroll).');

            return self::FAILURE;
        }

        if (strcasecmp((string) $this->option('fingerprint'), $key->fingerprint) !== 0) {
            $this->error('La huella no coincide con la registrada ('.$key->fingerprint.'). Confírmala con el usuario antes de entregar llaves.');

            return self::FAILURE;
        }

        $dir = (string) $this->option('private-dir');
        $userPublic = (string) base64_decode($key->public_key, true);
        $expires = now()->addDays(max(1, (int) $this->option('days')));
        $granted = [];

        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('domains')))) as $domain) {
            $kekId = (string) config("registry_encryption.public_keys.$domain.id");

            if (! in_array($domain, ['cand', 'proc'], true) || $kekId === '') {
                $this->error("Dominio inválido o sin configurar: $domain");

                return self::FAILURE;
            }

            try {
                $secret = PrivateKeyDirectory::secret($dir, $kekId, ((string) $this->option('age-identity')) ?: null);
            } catch (EncryptionException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $wrapped = KeyGrants::wrap($secret, $userPublic);
            sodium_memzero($secret);

            DB::table('registry_key_grants')->where('user_id', $user->getKey())->where('kek_id', $kekId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('registry_key_grants')->insert([
                'user_id' => $user->getKey(),
                'kek_id' => $kekId,
                'wrapped' => $wrapped,
                'expires_at' => $expires,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $granted[] = $kekId;
        }

        $this->info('Entregadas a '.$user->email.': '.implode(', ', $granted).' (vencen '.$expires->toDateString().').');

        return self::SUCCESS;
    }
}
