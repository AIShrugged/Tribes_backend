<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class GitHubGetCommitTool extends AbstractAgentTool
{
    private const MAX_PATCH_CHARS = 8000;

    private const MAX_TOTAL_PATCH_CHARS = 8000;

    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_get_commit';
    }

    public function getDescription(): string
    {
        return 'Get a single commit by SHA: message, per-file additions/deletions/status, and total stats. include_patches defaults to FALSE (filenames + stats only — usually enough to classify ADDED vs FIXED). Set include_patches=true ONLY when message + filenames are ambiguous; patches are capped so the result never exceeds the tool limit. Defaults owner/repo to the configured repo.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner' => ['type' => 'string', 'description' => 'Repository owner/org. Defaults to the configured owner.'],
                'repo' => ['type' => 'string', 'description' => 'Repository name. Defaults to the configured repo.'],
                'ref' => ['type' => 'string', 'description' => 'Commit SHA (required).'],
                'include_patches' => ['type' => 'boolean', 'description' => 'Include capped per-file diffs. Default false (stats only).'],
            ],
            'required' => ['ref'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? '')) ?: (string) config('github.default_owner');
        $repo = trim((string) ($parameters['repo'] ?? '')) ?: (string) config('github.default_repo');
        $ref = trim((string) ($parameters['ref'] ?? ''));
        $includePatches = (bool) ($parameters['include_patches'] ?? false);

        if ($owner === '' || $repo === '') {
            return ['success' => false, 'error' => 'owner and repo are required (and no github.default_owner/default_repo configured)'];
        }
        if ($ref === '') {
            return ['success' => false, 'error' => 'ref (commit SHA) is required'];
        }

        try {
            $commit = $this->client->getCommit($owner, $repo, $ref);
        } catch (\Throwable $e) {
            return $this->githubError($e);
        }

        $totalPatch = 0;
        $omitted = 0;
        $files = [];
        foreach (array_values(array_filter($commit['files'] ?? [], 'is_array')) as $file) {
            $entry = [
                'filename' => $file['filename'] ?? null,
                'status' => $file['status'] ?? null,
                'additions' => $file['additions'] ?? 0,
                'deletions' => $file['deletions'] ?? 0,
                'changes' => $file['changes'] ?? 0,
            ];

            if ($includePatches && is_string($file['patch'] ?? null)) {
                if ($totalPatch < self::MAX_TOTAL_PATCH_CHARS) {
                    $patch = $file['patch'];
                    $entry['patch'] = Str::limit($patch, self::MAX_PATCH_CHARS, "\n...[truncated]");
                    $entry['patch_truncated'] = mb_strlen($patch) > self::MAX_PATCH_CHARS;
                    $totalPatch += min(mb_strlen($patch), self::MAX_PATCH_CHARS);
                } else {
                    $omitted++;
                }
            }

            $files[] = $entry;
        }

        $message = (string) ($commit['commit']['message'] ?? '');

        return [
            'success' => true,
            'sha' => $commit['sha'] ?? $ref,
            'message' => Str::limit($message, 1000, '...'),
            'author' => $commit['author']['login'] ?? ($commit['commit']['author']['name'] ?? null),
            'date' => $commit['commit']['author']['date'] ?? null,
            'stats' => Arr::only((array) ($commit['stats'] ?? []), ['additions', 'deletions', 'total']),
            'files_changed' => count($files),
            'patches_omitted' => $omitted,
            'files' => $files,
        ];
    }

    private function githubError(\Throwable $e): array
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '404') || str_contains($msg, 'Not Found')) {
            $msg .= ' (commit not found, or the GitHub App installation lacks access to this repo)';
        } elseif (str_contains($msg, '403') || str_contains($msg, '401') || stripos($msg, 'Bad credentials') !== false) {
            $msg .= ' (GitHub auth failed — App installation token missing or expired)';
        }

        return ['success' => false, 'error' => $msg];
    }
}
