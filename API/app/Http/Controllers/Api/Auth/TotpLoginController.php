<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\TotpLoginRequest;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\AuthenticatedUserViewService;
use App\Services\Auth\BrowserDeviceService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TotpLoginController extends Controller
{
    private const PENDING_TOTP_USER_KEY = 'auth.pending_totp_user_id';

    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    /** Long enough to outlive the ±1 step verification window. */
    private const USED_CODE_TTL_SECONDS = 120;

    public function __construct(
        private readonly AuthenticatedUserViewService $authenticatedUserViewService,
        private readonly AuditEventService $auditEventService,
        private readonly BrowserDeviceService $browserDeviceService,
        private readonly TotpService $totpService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(TotpLoginRequest $request): JsonResponse
    {
        $pendingUserId = $request->session()->get(self::PENDING_TOTP_USER_KEY);

        if (! is_numeric($pendingUserId)) {
            return response()->json([
                'message' => 'Two-factor challenge was not initiated.',
            ], 401);
        }

        /** @var User|null $user */
        $user = User::query()->find($pendingUserId);

        if ($user === null || ! $user->hasTotpEnabled()) {
            $request->session()->forget(self::PENDING_TOTP_USER_KEY);

            return response()->json([
                'message' => 'Two-factor challenge is no longer available.',
            ], 401);
        }

        if ($user->isSuspended()) {
            $request->session()->forget(self::PENDING_TOTP_USER_KEY);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthLoginFailed,
                $user,
                metadata: [
                    'method' => 'totp',
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

        // Keyed by account, not session, so restarting the password step does not reset the budget.
        $rateLimitKey = 'login-totp:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_FAILED_ATTEMPTS)) {
            $request->session()->forget(self::PENDING_TOTP_USER_KEY);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthTotpChallengeFailed,
                $user,
                metadata: [
                    'method' => 'totp',
                    'reason' => 'rate_limited',
                ],
            );

            return response()->json([
                'message' => 'Too many authentication code attempts. Sign in again later.',
                'error' => [
                    'code' => 'totp_rate_limited',
                    'retryAfterSeconds' => RateLimiter::availableIn($rateLimitKey),
                ],
            ], 429);
        }

        $counter = $this->totpService->matchingCounter((string) $user->two_factor_secret, (string) $request->string('code'));
        $isReplay = $counter !== null
            && ! Cache::add('totp-used:'.$user->getKey().':'.$counter, true, self::USED_CODE_TTL_SECONDS);

        if ($counter === null || $isReplay) {
            RateLimiter::hit($rateLimitKey, self::LOCKOUT_SECONDS);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthTotpChallengeFailed,
                $user,
                metadata: [
                    'method' => 'totp',
                    ...($isReplay ? ['reason' => 'code_reused'] : []),
                ],
            );

            throw ValidationException::withMessages([
                'code' => ['The authentication code is invalid or expired.'],
            ]);
        }

        RateLimiter::clear($rateLimitKey);
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
                'method' => 'totp',
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

    /**
     * @param  array{isNewDevice: bool, metadata: array<string, mixed>}  $deviceContext
     */
    private function recordNewDeviceIfNeeded(TotpLoginRequest $request, User $user, array $deviceContext): void
    {
        if (! $deviceContext['isNewDevice']) {
            return;
        }

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthDeviceRegistered,
            $user,
            metadata: [
                'method' => 'totp',
                ...$deviceContext['metadata'],
            ],
        );
    }
}
