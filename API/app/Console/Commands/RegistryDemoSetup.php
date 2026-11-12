<?php

namespace App\Console\Commands;

use App\Enums\Department;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Admin\UserDepartmentAssignmentService;
use App\Services\Auth\TotpService;
use App\Services\Security\Encryption\KeyFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegistryDemoSetup extends Command
{
    private const PASSWORD = 'DemoPassword-2026!';

    private const PASSPHRASE = 'DemoKeys-2026-local';

    protected $signature = 'registry:demo-setup {--keys-dir= : Carpeta de las llaves de la demo (por defecto storage/app/demo-keys)}';

    protected $description = 'SOLO LOCAL: crea un usuario de demostración por nivel, las llaves de dominio y los archivos de llave del coordinador y el administrador';

    public function handle(TotpService $totp): int
    {
        if (! app()->environment('local')) {
            $this->error('registry:demo-setup solo corre con APP_ENV=local.');

            return self::FAILURE;
        }

        $keysDir = ((string) $this->option('keys-dir')) ?: storage_path('app/demo-keys');
        $privateDir = $keysDir.DIRECTORY_SEPARATOR.'private';
        @mkdir($keysDir, 0700, true);

        $definitions = [
            ['email' => 'voluntario@demo.test', 'name' => 'Voluntaria Demo', 'role' => UserRole::Volunteer, 'department' => Department::Humanitarian, 'level' => '4 Voluntario', 'keys' => false],
            ['email' => 'operador@demo.test', 'name' => 'Operador Demo', 'role' => UserRole::NonCoordinator, 'department' => Department::Psychosocial, 'level' => '3 Operador', 'keys' => false],
            ['email' => 'coordinador@demo.test', 'name' => 'Coordinadora Demo', 'role' => UserRole::Coordinator, 'department' => Department::Legal, 'level' => '2 Coordinador', 'keys' => true],
            ['email' => 'admin@demo.test', 'name' => 'Administrador Demo', 'role' => UserRole::Admin, 'department' => Department::InformationTechnology, 'level' => '1 Administrador', 'keys' => true],
        ];

        $users = [];
        $rows = [];

        foreach ($definitions as $definition) {
            $user = User::query()->firstOrNew(['email' => $definition['email']]);
            $secret = $user->two_factor_secret ?: $totp->generateSecret();

            $user->forceFill([
                'name' => $definition['name'],
                'role' => $definition['role']->value,
                'status' => UserStatus::Active->value,
                'email_verified_at' => now(),
                'password' => Hash::make(self::PASSWORD),
                'two_factor_enabled' => true,
                'two_factor_secret' => $secret,
            ])->save();

            if ($user->departmentValues() === []) {
                app(UserDepartmentAssignmentService::class)->sync(request(), $user, [$definition['department']], null, 'seeder');
            }

            $users[$definition['email']] = $user;
            $rows[] = [$definition['level'], $definition['email'], $definition['department']->value];
        }

        if (glob($privateDir.DIRECTORY_SEPARATOR.'pk_cand-1.*') === [] || glob($privateDir.DIRECTORY_SEPARATOR.'pk_proc-1.*') === []) {
            $this->info('Generando las llaves de dominio (solo desarrollo: privadas en claro en '.$privateDir.')...');
            $this->call('registry:keys-init', ['--private-dir' => $privateDir, '--plain' => true]);
        } else {
            $this->line('Las llaves de dominio ya existen en '.$privateDir.'; se reutilizan.');
        }

        $keyFiles = [];

        foreach ($definitions as $definition) {
            if (! $definition['keys']) {
                continue;
            }

            $user = $users[$definition['email']];
            $keyFile = KeyFile::create(self::PASSPHRASE);
            $path = $keysDir.DIRECTORY_SEPARATOR.strtolower($definition['role']->value).'.keyfile.json';
            file_put_contents($path, $keyFile['file']);

            DB::table('registry_user_keys')->updateOrInsert(
                ['user_id' => $user->getKey()],
                [
                    'public_key' => base64_encode($keyFile['public']),
                    'signing_public_key' => base64_encode($keyFile['signing_public']),
                    'fingerprint' => $keyFile['fingerprint'],
                    'revoked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $status = $this->callSilently('registry:keys-grant', [
                'email' => $user->email,
                '--fingerprint' => $keyFile['fingerprint'],
                '--private-dir' => $privateDir,
                '--days' => 365,
            ]);

            $keyFiles[] = [$definition['level'], $path, $status === self::SUCCESS ? 'entregadas' : 'FALLÓ la entrega'];
        }

        $this->newLine();
        $this->info('Usuarios de demostración (contraseña de todos: '.self::PASSWORD.')');
        $this->table(['Nivel', 'Correo', 'Departamento'], $rows);
        $this->info('Archivos de llave (contraseña del archivo: '.self::PASSPHRASE.')');
        $this->table(['Nivel', 'Archivo', 'Llaves de dominio'], $keyFiles);
        $this->line('Código TOTP de un usuario: php artisan registry:demo-totp <correo>');
        $this->line('Coordinador y administrador también deben registrar una llave de acceso (passkey) la primera vez que inician sesión.');

        return self::SUCCESS;
    }
}
