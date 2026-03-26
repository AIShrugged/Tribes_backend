<?php

namespace App\Services\Agent\Tools;

use App\Jobs\GenerateMethodologySchemeJob;
use App\Models\Chat;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * AI tool that creates a new Methodology and links the current chat to it.
 * Used during methodology configuration sessions in chat.
 */
class CreateMethodologyTool implements ToolInterface
{
    public function __construct(
        private readonly Chat $chat,
        private readonly ?User $user = null,
    ) {}

    public function getName(): string
    {
        return 'create_methodology';
    }

    public function getDescription(): string
    {
        return 'Creates a new evaluation methodology for the organization and links this chat to it. '
            . 'Use this tool after you have helped the user define the methodology criteria. '
            . 'The methodology will be saved and its visual artifacts will be shown on follow-up analysis pages. '
            . 'Call create_artifact before or after to show the user a visual preview of the methodology structure.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['name', 'text', 'organization_id'],
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'Short human-readable name for the methodology (e.g. "Sales Call Evaluation v2").',
                ],
                'text' => [
                    'type'        => 'string',
                    'description' => 'Full methodology description: evaluation criteria, scoring rules, metric definitions.',
                ],
                'organization_id' => [
                    'type'        => 'integer',
                    'description' => 'ID of the organization this methodology belongs to.',
                ],
                'team_ids' => [
                    'type'        => 'array',
                    'description' => 'Optional list of team IDs to assign this methodology to.',
                    'items'       => ['type' => 'integer'],
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $name = trim((string) ($parameters['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'name is required'];
        }

        $text = trim((string) ($parameters['text'] ?? ''));
        if ($text === '') {
            return ['success' => false, 'error' => 'text is required'];
        }

        $organizationId = isset($parameters['organization_id'])
            ? (int) $parameters['organization_id']
            : null;

        if (! $organizationId) {
            return ['success' => false, 'error' => 'organization_id is required'];
        }

        $organization = Organization::find($organizationId);
        if (! $organization) {
            return ['success' => false, 'error' => "Organization {$organizationId} not found"];
        }

        $user = $this->user ?? Auth::user();
        if (! ($user instanceof User)) {
            return ['success' => false, 'error' => 'Authenticated user context is required'];
        }

        try {
            DB::beginTransaction();

            $methodology = $organization->methodologies()->create([
                'name' => $name,
                'text' => $text,
            ]);

            $teamIds = $parameters['team_ids'] ?? null;
            if (is_array($teamIds) && count($teamIds) > 0) {
                $methodology->syncTeams($teamIds);
            }

            // Link this chat to the created methodology so artifacts are associated with it
            $this->chat->update(['methodology_id' => $methodology->id]);

            DB::commit();

            // Generate JSON scheme asynchronously
            GenerateMethodologySchemeJob::dispatch($methodology);

            return [
                'success'        => true,
                'methodology_id' => $methodology->id,
                'name'           => $methodology->name,
                'message'        => "Methodology \"{$methodology->name}\" created successfully. "
                    . 'Now use create_artifact to show the user a visual preview of the evaluation structure.',
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'error'   => 'Failed to create methodology: ' . $e->getMessage(),
            ];
        }
    }
}