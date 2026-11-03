<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;

class AuthenticatedUserViewService
{
    public function __construct(
        private readonly SessionCapabilityService $sessionCapabilityService,
    ) {}

    /**
     * @return array{
     *     capabilities: array{
     *         modules: array{
     *             admin: bool,
     *             dashboard: bool,
     *             documents: bool,
     *             history: bool,
     *             invites: bool,
     *             logging: bool,
     *             upload: bool,
     *         },
     *         security: array{
     *             enrolled: array{
     *                 passkey: bool,
     *                 totp: bool,
     *             },
     *             enforced: bool,
     *             isFullyEnrolled: bool,
     *             missing: array{
     *                 passkey: bool,
     *                 totp: bool,
     *             },
     *             requires: array{
     *                 passkey: bool,
     *                 totp: bool,
     *             },
     *         },
     *     },
     *     departments: list<string>,
     *     email: string,
     *     id: int|string,
     *     name: string,
     *     role: string,
     * }
     */
    public function toArray(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value ?? UserRole::default()->value,
            'departments' => $user->departmentValues(),
            'capabilities' => $this->sessionCapabilityService->forUser($user),
        ];
    }
}
