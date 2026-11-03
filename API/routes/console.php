<?php

use App\Enums\Department;
use App\Models\User;
use App\Services\Admin\UserDepartmentAssignmentService;
use App\Services\Documents\VerificationPackageSigningKeyService;
use App\Services\Registry\MigrantRegistryService;
use App\Services\Security\SecurityChallengeIntentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'verification-packages:generate-signing-key
        {--key-id= : Stable identifier for this signing key}
        {--bits=3072 : RSA key size}
        {--write-env : Write the generated values into .env}
        {--force : Allow replacing existing verification package signing keys}',
    function (): int {
        $signingKeyService = app(VerificationPackageSigningKeyService::class);
        $summary = $signingKeyService->summary();
        $hasConfiguredKey = (bool) ($summary['privateKeyConfigured'] ?? false)
            || (bool) ($summary['publicKeyConfigured'] ?? false);

        if ($hasConfiguredKey && ! $this->option('force')) {
            $this->error('A verification package signing key is already configured. Use --force only for an intentional rotation.');

            return Command::FAILURE;
        }

        $bits = max(2048, (int) $this->option('bits'));
        $keyId = (string) ($this->option('key-id') ?: 'cm-package-'.now()->format('Y-m-d'));
        $generatedKey = $signingKeyService->generate($keyId, $bits);
        $values = [
            'VERIFICATION_PACKAGE_SIGNING_KEY_ID' => $generatedKey['keyId'],
            'VERIFICATION_PACKAGE_SIGNING_PRIVATE_KEY_BASE64' => $generatedKey['privateKeyBase64'],
            'VERIFICATION_PACKAGE_SIGNING_PUBLIC_KEY_BASE64' => $generatedKey['publicKeyBase64'],
        ];

        if ($this->option('write-env')) {
            try {
                $signingKeyService->writeEnvValues($values, (bool) $this->option('force'));
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return Command::FAILURE;
            }

            $this->info('Updated .env with verification package signing key values.');
            $this->warn('Run php artisan optimize:clear before exporting signed verification packages.');
        } else {
            $this->line('Copy these values into .env:');
            $this->newLine();

            foreach ($values as $name => $value) {
                $this->line($name.'='.$value);
            }
        }

        $this->newLine();
        $this->info('Public key fingerprint: '.$generatedKey['publicKeyFingerprint']);
        $this->line('Publish or pin this fingerprint separately from exported verification packages.');

        if (Str::contains($keyId, 'dev')) {
            $this->warn('This key id looks development-scoped. Use a stable staging/production key id for real package review.');
        }

        return Command::SUCCESS;
    },
)->purpose('Generate the RSA keypair used to sign document verification package manifests.');

Artisan::command(
    'security-challenges:expire
        {--limit=500 : Maximum number of pending challenge intents to expire}',
    function (): int {
        $expiredCount = app(SecurityChallengeIntentService::class)
            ->expirePending(max(1, (int) $this->option('limit')));

        $this->info("Expired {$expiredCount} pending security challenge intent(s).");

        return Command::SUCCESS;
    },
)->purpose('Mark pending security challenge intents as expired after their expiry time.');

Artisan::command(
    'users:departments
        {email : Account email}
        {departments?* : Department values to assign; omit to list the current ones}
        {--replace : Replace the current set instead of adding to it}',
    function (): int {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->error('No user with that email.');

            return Command::FAILURE;
        }

        $requested = (array) $this->argument('departments');

        if ($requested === []) {
            $this->line($user->email.': '.(implode(', ', $user->departmentValues()) ?: '(none)'));
            $this->line('Valid values: '.implode(', ', Department::values()));

            return Command::SUCCESS;
        }

        $invalid = array_diff($requested, Department::values());

        if ($invalid !== []) {
            $this->error('Invalid department(s): '.implode(', ', $invalid).'. Valid values: '.implode(', ', Department::values()));

            return Command::FAILURE;
        }

        $values = $this->option('replace') ? $requested : [...$user->departmentValues(), ...$requested];
        $result = app(UserDepartmentAssignmentService::class)->sync(
            request(),
            $user,
            array_map(fn (string $value): Department => Department::from($value), array_unique($values)),
            null,
            'console',
        );

        $this->info($user->email.': '.implode(', ', $result['current']));

        return Command::SUCCESS;
    },
)->purpose('List or assign the departments a user can act for (bootstrap for the first admin).');

Artisan::command('registry:migrant-drafts:purge', function (): int {
    $purged = app(MigrantRegistryService::class)->purgeExpiredDrafts(7);
    $this->info("Purged {$purged} expired migrant registration draft(s).");

    return Command::SUCCESS;
})->purpose('Permanently delete migrant registration drafts inactive for seven days.');

Schedule::command('registry:migrant-drafts:purge')->daily();
