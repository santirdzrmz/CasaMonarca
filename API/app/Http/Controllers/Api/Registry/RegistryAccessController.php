<?php

namespace App\Http\Controllers\Api\Registry;

use App\Http\Controllers\Controller;
use App\Services\Security\Encryption\AccessGrantService;
use App\Services\Security\Encryption\OperatorSessionKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Temporary, signed access of operators to single records. See docs/operations/registry-user-keys.md. */
class RegistryAccessController extends Controller
{
    public function __construct(
        private readonly AccessGrantService $grants,
        private readonly OperatorSessionKeys $sessions,
    ) {}

    /** Operator: opens (or reuses) the session key and returns its fingerprint and own requests. */
    public function mine(Request $request): JsonResponse
    {
        if (! config('registry_encryption.enabled')) {
            return response()->json(['data' => ['mode' => config('registry_encryption.enabled') ? 'publickey' : 'off']]);
        }

        $session = $this->sessions->ensure($request, (int) $request->user()->getKey());

        return response()->json(['data' => [
            'mode' => 'publickey',
            'fingerprint' => $session['fingerprint'],
            'expires_at' => $session['expires_at'],
            'requests' => $this->grants->mine($request, $request->user()),
        ]]);
    }

    /** Operator: asks for temporary access to one record. */
    public function store(Request $request): JsonResponse
    {
        abort_unless(config('registry_encryption.enabled'), 409, 'User keys are not enabled.');

        $validated = $request->validate([
            'resource' => ['required', 'in:registry,arco'],
            'resource_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->sessions->ensure($request, (int) $request->user()->getKey());
        $id = $this->grants->request($request, $request->user(), $validated['resource'], (int) $validated['resource_id'], $validated['reason'] ?? null);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    /** Coordinator or admin: pending requests (or ?status=granted for active grants). */
    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), ['requested', 'granted'], true) ? (string) $request->query('status') : 'requested';

        return response()->json(['data' => $this->grants->requests($status)]);
    }

    public function grant(Request $request, int $grant): JsonResponse
    {
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'max:32'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
        ]);

        $this->grants->grant($request, $request->user(), $grant, $validated['fingerprint'], (int) ($validated['minutes'] ?? 60));

        return response()->json(['data' => ['id' => $grant, 'status' => 'granted']]);
    }

    public function deny(Request $request, int $grant): JsonResponse
    {
        $this->grants->deny($request, $request->user(), $grant);

        return response()->json(['data' => ['id' => $grant, 'status' => 'denied']]);
    }

    public function revoke(Request $request, int $grant): JsonResponse
    {
        $this->grants->revoke($request, $request->user(), $grant);

        return response()->json(['data' => ['id' => $grant, 'status' => 'revoked']]);
    }
}
