<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubApiClient
{
    public function __construct(
        private readonly GitHubAppTokenService $tokenService,
    ) {}

    public function getRepository(string $owner, string $repo): array
    {
        return $this->get("/repos/{$owner}/{$repo}");
    }

    public function getBranch(string $owner, string $repo, string $branch): array
    {
        return $this->get("/repos/{$owner}/{$repo}/branches/".rawurlencode($branch));
    }

    public function listBranches(string $owner, string $repo, int $perPage = 100): array
    {
        $response = $this->request()
            ->get($this->url("/repos/{$owner}/{$repo}/branches"), ['per_page' => $perPage]);

        if (! $response->successful()) {
            throw new \RuntimeException('GitHub API request failed: '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException('GitHub API returned invalid JSON');
        }

        return $json;
    }

    public function getTree(string $owner, string $repo, string $ref, bool $recursive = false): array
    {
        $query = $recursive ? ['recursive' => '1'] : [];

        return $this->get("/repos/{$owner}/{$repo}/git/trees/{$ref}", $query);
    }

    public function getFileContents(string $owner, string $repo, string $path, ?string $ref = null): array
    {
        $query = $ref ? ['ref' => $ref] : [];

        return $this->get("/repos/{$owner}/{$repo}/contents/".ltrim($path, '/'), $query);
    }

    /**
     * Metadata-only commit list (the list endpoint carries no per-file patch).
     * since/until are ISO-8601 UTC and filter by committer date.
     */
    public function listCommits(
        string $owner,
        string $repo,
        ?string $sha = null,
        ?string $since = null,
        ?string $until = null,
        ?string $author = null,
        int $perPage = 30,
        int $page = 1,
    ): array {
        $query = array_filter([
            'sha' => $sha,
            'since' => $since,
            'until' => $until,
            'author' => $author,
            'per_page' => $perPage,
            'page' => $page,
        ], static fn ($value) => $value !== null && $value !== '');

        return $this->get("/repos/{$owner}/{$repo}/commits", $query);
    }

    /** Single commit incl. "stats" and "files" (each file may carry "patch"). */
    public function getCommit(string $owner, string $repo, string $ref): array
    {
        return $this->get("/repos/{$owner}/{$repo}/commits/".rawurlencode($ref));
    }

    public function createBranch(string $owner, string $repo, string $branch, string $sha): array
    {
        return $this->post("/repos/{$owner}/{$repo}/git/refs", [
            'ref' => "refs/heads/{$branch}",
            'sha' => $sha,
        ]);
    }

    public function createOrUpdateFile(
        string $owner,
        string $repo,
        string $path,
        string $content,
        string $message,
        string $branch,
        ?string $sha = null,
    ): array {
        $body = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch'  => $branch,
        ];

        if ($sha !== null) {
            $body['sha'] = $sha;
        }

        return $this->put("/repos/{$owner}/{$repo}/contents/".ltrim($path, '/'), $body);
    }

    public function createPullRequest(
        string $owner,
        string $repo,
        string $title,
        string $head,
        string $base,
        string $body = '',
    ): array {
        return $this->post("/repos/{$owner}/{$repo}/pulls", [
            'title' => $title,
            'head'  => $head,
            'base'  => $base,
            'body'  => $body,
        ]);
    }

    public function getPullRequestComments(string $owner, string $repo, int $pullNumber, int $perPage = 100): array
    {
        return $this->get("/repos/{$owner}/{$repo}/pulls/{$pullNumber}/comments", ['per_page' => $perPage]);
    }

    public function getIssueComments(string $owner, string $repo, int $issueNumber, int $perPage = 100): array
    {
        return $this->get("/repos/{$owner}/{$repo}/issues/{$issueNumber}/comments", ['per_page' => $perPage]);
    }

    public function downloadArchive(string $owner, string $repo, string $ref, string $destinationPath): void
    {
        $response = $this->request()
            ->withOptions(['sink' => $destinationPath])
            ->get($this->url("/repos/{$owner}/{$repo}/zipball/".rawurlencode($ref)));

        if (! $response->successful()) {
            throw new \RuntimeException('GitHub archive download failed: '.$response->body());
        }
    }

    private function post(string $path, array $data = []): array
    {
        $response = $this->request()
            ->post($this->url($path), $data);

        if (! $response->successful()) {
            throw new \RuntimeException('GitHub API request failed: '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException('GitHub API returned invalid JSON');
        }

        return $json;
    }

    private function put(string $path, array $data = []): array
    {
        $response = $this->request()
            ->put($this->url($path), $data);

        if (! $response->successful()) {
            throw new \RuntimeException('GitHub API request failed: '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException('GitHub API returned invalid JSON');
        }

        return $json;
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->request()
            ->get($this->url($path), $query);

        if (! $response->successful()) {
            throw new \RuntimeException('GitHub API request failed: '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException('GitHub API returned invalid JSON');
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'Accept' => 'application/vnd.github+json',
            ]);

        try {
            $token = $this->tokenService->issueInstallationToken();
            $request = $request->withToken($token);
        } catch (\RuntimeException) {
            // GitHub App not configured — proceed without auth (works for public repos)
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('github.api_base_url'), '/').'/'.ltrim($path, '/');
    }
}
