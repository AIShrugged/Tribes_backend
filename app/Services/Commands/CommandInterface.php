<?php

namespace App\Services\Commands;

use App\Models\User;

/**
 * A single agent mutation, executed through CommandRunner.
 *
 * Contract:
 * - authorize() runs BEFORE any write and throws CommandAuthorizationException if
 *   the actor may not perform it (tenant scope / policy). It also resolves and
 *   caches the target so execute() can run without re-checking.
 * - execute() performs the mutation through the domain layer (Eloquent models so
 *   observers/events fire) and returns a CommandResult describing what changed,
 *   a snapshot of the prior state, and an inverse payload for undo (or null).
 *
 * Commands are synchronous in-transaction use-cases, NOT queued jobs.
 */
interface CommandInterface
{
    public function authorize(User $actor): void;

    public function execute(): CommandResult;
}
