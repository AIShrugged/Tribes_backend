<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Arr;

class GitHubCreatePullRequestTool extends AbstractAgentTool
{
    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_create_pull_request';
    }

    public function getDescription(): string
    {
        return 'Create a pull request in a GitHub repository. Returns the PR number and URL.';
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
                'title' => [
                    'type' => 'string',
                    'description' => 'Pull request title.',
                ],
                'head' => [
                    'type' => 'string',
                    'description' => 'The branch that contains the changes (source branch).',
                ],
                'base' => [
                    'type' => 'string',
                    'description' => 'The branch to merge into (target branch, e.g., "dev" or "main").',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Pull request description (Markdown supported).',
                ],
            ],
            'required' => ['owner', 'repo', 'title', 'head', 'base'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $title = trim((string) ($parameters['title'] ?? ''));
        $head = trim((string) ($parameters['head'] ?? ''));
        $base = trim((string) ($parameters['base'] ?? ''));
        $body = trim((string) ($parameters['body'] ?? ''));

        if ($owner === '' || $repo === '' || $title === '' || $head === '' || $base === '') {
            return ['success' => false, 'error' => 'owner, repo, title, head, and base are required'];
        }

        try {
            $result = $this->client->createPullRequest($owner, $repo, $title, $head, $base, $body);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'pull_request' => Arr::only($result, [
                'number',
                'html_url',
                'title',
                'state',
                'head',
                'base',
            ]),
        ];
    }
}
