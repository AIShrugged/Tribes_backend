<?php

namespace App\Console\Commands;

use App\Models\AgentMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class PruneAgentMemoriesCommand extends Command
{
    protected $signature = 'agent-memories:prune {--dry-run : Show what would be deactivated without persisting changes}';

    protected $description = 'Deactivate noisy or duplicate agent memories accumulated from earlier sandbox runs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deactivateIds = [];

        $artifactIds = AgentMemory::query()
            ->where('active', true)
            ->where('kind', 'artifact_fact')
            ->pluck('id')
            ->all();

        $deactivateIds = array_merge($deactivateIds, $artifactIds);

        AgentMemory::query()
            ->where('active', true)
            ->orderBy('agent_profile_id')
            ->orderBy('scope_type')
            ->orderBy('scope_key')
            ->orderBy('kind')
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (AgentMemory $memory): string => implode('|', [
                $memory->agent_profile_id,
                $memory->scope_type,
                (string) ($memory->scope_key ?? ''),
                $memory->kind,
            ]))
            ->each(function (Collection $group) use (&$deactivateIds): void {
                $seen = [];

                foreach ($group as $memory) {
                    $signature = $this->contentSignature($memory);

                    if (isset($seen[$signature])) {
                        $deactivateIds[] = $memory->id;

                        continue;
                    }

                    $seen[$signature] = true;
                }
            });

        $deactivateIds = array_values(array_unique($deactivateIds));

        if ($dryRun) {
            $this->info('Would deactivate '.$this->formatCount(count($artifactIds), 'artifact fact').'.');
            $this->info('Would deactivate '.$this->formatCount(count($deactivateIds), 'memory').'.');

            return self::SUCCESS;
        }

        if ($deactivateIds !== []) {
            AgentMemory::query()
                ->whereIn('id', $deactivateIds)
                ->update(['active' => false]);
        }

        $this->info('Deactivated '.$this->formatCount(count($artifactIds), 'artifact fact').'.');
        $this->info('Deactivated '.$this->formatCount(count($deactivateIds), 'memory').'.');

        return self::SUCCESS;
    }

    private function contentSignature(AgentMemory $memory): string
    {
        $content = trim((string) $memory->content);

        if ($memory->kind === 'test_fact') {
            return $this->normalizeTestFact($content);
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $content) ?? $content));
    }

    private function normalizeTestFact(string $content): string
    {
        $patterns = [
            '/^test command failed:\s*(.+?)\s*\(exit_code=(\d+)\)\.?$/i',
            '/^test command failed\s*\(exit_code=(\d+)\):\s*(.+?)\.?$/i',
            '/^test command succeeded:\s*(.+?)\s*\(exit_code=(\d+)\)\.?$/i',
            '/^test command succeeded\s*\(exit_code=(\d+)\):\s*(.+?)\.?$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches) !== 1) {
                continue;
            }

            $isPrefixCommand = str_contains($pattern, ':\s*(.+?)\s*\(exit_code=');
            $command = $isPrefixCommand ? $matches[1] : $matches[2];
            $exitCode = $isPrefixCommand ? $matches[2] : $matches[1];
            $state = str_contains($pattern, 'failed') ? 'failed' : 'succeeded';

            return "test-{$state}|".mb_strtolower(trim($command)).'|'.$exitCode;
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $content) ?? $content));
    }

    private function formatCount(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
