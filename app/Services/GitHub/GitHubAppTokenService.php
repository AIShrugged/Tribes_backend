<?php

namespace App\Services\GitHub;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GitHubAppTokenService
{
    public function issueInstallationToken(): string
    {
        $cacheKey = sprintf('github_app_installation_token:%s', (string) config('github.installation_id'));

        return Cache::remember($cacheKey, now()->addMinutes(50), function (): string {
            $appId = trim((string) config('github.app_id'));
            $installationId = trim((string) config('github.installation_id'));
            $apiBaseUrl = rtrim((string) config('github.api_base_url'), '/');
            $privateKeyPath = trim((string) config('github.private_key_path'));
            $privateKeyPem = trim((string) config('github.private_key_pem'));

            if ($appId === '' || $installationId === '' || ($privateKeyPath === '' && $privateKeyPem === '')) {
                throw new \RuntimeException('GitHub App credentials are not fully configured');
            }

            if ($privateKeyPem !== '') {
                $privateKey = $privateKeyPem;
            } else {
                $resolvedKeyPath = base_path($privateKeyPath);
                if (! is_file($resolvedKeyPath)) {
                    throw new \RuntimeException("GitHub App private key file not found at {$resolvedKeyPath}");
                }

                $privateKey = file_get_contents($resolvedKeyPath);
                if (! is_string($privateKey) || $privateKey === '') {
                    throw new \RuntimeException('GitHub App private key file is empty');
                }
            }

            $jwt = $this->makeAppJwt($appId, $privateKey);

            $response = Http::acceptJson()
                ->withToken($jwt)
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post("{$apiBaseUrl}/app/installations/{$installationId}/access_tokens");

            if (! $response->successful()) {
                throw new \RuntimeException('Failed to issue GitHub installation token: '.$response->body());
            }

            $token = (string) $response->json('token');
            if ($token === '') {
                throw new \RuntimeException('GitHub installation token response did not contain a token');
            }

            return $token;
        });
    }

    private function makeAppJwt(string $appId, string $privateKeyPem): string
    {
        $now = now()->timestamp;
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));

        $payload = $this->base64UrlEncode(json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $appId,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = "{$header}.{$payload}";
        $signature = '';

        if (! openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign GitHub App JWT');
        }

        return "{$signingInput}.{$this->base64UrlEncode($signature)}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
