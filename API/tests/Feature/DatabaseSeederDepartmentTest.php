<?php

namespace Tests\Feature;

use App\Enums\Department;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederDepartmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn (): string => 'local');
        config([
            'app.local_admin_email' => 'local-admin@example.test',
            'app.local_admin_password' => 'Seeder-Test-Password#2026',
        ]);
    }

    public function test_local_admin_is_seeded_with_a_department(): void
    {
        (new DatabaseSeeder)->run();

        $admin = User::query()->where('email', 'local-admin@example.test')->firstOrFail();

        $this->assertSame([Department::InformationTechnology->value], $admin->departmentValues());
    }

    public function test_reseeding_keeps_existing_departments(): void
    {
        (new DatabaseSeeder)->run();

        $admin = User::query()->where('email', 'local-admin@example.test')->firstOrFail();
        $admin->departmentAssignments()->delete();
        $admin->departmentAssignments()->create(['department' => Department::Legal->value]);

        (new DatabaseSeeder)->run();

        $this->assertSame([Department::Legal->value], $admin->fresh()->departmentValues());
    }
}
