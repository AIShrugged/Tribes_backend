<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Str;

class GitHubGetFileContentsTool extends AbstractAgentTool
{
    private const MAX_CONTENT_CHARS = 20000;

    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_get_file_contents';
    }

    public function getDescription(): string
    {
        return 'Get GitHub file contents for a repository path. Returns decoded text content for regular files.';
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
                    'description' => 'Repository file path, for example README.md or composer.json.',
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Optional branch, tag, or commit SHA.',
                ],
            ],
            'required' => ['owner', 'repo', 'path'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $path = trim((string) ($parameters['path'] ?? ''));
        $ref = trim((string) ($parameters['ref'] ?? ''));

        if ($owner === '' || $repo === '' || $path === '') {
            return ['success' => false, 'error' => 'owner, repo, and path are required'];
        }

        try {
            $file = $this->client->getFileContents($owner, $repo, $path, $ref !== '' ? $ref : null);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (($file['type'] ?? null) !== 'file') {
            return ['success' => false, 'error' => 'Requested GitHub path is not a regular file'];
        }

        $content = '';
        if (($file['encoding'] ?? null) === 'base64' && is_string($file['content'] ?? null)) {
            $decoded = base64_decode(str_replace("\n", '', $file['content']), true);
            if ($decoded !== false) {
                $content = $decoded;
            }
        }

        if ($content === '') {
            return ['success' => false, 'error' => 'Unable to decode GitHub file content'];
        }

        return [
            'success' => true,
            'path' => $path,
            'sha' => $file['sha'] ?? null,
            'size' => $file['size'] ?? null,
            'content' => Str::limit($content, self::MAX_CONTENT_CHARS, "\n...[truncated]"),
            'truncated' => mb_strlen($content) > self::MAX_CONTENT_CHARS,
        ];
    }
}
