<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;

class GitHubCreateBranchTool extends AbstractAgentTool
{
    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_create_branch';
    }

    public function getDescription(): string
    {
        return 'Create a new branch in a GitHub repository from a given commit SHA. Use github_get_branch first to obtain the SHA of the source branch.';
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
                    'description' => 'Name for the new branch.',
                ],
                'sha' => [
                    'type' => 'string',
                    'description' => 'Commit SHA to create the branch from.',
                ],
            ],
            'required' => ['owner', 'repo', 'branch', 'sha'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $branch = trim((string) ($parameters['branch'] ?? ''));
        $sha = trim((string) ($parameters['sha'] ?? ''));

        if ($owner === '' || $repo === '' || $branch === '' || $sha === '') {
            return ['success' => false, 'error' => 'owner, repo, branch, and sha are required'];
        }

        try {
            $result = $this->client->createBranch($owner, $repo, $branch, $sha);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'ref' => $result['ref'] ?? null,
            'sha' => $result['object']['sha'] ?? $sha,
        ];
    }
}
