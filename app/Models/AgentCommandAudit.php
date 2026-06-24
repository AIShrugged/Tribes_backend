<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit of agent mutations run through CommandRunner.
 * FK-free soft references (target survives deletion); inverse_payload powers undo
 * and is purged after a TTL (see the purge job).
 */
class AgentCommandAudit extends Model
{
    protected $table = 'agent_command_audit';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'inverse_payload' => 'array',
            'tainted' => 'boolean',
        ];
    }
}
