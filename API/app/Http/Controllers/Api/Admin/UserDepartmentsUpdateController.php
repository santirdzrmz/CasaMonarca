<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Department;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\UserDepartmentAssignmentService;
use App\Services\Admin\UserDirectoryViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserDepartmentsUpdateController extends Controller
{
    public function __construct(
        private readonly UserDepartmentAssignmentService $assignmentService,
        private readonly UserDirectoryViewService $userDirectoryViewService,
    ) {}

    public function __invoke(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'departments' => ['present', 'array', 'max:'.count(Department::cases())],
            'departments.*' => ['string', 'distinct', Rule::enum(Department::class)],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $departments = array_map(
            fn (string $value): Department => Department::from($value),
            $validated['departments'],
        );

        if ($departments === [] && $actor->is($user)) {
            return response()->json([
                'message' => 'You cannot remove all of your own departments; you would no longer be able to make changes.',
            ], 422);
        }

        $result = $this->assignmentService->sync($request, $user, $departments, $actor);

        return response()->json([
            'message' => 'User departments updated successfully.',
            'departments' => $result,
            'user' => $this->userDirectoryViewService->serialize($user->fresh()),
        ]);
    }
}
