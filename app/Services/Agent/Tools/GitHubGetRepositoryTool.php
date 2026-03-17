<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Arr;

class GitHubGetRepositoryTool extends AbstractAgentTool
{
    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_get_repository';
    }

    public function getDescription(): string
    {
        return 'Get GitHub repository metadata for a specific owner/repo using the configured GitHub App installation.';
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
            ],
            'required' => ['owner', 'repo'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));

        if ($owner === '' || $repo === '') {
            return ['success' => false, 'error' => 'owner and repo are required'];
        }

        try {
            $repository = $this->client->getRepository($owner, $repo);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'repository' => Arr::only($repository, [
                'id',
                'name',
                'full_name',
                'private',
                'default_branch',
                'description',
                'language',
                'fork',
                'archived',
                'html_url',
            ]),
        ];
    }
}
