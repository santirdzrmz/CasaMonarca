<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistryStatusHistory;
use App\Models\User;
use App\Services\Registry\MigrantRegistryService;
use App\Services\Registry\SyntheticMigrantRegistryGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RegistrySyntheticData extends Command
{
    public const CREATOR_EMAIL = 'synthetic-data@example.test';

    protected $signature = 'registry:synthetic
        {--count=600 : Number of synthetic registrations to create}
        {--months=13 : Spread attention dates over this many months, ending today}
        {--seed= : Random seed for a reproducible data set}
        {--purge : Delete every synthetic registration instead of creating new ones}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Create (or --purge) SYNTHETIC migrant registrations for development. Refuses to run in production.';

    /** Share of each status among generated registrations. */
    private const STATUSES = [
        MigrantRegistryService::STATUS_APPROVED => 85,
        MigrantRegistryService::STATUS_PENDING_REVIEW => 6,
        MigrantRegistryService::STATUS_PENDING_APPROVAL => 5,
        MigrantRegistryService::STATUS_REJECTED => 4,
    ];

    public function handle(SyntheticMigrantRegistryGenerator $generator): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run: APP_ENV is production. Synthetic data must never be mixed with real registrations.');

            return self::FAILURE;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');

        return $this->option('purge')
            ? $this->purge($database)
            : $this->create($generator, $database);
    }

    private function create(SyntheticMigrantRegistryGenerator $generator, string $database): int
    {
        $count = max(1, (int) $this->option('count'));
        $months = max(1, (int) $this->option('months'));

        if (! $this->option('force') && ! $this->confirm("Create {$count} SYNTHETIC registrations in database [{$database}] (APP_ENV=".app()->environment().')?')) {
            return self::FAILURE;
        }

        if ($this->option('seed') !== null) {
            $generator->seed((int) $this->option('seed'));
            mt_srand((int) $this->option('seed'));
        }

        $creator = $this->creator();
        $today = CarbonImmutable::today();
        $start = $today->subMonthsNoOverflow($months - 1)->startOfMonth();
        $span = max(1, (int) $start->diffInDays($today));
        $offset = (int) MigrantRegistryEntry::query()->withTrashed()->where('created_by', $creator->id)->count();
        $byStatus = [];

        $bar = $this->output->createProgressBar($count);

        DB::transaction(function () use ($count, $generator, $creator, $start, $span, $offset, &$byStatus, $bar): void {
            for ($i = 1; $i <= $count; $i++) {
                $attention = $this->attentionDate($start, $span);
                $status = $this->status();
                $payload = $generator->payload($offset + $i, $attention);
                $createdAt = $attention->setTime(mt_rand(8, 18), mt_rand(0, 59));

                $entry = MigrantRegistryEntry::query()->create([
                    'created_by' => $creator->id,
                    'created_by_role' => UserRole::Volunteer->value,
                    'current_status' => $status,
                    'current_assignee_role' => match ($status) {
                        MigrantRegistryService::STATUS_PENDING_REVIEW => 'non_coordinator',
                        MigrantRegistryService::STATUS_PENDING_APPROVAL => 'coordinator',
                        default => null,
                    },
                    'pending_action' => in_array($status, [MigrantRegistryService::STATUS_PENDING_REVIEW, MigrantRegistryService::STATUS_PENDING_APPROVAL], true)
                        ? MigrantRegistryService::ACTION_CREATE
                        : null,
                    'pending_requested_by' => $creator->id,
                    'pending_requested_by_role' => UserRole::Volunteer->value,
                    'payload_json' => $payload,
                ]);
                $entry->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

                MigrantRegistryStatusHistory::query()->create([
                    'registry_entry_id' => $entry->id,
                    'from_status' => null,
                    'to_status' => $status,
                    'changed_by' => $creator->id,
                    'changed_by_role' => UserRole::Volunteer->value,
                    'reason' => SyntheticMigrantRegistryGenerator::MARKER.': generado por registry:synthetic',
                ]);

                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->table(['Status', 'Registrations'], collect($byStatus)->map(fn (int $n, string $s): array => [$s, $n])->values()->all());
        $this->info("Created {$count} synthetic registrations owned by ".self::CREATOR_EMAIL.'. Remove them with: php artisan registry:synthetic --purge');

        return self::SUCCESS;
    }

    private function purge(string $database): int
    {
        $creator = User::query()->where('email', self::CREATOR_EMAIL)->first();

        if (! $creator instanceof User) {
            $this->info('No synthetic data found.');

            return self::SUCCESS;
        }

        $ids = MigrantRegistryEntry::query()->withTrashed()->where('created_by', $creator->id)->pluck('id');

        if (! $this->option('force') && ! $this->confirm("Delete {$ids->count()} synthetic registrations from database [{$database}]?")) {
            return self::FAILURE;
        }

        // Records created while testing on top of synthetic entries (ARCO requests,
        // uploaded documents) are synthetic too; remove them child-first.
        DB::transaction(function () use ($ids, $creator): void {
            foreach ($ids->chunk(500) as $chunk) {
                $arcoIds = DB::table('migrant_arco_requests')->whereIn('registry_entry_id', $chunk)->pluck('id');

                $files = DB::table('migrant_registry_documents')->whereIn('registry_entry_id', $chunk)
                    ->union(DB::table('migrant_arco_artifacts')->whereIn('arco_request_id', $arcoIds)->select(['storage_disk', 'storage_path']))
                    ->select(['storage_disk', 'storage_path'])
                    ->get();

                foreach ($files as $file) {
                    if ($file->storage_disk && $file->storage_path) {
                        Storage::disk($file->storage_disk)->delete($file->storage_path);
                    }
                }

                DB::table('migrant_registry_status_history')->whereIn('registry_entry_id', $chunk)->delete();

                foreach (['migrant_arco_signatures', 'migrant_arco_status_history', 'migrant_arco_artifacts'] as $table) {
                    DB::table($table)->whereIn('arco_request_id', $arcoIds)->delete();
                }

                foreach (['migrant_arco_requests', 'migrant_registry_signatures', 'migrant_registry_documents'] as $table) {
                    DB::table($table)->whereIn('registry_entry_id', $chunk)->delete();
                }

                MigrantRegistryEntry::query()->withTrashed()->whereIn('id', $chunk)->forceDelete();
            }

            $creator->delete();
        });

        $this->info("Deleted {$ids->count()} synthetic registrations and the synthetic creator account.");

        return self::SUCCESS;
    }

    /** Suspended, password-less placeholder account that owns every synthetic record. */
    private function creator(): User
    {
        return User::query()->firstOrCreate(
            ['email' => self::CREATOR_EMAIL],
            [
                'name' => 'Datos sintéticos (no es una persona)',
                'role' => UserRole::Volunteer->value,
                'status' => UserStatus::Suspended->value,
                'suspension_reason' => 'Cuenta técnica para datos sintéticos; no inicia sesión.',
                'password' => Hash::make(Str::random(64)),
                'two_factor_enabled' => false,
            ],
        );
    }

    /** Arrivals vary by month (seasonality) so the monthly chart is not flat. */
    private function attentionDate(CarbonImmutable $start, int $span): CarbonImmutable
    {
        do {
            $date = $start->addDays(mt_rand(0, $span));
            $weight = 0.7 + 0.3 * sin(($date->month / 12) * 2 * M_PI);
        } while (mt_rand() / mt_getrandmax() > $weight);

        return $date;
    }

    private function status(): string
    {
        $roll = mt_rand(1, array_sum(self::STATUSES));

        foreach (self::STATUSES as $status => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $status;
            }
        }

        return MigrantRegistryService::STATUS_APPROVED;
    }
}
