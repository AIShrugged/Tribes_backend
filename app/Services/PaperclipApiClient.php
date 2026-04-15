<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PaperclipApiClient
{
    private string $apiUrl;
    private string $apiKey;
    private string $companyId;

    public function __construct()
    {
        $this->apiUrl    = rtrim((string) config('paperclip.api_url'), '/');
        $this->apiKey    = (string) config('paperclip.api_key');
        $this->companyId = (string) config('paperclip.company_id');
    }

    /**
     * Create a new issue in Paperclip.
     *
     * @param  array{title: string, description: string, assigneeAgentId?: string, status?: string}  $data
     * @return array
     */
    public function createIssue(array $data): array
    {
        $response = $this->request()->post(
            "{$this->apiUrl}/api/companies/{$this->companyId}/issues",
            $data
        );

        $this->assertOk($response, 'createIssue');

        return $response->json();
    }

    /**
     * Get a single issue by ID.
     */
    public function getIssue(string $issueId): array
    {
        $response = $this->request()->get("{$this->apiUrl}/api/issues/{$issueId}");

        $this->assertOk($response, 'getIssue');

        return $response->json();
    }

    /**
     * Get activity log for the company, optionally filtered by agentId, entityType, entityId.
     *
     * @param  array{agentId?: string, entityType?: string, entityId?: string}  $filters
     */
    public function getActivity(array $filters = []): array
    {
        $response = $this->request()->get(
            "{$this->apiUrl}/api/companies/{$this->companyId}/activity",
            $filters
        );

        $this->assertOk($response, 'getActivity');

        return $response->json();
    }

    /**
     * Get comments for an issue, ordered by creation date ascending.
     */
    public function getIssueComments(string $issueId): array
    {
        $response = $this->request()->get("{$this->apiUrl}/api/issues/{$issueId}/comments");

        $this->assertOk($response, 'getIssueComments');

        return $response->json();
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson();
    }

    private function assertOk(Response $response, string $operation): void
    {
        if ($response->failed()) {
            $error = $response->json('error') ?? $response->body();
            throw new \RuntimeException(
                "Paperclip API error on {$operation} (HTTP {$response->status()}): {$error}"
            );
        }
    }
}
