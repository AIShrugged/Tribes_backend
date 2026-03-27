<?php

namespace App\Services\Chat\Export;

use App\Models\Followup;

abstract class AbstractFollowupExporter
{
    abstract public function export(Followup $followup): string;

    abstract public function getContentType(): string;

    abstract public function getFileExtension(): string;

    public function getFileName(Followup $followup): string
    {
        return sprintf(
            'followup_%d_%s.%s',
            $followup->id,
            $followup->created_at->format('Y-m-d'),
            $this->getFileExtension()
        );
    }

    protected function prepareData(Followup $followup): array
    {
        $data = json_decode($followup->text, true);

        if (!is_array($data)) {
            return [];
        }

        if (isset($data['artifacts'], $data['layout']) && is_array($data['artifacts'])) {
            $artifact = $this->firstArtifact($data['artifacts']);
            if (is_array($artifact)) {
                $data = $this->artifactToLegacyData($artifact['data'] ?? []);
            }
        }

        return [
            'id'         => $followup->id,
            'created_at' => $followup->created_at->format('d.m.Y H:i'),
            'data'       => $data,
        ];
    }

    protected function extractMetrics(array $data): array
    {
        $metrics = [];

        if (isset($data['data']['metrics']) && is_array($data['data']['metrics'])) {
            foreach ($data['data']['metrics'] as $metric) {
                $metrics[] = [
                    'name'    => $metric['display_name'] ?? $metric['name'] ?? 'N/A',
                    'score'   => isset($metric['max_value'])
                        ? ($metric['current_value'] ?? 0) . ' / ' . $metric['max_value']
                        : ($metric['current_value'] ?? $metric['score'] ?? 0),
                    'comment' => $metric['comment'] ?? '',
                ];
            }
        }

        return $metrics;
    }

    protected function extractTotalScore(array $data): ?string
    {
        if (isset($data['data']['total'])) {
            $total = $data['data']['total'];
            $current = $total['current_value'] ?? 0;
            $max = $total['max_value'] ?? null;
            return $max !== null ? "$current / $max" : (string) $current;
        }

        return $data['data']['total_score'] ?? null;
    }

    protected function extractStrengths(array $data): array
    {
        // Legacy format
        if (!empty($data['data']['strengths'])) {
            return $this->normalizeTextArray($data['data']['strengths']);
        }

        // New format: extract from conclusion
        return $this->extractFromConclusion($data, 'Strong skills');
    }

    protected function extractAreasForDevelopment(array $data): array
    {
        // Legacy format
        if (!empty($data['data']['areas_for_development'])) {
            return $this->normalizeTextArray($data['data']['areas_for_development']);
        }

        // New format: extract from conclusion (Medium skills)
        return $this->extractFromConclusion($data, 'Medium skills');
    }

    protected function extractActionPlan(array $data): array
    {
        // Legacy format
        if (!empty($data['data']['action_plan'])) {
            return $this->normalizeTextArray($data['data']['action_plan']);
        }

        // New format: extract from conclusion (Recommendations)
        return $this->extractFromConclusion($data, 'Recommendations');
    }

    protected function extractConclusion(array $data): array
    {
        $conclusion = $data['data']['conclusion'] ?? null;

        if (!$conclusion || !isset($conclusion['value']) || !is_array($conclusion['value'])) {
            return [];
        }

        $result = [];
        foreach ($conclusion['value'] as $section) {
            $name = $section['display_name'] ?? 'Unknown';
            $items = $this->normalizeTextArray($section['value'] ?? []);
            $result[] = [
                'name'  => $name,
                'items' => $items,
            ];
        }

        return $result;
    }

    protected function extractFromConclusion(array $data, string $sectionName): array
    {
        $conclusion = $data['data']['conclusion'] ?? null;

        if (!$conclusion || !isset($conclusion['value']) || !is_array($conclusion['value'])) {
            return [];
        }

        foreach ($conclusion['value'] as $section) {
            if (($section['display_name'] ?? '') === $sectionName) {
                return $this->normalizeTextArray($section['value'] ?? []);
            }
        }

        return [];
    }

    protected function normalizeTextArray(array $items): array
    {
        return array_map(function ($item) {
            if (is_array($item)) {
                return $item['text'] ?? json_encode($item);
            }
            return $item;
        }, $items);
    }

    /**
     * @param array<string, mixed> $artifacts
     * @return array<string, mixed>|null
     */
    private function firstArtifact(array $artifacts): ?array
    {
        $first = reset($artifacts);

        return is_array($first) ? $first : null;
    }

    /**
     * Convert the new block-based artifact payload back to the legacy structure
     * expected by the existing HTML/PDF/XLSX exporters.
     *
     * @param array<string, mixed> $artifactData
     * @return array<string, mixed>
     */
    private function artifactToLegacyData(array $artifactData): array
    {
        $legacy = [];
        $blocks = $artifactData['blocks'] ?? [];

        if (!is_array($blocks)) {
            return $legacy;
        }

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type === 'progress_summary') {
                $item = $block['items'][0] ?? null;
                if (is_array($item)) {
                    $legacy['total'] = [
                        'display_name' => $item['label'] ?? 'Общий балл',
                        'current_value' => $item['value'] ?? 0,
                        'max_value' => $item['max'] ?? null,
                    ];
                }
            }

            if ($type === 'scoring_table') {
                $legacy['metrics'] = array_map(function ($row) {
                    $row = is_array($row) ? array_values($row) : [];

                    return [
                        'display_name' => (string) ($row[0] ?? 'N/A'),
                        'current_value' => $row[1] ?? 0,
                        'max_value' => $row[2] ?? null,
                        'comment' => (string) ($row[3] ?? ''),
                    ];
                }, $block['rows'] ?? []);
            }

            if ($type === 'text_list') {
                $title = (string) ($block['title'] ?? '');
                $items = $this->normalizeTextArray(array_values($block['items'] ?? []));

                if (in_array($title, ['Сильные стороны', 'Strong skills'], true)) {
                    $legacy['strengths'] = $items;
                } elseif (in_array($title, ['Зоны для развития', 'Medium skills', 'Areas for development'], true)) {
                    $legacy['areas_for_development'] = $items;
                } elseif (in_array($title, ['План действий', 'Recommendations', 'Action plan'], true)) {
                    $legacy['action_plan'] = $items;
                } elseif ($title !== '') {
                    $legacy['conclusion']['value'][] = [
                        'display_name' => $title,
                        'value' => $items,
                    ];
                }
            }
        }

        if (!empty($legacy['conclusion']['value']) && !isset($legacy['conclusion']['display_name'])) {
            $legacy['conclusion']['display_name'] = 'Results';
        }

        return $legacy;
    }
}
