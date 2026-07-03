<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\SecondBrainInstance;
use App\Models\User;
use App\Services\SecondBrain\SecondBrainProvisioningService;
use Illuminate\Http\Request;

/**
 * Manager-facing control of the per-organization "second brain": enable (issues
 * a scoped token + provisions a dedicated container), disable, and read status.
 *
 * Authorization mirrors the sibling brain controllers (BrainSuggestionController
 * / BrainEventController): managed org ids from the `manager` pivot, 403 via
 * AppException. Secrets are never returned — enable responds with status only.
 */
class SecondBrainController extends Controller
{
    public function index(Request $request): ApiResponse
    {
        $managed = $this->managedOrganizationIds($request->user());
        $this->assertManagesAny($managed);

        $organizationId = $request->integer('organization_id') ?: null;
        if ($organizationId !== null && ! in_array($organizationId, $managed, true)) {
            throw new AppException('You do not manage this organization.', 'SECOND_BRAIN_FORBIDDEN', 403);
        }

        $instances = SecondBrainInstance::query()
            ->whereIn('organization_id', $organizationId ? [$organizationId] : $managed)
            ->get()
            ->keyBy('organization_id');

        // One row per managed org, synthesizing "disabled" where none exists yet.
        $orgIds = $organizationId ? [$organizationId] : $managed;
        $items = array_map(function (int $orgId) use ($instances) {
            $instance = $instances->get($orgId);

            return $instance ? $this->format($instance) : $this->disabledPlaceholder($orgId);
        }, $orgIds);

        return ApiResponse::list($items, count($items));
    }

    public function status(Request $request, Organization $organization): ApiResponse
    {
        $this->authorizeManage($request->user(), $organization->id);

        $instance = SecondBrainInstance::where('organization_id', $organization->id)->first();

        return ApiResponse::success('OK', $instance ? $this->format($instance) : $this->disabledPlaceholder($organization->id));
    }

    public function enable(Request $request, Organization $organization, SecondBrainProvisioningService $provisioning): ApiResponse
    {
        $this->authorizeManage($request->user(), $organization->id);

        $existing = SecondBrainInstance::where('organization_id', $organization->id)->first();
        $hasStoredCredential = $existing?->claude_auth_ciphertext !== null;

        $validated = $request->validate([
            'claude_auth_type' => [$hasStoredCredential ? 'nullable' : 'required', 'string', 'in:'.implode(',', SecondBrainInstance::AUTH_TYPES)],
            'claude_auth_token' => [$hasStoredCredential ? 'nullable' : 'required', 'string', 'min:8'],
        ]);

        $instance = $provisioning->enable(
            $organization,
            $validated['claude_auth_type'] ?? null,
            $validated['claude_auth_token'] ?? null,
        );

        return ApiResponse::success('Second brain enabling', $this->format($instance));
    }

    public function disable(Request $request, Organization $organization, SecondBrainProvisioningService $provisioning): ApiResponse
    {
        $this->authorizeManage($request->user(), $organization->id);

        $instance = $provisioning->disable($organization);

        return ApiResponse::success('Second brain disabling', $this->format($instance));
    }

    private function authorizeManage(User $user, int $organizationId): void
    {
        $managed = $this->managedOrganizationIds($user);
        $this->assertManagesAny($managed);

        if (! in_array($organizationId, $managed, true)) {
            throw new AppException('You do not manage this organization.', 'SECOND_BRAIN_FORBIDDEN', 403);
        }
    }

    private function format(SecondBrainInstance $instance): array
    {
        return [
            'organization_id' => (int) $instance->organization_id,
            'enabled' => (bool) $instance->enabled,
            'status' => $instance->status,
            'container_name' => $instance->container_name,
            'claude_auth_type' => $instance->claude_auth_type,
            'last_error' => $instance->last_error,
            'last_started_at' => $instance->last_started_at?->toIso8601String(),
            'last_reconciled_at' => $instance->last_reconciled_at?->toIso8601String(),
        ];
    }

    private function disabledPlaceholder(int $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'enabled' => false,
            'status' => SecondBrainInstance::STATUS_DISABLED,
            'container_name' => null,
            'claude_auth_type' => null,
            'last_error' => null,
            'last_started_at' => null,
            'last_reconciled_at' => null,
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
            throw new AppException('Only organization managers can manage the second brain.', 'SECOND_BRAIN_MANAGER_REQUIRED', 403);
        }
    }
}
