<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Auth\SecurityEnrollmentService;

class UserDirectoryViewService
{
    public function __construct(
        private readonly SecurityEnrollmentService $securityEnrollmentService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(User $user): array
    {
        $security = $this->securityEnrollmentService->summaryForUser($user);
        $passkeyCount = (int) ($user->webauthn_credentials_count ?? $user->webauthnCredentials()->count());
        $deviceCount = (int) ($user->browser_devices_count ?? $user->browserDevices()->count());

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'curp' => $user->curp,
            'role' => $user->role?->value,
            'departments' => $user->departmentValues(),
            'status' => $user->status?->value ?? 'active',
            'emailVerifiedAt' => $user->email_verified_at?->toISOString(),
            'createdAt' => $user->created_at?->toISOString(),
            'updatedAt' => $user->updated_at?->toISOString(),
            'suspendedAt' => $user->suspended_at?->toISOString(),
            'suspensionReason' => $user->suspension_reason,
            'lastSignInAt' => $user->last_sign_in_at?->toISOString(),
            'enrollment' => [
                ...$security,
                'passkeyCount' => $passkeyCount,
            ],
            'devices' => [
                'count' => $deviceCount,
                'recent' => $user->browserDevices
                    ->take(3)
                    ->map(fn ($device): array => [
                        'id' => $device->id,
                        'deviceId' => $device->identifierPreview(),
                        'alias' => $device->alias,
                        'lastIpAddress' => $device->last_ip_address,
                        'firstSeenAt' => $device->first_seen_at?->toISOString(),
                        'lastSeenAt' => $device->last_seen_at?->toISOString(),
                        'lastLoginAt' => $device->last_login_at?->toISOString(),
                        'trustedAt' => $device->trusted_at?->toISOString(),
                        'revokedAt' => $device->revoked_at?->toISOString(),
                    ])
                    ->values(),
            ],
        ];
    }
}
