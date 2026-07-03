<?php

namespace App\Services\SecondBrain;

use App\Enums\UserRole;
use App\Exceptions\AppException;
use App\Models\Organization;
use App\Models\SecondBrainInstance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Provisions and toggles the per-organization "second brain".
 *
 * Centralizes the MCP service-user + token logic (previously inline in
 * IssueBrainTokenCommand) and owns the desired-state row the orchestrator
 * reconciles. On enable a scoped `mcp` token is issued and stored ENCRYPTED
 * (the container needs the plaintext bearer, which Sanctum does not keep); on
 * disable the token is revoked so an orphaned container loses access at once.
 */
class SecondBrainProvisioningService
{
    /**
     * Create/reuse the per-org MCP service user (manager of exactly this org, so
     * the tenant resolves unambiguously) and issue a fresh token with the single
     * `mcp` ability, rotating any prior token of the same name.
     */
    public function issueToken(Organization $organization, string $name = 'second-brain'): NewAccessToken
    {
        $email = "second-brain+org{$organization->id}@brain.tribesmcp.local";

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => "{$name} ({$organization->name})",
                'password' => Hash::make(Str::random(48)),
                'email_verified_at' => now(),
            ],
        );

        // Manager of exactly this org → org-wide visibility, scoped to one tenant.
        $organization->users()->syncWithoutDetaching([
            $user->id => ['role' => UserRole::MANAGER->value],
        ]);

        // Rotate: drop any previous brain tokens with the same label.
        $user->tokens()->where('name', $name)->delete();

        return $user->createToken($name, ['mcp']);
    }

    /**
     * Enable the second brain for an organization: persist the Claude credential,
     * issue+store the MCP token (unless already running), and mark the instance
     * pending so the orchestrator starts its container.
     */
    public function enable(Organization $organization, ?string $claudeAuthType = null, ?string $claudeAuthToken = null): SecondBrainInstance
    {
        return DB::transaction(function () use ($organization, $claudeAuthType, $claudeAuthToken) {
            $instance = SecondBrainInstance::where('organization_id', $organization->id)
                ->lockForUpdate()
                ->first()
                ?? new SecondBrainInstance(['organization_id' => $organization->id]);

            // Resolve the Claude credential: required unless one is already stored.
            if ($claudeAuthType !== null || $claudeAuthToken !== null) {
                $this->assertValidCredential($claudeAuthType, $claudeAuthToken);
                $instance->claude_auth_type = $claudeAuthType;
                $instance->claude_auth_ciphertext = $claudeAuthToken;
                $instance->credentials_changed_at = now();
            } elseif ($instance->claude_auth_ciphertext === null) {
                throw new AppException(
                    'A Claude credential (claude_auth_type + claude_auth_token) is required to enable the second brain.',
                    'SECOND_BRAIN_CREDENTIAL_REQUIRED',
                    422,
                );
            }

            $instance->container_name = SecondBrainInstance::containerNameFor($organization->id);
            $instance->volume_name = SecondBrainInstance::volumeNameFor($organization->id);

            $alreadyLive = $instance->exists
                && $instance->enabled
                && in_array($instance->status, [SecondBrainInstance::STATUS_PENDING, SecondBrainInstance::STATUS_RUNNING], true);

            if ($alreadyLive) {
                // Idempotent re-enable: never re-issue the token (it would invalidate
                // the live container's bearer). Only a credential change was applied.
                $instance->save();

                return $instance;
            }

            $token = $this->issueToken($organization);
            $instance->token_ciphertext = $token->plainTextToken;
            $instance->token_id = $token->accessToken->getKey();
            $instance->service_user_id = $token->accessToken->tokenable_id;
            $instance->enabled = true;
            $instance->status = SecondBrainInstance::STATUS_PENDING;
            $instance->last_error = null;
            $instance->save();

            unset($credentialChanged); // documented above; reconciler recreates via credentials_changed_at

            return $instance;
        });
    }

    /**
     * Disable the second brain: flip desired state to off and revoke the MCP
     * token so an orphaned container immediately fails `abilities:mcp`. The
     * Claude credential is retained (encrypted) for a quick re-enable.
     */
    public function disable(Organization $organization, bool $revokeToken = true): SecondBrainInstance
    {
        return DB::transaction(function () use ($organization, $revokeToken) {
            $instance = SecondBrainInstance::where('organization_id', $organization->id)
                ->lockForUpdate()
                ->first();

            if ($instance === null) {
                // Nothing provisioned — synthesize a disabled row for a consistent response.
                return new SecondBrainInstance([
                    'organization_id' => $organization->id,
                    'enabled' => false,
                    'status' => SecondBrainInstance::STATUS_DISABLED,
                ]);
            }

            if ($revokeToken && $instance->token_id !== null) {
                $instance->token()->delete();
            }

            $instance->enabled = false;
            $instance->status = SecondBrainInstance::STATUS_STOPPING;
            $instance->token_id = null;
            $instance->token_ciphertext = null;
            $instance->save();

            return $instance;
        });
    }

    public function statusFor(Organization $organization): ?SecondBrainInstance
    {
        return SecondBrainInstance::where('organization_id', $organization->id)->first();
    }

    private function assertValidCredential(?string $type, ?string $token): void
    {
        if (! in_array($type, SecondBrainInstance::AUTH_TYPES, true)) {
            throw new AppException(
                'claude_auth_type must be one of: '.implode(', ', SecondBrainInstance::AUTH_TYPES).'.',
                'SECOND_BRAIN_INVALID_AUTH_TYPE',
                422,
            );
        }

        if ($token === null || trim($token) === '') {
            throw new AppException(
                'claude_auth_token is required.',
                'SECOND_BRAIN_CREDENTIAL_REQUIRED',
                422,
            );
        }
    }
}
