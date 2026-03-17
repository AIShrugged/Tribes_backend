<?php

namespace App\Services;

use App\Models\AgentTaskRun;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AgentTaskRunTokenService
{
    public function issue(AgentTaskRun $run): string
    {
        $plainToken = Str::random(64);

        $run->update([
            'run_token_hash' => Hash::make($plainToken),
            'run_token_expires_at' => now()->addSeconds((int) config('agent.agent_tasks.sandbox_run_token_ttl_seconds', 3600)),
        ]);

        return $plainToken;
    }

    public function validate(AgentTaskRun $run, ?string $plainToken): bool
    {
        if (! $plainToken || ! $run->run_token_hash || ! $run->run_token_expires_at) {
            return false;
        }

        if ($run->run_token_expires_at->isPast()) {
            return false;
        }

        return Hash::check($plainToken, $run->run_token_hash);
    }
}
