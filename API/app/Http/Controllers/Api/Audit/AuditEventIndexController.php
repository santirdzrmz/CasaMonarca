<?php

namespace App\Http\Controllers\Api\Audit;

use App\Enums\Department;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditEventIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'in:account,admin,auth,document,security,vcs'],
            'department' => ['nullable', 'string', Rule::enum(Department::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'outcome' => ['nullable', 'string', 'in:denied,failure,success'],
            'page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        $page = (int) ($validated['page'] ?? 1);
        $category = $validated['category'] ?? null;
        $outcome = $validated['outcome'] ?? null;
        $search = trim((string) ($validated['q'] ?? ''));

        $query = AuditEvent::query()
            ->with('actor');

        if (is_string($category)) {
            $query->where('event_type', 'like', $category.'.%');
        }

        if (is_string($outcome)) {
            $query->where('outcome', $outcome);
        }

        if (is_string($validated['department'] ?? null)) {
            $query->where('acting_department', $validated['department']);
        }

        if ($search !== '') {
            $likeSearch = '%'.addcslashes($search, '\%_').'%';
            $numericSearch = ctype_digit($search) ? (int) $search : null;

            $query->where(function ($searchQuery) use ($likeSearch, $numericSearch): void {
                $searchQuery
                    ->where('event_type', 'like', $likeSearch)
                    ->orWhere('resource_type', 'like', $likeSearch)
                    ->orWhere('ip_address', 'like', $likeSearch)
                    ->orWhere('metadata', 'like', $likeSearch)
                    ->orWhereHas('actor', function ($actorQuery) use ($likeSearch): void {
                        $actorQuery
                            ->where('name', 'like', $likeSearch)
                            ->orWhere('email', 'like', $likeSearch);
                    });

                if ($numericSearch !== null) {
                    $searchQuery
                        ->orWhere('resource_id', $numericSearch)
                        ->orWhere('document_id', $numericSearch)
                        ->orWhere('revision_id', $numericSearch);
                }
            });
        }

        $total = (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / $limit));

        $events = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->forPage($page, $limit)
            ->get();

        return response()->json([
            'message' => 'Audit events loaded successfully.',
            'pagination' => [
                'hasNextPage' => $page < $totalPages,
                'hasPreviousPage' => $page > 1,
                'limit' => $limit,
                'page' => $page,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
            'events' => $events
                ->map(fn (AuditEvent $event): array => [
                    'id' => $event->getKey(),
                    'occurredAt' => $event->occurred_at?->toIso8601String(),
                    'eventType' => $event->event_type,
                    'outcome' => $event->outcome,
                    'actor' => [
                        'userId' => $event->actor_user_id,
                        'name' => $event->actor?->name,
                        'email' => $event->actor?->email,
                        'role' => $event->actor_role,
                        'department' => $event->acting_department,
                    ],
                    'resource' => [
                        'type' => $event->resource_type,
                        'id' => $event->resource_id,
                        'documentId' => $event->document_id,
                        'revisionId' => $event->revision_id,
                    ],
                    'request' => [
                        'id' => $event->request_id,
                        'ipAddress' => $event->ip_address,
                        'userAgent' => $event->user_agent,
                    ],
                    'metadata' => $event->metadata ?? [],
                ])
                ->values(),
        ]);
    }
}
