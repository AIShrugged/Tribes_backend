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
             . 'The saved focus is injected automatically into future sessions; only call when focus changes.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'focus_text' => [
                    'type'        => 'string',
                    'description' => 'What the user is focused on (free text, max 500 chars)',
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

        $deadline = $parameters['deadline'] ?? null;

        try {
            $record = $this->userFocusService->setFocus($this->profile, $focusText, $deadline);
            $this->memoryService->invalidateMemoryCache($this->profile, $this->channel);

            return [
                'success'         => true,
                'action'          => $record->wasRecentlyCreated ? 'created' : 'updated',
                'focus'           => [
                    'text'       => $focusText,
                    'deadline'   => $deadline,
                    'expires_at' => $record->expires_at?->toIso8601String(),
                ],
                'confirm_message' => 'Focus saved: "'.$focusText.'"'.($deadline ? " (until {$deadline})" : ''),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}