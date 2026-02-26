<?php

namespace App\Services\Agent\Tools;

use App\Enums\ArtifactType;
use App\Models\Chat;
use App\Services\Artifact\ArtifactSchema;
use App\Services\Artifact\ArtifactStateService;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ValidationError;

class CreateArtifactTool implements ToolInterface
{
    public function __construct(
        private readonly Chat $chat,
        private readonly ArtifactStateService $artifactStateService,
    ) {
    }

    public function getName(): string
    {
        return 'create_artifact';
    }

    public function getDescription(): string
    {
        return 'Renders a structured visual artifact (table, card, chart, etc.) in the user\'s workspace. '
            . 'Use when the user\'s request is best answered with a structured visual rather than plain text — '
            . 'for example, showing a list of tasks, a meeting card, a person\'s profile, or a chart. '
            . 'Collect the needed data first using other tools, then call this tool to display it. '
            . 'You can create multiple artifacts per response.';
    }

    public function getParameters(): array
    {
        $typeValues = array_column(ArtifactType::cases(), 'value');

        return [
            'type'       => 'object',
            'required'   => ['type', 'title', 'data'],
            'properties' => [
                'type' => [
                    'type'        => 'string',
                    'enum'        => $typeValues,
                    'description' => 'Artifact type. Choose the most appropriate for the content.',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => 'Short human-readable title shown above the artifact.',
                ],
                'data' => [
                    'type'        => 'object',
                    'description' => ArtifactSchema::dataDescription(),
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $typeValue = $parameters['type'] ?? null;
        $title     = $parameters['title'] ?? null;
        $data      = $parameters['data'] ?? null;

        if (! $typeValue || ! $title || $data === null) {
            return [
                'success' => false,
                'error'   => 'Parameters `type`, `title`, and `data` are required.',
            ];
        }

        $artifactType = ArtifactType::tryFrom($typeValue);
        if (! $artifactType) {
            $valid = implode(', ', array_column(ArtifactType::cases(), 'value'));

            return [
                'success' => false,
                'error'   => "Unknown artifact type '{$typeValue}'. Valid types: {$valid}.",
            ];
        }

        $validationError = $this->validateData($artifactType, $data);
        if ($validationError !== null) {
            return [
                'success' => false,
                'error'   => "Invalid `data` for type '{$typeValue}': {$validationError}. Fix the data structure and retry.",
            ];
        }

        $event = $this->artifactStateService->recordEvent($this->chat, 'artifact.create', [
            'id'    => $parameters['artifact_id'] ?? $this->generateId($artifactType),
            'type'  => $typeValue,
            'title' => $title,
            'data'  => $data,
        ]);

        return [
            'success'     => true,
            'artifact_id' => $event->payload['id'],
            'type'        => $typeValue,
            'message'     => "Artifact '{$title}' created and will be displayed to the user.",
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

    private function generateId(ArtifactType $type): string
    {
        return $type->value . '_' . substr(uniqid(), -6);
    }
}
