<?php

namespace App\Services\Followup;

use App\Models\Followup;

class FollowupArtifactStateService
{
    public function toState(Followup $followup): array
    {
        $followup->loadMissing('calendarEvent');

        $decoded = json_decode((string) $followup->text, true);
        if (is_array($decoded) && isset($decoded['artifacts'], $decoded['layout'])) {
            return $decoded;
        }

        $legacy = is_array($decoded) ? $decoded : [];

        return $this->legacyToState($followup, $legacy);
    }

    public function legacyToState(Followup $followup, array $legacy): array
    {
        $artifactId = 'followup_'.$followup->id;
        $title = $followup->calendarEvent?->title
            ? "Followup #{$followup->id} — {$followup->calendarEvent->title}"
            : "Followup #{$followup->id}";

        return [
            'artifacts' => [
                $artifactId => [
                    'id' => $artifactId,
                    'type' => 'methodology_criteria',
                    'title' => $title,
                    'data' => [
                        'blocks' => $this->buildBlocks($legacy, $followup),
                    ],
                    'status' => $this->mapStatus($followup->status),
                ],
            ],
            'layout' => [
                'items' => [
                    ['id' => $artifactId],
                ],
            ],
        ];
    }

    private function buildBlocks(array $legacy, Followup $followup): array
    {
        $blocks = [];

        $blocks[] = [
            'type' => 'header',
            'text' => $followup->calendarEvent?->title
                ? $followup->calendarEvent->title
                : "Followup #{$followup->id}",
        ];

        $overviewItems = [];
        $overviewItems[] = 'ID: '.$followup->id;
        if ($followup->user?->name) {
            $overviewItems[] = 'Участник: '.$followup->user->name;
        }
        if ($followup->team?->name) {
            $overviewItems[] = 'Команда: '.$followup->team->name;
        }
        if ($followup->methodology?->name) {
            $overviewItems[] = 'Методология: '.$followup->methodology->name;
        }

        if (! empty($overviewItems)) {
            $blocks[] = [
                'type' => 'text_list',
                'title' => 'Общая информация',
                'items' => $overviewItems,
            ];
        }

        if (isset($legacy['total_score']) && is_numeric($legacy['total_score'])) {
            $blocks[] = [
                'type' => 'progress_summary',
                'items' => [
                    [
                        'label' => 'Общий балл',
                        'value' => (int) $legacy['total_score'],
                        'max' => $legacy['total_max'] ?? null,
                    ],
                ],
            ];
        } elseif (isset($legacy['total']) && is_array($legacy['total'])) {
            $blocks[] = [
                'type' => 'progress_summary',
                'items' => [
                    [
                        'label' => $legacy['total']['display_name'] ?? 'Общий балл',
                        'value' => $legacy['total']['current_value'] ?? 0,
                        'max' => $legacy['total']['max_value'] ?? null,
                    ],
                ],
            ];
        }

        $metricsRows = $this->buildMetricRows($legacy);
        if (! empty($metricsRows)) {
            $blocks[] = [
                'type' => 'scoring_table',
                'columns' => ['Метрика', 'Балл', 'Макс.', 'Комментарий'],
                'rows' => $metricsRows,
            ];
        }

        foreach ($this->buildTextSections($legacy) as $section) {
            $blocks[] = $section;
        }

        return $blocks;
    }

    private function buildMetricRows(array $legacy): array
    {
        $rows = [];

        if (isset($legacy['metrics']) && is_array($legacy['metrics'])) {
            if (array_is_list($legacy['metrics'])) {
                foreach ($legacy['metrics'] as $metric) {
                    if (! is_array($metric)) {
                        continue;
                    }

                    $rows[] = [
                        $metric['display_name'] ?? 'N/A',
                        $metric['current_value'] ?? $metric['score'] ?? 0,
                        $metric['max_value'] ?? null,
                        $metric['comment'] ?? '',
                    ];

                    if (isset($metric['submetrics']) && is_array($metric['submetrics'])) {
                        foreach ($metric['submetrics'] as $submetric) {
                            if (! is_array($submetric)) {
                                continue;
                            }

                            $rows[] = [
                                '  - '.($submetric['display_name'] ?? 'N/A'),
                                $submetric['current_value'] ?? $submetric['score'] ?? 0,
                                $submetric['max_value'] ?? null,
                                $submetric['comment'] ?? '',
                            ];
                        }
                    }
                }
            } else {
                $this->flattenAssocMetrics($legacy['metrics'], $rows);
            }
        }

        return $rows;
    }

    private function flattenAssocMetrics(array $metrics, array &$rows, string $prefix = ''): void
    {
        foreach ($metrics as $key => $value) {
            $label = $prefix !== '' ? $prefix.' / '.$this->labelForKey((string) $key) : $this->labelForKey((string) $key);

            if (! is_array($value)) {
                $rows[] = [$label, $value, null, ''];
                continue;
            }

            if (isset($value['current_value']) || isset($value['score']) || isset($value['max_value'])) {
                $rows[] = [
                    $value['display_name'] ?? $label,
                    $value['current_value'] ?? $value['score'] ?? 0,
                    $value['max_value'] ?? null,
                    $value['comment'] ?? '',
                ];
            }

            if (isset($value['submetrics']) && is_array($value['submetrics'])) {
                $this->flattenAssocMetrics($value['submetrics'], $rows, $value['display_name'] ?? $label);
                continue;
            }

            foreach ($value as $nestedKey => $nestedValue) {
                if (in_array($nestedKey, ['display_name', 'current_value', 'score', 'max_value', 'comment', 'submetrics', 'frontend_component_type'], true)) {
                    continue;
                }

                if (is_array($nestedValue)) {
                    $this->flattenAssocMetrics([$nestedKey => $nestedValue], $rows, $value['display_name'] ?? $label);
                }
            }
        }
    }

    private function buildTextSections(array $legacy): array
    {
        $sections = [];

        if (isset($legacy['conclusion']) && is_array($legacy['conclusion'])) {
            if (isset($legacy['conclusion']['value']) && is_array($legacy['conclusion']['value'])) {
                foreach ($legacy['conclusion']['value'] as $section) {
                    if (! is_array($section)) {
                        continue;
                    }

                    $items = $section['value'] ?? [];
                    if (! is_array($items)) {
                        $items = [$items];
                    }

                    $sections[] = [
                        'type' => 'text_list',
                        'title' => $section['display_name'] ?? 'Раздел',
                        'items' => array_values(array_map(
                            fn ($item) => is_array($item) ? ($item['text'] ?? json_encode($item, JSON_UNESCAPED_UNICODE)) : (string) $item,
                            $items
                        )),
                    ];
                }
            }
        }

        if (isset($legacy['summary']) && is_string($legacy['summary']) && trim($legacy['summary']) !== '') {
            $sections[] = [
                'type' => 'text_list',
                'title' => 'Краткое резюме',
                'items' => [$legacy['summary']],
            ];
        }

        if (isset($legacy['action_items']) && is_array($legacy['action_items'])) {
            $sections[] = [
                'type' => 'text_list',
                'title' => 'Дальнейшие действия',
                'items' => array_values(array_map('strval', $legacy['action_items'])),
            ];
        }

        if (isset($legacy['strengths']) && is_array($legacy['strengths'])) {
            $sections[] = [
                'type' => 'text_list',
                'title' => 'Сильные стороны',
                'items' => array_values(array_map('strval', $legacy['strengths'])),
            ];
        }

        if (isset($legacy['areas_for_development']) && is_array($legacy['areas_for_development'])) {
            $sections[] = [
                'type' => 'text_list',
                'title' => 'Зоны для развития',
                'items' => array_values(array_map('strval', $legacy['areas_for_development'])),
            ];
        }

        if (isset($legacy['action_plan']) && is_array($legacy['action_plan'])) {
            $sections[] = [
                'type' => 'text_list',
                'title' => 'План действий',
                'items' => array_values(array_map('strval', $legacy['action_plan'])),
            ];
        }

        return $sections;
    }

    private function labelForKey(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'in_progress' => 'processing',
            'failed' => 'failed',
            default => 'ready',
        };
    }
}
