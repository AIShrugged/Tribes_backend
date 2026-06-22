<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BrainSuggestion;
use App\Models\User;
use App\Services\SecondBrain\SuggestionApplier;
use Illuminate\Http\Request;

/**
 * Human-in-the-loop review of second-brain proposals. Managers list pending
 * suggestions and approve (→ applied deterministically by SuggestionApplier) or
 * reject them. The brain never mutates the product directly.
 */
class BrainSuggestionController extends Controller
{
    public function index(Request $request): ApiResponse
    {
        $managed = $this->managedOrganizationIds($request->user());
        $this->assertManagesAny($managed);

        $organizationId = $request->integer('organization_id') ?: null;
        if ($organizationId !== null && ! in_array($organizationId, $managed, true)) {
            throw new AppException('You do not manage this organization.', 'BRAIN_SUGGESTION_FORBIDDEN', 403);
        }

        $query = BrainSuggestion::query()
            ->whereIn('organization_id', $organizationId ? [$organizationId] : $managed);

        $status = trim((string) $request->query('status', BrainSuggestion::STATUS_PENDING));
        if ($status !== '' && strtolower($status) !== 'all') {
            $query->where('status', $status);
        }
        if (($key = trim((string) $request->query('key'))) !== '') {
            $query->where('key', $key);
        }

        $count = (clone $query)->count();
        $perPage = min(max((int) ($request->integer('per_page') ?: 50), 1), 200);
        $page = max((int) ($request->integer('page') ?: 1), 1);

        $items = $query->orderByDesc('id')
            ->limit($perPage)->offset(($page - 1) * $perPage)
            ->get()
            ->map(fn (BrainSuggestion $s) => $this->format($s));

        return ApiResponse::list($items, $count);
    }

    public function approve(Request $request, BrainSuggestion $suggestion, SuggestionApplier $applier): ApiResponse
    {
        $this->authorizeManage($request->user(), $suggestion);

        if (! $suggestion->isPending()) {
            return ApiResponse::error("Suggestion is already {$suggestion->status}.", $this->format($suggestion), 409);
        }

        try {
            $result = $applier->apply($suggestion, $request->user());
        } catch (AppException $e) {
            $suggestion->update([
                'status' => BrainSuggestion::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'resolved_by_user_id' => $request->user()->id,
                'resolved_at' => now(),
            ]);

            return ApiResponse::error($e->getMessage(), $this->format($suggestion->fresh()), 422);
        }

        $suggestion->update([
            'status' => BrainSuggestion::STATUS_APPLIED,
            'applied_result' => $result,
            'applied_at' => now(),
            'resolved_by_user_id' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return ApiResponse::success('Applied', $this->format($suggestion->fresh()));
    }

    public function reject(Request $request, BrainSuggestion $suggestion): ApiResponse
    {
        $this->authorizeManage($request->user(), $suggestion);

        if (! $suggestion->isPending()) {
            return ApiResponse::error("Suggestion is already {$suggestion->status}.", $this->format($suggestion), 409);
        }

        $suggestion->update([
            'status' => BrainSuggestion::STATUS_REJECTED,
            'resolved_by_user_id' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return ApiResponse::success('Rejected', $this->format($suggestion->fresh()));
    }

    private function authorizeManage(User $user, BrainSuggestion $suggestion): void
    {
        if (! in_array((int) $suggestion->organization_id, $this->managedOrganizationIds($user), true)) {
            throw new AppException('You do not manage this organization.', 'BRAIN_SUGGESTION_FORBIDDEN', 403);
        }
    }

    private function format(BrainSuggestion $s): array
    {
        return [
            'id' => $s->id,
            'organization_id' => $s->organization_id,
            'run_uuid' => $s->run_uuid,
            'key' => $s->key,
            'title' => $s->title,
            'summary' => $s->summary,
            'reasoning' => $s->reasoning,
            'evidence' => $s->evidence,
            'confidence' => $s->confidence,
            'payload' => $s->payload,
            'status' => $s->status,
            'applied_result' => $s->applied_result,
            'failure_reason' => $s->failure_reason,
            'dedupe_key' => $s->dedupe_key,
            'created_at' => $s->created_at?->toIso8601String(),
            'resolved_at' => $s->resolved_at?->toIso8601String(),
            'applied_at' => $s->applied_at?->toIso8601String(),
        ];
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

    private function assertManagesAny(array $managed): void
    {
        if ($managed === []) {
            throw new AppException('Only organization managers can review brain suggestions.', 'BRAIN_SUGGESTION_MANAGER_REQUIRED', 403);
        }
    }
}
