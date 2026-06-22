<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BrainEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Reasoning / activity log for the external "second brain".
 *
 * - store(): called by the sidecar (Sanctum token with the `mcp` ability) after
 *   each loop cycle, with the normalized Claude transcript (reasoning, tool
 *   calls, tool results, summary). Scoped to the service user's organization.
 * - index(): read access for organization managers (app/UI).
 */
class BrainEventController extends Controller
{
    public function store(Request $request): ApiResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'run_uuid' => ['nullable', 'string', 'max:64'],
            'organization_id' => ['nullable', 'integer'],
            'events' => ['required', 'array', 'min:1', 'max:2000'],
            'events.*.type' => ['required', 'string', 'max:64'],
            'events.*.tool_name' => ['nullable', 'string', 'max:128'],
            'events.*.content' => ['nullable', 'string'],
            'events.*.payload' => ['nullable'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ]);

        $organizationId = $this->resolveOrganizationId($user, $request->integer('organization_id') ?: null);

        $runUuid = $validated['run_uuid'] ?? null;
        $now = now();

        $rows = [];
        foreach (array_values($validated['events']) as $i => $event) {
            $payload = $event['payload'] ?? null;
            $rows[] = [
                'organization_id' => $organizationId,
                'run_uuid' => $runUuid,
                'seq' => $i,
                'type' => $event['type'],
                'tool_name' => $event['tool_name'] ?? null,
                'content' => $event['content'] ?? null,
                'payload' => $payload !== null ? json_encode($payload) : null,
                'occurred_at' => $event['occurred_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunked bulk insert keeps a large transcript in a few statements.
        foreach (array_chunk($rows, 200) as $chunk) {
            BrainEvent::insert($chunk);
        }

        return ApiResponse::success('Recorded', [
            'count' => count($rows),
            'run_uuid' => $runUuid,
            'organization_id' => $organizationId,
        ], 201);
    }

    public function index(Request $request): ApiResponse
    {
        $user = $request->user();
        $managed = $this->managedOrganizationIds($user);
        $this->assertUserManagesAnyOrganization($managed);

        $organizationId = $request->integer('organization_id') ?: null;
        if ($organizationId !== null && ! in_array($organizationId, $managed, true)) {
            throw new AppException('You do not manage this organization.', 'BRAIN_EVENT_FORBIDDEN', 403);
        }

        $query = BrainEvent::query()
            ->whereIn('organization_id', $organizationId ? [$organizationId] : $managed);

        if (($runUuid = trim((string) $request->query('run_uuid'))) !== '') {
            $query->where('run_uuid', $runUuid);
        }
        if (($type = trim((string) $request->query('type'))) !== '') {
            $query->where('type', $type);
        }

        $count = (clone $query)->count();

        $perPage = min(max((int) ($request->integer('per_page') ?: 100), 1), 500);
        $page = max((int) ($request->integer('page') ?: 1), 1);

        $events = $query
            ->orderByDesc('id')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get()
            ->map(fn (BrainEvent $event) => $this->format($event));

        return ApiResponse::list($events, $count);
    }

    private function format(BrainEvent $event): array
    {
        return [
            'id' => $event->id,
            'organization_id' => $event->organization_id,
            'run_uuid' => $event->run_uuid,
            'seq' => $event->seq,
            'type' => $event->type,
            'tool_name' => $event->tool_name,
            'content' => $event->content,
            'payload' => $event->payload,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    private function resolveOrganizationId(User $user, ?int $requested): int
    {
        $orgIds = $user->organizations()->pluck('organizations.id')->map(fn ($id) => (int) $id);

        if ($requested !== null) {
            if (! $orgIds->contains($requested)) {
                throw new AppException('You do not belong to this organization.', 'BRAIN_EVENT_FORBIDDEN', 403);
            }

            return $requested;
        }

        if ($orgIds->count() === 1) {
            return (int) $orgIds->first();
        }

        throw new AppException(
            'organization_id is required (the token user belongs to multiple organizations).',
            'BRAIN_EVENT_ORG_REQUIRED',
            422,
        );
    }

    /** @return array<int, int> */
    private function managedOrganizationIds(User $user): array
    {
        return $user->organizations()
            ->wherePivot('role', 'manager')
            ->pluck('organizations.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function assertUserManagesAnyOrganization(array $managedOrganizationIds): void
    {
        if ($managedOrganizationIds !== []) {
            return;
        }

        throw new AppException(
            'Only organization managers can read the second-brain log.',
            'BRAIN_EVENT_MANAGER_REQUIRED',
            403,
        );
    }
}
