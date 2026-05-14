<?php

namespace App\Services\Onboarding;

use App\Models\OrganizationContext;
use App\Models\OrganizationLink;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class OrganizationContextIndexerService extends OnboardingLlmBase
{
    public function indexLink(OrganizationLink $link): OrganizationContext
    {
        if (!$this->isSafeUrl($link->url)) {
            throw new \InvalidArgumentException("URL is not allowed: {$link->url}");
        }

        $rawText = $this->fetchSingleUrl($link->url);

        $text = $this->compact($link->url, $rawText);

        Log::info('Organization context indexed', [
            'organization_id' => $link->organization_id,
            'url'             => $link->url,
            'chars'           => strlen($text),
        ]);

        return OrganizationContext::updateOrCreate(
            [
                'source_type' => OrganizationLink::class,
                'source_id'   => $link->id,
            ],
            [
                'organization_id' => $link->organization_id,
                'text'            => $text,
                'indexed_at'      => now(),
            ]
        );
    }

    private function compact(string $url, string $rawText): string
    {
        if (trim($rawText) === '' || str_starts_with($rawText, 'Error fetching')) {
            return $rawText;
        }

        $model = config('ai.providers.openrouter.models.onboarding');

        $messages = [
            [
                'role'    => 'system',
                'content' => <<<'SYS'
You are extracting structured context about an organization from a web page.
Extract key facts: what the organization does, tech stack, team members mentioned, recent activity, open issues, milestones.
Be concise — plain text, no JSON. Maximum ~800 words.
SYS,
            ],
            [
                'role'    => 'user',
                'content' => "URL: {$url}\n\nPage content:\n{$rawText}",
            ],
        ];

        return OpenRouterClient::chat($messages, $model, 2048);
    }
}
