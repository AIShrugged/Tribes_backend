<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;

class GitHubCreateOrUpdateFileTool extends AbstractAgentTool
{
    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_create_or_update_file';
    }

    public function getDescription(): string
    {
        return 'Create or update a file in a GitHub repository. For updates, provide the current file SHA (use github_get_file_contents to obtain it). Creates a commit on the specified branch.';
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
                'path' => [
                    'type' => 'string',
                    'description' => 'File path in the repository (e.g., "src/index.js").',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The full file content (plain text, will be base64-encoded automatically).',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Commit message.',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'Branch to commit to.',
                ],
                'sha' => [
                    'type' => 'string',
                    'description' => 'Current file SHA (required for updates, omit for new files).',
                ],
            ],
            'required' => ['owner', 'repo', 'path', 'content', 'message', 'branch'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $path = trim((string) ($parameters['path'] ?? ''));
        $content = (string) ($parameters['content'] ?? '');
        $message = trim((string) ($parameters['message'] ?? ''));
        $branch = trim((string) ($parameters['branch'] ?? ''));
        $sha = trim((string) ($parameters['sha'] ?? '')) ?: null;

        if ($owner === '' || $repo === '' || $path === '' || $message === '' || $branch === '') {
            return ['success' => false, 'error' => 'owner, repo, path, message, and branch are required'];
        }

        try {
            $result = $this->client->createOrUpdateFile($owner, $repo, $path, $content, $message, $branch, $sha);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'path' => $result['content']['path'] ?? $path,
            'sha' => $result['content']['sha'] ?? null,
            'commit_sha' => $result['commit']['sha'] ?? null,
        ];
    }
}
