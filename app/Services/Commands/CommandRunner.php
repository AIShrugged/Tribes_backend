<?php

namespace App\Services\Commands;

use App\Models\AgentCommandAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Runs an agent mutation: authorize → transactional execute → audit.
 *
 * - authorize() may throw {@see CommandAuthorizationException}; it propagates to the
 *   caller and NO audit row is written (the mutation never happened).
 * - execute() runs inside a DB transaction; if it throws, the transaction rolls back
 *   and no audit row is written (no orphan audit).
 * - On success an append-only audit row is written AFTER commit, capturing the prior
 *   snapshot and the inverse payload (for undo).
 */
class CommandRunner
{
    public function run(CommandInterface $command, User $actor, bool $tainted = false): CommandResult
    {
        $command->authorize($actor);

        $result = DB::transaction(static fn (): CommandResult => $command->execute());

        // The mutation has already committed. A failure to write the audit row must NOT
        // surface as a command failure (that would imply the mutation didn't happen) — log
        // it and move on so the result reflects the committed state.
        try {
            AgentCommandAudit::create([
                'actor_id' => $actor->id,
                'organization_id' => $actor->organizations()->value('organizations.id'),
                'command_type' => $result->name,
                'target_type' => $result->targetType,
                'target_id' => $result->targetId,
                'snapshot' => $result->snapshot,
                'inverse_payload' => $result->inversePayload,
                'tainted' => $tainted,
                'status' => 'committed',
                'summary' => $result->summary,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Agent command audit write failed', [
                'command_type' => $result->name,
                'target_type' => $result->targetType,
                'target_id' => $result->targetId,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }
}
