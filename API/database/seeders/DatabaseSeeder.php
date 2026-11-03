<?php

namespace Database\Seeders;

use App\Enums\Department;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Admin\UserDepartmentAssignmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $email = config('app.local_admin_email');
        $password = config('app.local_admin_password');

        if (! is_string($email) || trim($email) === '' || ! is_string($password) || $password === '') {
            $this->command?->warn('Local administrator was not seeded because local credentials are not configured.');

            return;
        }

        $admin = User::updateOrCreate(
            ['email' => trim($email)],
            [
                'name' => 'Local Admin',
                'role' => UserRole::Admin->value,
                'status' => UserStatus::Active->value,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
            ],
        );

        // Department attribution is enforced by default, so an admin without a
        // department could not make any change. Keep departments set on reseed.
        if ($admin->departmentValues() === []) {
            app(UserDepartmentAssignmentService::class)->sync(
                request(),
                $admin,
                [Department::InformationTechnology],
                null,
                'seeder',
            );
        }
    }
}
