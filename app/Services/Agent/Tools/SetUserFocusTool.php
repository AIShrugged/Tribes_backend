<?php

namespace App\Services\Agent\Tools;

use App\Models\Profile;
use App\Services\Agent\MemoryService;
use App\Services\UserFocusService;

class SetUserFocusTool implements ToolInterface
{
    public function __construct(
        private readonly Profile $profile,
        private readonly UserFocusService $userFocusService,
        private readonly MemoryService $memoryService,
        private readonly string $channel = 'web',
    ) {}

    public function getName(): string
    {
        return 'set_user_focus';
    }

    public function getDescription(): string
    {
        return 'Save or update what the user is currently focused on — their top priority, sprint theme, or stated goal. '
             . 'Call when the user explicitly states their focus ("I\'m focused on X", "my priority is Y", "focusing on Z until date"). '
             . 'Do NOT infer focus from task patterns — only call when the user has communicated clearly. '
             . 'When the user names specific tasks, ALWAYS pass their numeric IDs in `issue_ids` (look them up via get_tasks/query_db if needed). '
             . 'The saved focus is injected automatically into future sessions; only call when focus changes.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'focus_text' => [
                    'type'        => 'string',
                    'description' => 'Short human-readable description of the focus (free text, max 500 chars). E.g. "Интеграция календарей" or "v2.0 релиз".',
                ],
                'issue_ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'Numeric IDs of specific tasks the user wants to focus on, in priority order (most important first). Pass when the user names concrete tasks. Omit or pass empty for thematic focus without specific tasks.',
                ],
                'deadline' => [
                    'type'        => 'string',
                    'description' => 'ISO 8601 date when this focus ends, e.g. "2026-04-25". Omit if no deadline.',
                ],
                'source' => [
                    'type'        => 'string',
                    'enum'        => ['explicit', 'confirmed'],
                    'description' => '"explicit" = user stated directly; "confirmed" = agent inferred and user confirmed.',
                ],
            ],
            'required' => ['focus_text'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $focusText = trim($parameters['focus_text'] ?? '');

        if ($focusText === '') {
            return ['success' => false, 'error' => 'focus_text cannot be empty'];
        }

        if (mb_strlen($focusText) > 500) {
            return ['success' => false, 'error' => 'focus_text must not exceed 500 characters'];
        }

        $deadline = $parameters['deadline'] ?? null;

        if ($deadline !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
            return ['success' => false, 'error' => 'deadline must be in Y-m-d format, e.g. "2026-04-25"'];
        }

        $issueIds = $parameters['issue_ids'] ?? null;
        if ($issueIds !== null && ! is_array($issueIds)) {
            return ['success' => false, 'error' => 'issue_ids must be an array of integers'];
        }

        try {
            $record = $this->userFocusService->setFocus($this->profile, $focusText, $deadline, $issueIds);
            $this->memoryService->invalidateMemoryCache($this->profile, $this->channel);

            $storedIds = $record->content['issue_ids'] ?? [];

            return [
                'success'         => true,
                'action'          => $record->wasRecentlyCreated ? 'created' : 'updated',
                'focus'           => [
                    'text'         => $focusText,
                    'issue_ids'    => $storedIds,
                    'deadline'     => $deadline,
                    'expires_at'   => $record->expires_at?->toIso8601String(),
                ],
                'confirm_message' => 'Focus saved: "'.$focusText.'"'
                    . (count($storedIds) > 0 ? ' [' . count($storedIds) . ' tasks]' : '')
                    . ($deadline ? " (until {$deadline})" : ''),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}