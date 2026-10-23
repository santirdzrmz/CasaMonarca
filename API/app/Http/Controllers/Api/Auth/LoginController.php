<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\AuthenticatedUserViewService;
use App\Services\Auth\BrowserDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const PENDING_TOTP_USER_KEY = 'auth.pending_totp_user_id';

    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 300;

    public function __construct(
        private readonly AuthenticatedUserViewService $authenticatedUserViewService,
        private readonly AuditEventService $auditEventService,
        private readonly BrowserDeviceService $browserDeviceService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $rateLimitKey = 'login:'.sha1(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_FAILED_ATTEMPTS)) {
            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthLoginFailed,
                null,
                metadata: [
                    'method' => 'password',
                    'reason' => 'rate_limited',
                    'submittedEmail' => $credentials['email'],
                ],
            );

            return response()->json([
                'message' => 'Too many sign-in attempts. Try again later.',
                'error' => [
                    'code' => 'login_rate_limited',
                    'retryAfterSeconds' => RateLimiter::availableIn($rateLimitKey),
                ],
            ], 429);
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        // Hash even for unknown emails so response time does not reveal which accounts exist.
        $passwordMatches = Hash::check($credentials['password'], $user?->password ?? self::dummyHash());

        if ($user === null || ! $passwordMatches) {
            RateLimiter::hit($rateLimitKey, self::LOCKOUT_SECONDS);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthLoginFailed,
                $user,
                metadata: [
                    'method' => 'password',
                    'submittedEmail' => $credentials['email'],
                ],
            );

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        RateLimiter::clear($rateLimitKey);

        if ($user->isSuspended()) {
            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthLoginFailed,
                $user,
                metadata: [
                    'method' => 'password',
                    'reason' => 'account_suspended',
                ],
            );

            return response()->json([
                'message' => 'This account is suspended.',
                'error' => [
                    'code' => 'account_suspended',
                ],
            ], 403);
        }

        if ($user->hasTotpEnabled()) {
            Auth::guard('web')->logout();

            $request->session()->put(self::PENDING_TOTP_USER_KEY, $user->getKey());
            $request->session()->regenerateToken();

            $this->auditEventService->success(
                $request,
                AuditEventType::AuthTotpChallengeStarted,
                $user,
                metadata: [
                    'method' => 'password',
                ],
            );

            return response()->json([
                'message' => 'Two-factor authentication code is required.',
                'requiresTwoFactor' => true,
            ], 202);
        }

        $request->session()->forget(self::PENDING_TOTP_USER_KEY);
        Auth::guard('web')->login($user);
        $user->forceFill([
            'last_sign_in_at' => now(),
        ])->save();

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $deviceContext = $this->browserDeviceService->rememberAuthenticatedDevice($request, $user);

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthLoginSucceeded,
            $user,
            metadata: [
                'method' => 'password',
                ...$deviceContext['metadata'],
            ],
        );

        $this->recordNewDeviceIfNeeded($request, $user, $deviceContext);

        return response()->json([
            'message' => 'Login successful.',
            'requiresTwoFactor' => false,
            'user' => $this->authenticatedUserViewService->toArray($user->fresh() ?? $user),
        ]);
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }

    /**
     * @param  array{isNewDevice: bool, metadata: array<string, mixed>}  $deviceContext
     */
    private function recordNewDeviceIfNeeded(LoginRequest $request, User $user, array $deviceContext): void
    {
        if (! $deviceContext['isNewDevice']) {
            return;
        }

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthDeviceRegistered,
            $user,
            metadata: [
                'method' => 'password',
                ...$deviceContext['metadata'],
            ],
        );
    }
}
