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

    public function downloadArchive(string $owner, string $repo, string $ref, string $destinationPath): void
    {
        $response = $this->request()
            ->withOptions(['sink' => $destinationPath])
            ->get($this->url("/repos/{$owner}/{$repo}/zipball/".rawurlencode($ref)));

        if (! $response->successful()) {
            throw new \RuntimeException('GitHub archive download failed: '.$response->body());
        }
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
