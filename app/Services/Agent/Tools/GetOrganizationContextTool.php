<?php

namespace App\Services\Agent\Tools;

use App\Models\OrganizationContext;
use App\Models\User;

class GetOrganizationContextTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly ?int $organizationId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'get_organization_context';
    }

    public function getDescription(): string
    {
        return 'Retrieve indexed context about the organization from its linked sources '
            . '(website, GitHub, docs, etc.). Returns summarized text extracted from those sources. '
            . 'Use when you need background info about what the organization does, its tech stack, or team.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => [],
            'properties' => [
                'organization_id' => [
                    'type'        => 'integer',
                    'description' => 'Organization ID. Defaults to current context organization if omitted.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $orgId = (int) ($parameters['organization_id'] ?? $this->organizationId ?? 0);

        if (!$orgId) {
            return ['success' => false, 'error' => 'organization_id is required'];
        }

        $orgIds = $this->user->organizations()->pluck('organizations.id');
        if (!$orgIds->contains($orgId)) {
            return ['success' => false, 'error' => 'Organization not found or access denied'];
        }

        $contexts = OrganizationContext::where('organization_id', $orgId)
            ->with('source')
            ->get();

        if ($contexts->isEmpty()) {
            return [
                'success' => true,
                'context' => null,
                'message' => 'No indexed context available for this organization yet.',
            ];
        }

        $chunks = $contexts->map(function ($c) {
            $url = $c->source?->url ?? 'unknown source';
            return "### Source: {$url}\n{$c->text}";
        })->join("\n\n---\n\n");

        return ['success' => true, 'context' => $chunks];
    }
}
