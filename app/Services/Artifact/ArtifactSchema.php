<?php

namespace App\Services\Artifact;

use App\Enums\ArtifactType;

/**
 * JSON Schema definitions for each artifact type's `data` field.
 * Used for LLM tool parameter description and payload validation.
 */
class ArtifactSchema
{
    /**
     * Returns the JSON Schema for the `data` field of the given artifact type.
     */
    public static function forType(ArtifactType $type): array
    {
        return match ($type) {
            ArtifactType::TaskTable      => self::taskTable(),
            ArtifactType::MeetingCard    => self::meetingCard(),
            ArtifactType::PeopleList     => self::peopleList(),
            ArtifactType::InsightCard    => self::insightCard(),
            ArtifactType::Chart          => self::chart(),
            ArtifactType::TranscriptView        => self::transcriptView(),
            ArtifactType::MethodologyCriteria   => self::methodologyCriteria(),
        };
    }

    /**
     * Returns a combined schema description for the LLM tool parameter.
     * Describes what `data` should look like per artifact type.
     */
    public static function dataDescription(): string
    {
        return <<<'TEXT'
        Structured payload depending on `type`:

        - task_table: {"tasks": [{"title": string, "assignee_name": string|null, "due_date": "YYYY-MM-DD"|null, "status": string, "description": string|null}]}
        - meeting_card: {"title": string, "starts_at": ISO8601, "ends_at": ISO8601|null, "participants": [string], "summary": string|null, "key_points": [string], "decisions": [string]}
        - people_list: {"members": [{"name": string, "role": string|null, "profile_id": int|null, "user_id": int|null}]}
        - insight_card: {"person": {"name": string, "profile_id": int}, "insights": [{"category": string, "content": object}]}
        - chart: {"chart_type": "bar"|"line"|"pie", "title": string|null, "labels": [string], "datasets": [{"label": string, "data": [number]}]}
        - transcript_view: {"meeting_title": string, "entries": [{"speaker": string, "text": string, "timestamp": string|null}]}
        - methodology_criteria: {"blocks": [Block]} where Block is one of:
          - {"type": "header", "text": string} — section header
          - {"type": "scoring_table", "columns": [string], "rows": [[string|number]]} — criteria table with scores
          - {"type": "progress_summary", "items": [{"label": string, "value": number, "max": number|null}]} — progress bars / key metrics
          - {"type": "scale", "title": string, "items": [{"score": number, "label": string}]} — scoring scale legend
          - {"type": "text_list", "title": string, "items": [string]} — bullet list with title
        TEXT;
    }

    private static function taskTable(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['tasks'],
            'properties' => [
                'tasks' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['title'],
                        'properties' => [
                            'title'         => ['type' => 'string'],
                            'assignee_name' => ['type' => ['string', 'null']],
                            'due_date'      => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                            'status'        => ['type' => ['string', 'null']],
                            'description'   => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function meetingCard(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['title', 'starts_at'],
            'properties' => [
                'title'        => ['type' => 'string'],
                'starts_at'    => ['type' => 'string', 'description' => 'ISO 8601 datetime'],
                'ends_at'      => ['type' => ['string', 'null']],
                'participants' => ['type' => 'array', 'items' => ['type' => 'string']],
                'summary'      => ['type' => ['string', 'null']],
                'key_points'   => ['type' => 'array', 'items' => ['type' => 'string']],
                'decisions'    => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    private static function peopleList(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['members'],
            'properties' => [
                'members' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['name'],
                        'properties' => [
                            'name'       => ['type' => 'string'],
                            'role'       => ['type' => ['string', 'null']],
                            'profile_id' => ['type' => ['integer', 'null']],
                            'user_id'    => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function insightCard(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['person', 'insights'],
            'properties' => [
                'person' => [
                    'type'       => 'object',
                    'required'   => ['name', 'profile_id'],
                    'properties' => [
                        'name'       => ['type' => 'string'],
                        'profile_id' => ['type' => 'integer'],
                    ],
                ],
                'insights' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['category', 'content'],
                        'properties' => [
                            'category' => ['type' => 'string'],
                            'content'  => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function chart(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['chart_type', 'labels', 'datasets'],
            'properties' => [
                'chart_type' => ['type' => 'string', 'enum' => ['bar', 'line', 'pie']],
                'title'      => ['type' => ['string', 'null']],
                'labels'     => ['type' => 'array', 'items' => ['type' => 'string']],
                'datasets'   => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['label', 'data'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'data'  => ['type' => 'array', 'items' => ['type' => 'number']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function transcriptView(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['meeting_title', 'entries'],
            'properties' => [
                'meeting_title' => ['type' => 'string'],
                'entries'       => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['speaker', 'text'],
                        'properties' => [
                            'speaker'   => ['type' => 'string'],
                            'text'      => ['type' => 'string'],
                            'timestamp' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function methodologyCriteria(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['blocks'],
            'properties' => [
                'blocks' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['type'],
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['header', 'scoring_table', 'progress_summary', 'scale', 'text_list'],
                            ],
                            // header
                            'text' => ['type' => 'string'],
                            // scoring_table
                            'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'rows'    => ['type' => 'array', 'items' => ['type' => 'array']],
                            // progress_summary & scale
                            'title' => ['type' => 'string'],
                            'items' => ['type' => 'array'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
