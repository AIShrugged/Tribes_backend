<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Str;

class GitHubListCommitsTool extends AbstractAgentTool
{
    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_list_commits';
    }

    public function getDescription(): string
    {
        return 'List recent commits (METADATA ONLY: sha, first message line, author, date, plus deterministic flags is_merge, is_bot and a prefix_hint — NO diff) for a repo branch. Defaults to the configured repo and its dev branch. Filter with since/until (ISO-8601 UTC). Call github_get_commit afterwards only for commits whose classification is ambiguous.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner' => ['type' => 'string', 'description' => 'Repository owner/org. Defaults to the configured owner.'],
                'repo' => ['type' => 'string', 'description' => 'Repository name. Defaults to the configured repo.'],
                'branch' => ['type' => 'string', 'description' => 'Branch name. If omitted, resolves dev, then develop, then the default branch.'],
                'since' => ['type' => 'string', 'description' => 'Only commits after this ISO-8601 UTC timestamp (e.g. 2026-06-14T21:00:00Z).'],
                'until' => ['type' => 'string', 'description' => 'Only commits before this ISO-8601 UTC timestamp.'],
                'per_page' => ['type' => 'integer', 'description' => 'Max commits to return (1-50, default 30).'],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? '')) ?: (string) config('github.default_owner');
        $repo = trim((string) ($parameters['repo'] ?? '')) ?: (string) config('github.default_repo');
        $branch = trim((string) ($parameters['branch'] ?? ''));
        $since = trim((string) ($parameters['since'] ?? ''));
        $until = trim((string) ($parameters['until'] ?? ''));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($parameters['per_page'] ?? 30)));

        if ($owner === '' || $repo === '') {
            return ['success' => false, 'error' => 'owner and repo are required (and no github.default_owner/default_repo configured)'];
        }

        try {
            if ($branch === '') {
                $branch = $this->resolveBranch($owner, $repo);
            }

            $commits = $this->client->listCommits(
                $owner,
                $repo,
                $branch !== '' ? $branch : null,
                $since !== '' ? $since : null,
                $until !== '' ? $until : null,
                null,
                $perPage,
            );
        } catch (\Throwable $e) {
            return $this->githubError($e);
        }

        $rawCount = is_array($commits) ? count($commits) : 0;
        $rows = [];
        foreach (array_values(array_filter($commits, 'is_array')) as $item) {
            $message = (string) ($item['commit']['message'] ?? '');
            $rows[] = [
                'sha' => $item['sha'] ?? null,
                'short_sha' => substr((string) ($item['sha'] ?? ''), 0, 7),
                'message_first_line' => Str::limit($this->firstLine($message), 200, '...'),
                'author' => $item['author']['login'] ?? ($item['commit']['author']['name'] ?? null),
                'date' => $item['commit']['author']['date'] ?? null,
                'is_merge' => count($item['parents'] ?? []) > 1,
                'is_bot' => $this->looksLikeBot((string) ($item['author']['login'] ?? '')),
                'prefix_hint' => $this->prefixHint($message),
            ];
        }

        return [
            'success' => true,
            'owner' => $owner,
            'repo' => $repo,
            'branch' => $branch !== '' ? $branch : null,
            'count' => count($rows),
            // Derive from the RAW page size (pre-filter) so truncation is always flagged
            // even when a malformed entry is dropped from $rows.
            'has_more' => $rawCount >= $perPage,
            'commits' => $rows,
        ];
    }

    private function resolveBranch(string $owner, string $repo): string
    {
        try {
            $repository = $this->client->getRepository($owner, $repo);
            $defaultBranch = (string) ($repository['default_branch'] ?? '');
            $branches = $this->client->listBranches($owner, $repo);
            $available = array_values(array_filter(array_map(
                static fn ($item) => is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : null,
                $branches,
            )));

            return collect(['dev', 'develop', $defaultBranch])
                ->filter(fn ($candidate) => is_string($candidate) && $candidate !== '')
                ->first(fn ($candidate) => in_array($candidate, $available, true))
                ?? $defaultBranch;
        } catch (\Throwable) {
            return ''; // fall back to the repo default branch (sha=null)
        }
    }

    private function firstLine(string $text): string
    {
        return trim(explode("\n", $text, 2)[0]);
    }

    private function looksLikeBot(string $login): bool
    {
        $login = strtolower($login);
        if ($login === '') {
            return false;
        }

        // NB: 'web-flow' (github.com web-editor author) is intentionally NOT here — it
        // also authors legit human browser commits; web *merges* are caught by is_merge.
        return str_ends_with($login, '[bot]')
            || in_array($login, ['dependabot', 'github-actions', 'renovate'], true);
    }

    private function prefixHint(string $message): string
    {
        $first = strtolower($this->firstLine($message));
        if (preg_match('/^(feat|feature)(\(|!|:)/', $first)) {
            return 'added';
        }
        if (preg_match('/^(fix|bugfix|hotfix)(\(|!|:)/', $first)) {
            return 'fixed';
        }
        if (preg_match('/^(chore|docs|style|ci|build|test|refactor|perf)(\(|!|:)/', $first)) {
            return 'skip';
        }

        return 'ambiguous';
    }

    private function githubError(\Throwable $e): array
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '404') || str_contains($msg, 'Not Found')) {
            $msg .= ' (repository/branch not found, or the GitHub App installation lacks access to this repo)';
        } elseif (str_contains($msg, '403') || str_contains($msg, '401') || stripos($msg, 'Bad credentials') !== false) {
            $msg .= ' (GitHub auth failed — App installation token missing or expired)';
        }

        return ['success' => false, 'error' => $msg];
    }
}
