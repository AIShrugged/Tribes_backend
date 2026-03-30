<?php

namespace App\Services;

use App\Models\AgentTaskRun;
use Illuminate\Support\Facades\File;

class SandboxRunWorkspaceService
{
    public function root(): string
    {
        $root = trim((string) config('agent.agent_tasks.sandbox_run_root', sys_get_temp_dir().'/spodial-sandbox-runs'));

        return $root !== '' ? rtrim($root, '/') : sys_get_temp_dir().'/spodial-sandbox-runs';
    }

    public function pathForRun(AgentTaskRun|int $run): string
    {
        $runId = $run instanceof AgentTaskRun ? $run->id : $run;

        return $this->root().'/'.$runId;
    }

    public function prepare(AgentTaskRun|int $run): string
    {
        $workspace = $this->pathForRun($run);

        File::ensureDirectoryExists($workspace);
        File::ensureDirectoryExists($workspace.'/input');
        File::ensureDirectoryExists($workspace.'/output');
        File::ensureDirectoryExists($workspace.'/artifacts');
        File::ensureDirectoryExists($workspace.'/synced-workspaces');

        return $workspace;
    }

    public function cleanup(AgentTaskRun|int $run): void
    {
        $workspace = $this->pathForRun($run);

        if (File::exists($workspace)) {
            File::deleteDirectory($workspace);
        }
    }
}
