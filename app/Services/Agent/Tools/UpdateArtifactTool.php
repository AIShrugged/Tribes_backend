<?php

namespace App\Services\Agent\Tools;

use App\Enums\ArtifactType;
use App\Models\Chat;
use App\Services\Artifact\ArtifactSchema;
use App\Services\Artifact\ArtifactStateService;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ValidationError;

class UpdateArtifactTool implements ToolInterface
{
    public function __construct(
        private readonly Chat $chat,
        private readonly ArtifactStateService $artifactStateService,
    ) {
    }

    public function getName(): string
    {
        return 'update_artifact';
    }

    public function getDescription(): string
    {
        return 'Updates an existing artifact in-place. '
            . 'Use when the user provides corrections or refinements to a previously displayed artifact. '
            . 'Pass the full updated `data` payload, not a partial diff.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['artifact_id', 'data'],
            'properties' => [
                'artifact_id' => [
                    'type'        => 'string',
                    'description' => 'The ID of the artifact to update (returned from create_artifact).',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => 'New title for the artifact. Omit to keep the existing title.',
                ],
                'data' => [
                    'type'        => 'object',
                    'description' => 'Full updated data payload for the artifact. Must match the schema for the artifact\'s type.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $artifactId = $parameters['artifact_id'] ?? null;
        $title      = $parameters['title'] ?? null;
        $data       = $parameters['data'] ?? null;

        if (! $artifactId || $data === null) {
            return [
                'success' => false,
                'error'   => 'Parameters `artifact_id` and `data` are required.',
            ];
        }

        $state = $this->artifactStateService->loadState($this->chat);

        if (! isset($state['artifacts'][$artifactId])) {
            return [
                'success' => false,
                'error'   => "Artifact '{$artifactId}' not found in this chat.",
            ];
        }

        $artifact     = $state['artifacts'][$artifactId];
        $artifactType = ArtifactType::tryFrom($artifact['type']);

        if (! $artifactType) {
            return [
                'success' => false,
                'error'   => "Unknown artifact type '{$artifact['type']}'.",
            ];
        }

        $validationError = $this->validateData($artifactType, $data);
        if ($validationError !== null) {
            return [
                'success' => false,
                'error'   => "Invalid `data` for type '{$artifact['type']}': {$validationError}. Fix the data structure and retry.",
            ];
        }

        $payload = ['id' => $artifactId, 'data' => $data];

        if ($title !== null) {
            $payload['title'] = $title;
        }

        $this->artifactStateService->recordEvent($this->chat, 'artifact.update', $payload);

        return [
            'success'     => true,
            'artifact_id' => $artifactId,
            'message'     => 'Artifact updated and will be refreshed for the user.',
        ];
    }

    private function validateData(ArtifactType $type, mixed $data): ?string
    {
        if (! is_array($data)) {
            return '`data` must be an object.';
        }

        $schema    = ArtifactSchema::forType($type);
        $validator = new Validator();

        $result = $validator->validate(
            json_decode(json_encode($data)),
            json_decode(json_encode($schema))
        );

        if ($result->isValid()) {
            return null;
        }

        return $this->formatValidationError($result->error());
    }

    private function formatValidationError(ValidationError $error): string
    {
        $message  = $error->message();
        $keyword  = $error->keyword();
        $children = $error->children();

        if ($children) {
            $childMessages = array_map(
                fn ($child) => $this->formatValidationError($child),
                $children
            );

            return implode('; ', array_filter($childMessages));
        }

        return "[{$keyword}] {$message}";
    }
}
