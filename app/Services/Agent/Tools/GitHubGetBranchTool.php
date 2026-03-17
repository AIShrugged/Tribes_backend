<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Arr;

class GitHubGetBranchTool extends AbstractAgentTool
{
    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_get_branch';
    }

    public function getDescription(): string
    {
        return 'Get GitHub branch information including the latest commit SHA. If branch is omitted, this tool prefers dev, then develop, then the default branch.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner' => [
                    'type' => 'string',
                    'description' => 'GitHub repository owner or organization.',
                ],
                'repo' => [
                    'type' => 'string',
                    'description' => 'GitHub repository name.',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'Optional branch name. If omitted, the tool resolves a preferred branch automatically.',
                ],
            ],
            'required' => ['owner', 'repo'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $branch = trim((string) ($parameters['branch'] ?? ''));

        if ($owner === '' || $repo === '') {
            return ['success' => false, 'error' => 'owner and repo are required'];
        }

        try {
            if ($branch === '') {
                $repository = $this->client->getRepository($owner, $repo);
                $defaultBranch = (string) ($repository['default_branch'] ?? '');
                $branches = $this->client->listBranches($owner, $repo);
                $available = array_values(array_filter(array_map(
                    static fn (array $item) => is_string($item['name'] ?? null) ? $item['name'] : null,
                    array_values(array_filter($branches, 'is_array'))
                )));

                $branch = collect(['dev', 'develop', $defaultBranch])
                    ->filter(fn ($candidate) => is_string($candidate) && $candidate !== '')
                    ->first(fn ($candidate) => in_array($candidate, $available, true))
                    ?? $defaultBranch;
            }

            $branchInfo = $this->client->getBranch($owner, $repo, $branch);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'branch' => Arr::only($branchInfo, ['name', 'protected']),
            'commit' => Arr::only((array) ($branchInfo['commit'] ?? []), ['sha', 'url']),
        ];
    }
}
