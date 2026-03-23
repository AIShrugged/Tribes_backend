<?php

namespace App\Services\Agent\Tools;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Arr;

class GitHubGetPullRequestCommentsTool extends AbstractAgentTool
{
    public function __construct(
        private readonly GitHubApiClient $client,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'github_get_pull_request_comments';
    }

    public function getDescription(): string
    {
        return 'Get all review comments and general comments on a GitHub pull request. Use this to understand reviewer feedback when a task is reopened.';
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
                'pull_number' => [
                    'type' => 'integer',
                    'description' => 'Pull request number.',
                ],
            ],
            'required' => ['owner', 'repo', 'pull_number'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $owner = trim((string) ($parameters['owner'] ?? ''));
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $pullNumber = (int) ($parameters['pull_number'] ?? 0);

        if ($owner === '' || $repo === '' || $pullNumber <= 0) {
            return ['success' => false, 'error' => 'owner, repo, and pull_number are required'];
        }

        try {
            $reviewComments = $this->client->getPullRequestComments($owner, $repo, $pullNumber);
            $issueComments = $this->client->getIssueComments($owner, $repo, $pullNumber);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $formatComment = static fn (array $comment) => [
            'id' => $comment['id'] ?? null,
            'user' => $comment['user']['login'] ?? null,
            'body' => $comment['body'] ?? '',
            'path' => $comment['path'] ?? null,
            'line' => $comment['line'] ?? $comment['original_line'] ?? null,
            'created_at' => $comment['created_at'] ?? null,
        ];

        return [
            'success' => true,
            'review_comments' => array_map($formatComment, $reviewComments),
            'general_comments' => array_map($formatComment, $issueComments),
        ];
    }
}
