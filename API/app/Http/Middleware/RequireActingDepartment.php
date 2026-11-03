<?php

namespace App\Http\Middleware;

use App\Enums\AuditEventType;
use App\Enums\Department;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every modifying request must declare which of the actor's assigned departments
 * it acts for (X-Acting-Department). The department is exposed to the audit log as
 * a request attribute.
 *
 * Passkey flows run in two requests: `.../options` issues the challenge and
 * `.../verify` consumes the signature. The department declared on options is kept
 * in the session and verify must declare the same one, so the signed action is
 * bound to the department chosen when the challenge was issued.
 */
class RequireActingDepartment
{
    public const HEADER = 'X-Acting-Department';

    public const ATTRIBUTE = 'acting_department';

    private const CHALLENGE_SESSION_KEY = 'acting_department_challenges';

    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $declared = trim((string) $request->headers->get(self::HEADER, ''));

        if ($declared === '') {
            if (! config('departments.enforced')) {
                return $next($request);
            }

            return $this->deny($request, $user, 'acting_department_required', 'Select the department you are acting for before making changes.', 422);
        }

        $department = Department::tryFrom($declared);

        if ($department === null) {
            return $this->deny($request, $user, 'acting_department_invalid', 'The declared department is not valid.', 422, $declared);
        }

        if (! $user->hasDepartment($department)) {
            return $this->deny($request, $user, 'acting_department_not_assigned', 'You are not assigned to the declared department.', 403, $department->value);
        }

        $challengeKey = $this->challengeKey($request);

        if ($challengeKey !== null && str_ends_with($challengeKey, '|verify')) {
            $issuedFor = $this->challengeDepartment($request, $challengeKey);

            if ($issuedFor !== null && $issuedFor !== $department->value) {
                return $this->deny($request, $user, 'acting_department_mismatch', 'The declared department differs from the one used when this signature was requested. Start the action again.', 409, $department->value, $issuedFor);
            }
        }

        $request->attributes->set(self::ATTRIBUTE, $department);

        $response = $next($request);

        if ($challengeKey !== null) {
            $this->rememberChallenge($request, $challengeKey, $department, $response);
        }

        return $response;
    }

    /**
     * "registry/migrants/5/approval/options" -> "registry/migrants/5/approval|options".
     */
    private function challengeKey(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $path = trim($request->path(), '/');

        foreach (['options', 'verify'] as $step) {
            if (str_ends_with($path, '/'.$step)) {
                return substr($path, 0, -strlen('/'.$step)).'|'.$step;
            }
        }

        return null;
    }

    private function challengeDepartment(Request $request, string $verifyKey): ?string
    {
        $flow = substr($verifyKey, 0, -strlen('|verify'));
        $value = $request->session()->get(self::CHALLENGE_SESSION_KEY, [])[$flow] ?? null;

        return is_string($value) ? $value : null;
    }

    private function rememberChallenge(Request $request, string $challengeKey, Department $department, Response $response): void
    {
        [$flow, $step] = explode('|', $challengeKey, 2);
        $challenges = $request->session()->get(self::CHALLENGE_SESSION_KEY, []);

        if ($step === 'options' && $response->isSuccessful()) {
            $challenges[$flow] = $department->value;
        } elseif ($step === 'verify') {
            unset($challenges[$flow]);
        } else {
            return;
        }

        $request->session()->put(self::CHALLENGE_SESSION_KEY, $challenges);
    }

    private function deny(
        Request $request,
        User $user,
        string $code,
        string $message,
        int $status,
        ?string $declared = null,
        ?string $issuedFor = null,
    ): JsonResponse {
        $this->auditEventService->denied(
            $request,
            AuditEventType::AuthActingDepartmentDenied,
            $user,
            metadata: array_filter([
                'reason' => $code,
                'path' => $request->path(),
                'method' => $request->method(),
                'declaredDepartment' => $declared,
                'challengeDepartment' => $issuedFor,
                'assignedDepartments' => $user->departmentValues(),
            ], fn (mixed $value): bool => $value !== null),
        );

        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
                'assignedDepartments' => $user->departmentValues(),
            ],
        ], $status);
    }
}
