<?php

namespace App\Services\Admin;

use App\Enums\AuditEventType;
use App\Enums\Department;
use App\Models\User;
use App\Models\UserDepartment;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserDepartmentAssignmentService
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    /**
     * Replace the user's department set and audit the change.
     *
     * @param  list<Department>  $departments
     * @return array{previous: list<string>, current: list<string>}
     */
    public function sync(Request $request, User $target, array $departments, ?User $actor, string $source = 'admin'): array
    {
        $previous = $target->departmentValues();
        $current = collect($departments)
            ->map(fn (Department $department): string => $department->value)
            ->unique()
            ->sort()
            ->values()
            ->all();

        DB::transaction(function () use ($target, $current, $actor): void {
            $target->departmentAssignments()->whereNotIn('department', $current)->delete();

            foreach ($current as $value) {
                UserDepartment::query()->firstOrCreate(
                    ['user_id' => $target->getKey(), 'department' => $value],
                    ['assigned_by_user_id' => $actor?->getKey()],
                );
            }
        });

        if ($previous !== $current) {
            $this->auditEventService->success(
                $request,
                AuditEventType::AdminUserDepartmentsChanged,
                $actor,
                ['type' => 'user', 'id' => $target->getKey()],
                [
                    'previousDepartments' => $previous,
                    'departments' => $current,
                    'added' => array_values(array_diff($current, $previous)),
                    'removed' => array_values(array_diff($previous, $current)),
                    'targetUserId' => $target->getKey(),
                    'targetUserName' => $target->name,
                    'targetUserEmail' => $target->email,
                    'source' => $source,
                ],
            );
        }

        return ['previous' => $previous, 'current' => $current];
    }
}
