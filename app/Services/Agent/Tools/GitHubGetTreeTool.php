<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Arr;

class GitHubGetTreeTool extends AbstractAgentTool
{
    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_get_tree';
    }

    public function getDescription(): string
    {
        return 'Get a repository git tree from GitHub for a branch, tag, or commit SHA. Use recursive=true to inspect the repository structure.';
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
                'ref' => [
                    'type' => 'string',
                    'description' => 'Branch name, tag, or commit SHA. Use the default branch if omitted.',
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Whether to return the tree recursively.',
                ],
            ],
            'required' => ['owner', 'repo'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $ref = trim((string) ($parameters['ref'] ?? ''));
        $recursive = (bool) ($parameters['recursive'] ?? false);

        if ($owner === '' || $repo === '') {
            return ['success' => false, 'error' => 'owner and repo are required'];
        }

        try {
            if ($ref === '') {
                $repository = $this->client->getRepository($owner, $repo);
                $ref = (string) ($repository['default_branch'] ?? 'HEAD');
            }

            $tree = $this->client->getTree($owner, $repo, $ref, $recursive);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'ref' => $ref,
            'tree' => array_map(
                static fn (array $item) => Arr::only($item, ['path', 'mode', 'type', 'sha', 'size', 'url']),
                array_values(array_filter($tree['tree'] ?? [], 'is_array'))
            ),
            'truncated' => (bool) ($tree['truncated'] ?? false),
        ];
    }
}
